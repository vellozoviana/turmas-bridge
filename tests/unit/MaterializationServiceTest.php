<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Materialization\Gravity_Forms_Gateway;
use TurmasBridge\Materialization\Materialization_Service;
use TurmasBridge\Materialization\Materialization_Store;
use TurmasBridge\Materialization\Template_Preflight;

final class MaterializationServiceTest extends TestCase {
	protected function setUp(): void { $GLOBALS['turmas_bridge_test_options']['turmas_bridge_template_id'] = 199; }

	public function test_materializes_once_and_reuses_same_form_for_the_publication_key(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway(); $service = $this->service($store, $gateway);
		$first = $service->materialize($this->payload(), 'a');
		$store->choice_fingerprint('2027:MT1', 'prepared');
		$second = $service->materialize($this->payload(), 'a');
		self::assertSame('materialized', $first['status']); self::assertSame(412, $first['form_id']); self::assertFalse($first['idempotent_replay']);
		self::assertTrue($second['idempotent_replay']); self::assertSame(1, $gateway->duplicates); self::assertSame(array('10', '11'), $store->records['2027:MT1']['field_ids']);
	}

	public function test_rejects_unavailable_or_invalid_template_without_creating_a_form(): void {
		$gateway = new Fake_Gravity_Gateway(); $gateway->available = false;
		$result = $this->service(new Memory_Materialization_Store(), $gateway)->materialize($this->payload(), 'a');
		self::assertSame('turmas_bridge_gravity_forms_unavailable', $result->get_error_code());
		$gateway = new Fake_Gravity_Gateway(); $gateway->template = null;
		$result = $this->service(new Memory_Materialization_Store(), $gateway)->materialize($this->payload(), 'a');
		self::assertSame('turmas_bridge_invalid_template', $result->get_error_code()); self::assertSame(0, $gateway->duplicates);
	}

	public function test_failure_after_creation_preserves_form_id_for_reconciliation_without_duplicate(): void {
		$store = new Memory_Materialization_Store(); $store->fail_materialized = true; $gateway = new Fake_Gravity_Gateway(); $service = $this->service($store, $gateway);
		$result = $service->materialize($this->payload(), 'a');
		self::assertSame('turmas_bridge_materialization_persist_failed', $result->get_error_code()); self::assertSame(412, $store->records['2027:MT1']['form_id']);
		$store->fail_materialized = false; $blocked = $service->materialize($this->payload(), 'a');
		self::assertSame('turmas_bridge_reconciliation_required', $blocked->get_error_code()); self::assertSame(1, $gateway->duplicates);
	}

	public function test_existing_materialization_with_missing_form_requires_reconciliation(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway(); $service = $this->service($store, $gateway);
		$service->materialize($this->payload(), 'a');
		$store->choice_fingerprint('2027:MT1', 'prepared');
		$gateway->materialized_form_missing = true;

		$result = $service->materialize($this->payload(), 'a');

		self::assertSame('turmas_bridge_reconciliation_required', $result->get_error_code());
		self::assertSame(1, $gateway->duplicates);
	}

	public function test_ambiguous_existing_reservation_without_form_id_never_creates_a_second_clone(): void {
		$store = new Memory_Materialization_Store();
		$store->records['2027:MT1'] = array('publication_key' => '2027:MT1', 'template_id' => 199, 'form_id' => null, 'status' => 'RECEIVED', 'payload_hash' => 'a');
		$gateway = new Fake_Gravity_Gateway();

		$result = $this->service($store, $gateway)->materialize($this->payload(), 'a');

		self::assertSame('turmas_bridge_reconciliation_required', $result->get_error_code());
		self::assertSame(0, $gateway->duplicates);
		self::assertSame('RECEIVED', $store->records['2027:MT1']['status']);
	}

	public function test_failure_before_reservation_can_retry_after_the_template_becomes_valid(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway(); $template = $gateway->template; $gateway->template = null;
		$service = $this->service($store, $gateway);

		self::assertSame('turmas_bridge_invalid_template', $service->materialize($this->payload(), 'a')->get_error_code());
		self::assertSame(0, $gateway->duplicates);
		$gateway->template = $template;
		self::assertSame('materialized', $service->materialize($this->payload(), 'b')['status']);
		self::assertSame(1, $gateway->duplicates);
	}

	public function test_clone_failure_leaves_an_ambiguous_reservation_blocked_for_reconciliation(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway(); $gateway->clone_fails = true;
		$service = $this->service($store, $gateway);

		self::assertSame('turmas_bridge_form_clone_failed', $service->materialize($this->payload(), 'a')->get_error_code());
		$gateway->clone_fails = false;
		self::assertSame('turmas_bridge_reconciliation_required', $service->materialize($this->payload(), 'a')->get_error_code());
		self::assertSame(0, $gateway->duplicates);
	}

