<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Activation\Activation_Command;
use TurmasBridge\Activation\Activation_Mutation_Evidence;
use TurmasBridge\Activation\Activation_Operation_Orchestrator;
use TurmasBridge\Activation\Activation_Operation_Reservation;
use TurmasBridge\Activation\Activation_Operation_State;
use TurmasBridge\Activation\Activation_Operation_Store;
use TurmasBridge\Activation\Activation_Operation_Transition;
use TurmasBridge\Activation\Activation_Result;
use TurmasBridge\Activation\Form_Activation_Gateway;
use TurmasBridge\Activation\Form_Activation_Outcome;
use TurmasBridge\Activation\Form_Activation_Service;
use TurmasBridge\Activation\Remote_Activation_Command_Service;
use TurmasBridge\Materialization\Materialization_Store;
use TurmasBridge\Publications\Post_Activation_Status_Provider;
use TurmasBridge\Publications\Publication_Status_Provider;
use TurmasBridge\Reconciliation\Reconciliation_Attempt_Reservation;
use TurmasBridge\Reconciliation\Reconciliation_Attempt_State;
use TurmasBridge\Reconciliation\Reconciliation_Attempt_Store;
use TurmasBridge\Reconciliation\Reconciliation_Attempt_Transition;
use TurmasBridge\Reconciliation\Reconciliation_Service;

final class CausalEvidenceReachabilityTest extends TestCase {
	private const OPERATION = 'activate-lab-b3b3c-01';
	private const PUBLICATION = '2099:B3B3C';
	private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	public function test_confirmed_mutation_survives_command_ledger_and_reconciles_after_post_activation_drift(): void {
		$ledger = new C1ActivationLedger(); $gateway = new C1ActivationGateway(); $status = new C1StatusProvider(); $lock = new C1ActivationLock();
		$b2 = new Form_Activation_Service($status, $gateway, $lock);
		$command = new Remote_Activation_Command_Service(new Activation_Operation_Orchestrator($ledger), $b2);
		$activation = $command->execute($this->activation_command());

		self::assertSame(409, $activation->http_status()); self::assertSame(Activation_Operation_State::RECONCILIATION_REQUIRED, $activation->state());
		self::assertSame('POST_ACTIVATION_DRIFT', $ledger->record['error_code']); self::assertSame(1, $gateway->calls); self::assertTrue($gateway->active);
		$persisted = json_decode((string) $ledger->record['evidence_json'], true);
		self::assertTrue(Activation_Mutation_Evidence::is_confirmed_persisted($persisted['activation_mutation'] ?? null, 901));
		self::assertSame(1, $status->post_reads); self::assertSame('INACTIVE', $gateway->initial_state);

		$attempts = new C1ReconciliationAttempts();
		$reconciler = new Reconciliation_Service($ledger, $attempts, $status);
		$reconciliation = $reconciler->execute($this->reconciliation_command());

		self::assertSame(201, $reconciliation->http_status()); self::assertSame(Reconciliation_Attempt_State::CONFIRMED_SUCCESS, $reconciliation->state());
		self::assertSame('CONFIRMED_ACTIVATION_OUTCOME', $reconciliation->code()); self::assertSame(1, $gateway->calls, 'Reconciliation must never invoke activation again.');
		self::assertSame(Activation_Operation_State::RECONCILIATION_REQUIRED, $ledger->record['state']); self::assertSame('POST_ACTIVATION_DRIFT', $ledger->record['error_code']);
		self::assertSame(2, $status->post_reads, 'One post-read occurred during activation and one fresh read during reconciliation.');
		self::assertSame(1, $status->reconciliation_reads); self::assertSame(1, count($attempts->records));
	}

	public function test_mutation_attempt_without_immediate_active_confirmation_stays_weak(): void {
		$outcome = new Form_Activation_Outcome('INACTIVE', true, 'ACTIVE', true, null);
		$mutation = Activation_Mutation_Evidence::from_verified_gateway($outcome, self::PUBLICATION, 901, array('publication_key' => self::PUBLICATION, 'form_id' => 901, 'form_state' => 'inactive'));

		self::assertSame('OUTCOME_UNCERTAIN', $mutation->state()); self::assertFalse(Activation_Mutation_Evidence::is_confirmed_persisted($mutation->to_array(), 901));
	}

	/** @return array<string,mixed> */
	private function activation_command(): array { return array('schema_version' => '1', 'operation_key' => self::OPERATION, 'publication_key' => self::PUBLICATION, 'expected_form_id' => 901, 'snapshot_fingerprint' => self::FINGERPRINT); }
	/** @return array<string,mixed> */
	private function reconciliation_command(): array { return array('schema_version' => '1', 'reconciliation_key' => 'reconcile-lab-b3b3c-01', 'activation_operation_key' => self::OPERATION, 'publication_key' => self::PUBLICATION, 'expected_form_id' => 901, 'snapshot_fingerprint' => self::FINGERPRINT); }
}

