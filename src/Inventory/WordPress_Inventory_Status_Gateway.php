<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

use TurmasBridge\Choices\Template_Field_Map;

/**
 * Read-only adapter for the GP Inventory representation used by the Bridge.
 * It deliberately reuses the existing inspect() path, which flushes the
 * vendor count cache before reading consumption and never writes inventory.
 */
final class WordPress_Inventory_Status_Gateway implements Inventory_Status_Gateway {
	private Inventory_Mapping_Store $mappings;
	private GP_Inventory_Operations $operations;
	private Template_Field_Map $field_map;

	public function __construct(?Inventory_Mapping_Store $mappings = null, ?GP_Inventory_Operations $operations = null, ?Template_Field_Map $field_map = null) {
		$this->mappings = $mappings ?? new Inventory_Mapping_Repository();
		$this->operations = $operations ?? new WordPress_GP_Inventory_Operations();
		$this->field_map = $field_map ?? new Template_Field_Map();
	}

	/** @return array{status:string,resources:list<array<string,mixed>>,blockers:list<array{code:string,message:string}>} */
	public function read(string $publication_key, int $form_id, array $form): array {
		return $this->read_context($publication_key, $form_id, $form, false);
	}

	/** @return array{status:string,resources:list<array<string,mixed>>,blockers:list<array{code:string,message:string}>} */
	public function read_post_activation(string $publication_key, int $form_id, array $form): array {
		return $this->read_context($publication_key, $form_id, $form, true);
	}

	/** @return array{status:string,resources:list<array<string,mixed>>,blockers:list<array{code:string,message:string}>} */
	private function read_context(string $publication_key, int $form_id, array $form, bool $post_activation): array {
		$rows = $this->mappings->list_for_publication($publication_key);
		$resources = array();
		$blockers = array();
		$seen_classes = array();
		$seen_resources = array();
		foreach ($rows as $mapping) {
			$class_key = (string) ($mapping['class_key'] ?? '');
			$resource_id = (int) ($mapping['resource_id'] ?? 0);
			if ((int) ($mapping['form_id'] ?? 0) !== $form_id) {
				$blockers[] = array('code' => 'BINDING_INVALID', 'message' => 'O mapeamento do Resource pertence a outro formulário.');
			}
			if (isset($seen_classes[$class_key]) || ($resource_id > 0 && isset($seen_resources[$resource_id]))) {
				$blockers[] = array('code' => 'RESOURCE_AMBIGUOUS', 'message' => 'Há mais de um mapeamento para a mesma identidade de Turma.');
			}
			$seen_classes[$class_key] = true;
			if ($resource_id > 0) $seen_resources[$resource_id] = true;
		}
		$seen_classes = array();
		$seen_resources = array();
		$resolved = $this->field_map->resolve($form);
		if (is_wp_error($resolved)) {
			return array('status' => 'BLOCKED', 'resources' => array(), 'blockers' => array(array('code' => 'BINDING_INVALID', 'message' => $resolved->get_error_message())));
		}

		foreach ($rows as $mapping) {
			$class_key = (string) ($mapping['class_key'] ?? '');
			$resource_id = (int) ($mapping['resource_id'] ?? 0);
			if (isset($seen_classes[$class_key]) || ($resource_id > 0 && isset($seen_resources[$resource_id]))) {
				$blockers[] = array('code' => 'RESOURCE_AMBIGUOUS', 'message' => 'Há mais de um mapeamento para a mesma identidade de Turma.');
				continue;
			}
			$seen_classes[$class_key] = true;
			if ($resource_id > 0) $seen_resources[$resource_id] = true;
			if ($class_key === '' || $resource_id < 1 || (string) ($mapping['status'] ?? '') !== 'HEALTHY') {
				$resources[] = array('class_key' => $class_key, 'resource_id' => $resource_id > 0 ? $resource_id : null, 'healthy' => false, 'reason' => 'RESOURCE_NOT_FOUND');
				$blockers[] = array('code' => 'RESOURCE_NOT_FOUND', 'message' => 'O Resource mapeado não está disponível ou saudável.');
				continue;
			}
			try {
				$identity = Resource_Identity::from_class_key($class_key);
				$representations = $this->representations($form, $resolved, $identity);
				if ($representations === array()) throw new \UnexpectedValueException('As representações da Turma não foram encontradas no formulário.');
				$capacity = $this->capacity($form, $resolved, $identity);
				$plan = new Resource_Plan($identity, $capacity, $representations, $form_id);
				$state = $post_activation ? $this->operations->inspect_post_activation($plan, $resource_id) : $this->operations->inspect($plan, $resource_id);
				$resources[] = array('class_key' => $class_key, 'resource_id' => $resource_id, 'capacity' => $state['capacity'], 'consumed' => $state['consumed'], 'healthy' => $state['healthy'], 'reason' => $state['reason']);
				if (! $state['healthy']) $blockers[] = $this->blocker((string) ($state['reason'] ?? 'RESOURCE_NOT_FOUND'));
			} catch (\UnexpectedValueException|\InvalidArgumentException|Inventory_Integration_Exception $error) {
				$resources[] = array('class_key' => $class_key, 'resource_id' => $resource_id, 'healthy' => false, 'reason' => 'BINDING_INVALID');
				$blockers[] = array('code' => 'BINDING_INVALID', 'message' => $error->getMessage());
			}
		}

		return array('status' => $blockers === array() ? 'READY' : 'BLOCKED', 'resources' => $resources, 'blockers' => $blockers);
	}

