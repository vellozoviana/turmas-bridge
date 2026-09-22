<?php

declare(strict_types=1);

namespace TurmasBridge\Reconciliation;

use TurmasBridge\Activation\Activation_Operation_State;
use TurmasBridge\Activation\Activation_Operation_Store;
use TurmasBridge\Activation\Activation_Operation_Transition;
use TurmasBridge\Activation\Activation_Mutation_Evidence;
use TurmasBridge\Publications\Post_Activation_Status_Provider;

/**
 * Read-only reconciliation of an activation outcome.
 *
 * This service never invokes an activation command or changes Gravity Forms or
 * GP Inventory.  It only records the bounded outcome of a fresh verification.
 */
final class Reconciliation_Service {
	public function __construct(private Activation_Operation_Store $operations, private Reconciliation_Attempt_Store $attempts, private Post_Activation_Status_Provider $verifier) {}

	/** @param array<string,mixed> $command */
	public function execute(array $command): Reconciliation_Result {
		$validated = $this->validate($command);
		if ($validated instanceof Reconciliation_Result) return $validated;
		[$reconciliation_key, $operation_key, $publication_key, $form_id, $fingerprint] = $validated;

		$operation = $this->operations->find_by_operation_key($operation_key);
		if (! is_array($operation)) return $this->error(404, 'activation_operation_not_found', Reconciliation_Attempt_State::INCONCLUSIVE, array('activation_operation_key' => $operation_key));
		if (! $this->identity_matches($operation, $publication_key, $form_id, $fingerprint)) return $this->error(409, 'reconciliation_identity_conflict', Reconciliation_Attempt_State::INCONCLUSIVE);
		$original_state = (string) ($operation['state'] ?? '');
		if ($original_state !== Activation_Operation_State::RECONCILIATION_REQUIRED) {
			return $this->error(409, 'reconciliation_state_conflict', Reconciliation_Attempt_State::INCONCLUSIVE, array('activation' => $this->activation_summary($operation)));
		}

		$reservation = $this->attempts->reserve($reconciliation_key, $operation_key, $publication_key, $form_id, $fingerprint);
		if ($reservation->result() === Reconciliation_Attempt_Reservation::ERROR) return $this->error(503, $reservation->error_code() ?? 'reconciliation_ledger_unavailable', Reconciliation_Attempt_State::TECHNICAL_FAILURE);
		if ($reservation->result() === Reconciliation_Attempt_Reservation::CONFLICT) return $this->error(409, 'reconciliation_identity_conflict', Reconciliation_Attempt_State::INCONCLUSIVE);
		$record = $reservation->record();
		if (! is_array($record)) return $this->error(503, 'reconciliation_ledger_record_unavailable', Reconciliation_Attempt_State::TECHNICAL_FAILURE);
		if ($reservation->result() === Reconciliation_Attempt_Reservation::EXISTING_MATCH) {
			$replay = $this->terminal_replay($record, $operation);
			if ($replay !== null) return $replay;
		}
		$id = (int) ($record['id'] ?? 0);
		if ($id < 1 || (string) ($record['state'] ?? Reconciliation_Attempt_State::PENDING) !== Reconciliation_Attempt_State::PENDING) {
			return $this->error(503, 'reconciliation_claim_failed', Reconciliation_Attempt_State::IN_PROGRESS, array('reconciliation_key' => $reconciliation_key));
		}
		$claimed = $this->attempts->claim($id);
		if ($claimed->result() !== Reconciliation_Attempt_Transition::UPDATED) return $this->error(503, 'reconciliation_claim_failed', Reconciliation_Attempt_State::IN_PROGRESS, array('reconciliation_key' => $reconciliation_key));

		$historical_causality = $this->historical_causality($operation);
		try {
			$fresh = $this->verifier->read_post_activation($publication_key);
		} catch (\Throwable) {
			return $this->finish_failure($id, $reconciliation_key, $operation, 'reconciliation_verification_failed');
		}
		if (is_wp_error($fresh)) return $this->finish_failure($id, $reconciliation_key, $operation, 'reconciliation_verification_unavailable');

		$evidence = $this->evidence($fresh, $operation, $historical_causality);
		$structural = $this->structural_state($fresh, $publication_key, $form_id);
		if ($structural !== 'HEALTHY') return $this->finish($id, $reconciliation_key, $operation, Reconciliation_Attempt_State::INCONCLUSIVE, 'STRUCTURAL_DRIFT', $evidence + array('structural_state' => $structural), 201);
		if (! $historical_causality) return $this->finish($id, $reconciliation_key, $operation, Reconciliation_Attempt_State::INCONCLUSIVE, 'INSUFFICIENT_CAUSAL_EVIDENCE', $evidence + array('structural_state' => 'HEALTHY'), 201);
		return $this->finish($id, $reconciliation_key, $operation, Reconciliation_Attempt_State::CONFIRMED_SUCCESS, 'CONFIRMED_ACTIVATION_OUTCOME', $evidence + array('structural_state' => 'HEALTHY'), 201);
	}