	/** @dataProvider incompatible_cre_sets */
	public function test_missing_cre_is_rejected_before_form_or_mapping_creation(array $cres, array $missing): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway();
		$payload = $this->payload(); $payload['classes'][0]['cres'] = $cres;
		$result = $this->service($store, $gateway)->materialize($payload, 'a');
		self::assertSame('turmas_bridge_cre_field_missing', $result->get_error_code());
		self::assertSame($missing, $result->get_error_data()['missing_cres']);
		self::assertTrue($result->get_error_data()['pre_effect']);
		self::assertSame(0, $gateway->duplicates);
		self::assertSame(array(), $store->records);
	}

	public static function incompatible_cre_sets(): iterable {
		yield 'one missing' => array(array('01', '03'), array('03'));
		yield 'multiple missing' => array(array('01', '03', '04'), array('03', '04'));
		yield 'physical P3 structural case' => array(array('04', '05', '11'), array('04', '05', '11'));
	}

	public function test_extra_template_fields_are_benign_but_duplicate_or_incompatible_managed_fields_fail_closed(): void {
		$gateway = new Fake_Gravity_Gateway(); $gateway->template['fields'][] = array('id' => 12, 'type' => 'text', 'adminLabel' => 'notes');
		self::assertSame('materialized', $this->service(new Memory_Materialization_Store(), $gateway)->materialize($this->payload(), 'a')['status']);
		$gateway = new Fake_Gravity_Gateway(); $gateway->template['fields'][] = array('id' => 12, 'type' => 'select', 'adminLabel' => 'turma_cre_01', 'choices' => array());
		self::assertSame('turmas_bridge_cre_field_ambiguous', $this->service(new Memory_Materialization_Store(), $gateway)->materialize($this->payload(), 'a')->get_error_code());
		self::assertSame(0, $gateway->duplicates);
		$gateway = new Fake_Gravity_Gateway(); $gateway->template['fields'][0]['type'] = 'text';
		self::assertSame('turmas_bridge_cre_field_incompatible', $this->service(new Memory_Materialization_Store(), $gateway)->materialize($this->payload(), 'a')->get_error_code());
		self::assertSame(0, $gateway->duplicates);
		$gateway = new Fake_Gravity_Gateway(); $gateway->template['fields'][1]['id'] = 10;
		self::assertSame('turmas_bridge_cre_field_ambiguous', $this->service(new Memory_Materialization_Store(), $gateway)->materialize($this->payload(), 'a')->get_error_code());
		self::assertSame(0, $gateway->duplicates);
		$gateway = new Fake_Gravity_Gateway(); $gateway->template['fields'][] = 'malformed field';
		self::assertSame('turmas_bridge_cre_field_incompatible', $this->service(new Memory_Materialization_Store(), $gateway)->materialize($this->payload(), 'a')->get_error_code());
		self::assertSame(0, $gateway->duplicates);
	}

	public function test_inventory_runtime_failure_is_pre_effect(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway();
		$preflight = new Template_Preflight(static fn (): ?string => 'turmas_bridge_gp_inventory_version_unsupported');
		$result = (new Materialization_Service($store, $gateway, null, $preflight))->materialize($this->payload(), 'a');
		self::assertSame('turmas_bridge_gp_inventory_version_unsupported', $result->get_error_code());
		self::assertSame(0, $gateway->duplicates); self::assertSame(array(), $store->records);
	}

	public function test_materialized_form_without_completed_choices_cannot_be_blindly_replayed(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway(); $service = $this->service($store, $gateway);
		self::assertSame('materialized', $service->materialize($this->payload(), 'a')['status']);
		self::assertSame('turmas_bridge_reconciliation_required', $service->materialize($this->payload(), 'a')->get_error_code());
		self::assertSame(1, $gateway->duplicates);
	}

	public function test_template_change_during_clone_is_partial_effect_and_requires_reconciliation(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway(); $gateway->clone_drops_cre = true;
		$service = $this->service($store, $gateway);
		self::assertSame('turmas_bridge_clone_structure_invalid', $service->materialize($this->payload(), 'a')->get_error_code());
		self::assertSame(412, $store->records['2027:MT1']['form_id']);
		self::assertSame('FAILED', $store->records['2027:MT1']['status']);
		self::assertSame('turmas_bridge_reconciliation_required', $service->materialize($this->payload(), 'a')->get_error_code());
		self::assertSame(1, $gateway->duplicates);
	}

	public function test_clone_left_active_is_partial_effect_not_a_success(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway(); $gateway->clone_stays_active = true;
		$result = $this->service($store, $gateway)->materialize($this->payload(), 'a');
		self::assertSame('turmas_bridge_clone_structure_invalid', $result->get_error_code());
		self::assertSame(412, $store->records['2027:MT1']['form_id']);
		self::assertSame('FAILED', $store->records['2027:MT1']['status']);
	}

	public function test_existing_publication_rejects_different_payload_without_second_form(): void {
		$store = new Memory_Materialization_Store(); $gateway = new Fake_Gravity_Gateway(); $service = $this->service($store, $gateway);
		$service->materialize($this->payload(), 'a');
		$store->choice_fingerprint('2027:MT1', 'prepared');
		self::assertSame('turmas_bridge_materialization_payload_conflict', $service->materialize($this->payload(), 'b')->get_error_code());
		self::assertSame(1, $gateway->duplicates);
	}

	private function service(Memory_Materialization_Store $store, Fake_Gravity_Gateway $gateway): Materialization_Service { return new Materialization_Service($store, $gateway, null, new Template_Preflight(static fn (): ?string => null)); }
	/** @return array<string,mixed> */
	private function payload(): array { return array('publication' => array('year' => 2027, 'formation_code' => 'MT1', 'publication_key' => '2027:MT1'), 'classes' => array(array('class_key' => '2027:MT1:01.01', 'class_code' => '01.01', 'short_name' => 'MT1 01.01 SEG', 'capacity' => 3, 'cres' => array('01', '02')))); }
}

