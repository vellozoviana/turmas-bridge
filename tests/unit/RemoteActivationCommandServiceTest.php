<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Activation\Activation_Command;
use TurmasBridge\Activation\Activation_Operation_Orchestrator;
use TurmasBridge\Activation\Activation_Operation_Reservation;
use TurmasBridge\Activation\Activation_Operation_State;
use TurmasBridge\Activation\Activation_Operation_Store;
use TurmasBridge\Activation\Activation_Operation_Transition;
use TurmasBridge\Activation\Activation_Mutation_Evidence;
use TurmasBridge\Activation\Activation_Result;
use TurmasBridge\Activation\Remote_Activation_Command_Service;

final class RemoteActivationCommandServiceTest extends TestCase {
	public function test_new_operation_calls_fake_b2_once_and_persists_success(): void {
		$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $service = $this->service($store, $b2);
		$result = $service->execute($this->command());

		self::assertSame(201, $result->http_status()); self::assertSame('activation_succeeded', $result->code()); self::assertSame(Activation_Operation_State::SUCCEEDED, $result->state()); self::assertSame(str_repeat('a', 64), $result->to_array()['snapshot_fingerprint']); self::assertSame(1, $b2->calls); self::assertSame(Activation_Operation_State::SUCCEEDED, $store->record['state']);
	}

	public function test_success_replay_does_not_call_b2(): void {
		$store = new Remote_Activation_Fake_Store(); $store->seed(Activation_Operation_State::SUCCEEDED); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $result = $this->service($store, $b2)->execute($this->command());

		self::assertSame(200, $result->http_status()); self::assertSame('activation_replay', $result->code()); self::assertSame(0, $b2->calls);
	}

	public function test_pending_replay_claims_once_and_calls_b2(): void {
		$store = new Remote_Activation_Fake_Store(); $store->seed(Activation_Operation_State::PENDING); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $result = $this->service($store, $b2)->execute($this->command());

		self::assertSame(201, $result->http_status()); self::assertSame(1, $b2->calls);
	}

	public function test_sequential_same_operation_is_idempotent(): void {
		$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $service = $this->service($store, $b2);
		$first = $service->execute($this->command()); $second = $service->execute($this->command());

		self::assertSame(201, $first->http_status()); self::assertSame(200, $second->http_status()); self::assertSame('activation_replay', $second->code()); self::assertSame(1, $b2->calls);
	}

	public function test_in_progress_failed_and_reconciliation_replays_never_call_b2(): void {
		foreach (array(Activation_Operation_State::IN_PROGRESS, Activation_Operation_State::FAILED, Activation_Operation_State::RECONCILIATION_REQUIRED) as $state) {
			$store = new Remote_Activation_Fake_Store(); $store->seed($state); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $result = $this->service($store, $b2)->execute($this->command());
			self::assertSame(0, $b2->calls); self::assertContains($result->http_status(), array(409));
		}
	}

	public function test_identity_conflicts_are_rejected_without_b2(): void {
		$store = new Remote_Activation_Fake_Store(); $store->conflict = true; $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $result = $this->service($store, $b2)->execute($this->command());

		self::assertSame(409, $result->http_status()); self::assertSame('operation_conflict', $result->code()); self::assertSame(0, $b2->calls);
	}

	public function test_existing_operation_with_different_snapshot_is_conflict(): void {
		$store = new Remote_Activation_Fake_Store(); $store->seed(Activation_Operation_State::PENDING); $store->record['snapshot_fingerprint'] = str_repeat('b', 64); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $result = $this->service($store, $b2)->execute($this->command());

		self::assertSame(409, $result->http_status()); self::assertSame('operation_conflict', $result->code()); self::assertSame(0, $b2->calls);
	}

	public function test_claim_cas_conflict_fails_closed_without_b2(): void {
		$store = new Remote_Activation_Fake_Store(); $store->claim_conflict = true; $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $result = $this->service($store, $b2)->execute($this->command());

		self::assertSame(503, $result->http_status()); self::assertSame('activation_claim_failed', $result->code()); self::assertSame(0, $b2->calls);
	}

