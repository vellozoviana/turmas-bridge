<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Gravity\Template_Manifest;

final class TemplateManifestTest extends TestCase {
	public function test_manifest_contains_only_allowlisted_template_metadata(): void {
		$manifest = $this->manifest_for($this->valid_form());

		self::assertIsArray($manifest);
		self::assertSame(array('form', 'fields', 'integration_hints'), array_keys($manifest));
		self::assertSame(array('id', 'title', 'status', 'fingerprint'), array_keys($manifest['form']));
		self::assertSame('Modelo fictício', $manifest['form']['title']);
		self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['form']['fingerprint']);
		self::assertCount(3, $manifest['fields']);
		self::assertSame(array('id', 'type', 'label', 'admin_label', 'input_type', 'is_required', 'allows_prepopulate', 'input_name', 'input_ids', 'has_conditional_logic', 'has_gp_inventory', 'has_choices', 'choice_count', 'choices'), array_keys($manifest['fields'][0]));
		self::assertSame(array('text' => 'Turma A', 'value' => 'turma-a'), $manifest['fields'][0]['choices'][0]);
		self::assertSame(array('10'), $manifest['integration_hints']['choice_field_ids']);
		self::assertSame(array('10'), $manifest['integration_hints']['conditional_logic_field_ids']);
		self::assertSame(array('10'), $manifest['integration_hints']['gp_inventory_field_ids']);
	}

	public function test_choices_are_normalised_and_unknown_properties_do_not_leak(): void {
		$manifest = $this->manifest_for($this->valid_form());
		$encoded = (string) json_encode($manifest);

		self::assertArrayNotHasKey('isSelected', $manifest['fields'][0]['choices'][0]);
		self::assertStringNotContainsString('price', $encoded);
		self::assertStringNotContainsString('arbitrary_internal_property', $encoded);
		self::assertStringNotContainsString('sensitive@example.test', $encoded);
		self::assertStringNotContainsString('00000000000', $encoded);
		self::assertStringNotContainsString('bridge-test-secret-only', $encoded);
		self::assertStringNotContainsString('rule@example.test', $encoded);
		self::assertStringNotContainsString('inventory-limit-should-not-leak', $encoded);
	}

	public function test_multiple_turma_fields_are_reported_as_candidates_without_selecting_one(): void {
		$fields = array();
		for ($cre = 1; $cre <= 11; $cre++) {
			$fields[] = array(
				'id' => 194 + $cre,
				'type' => 'select',
				'label' => sprintf('Turma %dª CRE', $cre),
				'adminLabel' => sprintf('turma_cre_%02d', $cre),
				'isRequired' => true,
				'choices' => array(array('text' => 'Turma de teste', 'value' => sprintf('turma-%02d', $cre))),
			);
		}

		$manifest = $this->manifest_for(array('id' => 199, 'title' => 'Modelo fictício', 'is_active' => true, 'fields' => $fields));

		self::assertCount(11, $manifest['integration_hints']['turma_candidates']);
		self::assertSame('195', $manifest['integration_hints']['turma_candidates'][0]['field_id']);
		self::assertSame('205', $manifest['integration_hints']['turma_candidates'][10]['field_id']);
		self::assertSame(array('195', '196', '197', '198', '199', '200', '201', '202', '203', '204', '205'), $manifest['integration_hints']['choice_field_ids']);
	}

	public function test_integration_hints_use_stable_metadata_without_deciding_a_mapping(): void {
		$manifest = $this->manifest_for($this->valid_form());

		self::assertSame(array(array('field_id' => '10', 'signals' => array('admin_label', 'input_name', 'choice_capable'))), $manifest['integration_hints']['turma_candidates']);
		self::assertSame(array(array('field_id' => '11', 'signals' => array('admin_label', 'input_name'))), $manifest['integration_hints']['formacao_candidates']);
		self::assertSame(array(array('field_id' => '20', 'signals' => array('admin_label', 'input_name'))), $manifest['integration_hints']['participant_identifier_candidates']);
		self::assertStringContainsString('heurísticos', $manifest['integration_hints']['note']);
	}

	public function test_unavailable_gravity_forms_returns_controlled_error(): void {
		$result = (new Template_Manifest(static fn (): bool => false))->for_form(1);

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_gravity_forms_unavailable', $result->get_error_code());
		self::assertSame(503, $result->get_error_data()['status']);
	}

	public function test_missing_template_returns_not_found(): void {
		$result = (new Template_Manifest(static fn (): bool => true, static fn (int $id): bool => false))->for_form(999);

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_template_not_found', $result->get_error_code());
		self::assertSame(404, $result->get_error_data()['status']);
	}

	public function test_gravity_forms_read_error_is_sanitised_without_stack_trace(): void {
		$result = (new Template_Manifest(static fn (): bool => true, static function (int $id): never {
			throw new \RuntimeException('Internal database path and bridge-test-secret-only');
		}))->for_form(282);

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_template_read_failed', $result->get_error_code());
		self::assertSame(500, $result->get_error_data()['status']);
		self::assertStringNotContainsString('Internal database path', $result->get_error_message());
		self::assertStringNotContainsString('bridge-test-secret-only', $result->get_error_message());
	}

	public function test_unserialisable_template_returns_controlled_manifest_error(): void {
		$result = $this->manifest_result_for(array(
			'id' => 282,
			'title' => "Invalid UTF-8 \xB1",
			'is_active' => true,
			'fields' => array(),
		));

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_template_manifest_invalid', $result->get_error_code());
		self::assertSame(500, $result->get_error_data()['status']);
	}

	/** @param array<string, mixed> $form @return array<string, mixed> */
	private function manifest_for(array $form): array {
		$result = $this->manifest_result_for($form);
		self::assertIsArray($result);

		return $result;
	}

	/** @param array<string, mixed> $form */
	private function manifest_result_for(array $form): array|\WP_Error {
		return (new Template_Manifest(static fn (): bool => true, static fn (int $id): array => $form))->for_form(282);
	}

	/** @return array<string, mixed> */
	private function valid_form(): array {
		return array(
			'id' => 282,
			'title' => 'Modelo <b>fictício</b>',
			'is_active' => true,
			'notifications' => array('sensitive@example.test'),
			'entries' => array(array('cpf' => '00000000000')),
			'arbitrary_internal_property' => 'never-return-this',
			'fields' => array(
				array(
					'id' => 10,
					'type' => 'select',
					'label' => 'Turma',
					'adminLabel' => 'turma_publicacao',
					'inputName' => 'turma_publicacao',
					'conditionalLogic' => array('actionType' => 'show', 'rules' => array(array('fieldId' => '20', 'value' => 'rule@example.test'))),
					'gpiInventory' => array('type' => 'simple', 'limit' => 'inventory-limit-should-not-leak'),
					'choices' => array(array('text' => 'Turma <b>A</b>', 'value' => 'turma-a', 'isSelected' => true, 'price' => '100', 'inventory_limit' => 'inventory-limit-should-not-leak')),
				),
				array('id' => 11, 'type' => 'text', 'label' => 'Formação', 'adminLabel' => 'formacao', 'inputName' => 'formacao'),
				array('id' => 20, 'type' => 'text', 'label' => 'CPF', 'adminLabel' => 'cpf_participante', 'allowsPrepopulate' => true, 'inputName' => 'cpf'),
			),
		);
	}
}
