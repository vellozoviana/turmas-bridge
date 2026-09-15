<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Materialization\Gravity_Forms_Gateway;
use TurmasBridge\Materialization\Materialization_Service;
use TurmasBridge\Materialization\Materialization_Store;

final class MaterializationServiceTest extends TestCase {
	protected function setUp(): void { $GLOBALS['turmas_bridge_test_options']['turmas_bridge_template_id'] = 199; }

	public function test_materializes_once_and_reuses_same_form_for_the_publication_key(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway(); $service = new Materialization_Service($store, $gateway);
		$first = $service->materialize($this->payload(), 'a');
		$second = $service->materialize($this->payload(), 'b');
		self::assertSame('materialized', $first['status']); self::assertSame(412, $first['form_id']); self::assertFalse($first['idempotent_replay']);
		self::assertTrue($second['idempotent_replay']); self::assertSame(1, $gateway->duplicates); self::assertSame(array('10', '11'), $store->records['2027:MT1']['field_ids']);
	}

	public function test_rejects_unavailable_or_invalid_template_without_creating_a_form(): void {
		$gateway = new Fake_Gravity_Gateway(); $gateway->available = false;
		$result = (new Materialization_Service(new Memory_Materialization_Store(), $gateway))->materialize($this->payload(), 'a');
		self::assertSame('turmas_bridge_gravity_forms_unavailable', $result->get_error_code());
		$gateway = new Fake_Gravity_Gateway(); $gateway->template = null;
		$result = (new Materialization_Service(new Memory_Materialization_Store(), $gateway))->materialize($this->payload(), 'a');
		self::assertSame('turmas_bridge_invalid_template', $result->get_error_code()); self::assertSame(0, $gateway->duplicates);
	}

	public function test_failure_after_creation_preserves_form_id_for_reconciliation_without_duplicate(): void {
		$store = new Memory_Materialization_Store(); $store->fail_materialized = true; $gateway = new Fake_Gravity_Gateway(); $service = new Materialization_Service($store, $gateway);
		$result = $service->materialize($this->payload(), 'a');
		self::assertSame('turmas_bridge_materialization_persist_failed', $result->get_error_code()); self::assertSame(412, $store->records['2027:MT1']['form_id']);
		$store->fail_materialized = false; $reconciled = $service->materialize($this->payload(), 'b');
		self::assertSame(412, $reconciled['form_id']); self::assertTrue($reconciled['idempotent_replay']); self::assertSame(1, $gateway->duplicates);
	}

	/** @return array<string,mixed> */
	private function payload(): array { return array('publication' => array('year' => 2027, 'formation_code' => 'MT1', 'publication_key' => '2027:MT1')); }
}

final class Memory_Materialization_Store implements Materialization_Store {
	/** @var array<string,array<string,mixed>> */ public array $records = array(); public bool $fail_materialized = false;
	public function find(string $publication_key): ?array { return $this->records[$publication_key] ?? null; }
	public function reserve(string $publication_key, int $template_id, string $payload_hash): bool { if (isset($this->records[$publication_key])) return false; $this->records[$publication_key] = array('publication_key' => $publication_key, 'template_id' => $template_id, 'form_id' => null, 'status' => 'RECEIVED'); return true; }
	public function materialized(string $publication_key, int $form_id, array $field_ids): bool { $this->records[$publication_key]['form_id'] = $form_id; $this->records[$publication_key]['field_ids'] = $field_ids; if ($this->fail_materialized) { $this->records[$publication_key]['status'] = 'FAILED'; return false; } $this->records[$publication_key]['status'] = 'MATERIALIZED'; return true; }
	public function failed(string $publication_key, ?int $form_id, string $error_code): bool { $this->records[$publication_key]['form_id'] = $form_id; $this->records[$publication_key]['status'] = 'FAILED'; return true; }
	public function choice_fingerprint(string $publication_key, string $fingerprint): bool { $this->records[$publication_key]['choices_fingerprint'] = $fingerprint; return true; }
	public function acquire_choice_lock(string $publication_key): bool { return true; }
	public function release_choice_lock(string $publication_key): void {}
}

final class Fake_Gravity_Gateway implements Gravity_Forms_Gateway {
	public bool $available = true; /** @var array<string,mixed>|null */ public ?array $template = array('id' => 199, 'is_active' => true, 'fields' => array(array('id' => 10, 'choices' => array(array('text' => 'x'))), array('id' => 11, 'choices' => array(array('text' => 'y'))))); public int $duplicates = 0;
	public function is_available(): bool { return $this->available; }
	public function form(int $form_id): ?array { if ($form_id === 199) return $this->template; return $this->duplicates > 0 ? array('id' => $form_id, 'is_active' => false, 'fields' => $this->template['fields']) : null; }
	public function duplicate_inactive(int $template_id, string $title, string $marker): int|\WP_Error { $this->duplicates++; return 412; }
	public function update_form(array $form): bool|\WP_Error { return true; }
}
