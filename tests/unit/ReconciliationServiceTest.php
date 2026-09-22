<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Activation\Activation_Operation_Reservation;
use TurmasBridge\Activation\Activation_Operation_State;
use TurmasBridge\Activation\Activation_Operation_Store;
use TurmasBridge\Activation\Activation_Operation_Transition;
use TurmasBridge\Publications\Post_Activation_Status_Provider;
use TurmasBridge\Reconciliation\Reconciliation_Attempt_Reservation;
use TurmasBridge\Reconciliation\Reconciliation_Attempt_State;
use TurmasBridge\Reconciliation\Reconciliation_Attempt_Store;
use TurmasBridge\Reconciliation\Reconciliation_Attempt_Transition;
use TurmasBridge\Reconciliation\Reconciliation_Service;

final class ReconciliationServiceTest extends TestCase {
	private const OPERATION = 'activate-2099-e2e';
	private const PUBLICATION = '2099:E2E';
	private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	public function test_healthy_verification_with_historical_mutation_evidence_is_confirmed(): void {
		$attempts = new FakeReconciliationAttempts(); $verifier = new FakePostActivationVerifier($this->healthy());
		$result = $this->service($attempts, $verifier, $this->operation(true))->execute($this->command('reconcile-1'));

		self::assertSame(201, $result->http_status()); self::assertSame('CONFIRMED_ACTIVATION_OUTCOME', $result->code()); self::assertSame(Reconciliation_Attempt_State::CONFIRMED_SUCCESS, $result->state());
		self::assertSame(1, $verifier->calls); self::assertSame(0, $attempts->operation_mutations); self::assertFalse($result->to_array()['evidence']['historical_causality'] === false);
	}

	public function test_active_form_without_causal_evidence_is_inconclusive(): void {
		$result = $this->service(new FakeReconciliationAttempts(), new FakePostActivationVerifier($this->healthy()), $this->operation(false))->execute($this->command('reconcile-2'));

		self::assertSame(201, $result->http_status()); self::assertSame('INSUFFICIENT_CAUSAL_EVIDENCE', $result->code()); self::assertSame(Reconciliation_Attempt_State::INCONCLUSIVE, $result->state());
	}

	public function test_legacy_attempt_only_and_claimed_confirmation_fields_are_not_trusted(): void {
		foreach (array(array('mutation_attempted' => true), array('gateway' => array('mutation_attempted' => true)), array('activation_mutation_confirmed' => true), array('mutation_attempted' => 'true'), array('mutation_attempted' => 1)) as $legacy) {
			$operation = $this->operation(false); $operation['evidence_json'] = json_encode($legacy);
			$result = $this->service(new FakeReconciliationAttempts(), new FakePostActivationVerifier($this->healthy()), $operation)->execute($this->command('legacy-' . count($legacy) . '-' . md5((string) json_encode($legacy))));
			self::assertSame(Reconciliation_Attempt_State::INCONCLUSIVE, $result->state()); self::assertSame('INSUFFICIENT_CAUSAL_EVIDENCE', $result->code());
		}
	}

	public function test_canonical_confirmation_must_match_version_source_form_and_exact_types(): void {
		$invalid = array(
			array('version' => '1', 'state' => 'MUTATION_CONFIRMED', 'form_id' => 10, 'source' => 'b2_gateway_success_and_post_read', 'observed_form_state' => 'active'),
			array('version' => 1, 'state' => 'MUTATION_CONFIRMED', 'form_id' => 11, 'source' => 'b2_gateway_success_and_post_read', 'observed_form_state' => 'active'),
			array('version' => 1, 'state' => 'MUTATION_CONFIRMED', 'form_id' => 10, 'source' => 'client', 'observed_form_state' => 'active'),
			array('version' => 1, 'state' => 'MUTATION_ATTEMPTED', 'form_id' => 10, 'source' => 'b2_gateway_error', 'observed_form_state' => 'unknown'),
			array('version' => 2, 'state' => 'MUTATION_CONFIRMED', 'form_id' => 10, 'source' => 'b2_gateway_success_and_post_read', 'observed_form_state' => 'active'),
		);
		foreach ($invalid as $index => $canonical) {
			$operation = $this->operation(false); $operation['evidence_json'] = json_encode(array('activation_mutation' => $canonical));
			$result = $this->service(new FakeReconciliationAttempts(), new FakePostActivationVerifier($this->healthy()), $operation)->execute($this->command('invalid-canonical-' . $index));
			self::assertSame(Reconciliation_Attempt_State::INCONCLUSIVE, $result->state()); self::assertSame('INSUFFICIENT_CAUSAL_EVIDENCE', $result->code());
		}
	}