	/** @return array{string,string,string,int,string}|Reconciliation_Result */
	private function validate(array $command): array|Reconciliation_Result {
		$allowed = array('schema_version', 'reconciliation_key', 'activation_operation_key', 'publication_key', 'expected_form_id', 'snapshot_fingerprint');
		if (array_diff(array_keys($command), $allowed) !== array() || array_diff($allowed, array_keys($command)) !== array()) return $this->error(400, 'invalid_reconciliation_request', Reconciliation_Attempt_State::PENDING);
		$key = (string) $command['reconciliation_key']; $operation = (string) $command['activation_operation_key']; $publication = (string) $command['publication_key']; $form = $command['expected_form_id']; $fingerprint = strtolower((string) $command['snapshot_fingerprint']);
		if ((string) $command['schema_version'] !== '1' || ! preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $key) || ! preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $operation) || ! preg_match('/^\d{4}:[A-Z0-9_-]{1,50}$/', $publication) || ! is_int($form) || $form < 1 || ! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) return $this->error(400, 'invalid_reconciliation_identity', Reconciliation_Attempt_State::PENDING);
		return array($key, $operation, $publication, $form, $fingerprint);
	}

	/** @param array<string,mixed> $operation */
	private function identity_matches(array $operation, string $publication, int $form, string $fingerprint): bool { return (string) ($operation['publication_key'] ?? '') === $publication && (int) ($operation['gravity_form_id'] ?? 0) === $form && hash_equals((string) ($operation['snapshot_fingerprint'] ?? ''), $fingerprint); }

	/** @param array<string,mixed> $record @param array<string,mixed> $operation */
	private function terminal_replay(array $record, array $operation): ?Reconciliation_Result {
		$state = (string) ($record['state'] ?? '');
		if (! in_array($state, Reconciliation_Attempt_State::terminal(), true)) return null;
		$code = (string) ($record['result_code'] ?? 'reconciliation_result_unavailable');
		$http = $state === Reconciliation_Attempt_State::TECHNICAL_FAILURE ? 503 : 200;
		return new Reconciliation_Result($http, $code, $state, array('reconciliation_key' => (string) ($record['reconciliation_key'] ?? ''), 'activation_operation_key' => (string) ($record['activation_operation_key'] ?? ''), 'publication_key' => (string) ($record['publication_key'] ?? ''), 'gravity_form_id' => (int) ($record['gravity_form_id'] ?? 0), 'idempotent_replay' => true, 'activation' => $this->activation_summary($operation), 'evidence' => $this->decode_evidence($record['evidence_json'] ?? null)));
	}

	/** @param array<string,mixed> $operation */
	private function finish_failure(int $id, string $key, array $operation, string $code): Reconciliation_Result {
		return $this->finish($id, $key, $operation, Reconciliation_Attempt_State::TECHNICAL_FAILURE, $code, array('observed_at' => gmdate('c'), 'historical_causality' => false, 'reason_code' => $code, 'original_error' => (string) ($operation['error_code'] ?? '')), 503);
	}

	/** @param array<string,mixed> $operation @param array<string,mixed> $evidence */
	private function finish(int $id, string $key, array $operation, string $state, string $code, array $evidence, int $http): Reconciliation_Result {
		$transition = $this->attempts->complete($id, Reconciliation_Attempt_State::IN_PROGRESS, $state, $code, $this->bounded_evidence($evidence));
		if ($transition->result() !== Reconciliation_Attempt_Transition::UPDATED) return $this->error(503, 'reconciliation_persistence_failed', Reconciliation_Attempt_State::TECHNICAL_FAILURE, array('reconciliation_key' => $key));
		return new Reconciliation_Result($http, $code, $state, array('reconciliation_key' => $key, 'activation_operation_key' => (string) ($operation['operation_key'] ?? ''), 'publication_key' => (string) ($operation['publication_key'] ?? ''), 'gravity_form_id' => (int) ($operation['gravity_form_id'] ?? 0), 'idempotent_replay' => false, 'activation' => $this->activation_summary($operation), 'evidence' => $this->bounded_evidence($evidence)));
	}

	/** @param array<string,mixed> $fresh @param array<string,mixed> $operation */
	private function evidence(array $fresh, array $operation, bool $causal): array {
		$inventory = is_array($fresh['inventory'] ?? null) ? $fresh['inventory'] : array(); $resources = is_array($inventory['resources'] ?? null) ? $inventory['resources'] : array();
		$ids = array(); $capacities = array(); $consumed = array();
		foreach ($resources as $resource) if (is_array($resource)) { $ids[] = (int) ($resource['resource_id'] ?? 0); $capacities[] = (int) ($resource['capacity'] ?? 0); $consumed[] = (int) ($resource['consumed'] ?? 0); }
		return array('observed_at' => gmdate('c'), 'historical_causality' => $causal, 'form_id' => (int) ($fresh['form_id'] ?? 0), 'resource_ids' => array_values(array_filter($ids)), 'capacity' => count($capacities) === 1 ? $capacities[0] : $capacities, 'consumed' => count($consumed) === 1 ? $consumed[0] : $consumed, 'reason_code' => (string) ($operation['error_code'] ?? ''));
	}

	/** @param array<string,mixed> $fresh */
	private function structural_state(array $fresh, string $publication, int $form): string {
		if ((string) ($fresh['publication_key'] ?? '') !== $publication || (int) ($fresh['form_id'] ?? 0) !== $form) return 'IDENTITY_DRIFT';
		if ((string) ($fresh['effective_state'] ?? '') !== 'POST_ACTIVATION_VERIFIED' || (string) ($fresh['form_state'] ?? '') !== 'active') return 'FORM_STATE_DRIFT';
		$inventory = is_array($fresh['inventory'] ?? null) ? $fresh['inventory'] : array();
		if ((string) ($inventory['status'] ?? '') !== 'READY') return 'INVENTORY_NOT_READY';
		$resources = $inventory['resources'] ?? null; if (! is_array($resources) || $resources === array()) return 'RESOURCE_MISSING';
		$seen_ids = array();
		foreach ($resources as $resource) {
			if (! is_array($resource) || (int) ($resource['resource_id'] ?? 0) < 1 || empty($resource['healthy']) || ! is_numeric($resource['capacity'] ?? null) || ! is_numeric($resource['consumed'] ?? null)) return 'RESOURCE_DRIFT';
			if (isset($seen_ids[(int) $resource['resource_id']])) return 'RESOURCE_DRIFT';
			$seen_ids[(int) $resource['resource_id']] = true;
			if ((int) $resource['capacity'] < 1 || (int) $resource['consumed'] < 0 || (int) $resource['consumed'] > (int) $resource['capacity']) return 'CAPACITY_DRIFT';
		}
		return 'HEALTHY';
	}

	/** @param array<string,mixed> $operation @return array<string,mixed> */
	private function activation_summary(array $operation): array { return array('operation_key' => (string) ($operation['operation_key'] ?? ''), 'state' => (string) ($operation['state'] ?? ''), 'error_code' => $operation['error_code'] ?? null); }

	/** @param array<string,mixed> $operation */
	private function historical_causality(array $operation): bool {
		$evidence = $this->decode_evidence($operation['evidence_json'] ?? null); if ($evidence === array()) return false;
		if (Activation_Mutation_Evidence::is_confirmed_persisted($evidence['activation_mutation'] ?? null, (int) ($operation['gravity_form_id'] ?? 0))) return true;
		// Legacy attempt flags are deliberately weak: they record invocation, not confirmation.
		return false;
	}

	/** @return array<string,mixed> */
	private function decode_evidence(mixed $json): array { if (! is_string($json) || $json === '') return array(); $decoded = json_decode($json, true); return is_array($decoded) ? $decoded : array(); }

	/** @param array<string,mixed> $evidence @return array<string,mixed> */
	private function bounded_evidence(array $evidence): array { $allowed = array('observed_at','historical_causality','structural_state','form_id','resource_ids','capacity','consumed','reason_code','original_error'); $out = array(); foreach ($allowed as $key) if (array_key_exists($key, $evidence)) $out[$key] = $evidence[$key]; return $out; }

	/** @param array<string,mixed> $payload */
	private function error(int $http, string $code, string $state, array $payload = array()): Reconciliation_Result { return new Reconciliation_Result($http, $code, $state, $payload); }
}
