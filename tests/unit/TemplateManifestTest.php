<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Gravity\Template_Manifest;

final class TemplateManifestTest extends TestCase {
	public function test_manifest_contains_only_allowlisted_template_metadata(): void {
		$manifest = (new Template_Manifest(
			static fn (): bool => true,
			static fn (int $id): array => array(
				'id' => $id,
				'title' => 'Modelo <b>fictício</b>',
				'is_active' => true,
				'notifications' => array('sensitive@example.test'),
				'entries' => array(array('cpf' => '00000000000')),
				'fields' => array(
					array('id' => 10, 'type' => 'select', 'label' => 'Turma', 'choices' => array(array('text' => 'A', 'value' => 'A'))),
					array('id' => 20, 'type' => 'text', 'label' => 'CPF', 'allowsPrepopulate' => true, 'inputName' => 'cpf'),
				),
			)
		))->for_form(282);

		self::assertIsArray($manifest);
		self::assertSame(array('id', 'title', 'status', 'fields', 'field_summary'), array_keys($manifest));
		self::assertSame('Modelo fictício', $manifest['title']);
		self::assertCount(2, $manifest['fields']);
		self::assertSame(array('id', 'type', 'label', 'input_type', 'is_required', 'allows_prepopulate', 'input_name', 'input_ids', 'has_choices', 'choice_count'), array_keys($manifest['fields'][0]));
		self::assertArrayNotHasKey('choices', $manifest['fields'][0]);
		self::assertStringNotContainsString('sensitive@example.test', (string) json_encode($manifest));
		self::assertStringNotContainsString('00000000000', (string) json_encode($manifest));
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
}
