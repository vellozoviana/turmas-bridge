<?php

declare(strict_types=1);

namespace TurmasBridge\Materialization;

final class WordPress_Gravity_Forms_Gateway implements Gravity_Forms_Gateway {
	public function is_available(): bool { return class_exists('GFAPI') && method_exists('GFAPI', 'get_form') && method_exists('GFAPI', 'duplicate_form') && method_exists('GFAPI', 'update_form'); }
	/** @return array<string, mixed>|null */
	public function form(int $form_id): ?array {
		if (! $this->is_available()) return null;
		$form = \GFAPI::get_form($form_id);
		return is_array($form) ? $form : null;
	}
	/** @return int|\WP_Error */
	public function duplicate_inactive(int $template_id, string $title, string $marker): int|\WP_Error {
		if (! $this->is_available()) return new \WP_Error('turmas_bridge_gravity_forms_unavailable', 'Gravity Forms não está disponível.', array('status' => 503));
		$new_id = \GFAPI::duplicate_form($template_id);
		if (! is_numeric($new_id) || (int) $new_id < 1) return new \WP_Error('turmas_bridge_form_clone_failed', 'Não foi possível criar o formulário.', array('status' => 502));
		$form = $this->form((int) $new_id);
		if (! $form) return new \WP_Error('turmas_bridge_form_clone_failed', 'Não foi possível ler o formulário criado.', array('status' => 502));
		$form['title'] = $title;
		$form['description'] = trim((string) ($form['description'] ?? '') . "\n[Turmas Bridge: {$marker}]");
		$form['is_active'] = false;
		$result = \GFAPI::update_form($form);
		if ($result === false || is_wp_error($result)) return new \WP_Error('turmas_bridge_form_update_failed', 'Não foi possível proteger o formulário criado.', array('status' => 502));
		return (int) $new_id;
	}
}
