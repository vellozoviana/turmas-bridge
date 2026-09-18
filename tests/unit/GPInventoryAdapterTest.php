<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Inventory\GP_Inventory_Adapter;
use TurmasBridge\Inventory\GP_Inventory_Operations;
use TurmasBridge\Inventory\Inventory_Integration_Exception;
use TurmasBridge\Inventory\Inventory_Mapping_Store;
use TurmasBridge\Inventory\Resource_Identity;
use TurmasBridge\Inventory\Resource_Plan;
use TurmasBridge\Inventory\Resource_Representation;
use TurmasBridge\Inventory\WordPress_GP_Inventory_Operations;

final class GPInventoryAdapterTest extends TestCase {
	public function test_missing_mapping_creates_one_resource_then_replay_is_a_no_op(): void {
		$mapping = new Memory_Inventory_Mapping(); $operations = new Fake_GP_Inventory_Operations();
		$adapter = new GP_Inventory_Adapter($mapping, $operations);
		$first = $adapter->ensure($this->plan()); $second = $adapter->ensure($this->plan());
		self::assertSame(91, $first->resource_id()); self::assertSame(91, $second->resource_id());
		self::assertSame(1, $operations->creates); self::assertSame(1, $operations->synchronizes);
		self::assertSame('HEALTHY', $mapping->record['status']);
	}
	public function test_new_resource_with_initial_field_drift_is_synchronized(): void {
		$mapping = new Memory_Inventory_Mapping(); $operations = new Fake_GP_Inventory_Operations(); $operations->healthy = false; $operations->reason = 'field_resource_drift';
		$result = (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan());
		self::assertSame(91, $result->resource_id()); self::assertSame(1, $operations->synchronizes); self::assertSame('HEALTHY', $mapping->record['status']);
	}
	public function test_orphan_resource_is_adopted_without_creating_a_duplicate(): void {
		$mapping = new Memory_Inventory_Mapping(); $operations = new Fake_GP_Inventory_Operations(); $operations->orphan_resource = 77;
		$result = (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan());
		self::assertSame(77, $result->resource_id()); self::assertSame(0, $operations->creates); self::assertSame('HEALTHY', $mapping->record['status']);
	}
	public function test_resource_creation_failure_discards_empty_reservation(): void {
		$mapping = new Memory_Inventory_Mapping(); $operations = new Fake_GP_Inventory_Operations(); $operations->create_error = new Inventory_Integration_Exception('create_failed', 'create failed');
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::fail('Expected create failure.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('create_failed', $error->error_code()); }
		self::assertNull($mapping->record); self::assertTrue($mapping->discarded);
	}
	public function test_ambiguous_resource_creation_preserves_reservation_for_reconciliation(): void {
		$mapping = new Memory_Inventory_Mapping(); $operations = new Fake_GP_Inventory_Operations(); $operations->create_error = new Inventory_Integration_Exception('create_unknown', 'unknown', true);
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::fail('Expected ambiguous create failure.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('create_unknown', $error->error_code()); }
		self::assertFalse($mapping->discarded); self::assertNotNull($mapping->record); self::assertSame('RECONCILIATION_REQUIRED', $mapping->record['status']);
	}
	public function test_ambiguous_orphan_lookup_fails_closed_without_creation(): void {
		$mapping = new Memory_Inventory_Mapping(); $operations = new Fake_GP_Inventory_Operations(); $operations->orphan_lookup_error = new Inventory_Integration_Exception('ambiguous', 'ambiguous');
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::fail('Expected ambiguous lookup failure.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('ambiguous', $error->error_code()); }
		self::assertSame(0, $operations->creates); self::assertSame('RECONCILIATION_REQUIRED', $mapping->record['status']);
	}
	public function test_lock_blocks_a_second_orchestration_attempt(): void {
		$mapping = new Memory_Inventory_Mapping(); $mapping->locked = true;
		$this->expectException(Inventory_Integration_Exception::class);
		$this->expectExceptionMessage('já está em processamento');
		(new GP_Inventory_Adapter($mapping, new Fake_GP_Inventory_Operations()))->ensure($this->plan());
	}
	public function test_existing_mapping_with_missing_resource_requires_reconciliation_without_creation(): void {
		$mapping = new Memory_Inventory_Mapping(); $mapping->record = $mapping->mapped($this->identity(), 44);
		$operations = new Fake_GP_Inventory_Operations(); $operations->exists = false;
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::fail('Expected controlled reconciliation failure.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('turmas_bridge_inventory_reconciliation_required', $error->error_code()); }
		self::assertSame(0, $operations->creates); self::assertSame('RECONCILIATION_REQUIRED', $mapping->record['status']);
	}
	public function test_capacity_below_fresh_consumption_is_blocked_before_mutation(): void {
		$mapping = new Memory_Inventory_Mapping(); $mapping->record = $mapping->mapped($this->identity(), 44);
		$operations = new Fake_GP_Inventory_Operations(); $operations->capacity = 8; $operations->consumed = 6;
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan(5)); self::fail('Expected capacity guard.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('turmas_bridge_capacity_below_consumed', $error->error_code()); }
		self::assertSame(0, $operations->synchronizes);
		self::assertSame(0, $operations->field_writes); self::assertSame(0, $operations->binding_writes); self::assertSame(0, $operations->capacity_writes);
	}
	public function test_fresh_consumed_failure_requires_reconciliation_without_mutation(): void {
		$mapping = new Memory_Inventory_Mapping(); $mapping->record = $mapping->mapped($this->identity(), 44); $operations = new Fake_GP_Inventory_Operations(); $operations->inspect_error = new Inventory_Integration_Exception('fresh_read_failed', 'fresh read failed');
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::fail('Expected fresh-read failure.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('fresh_read_failed', $error->error_code()); }
		self::assertSame('RECONCILIATION_REQUIRED', $mapping->record['status']); self::assertSame(0, $operations->synchronizes); self::assertSame(0, $operations->field_writes); self::assertSame(0, $operations->binding_writes); self::assertSame(0, $operations->capacity_writes);
	}
	public function test_duplicate_form_contract_reconciles_copied_resource_without_new_resource_or_binding_duplicate(): void {
		$mapping = new Memory_Inventory_Mapping(); $mapping->record = $mapping->mapped($this->identity(), 44); $operations = new Duplicate_Form_Contract_Fake(); $before = $operations->inspect($this->plan(), 44);
		self::assertSame(44, $operations->copied_resource_id); self::assertFalse($before['healthy']); self::assertSame('binding_missing', $before['reason']); self::assertFalse($operations->binding_present);
		$result = (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::assertSame(44, $result->resource_id()); self::assertSame(0, $operations->creates); self::assertTrue($operations->binding_present); self::assertSame(1, $operations->synchronizes);
		$replay = (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::assertSame(44, $replay->resource_id()); self::assertSame(1, $operations->synchronizes); self::assertSame('HEALTHY', $mapping->record['status']);
	}
	public function test_duplicate_form_copy_with_resource_but_without_binding_is_reconciled(): void {
		$mapping = new Memory_Inventory_Mapping(); $mapping->record = $mapping->mapped($this->identity(), 44);
		$operations = new Fake_GP_Inventory_Operations(); $operations->healthy = false; $operations->reason = 'binding_missing'; $operations->capacity = 5;
		$result = (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan());
		self::assertSame(1, $operations->synchronizes); self::assertSame(44, $result->resource_id()); self::assertSame('HEALTHY', $mapping->record['status']);
	}
	public function test_semantic_field_drift_fails_closed_without_reinterpreting_entries(): void {
		$mapping = new Memory_Inventory_Mapping(); $mapping->record = $mapping->mapped($this->identity(), 44);
		$operations = new Fake_GP_Inventory_Operations(); $operations->healthy = false; $operations->reason = 'field_resource_drift';
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::fail('Expected semantic drift reconciliation.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('turmas_bridge_inventory_reconciliation_required', $error->error_code()); }
		self::assertSame(0, $operations->synchronizes); self::assertSame('RECONCILIATION_REQUIRED', $mapping->record['status']);
	}
	public function test_partial_representation_update_requires_reconciliation(): void {
		$mapping = new Memory_Inventory_Mapping(); $mapping->record = $mapping->mapped($this->identity(), 44);
		$operations = new Fake_GP_Inventory_Operations(); $operations->healthy = false; $operations->reason = 'capacity_drift'; $operations->sync_healthy = false; $operations->sync_reason = 'form_update_failed';
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::fail('Expected reconciliation failure.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('turmas_bridge_inventory_reconciliation_required', $error->error_code()); }
		self::assertSame('RECONCILIATION_REQUIRED', $mapping->record['status']);
	}
	public function test_consumption_increase_during_synchronization_requires_reconciliation(): void {
		$mapping = new Memory_Inventory_Mapping(); $mapping->record = $mapping->mapped($this->identity(), 44);
		$operations = new Fake_GP_Inventory_Operations(); $operations->healthy = false; $operations->reason = 'binding_missing'; $operations->consumed_after_synchronize = 6;
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan(5)); self::fail('Expected capacity guard after synchronization.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('turmas_bridge_capacity_below_consumed', $error->error_code()); }
		self::assertSame('RECONCILIATION_REQUIRED', $mapping->record['status']);
		self::assertSame('turmas_bridge_capacity_below_consumed_after_sync', $mapping->record['last_error_code']);
	}
	public function test_unavailable_runtime_fails_closed_before_mapping_write(): void {
		$mapping = new Memory_Inventory_Mapping(); $operations = new Fake_GP_Inventory_Operations(); $operations->available = false;
		try { (new GP_Inventory_Adapter($mapping, $operations))->ensure($this->plan()); self::fail('Expected dependency failure.'); } catch (Inventory_Integration_Exception $error) { self::assertSame('turmas_bridge_gp_inventory_unavailable', $error->error_code()); }
		self::assertNull($mapping->record);
	}
	public function test_wordpress_operations_fail_closed_when_gp_symbols_are_absent(): void {
		self::assertFalse((new WordPress_GP_Inventory_Operations())->is_available());
	}
	private function identity(): Resource_Identity { return Resource_Identity::from_class_key('2099:ADAPTER:01.01'); }
	private function plan(int $capacity = 5): Resource_Plan {
		$identity = $this->identity();
		return (new Resource_Plan($identity, $capacity, array(new Resource_Representation('01', '10', $identity->class_key(), $identity, 'ADAPTER 01.01 SEG MANHÃ'), new Resource_Representation('02', '11', $identity->class_key(), $identity, 'ADAPTER 01.01 SEG MANHÃ'), new Resource_Representation('03', '12', $identity->class_key(), $identity, 'ADAPTER 01.01 SEG MANHÃ'))))->for_form(412);
	}
}

final class Memory_Inventory_Mapping implements Inventory_Mapping_Store {
	/** @var array<string,mixed>|null */ public ?array $record = null;
	public bool $locked = false;
	public bool $discarded = false;
	public function find(Resource_Identity $identity): ?array { return $this->record; }
	public function reserve(Resource_Identity $identity, int $form_id): bool { if ($this->record) return false; $this->record = array('publication_key' => $identity->publication_key(), 'class_key' => $identity->class_key(), 'resource_id' => null, 'form_id' => $form_id, 'status' => 'PROVISIONING'); return true; }
	public function discard_provisioning(Resource_Identity $identity): bool { $this->discarded = true; if ($this->record !== null && $this->record['resource_id'] === null) $this->record = null; return true; }
	public function resource_created(Resource_Identity $identity, int $resource_id, int $form_id): bool { if (! $this->record) return false; $this->record['resource_id'] = $resource_id; $this->record['form_id'] = $form_id; return true; }
	public function healthy(Resource_Identity $identity, int $form_id): bool { if (! $this->record) return false; $this->record['form_id'] = $form_id; $this->record['status'] = 'HEALTHY'; return true; }
	public function reconciliation_required(Resource_Identity $identity, string $error_code): bool { if (! $this->record) return false; $this->record['status'] = 'RECONCILIATION_REQUIRED'; $this->record['last_error_code'] = $error_code; return true; }
	public function acquire_lock(Resource_Identity $identity): bool { if ($this->locked) return false; $this->locked = true; return true; }
	public function release_lock(Resource_Identity $identity): void { $this->locked = false; }
	/** @return array<string,mixed> */ public function mapped(Resource_Identity $identity, int $resource_id): array { return array('publication_key' => $identity->publication_key(), 'class_key' => $identity->class_key(), 'resource_id' => $resource_id, 'form_id' => 412, 'status' => 'HEALTHY'); }
}

class Fake_GP_Inventory_Operations implements GP_Inventory_Operations {
	public bool $available = true; public bool $exists = true; public int $creates = 0; public int $synchronizes = 0; public int $field_writes = 0; public int $binding_writes = 0; public int $capacity_writes = 0; public int $capacity = 5; public int $consumed = 0; public ?int $consumed_after_synchronize = null; public bool $healthy = true; public ?string $reason = null; public bool $sync_healthy = true; public ?string $sync_reason = null; public ?int $orphan_resource = null; public ?Inventory_Integration_Exception $create_error = null; public ?Inventory_Integration_Exception $orphan_lookup_error = null; public ?Inventory_Integration_Exception $inspect_error = null;
	public function is_available(): bool { return $this->available; }
	public function find_resource(Resource_Identity $identity): ?int { if ($this->orphan_lookup_error !== null) throw $this->orphan_lookup_error; return $this->orphan_resource; }
	public function resource_exists(int $resource_id): bool { return $this->exists; }
	public function create_resource(Resource_Identity $identity): int { if ($this->create_error !== null) throw $this->create_error; $this->creates++; return 91; }
	public function inspect(Resource_Plan $plan, int $resource_id): array { if ($this->inspect_error !== null) throw $this->inspect_error; return array('capacity' => $this->capacity, 'consumed' => $this->consumed, 'healthy' => $this->healthy, 'reason' => $this->reason); }
	public function synchronize(Resource_Plan $plan, int $resource_id): array { $this->synchronizes++; $this->field_writes++; $this->binding_writes++; $this->capacity_writes++; $this->capacity = $plan->capacity(); if ($this->consumed_after_synchronize !== null) $this->consumed = $this->consumed_after_synchronize; return array('capacity' => $this->capacity, 'consumed' => $this->consumed, 'healthy' => $this->sync_healthy, 'reason' => $this->sync_reason); }
}

final class Duplicate_Form_Contract_Fake extends Fake_GP_Inventory_Operations {
	public int $copied_resource_id = 44; public bool $binding_present = false;
	public function inspect(Resource_Plan $plan, int $resource_id): array { return array('capacity' => 5, 'consumed' => 0, 'healthy' => $this->binding_present, 'reason' => $this->binding_present ? null : 'binding_missing'); }
	public function synchronize(Resource_Plan $plan, int $resource_id): array { $this->synchronizes++; $this->binding_present = true; return array('capacity' => $plan->capacity(), 'consumed' => 0, 'healthy' => true, 'reason' => null); }
}
