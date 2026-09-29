<?php
declare(strict_types=1);
namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Capacity\WordPress_Capacity_Gateway;
use TurmasBridge\Inventory\Inventory_Mapping_Store;
use TurmasBridge\Inventory\GP_Inventory_Operations;
use TurmasBridge\Materialization\Materialization_Store;

final class CapacityGatewayTest extends TestCase {
	private Inventory_Mapping_Store $m;
	private Materialization_Store $p;
	private GP_Inventory_Operations $ops;
	private WordPress_Capacity_Gateway $gateway;
	protected function setUp(): void {
		require_once __DIR__ . '/../stubs/gravityforms.php';
		\GFAPI::reset();
		\GFAPI::$update_result = true;
		\GFAPI::$form = array('id' => 800, 'is_active' => false, 'fields' => array());
		foreach (array(1, 2, 3) as $id) \GFAPI::$form['fields'][] = (object) array('id' => $id, 'type' => 'select', 'adminLabel' => sprintf('turma_cre_%02d', $id), 'gpiResource' => '801', 'choices' => array(array('value' => '2095:UNIT:01.01', 'inventory_limit' => 8)));
		$GLOBALS['capacity_test_meta'] = array(801 => array('gpi_inventory_limit' => '8', 'gpi_field' => array('800_1', '800_2', '800_3')));
		$GLOBALS['capacity_test_meta'][801] += array('turmas_bridge_managed' => '1', 'turmas_bridge_class_key' => '2095:UNIT:01.01');
		$GLOBALS['capacity_test_writes'] = array();
		$this->m = $this->createMock(Inventory_Mapping_Store::class);
		$this->m->method('find')->willReturn(array('publication_key' => '2095:UNIT', 'status' => 'HEALTHY', 'form_id' => 800, 'resource_id' => 801));
		foreach (array('reserve', 'resource_created', 'healthy', 'reconciliation_required', 'discard_provisioning') as $method) $this->m->expects(self::never())->method($method);
		$this->p = $this->createMock(Materialization_Store::class);
		$this->p->method('find')->willReturn(array('status' => 'MATERIALIZED', 'form_id' => 800));
		$this->ops = $this->createMock(GP_Inventory_Operations::class);
		$this->ops->method('is_available')->willReturn(true);
		$this->ops->method('resource_exists')->willReturn(true);
		$this->ops->method('inspect')->willReturnCallback(static function (): array {
			$limits = array_map(static fn ($f) => $f->choices[0]['inventory_limit'], \GFAPI::$form['fields']);
			return array('healthy' => count(array_unique($limits)) === 1, 'capacity' => $limits[0], 'consumed' => $GLOBALS['capacity_test_consumed'] ?? 2, 'reason' => null);
		});
		$this->ops->expects(self::never())->method('synchronize');
		$this->ops->expects(self::never())->method('create_resource');
		$this->gateway = new WordPress_Capacity_Gateway($this->m, $this->p, $this->ops);
	}
	protected function tearDown(): void { \GFAPI::reset(); unset($GLOBALS['capacity_test_meta'], $GLOBALS['capacity_test_writes'], $GLOBALS['capacity_test_meta_fail'], $GLOBALS['capacity_test_counter'], $GLOBALS['capacity_test_form_write_hook'], $GLOBALS['capacity_test_meta_write_hook'], $GLOBALS['capacity_test_consumed']); }
	public function test_active_blocks_actual_gateway_without_form_meta_or_mapping_writes(): void {
		\GFAPI::$form['is_active'] = true; $before = $GLOBALS['capacity_test_meta'];
		self::assertSame('FORM_ACTIVE', $this->gateway->write(CapacityServiceTest::command()));
		self::assertSame(array(), \GFAPI::$updated_forms); self::assertSame($before, $GLOBALS['capacity_test_meta']); self::assertSame(array(), $GLOBALS['capacity_test_writes']);
	}
	public function test_all_three_representations_and_resource_are_written_and_read_back(): void {
		self::assertTrue($this->gateway->inspect(CapacityServiceTest::command())['healthy']);
		self::assertNull($this->gateway->write(CapacityServiceTest::command()));
		foreach (\GFAPI::$form['fields'] as $field) self::assertSame(5, $field->choices[0]['inventory_limit']);
		$r = $this->gateway->inspect(CapacityServiceTest::command());
		self::assertTrue($r['healthy']); self::assertSame(5, $r['resource_capacity']); self::assertCount(1, \GFAPI::$updated_forms); self::assertCount(1, $GLOBALS['capacity_test_writes']);
	}
	public function test_failed_meta_readback_throws_after_form_effect(): void {
		$GLOBALS['capacity_test_meta_fail'] = true;
		try { $this->gateway->write(CapacityServiceTest::command()); self::fail('Expected uncertain write.'); }
		catch (\RuntimeException $e) { self::assertSame('Resource write unconfirmed.', $e->getMessage()); }
		self::assertCount(1, \GFAPI::$updated_forms); self::assertSame('8', $GLOBALS['capacity_test_meta'][801]['gpi_inventory_limit']);
	}
	public function test_extra_binding_and_meta_divergence_fail_closed(): void {
		$GLOBALS['capacity_test_meta'][801]['gpi_field'][] = '999_9'; self::assertFalse($this->gateway->inspect(CapacityServiceTest::command())['healthy']);
		array_pop($GLOBALS['capacity_test_meta'][801]['gpi_field']); $GLOBALS['capacity_test_meta'][801]['gpi_inventory_limit'] = '7'; self::assertFalse($this->gateway->inspect(CapacityServiceTest::command())['healthy']);
	}
	public function test_two_cooperative_lock_levels_release_in_reverse_order(): void {
		$events = array();
		$this->p->method('acquire_choice_lock')->willReturnCallback(static function () use (&$events): bool { $events[] = 'publication+'; return true; });
		$this->m->method('acquire_lock')->willReturnCallback(static function () use (&$events): bool { $events[] = 'class+'; return true; });
		$this->m->method('release_lock')->willReturnCallback(static function () use (&$events): void { $events[] = 'class-'; });
		$this->p->method('release_choice_lock')->willReturnCallback(static function () use (&$events): void { $events[] = 'publication-'; });
		self::assertTrue($this->gateway->lock('2095:UNIT:01.01')); $this->gateway->unlock('2095:UNIT:01.01');
		self::assertSame(array('publication+', 'class+', 'class-', 'publication-'), $events);
	}
	public function test_class_lock_failure_releases_publication_lock(): void {
		$this->p->method('acquire_choice_lock')->willReturn(true); $this->m->method('acquire_lock')->willReturn(false);
		$this->p->expects(self::once())->method('release_choice_lock')->with('2095:UNIT');
		self::assertFalse($this->gateway->lock('2095:UNIT:01.01'));
	}
	public function test_release_failure_still_attempts_publication_release(): void {
		$this->m->method('release_lock')->willThrowException(new \RuntimeException('synthetic release failure'));
		$this->p->expects(self::once())->method('release_choice_lock');
		$this->expectException(\RuntimeException::class); $this->gateway->unlock('2095:UNIT:01.01');
	}
	public function test_wrong_resource_ownership_blocks_without_writes(): void {
		$GLOBALS['capacity_test_meta'][801]['turmas_bridge_class_key'] = '2095:UNIT:02.01';
		try { $this->gateway->write(CapacityServiceTest::command()); self::fail('Expected ownership check.'); } catch (\RuntimeException) {}
		self::assertSame(array(), \GFAPI::$updated_forms); self::assertSame(array(), $GLOBALS['capacity_test_writes']);
	}
	public function test_vendor_count_is_flushed_on_each_inspection_and_invalid_count_fails_closed(): void {
		foreach (\GFAPI::$form['fields'] as $f) $f->gpiInventory = 'advanced';
		$counter = new class {
			public int $flushes = 0; public mixed $count = 2;
			public function flush_choice_count_cache(array $form): void { $this->flushes++; }
			public function get_choice_count(mixed ...$args): mixed { return $this->count; }
		};
		$GLOBALS['capacity_test_counter'] = $counter;
		$gateway = new WordPress_Capacity_Gateway($this->m, $this->p, new \TurmasBridge\Inventory\WordPress_GP_Inventory_Operations());
		self::assertSame(2, $gateway->inspect(CapacityServiceTest::command())['consumed']);
		$counter->count = 3; self::assertSame(3, $gateway->inspect(CapacityServiceTest::command())['consumed']); self::assertSame(2, $counter->flushes);
		$counter->count = null;
		$this->expectException(\TurmasBridge\Inventory\Inventory_Integration_Exception::class); $gateway->inspect(CapacityServiceTest::command());
	}
	public function test_missing_binding_or_divergent_choice_is_not_verified(): void {
		array_pop($GLOBALS['capacity_test_meta'][801]['gpi_field']); self::assertFalse($this->gateway->inspect(CapacityServiceTest::command())['healthy']);
		$GLOBALS['capacity_test_meta'][801]['gpi_field'][] = '800_3'; \GFAPI::$form['fields'][2]->choices[0]['inventory_limit'] = 9;
		self::assertFalse($this->gateway->inspect(CapacityServiceTest::command())['healthy']);
	}
	/** @dataProvider partial_failures */
	public function test_partial_effects_through_actual_adapter_require_reconciliation(string $fault): void {
		$this->p->method('acquire_choice_lock')->willReturn(true); $this->m->method('acquire_lock')->willReturn(true);
		$GLOBALS['capacity_test_form_write_hook'] = static function () use ($fault): void {
			if ($fault === 'choice') \GFAPI::$form['fields'][2]->choices[0]['inventory_limit'] = 9;
			if ($fault === 'binding') array_pop($GLOBALS['capacity_test_meta'][801]['gpi_field']);
			if ($fault === 'active') \GFAPI::$form['is_active'] = true;
			if ($fault === 'consumed') $GLOBALS['capacity_test_consumed'] = 6;
			if ($fault === 'throw') throw new \RuntimeException('synthetic post-write exception');
		};
		if ($fault === 'meta-failure') $GLOBALS['capacity_test_meta_fail'] = true;
		if ($fault === 'meta-divergence') $GLOBALS['capacity_test_meta_write_hook'] = static function (): void { $GLOBALS['capacity_test_meta'][801]['gpi_inventory_limit'] = '9'; };
		$service = new \TurmasBridge\Capacity\Capacity_Service(new CapacityMemoryStore(), $this->gateway);
		self::assertSame('RECONCILIATION_REQUIRED', $service->execute(CapacityServiceTest::command())['state']);
		self::assertSame('RECONCILIATION_REQUIRED', $service->execute(CapacityServiceTest::command())['state']); self::assertCount(1, \GFAPI::$updated_forms);
	}
	public static function partial_failures(): array { return array(array('choice'), array('binding'), array('active'), array('consumed'), array('throw'), array('meta-failure'), array('meta-divergence')); }
	public function test_publication_lock_serializes_two_distinct_classes_of_same_form(): void {
		$held = false;
		$this->p->method('acquire_choice_lock')->willReturnCallback(static function () use (&$held): bool { if ($held) return false; return $held = true; });
		$this->p->method('release_choice_lock')->willReturnCallback(static function () use (&$held): void { $held = false; });
		$this->m->method('acquire_lock')->willReturn(true);
		$second = new WordPress_Capacity_Gateway($this->m, $this->p, $this->ops);
		self::assertTrue($this->gateway->lock('2095:UNIT:01.01')); self::assertFalse($second->lock('2095:UNIT:01.02'));
		$this->gateway->unlock('2095:UNIT:01.01'); self::assertTrue($second->lock('2095:UNIT:01.02')); $second->unlock('2095:UNIT:01.02');
	}
	public function test_unlock_exception_is_not_reported_as_http_success(): void {
		$this->p->method('acquire_choice_lock')->willReturn(true); $this->m->method('acquire_lock')->willReturn(true);
		$this->m->method('release_lock')->willThrowException(new \RuntimeException()); $this->p->expects(self::once())->method('release_choice_lock');
		$s = new CapacityMemoryStore(); $r = (new \TurmasBridge\Capacity\Capacity_Service($s, $this->gateway))->execute(CapacityServiceTest::command());
		self::assertSame('CAPACITY_UNLOCK_UNCONFIRMED', $r->get_error_code()); self::assertSame('APPLIED_VERIFIED', array_values($s->rows)[0]['state']);
	}
}
