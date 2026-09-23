<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Materialization\Gravity_Forms_Gateway;
use TurmasBridge\Materialization\Materialization_Store;
use TurmasBridge\Publications\Publication_Status_Reader;
use TurmasBridge\Inventory\Inventory_Status_Gateway;

final class PublicationStatusReaderTest extends TestCase {
	public function test_reports_materialized_only_when_form_exists_and_is_inactive(): void {
		$store = new Status_Store(array('status' => 'MATERIALIZED', 'form_id' => 412, 'updated_at' => '2026-01-01 00:00:00'));
		$gravity = new Status_Gravity(array('id' => 412, 'is_active' => false));
		$result = (new Publication_Status_Reader($store, $gravity, new Healthy_Inventory_Status()))->read('2099:E2E');

		self::assertIsArray($result);
		self::assertSame('MATERIALIZED', $result['effective_state']);
		self::assertSame('inactive', $result['form_state']);
		self::assertSame(412, $result['form_id']);
	}

	public function test_active_or_missing_form_never_reports_ready_state(): void {
		$store = new Status_Store(array('status' => 'MATERIALIZED', 'form_id' => 412));
		$active = (new Publication_Status_Reader($store, new Status_Gravity(array('id' => 412, 'is_active' => true)), new Healthy_Inventory_Status()))->read('2099:E2E');
		$missing = (new Publication_Status_Reader($store, new Status_Gravity(null), new Healthy_Inventory_Status()))->read('2099:E2E');

		self::assertSame('POST_ACTIVATION_VERIFIED', $active['effective_state']);
		self::assertSame('READY', $active['inventory']['status']);
		self::assertSame('RECONCILIATION_REQUIRED', $missing['effective_state']);
	}

	public function test_post_activation_reader_verifies_expected_active_form_without_mutation(): void {
		$store = new Status_Store(array('status' => 'MATERIALIZED', 'form_id' => 412));
		$result = (new Publication_Status_Reader($store, new Status_Gravity(array('id' => 412, 'is_active' => true)), new Healthy_Inventory_Status()))->read_post_activation('2099:E2E');
		self::assertSame('POST_ACTIVATION_VERIFIED', $result['effective_state']);
		self::assertSame('active', $result['form_state']);
		self::assertSame(0, $store->mutations);
	}

	public function test_unknown_publication_is_not_synthesized(): void {
		$result = (new Publication_Status_Reader(new Status_Store(null), new Status_Gravity(null), new Healthy_Inventory_Status()))->read('2099:E2E');

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_publication_not_found', $result->get_error_code());
	}

	public function test_status_read_is_strictly_read_only(): void {
		$store = new Status_Store(array('status' => 'MATERIALIZED', 'form_id' => 412));
		$result = (new Publication_Status_Reader($store, new Status_Gravity(array('id' => 412, 'is_active' => false)), new Healthy_Inventory_Status()))->read('2099:E2E');

		self::assertIsArray($result);
		self::assertSame(0, $store->mutations);
	}
}

final class Healthy_Inventory_Status implements Inventory_Status_Gateway {
	public function read(string $publication_key, int $form_id, array $form): array {
		return array('status' => 'READY', 'resources' => array(), 'blockers' => array());
	}
	public function read_post_activation(string $publication_key, int $form_id, array $form): array { return $this->read($publication_key, $form_id, $form); }
}

final class Status_Store implements Materialization_Store {
	public int $mutations = 0;
	public function __construct(private ?array $record) {}
	public function find(string $publication_key): ?array { return $this->record; }
	public function reserve(string $publication_key, int $template_id, string $payload_hash): bool { $this->mutations++; return false; }
	public function materialized(string $publication_key, int $form_id, array $field_ids): bool { $this->mutations++; return false; }
	public function failed(string $publication_key, ?int $form_id, string $error_code): bool { $this->mutations++; return false; }
	public function choice_fingerprint(string $publication_key, string $fingerprint): bool { $this->mutations++; return false; }
	public function acquire_choice_lock(string $publication_key): bool { $this->mutations++; return false; }
	public function release_choice_lock(string $publication_key): void { $this->mutations++; }
}

final class Status_Gravity implements Gravity_Forms_Gateway {
	public function __construct(private ?array $form) {}
	public function is_available(): bool { return true; }
	public function form(int $form_id): ?array { return $this->form; }
	public function duplicate_inactive(int $template_id, string $title, string $marker): int|\WP_Error { return 0; }
	public function update_form(array $form): bool|\WP_Error { return false; }
}
