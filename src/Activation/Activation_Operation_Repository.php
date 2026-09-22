<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

final class Activation_Operation_Repository implements Activation_Operation_Store {
	private \wpdb $wpdb;
	public function __construct(?\wpdb $database = null) { global $wpdb; $this->wpdb = $database ?? $wpdb; }
	/** @return array<string,mixed>|null */
	public function find_by_operation_key(string $operation_key): ?array {
		$row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE operation_key = %s", $operation_key), 'ARRAY_A');
		return is_array($row) ? $row : null;
	}
	/** @return array<string,mixed>|null */
	public function find_by_id(int $id): ?array {
		$row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id), 'ARRAY_A');
		return is_array($row) ? $row : null;
	}
	public function reserve(string $operation_key, string $publication_key, int $gravity_form_id, string $snapshot_fingerprint): Activation_Operation_Reservation {
		if (! preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $operation_key)) return new Activation_Operation_Reservation(Activation_Operation_Reservation::ERROR, null, 'turmas_bridge_invalid_operation_key');
		if (! preg_match('/^[0-9]{4}:[A-Z0-9_-]{1,50}$/', $publication_key)) return new Activation_Operation_Reservation(Activation_Operation_Reservation::ERROR, null, 'turmas_bridge_invalid_publication_key');
		if ($gravity_form_id < 1) return new Activation_Operation_Reservation(Activation_Operation_Reservation::ERROR, null, 'turmas_bridge_invalid_form_id');
		if (! preg_match('/^[a-f0-9]{64}$/', $snapshot_fingerprint)) return new Activation_Operation_Reservation(Activation_Operation_Reservation::ERROR, null, 'turmas_bridge_invalid_snapshot_fingerprint');
		$time = current_time('mysql', true);
		$inserted = $this->wpdb->insert($this->table(), array(
			'operation_key' => $operation_key,
			'publication_key' => $publication_key,
			'gravity_form_id' => $gravity_form_id,
			'snapshot_fingerprint' => $snapshot_fingerprint,
			'state' => Activation_Operation_State::PENDING,
			'error_code' => null,
			'evidence_json' => null,
			'processing_started_at' => null,
			'reconciliation_at' => null,
			'created_at' => $time,
			'updated_at' => $time,
		));
		if ($inserted !== false) return new Activation_Operation_Reservation(Activation_Operation_Reservation::CREATED, $this->find_by_operation_key($operation_key));
		$existing = $this->find_by_operation_key($operation_key);
		if (! $existing) return new Activation_Operation_Reservation(Activation_Operation_Reservation::ERROR, null, 'turmas_bridge_activation_ledger_write_failed');
		$matches = (string) ($existing['publication_key'] ?? '') === $publication_key
			&& (int) ($existing['gravity_form_id'] ?? 0) === $gravity_form_id
			&& hash_equals((string) ($existing['snapshot_fingerprint'] ?? ''), $snapshot_fingerprint);
		return new Activation_Operation_Reservation($matches ? Activation_Operation_Reservation::EXISTING_MATCH : Activation_Operation_Reservation::CONFLICT, $existing, $matches ? null : 'turmas_bridge_activation_identity_conflict');
	}
	/** @param array<string,mixed> $evidence */
	public function transition(int $id, string $expected_state, string $new_state, ?string $error_code = null, array $evidence = array()): Activation_Operation_Transition {
		if (! Activation_Operation_State::can_transition($expected_state, $new_state)) return new Activation_Operation_Transition(Activation_Operation_Transition::ERROR, null, 'turmas_bridge_activation_invalid_transition');
		$time = current_time('mysql', true);
		$evidence_json = $this->encode_evidence($evidence);
		if ($evidence_json === false) return new Activation_Operation_Transition(Activation_Operation_Transition::ERROR, null, 'turmas_bridge_activation_evidence_invalid');
		$sets = 'state = %s, error_code = %s, evidence_json = %s, updated_at = %s';
		$args = array($new_state, $error_code, $evidence_json, $time);
		if ($new_state === Activation_Operation_State::IN_PROGRESS) { $sets = 'state = %s, error_code = NULL, processing_started_at = %s, updated_at = %s'; $args = array($new_state, $time, $time); }
		if ($new_state === Activation_Operation_State::RECONCILIATION_REQUIRED) { $sets .= ', reconciliation_at = %s'; $args[] = $time; }
		$args[] = $id; $args[] = $expected_state;
		$query = $this->wpdb->prepare("UPDATE {$this->table()} SET {$sets} WHERE id = %d AND state = %s", ...$args);
		$updated = $this->wpdb->query($query);
		if ($updated === false) return new Activation_Operation_Transition(Activation_Operation_Transition::ERROR, null, 'turmas_bridge_activation_ledger_update_failed');
		if ($updated !== 1) return new Activation_Operation_Transition(Activation_Operation_Transition::STATE_MISMATCH, $this->find_by_id($id));
		return new Activation_Operation_Transition(Activation_Operation_Transition::UPDATED, $this->find_by_id($id));
	}
	public function recover_stale(int $id, string $expected_updated_at): Activation_Operation_Transition {
		$time = current_time('mysql', true);
		$query = $this->wpdb->prepare("UPDATE {$this->table()} SET state = %s, error_code = %s, reconciliation_at = %s, updated_at = %s WHERE id = %d AND state = %s AND updated_at = %s", Activation_Operation_State::RECONCILIATION_REQUIRED, 'turmas_bridge_stale_activation', $time, $time, $id, Activation_Operation_State::IN_PROGRESS, $expected_updated_at);
		$updated = $this->wpdb->query($query);
		if ($updated === false) return new Activation_Operation_Transition(Activation_Operation_Transition::ERROR, null, 'turmas_bridge_activation_ledger_update_failed');
		if ($updated !== 1) return new Activation_Operation_Transition(Activation_Operation_Transition::STATE_MISMATCH, $this->find_by_id($id));
		return new Activation_Operation_Transition(Activation_Operation_Transition::UPDATED, $this->find_by_id($id));
	}
	private function table(): string { return $this->wpdb->prefix . 'turmas_bridge_activation_operations'; }
	private function encode_evidence(array $evidence): string|false|null {
		if ($evidence === array()) return null;
		$allowed = array('observed_form_state', 'observed_inventory_health', 'reason_code', 'verified_at', 'activation_mutation');
		foreach ($evidence as $key => $value) {
			if (! is_string($key) || ! in_array($key, $allowed, true)) return false;
			if ($key === 'activation_mutation') { if (! Activation_Mutation_Evidence::is_valid_persisted($value)) return false; continue; }
			if (is_array($value) || is_object($value) || ! is_scalar($value)) return false;
		}
		$json = wp_json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($json) && strlen($json) <= 2048 ? $json : false;
	}
}
