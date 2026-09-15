<?php

declare(strict_types=1);

namespace TurmasBridge\Materialization;

final class Materialization_Repository implements Materialization_Store {
	private \wpdb $wpdb;
	public function __construct(?\wpdb $database = null) { global $wpdb; $this->wpdb = $database ?? $wpdb; }
	/** @return array<string, mixed>|null */
	public function find(string $publication_key): ?array {
		$row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE publication_key = %s", $publication_key), 'ARRAY_A');
		return is_array($row) ? $row : null;
	}
	public function reserve(string $publication_key, int $template_id, string $payload_hash): bool {
		$time = current_time('mysql', true);
		return $this->wpdb->insert($this->table(), array('publication_key' => $publication_key, 'template_id' => $template_id, 'form_id' => null, 'status' => 'RECEIVED', 'payload_hash' => $payload_hash, 'created_at' => $time, 'updated_at' => $time)) !== false;
	}
	/** @param list<string> $field_ids */
	public function materialized(string $publication_key, int $form_id, array $field_ids): bool { return $this->update($publication_key, array('form_id' => $form_id, 'status' => 'MATERIALIZED', 'field_map_json' => wp_json_encode(array_values($field_ids)), 'error_code' => null)); }
	public function failed(string $publication_key, ?int $form_id, string $error_code): bool { return $this->update($publication_key, array('form_id' => $form_id, 'status' => 'FAILED', 'error_code' => $error_code)); }
	/** @param array<string, mixed> $data */
	private function update(string $publication_key, array $data): bool { $data['updated_at'] = current_time('mysql', true); return $this->wpdb->update($this->table(), $data, array('publication_key' => $publication_key)) !== false; }
	private function table(): string { return $this->wpdb->prefix . 'turmas_bridge_materializations'; }
}
