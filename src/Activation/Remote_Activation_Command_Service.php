<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

/** Coordinates the authenticated command boundary without knowing HTTP. */
final class Remote_Activation_Command_Service {
	public function __construct(private Activation_Operation_Orchestrator $operations, private Activation_Command $activation) {}

	/** @param array<string,mixed> $command */
	public function execute(array $command): Remote_Activation_Result {
		$validated = $this->validate($command);
		if ($validated instanceof Remote_Activation_Result) return $validated;
		[$operation_key, $publication_key, $form_id, $fingerprint] = $validated;
		$reservation = $this->operations->reserve($operation_key, $publication_key, $form_id, $fingerprint);
		if ($reservation->result() === Activation_Operation_Reservation::ERROR) return $this->temporary($reservation->error_code() ?? 'activation_ledger_unavailable', Activation_Operation_State::PENDING);
		if ($reservation->result() === Activation_Operation_Reservation::CONFLICT) return $this->conflict('operation_conflict', (string) (($reservation->record() ?? array())['state'] ?? Activation_Operation_State::PENDING));
		$record = $reservation->record();
		if (! is_array($record)) return $this->temporary('activation_ledger_record_unavailable', Activation_Operation_State::PENDING);
		$state = (string) ($record['state'] ?? Activation_Operation_State::PENDING);
		if ($reservation->result() === Activation_Operation_Reservation::EXISTING_MATCH) {
			$existing = $this->existing($record);
			if ($existing !== null) return $existing;
		}
		if ($state !== Activation_Operation_State::PENDING) return $this->existing($record) ?? $this->temporary('activation_ledger_state_unknown', $state);
		$id = (int) ($record['id'] ?? 0);
		if ($id < 1) return $this->temporary('activation_ledger_id_missing', Activation_Operation_State::PENDING);
		$claimed = $this->operations->claim($id);
		if ($claimed->result() !== Activation_Operation_Transition::UPDATED) {
			$current = $this->operations->find_by_id($id);
			return is_array($current) ? ($this->existing($current) ?? $this->temporary('activation_claim_failed', (string) ($current['state'] ?? Activation_Operation_State::IN_PROGRESS))) : $this->temporary('activation_claim_failed', Activation_Operation_State::IN_PROGRESS);
		}
		try {
			$outcome = $this->activation->activate($publication_key, $form_id, $operation_key, $fingerprint);
		} catch (\Throwable) {
			return $this->reconcile($id, 'activation_exception');
		}
		return $this->persist_outcome($id, $operation_key, $publication_key, $form_id, $outcome);
	}

