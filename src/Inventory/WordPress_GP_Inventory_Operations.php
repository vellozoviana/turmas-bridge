<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/**
 * Compatibility-sensitive adapter for GP Inventory 1.0.29 internals.
 * Gravity Forms APIs are public; Resource posts, gpi_field and counting helpers
 * are deliberately isolated here because they are not public vendor APIs.
 */
final class WordPress_GP_Inventory_Operations implements GP_Inventory_Operations {
	public function is_available(): bool {
		if (! (class_exists('GFAPI')
			&& method_exists('GFAPI', 'get_form')
			&& method_exists('GFAPI', 'update_form')
			&& function_exists('gp_inventory_resources')
			&& function_exists('gp_inventory_type_advanced')
			&& function_exists('gp_inventory_type_choices')
			&& function_exists('wp_insert_post')
			&& function_exists('get_posts')
			&& function_exists('get_post_type')
			&& function_exists('get_post_meta')
			&& function_exists('update_post_meta')
			&& function_exists('do_action'))) {
			return false;
		}
		try {
			$choices = \gp_inventory_type_choices();
		} catch (\Throwable) {
			return false;
		}
		return is_object($choices)
			&& method_exists($choices, 'flush_choice_count_cache')
			&& method_exists($choices, 'get_choice_count');
	}
	public function find_resource(Resource_Identity $identity): ?int {
		if (! $this->is_available()) return null;
		$ids = get_posts(array('post_type' => 'gpi_resource', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 2, 'no_found_rows' => true, 'meta_query' => array('relation' => 'AND', array('key' => 'turmas_bridge_managed', 'value' => '1', 'compare' => '='), array('key' => 'turmas_bridge_class_key', 'value' => $identity->class_key(), 'compare' => '='))));
		if (! is_array($ids)) throw new Inventory_Integration_Exception('turmas_bridge_inventory_resource_lookup_failed', 'Não foi possível consultar Resources existentes.');
		if (count($ids) > 1) throw new Inventory_Integration_Exception('turmas_bridge_inventory_resource_ambiguous', 'Há mais de um Resource candidato para esta class_key.');
		if (count($ids) === 0) return null;
		if (! is_numeric($ids[0])) throw new Inventory_Integration_Exception('turmas_bridge_inventory_resource_lookup_failed', 'O Resource encontrado possui identificador inválido.');
		return (int) $ids[0];
	}
	public function resource_exists(int $resource_id): bool { return $resource_id > 0 && get_post_type($resource_id) === 'gpi_resource'; }
	public function create_resource(Resource_Identity $identity): int {
		if (! $this->is_available()) throw new Inventory_Integration_Exception('turmas_bridge_gp_inventory_unavailable', 'O runtime do GP Inventory não está disponível.');
		try {
			$result = wp_insert_post(array('post_type' => 'gpi_resource', 'post_title' => '[Turmas Bridge] ' . $identity->class_key(), 'post_status' => 'publish', 'meta_input' => array('turmas_bridge_managed' => '1', 'turmas_bridge_class_key' => $identity->class_key(), 'gpi_inventory_limit' => '1', 'gpi_choice_based' => '0', 'gpi_properties' => array())), true);
		} catch (\Throwable) {
			throw new Inventory_Integration_Exception('turmas_bridge_inventory_resource_create_unknown', 'O resultado da criação do Resource externo é indeterminado.', true);
		}
		if (is_wp_error($result) || ! is_numeric($result) || (int) $result < 1) throw new Inventory_Integration_Exception('turmas_bridge_inventory_resource_create_failed', 'Não foi possível criar o Resource externo.');
		return (int) $result;
	}
	public function inspect(Resource_Plan $plan, int $resource_id): array {
		$form = $this->form($plan);
		if (! $this->resource_exists($resource_id)) return $this->state(0, 0, false, 'resource_missing');
		if (! empty($form['is_active'])) return $this->state(0, 0, false, 'form_active');
		$fields = $this->field_map($form);
		$consumed = $this->fresh_consumed($form, $fields, $plan);
		$expected_bindings = array();
		$limits = array();
		foreach ($plan->representations() as $representation) {
			$field = $fields[$representation->field_id()] ?? null;
			if ($field === null) return $this->state(0, $consumed, false, 'representation_missing');
			$expected_bindings[] = $plan->form_id() . '_' . $representation->field_id();
			if ((string) $this->read($field, 'gpiInventory') !== 'advanced' || (int) $this->read($field, 'gpiResource') !== $resource_id) return $this->state(0, $consumed, false, 'field_resource_drift');
			$choice = $this->choice($field, $representation->choice_value());
			if ($choice === null) return $this->state(0, $consumed, false, 'choice_value_drift');
			$limit = $choice['inventory_limit'] ?? null;
			if (! is_numeric($limit) || (int) $limit < 1) return $this->state(0, $consumed, false, 'capacity_missing');
			$limits[] = (int) $limit;
		}
		$bindings = array_map('strval', (array) get_post_meta($resource_id, 'gpi_field'));
		foreach ($expected_bindings as $binding) if (! in_array($binding, $bindings, true)) return $this->state($limits[0] ?? 0, $consumed, false, 'binding_missing');
		foreach ($bindings as $binding) if (str_starts_with($binding, $plan->form_id() . '_') && ! in_array($binding, $expected_bindings, true)) return $this->state($limits[0] ?? 0, $consumed, false, 'binding_drift');
		if (count(array_unique($limits)) !== 1) return $this->state(0, $consumed, false, 'capacity_drift');
		return $this->state($limits[0], $consumed, true, null);
	}
	public function synchronize(Resource_Plan $plan, int $resource_id): array {
		$form = $this->form($plan);
		if (! empty($form['is_active'])) return $this->state(0, 0, false, 'form_active');
		$fields = $this->field_map($form);
		foreach ($plan->representations() as $representation) {
			$field_id = $representation->field_id();
			if (! isset($fields[$field_id])) return $this->state(0, 0, false, 'representation_missing');
			$field = $fields[$field_id];
			$choice = $this->choice($field, $representation->choice_value());
			if ($choice === null) return $this->state(0, 0, false, 'choice_value_drift');
			$this->write($field, 'gpiInventory', 'advanced');
			$this->write($field, 'gpiResource', (string) $resource_id);
			$this->write($field, 'gpiResourcePropertyMap', array());
			$choices = (array) $this->read($field, 'choices');
			foreach ($choices as &$item) {
				if ((string) ($item['value'] ?? '') === $representation->choice_value()) {
					$item['inventory_limit'] = $plan->capacity();
					if ($representation->choice_text() !== '') $item['text'] = $representation->choice_text();
				}
			}
			unset($item);
			$this->write($field, 'choices', $choices);
			$this->replace_field($form, $field_id, $field);
		}
		$result = \GFAPI::update_form($form);
		if ($result === false || is_wp_error($result)) return $this->state(0, 0, false, 'form_update_failed');
		update_post_meta($resource_id, 'gpi_inventory_limit', (string) $plan->capacity());
		// GP Inventory records gpi_field on this documented Gravity Forms hook; it is an internal compatibility point.
		do_action('gform_after_save_form', \GFAPI::get_form($plan->form_id()), false, array());
		return $this->inspect($plan, $resource_id);
	}
	/** @return array<string,mixed> */
	private function form(Resource_Plan $plan): array {
		$form_id = $plan->form_id();
		$form = $form_id === null ? null : \GFAPI::get_form($form_id);
		if (! is_array($form)) throw new Inventory_Integration_Exception('turmas_bridge_inventory_form_missing', 'O formulário materializado não está disponível para inventário.');
		return $form;
	}
	/** @param array<string,mixed> $form @return array<string,mixed> */
	private function field_map(array $form): array {
		$map = array();
		foreach ((array) ($form['fields'] ?? array()) as $field) $map[(string) $this->read($field, 'id')] = $field;
		return $map;
	}
	private function read(mixed $field, string $key): mixed { return is_array($field) ? ($field[$key] ?? null) : ($field->{$key} ?? null); }
	private function write(mixed &$field, string $key, mixed $value): void { if (is_array($field)) $field[$key] = $value; else $field->{$key} = $value; }
	private function replace_field(array &$form, string $field_id, mixed $replacement): void { foreach ((array) $form['fields'] as $index => $field) if ((string) $this->read($field, 'id') === $field_id) { $form['fields'][$index] = $replacement; return; } }
	/** @return array<string,mixed>|null */
	private function choice(mixed $field, string $value): ?array { foreach ((array) $this->read($field, 'choices') as $choice) if ((string) ($choice['value'] ?? '') === $value) return $choice; return null; }
	/** @param array<string,mixed> $form @param array<string,mixed> $fields */
	private function fresh_consumed(array $form, array $fields, Resource_Plan $plan): int {
		$first = $plan->representations()[0] ?? null;
		if (! $first || ! isset($fields[$first->field_id()])) throw new Inventory_Integration_Exception('turmas_bridge_inventory_representation_missing', 'Não existe representação para leitura de consumo.');
		$choices = \gp_inventory_type_choices();
		if (! method_exists($choices, 'flush_choice_count_cache') || ! method_exists($choices, 'get_choice_count')) throw new Inventory_Integration_Exception('turmas_bridge_gp_inventory_incompatible', 'O GP Inventory não possui os símbolos necessários para leitura segura.');
		$choices->flush_choice_count_cache($form);
		return (int) $choices->get_choice_count($first->choice_value(), $fields[$first->field_id()], $plan->form_id());
	}
	/** @return array{capacity:int,consumed:int,healthy:bool,reason:?string} */
	private function state(int $capacity, int $consumed, bool $healthy, ?string $reason): array { return array('capacity' => $capacity, 'consumed' => $consumed, 'healthy' => $healthy, 'reason' => $reason); }
}