	public function test_structural_drift_is_inconclusive_even_with_causal_evidence(): void {
		$fresh = $this->healthy(); $fresh['inventory']['resources'][0]['consumed'] = 6;
		$result = $this->service(new FakeReconciliationAttempts(), new FakePostActivationVerifier($fresh), $this->operation(true))->execute($this->command('reconcile-3'));

		self::assertSame('STRUCTURAL_DRIFT', $result->code()); self::assertSame(Reconciliation_Attempt_State::INCONCLUSIVE, $result->state());
	}

	public function test_consumption_can_change_without_invalidating_a_healthy_result(): void {
		$fresh = $this->healthy(); $fresh['inventory']['resources'][0]['consumed'] = 2;
		$result = $this->service(new FakeReconciliationAttempts(), new FakePostActivationVerifier($fresh), $this->operation(true))->execute($this->command('reconcile-4'));

		self::assertSame('CONFIRMED_ACTIVATION_OUTCOME', $result->code()); self::assertSame(2, $result->to_array()['evidence']['consumed']);
	}

	public function test_missing_or_inactive_form_is_structural_drift(): void {
		$fresh = $this->healthy(); $fresh['form_state'] = 'inactive'; $fresh['effective_state'] = 'RECONCILIATION_REQUIRED';
		$result = $this->service(new FakeReconciliationAttempts(), new FakePostActivationVerifier($fresh), $this->operation(true))->execute($this->command('reconcile-5'));

		self::assertSame('STRUCTURAL_DRIFT', $result->code()); self::assertSame(Reconciliation_Attempt_State::INCONCLUSIVE, $result->state());
	}

	public function test_missing_resource_identity_is_not_treated_as_healthy(): void {
		$fresh = $this->healthy(); unset($fresh['inventory']['resources'][0]['resource_id']);
		$result = $this->service(new FakeReconciliationAttempts(), new FakePostActivationVerifier($fresh), $this->operation(true))->execute($this->command('reconcile-5b'));

		self::assertSame('STRUCTURAL_DRIFT', $result->code()); self::assertSame(Reconciliation_Attempt_State::INCONCLUSIVE, $result->state());
	}

	public function test_wrong_identity_is_rejected_before_read(): void {
		$verifier = new FakePostActivationVerifier($this->healthy()); $command = $this->command('reconcile-6'); $command['expected_form_id'] = 99;
		$result = $this->service(new FakeReconciliationAttempts(), $verifier, $this->operation(true))->execute($command);

		self::assertSame(409, $result->http_status()); self::assertSame('reconciliation_identity_conflict', $result->code()); self::assertSame(0, $verifier->calls);
	}

	public function test_same_terminal_key_replays_without_a_second_read(): void {
		$attempts = new FakeReconciliationAttempts(); $verifier = new FakePostActivationVerifier($this->healthy()); $service = $this->service($attempts, $verifier, $this->operation(true));
		$first = $service->execute($this->command('reconcile-7')); $second = $service->execute($this->command('reconcile-7'));

		self::assertSame('CONFIRMED_ACTIVATION_OUTCOME', $first->code()); self::assertSame(200, $second->http_status()); self::assertTrue($second->to_array()['idempotent_replay']); self::assertSame(1, $verifier->calls);
	}

	public function test_new_key_is_allowed_after_inconclusive_attempt(): void {
		$attempts = new FakeReconciliationAttempts(); $service = $this->service($attempts, new FakePostActivationVerifier($this->healthy()), $this->operation(false));
		$first = $service->execute($this->command('reconcile-8')); $second = $service->execute($this->command('reconcile-9'));

		self::assertSame(Reconciliation_Attempt_State::INCONCLUSIVE, $first->state()); self::assertSame(Reconciliation_Attempt_State::INCONCLUSIVE, $second->state()); self::assertCount(2, $attempts->records);
	}

