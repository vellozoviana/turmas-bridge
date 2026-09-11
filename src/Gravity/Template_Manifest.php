<?php

declare(strict_types=1);

namespace TurmasBridge\Gravity;

final class Template_Manifest {
	/** @var callable():bool */
	private $availability;
	/** @var callable(int):mixed */
	private $reader;

	public function __construct(?callable $availability = null, ?callable $reader = null) {
		$this->availability = $availability ?? static fn (): bool => class_exists('GFAPI') && method_exists('GFAPI', 'get_form');
		$this->reader = $reader ?? static fn (int $form_id): mixed => \GFAPI::get_form($form_id);
	}

	public function is_available(): bool {
		return (bool) call_user_func($this->availability);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function for_form(int $form_id): array|\WP_Error {
		if (! $this->is_available()) {
			return new \WP_Error('turmas_bridge_gravity_forms_unavailable', 'Gravity Forms não está disponível.', array('status' => 503));
		}

		$form = call_user_func($this->reader, $form_id);
		if (! is_array($form)) {
			return new \WP_Error('turmas_bridge_template_not_found', 'Formulário-modelo não encontrado.', array('status' => 404));
		}

		$fields = array();
		foreach ((array) ($form['fields'] ?? array()) as $field) {
			$manifest_field = $this->field($field);
			if ($manifest_field !== null) {
				$fields[] = $manifest_field;
			}
		}

		return array(
			'id' => (int) ($form['id'] ?? $form_id),
			'title' => sanitize_text_field((string) ($form['title'] ?? '')),
			'status' => ! empty($form['is_active']) ? 'active' : 'inactive',
			'fields' => $fields,
			'field_summary' => array(
				'field_count' => count($fields),
				'choice_field_count' => count(array_filter($fields, static fn (array $field): bool => $field['has_choices'])),
				'prepopulated_field_count' => count(array_filter($fields, static fn (array $field): bool => $field['allows_prepopulate'])),
			),
		);
	}

	/** @return array<string, mixed>|null */
	private function field(mixed $field): ?array {
		$id = $this->property($field, 'id');
		$type = sanitize_key((string) $this->property($field, 'type'));
		if ($id === null || $type === '') {
			return null;
		}

		$inputs = array();
		foreach ((array) $this->property($field, 'inputs') as $input) {
			$input_id = $this->property($input, 'id');
			if ($input_id !== null) {
				$inputs[] = (string) $input_id;
			}
		}

		return array(
			'id' => (string) $id,
			'type' => $type,
			'label' => sanitize_text_field((string) $this->property($field, 'label')),
			'input_type' => sanitize_key((string) $this->property($field, 'inputType')),
			'is_required' => (bool) $this->property($field, 'isRequired'),
			'allows_prepopulate' => (bool) $this->property($field, 'allowsPrepopulate'),
			'input_name' => sanitize_key((string) $this->property($field, 'inputName')),
			'input_ids' => $inputs,
			'has_choices' => count((array) $this->property($field, 'choices')) > 0,
			'choice_count' => count((array) $this->property($field, 'choices')),
		);
	}

	private function property(mixed $source, string $property): mixed {
		if (is_array($source)) {
			return $source[$property] ?? null;
		}
		if (is_object($source)) {
			return $source->{$property} ?? null;
		}

		return null;
	}
}
