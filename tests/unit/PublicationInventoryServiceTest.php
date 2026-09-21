<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Inventory\Inventory_Gateway;
use TurmasBridge\Inventory\Inventory_Resource;
use TurmasBridge\Inventory\Resource_Plan;
use TurmasBridge\Inventory\Publication_Inventory_Service;
use TurmasBridge\Materialization\Materialization_Store;

final class PublicationInventoryServiceTest extends TestCase {
	public function test_publication_lock_failure_blocks_inventory_preparation(): void {
		$store = new InventoryLockMaterializationStore(); $store->available = false;
		$result = (new Publication_Inventory_Service($store, null, new NoopInventoryGateway()))->prepare(array('publication' => array('publication_key' => '2099:E2F')));
		self::assertInstanceOf(\WP_Error::class, $result); self::assertSame('turmas_bridge_publication_lock_unavailable', $result->get_error_code()); self::assertSame(1, $store->acquire_calls); self::assertSame(0, $store->release_calls);
	}

	public function test_publication_lock_is_released_when_preparation_exits_early(): void {
		$store = new InventoryLockMaterializationStore();
		$result = (new Publication_Inventory_Service($store, null, new NoopInventoryGateway()))->prepare(array('publication' => array('publication_key' => '2099:E2F')));
		self::assertInstanceOf(\WP_Error::class, $result); self::assertSame('turmas_bridge_materialization_required', $result->get_error_code()); self::assertSame(1, $store->acquire_calls); self::assertSame(1, $store->release_calls);
	}
}

final class NoopInventoryGateway implements Inventory_Gateway {
	public function find(\TurmasBridge\Inventory\Resource_Identity $identity): ?Inventory_Resource { return null; }
	public function ensure(Resource_Plan $plan): Inventory_Resource { throw new \LogicException('not reached'); }
}

final class InventoryLockMaterializationStore implements Materialization_Store {
	public bool $available = true; public int $acquire_calls = 0; public int $release_calls = 0;
	public function find(string $publication_key): ?array { return null; }
	public function reserve(string $publication_key, int $template_id, string $payload_hash): bool { return false; }
	public function materialized(string $publication_key, int $form_id, array $field_ids): bool { return false; }
	public function failed(string $publication_key, ?int $form_id, string $error_code): bool { return false; }
	public function choice_fingerprint(string $publication_key, string $fingerprint): bool { return false; }
	public function acquire_choice_lock(string $publication_key): bool { $this->acquire_calls++; return $this->available; }
	public function release_choice_lock(string $publication_key): void { $this->release_calls++; }
}