	public function test_claim_compare_and_swap_failure_does_not_verify(): void {
		$attempts = new FakeReconciliationAttempts(); $attempts->claim_result = Reconciliation_Attempt_Transition::STATE_MISMATCH; $verifier = new FakePostActivationVerifier($this->healthy());
		$result = $this->service($attempts, $verifier, $this->operation(true))->execute($this->command('reconcile-10'));

		self::assertSame(503, $result->http_status()); self::assertSame('reconciliation_claim_failed', $result->code()); self::assertSame(0, $verifier->calls);
	}

	public function test_verifier_error_is_technical_failure_and_not_success(): void {
		$result = $this->service(new FakeReconciliationAttempts(), new FakePostActivationVerifier(new \WP_Error('reader_failed')), $this->operation(true))->execute($this->command('reconcile-11'));

		self::assertSame(503, $result->http_status()); self::assertSame(Reconciliation_Attempt_State::TECHNICAL_FAILURE, $result->state()); self::assertSame('reconciliation_verification_unavailable', $result->code());
	}

	public function test_persistence_failure_is_reported_conservatively(): void {
		$attempts = new FakeReconciliationAttempts(); $attempts->complete_result = Reconciliation_Attempt_Transition::ERROR;
		$result = $this->service($attempts, new FakePostActivationVerifier($this->healthy()), $this->operation(true))->execute($this->command('reconcile-12'));

		self::assertSame(503, $result->http_status()); self::assertSame('reconciliation_persistence_failed', $result->code());
	}

	public function test_operation_state_conflict_preserves_original_ledger(): void {
		$operation = $this->operation(true); $operation['state'] = Activation_Operation_State::SUCCEEDED; $attempts = new FakeReconciliationAttempts();
		$result = $this->service($attempts, new FakePostActivationVerifier($this->healthy()), $operation)->execute($this->command('reconcile-13'));

		self::assertSame(409, $result->http_status()); self::assertSame('reconciliation_state_conflict', $result->code()); self::assertSame(0, $attempts->reserve_calls);
	}

	/** @param array<string,mixed> $operation */
	private function service(FakeReconciliationAttempts $attempts, FakePostActivationVerifier $verifier, array $operation): Reconciliation_Service { return new Reconciliation_Service(new FakeActivationOperations($operation, $attempts), $attempts, $verifier); }
	/** @return array<string,mixed> */
	private function operation(bool $causal): array { $strong = array('version' => 1, 'state' => 'MUTATION_CONFIRMED', 'form_id' => 10, 'source' => 'b2_gateway_success_and_post_read', 'observed_form_state' => 'active'); return array('id' => 1, 'operation_key' => self::OPERATION, 'publication_key' => self::PUBLICATION, 'gravity_form_id' => 10, 'snapshot_fingerprint' => self::FINGERPRINT, 'state' => Activation_Operation_State::RECONCILIATION_REQUIRED, 'error_code' => 'POST_ACTIVATION_DRIFT', 'evidence_json' => json_encode($causal ? array('activation_mutation' => $strong) : array('reason_code' => 'POST_ACTIVATION_DRIFT'))); }
	/** @return array<string,mixed> */
	private function command(string $key): array { return array('schema_version' => '1', 'reconciliation_key' => $key, 'activation_operation_key' => self::OPERATION, 'publication_key' => self::PUBLICATION, 'expected_form_id' => 10, 'snapshot_fingerprint' => self::FINGERPRINT); }
	/** @return array<string,mixed> */
	private function healthy(): array { return array('publication_key' => self::PUBLICATION, 'effective_state' => 'POST_ACTIVATION_VERIFIED', 'form_id' => 10, 'form_state' => 'active', 'inventory' => array('status' => 'READY', 'resources' => array(array('resource_id' => 11, 'capacity' => 5, 'consumed' => 0, 'healthy' => true)))); }
}