final class Memory_Materialization_Store implements Materialization_Store {
	/** @var array<string,array<string,mixed>> */ public array $records = array(); public bool $fail_materialized = false;
	public function find(string $publication_key): ?array { return $this->records[$publication_key] ?? null; }
	public function reserve(string $publication_key, int $template_id, string $payload_hash): bool { if (isset($this->records[$publication_key])) return false; $this->records[$publication_key] = array('publication_key' => $publication_key, 'template_id' => $template_id, 'form_id' => null, 'status' => 'RECEIVED', 'payload_hash' => $payload_hash); return true; }
	public function materialized(string $publication_key, int $form_id, array $field_ids): bool { $this->records[$publication_key]['form_id'] = $form_id; $this->records[$publication_key]['field_ids'] = $field_ids; if ($this->fail_materialized) { $this->records[$publication_key]['status'] = 'FAILED'; return false; } $this->records[$publication_key]['status'] = 'MATERIALIZED'; return true; }
	public function failed(string $publication_key, ?int $form_id, string $error_code): bool { $this->records[$publication_key]['form_id'] = $form_id; $this->records[$publication_key]['status'] = 'FAILED'; return true; }
	public function choice_fingerprint(string $publication_key, string $fingerprint): bool { $this->records[$publication_key]['choices_fingerprint'] = $fingerprint; return true; }
	public function acquire_choice_lock(string $publication_key): bool { return true; }
	public function release_choice_lock(string $publication_key): void {}
}

final class Fake_Gravity_Gateway implements Gravity_Forms_Gateway {
	public bool $available = true; public bool $materialized_form_missing = false; public bool $clone_fails = false; public bool $clone_drops_cre = false; public bool $clone_stays_active = false; /** @var array<string,mixed>|null */ public ?array $template = array('id' => 199, 'is_active' => true, 'fields' => array(array('id' => 10, 'type' => 'select', 'adminLabel' => 'turma_cre_01', 'choices' => array(array('text' => 'x'))), array('id' => 11, 'type' => 'select', 'adminLabel' => 'turma_cre_02', 'choices' => array(array('text' => 'y'))))); public int $duplicates = 0;
	public function is_available(): bool { return $this->available; }
	public function form(int $form_id): ?array { if ($form_id === 199) return $this->template; return $this->duplicates > 0 && ! $this->materialized_form_missing ? array('id' => $form_id, 'is_active' => $this->clone_stays_active, 'fields' => $this->clone_drops_cre ? array_slice($this->template['fields'], 0, 1) : $this->template['fields']) : null; }
	public function duplicate_inactive(int $template_id, string $title, string $marker): int|\WP_Error { if ($this->clone_fails) return new \WP_Error('turmas_bridge_form_clone_failed', 'Falha fictícia de clone.'); $this->duplicates++; return 412; }
	public function update_form(array $form): bool|\WP_Error { return true; }
}