	public function test_safe_b2_block_is_failed_but_unknown_is_reconciliation(): void {
		$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::BLOCKED, 'PUBLICATION_NOT_MATERIALIZED', 'blocked')); $result = $this->service($store, $b2)->execute($this->command());
		self::assertSame(422, $result->http_status()); self::assertSame(Activation_Operation_State::FAILED, $store->record['state']); self::assertSame(array('mutation_safety' => 'NO_MUTATION', 'retry_disposition' => 'RETRYABLE'), $result->to_array()['failure_disposition']);

		$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::UNKNOWN, 'FORM_ACTIVATION_UNKNOWN', 'unknown')); $result = $this->service($store, $b2)->execute($this->command());
		self::assertSame(409, $result->http_status()); self::assertSame(Activation_Operation_State::RECONCILIATION_REQUIRED, $store->record['state']);
	}

	public function test_bridge_exposes_retryable_only_for_allowlisted_pre_mutation_transient_failure(): void {
		foreach (array('GRAVITY_FORMS_UNAVAILABLE' => 'RETRYABLE', 'STATUS_UNAVAILABLE' => 'RETRYABLE', 'LOCK_UNAVAILABLE' => 'RETRYABLE', 'PUBLICATION_NOT_MATERIALIZED' => 'RETRYABLE', 'FORM_NOT_INACTIVE' => 'RETRYABLE', 'INVENTORY_NOT_HEALTHY' => 'RETRYABLE', 'FORM_NOT_FOUND' => 'NOT_RETRYABLE', 'UNKNOWN_FUTURE_FAILURE' => 'NOT_RETRYABLE') as $code => $expected) {
			$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::BLOCKED, $code, 'blocked')); $service = $this->service($store, $b2);
			$service->execute($this->command()); $status = $service->status((string) $store->record['operation_key'])->to_array();
			self::assertSame('NO_MUTATION_EVIDENCE', $store->record['evidence_json']['activation_mutation']['state']);
			self::assertSame('NO_MUTATION', $status['failure_disposition']['mutation_safety']); self::assertSame($expected, $status['failure_disposition']['retry_disposition']);
		}
	}

	public function test_bridge_never_classifies_uncertain_or_confirmed_mutation_as_retryable(): void {
		$store = new Remote_Activation_Fake_Store(); $store->seed(Activation_Operation_State::RECONCILIATION_REQUIRED); $store->record['evidence_json'] = array('activation_mutation' => Activation_Mutation_Evidence::uncertain(10)->to_array());
		$status = (new Remote_Activation_Command_Service(new Activation_Operation_Orchestrator($store), new Remote_Activation_Fake_B2(null)))->status((string) $store->record['operation_key'])->to_array();
		self::assertSame('MUTATION_UNCERTAIN', $status['failure_disposition']['mutation_safety']); self::assertSame('RECONCILIATION_REQUIRED', $status['failure_disposition']['retry_disposition']);

		$store->record['evidence_json'] = array('activation_mutation' => Activation_Mutation_Evidence::from_verified_gateway(new \TurmasBridge\Activation\Form_Activation_Outcome('INACTIVE', true, 'ACTIVE', true), '2099:E2F', 10, array('publication_key' => '2099:E2F', 'form_id' => 10, 'form_state' => 'active'))->to_array());
		$status = (new Remote_Activation_Command_Service(new Activation_Operation_Orchestrator($store), new Remote_Activation_Fake_B2(null)))->status((string) $store->record['operation_key'])->to_array();
		self::assertSame('MUTATION_CONFIRMED', $status['failure_disposition']['mutation_safety']); self::assertSame('RECONCILIATION_REQUIRED', $status['failure_disposition']['retry_disposition']);
	}

	public function test_b2_exception_is_fail_closed_without_retry(): void {
		$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(null, true); $result = $this->service($store, $b2)->execute($this->command());

		self::assertSame(409, $result->http_status()); self::assertSame(Activation_Operation_State::RECONCILIATION_REQUIRED, $store->record['state']); self::assertSame(1, $b2->calls);
	}

	public function test_blocked_outcome_with_nonempty_mutation_evidence_is_reconciliation_not_failed(): void {
		$store = new Remote_Activation_Fake_Store();
		$mutation = Activation_Mutation_Evidence::uncertain(10, 'b2_gateway_error');
		$b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::BLOCKED, 'LOCK_UNAVAILABLE', 'blocked', array(), $mutation));
		$result = $this->service($store, $b2)->execute($this->command());
		self::assertSame(409, $result->http_status());
		self::assertSame(Activation_Operation_State::RECONCILIATION_REQUIRED, $store->record['state']);
	}

	public function test_success_persistence_failure_returns_reconciliation_without_claiming_success(): void {
		$mutation = Activation_Mutation_Evidence::from_verified_gateway(new \TurmasBridge\Activation\Form_Activation_Outcome('INACTIVE', true, 'ACTIVE', true), '2099:E2F', 10, array('publication_key' => '2099:E2F', 'form_id' => 10, 'form_state' => 'active'));
		$store = new Remote_Activation_Fake_Store(); $store->fail_success_transition = true; $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok', array(), $mutation)); $service = $this->service($store, $b2); $result = $service->execute($this->command()); $replay = $service->execute($this->command());

		self::assertSame(409, $result->http_status()); self::assertSame('reconciliation_required', $result->code()); self::assertSame(Activation_Operation_State::RECONCILIATION_REQUIRED, $store->record['state']); self::assertSame(1, $b2->calls); self::assertSame(409, $replay->http_status()); self::assertSame(1, $b2->calls); self::assertTrue(Activation_Mutation_Evidence::is_confirmed_persisted($store->record['evidence_json']['activation_mutation'] ?? null, 10));
	}

	public function test_invalid_request_does_not_reserve_or_call_b2(): void {
		$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $command = $this->command(); $command['expected_form_id'] = '10'; $result = $this->service($store, $b2)->execute($command);

		self::assertSame(400, $result->http_status()); self::assertSame(0, $store->reserves); self::assertSame(0, $b2->calls);
	}

	public function test_invalid_fingerprint_and_form_values_are_rejected_before_ledger(): void {
		foreach (array('', str_repeat('a', 63), str_repeat('a', 65), str_repeat('g', 64)) as $fingerprint) {
			$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $command = $this->command(); $command['snapshot_fingerprint'] = $fingerprint; $result = $this->service($store, $b2)->execute($command);
			self::assertSame(400, $result->http_status()); self::assertSame(0, $store->reserves); self::assertSame(0, $b2->calls);
		}
		foreach (array(0, -1, '10') as $form_id) {
			$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $command = $this->command(); $command['expected_form_id'] = $form_id; $result = $this->service($store, $b2)->execute($command);
			self::assertSame(400, $result->http_status()); self::assertSame(0, $store->reserves); self::assertSame(0, $b2->calls);
		}
	}

	public function test_unsupported_schema_and_extra_fields_are_rejected(): void {
		$store = new Remote_Activation_Fake_Store(); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $command = $this->command(); $command['schema_version'] = '2'; $result = $this->service($store, $b2)->execute($command);
		self::assertSame(400, $result->http_status()); self::assertSame(0, $store->reserves);

		$store = new Remote_Activation_Fake_Store(); $command = $this->command(); $command['extra'] = 'rejected'; $result = $this->service($store, $b2)->execute($command);
		self::assertSame(400, $result->http_status()); self::assertSame(0, $store->reserves);
	}

	public function test_status_lookup_is_read_only_and_does_not_change_ledger(): void {
		$store = new Remote_Activation_Fake_Store(); $store->seed(Activation_Operation_State::SUCCEEDED); $b2 = new Remote_Activation_Fake_B2(new Activation_Result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'ok')); $service = $this->service($store, $b2); $before = $store->record;
		$result = $service->status('publish-2099:E2F-v1');

		self::assertSame(200, $result->http_status()); self::assertSame($before, $store->record); self::assertSame(0, $b2->calls);
	}

	public function test_status_preserves_original_error_alongside_reconciliation_required_state(): void {
		$store = new Remote_Activation_Fake_Store(); $store->seed(Activation_Operation_State::RECONCILIATION_REQUIRED); $store->record['error_code'] = 'POST_ACTIVATION_DRIFT';
		$result = (new Remote_Activation_Command_Service(new Activation_Operation_Orchestrator($store), new Remote_Activation_Fake_B2(null)))->status('publish-2099:E2F-v1');

		self::assertSame(409, $result->http_status()); self::assertSame(Activation_Operation_State::RECONCILIATION_REQUIRED, $result->to_array()['state']); self::assertSame('POST_ACTIVATION_DRIFT', $result->to_array()['error_code']);
	}

	public function test_status_preserves_failed_error_code(): void {
		$store = new Remote_Activation_Fake_Store(); $store->seed(Activation_Operation_State::FAILED); $store->record['error_code'] = 'FORM_NOT_FOUND';
		$result = (new Remote_Activation_Command_Service(new Activation_Operation_Orchestrator($store), new Remote_Activation_Fake_B2(null)))->status('publish-2099:E2F-v1');

		self::assertSame('FORM_NOT_FOUND', $result->to_array()['error_code']); self::assertSame(Activation_Operation_State::FAILED, $result->to_array()['state']);
	}

	public function test_status_exposes_null_error_for_succeeded_operation(): void {
		$store = new Remote_Activation_Fake_Store(); $store->seed(Activation_Operation_State::SUCCEEDED);
		$result = (new Remote_Activation_Command_Service(new Activation_Operation_Orchestrator($store), new Remote_Activation_Fake_B2(null)))->status('publish-2099:E2F-v1');

		self::assertArrayHasKey('error_code', $result->to_array()); self::assertNull($result->to_array()['error_code']);
		self::assertSame(str_repeat('a', 64), $result->to_array()['snapshot_fingerprint']);
		self::assertSame(array(), $result->to_array()['activation_evidence']);
	}

	/** @param array<string,mixed>|null $outcome */
	private function service(Remote_Activation_Fake_Store $store, Remote_Activation_Fake_B2 $b2): Remote_Activation_Command_Service { return new Remote_Activation_Command_Service(new Activation_Operation_Orchestrator($store), $b2); }
	/** @return array<string,mixed> */
	private function command(): array { return array('schema_version' => '1', 'operation_key' => 'publish-2099:E2F-v1', 'publication_key' => '2099:E2F', 'expected_form_id' => 10, 'snapshot_fingerprint' => str_repeat('a', 64)); }
}