final class FakeActivationOperations implements Activation_Operation_Store {
	/** @param array<string,mixed> $record */ public function __construct(private array $record, private FakeReconciliationAttempts $counter) {}
	public function find_by_operation_key(string $operation_key): ?array { return $operation_key === (string) $this->record['operation_key'] ? $this->record : null; }
	public function find_by_id(int $id): ?array { return $id === (int) $this->record['id'] ? $this->record : null; }
	public function reserve(string $operation_key, string $publication_key, int $gravity_form_id, string $snapshot_fingerprint): Activation_Operation_Reservation { throw new \LogicException('not used'); }
	public function transition(int $id, string $expected_state, string $new_state, ?string $error_code = null, array $evidence = array()): Activation_Operation_Transition { $this->counter->operation_mutations++; throw new \LogicException('reconciliation must not mutate activation ledger'); }
	public function recover_stale(int $id, string $expected_updated_at): Activation_Operation_Transition { throw new \LogicException('not used'); }
}

final class FakeReconciliationAttempts implements Reconciliation_Attempt_Store {
	/** @var array<string,array<string,mixed>> */ public array $records = array(); public int $reserve_calls = 0; public int $operation_mutations = 0; public string $claim_result = Reconciliation_Attempt_Transition::UPDATED; public string $complete_result = Reconciliation_Attempt_Transition::UPDATED; private int $next_id = 1;
	public function find_by_key(string $reconciliation_key): ?array { return $this->records[$reconciliation_key] ?? null; }
	public function find_latest_for_activation(string $activation_operation_key): ?array { $records = array_filter($this->records, static fn (array $record): bool => $record['activation_operation_key'] === $activation_operation_key); return $records ? array_values($records)[count($records) - 1] : null; }
	public function reserve(string $key, string $activation, string $publication, int $form_id, string $fingerprint): Reconciliation_Attempt_Reservation { $this->reserve_calls++; if (isset($this->records[$key])) { $record = $this->records[$key]; $match = $record['activation_operation_key'] === $activation && $record['publication_key'] === $publication && (int) $record['gravity_form_id'] === $form_id && $record['snapshot_fingerprint'] === $fingerprint; return new Reconciliation_Attempt_Reservation($match ? Reconciliation_Attempt_Reservation::EXISTING_MATCH : Reconciliation_Attempt_Reservation::CONFLICT, $record); } $record = array('id' => $this->next_id++, 'reconciliation_key' => $key, 'activation_operation_key' => $activation, 'publication_key' => $publication, 'gravity_form_id' => $form_id, 'snapshot_fingerprint' => $fingerprint, 'state' => Reconciliation_Attempt_State::PENDING); $this->records[$key] = $record; return new Reconciliation_Attempt_Reservation(Reconciliation_Attempt_Reservation::CREATED, $record); }
	public function claim(int $id): Reconciliation_Attempt_Transition { if ($this->claim_result !== Reconciliation_Attempt_Transition::UPDATED) return new Reconciliation_Attempt_Transition($this->claim_result); foreach ($this->records as &$record) if ((int) $record['id'] === $id) { $record['state'] = Reconciliation_Attempt_State::IN_PROGRESS; return new Reconciliation_Attempt_Transition(Reconciliation_Attempt_Transition::UPDATED, $record); } return new Reconciliation_Attempt_Transition(Reconciliation_Attempt_Transition::STATE_MISMATCH); }
	public function complete(int $id, string $expected_state, string $state, string $result_code, array $evidence): Reconciliation_Attempt_Transition { if ($this->complete_result !== Reconciliation_Attempt_Transition::UPDATED) return new Reconciliation_Attempt_Transition($this->complete_result); foreach ($this->records as &$record) if ((int) $record['id'] === $id && $record['state'] === $expected_state) { $record['state'] = $state; $record['result_code'] = $result_code; $record['evidence_json'] = json_encode($evidence); return new Reconciliation_Attempt_Transition(Reconciliation_Attempt_Transition::UPDATED, $record); } return new Reconciliation_Attempt_Transition(Reconciliation_Attempt_Transition::STATE_MISMATCH); }
}

final class FakePostActivationVerifier implements Post_Activation_Status_Provider {
	public int $calls = 0;
	public function __construct(private array|\WP_Error $result) {}
	public function read_post_activation(string $publication_key): array|\WP_Error { $this->calls++; return $this->result; }
}
