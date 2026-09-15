<?php

declare(strict_types=1);

namespace TurmasBridge\Materialization;

final class Materialization_Service implements Publication_Materializer {
	private Materialization_Store $store;
	private Gravity_Forms_Gateway $gravity;
	private Template_Config $config;
	public function __construct(?Materialization_Store $store = null, ?Gravity_Forms_Gateway $gravity = null, ?Template_Config $config = null) { $this->store = $store ?? new Materialization_Repository(); $this->gravity = $gravity ?? new WordPress_Gravity_Forms_Gateway(); $this->config = $config ?? new Template_Config(); }
	/** @param array<string, mixed> $payload @return array<string, mixed>|\WP_Error */
	public function materialize(array $payload, string $payload_hash): array|\WP_Error {
		$key = (string) $payload['publication']['publication_key'];
		$existing = $this->store->find($key);
		if ($existing && (string) $existing['status'] === 'MATERIALIZED') return $this->response($key, (int) $existing['form_id'], true);
		if (! $this->gravity->is_available()) return $this->error('turmas_bridge_gravity_forms_unavailable', 'Gravity Forms não está disponível.', 503);
		$template_id = $this->config->id();
		$template = $this->gravity->form($template_id);
		if ($template_id < 1 || ! $this->valid_form($template, true)) return $this->error('turmas_bridge_invalid_template', 'O template configurado não é utilizável.', 422);
		if ($existing && (int) ($existing['form_id'] ?? 0) > 0) return $this->reconcile($key, (int) $existing['form_id']);
		if (! $existing && ! $this->store->reserve($key, $template_id, $payload_hash)) {
			$raced = $this->store->find($key);
			if ($raced && (string) $raced['status'] === 'MATERIALIZED') return $this->response($key, (int) $raced['form_id'], true);
			return $this->error('turmas_bridge_materialization_in_progress', 'A materialização já está em processamento.', 409);
		}
		$title = 'Inscrições ' . (int) $payload['publication']['year'] . ' — ' . (string) $payload['publication']['formation_code'] . ' (em preparação)';
		$form_id = $this->gravity->duplicate_inactive($template_id, $title, $key);
		if (is_wp_error($form_id)) { $this->store->failed($key, null, $form_id->get_error_code()); return $form_id; }
		$form = $this->gravity->form($form_id);
		if (! $this->valid_form($form, false)) { $this->store->failed($key, $form_id, 'turmas_bridge_clone_structure_invalid'); return $this->error('turmas_bridge_clone_structure_invalid', 'O formulário criado requer reconciliação.', 502); }
		$fields = $this->choice_field_ids($form);
		if (! $this->store->materialized($key, $form_id, $fields)) { $this->store->failed($key, $form_id, 'turmas_bridge_materialization_persist_failed'); return $this->error('turmas_bridge_materialization_persist_failed', 'O formulário foi criado e requer reconciliação.', 502); }
		return $this->response($key, $form_id, false);
	}
	/** @param array<string, mixed>|null $form */
	private function valid_form(?array $form, bool $require_active): bool { return is_array($form) && (! $require_active || ! empty($form['is_active'])) && empty($form['is_trash']) && ! empty($form['fields']) && $this->choice_field_ids($form) !== array(); }
	/** @param array<string, mixed> $form @return list<string> */
	private function choice_field_ids(array $form): array { $ids = array(); foreach ((array) $form['fields'] as $field) { $choices = is_array($field) ? ($field['choices'] ?? array()) : ($field->choices ?? array()); $id = is_array($field) ? ($field['id'] ?? null) : ($field->id ?? null); if ($id !== null && is_array($choices) && $choices !== array()) $ids[] = (string) $id; } return $ids; }
	/** @return array<string,mixed>|\WP_Error */
	private function reconcile(string $key, int $form_id): array|\WP_Error { $form = $this->gravity->form($form_id); if (! $this->valid_form($form, false)) return $this->error('turmas_bridge_reconciliation_required', 'A materialização existente requer reconciliação.', 409); if (! $this->store->materialized($key, $form_id, $this->choice_field_ids($form))) return $this->error('turmas_bridge_materialization_persist_failed', 'Não foi possível reconciliar a materialização.', 502); return $this->response($key, $form_id, true); }
	/** @return array<string,mixed> */
	private function response(string $key, int $form_id, bool $replay): array { return array('schema_version' => '1', 'publication_key' => $key, 'status' => 'materialized', 'form_id' => $form_id, 'idempotent_replay' => $replay); }
	private function error(string $code, string $message, int $status): \WP_Error { return new \WP_Error($code, $message, array('status' => $status)); }
}
