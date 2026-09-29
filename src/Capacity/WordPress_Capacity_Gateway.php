<?php
declare(strict_types=1);
namespace TurmasBridge\Capacity;

use TurmasBridge\Inventory\Inventory_Mapping_Store;
use TurmasBridge\Inventory\Inventory_Mapping_Repository;
use TurmasBridge\Inventory\GP_Inventory_Operations;
use TurmasBridge\Inventory\WordPress_GP_Inventory_Operations;
use TurmasBridge\Inventory\Resource_Identity;
use TurmasBridge\Inventory\Resource_Plan;
use TurmasBridge\Inventory\Resource_Representation;
use TurmasBridge\Materialization\Materialization_Store;
use TurmasBridge\Materialization\Materialization_Repository;

/** GP 1.0.29 internals stay behind this boundary. No Entries, bindings or mappings are written here. */
final class WordPress_Capacity_Gateway implements Capacity_Gateway {
	public function __construct(private Inventory_Mapping_Store $mappings = new Inventory_Mapping_Repository(), private Materialization_Store $publications = new Materialization_Repository(), private GP_Inventory_Operations $operations = new WordPress_GP_Inventory_Operations()) {}
	public function lock(string $key): bool {
		$identity = Resource_Identity::from_class_key($key);
		// GF saves the entire form: serialize different classes of this publication too.
		// Same lock order as inventory preparation: publication first, then class.
		if (! $this->publications->acquire_choice_lock($identity->publication_key())) return false;
		try { if ($this->mappings->acquire_lock($identity)) return true; }
		catch (\Throwable $error) { $this->publications->release_choice_lock($identity->publication_key()); throw $error; }
		$this->publications->release_choice_lock($identity->publication_key());
		return false;
	}
	public function unlock(string $key): void {
		$identity = Resource_Identity::from_class_key($key);
		try { $this->mappings->release_lock($identity); }
		finally { $this->publications->release_choice_lock($identity->publication_key()); }
	}
	public function inspect(array $c): array {
		[$form, $resource, $plan] = $this->context($c);
		if (! empty($form['is_active'])) return array('form_active' => true, 'healthy' => false, 'resource_id' => $resource);
		$state = $this->operations->inspect($plan, $resource);
		if (function_exists('wp_cache_delete')) wp_cache_delete($resource, 'post_meta');
		$meta = get_post_meta($resource, 'gpi_inventory_limit', true);
		$limit = filter_var($meta, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
		$bindings = array_map('strval', (array) get_post_meta($resource, 'gpi_field'));
		sort($bindings, SORT_STRING);
		$expected = array_map(static fn (Resource_Representation $r): string => $c['expected_form_id'] . '_' . $r->field_id(), $plan->representations()); sort($expected, SORT_STRING);
		$state['healthy'] = $state['healthy'] && $limit !== false && $limit === $state['capacity'] && $bindings === $expected;
		$fresh_form = \GFAPI::get_form($c['expected_form_id']);
		if (! is_array($fresh_form) || ! empty($fresh_form['is_active']) || ! empty($fresh_form['is_trash'])) return $state + array('form_active' => true, 'resource_id' => $resource);
		return $state + array('form_active' => false, 'resource_id' => $resource, 'resource_capacity' => $limit === false ? null : $limit, 'bindings' => $bindings);
	}
	public function write(array $c): ?string {
		// Recheck immediately before any write. This is not a lock shared with GF submissions.
		$check = $this->inspect($c);
		if (! empty($check['form_active'])) return 'FORM_ACTIVE';
		if (($check['healthy'] ?? false) !== true) throw new \RuntimeException('Inventory drift before write.');
		if ($check['consumed'] > $c['desired_capacity']) return 'CAPACITY_BELOW_CONSUMED';
		[$form, $resource] = $this->context($c);
		if (! empty($form['is_active'])) return 'FORM_ACTIVE';
		foreach ($form['fields'] as &$field) {
			if (is_object($field)) $field = clone $field;
			$choices = is_object($field) ? ($field->choices ?? array()) : ($field['choices'] ?? array());
			foreach ($choices as &$choice) if (($choice['value'] ?? '') === $c['class_key']) $choice['inventory_limit'] = $c['desired_capacity'];
			unset($choice);
			if (is_object($field)) $field->choices = $choices; else $field['choices'] = $choices;
		}
		unset($field);
		$result = \GFAPI::update_form($form);
		if ($result !== true) throw new \RuntimeException('Form write unconfirmed.');
		$updated = update_post_meta($resource, 'gpi_inventory_limit', (string) $c['desired_capacity']);
		// WordPress returns false on an unchanged value as well as on failure: read back in either case.
		if (function_exists('wp_cache_delete')) wp_cache_delete($resource, 'post_meta');
		$stored = get_post_meta($resource, 'gpi_inventory_limit', true);
		if (! in_array($stored, array($c['desired_capacity'], (string) $c['desired_capacity']), true) || is_wp_error($updated)) throw new \RuntimeException('Resource write unconfirmed.');
		return null;
	}
	/** @return array{0:array,1:int,2:Resource_Plan} */
	private function context(array $c): array {
		if (! $this->operations->is_available()) throw new \RuntimeException('Inventory runtime unavailable.');
		$identity = Resource_Identity::from_class_key($c['class_key']);
		$m = $this->mappings->find($identity); $p = $this->publications->find($c['publication_key']);
		if (! $m || ! $p || ($m['status'] ?? '') !== 'HEALTHY' || ($p['status'] ?? '') !== 'MATERIALIZED' || ($m['publication_key'] ?? '') !== $c['publication_key'] || (int) $m['form_id'] !== $c['expected_form_id'] || (int) $p['form_id'] !== $c['expected_form_id'] || ! $this->operations->resource_exists((int) $m['resource_id'])) throw new \RuntimeException('Existing identity unavailable.');
		if (function_exists('wp_cache_delete')) wp_cache_delete((int) $m['resource_id'], 'post_meta');
		if ((string) get_post_meta((int) $m['resource_id'], 'turmas_bridge_managed', true) !== '1' || get_post_meta((int) $m['resource_id'], 'turmas_bridge_class_key', true) !== $c['class_key']) throw new \RuntimeException('Resource ownership unconfirmed.');
		$form = \GFAPI::get_form($c['expected_form_id']);
		if (! is_array($form) || ! empty($form['is_trash']) || (int) ($form['id'] ?? 0) !== $c['expected_form_id']) throw new \RuntimeException('Form unavailable.');
		$reps = array();
		foreach ((array) ($form['fields'] ?? array()) as $field) {
			$f = is_object($field) ? get_object_vars($field) : $field;
			foreach ((array) ($f['choices'] ?? array()) as $choice) {
				if (($choice['value'] ?? '') !== $c['class_key']) continue;
				if (! preg_match('/\Aturma_cre_(\d{2})\z/', (string) ($f['adminLabel'] ?? ''), $match) || ($f['type'] ?? '') !== 'select') throw new \RuntimeException('Unrecognized representation.');
				$reps[] = new Resource_Representation($match[1], (string) $f['id'], $c['class_key'], $identity);
			}
		}
		return array($form, (int) $m['resource_id'], new Resource_Plan($identity, $c['desired_capacity'], $reps, $c['expected_form_id']));
	}
}
