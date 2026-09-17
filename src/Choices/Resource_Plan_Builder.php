<?php

declare(strict_types=1);

namespace TurmasBridge\Choices;

use TurmasBridge\Inventory\Resource_Identity;
use TurmasBridge\Inventory\Resource_Plan;
use TurmasBridge\Inventory\Resource_Representation;

final class Resource_Plan_Builder {
	/** @param list<array<string, mixed>> $classes @param array<string,array{id:string,index:int}> $field_map @return list<array<string,mixed>>|\WP_Error */
	public function build(array $classes, array $field_map): array|\WP_Error {
		$models = $this->build_models($classes, $field_map);
		if (is_wp_error($models)) return $models;
		return array_map(static fn (Resource_Plan $plan): array => $plan->to_array(), $models);
	}
	/** @param list<array<string, mixed>> $classes @param array<string,array{id:string,index:int}> $field_map @return list<Resource_Plan>|\WP_Error */
	public function build_models(array $classes, array $field_map): array|\WP_Error {
		$plans = array();
		foreach ($classes as $class) {
			$key = (string) ($class['class_key'] ?? ''); $capacity = (int) ($class['capacity'] ?? 0);
			if ($key === '' || $capacity < 1 || isset($plans[$key])) return $this->error('turmas_bridge_invalid_resource_plan', 'O plano de Resource da Turma é inválido.');
			try { $identity = Resource_Identity::from_class_key($key); } catch (\InvalidArgumentException) { return $this->error('turmas_bridge_invalid_resource_plan', 'O plano de Resource da Turma é inválido.'); }
			$representations = array(); $seen = array();
			foreach ((array) ($class['cres'] ?? array()) as $cre) {
				$cre = (string) $cre;
				if (! isset($field_map[$cre])) return $this->error('turmas_bridge_cre_field_missing', 'Não existe field configurado para uma CRE necessária.');
				if (isset($seen[$cre])) return $this->error('turmas_bridge_duplicate_cres', 'Uma Turma possui CRE repetida.');
				$seen[$cre] = true;
				try { $representations[] = new Resource_Representation($cre, (string) $field_map[$cre]['id'], $key, $identity, trim((string) ($class['short_name'] ?? ''))); } catch (\InvalidArgumentException) { return $this->error('turmas_bridge_invalid_resource_plan', 'O plano de Resource da Turma é inválido.'); }
			}
			try { $plans[$key] = new Resource_Plan($identity, $capacity, $representations); } catch (\InvalidArgumentException) { return $this->error('turmas_bridge_invalid_resource_plan', 'O plano de Resource da Turma é inválido.'); }
		}
		ksort($plans, SORT_STRING);
		return array_values($plans);
	}
	private function error(string $code, string $message): \WP_Error { return new \WP_Error($code, $message, array('status' => 422)); }
}
