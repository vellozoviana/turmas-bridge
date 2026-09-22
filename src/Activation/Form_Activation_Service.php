<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

use TurmasBridge\Materialization\Materialization_Repository;
use TurmasBridge\Materialization\Materialization_Store;
use TurmasBridge\Publications\Publication_Status_Provider;
use TurmasBridge\Publications\Publication_Status_Reader;

/** B2 activation primitive; B3B1 is the only command boundary that may compose it. */
final class Form_Activation_Service implements Activation_Command {
	private Publication_Status_Provider $status;
	private Form_Activation_Gateway $gateway;
	private Materialization_Store $lock;

	public function __construct(?Publication_Status_Provider $status = null, ?Form_Activation_Gateway $gateway = null, ?Materialization_Store $lock = null) {
		$this->status = $status ?? new Publication_Status_Reader();
		$this->gateway = $gateway ?? new WordPress_Form_Activation_Gateway();
		$this->lock = $lock ?? new Materialization_Repository();
	}

	/** @return Activation_Result */
	public function activate(string $publication_key, int $expected_form_id, string $operation_key, string $snapshot_fingerprint): Activation_Result {
		if (! preg_match('/^\d{4}:[A-Z0-9_-]{1,50}$/', $publication_key) || $expected_form_id < 1 || ! preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $operation_key) || ! preg_match('/^[a-f0-9]{64}$/i', $snapshot_fingerprint)) return $this->result(Activation_Result::BLOCKED, 'INVALID_ACTIVATION_IDENTITY', 'A identidade técnica da ativação é inválida.');
		if (! $this->gateway->is_available()) return $this->result(Activation_Result::BLOCKED, 'GRAVITY_FORMS_UNAVAILABLE', 'Gravity Forms não está disponível.');
		$initial = $this->status->read($publication_key);
		$precondition = $this->preconditions($initial, $publication_key, $expected_form_id);
		if ($precondition !== null) return $precondition;
		if (! $this->lock->acquire_choice_lock($publication_key)) return $this->result(Activation_Result::BLOCKED, 'LOCK_UNAVAILABLE', 'Não foi possível obter o lock da Publicação.');
		try {
			$fresh = $this->status->read($publication_key);
			$precondition = $this->preconditions($fresh, $publication_key, $expected_form_id);
			if ($precondition !== null) return $precondition;
			$outcome = $this->gateway->activate($expected_form_id);
			if (is_wp_error($outcome)) {
				$attempted = (bool) ($outcome->get_error_data()['mutation_attempted'] ?? false);
				return $this->result($attempted ? Activation_Result::UNKNOWN : Activation_Result::FAILED, $attempted ? 'FORM_ACTIVATION_UNKNOWN' : 'FORM_ACTIVATION_FAILED', $outcome->get_error_message(), array('operation_key' => $operation_key, 'snapshot_fingerprint' => substr($snapshot_fingerprint, 0, 12), 'mutation_attempted' => $attempted));
			}
			$after = $this->status->read($publication_key);
			if (is_wp_error($after)) return $this->result(Activation_Result::UNKNOWN, 'POST_ACTIVATION_STATUS_UNAVAILABLE', 'Não foi possível confirmar o estado após a ativação.', array('operation_key' => $operation_key, 'mutation_attempted' => true));
			if ((string) ($after['publication_key'] ?? '') !== $publication_key || (int) ($after['form_id'] ?? 0) !== $expected_form_id) return $this->result(Activation_Result::RECONCILIATION_REQUIRED, 'POST_ACTIVATION_DRIFT', 'A Publicação ou o Form divergiram após a ativação.', $this->evidence($operation_key, $snapshot_fingerprint, $outcome, $after));
			if (! $outcome->mutation_attempted() || $outcome->error_code() === 'FORM_ALREADY_ACTIVE') return $this->result(Activation_Result::RECONCILIATION_REQUIRED, 'FORM_ALREADY_ACTIVE_UNKNOWN', 'O adaptador encontrou o Form ativo sem mutação comprovada.', $this->evidence($operation_key, $snapshot_fingerprint, $outcome, $after));
			if (! $outcome->verification_complete() || $outcome->after_state() !== 'ACTIVE' || $outcome->error_code() === 'FORM_ACTIVATION_NOT_CONFIRMED') return $this->result(Activation_Result::FAILED, 'FORM_ACTIVATION_NOT_CONFIRMED', 'A ativação não foi confirmada pelo adaptador.', $this->evidence($operation_key, $snapshot_fingerprint, $outcome, $after));
			if ((string) ($after['effective_state'] ?? '') !== 'RECONCILIATION_REQUIRED' || (string) ($after['form_state'] ?? '') !== 'active') return $this->result(Activation_Result::RECONCILIATION_REQUIRED, 'POST_ACTIVATION_DRIFT', 'O estado pós-ativação não foi confirmado integralmente.', $this->evidence($operation_key, $snapshot_fingerprint, $outcome, $after));
			if (! $this->inventory_ready($after)) return $this->result(Activation_Result::RECONCILIATION_REQUIRED, 'POST_ACTIVATION_DRIFT', 'O inventário divergiu após a ativação.', $this->evidence($operation_key, $snapshot_fingerprint, $outcome, $after));
			return $this->result(Activation_Result::ACTIVATED, 'FORM_ACTIVATED', 'O formulário foi ativado e verificado.', $this->evidence($operation_key, $snapshot_fingerprint, $outcome, $after));
		} finally { $this->lock->release_choice_lock($publication_key); }
	}

	/** @param array<string,mixed>|\WP_Error $state */
	private function preconditions(array|\WP_Error $state, string $publication_key, int $form_id): ?Activation_Result {
		if (is_wp_error($state)) return $this->result(Activation_Result::BLOCKED, 'STATUS_UNAVAILABLE', 'Não foi possível comprovar o estado da Publicação.');
		if ((string) ($state['publication_key'] ?? '') !== $publication_key) return $this->result(Activation_Result::RECONCILIATION_REQUIRED, 'PUBLICATION_KEY_MISMATCH', 'A Publicação retornada não corresponde à operação.');
		if ((int) ($state['form_id'] ?? 0) !== $form_id) return $this->result(Activation_Result::RECONCILIATION_REQUIRED, 'FORM_ID_MISMATCH', 'O Form esperado diverge do mapping persistido.');
		if ((string) ($state['form_state'] ?? '') === 'missing') return $this->result(Activation_Result::RECONCILIATION_REQUIRED, 'FORM_NOT_FOUND', 'O Form esperado não existe no Gravity Forms.');
		if ((string) ($state['form_state'] ?? '') === 'active') return $this->result(Activation_Result::RECONCILIATION_REQUIRED, 'FORM_ALREADY_ACTIVE_UNKNOWN', 'O Form já está ativo sem prova de que esta operação o ativou.');
		if ((string) ($state['effective_state'] ?? '') !== 'MATERIALIZED') return $this->result(Activation_Result::BLOCKED, 'PUBLICATION_NOT_MATERIALIZED', 'A Publicação não está materializada em estado compatível.');
		if ((string) ($state['form_state'] ?? '') !== 'inactive') return $this->result(Activation_Result::BLOCKED, 'FORM_NOT_INACTIVE', 'O Form não está inativo.');
		if (! $this->inventory_ready($state)) return $this->result(Activation_Result::BLOCKED, 'INVENTORY_NOT_HEALTHY', 'O inventário não está saudável.');
		return null;
	}

	/** @param array<string,mixed> $state */
	private function inventory_ready(array $state): bool {
		$inventory = (array) ($state['inventory'] ?? array());
		if ((string) ($inventory['status'] ?? '') !== 'READY') return false;
		$resources = (array) ($inventory['resources'] ?? array());
		if ($resources === array()) return false;
		foreach ($resources as $resource) if (! is_array($resource) || empty($resource['healthy']) || ! is_numeric($resource['capacity'] ?? null) || ! is_numeric($resource['consumed'] ?? null) || (int) $resource['consumed'] > (int) $resource['capacity']) return false;
		return true;
	}

	/** @param array<string,mixed> $after */
	private function evidence(string $operation_key, string $fingerprint, Form_Activation_Outcome $outcome, array $after): array { return array('operation_key' => $operation_key, 'snapshot_fingerprint' => substr($fingerprint, 0, 12), 'gateway' => $outcome->evidence(), 'form_id' => (int) ($after['form_id'] ?? 0), 'form_state' => (string) ($after['form_state'] ?? ''), 'effective_state' => (string) ($after['effective_state'] ?? ''), 'inventory_status' => (string) (($after['inventory']['status'] ?? 'UNKNOWN'))); }
	/** @param array<string,mixed> $evidence */
	private function result(string $status, string $code, string $message, array $evidence = array()): Activation_Result { return new Activation_Result($status, $code, $message, $evidence); }
}
