<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Activation\Activation_Operation_Orchestrator;
use TurmasBridge\Activation\Activation_Operation_Repository;
use TurmasBridge\Activation\Activation_Operation_State;
use TurmasBridge\Activation\Activation_Operation_Reservation;
use TurmasBridge\Activation\Activation_Operation_Store;
use TurmasBridge\Activation\Activation_Operation_Transition;

final class ActivationOperationTest extends TestCase {
	public function test_reservation_creates_one_pending_operation_and_same_identity_replays(): void {
		$db = new Activation_Operation_Test_Database(); $repository = new Activation_Operation_Repository($db); $hash = str_repeat('a', 64);
		$first = $repository->reserve('lab-b3a-ledger-v1', '2099:LAB', 901, $hash);
		$again = $repository->reserve('lab-b3a-ledger-v1', '2099:LAB', 901, $hash);

		self::assertSame(Activation_Operation_Reservation::CREATED, $first->result());
		self::assertSame(Activation_Operation_Reservation::EXISTING_MATCH, $again->result());
		self::assertCount(1, $db->records);
		self::assertSame(Activation_Operation_State::PENDING, $db->records[1]['state']);
	}

	public function test_same_key_with_different_fingerprint_is_conflict_without_overwrite(): void {
		$db = new Activation_Operation_Test_Database(); $repository = new Activation_Operation_Repository($db);
		$repository->reserve('lab-b3a-ledger-v2', '2099:LAB', 901, str_repeat('a', 64));
		$result = $repository->reserve('lab-b3a-ledger-v2', '2099:LAB', 901, str_repeat('b', 64));

		self::assertSame(Activation_Operation_Reservation::CONFLICT, $result->result());
		self::assertSame(str_repeat('a', 64), $db->records[1]['snapshot_fingerprint']);
	}

	public function test_same_key_with_different_publication_or_form_is_conflict(): void {
		$db = new Activation_Operation_Test_Database(); $repository = new Activation_Operation_Repository($db); $hash = str_repeat('c', 64);
		$repository->reserve('lab-b3a-ledger-v3', '2099:LAB', 901, $hash);
		self::assertSame(Activation_Operation_Reservation::CONFLICT, $repository->reserve('lab-b3a-ledger-v3', '2099:OTHER', 901, $hash)->result());
		self::assertSame(Activation_Operation_Reservation::CONFLICT, $repository->reserve('lab-b3a-ledger-v3', '2099:LAB', 902, $hash)->result());
	}

	public function test_invalid_identity_is_rejected_before_any_database_write(): void {
		$db = new Activation_Operation_Test_Database(); $repository = new Activation_Operation_Repository($db);
		$result = $repository->reserve('bad key', '2099:LAB', 901, str_repeat('a', 64));

		self::assertSame(Activation_Operation_Reservation::ERROR, $result->result());
		self::assertSame('turmas_bridge_invalid_operation_key', $result->error_code());
		self::assertCount(0, $db->records);
	}

	public function test_compare_and_set_allows_only_one_claim_and_terminal_states_do_not_regress(): void {
		$db = new Activation_Operation_Test_Database(); $repository = new Activation_Operation_Repository($db);
		$record = $repository->reserve('lab-b3a-ledger-v4', '2099:LAB', 901, str_repeat('d', 64))->record();
		$id = (int) ($record['id'] ?? 0);
		$claimed = $repository->transition($id, Activation_Operation_State::PENDING, Activation_Operation_State::IN_PROGRESS);
		$second = $repository->transition($id, Activation_Operation_State::PENDING, Activation_Operation_State::IN_PROGRESS);
		$done = $repository->transition($id, Activation_Operation_State::IN_PROGRESS, Activation_Operation_State::SUCCEEDED, null, array('observed_form_state' => 'ACTIVE', 'observed_inventory_health' => 'READY'));
		$regress = $repository->transition($id, Activation_Operation_State::SUCCEEDED, Activation_Operation_State::IN_PROGRESS);

		self::assertSame(Activation_Operation_Transition::UPDATED, $claimed->result());
		self::assertSame(Activation_Operation_Transition::STATE_MISMATCH, $second->result());
		self::assertSame(Activation_Operation_Transition::UPDATED, $done->result());
		self::assertSame(Activation_Operation_Transition::ERROR, $regress->result());
		self::assertSame(Activation_Operation_State::SUCCEEDED, $db->records[$id]['state']);
	}

	public function test_recovery_marks_stale_in_progress_as_reconciliation_required_with_cas(): void {
		$db = new Activation_Operation_Test_Database(); $repository = new Activation_Operation_Repository($db);
		$record = $repository->reserve('lab-b3a-ledger-v5', '2099:LAB', 901, str_repeat('e', 64))->record(); $id = (int) $record['id'];
		$repository->transition($id, Activation_Operation_State::PENDING, Activation_Operation_State::IN_PROGRESS);
		$updated = $repository->find_by_id($id); $recovered = $repository->recover_stale($id, (string) $updated['updated_at']);

		self::assertSame(Activation_Operation_Transition::UPDATED, $recovered->result());
		self::assertSame(Activation_Operation_State::RECONCILIATION_REQUIRED, $db->records[$id]['state']);
		self::assertSame('turmas_bridge_stale_activation', $db->records[$id]['error_code']);
	}

