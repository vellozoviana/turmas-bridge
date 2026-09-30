<?php

declare(strict_types=1);

namespace TurmasBridge\Choices;

/** Runs before GP Inventory's pre-validation (priority 11) and validation (9/10). */
final class Cre_Submission_Guard {
	/** @var array<int,bool> */
	private static array $invalid = array();

	public static function register(): void {
		add_filter('gform_pre_validation', array(self::class, 'pre_validation'), 0);
		add_filter('gform_validation', array(self::class, 'validation'), 0);
	}

	/** @param array<string,mixed> $form @return array<string,mixed> */
	public static function pre_validation(array $form): array {
		if (empty($form['turmasBridgeCreExclusive'])) {
			unset(self::$invalid[(int) ($form['id'] ?? 0)]);
			return $form;
		}
		$result = self::inspect($form, $_POST);
		$_POST = $result['post'];
		self::$invalid[(int) ($form['id'] ?? 0)] = ! $result['valid'];
		return $form;
	}

	/** @param array<string,mixed> $result @return array<string,mixed> */
	public static function validation(array $result): array {
		$form = $result['form'] ?? array();
		if (! is_array($form) || empty(self::$invalid[(int) ($form['id'] ?? 0)])) return $result;
		$result['is_valid'] = false;
		$controller = (new Cre_Selection_Contract())->controller($form);
		if ($controller !== null) {
			$field = $form['fields'][$controller['index']];
			$field->failed_validation = true;
			$field->validation_message = 'Selecione uma única CRE e uma Turma correspondente.';
			$result['form'] = $form;
		}
		return $result;
	}

	/** @param array<string,mixed> $form @param array<string,mixed> $post @return array{valid:bool,post:array<string,mixed>} */
	public static function inspect(array $form, array $post): array {
		$map = (new Template_Field_Map())->resolve($form);
		if (is_wp_error($map)) {
			foreach ((array) ($form['fields'] ?? array()) as $item) {
				if (! is_array($item) && ! is_object($item)) continue;
				$data = is_array($item) ? $item : get_object_vars($item);
				if (preg_match('/^turma_cre_\d{2}$/', (string) ($data['adminLabel'] ?? ''))) unset($post['input_' . (string) ($data['id'] ?? '')]);
			}
			return array('valid' => false, 'post' => $post);
		}
		$bound = array();
		foreach ($map as $cre => $field) {
			$cre = (string) $cre;
			$item = $form['fields'][$field['index']];
			$data = is_array($item) ? $item : get_object_vars($item);
			if (! empty($data['gpiResource']) && ! empty($data['choices'])) $bound[] = $cre;
		}
		$contract = new Cre_Selection_Contract();
		$controller = $contract->controller($form);
		$valid = $controller !== null && $bound !== array() && $contract->validate($form, $bound, $map) === null;
		$selected = $controller === null ? '' : $post['input_' . $controller['id']] ?? '';
		$valid = $valid && is_string($selected) && in_array($selected, $bound, true);
		foreach ($map as $cre => $field) {
			$cre = (string) $cre;
			$name = 'input_' . $field['id'];
			$value = $post[$name] ?? '';
			if ($valid && $cre === $selected) {
				$item = $form['fields'][$field['index']];
				$data = is_array($item) ? $item : get_object_vars($item);
				$allowed = array_map(static fn ($choice): string => is_array($choice) ? (string) ($choice['value'] ?? '') : (string) ($choice->value ?? ''), (array) ($data['choices'] ?? array()));
				if (! is_string($value) || ! in_array($value, $allowed, true)) $valid = false;
			} elseif ($value !== '' && $value !== null) {
				$valid = false;
			}
			if ($cre !== $selected) unset($post[$name]);
		}
		if (! $valid) foreach ($map as $field) unset($post['input_' . $field['id']]);
		return array('valid' => $valid, 'post' => $post);
	}
}
