<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Inventory\GP_Inventory_Operations;
use TurmasBridge\Inventory\Inventory_Integration_Exception;
use TurmasBridge\Inventory\Inventory_Mapping_Store;
use TurmasBridge\Inventory\Inventory_Status_Gateway;
use TurmasBridge\Inventory\Resource_Identity;
use TurmasBridge\Inventory\Resource_Plan;
use TurmasBridge\Inventory\WordPress_Inventory_Status_Gateway;

final class InventoryStatusGatewayTest extends TestCase {
	public function test_reads_healthy_resource_without_writing(): void {
		$mapping = new Status_Mapping_Store(array(array('publication_key' => '2099:E2E', 'class_key' => '2099:E2E:01.01', 'resource_id' => 11, 'form_id' => 10, 'status' => 'HEALTHY')));
		$operations = new Status_Operations();
		$form = array('fields' => array(
			(object) array('id' => '1', 'type' => 'select', 'adminLabel' => 'turma_cre_01', 'choices' => array(array('value' => '2099:E2E:01.01', 'text' => 'E2E 01.01', 'inventory_limit' => 5))),
			(object) array('id' => '2', 'type' => 'select', 'adminLabel' => 'turma_cre_11', 'choices' => array(array('value' => '2099:E2E:01.01', 'text' => 'E2E 01.01', 'inventory_limit' => 5))),
		));
		$result = (new WordPress_Inventory_Status_Gateway($mapping, $operations))->read('2099:E2E', 10, $form);

		self::assertSame('READY', $result['status']);
		self::assertSame(11, $result['resources'][0]['resource_id']);
		self::assertSame(5, $result['resources'][0]['capacity']);
		self::assertSame(0, $result['resources'][0]['consumed']);
		self::assertSame(1, $operations->inspections);
		self::assertSame(0, $mapping->mutations);
	}

	public function test_detects_ambiguous_mapping_without_mutating_it(): void {
		$mapping = new Status_Mapping_Store(array(
			array('publication_key' => '2099:E2E', 'class_key' => '2099:E2E:01.01', 'resource_id' => 11, 'form_id' => 10, 'status' => 'HEALTHY'),
			array('publication_key' => '2099:E2E', 'class_key' => '2099:E2E:01.01', 'resource_id' => 12, 'form_id' => 10, 'status' => 'HEALTHY'),
		));
		$result = (new WordPress_Inventory_Status_Gateway($mapping, new Status_Operations()))->read('2099:E2E', 10, array('fields' => array(array('id' => '1', 'type' => 'select', 'adminLabel' => 'turma_cre_01', 'choices' => array()))));

		self::assertSame('BLOCKED', $result['status']);
		self::assertSame('RESOURCE_AMBIGUOUS', $result['blockers'][0]['code']);
		self::assertSame(0, $mapping->mutations);
	}
}

final class Status_Mapping_Store implements Inventory_Mapping_Store {
	public int $mutations = 0;
	public function __construct(private array $rows) {}
	public function find(Resource_Identity $identity): ?array { return $this->rows[0] ?? null; }
	public function list_for_publication(string $publication_key): array { return array_values(array_filter($this->rows, static fn (array $row): bool => $row['publication_key'] === $publication_key)); }
	public function reserve(Resource_Identity $identity, int $form_id): bool { $this->mutations++; return false; }
	public function discard_provisioning(Resource_Identity $identity): bool { $this->mutations++; return false; }
	public function resource_created(Resource_Identity $identity, int $resource_id, int $form_id): bool { $this->mutations++; return false; }
	public function healthy(Resource_Identity $identity, int $form_id): bool { $this->mutations++; return false; }
	public function reconciliation_required(Resource_Identity $identity, string $error_code): bool { $this->mutations++; return false; }
	public function acquire_lock(Resource_Identity $identity): bool { $this->mutations++; return false; }
	public function release_lock(Resource_Identity $identity): void { $this->mutations++; }
}

final class Status_Operations implements GP_Inventory_Operations {
	public int $inspections = 0;
	public function is_available(): bool { return true; }
	public function find_resource(Resource_Identity $identity): ?int { return 11; }
	public function resource_exists(int $resource_id): bool { return true; }
	public function create_resource(Resource_Identity $identity): int { throw new Inventory_Integration_Exception('not_used', ''); }
	public function inspect(Resource_Plan $plan, int $resource_id): array { $this->inspections++; return array('capacity' => 5, 'consumed' => 0, 'healthy' => true, 'reason' => null); }
	public function synchronize(Resource_Plan $plan, int $resource_id): array { throw new Inventory_Integration_Exception('not_used', ''); }
}
