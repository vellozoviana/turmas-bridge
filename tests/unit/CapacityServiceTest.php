<?php
declare(strict_types=1);
namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Capacity\Capacity_Command;
use TurmasBridge\Capacity\Capacity_Service;
use TurmasBridge\Capacity\Capacity_Store;
use TurmasBridge\Capacity\Capacity_Gateway;

final class CapacityServiceTest extends TestCase {
	public static function command(int $capacity = 5, int $version = 2): array {
		$c = array('schema_version' => '1', 'operation_key' => '', 'publication_key' => '2095:UNIT', 'class_key' => '2095:UNIT:01.01', 'expected_form_id' => 800, 'source_turma_id' => 900, 'source_row_version' => $version, 'desired_capacity' => $capacity, 'reason' => 'Synthetic capacity test');
		$c['operation_key'] = Capacity_Command::key($c); return $c;
	}
	public function test_active_form_is_blocked_and_can_be_reexecuted_after_closure(): void {
		$s = new CapacityMemoryStore(); $g = new CapacityFakeGateway(); $g->active = true; $service = new Capacity_Service($s, $g);
		$r = $service->execute(self::command()); self::assertSame('BLOCKED_FORM_ACTIVE', $r['state']); self::assertSame(0, $g->writes); self::assertFalse($g->locked);
		$g->active = false; $r = $service->execute(self::command()); self::assertSame('APPLIED_VERIFIED', $r['state']); self::assertSame(2, $r['attempts']); self::assertSame(1, $g->writes);
	}
	public function test_inactive_form_applies_and_verifies_all_evidence(): void {
		$s = new CapacityMemoryStore(); $g = new CapacityFakeGateway(); $r = (new Capacity_Service($s, $g))->execute(self::command());
		self::assertSame('APPLIED_VERIFIED', $r['state']); self::assertSame(8, $r['capacity_before']); self::assertSame(5, $r['capacity_after']); self::assertSame(2, $r['consumed_after']); self::assertFalse($g->locked);
	}
	public function test_desired_equal_to_consumed_is_allowed(): void {
		$g = new CapacityFakeGateway(); $g->consumed = 5; $r = (new Capacity_Service(new CapacityMemoryStore(), $g))->execute(self::command()); self::assertSame('APPLIED_VERIFIED', $r['state']);
	}
	public function test_below_consumed_blocks_before_write(): void {
		$g = new CapacityFakeGateway(); $g->consumed = 6; $r = (new Capacity_Service(new CapacityMemoryStore(), $g))->execute(self::command()); self::assertSame('BLOCKED_BELOW_CONSUMED', $r['state']); self::assertSame(0, $g->writes); self::assertFalse($g->locked);
	}
	public function test_aggregated_shared_resource_consumption_blocks_two_and_allows_three(): void {
		// The authoritative gateway supplies the union of Entry IDs across CRES fields.
		$blocked_gateway = new CapacityFakeGateway(); $blocked_gateway->consumed = 3;
		$blocked = (new Capacity_Service(new CapacityMemoryStore(), $blocked_gateway))->execute(self::command(2));
		self::assertSame('BLOCKED_BELOW_CONSUMED', $blocked['state']); self::assertSame(0, $blocked_gateway->writes);
		$allowed_gateway = new CapacityFakeGateway(); $allowed_gateway->consumed = 3;
		$allowed = (new Capacity_Service(new CapacityMemoryStore(), $allowed_gateway))->execute(self::command(3));
		self::assertSame('APPLIED_VERIFIED', $allowed['state']); self::assertSame(1, $allowed_gateway->writes);
	}
	/** @dataProvider divergences */
	public function test_post_write_divergence_never_returns_success(string $kind): void {
		$g = new CapacityFakeGateway(); $g->divergence = $kind; $s = new CapacityMemoryStore(); $service = new Capacity_Service($s, $g);
		$r = $service->execute(self::command()); self::assertSame('RECONCILIATION_REQUIRED', $r['state']); self::assertSame(1, $g->writes);
		$r = $service->execute(self::command()); self::assertSame('RECONCILIATION_REQUIRED', $r['state']); self::assertSame(1, $g->writes); self::assertFalse($g->locked);
	}
	public static function divergences(): array { return array(array('consumed'), array('choice'), array('resource'), array('binding'), array('active')); }
	public function test_external_exception_and_result_persistence_failure_do_not_retry(): void {
		$g = new CapacityFakeGateway(); $g->throw_after_write = true; $s = new CapacityMemoryStore(); $service = new Capacity_Service($s, $g);
		$r = $service->execute(self::command()); self::assertSame('RECONCILIATION_REQUIRED', $r['state']); $service->execute(self::command()); self::assertSame(1, $g->writes);
		$g = new CapacityFakeGateway(); $s = new CapacityMemoryStore(); $s->fail_success = true; $service = new Capacity_Service($s, $g);
		self::assertInstanceOf(\WP_Error::class, $service->execute(self::command())); self::assertSame('APPLYING', array_values($s->rows)[0]['state']);
		self::assertSame('RECONCILIATION_REQUIRED', $service->execute(self::command())['state']); self::assertSame(1, $g->writes);
	}
	public function test_verified_replay_returns_persisted_result_without_inspection_or_write(): void {
		$g = new CapacityFakeGateway(); $service = new Capacity_Service(new CapacityMemoryStore(), $g);
		$a = $service->execute(self::command()); $reads = $g->reads; $b = $service->execute(self::command());
		self::assertTrue($b['idempotent_replay']); self::assertSame($a['capacity_after'], $b['capacity_after']); self::assertSame($reads, $g->reads); self::assertSame(1, $g->writes);
	}
	public function test_same_identity_different_payload_is_conflict(): void {
		$g = new CapacityFakeGateway(); $service = new Capacity_Service(new CapacityMemoryStore(), $g); $service->execute(self::command()); $c = self::command(); $c['reason'] = 'different';
		$r = $service->execute($c); self::assertSame('IDEMPOTENCY_CONFLICT', $r->get_error_code()); self::assertSame(1, $g->writes);
	}
	public function test_stale_version_and_unresolved_prior_command_are_blocked(): void {
		$g = new CapacityFakeGateway(); $s = new CapacityMemoryStore(); $service = new Capacity_Service($s, $g); $service->execute(self::command(5, 3));
		self::assertSame('SOURCE_VERSION_CONFLICT', $service->execute(self::command(4, 2))->get_error_code()); self::assertSame(1, $g->writes);
		$g->throw_after_write = true; $service->execute(self::command(4, 4)); self::assertSame('PRIOR_OPERATION_UNRESOLVED', $service->execute(self::command(3, 5))->get_error_code());
	}
	public function test_reconciliation_inspects_without_repeating_write(): void {
		$g = new CapacityFakeGateway(); $g->throw_after_write = true; $service = new Capacity_Service(new CapacityMemoryStore(), $g);
		$service->execute(self::command()); $r = $service->execute(self::command(), true); self::assertSame('APPLIED_VERIFIED', $r['state']); self::assertSame(1, $g->writes);
		$g->capacity = 7; $r = $service->execute(self::command(), true); self::assertSame('RECONCILIATION_REQUIRED', $r['state']); self::assertSame(1, $g->writes);
	}
	public function test_cooperating_workers_cannot_both_enter_capacity_write(): void {
		$g = new CapacityFakeGateway(); $s = new CapacityMemoryStore(); $service = new Capacity_Service($s, $g); $second = null;
		$g->on_read = static function () use ($service, &$second): void { $second = $service->execute(self::command()); };
		self::assertSame('APPLIED_VERIFIED', $service->execute(self::command())['state']); self::assertSame('CAPACITY_LOCKED', $second->get_error_code()); self::assertSame(1, $g->writes); self::assertFalse($g->locked);
		// This deterministic cooperative-lock test does NOT serialize a GF submission or create an Entry.
	}
	public function test_invalid_identity_is_rejected_before_lock(): void {
		$g = new CapacityFakeGateway(); $c = self::command(); $c['desired_capacity'] = 0; self::assertInstanceOf(\WP_Error::class, (new Capacity_Service(new CapacityMemoryStore(), $g))->execute($c)); self::assertSame(0, $g->locks);
	}
	public function test_inspection_failure_before_any_effect_is_failed(): void {
		$g = new CapacityFakeGateway(); $g->on_read = static function (): void { throw new \RuntimeException(); }; $r = (new Capacity_Service(new CapacityMemoryStore(), $g))->execute(self::command()); self::assertSame('FAILED', $r['state']); self::assertSame(0, $g->writes); self::assertFalse($g->locked);
	}
}