final class C1ActivationLedger implements Activation_Operation_Store {
	/** @var array<string,mixed> */ public array $record = array();
	public function find_by_operation_key(string $operation_key): ?array { return $operation_key === (string) ($this->record['operation_key'] ?? '') ? $this->record : null; }
	public function find_by_id(int $id): ?array { return $id === (int) ($this->record['id'] ?? 0) ? $this->record : null; }
	public function reserve(string $operation_key, string $publication_key, int $gravity_form_id, string $snapshot_fingerprint): Activation_Operation_Reservation {
		if ($this->record !== array()) return new Activation_Operation_Reservation(Activation_Operation_Reservation::EXISTING_MATCH, $this->record);
		$this->record = array('id' => 1, 'operation_key' => $operation_key, 'publication_key' => $publication_key, 'gravity_form_id' => $gravity_form_id, 'snapshot_fingerprint' => $snapshot_fingerprint, 'state' => Activation_Operation_State::PENDING, 'error_code' => null, 'evidence_json' => null);
		return new Activation_Operation_Reservation(Activation_Operation_Reservation::CREATED, $this->record);
	}
	public function transition(int $id, string $expected_state, string $new_state, ?string $error_code = null, array $evidence = array()): Activation_Operation_Transition {
		if ((int) ($this->record['id'] ?? 0) !== $id || (string) ($this->record['state'] ?? '') !== $expected_state) return new Activation_Operation_Transition(Activation_Operation_Transition::STATE_MISMATCH, $this->record);
		$this->record['state'] = $new_state; $this->record['error_code'] = $error_code; $this->record['evidence_json'] = $evidence === array() ? null : json_encode($evidence);
		return new Activation_Operation_Transition(Activation_Operation_Transition::UPDATED, $this->record);
	}
	public function recover_stale(int $id, string $expected_updated_at): Activation_Operation_Transition { return new Activation_Operation_Transition(Activation_Operation_Transition::ERROR); }
}

final class C1ActivationGateway implements Form_Activation_Gateway {
	public int $calls = 0; public bool $active = false; public string $initial_state = 'INACTIVE';
	public function is_available(): bool { return true; }
	public function read(int $form_id): ?array { return array('id' => $form_id, 'is_active' => $this->active); }
	public function activate(int $form_id): Form_Activation_Outcome { $this->calls++; $this->active = true; return new Form_Activation_Outcome('INACTIVE', true, 'ACTIVE', true); }
}

final class C1StatusProvider implements Publication_Status_Provider, Post_Activation_Status_Provider {
	public int $post_reads = 0; public int $reconciliation_reads = 0;
	public function read(string $publication_key): array|\WP_Error { return $this->state('MATERIALIZED', 'inactive', 'READY'); }
	public function read_post_activation(string $publication_key): array|\WP_Error {
		$this->post_reads++;
		if ($this->post_reads === 1) return $this->state('RECONCILIATION_REQUIRED', 'active', 'BLOCKED');
		$this->reconciliation_reads++;
		return $this->state('POST_ACTIVATION_VERIFIED', 'active', 'READY');
	}
	/** @return array<string,mixed> */
	private function state(string $effective, string $form_state, string $inventory): array { return array('publication_key' => '2099:B3B3C', 'effective_state' => $effective, 'form_id' => 901, 'form_state' => $form_state, 'inventory' => array('status' => $inventory, 'resources' => array(array('resource_id' => 902, 'capacity' => 3, 'consumed' => 0, 'healthy' => true)))); }
}

final class C1ActivationLock implements Materialization_Store {
	public function find(string $publication_key): ?array { return null; }
	public function reserve(string $publication_key, int $template_id, string $payload_hash): bool { return false; }
	public function materialized(string $publication_key, int $form_id, array $field_ids): bool { return false; }
	public function failed(string $publication_key, ?int $form_id, string $error_code): bool { return false; }
	public function choice_fingerprint(string $publication_key, string $fingerprint): bool { return false; }
	public function acquire_choice_lock(string $publication_key): bool { return true; }
	public function release_choice_lock(string $publication_key): void {}
}

final class C1ReconciliationAttempts implements Reconciliation_Attempt_Store {
	/** @var array<string,array<string,mixed>> */ public array $records = array(); private int $id = 1;
	public function find_by_key(string $reconciliation_key): ?array { return $this->records[$reconciliation_key] ?? null; }
	public function find_latest_for_activation(string $activation_operation_key): ?array { return null; }
	public function reserve(string $key, string $activation, string $publication, int $form_id, string $fingerprint): Reconciliation_Attempt_Reservation {
		if (isset($this->records[$key])) return new Reconciliation_Attempt_Reservation(Reconciliation_Attempt_Reservation::EXISTING_MATCH, $this->records[$key]);
		$this->records[$key] = array('id' => $this->id++, 'reconciliation_key' => $key, 'activation_operation_key' => $activation, 'publication_key' => $publication, 'gravity_form_id' => $form_id, 'snapshot_fingerprint' => $fingerprint, 'state' => Reconciliation_Attempt_State::PENDING);
		return new Reconciliation_Attempt_Reservation(Reconciliation_Attempt_Reservation::CREATED, $this->records[$key]);
	}
	public function claim(int $id): Reconciliation_Attempt_Transition { foreach ($this->records as &$record) if ((int) $record['id'] === $id && $record['state'] === Reconciliation_Attempt_State::PENDING) { $record['state'] = Reconciliation_Attempt_State::IN_PROGRESS; return new Reconciliation_Attempt_Transition(Reconciliation_Attempt_Transition::UPDATED, $record); } return new Reconciliation_Attempt_Transition(Reconciliation_Attempt_Transition::STATE_MISMATCH); }
	public function complete(int $id, string $expected_state, string $state, string $result_code, array $evidence): Reconciliation_Attempt_Transition { foreach ($this->records as &$record) if ((int) $record['id'] === $id && $record['state'] === $expected_state) { $record['state'] = $state; $record['result_code'] = $result_code; $record['evidence_json'] = json_encode($evidence); return new Reconciliation_Attempt_Transition(Reconciliation_Attempt_Transition::UPDATED, $record); } return new Reconciliation_Attempt_Transition(Reconciliation_Attempt_Transition::STATE_MISMATCH); }
}
