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

	/** @param array<string, mixed> $response */
	public function record(string $idempotency_key, string $payload_hash, int $status, array $response): bool {
		$body = wp_json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (! is_string($body)) return false;
		$time = current_time('mysql', true);
		return $this->wpdb->insert($this->table(), array('idempotency_key' => $idempotency_key, 'payload_hash' => $payload_hash, 'response_status' => $status, 'response_body' => $body, 'created_at' => $time, 'updated_at' => $time)) !== false;
	}

	private function table(): string { return $this->wpdb->prefix . 'turmas_bridge_idempotency'; }
}