final class CapacityMemoryStore implements Capacity_Store {
	public array $rows = array(); public bool $fail_success = false;
	public function find(string $key): ?array { return $this->rows[$key] ?? null; }
	public function reserve(array $c): bool { $this->rows[$c['operation_key']] = $c + array('command_json' => json_encode($c), 'payload_hash' => Capacity_Command::hash($c), 'state' => 'PENDING', 'attempts' => 0, 'revision' => 1); return true; }
	public function conflict(array $c): ?string { foreach ($this->rows as $key => $r) { if ($key === $c['operation_key'] || $r['class_key'] !== $c['class_key']) continue; if (in_array($r['state'], array('APPLYING', 'RECONCILIATION_REQUIRED'), true)) return 'PRIOR_OPERATION_UNRESOLVED'; if ($r['source_row_version'] >= $c['source_row_version']) return 'SOURCE_VERSION_CONFLICT'; } return null; }
	public function transition(array $r, string $state, array $evidence, ?string $error, bool $attempt = false): ?array {
		if (($this->fail_success && $state === 'APPLIED_VERIFIED') || $this->rows[$r['operation_key']]['revision'] !== $r['revision']) return null;
		return $this->rows[$r['operation_key']] = array_merge($r, $evidence, array('state' => $state, 'error_code' => $error, 'revision' => $r['revision'] + 1, 'attempts' => $r['attempts'] + ($attempt ? 1 : 0)));
	}
}
final class CapacityFakeGateway implements Capacity_Gateway {
	public bool $active = false; public bool $locked = false; public int $capacity = 8; public int $consumed = 2; public int $writes = 0; public int $reads = 0; public int $locks = 0; public string $divergence = ''; public bool $throw_after_write = false; public $on_read = null;
	public function lock(string $key): bool { $this->locks++; if ($this->locked) return false; $this->locked = true; return true; }
	public function unlock(string $key): void { $this->locked = false; }
	public function inspect(array $c): array {
		$this->reads++; if ($this->on_read) { $f = $this->on_read; $this->on_read = null; $f(); }
		$post = $this->writes > 0; return array('form_active' => $this->active || ($post && $this->divergence === 'active'), 'healthy' => ! ($post && in_array($this->divergence, array('choice', 'binding'), true)), 'capacity' => $this->capacity, 'resource_capacity' => $post && $this->divergence === 'resource' ? 99 : $this->capacity, 'consumed' => $post && $this->divergence === 'consumed' ? 6 : $this->consumed, 'resource_id' => 801, 'bindings' => array('800_2', '800_3'));
	}
	public function write(array $c): ?string { $this->writes++; $this->capacity = $c['desired_capacity']; if ($this->throw_after_write) throw new \RuntimeException(); return null; }
}
