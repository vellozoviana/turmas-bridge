<?php

declare(strict_types=1);

namespace TurmasBridge\Materialization;

use TurmasBridge\Choices\Resource_Plan_Builder;
use TurmasBridge\Choices\Template_Field_Map;
use TurmasBridge\Inventory\WordPress_GP_Inventory_Operations;

/** Pure template/command checks plus a read-only inventory runtime probe. */
final class Template_Preflight {
	/** @var callable():?string */
	private $inventory_readiness;

	/** @param callable():?string|null $inventory_readiness Returns an error code, or null when ready. */
	public function __construct(?callable $inventory_readiness = null, private ?Template_Field_Map $fields = null, private ?Resource_Plan_Builder $plans = null) {
		$this->inventory_readiness = $inventory_readiness ?? static function (): ?string {
			$operations = new WordPress_GP_Inventory_Operations();
			if ($operations->availability_error_code() !== null) return 'turmas_bridge_gp_inventory_version_unsupported';
			return $operations->is_available() ? null : 'turmas_bridge_gp_inventory_unavailable';
		};
		$this->fields = $this->fields ?? new Template_Field_Map();
		$this->plans = $this->plans ?? new Resource_Plan_Builder();
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $template */
	public function validate(array $payload, array $template): ?\WP_Error {
		$map = $this->fields->resolve($template);
		if (is_wp_error($map)) return $this->pre_effect($map);
		$required = array();
		foreach ((array) ($payload['classes'] ?? array()) as $class) {
			foreach ((array) ($class['cres'] ?? array()) as $cre) {
				$code = (string) $cre;
				if (preg_match('/^\d{2}$/', $code) && ! isset($map[$code])) $required[$code] = true;
			}
		}
		if ($required !== array()) {
			$missing = array_map('strval', array_keys($required));
			sort($missing, SORT_STRING);
			return new \WP_Error('turmas_bridge_cre_field_missing', 'O template não possui fields para as CRES ' . implode(', ', $missing) . '.', array('status' => 422, 'pre_effect' => true, 'missing_cres' => $missing));
		}
		$plans = $this->plans->build_models((array) ($payload['classes'] ?? array()), $map);
		if (is_wp_error($plans)) return $this->pre_effect($plans);
		try { $readiness = call_user_func($this->inventory_readiness); }
		catch (\Throwable) { $readiness = 'turmas_bridge_gp_inventory_unavailable'; }
		if ($readiness !== null) return new \WP_Error($readiness, 'O runtime de inventário requerido não está disponível para materialização.', array('status' => 503, 'pre_effect' => true));
		return null;
	}

	private function pre_effect(\WP_Error $error): \WP_Error {
		$data = $error->get_error_data();
		return new \WP_Error($error->get_error_code(), $error->get_error_message(), array_merge((array) $data, array('pre_effect' => true)));
	}
}