	public function status(string $operation_key): Remote_Activation_Result {
		if (! preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $operation_key)) return new Remote_Activation_Result(400, 'invalid_operation_key', Activation_Operation_State::PENDING);
		$record = $this->operations->find_by_operation_key($operation_key);
		if (! is_array($record)) return new Remote_Activation_Result(404, 'operation_not_found', Activation_Operation_State::PENDING, array('operation_key' => $operation_key));
		return $this->status_result($record, false);
	}

	/** @return array{string,string,int,string}|Remote_Activation_Result */
	private function validate(array $command): array|Remote_Activation_Result {
		$allowed = array('schema_version', 'operation_key', 'publication_key', 'expected_form_id', 'snapshot_fingerprint');
		if (array_diff(array_keys($command), $allowed) !== array() || array_diff($allowed, array_keys($command)) !== array()) return new Remote_Activation_Result(400, 'invalid_activation_request', Activation_Operation_State::PENDING);
		if ((string) $command['schema_version'] !== '1') return new Remote_Activation_Result(400, 'unsupported_schema_version', Activation_Operation_State::PENDING);
		$operation_key = (string) $command['operation_key']; $publication_key = (string) $command['publication_key']; $form_id = $command['expected_form_id']; $fingerprint = strtolower((string) $command['snapshot_fingerprint']);
		if (! preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $operation_key) || ! preg_match('/^\d{4}:[A-Z0-9_-]{1,50}$/', $publication_key) || ! is_int($form_id) || $form_id < 1 || ! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) return new Remote_Activation_Result(400, 'invalid_activation_identity', Activation_Operation_State::PENDING);
		return array($operation_key, $publication_key, $form_id, $fingerprint);
	}
	private function persist_outcome(int $id, string $operation_key, string $publication_key, int $form_id, Activation_Result $outcome): Remote_Activation_Result {
		$status = $outcome->status(); $evidence = $this->safe_evidence($outcome);
		if ($status === Activation_Result::ACTIVATED) {
			$transition = $this->operations->succeed($id, $evidence);
			return $transition->result() === Activation_Operation_Transition::UPDATED ? $this->success($operation_key, $publication_key, $form_id, false) : $this->reconcile($id, 'activation_success_persistence_failed');
		}
		if ($this->safe_failure($outcome)) {
			$transition = $this->operations->fail_before_mutation($id, $outcome->code());
			return $transition->result() === Activation_Operation_Transition::UPDATED ? new Remote_Activation_Result(422, $outcome->code(), Activation_Operation_State::FAILED, array('operation_key' => $operation_key, 'publication_key' => $publication_key, 'gravity_form_id' => $form_id, 'idempotent_replay' => false)) : $this->reconcile($id, 'activation_failure_persistence_failed');
		}
		return $this->reconcile($id, $outcome->code(), $operation_key, $publication_key, $form_id);
	}
	private function safe_failure(Activation_Result $outcome): bool {
		if ($outcome->status() === Activation_Result::BLOCKED) return true;
		if ($outcome->status() !== Activation_Result::FAILED) return false;
		$gateway = (array) ($outcome->evidence()['gateway'] ?? array());
		return array_key_exists('mutation_attempted', $gateway) && $gateway['mutation_attempted'] === false;
	}
	/** @param array<string,mixed> $record */
	private function existing(array $record): ?Remote_Activation_Result {
		$state = (string) ($record['state'] ?? '');
		if ($state === Activation_Operation_State::PENDING) return null;
		return $this->status_result($record, true);
	}
	/** @param array<string,mixed> $record */
	private function status_result(array $record, bool $replay): Remote_Activation_Result {
		$state = (string) ($record['state'] ?? Activation_Operation_State::RECONCILIATION_REQUIRED); $base = array('operation_key' => (string) ($record['operation_key'] ?? ''), 'publication_key' => (string) ($record['publication_key'] ?? ''), 'gravity_form_id' => (int) ($record['gravity_form_id'] ?? 0), 'idempotent_replay' => $replay);
		if ($state === Activation_Operation_State::SUCCEEDED) return new Remote_Activation_Result(200, 'activation_replay', $state, $base);
		if ($state === Activation_Operation_State::FAILED) return new Remote_Activation_Result(409, (string) ($record['error_code'] ?? 'activation_failed'), $state, $base);
		if ($state === Activation_Operation_State::RECONCILIATION_REQUIRED || $state === Activation_Operation_State::IN_PROGRESS) return new Remote_Activation_Result(409, 'reconciliation_required', $state, array_merge($base, array('reconciliation_required' => true)));
		return new Remote_Activation_Result(503, 'activation_state_unavailable', $state, $base);
	}
	private function success(string $operation_key, string $publication_key, int $form_id, bool $replay): Remote_Activation_Result { return new Remote_Activation_Result($replay ? 200 : 201, $replay ? 'activation_replay' : 'activation_succeeded', Activation_Operation_State::SUCCEEDED, array('operation_key' => $operation_key, 'publication_key' => $publication_key, 'gravity_form_id' => $form_id, 'idempotent_replay' => $replay)); }
	private function conflict(string $code, string $state): Remote_Activation_Result { return new Remote_Activation_Result(409, $code, $state); }
	private function temporary(string $code, string $state): Remote_Activation_Result { return new Remote_Activation_Result(503, $code, $state, array('reconciliation_required' => $state === Activation_Operation_State::IN_PROGRESS)); }
	private function reconcile(int $id, string $code, string $operation_key = '', string $publication_key = '', int $form_id = 0): Remote_Activation_Result { $transition = $this->operations->require_reconciliation($id, $code, array('reason_code' => $code, 'verified_at' => gmdate('c'))); if ($transition->result() !== Activation_Operation_Transition::UPDATED) return new Remote_Activation_Result(503, 'activation_reconciliation_persistence_failed', Activation_Operation_State::RECONCILIATION_REQUIRED, array('reconciliation_required' => true)); return new Remote_Activation_Result(409, 'reconciliation_required', Activation_Operation_State::RECONCILIATION_REQUIRED, array('operation_key' => $operation_key, 'publication_key' => $publication_key, 'gravity_form_id' => $form_id, 'reconciliation_required' => true)); }
	/** @return array<string,mixed> */
	private function safe_evidence(Activation_Result $outcome): array { return array('observed_form_state' => $outcome->status() === Activation_Result::ACTIVATED ? 'ACTIVE' : 'UNKNOWN', 'observed_inventory_health' => $outcome->status() === Activation_Result::ACTIVATED ? 'READY' : 'UNKNOWN', 'reason_code' => $outcome->code(), 'verified_at' => gmdate('c')); }
}
