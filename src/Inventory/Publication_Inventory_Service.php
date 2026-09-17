<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

use TurmasBridge\Choices\Resource_Plan_Builder;
use TurmasBridge\Choices\Template_Field_Map;
use TurmasBridge\Materialization\Gravity_Forms_Gateway;
use TurmasBridge\Materialization\Materialization_Repository;
use TurmasBridge\Materialization\Materialization_Store;
use TurmasBridge\Materialization\WordPress_Gravity_Forms_Gateway;

/** Orchestrates Resources only after choices and an inactive materialized form exist. */
final class Publication_Inventory_Service implements Publication_Inventory_Preparer {
	private Materialization_Store $materializations;
	private Gravity_Forms_Gateway $gravity;
	private Template_Field_Map $fields;
	private Resource_Plan_Builder $plans;
	private Inventory_Gateway $inventory;

	public function __construct(?Materialization_Store $materializations = null, ?Gravity_Forms_Gateway $gravity = null, ?Inventory_Gateway $inventory = null, ?Template_Field_Map $fields = null, ?Resource_Plan_Builder $plans = null) {
		$this->materializations = $materializations ?? new Materialization_Repository();
		$this->gravity = $gravity ?? new WordPress_Gravity_Forms_Gateway();
		$this->inventory = $inventory ?? new GP_Inventory_Adapter();
		$this->fields = $fields ?? new Template_Field_Map();
		$this->plans = $plans ?? new Resource_Plan_Builder();
	}
	public function prepare(array $payload): array|\WP_Error {
		$key = (string) ($payload['publication']['publication_key'] ?? '');
		$record = $this->materializations->find($key);
		if (! $record || (string) ($record['status'] ?? '') !== 'MATERIALIZED' || (int) ($record['form_id'] ?? 0) < 1) return $this->error('turmas_bridge_materialization_required', 'A Publicação ainda não possui formulário materializado.', 409);
		$form_id = (int) $record['form_id'];
		$form = $this->gravity->form($form_id);
		if (! is_array($form) || ! empty($form['is_active'])) return $this->error('turmas_bridge_form_not_inactive', 'O formulário deve permanecer inativo durante a preparação de inventário.', 409);
		$map = $this->fields->resolve($form);
		if (is_wp_error($map)) return $map;
		$plans = $this->plans->build_models((array) ($payload['classes'] ?? array()), $map);
		if (is_wp_error($plans)) return $plans;
		$resources = array();
		try {
			foreach ($plans as $plan) {
				$resource = $this->inventory->ensure($plan->for_form($form_id));
				$resources[] = array('class_key' => $resource->identity()->class_key(), 'resource_id' => $resource->resource_id(), 'capacity' => $resource->capacity(), 'consumed' => $resource->consumed(), 'remaining' => $resource->remaining());
			}
		} catch (Inventory_Integration_Exception $error) { return $this->error($error->error_code(), $error->getMessage(), $this->status($error->error_code())); }
		return array('publication_key' => $key, 'status' => 'inventory_prepared', 'resources' => $resources);
	}
	private function status(string $code): int { return in_array($code, array('turmas_bridge_inventory_locked', 'turmas_bridge_inventory_reconciliation_required', 'turmas_bridge_capacity_below_consumed'), true) ? 409 : 503; }
	private function error(string $code, string $message, int $status): \WP_Error { return new \WP_Error($code, $message, array('status' => $status)); }
}
