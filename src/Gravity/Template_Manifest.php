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
			return $this->error('turmas_bridge_gravity_forms_unavailable', 'Gravity Forms não está disponível.', 503);
		}

		try {
			$form = call_user_func($this->reader, $form_id);
		} catch (\Throwable) {
			return $this->error('turmas_bridge_template_read_failed', 'Não foi possível ler o formulário-modelo.', 500);
		}
		if (is_wp_error($form)) {
			return $this->error('turmas_bridge_template_read_failed', 'Não foi possível ler o formulário-modelo.', 500);
		}
		if (! is_array($form)) {
			return $this->error('turmas_bridge_template_not_found', 'Formulário-modelo não encontrado.', 404);
		}

		$fields = array();
		foreach ((array) ($form['fields'] ?? array()) as $field) {
			$manifest_field = $this->field($field);
			if ($manifest_field !== null) {
				$fields[] = $manifest_field;
			}
		}

		$manifest = array(
			'form' => array(
				'id' => (int) ($form['id'] ?? $form_id),
				'title' => sanitize_text_field((string) ($form['title'] ?? '')),
				'status' => $this->status($form),
			),
			'fields' => $fields,
			'integration_hints' => $this->integration_hints($fields),
		);
		$manifest_json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (! is_string($manifest_json)) {
			return $this->error('turmas_bridge_template_manifest_invalid', 'Não foi possível produzir o manifesto seguro do formulário-modelo.', 500);
		}
		$manifest['form']['fingerprint'] = hash('sha256', $manifest_json);

		return $manifest;
	}

	/** @return array<string, mixed>|null */
	private function field(mixed $field): ?array {
		$id = $this->identifier($this->property($field, 'id'));
		$type = sanitize_key((string) $this->property($field, 'type'));
		if ($id === null || $type === '') {
			return null;
		}

		$inputs = array();
		foreach ((array) $this->property($field, 'inputs') as $input) {
			$input_id = $this->identifier($this->property($input, 'id'));
			if ($input_id !== null) {
				$inputs[] = $input_id;
			}
		}

		$choices = $this->choices((array) $this->property($field, 'choices'));

		return array(
			'id' => $id,
			'type' => $type,
			'label' => sanitize_text_field((string) $this->property($field, 'label')),
			'admin_label' => sanitize_text_field((string) $this->property($field, 'adminLabel')),
			'input_type' => sanitize_key((string) $this->property($field, 'inputType')),
			'is_required' => (bool) $this->property($field, 'isRequired'),
			'allows_prepopulate' => (bool) $this->property($field, 'allowsPrepopulate'),
			'input_name' => sanitize_key((string) $this->property($field, 'inputName')),
			'input_ids' => $inputs,
			'has_conditional_logic' => $this->has_structural_configuration($this->property($field, 'conditionalLogic')),
			'has_gp_inventory' => $this->has_structural_configuration($this->property($field, 'gpiInventory')),
			'has_choices' => $choices !== array(),
			'choice_count' => count($choices),
			'choices' => $choices,
		);
	}

	/** @param list<mixed> $choices @return list<array{text:string,value:string}> */
	private function choices(array $choices): array {
		$normalised = array();
		foreach ($choices as $choice) {
			if (! is_array($choice) && ! is_object($choice)) {
				continue;
			}
			$normalised[] = array(
				'text' => sanitize_text_field((string) $this->property($choice, 'text')),
				'value' => sanitize_text_field((string) $this->property($choice, 'value')),
			);
		}

		return $normalised;
	}

	/** @param list<array<string, mixed>> $fields @return array<string, mixed> */
	private function integration_hints(array $fields): array {
		return array(
			'turma_candidates' => $this->candidates($fields, 'turma', true),
			'formacao_candidates' => $this->candidates($fields, 'formacao'),
			'participant_identifier_candidates' => $this->candidates($fields, 'cpf'),
			'choice_field_ids' => array_values(array_map(
				static fn (array $field): string => (string) $field['id'],
				array_filter($fields, static fn (array $field): bool => (bool) $field['has_choices'])
			)),
			'conditional_logic_field_ids' => $this->field_ids_with_flag($fields, 'has_conditional_logic'),
			'gp_inventory_field_ids' => $this->field_ids_with_flag($fields, 'has_gp_inventory'),
			'note' => 'Candidatos são heurísticos. A configuração futura deve usar field ID e admin_label/input_name aprovados para o template, não somente labels visíveis.',
		);
	}

	/** @param list<array<string, mixed>> $fields @return list<string> */
	private function field_ids_with_flag(array $fields, string $flag): array {
		return array_values(array_map(
			static fn (array $field): string => (string) $field['id'],
			array_filter($fields, static fn (array $field): bool => ! empty($field[$flag]))
		));
	}

	/** @param list<array<string, mixed>> $fields @return list<array{field_id:string,signals:list<string>}> */
	private function candidates(array $fields, string $needle, bool $include_choice_fields = false): array {
		$candidates = array();
		foreach ($fields as $field) {
			$signals = array();
			if (str_contains($this->normalise_hint_text((string) $field['admin_label']), $needle)) {
				$signals[] = 'admin_label';
			}
			if (str_contains($this->normalise_hint_text((string) $field['input_name']), $needle)) {
				$signals[] = 'input_name';
			}
			if ($include_choice_fields && (bool) $field['has_choices']) {
				$signals[] = 'choice_capable';
			}
			if ($signals !== array()) {
				$candidates[] = array('field_id' => (string) $field['id'], 'signals' => array_values(array_unique($signals)));
			}
		}

		return $candidates;
	}

	private function normalise_hint_text(string $value): string {
		$value = strtolower($value);
		$value = strtr($value, array('á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c'));

		return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
	}

	/** @param array<string, mixed> $form */
	private function status(array $form): string {
		if (! empty($form['is_trash'])) {
			return 'trash';
		}

		return ! empty($form['is_active']) ? 'active' : 'inactive';
	}

	private function identifier(mixed $value): ?string {
		$value = trim((string) $value);

		return preg_match('/^\d+(?:\.\d+)?$/', $value) ? $value : null;
	}

	private function has_structural_configuration(mixed $value): bool {
		if (is_array($value)) {
			return $value !== array();
		}
		if (is_object($value)) {
			return get_object_vars($value) !== array();
		}
		if (is_string($value)) {
			return trim($value) !== '';
		}

		return (bool) $value;
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

	private function error(string $code, string $message, int $status): \WP_Error {
		return new \WP_Error($code, $message, array('status' => $status));
	}
}
