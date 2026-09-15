<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Choices\Publication_Choice_Service;
use TurmasBridge\Materialization\Gravity_Forms_Gateway;
use TurmasBridge\Materialization\Materialization_Store;

final class PublicationChoiceServiceTest extends TestCase {
	public function test_same_class_key_is_materialized_in_multiple_cre_fields_with_single_resource_plan(): void {
		$store = new Choice_Store_Fake(); $gateway = new Choice_Gateway_Fake(); $service = new Publication_Choice_Service($store, $gateway);
		$result = $service->prepare($this->payload());
		self::assertSame('2027:MT1:01.01', $gateway->form['fields'][0]['choices'][0]['value']);
		self::assertSame('2027:MT1:01.01', $gateway->form['fields'][1]['choices'][0]['value']);
		self::assertSame(1, count($result['resource_plans'])); self::assertSame(30, $result['resource_plans'][0]['capacity']); self::assertCount(2, $result['resource_plans'][0]['representations']);
		self::assertSame(array(), $gateway->form['fields'][2]['choices']); self::assertSame('Nome', $gateway->form['fields'][3]['label']); self::assertFalse($gateway->form['is_active']);
	}

	public function test_update_is_convergent_and_preserves_non_managed_fields(): void {
		$store = new Choice_Store_Fake(); $gateway = new Choice_Gateway_Fake(); $service = new Publication_Choice_Service($store, $gateway);
		$service->prepare($this->payload()); $again = $service->prepare($this->payload());
		self::assertTrue($again['idempotent_replay']); self::assertSame(1, $gateway->updates); self::assertSame('keep', $gateway->form['fields'][3]['choices'][0]['value']);
	}

	public function test_missing_or_ambiguous_cre_field_fails_before_update(): void {
		$store = new Choice_Store_Fake(); $gateway = new Choice_Gateway_Fake(); array_splice($gateway->form['fields'], 1, 1);
		$result = (new Publication_Choice_Service($store, $gateway))->prepare($this->payload()); self::assertSame('turmas_bridge_cre_field_missing', $result->get_error_code()); self::assertSame(0, $gateway->updates);
		$gateway = new Choice_Gateway_Fake(); $gateway->form['fields'][] = array('id' => 99, 'type' => 'select', 'adminLabel' => 'turma_cre_01', 'choices' => array());
		$result = (new Publication_Choice_Service(new Choice_Store_Fake(), $gateway))->prepare($this->payload()); self::assertSame('turmas_bridge_cre_field_ambiguous', $result->get_error_code()); self::assertSame(0, $gateway->updates);
	}

	/** @return array<string,mixed> */
	private function payload(): array { return array('publication' => array('publication_key' => '2027:MT1'), 'classes' => array(array('class_key' => '2027:MT1:01.01', 'class_code' => '01.01', 'short_name' => 'MT1 01.01 SEG MANHÃ', 'capacity' => 30, 'cres' => array('01','02')))); }
}

final class Choice_Store_Fake implements Materialization_Store {
	/** @var array<string,mixed> */ public array $record = array('status' => 'MATERIALIZED', 'form_id' => 412, 'choices_fingerprint' => ''); public bool $locked = false;
	public function find(string $publication_key): ?array { return $this->record; } public function reserve(string $publication_key, int $template_id, string $payload_hash): bool { return true; } public function materialized(string $publication_key, int $form_id, array $field_ids): bool { return true; } public function failed(string $publication_key, ?int $form_id, string $error_code): bool { return true; }
	public function choice_fingerprint(string $publication_key, string $fingerprint): bool { $this->record['choices_fingerprint'] = $fingerprint; return true; } public function acquire_choice_lock(string $publication_key): bool { if ($this->locked) return false; $this->locked = true; return true; } public function release_choice_lock(string $publication_key): void { $this->locked = false; }
}

final class Choice_Gateway_Fake implements Gravity_Forms_Gateway {
	/** @var array<string,mixed> */ public array $form; public int $updates = 0;
	public function __construct() { $this->form = array('id' => 412, 'is_active' => false, 'fields' => array(array('id' => 10,'type' => 'select','adminLabel' => 'turma_cre_01','choices' => array(array('text'=>'old','value'=>'old'))), array('id' => 11,'type' => 'select','adminLabel' => 'turma_cre_02','choices' => array(array('text'=>'old','value'=>'old'))), array('id' => 12,'type' => 'select','adminLabel' => 'turma_cre_03','choices' => array(array('text'=>'old','value'=>'old'))), array('id' => 30,'type' => 'text','label' => 'Nome','choices' => array(array('text'=>'keep','value'=>'keep'))))); }
	public function is_available(): bool { return true; } public function form(int $form_id): ?array { return $this->form; } public function duplicate_inactive(int $template_id, string $title, string $marker): int|\WP_Error { return 412; } public function update_form(array $form): bool|\WP_Error { $this->updates++; $this->form = $form; return true; }
}
