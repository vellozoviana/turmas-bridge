<?php

declare(strict_types=1);

namespace TurmasBridge\Publications;

final class Idempotency_Repository implements Publication_Command_Store {
	private \wpdb $wpdb;

	public function __construct(?\wpdb $database = null) {
		global $wpdb;
		$this->wpdb = $database ?? $wpdb;
	}

	/** @return array<string, mixed>|null */
	public function find(string $idempotency_key): ?array {
		$row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE idempotency_key = %s", $idempotency_key), 'ARRAY_A');
		return is_array($row) ? $row : null;
	}

	public function reserve(string $idempotency_key, string $payload_hash, string $publication_key): array {
		$time = current_time('mysql', true);
		$inserted = $this->wpdb->insert($this->table(), array('idempotency_key' => $idempotency_key, 'payload_hash' => $payload_hash, 'publication_key' => $publication_key, 'state' => Publication_Command_Store::RESERVED, 'response_status' => 202, 'response_body' => '{"status":"processing"}', 'created_at' => $time, 'updated_at' => $time));
		if ($inserted !== false) return array('result' => Publication_Command_Store::ACQUIRED, 'record' => null);
		return $this->existing_result($idempotency_key, $payload_hash);
	}

	public function begin_materialization(string $idempotency_key, string $payload_hash, string $publication_key): array {
		$time = current_time('mysql', true);
		$updated = $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table()} SET state = %s, publication_key = %s, processing_started_at = %s, updated_at = %s WHERE idempotency_key = %s AND payload_hash = %s AND state = %s", Publication_Command_Store::MATERIALIZING, $publication_key, $time, $time, $idempotency_key, $payload_hash, Publication_Command_Store::RESERVED));
		if ($updated === 1) return array('result' => Publication_Command_Store::ACQUIRED, 'record' => null);
		if ($updated === false) return array('result' => Publication_Command_Store::STORAGE_FAILURE, 'record' => null);
		return $this->existing_result($idempotency_key, $payload_hash);
	}

	/** @param array<string, mixed> $response */
	public function succeed(string $idempotency_key, string $payload_hash, int $status, array $response): bool {
		$body = wp_json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (! is_string($body)) return false;
		$time = current_time('mysql', true);
		return $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table()} SET state = %s, response_status = %d, response_body = %s, updated_at = %s WHERE idempotency_key = %s AND payload_hash = %s AND state = %s", Publication_Command_Store::SUCCEEDED, $status, $body, $time, $idempotency_key, $payload_hash, Publication_Command_Store::MATERIALIZING)) === 1;
	}

	public function return_to_reserved(string $idempotency_key, string $payload_hash, string $error_code): bool { return $this->change_state($idempotency_key, $payload_hash, Publication_Command_Store::RESERVED, $error_code); }
	public function require_reconciliation(string $idempotency_key, string $payload_hash, string $error_code): bool { return $this->change_state($idempotency_key, $payload_hash, Publication_Command_Store::RECONCILIATION_REQUIRED, $error_code); }

	public function recover_reserved(string $idempotency_key, string $payload_hash, string $expected_updated_at): array {
		$time = current_time('mysql', true);
		$updated = $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table()} SET updated_at = %s, last_error_code = NULL WHERE idempotency_key = %s AND payload_hash = %s AND state = %s AND updated_at = %s", $time, $idempotency_key, $payload_hash, Publication_Command_Store::RESERVED, $expected_updated_at));
		if ($updated === 1) return array('result' => Publication_Command_Store::ACQUIRED, 'record' => null);
		if ($updated === false) return array('result' => Publication_Command_Store::STORAGE_FAILURE, 'record' => null);
		return $this->existing_result($idempotency_key, $payload_hash);
	}

	public function reconcile_stale_materializing(string $idempotency_key, string $payload_hash, string $expected_updated_at): array {
		$time = current_time('mysql', true);
		$updated = $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table()} SET state = %s, response_status = %d, reconciliation_at = %s, last_error_code = %s, updated_at = %s WHERE idempotency_key = %s AND payload_hash = %s AND state = %s AND updated_at = %s", Publication_Command_Store::RECONCILIATION_REQUIRED, 409, $time, 'turmas_bridge_stale_materializing', $time, $idempotency_key, $payload_hash, Publication_Command_Store::MATERIALIZING, $expected_updated_at));
		if ($updated === 1) return array('result' => Publication_Command_Store::ACQUIRED, 'record' => null);
		if ($updated === false) return array('result' => Publication_Command_Store::STORAGE_FAILURE, 'record' => null);
		return $this->existing_result($idempotency_key, $payload_hash);
	}

	/** @return array{result:string,record:?array} */
	private function existing_result(string $idempotency_key, string $payload_hash): array {
		$record = $this->find($idempotency_key);
		if (! $record) return array('result' => Publication_Command_Store::STORAGE_FAILURE, 'record' => null);
		return array('result' => hash_equals((string) $record['payload_hash'], $payload_hash) ? Publication_Command_Store::EXISTING_SAME_HASH : Publication_Command_Store::EXISTING_DIFFERENT_HASH, 'record' => $record);
	}

	private function change_state(string $idempotency_key, string $payload_hash, string $state, string $error_code): bool {
		$time = current_time('mysql', true);
		if ($state === Publication_Command_Store::RESERVED) return $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table()} SET state = %s, response_status = %d, processing_started_at = NULL, reconciliation_at = NULL, last_error_code = %s, updated_at = %s WHERE idempotency_key = %s AND payload_hash = %s AND state = %s", $state, 202, substr($error_code, 0, 100), $time, $idempotency_key, $payload_hash, Publication_Command_Store::MATERIALIZING)) === 1;
		return $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table()} SET state = %s, response_status = %d, reconciliation_at = %s, last_error_code = %s, updated_at = %s WHERE idempotency_key = %s AND payload_hash = %s AND state = %s", $state, 409, $time, substr($error_code, 0, 100), $time, $idempotency_key, $payload_hash, Publication_Command_Store::MATERIALIZING)) === 1;
	}

	private function table(): string { return $this->wpdb->prefix . 'turmas_bridge_idempotency'; }
}