	/** @param array<string,mixed> $form @param array<string,array{id:string,index:int}> $resolved @return list<Resource_Representation> */
	private function representations(array $form, array $resolved, Resource_Identity $identity): array {
		$representations = array();
		foreach ($resolved as $cre => $field) {
			$cre = (string) $cre;
			$data = $this->field_data($form['fields'][$field['index']] ?? null);
			$choices = (array) ($data['choices'] ?? array());
			foreach ($choices as $choice) {
				if ((string) ($choice['value'] ?? '') !== $identity->class_key()) continue;
				$representations[] = new Resource_Representation($cre, (string) $field['id'], $identity->class_key(), $identity, (string) ($choice['text'] ?? ''));
				break;
			}
		}
		return $representations;
	}

	/** @param array<string,mixed> $form @param array<string,array{id:string,index:int}> $resolved */
	private function capacity(array $form, array $resolved, Resource_Identity $identity): int {
		$limits = array();
		foreach ($resolved as $field) {
			$data = $this->field_data($form['fields'][$field['index']] ?? null);
			foreach ((array) ($data['choices'] ?? array()) as $choice) {
				if ((string) ($choice['value'] ?? '') === $identity->class_key() && is_numeric($choice['inventory_limit'] ?? null)) {
					$limits[] = (int) $choice['inventory_limit'];
				}
			}
		}
		$limits = array_values(array_filter($limits, static fn (int $limit): bool => $limit > 0));
		if ($limits === array()) throw new \UnexpectedValueException('A capacidade do Resource não está definida.');
		if (count(array_unique($limits)) !== 1) throw new \UnexpectedValueException('As representações possuem capacidades divergentes.');
		return $limits[0];
	}
	/** @return array<string,mixed> */
	private function field_data(mixed $field): array {
		if (is_array($field)) return $field;
		return is_object($field) ? get_object_vars($field) : array();
	}

	/** @return array{code:string,message:string} */
	private function blocker(string $reason): array {
		$map = array(
			'resource_missing' => array('RESOURCE_NOT_FOUND', 'O Resource não existe.'),
			'form_active' => array('FORM_ALREADY_ACTIVE', 'O formulário está ativo.'),
			'representation_missing' => array('BINDING_INVALID', 'Falta uma representação esperada.'),
			'choice_value_drift' => array('BINDING_INVALID', 'O valor de choice diverge da identidade esperada.'),
			'binding_missing' => array('BINDING_INVALID', 'Falta um binding gpi_field.'),
			'binding_drift' => array('BINDING_INVALID', 'Existe binding gpi_field inesperado.'),
			'capacity_missing' => array('CAPACITY_MISMATCH', 'A capacidade não está definida.'),
			'capacity_drift' => array('CAPACITY_MISMATCH', 'As representações possuem capacidades divergentes.'),
			'capacity_below_consumed' => array('CONSUMPTION_EXCEEDS_CAPACITY', 'O consumo excede a capacidade.'),
		);
		$item = $map[$reason] ?? array('RESOURCE_NOT_FOUND', 'O Resource não está saudável.');
		return array('code' => $item[0], 'message' => $item[1]);
	}
}
