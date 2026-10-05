<?php

declare(strict_types=1);

namespace TurmasBridge\Choices;

/** The CRE selector is a routing field, never an inventory field. */
final class Cre_Selection_Contract {
	public const CONTROLLER_LABEL = 'turmas_cre_selector';
	public const ERROR_CODE = 'turmas_bridge_cre_selection_invalid';

	/** @param list<array<string,mixed>> $classes @return list<string> */
	public function required_cres(array $classes): array {
		$cres = array();
		foreach ($classes as $class) foreach ((array) ($class['cres'] ?? array()) as $cre) $cres[(string) $cre] = true;
		$result = array_map('strval', array_keys($cres));
		sort($result, SORT_STRING);
		return $result;
	}

	/** @param array<string,mixed> $form @param list<string> $required @param array<string,array{id:string,index:int}> $map */
	public function validate(array $form, array $required, array $map): ?\WP_Error {
		if (count($required) < 2) return null;
		$controller = $this->controller($form);
		if ($controller === null) return $this->error('O template precisa de um único controlador de CRE obrigatório.');
		$data = $controller['data'];
		$id = $controller['id'];
		if (($data['type'] ?? '') !== 'select' || empty($data['isRequired']) || trim((string) ($data['placeholder'] ?? '')) === '' || ! empty($data['gpiInventory']) || ! empty($data['gpiResource']) || ! empty($data['adminOnly']) || ! in_array((string) ($data['visibility'] ?? 'visible'), array('', 'visible'), true) || ! empty($data['defaultValue'])) {
			return $this->error('O controlador de CRE deve ser um select obrigatório, com placeholder e sem inventário.');
		}
		$available = array();
		foreach ((array) ($data['choices'] ?? array()) as $choice) {
			$value = is_object($choice) ? (string) ($choice->value ?? '') : (string) ($choice['value'] ?? '');
			$selected = is_object($choice) ? ! empty($choice->isSelected) : ! empty($choice['isSelected']);
			if ($selected || ! preg_match('/^\d{2}$/', $value) || isset($available[$value])) return $this->error('As opções do controlador de CRE são inválidas.');
			$available[$value] = true;
		}
		foreach ($required as $cre) if (! isset($available[$cre])) return $this->error('O controlador não oferece todas as CREs da Publicação.');
		foreach ($map as $cre => $field) {
			$cre = (string) $cre;
			if ($field['id'] === $id) return $this->error('O controlador e um campo de Turma compartilham o mesmo ID.');
			$item = $form['fields'][$field['index']];
			$metadata = is_array($item) ? $item : get_object_vars($item);
			$logic = $metadata['conditionalLogic'] ?? null;
			$rules = is_array($logic) ? ($logic['rules'] ?? null) : null;
			if ((string) ($metadata['type'] ?? '') !== 'select' || empty($metadata['isRequired']) || ! empty($metadata['adminOnly']) || ! in_array((string) ($metadata['visibility'] ?? 'visible'), array('', 'visible'), true) || ! is_array($rules) || count($rules) !== 1 || ($logic['actionType'] ?? '') !== 'show' || ($logic['logicType'] ?? '') !== 'all') return $this->error('Um campo de Turma não está condicionado à sua CRE.');
			$rule = $rules[0];
			if (! is_array($rule) || (string) ($rule['fieldId'] ?? '') !== $id || ($rule['operator'] ?? '') !== 'is' || ($rule['value'] ?? '') !== $cre) return $this->error('A condição de um campo de Turma não corresponde à sua CRE.');
		}
		return null;
	}

	/** @param array<string,mixed> $form @return array{id:string,index:int,data:array<string,mixed>}|null */
	public function controller(array $form): ?array {
		$found = null;
		foreach ((array) ($form['fields'] ?? array()) as $index => $field) {
			if (! is_array($field) && ! is_object($field)) continue;
			$data = is_array($field) ? $field : get_object_vars($field);
			if (($data['adminLabel'] ?? '') !== self::CONTROLLER_LABEL) continue;
			if ($found !== null || ! ctype_digit((string) ($data['id'] ?? '')) || (int) $data['id'] < 1) return null;
			$found = array('id' => (string) $data['id'], 'index' => (int) $index, 'data' => $data);
		}
		return $found;
	}

	/** @param array<string,mixed> $form @param array<string,array{id:string,index:int}> $map */
	public function signature(array $form, array $map): string {
		$controller = $this->controller($form);
		if ($controller === null) return '';
		$relevant = array('controller' => $this->relevant($controller['data']), 'fields' => array());
		foreach ($map as $cre => $field) {
			$item = $form['fields'][$field['index']];
			$relevant['fields'][(string) $cre] = $this->relevant(is_array($item) ? $item : get_object_vars($item));
		}
		ksort($relevant['fields'], SORT_STRING);
		return hash('sha256', (string) wp_json_encode($relevant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private function relevant(array $data): array {
		$result = array();
		foreach (array('id', 'type', 'adminLabel', 'isRequired', 'visibility', 'adminOnly', 'placeholder', 'defaultValue', 'conditionalLogic', 'choices') as $key) $result[$key] = $data[$key] ?? null;
		return $result;
	}

	private function error(string $message): \WP_Error { return new \WP_Error(self::ERROR_CODE, $message, array('status' => 422)); }
}
