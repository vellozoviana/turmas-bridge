<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

final class WordPress_Form_Activation_Gateway implements Form_Activation_Gateway {
	public function is_available(): bool { return class_exists('GFAPI') && method_exists('GFAPI', 'get_form') && method_exists('GFAPI', 'update_form_property'); }
	/** @return array<string,mixed>|null */
	public function read(int $form_id): ?array {
		if (! $this->is_available() || $form_id < 1) return null;
		try { $form = \GFAPI::get_form($form_id); } catch (\Throwable) { return null; }
		return is_array($form) ? $form : (is_object($form) ? get_object_vars($form) : null);
	}
	/** @return Form_Activation_Outcome|\WP_Error */
	public function activate(int $form_id): Form_Activation_Outcome|\WP_Error {
		if (! $this->is_available()) return new \WP_Error('turmas_bridge_gravity_forms_unavailable', 'Gravity Forms não está disponível.', array('status' => 503, 'mutation_attempted' => false));
		$form = $this->read($form_id);
		if (! $form) return new \WP_Error('turmas_bridge_form_not_found', 'O formulário esperado não existe.', array('status' => 409, 'mutation_attempted' => false));
		if (! empty($form['is_active'])) return new Form_Activation_Outcome('ACTIVE', false, 'ACTIVE', true, 'FORM_ALREADY_ACTIVE');
		try { $result = \GFAPI::update_form_property($form_id, 'is_active', 1); } catch (\Throwable) { return new \WP_Error('turmas_bridge_form_activation_unknown', 'A resposta da ativação do formulário foi perdida.', array('status' => 502, 'mutation_attempted' => true)); }
		if ($result === false || is_wp_error($result) || (int) $result !== 1) return new \WP_Error('turmas_bridge_form_activation_unknown', 'Não foi possível confirmar o resultado da ativação do formulário.', array('status' => 502, 'mutation_attempted' => true));
		$after = $this->read($form_id);
		if (! $after) return new \WP_Error('turmas_bridge_form_activation_unknown', 'O formulário desapareceu após a ativação.', array('status' => 502, 'mutation_attempted' => true));
		$active = ! empty($after['is_active']);
		return new Form_Activation_Outcome('INACTIVE', true, $active ? 'ACTIVE' : 'INACTIVE', true, $active ? null : 'FORM_ACTIVATION_NOT_CONFIRMED');
	}
}
