<?php
declare(strict_types=1);
namespace TurmasBridge\Tests\Capacity;
use PHPUnit\Framework\TestCase;
use TurmasBridge\Capacity\Capacity_Repository;
use TurmasBridge\Capacity\Capacity_State;

final class CapacityPersistenceTest extends TestCase {
	public function test_cas_where_and_append_only_history_are_persisted(): void {
		$db = new CapacityPersistenceDatabase(); $repo = new Capacity_Repository($db);
		$r = array('id' => 800, 'operation_key' => 'synthetic-key', 'state' => 'PENDING', 'revision' => 1, 'attempts' => 0, 'evidence_json' => '[]');
		$n = $repo->transition($r, 'APPLYING', array('capacity_before' => 8), null, true);
		self::assertSame(2, $n['revision']); self::assertSame(1, $n['attempts']);
		self::assertSame('PENDING', $db->where['state']); self::assertSame(1, $db->where['revision']);
		self::assertSame('synthetic-key', $db->where['operation_key']);
		self::assertCount(1, json_decode($n['evidence_json'], true));
		self::assertArrayHasKey('updated_at', $n);
		$db->affected = 0;
		self::assertNull($repo->transition($r, 'APPLYING', array(), null));
	}
	public function test_terminal_cannot_return_to_pending_or_apply_and_unknown_states_rejected(): void {
		self::assertFalse(Capacity_State::allows('APPLIED_VERIFIED', 'PENDING'));
		self::assertFalse(Capacity_State::allows('APPLIED_VERIFIED', 'APPLYING'));
		self::assertFalse(Capacity_State::allows('FAILED', 'APPLYING'));
		self::assertFalse(Capacity_State::allows('UNKNOWN', 'APPLYING'));
		self::assertFalse(Capacity_State::allows('PENDING', 'SUCCEEDED'));
		$db = new CapacityPersistenceDatabase();
		self::assertNull((new Capacity_Repository($db))->transition(array('state' => 'APPLIED_VERIFIED'), 'APPLYING', array(), null));
		self::assertSame(array(), $db->where);
	}
	public function test_corrupt_history_cannot_be_silently_replaced(): void {
		$db = new CapacityPersistenceDatabase();
		self::assertNull((new Capacity_Repository($db))->transition(array('state' => 'PENDING', 'evidence_json' => '{invalid'), 'APPLYING', array(), null));
		self::assertSame(array(), $db->where);
	}
}
final class CapacityPersistenceDatabase extends \wpdb {
	public int $affected = 1; public array $where = array();
	public function update(string $table, array $data, array $where): int|false { $this->where = $where; return $this->affected; }
}
