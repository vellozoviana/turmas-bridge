<?php

declare(strict_types=1);

namespace TurmasBridge\Choices;

final class Template_Field_Map {
	/** @param array<string, mixed> $form @return array<string, array{id:string,index:int}>|\WP_Error */
	public function resolve(array $form): array|\WP_Error {
		$map = array();
		foreach ((array) ($form['fields'] ?? array()) as $index => $field) {
			$data = is_array($field) ? $field : get_object_vars($field);
			$admin_label = (string) ($data['adminLabel'] ?? $data['admin_label'] ?? '');
			if (! preg_match('/^turma_cre_(\d{2})$/', $admin_label, $match)) continue;
			if (! $this->choice_capable($data)) return $this->error('turmas_bridge_cre_field_incompatible', 'Um field de Turma por CRE não aceita choices.');
			if (isset($map[$match[1]])) return $this->error('turmas_bridge_cre_field_ambiguous', 'Dois fields reivindicam a mesma CRE.');
			$map[$match[1]] = array('id' => (string) ($data['id'] ?? ''), 'index' => (int) $index);
		}
		return $map;
	}
	/** @param array<string, mixed> $field */
	private function choice_capable(array $field): bool { return in_array((string) ($field['type'] ?? ''), array('select', 'radio', 'checkbox'), true) && (string) ($field['id'] ?? '') !== ''; }
	private function error(string $code, string $message): \WP_Error { return new \WP_Error($code, $message, array('status' => 422)); }
}