final class Remote_Activation_Fake_B2 implements Activation_Command {
	public int $calls = 0;
	public function __construct(private ?Activation_Result $result, private bool $throws = false) {}
	public function activate(string $publication_key, int $expected_form_id, string $operation_key, string $snapshot_fingerprint): Activation_Result { $this->calls++; if ($this->throws) throw new \RuntimeException('synthetic failure'); return $this->result ?? new Activation_Result(Activation_Result::UNKNOWN, 'unknown', 'unknown'); }
}

final class Remote_Activation_Fake_Store implements Activation_Operation_Store {
	/** @var array<string,mixed> */ public array $record = array(); public int $reserves = 0; public bool $conflict = false; public bool $claim_conflict = false; public bool $fail_success_transition = false; private int $id = 1;
	public function find_by_operation_key(string $operation_key): ?array { return $this->record === array() ? null : $this->record; }
	public function find_by_id(int $id): ?array { return $this->record === array() ? null : $this->record; }
	public function reserve(string $operation_key, string $publication_key, int $gravity_form_id, string $snapshot_fingerprint): Activation_Operation_Reservation { $this->reserves++; if ($this->conflict || ($this->record !== array() && ((string) ($this->record['operation_key'] ?? '') !== $operation_key || (string) ($this->record['publication_key'] ?? '') !== $publication_key || (int) ($this->record['gravity_form_id'] ?? 0) !== $gravity_form_id || (string) ($this->record['snapshot_fingerprint'] ?? '') !== $snapshot_fingerprint))) return new Activation_Operation_Reservation(Activation_Operation_Reservation::CONFLICT, $this->record); if ($this->record === array()) { $this->record = array('id' => $this->id, 'operation_key' => $operation_key, 'publication_key' => $publication_key, 'gravity_form_id' => $gravity_form_id, 'snapshot_fingerprint' => $snapshot_fingerprint, 'state' => Activation_Operation_State::PENDING); return new Activation_Operation_Reservation(Activation_Operation_Reservation::CREATED, $this->record); } return new Activation_Operation_Reservation(Activation_Operation_Reservation::EXISTING_MATCH, $this->record); }
	public function transition(int $id, string $expected_state, string $new_state, ?string $error_code = null, array $evidence = array()): Activation_Operation_Transition { if ($this->claim_conflict && $new_state === Activation_Operation_State::IN_PROGRESS) return new Activation_Operation_Transition(Activation_Operation_Transition::STATE_MISMATCH, $this->record); if ($this->fail_success_transition && $new_state === Activation_Operation_State::SUCCEEDED) return new Activation_Operation_Transition(Activation_Operation_Transition::ERROR, $this->record, 'synthetic_persistence_failure'); if ((string) ($this->record['state'] ?? '') !== $expected_state) return new Activation_Operation_Transition(Activation_Operation_Transition::STATE_MISMATCH, $this->record); $this->record['state'] = $new_state; $this->record['error_code'] = $error_code; $this->record['evidence_json'] = $evidence; return new Activation_Operation_Transition(Activation_Operation_Transition::UPDATED, $this->record); }
	public function recover_stale(int $id, string $expected_updated_at): Activation_Operation_Transition { return $this->transition($id, Activation_Operation_State::IN_PROGRESS, Activation_Operation_State::RECONCILIATION_REQUIRED, 'stale'); }
	public function seed(string $state): void { $this->record = array('id' => 1, 'operation_key' => 'publish-2099:E2F-v1', 'publication_key' => '2099:E2F', 'gravity_form_id' => 10, 'snapshot_fingerprint' => str_repeat('a', 64), 'state' => $state); }
}