	public function test_evidence_contract_rejects_unknown_or_nested_data_before_write(): void {
		$db = new Activation_Operation_Test_Database(); $repository = new Activation_Operation_Repository($db);
		$record = $repository->reserve('lab-b3a-ledger-v5b', '2099:LAB', 901, str_repeat('e', 64))->record(); $id = (int) $record['id'];
		$repository->transition($id, Activation_Operation_State::PENDING, Activation_Operation_State::IN_PROGRESS);
		$result = $repository->transition($id, Activation_Operation_State::IN_PROGRESS, Activation_Operation_State::SUCCEEDED, null, array('Authorization' => 'forbidden'));

		self::assertSame(Activation_Operation_Transition::ERROR, $result->result());
		self::assertSame('turmas_bridge_activation_evidence_invalid', $result->error_code());
		self::assertSame(Activation_Operation_State::IN_PROGRESS, $db->records[$id]['state']);
	}

	public function test_orchestrator_exposes_only_ledger_transitions(): void {
		$store = new Activation_Operation_Fake_Store(); $orchestrator = new Activation_Operation_Orchestrator($store);
		$orchestrator->reserve('lab-b3a-ledger-v6', '2099:LAB', 901, str_repeat('f', 64));
		$orchestrator->claim(1); $orchestrator->succeed(1, array('postcondition' => 'verified'));

		self::assertSame(array('reserve', 'transition:PENDING>IN_PROGRESS', 'transition:IN_PROGRESS>SUCCEEDED'), $store->calls);
		self::assertNotContains('activate', $store->calls);
	}

	public function test_state_machine_rejects_unknown_states_and_only_allows_forward_transitions(): void {
		self::assertTrue(Activation_Operation_State::can_transition(Activation_Operation_State::PENDING, Activation_Operation_State::IN_PROGRESS));
		self::assertTrue(Activation_Operation_State::can_transition(Activation_Operation_State::IN_PROGRESS, Activation_Operation_State::RECONCILIATION_REQUIRED));
		self::assertFalse(Activation_Operation_State::can_transition(Activation_Operation_State::SUCCEEDED, Activation_Operation_State::IN_PROGRESS));
		self::assertFalse(Activation_Operation_State::can_transition('UNKNOWN', Activation_Operation_State::SUCCEEDED));
		self::assertCount(5, Activation_Operation_State::values());
	}
}

final class Activation_Operation_Test_Database extends \wpdb {
	/** @var array<int,array<string,mixed>> */ public array $records = array();
	private array $arguments = array(); private int $next_id = 1;
	public function prepare(string $query, mixed ...$arguments): string { $this->arguments = $arguments; return $query; }
	public function insert(string $table, array $data): int|false {
		foreach ($this->records as $record) if ((string) $record['operation_key'] === (string) $data['operation_key']) return false;
		$id = $this->next_id++; $data['id'] = $id; $this->records[$id] = $data; return 1;
	}
	public function get_row(string $query, string $output = ''): mixed {
		if (str_contains($query, 'WHERE operation_key')) foreach ($this->records as $record) if ((string) $record['operation_key'] === (string) ($this->arguments[0] ?? '')) return $record;
		if (str_contains($query, 'WHERE id =')) return $this->records[(int) ($this->arguments[0] ?? 0)] ?? null;
		return null;
	}
	public function query(string $query): int|false {
		$args = $this->arguments;
		if (str_contains($query, 'updated_at = %s WHERE id = %d AND state = %s AND updated_at = %s')) {
			$id = (int) ($args[4] ?? 0); if (! isset($this->records[$id]) || (string) $this->records[$id]['state'] !== (string) $args[5] || (string) $this->records[$id]['updated_at'] !== (string) $args[6]) return 0;
			$this->records[$id] = array_merge($this->records[$id], array('state' => $args[0], 'error_code' => $args[1], 'reconciliation_at' => $args[2], 'updated_at' => $args[3])); return 1;
		}
		if (str_contains($query, 'WHERE id = %d AND state = %s')) {
			$id = (int) ($args[count($args) - 2] ?? 0); $expected = (string) ($args[count($args) - 1] ?? '');
			if (! isset($this->records[$id]) || (string) $this->records[$id]['state'] !== $expected) return 0;
			if (str_contains($query, 'processing_started_at = %s')) $this->records[$id] = array_merge($this->records[$id], array('state' => $args[0], 'processing_started_at' => $args[1], 'updated_at' => $args[2]));
			else { $this->records[$id] = array_merge($this->records[$id], array('state' => $args[0], 'error_code' => $args[1], 'evidence_json' => $args[2], 'updated_at' => $args[3])); if (str_contains($query, 'reconciliation_at = %s')) $this->records[$id]['reconciliation_at'] = $args[4]; }
			return 1;
		}
		return 0;
	}
}

final class Activation_Operation_Fake_Store implements Activation_Operation_Store {
	/** @var list<string> */ public array $calls = array();
	public function find_by_operation_key(string $operation_key): ?array { return null; }
	public function find_by_id(int $id): ?array { return null; }
	public function reserve(string $operation_key, string $publication_key, int $gravity_form_id, string $snapshot_fingerprint): Activation_Operation_Reservation { $this->calls[] = 'reserve'; return new Activation_Operation_Reservation(Activation_Operation_Reservation::CREATED, array('id' => 1)); }
	public function transition(int $id, string $expected_state, string $new_state, ?string $error_code = null, array $evidence = array()): Activation_Operation_Transition { $this->calls[] = 'transition:' . $expected_state . '>' . $new_state; return new Activation_Operation_Transition(Activation_Operation_Transition::UPDATED, array('id' => $id, 'state' => $new_state)); }
	public function recover_stale(int $id, string $expected_updated_at): Activation_Operation_Transition { $this->calls[] = 'recover'; return new Activation_Operation_Transition(Activation_Operation_Transition::UPDATED, array('id' => $id)); }
}
