<?php

declare(strict_types=1);

namespace TurmasBridge\Choices;

use TurmasBridge\Materialization\Gravity_Forms_Gateway;
use TurmasBridge\Materialization\Materialization_Store;

final class Publication_Choice_Service implements Publication_Choice_Preparer {
	private Materialization_Store $store; private Gravity_Forms_Gateway $gravity; private Template_Field_Map $fields; private Resource_Plan_Builder $plans;
	public function __construct(Materialization_Store $store, Gravity_Forms_Gateway $gravity, ?Template_Field_Map $fields = null, ?Resource_Plan_Builder $plans = null) { $this->store = $store; $this->gravity = $gravity; $this->fields = $fields ?? new Template_Field_Map(); $this->plans = $plans ?? new Resource_Plan_Builder(); }
	/** @param array<string,mixed> $payload @return array<string,mixed>|\WP_Error */
	public function prepare(array $payload): array|\WP_Error {
		$key = (string) ($payload['publication']['publication_key'] ?? '');
		if ($key === '' || ! $this->store->acquire_choice_lock($key)) return $this->error('turmas_bridge_choice_update_locked', 'A preparação de choices já está em processamento.', 409);
		try {
			$record = $this->store->find($key); if (! $record || (string) ($record['status'] ?? '') !== 'MATERIALIZED' || (int) ($record['form_id'] ?? 0) < 1) return $this->error('turmas_bridge_materialization_required', 'A Publicação ainda não possui formulário materializado.', 409);
			$form = $this->gravity->form((int) $record['form_id']); if (! is_array($form) || ! empty($form['is_active'])) return $this->error('turmas_bridge_form_not_inactive', 'O formulário deve permanecer inativo durante a preparação.', 409);
			$map = $this->fields->resolve($form); if (is_wp_error($map)) return $map;
			$classes = (array) ($payload['classes'] ?? array()); $desired = $this->desired($classes, $map); if (is_wp_error($desired)) return $desired;
			$plans = $this->plans->build($classes, $map); if (is_wp_error($plans)) return $plans;
			$fingerprint = hash('sha256', (string) wp_json_encode($desired, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			if ((string) ($record['choices_fingerprint'] ?? '') === $fingerprint) return array('publication_key' => $key, 'status' => 'choices_prepared', 'idempotent_replay' => true, 'resource_plans' => $plans);
			foreach ($map as $cre => $field) { $form['fields'][$field['index']]['choices'] = $desired[$cre] ?? array(); }
			$result = $this->gravity->update_form($form); if (is_wp_error($result) || $result !== true) return $this->error('turmas_bridge_choice_update_failed', 'Não foi possível atualizar as choices do formulário.', 502);
			if (! $this->store->choice_fingerprint($key, $fingerprint)) return $this->error('turmas_bridge_choice_fingerprint_failed', 'As choices foram atualizadas e requerem reconciliação.', 502);
			return array('publication_key' => $key, 'status' => 'choices_prepared', 'idempotent_replay' => false, 'resource_plans' => $plans);
		} finally { $this->store->release_choice_lock($key); }
	}
	/** @param list<array<string,mixed>> $classes @param array<string,array{id:string,index:int}> $map @return array<string,list<array{text:string,value:string}>>|\WP_Error */
	private function desired(array $classes, array $map): array|\WP_Error {
		$choices = array(); foreach ($map as $cre => $_) $choices[$cre] = array(); $seen = array();
		foreach ($classes as $class) { $key = (string) ($class['class_key'] ?? ''); $code = (string) ($class['class_code'] ?? ''); $text = trim((string) ($class['short_name'] ?? ''));
			if ($key === '' || $code === '' || $text === '' || isset($seen[$key])) return $this->error('turmas_bridge_invalid_choice_class', 'Uma Turma não possui identidade ou apresentação válida.', 422); $seen[$key] = true;
			$cre_seen = array(); foreach ((array) ($class['cres'] ?? array()) as $cre) { $cre = (string) $cre; if (! preg_match('/^\d{2}$/', $cre) || isset($cre_seen[$cre])) return $this->error('turmas_bridge_invalid_choice_cres', 'As CRES de uma Turma são inválidas.', 422); if (! isset($map[$cre])) return $this->error('turmas_bridge_cre_field_missing', 'Não existe field configurado para uma CRE necessária.', 422); $cre_seen[$cre] = true; $choices[$cre][] = array('text' => $text, 'value' => $key, '_code' => $code); }
		}
		foreach ($choices as $cre => $items) { usort($items, static fn (array $a, array $b): int => strnatcmp($a['_code'], $b['_code'])); $choices[$cre] = array_map(static fn (array $item): array => array('text' => $item['text'], 'value' => $item['value']), $items); }
		ksort($choices, SORT_STRING);
		return $choices;
	}
	private function error(string $code, string $message, int $status): \WP_Error { return new \WP_Error($code, $message, array('status' => $status)); }
}
