<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Durable Bridge-owned mapping. Resource internals never define class identity. */
final class Inventory_Mapping_Repository implements Inventory_Mapping_Store {
	private \wpdb $wpdb;

	public function __construct(?\wpdb $database = null) { global $wpdb; $this->wpdb = $database ?? $wpdb; }
	public function find(Resource_Identity $identity): ?array {
		$row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE class_key = %s", $identity->class_key()), 'ARRAY_A');
		return is_array($row) ? $row : null;
	}
	/** @return list<array<string,mixed>> */
	public function list_for_publication(string $publication_key): array {
		$rows = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE publication_key = %s ORDER BY class_key ASC", $publication_key), 'ARRAY_A');
		return array_values(array_filter($rows, 'is_array'));
	}
	public function reserve(Resource_Identity $identity, int $form_id): bool {
		$time = current_time('mysql', true);
		return $this->wpdb->insert($this->table(), array('publication_key' => $identity->publication_key(), 'class_key' => $identity->class_key(), 'resource_id' => null, 'form_id' => $form_id, 'status' => 'PROVISIONING', 'created_at' => $time, 'updated_at' => $time)) !== false;
	}
	public function discard_provisioning(Resource_Identity $identity): bool {
		return $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$this->table()} WHERE class_key = %s AND status = 'PROVISIONING' AND resource_id IS NULL", $identity->class_key())) !== false;
	}
	public function resource_created(Resource_Identity $identity, int $resource_id, int $form_id): bool {
		return $this->update($identity, array('resource_id' => $resource_id, 'form_id' => $form_id, 'status' => 'PROVISIONING', 'last_error_code' => null));
	}
	public function healthy(Resource_Identity $identity, int $form_id): bool {
		$time = current_time('mysql', true);
		return $this->update($identity, array('form_id' => $form_id, 'status' => 'HEALTHY', 'last_error_code' => null, 'last_reconciled_at' => $time));
	}
	public function reconciliation_required(Resource_Identity $identity, string $error_code): bool { return $this->update($identity, array('status' => 'RECONCILIATION_REQUIRED', 'last_error_code' => $error_code)); }
	public function acquire_lock(Resource_Identity $identity): bool { return (int) $this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, 10)', $this->lock_name($identity))) === 1; }
	public function release_lock(Resource_Identity $identity): void { $this->wpdb->get_var($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lock_name($identity))); }
	/** @param array<string,mixed> $data */
	private function update(Resource_Identity $identity, array $data): bool { $data['updated_at'] = current_time('mysql', true); return $this->wpdb->update($this->table(), $data, array('class_key' => $identity->class_key())) !== false; }
	private function table(): string { return $this->wpdb->prefix . 'turmas_bridge_inventory_resources'; }
	private function lock_name(Resource_Identity $identity): string { return 'tbr_inventory_' . substr(hash('sha256', $identity->class_key()), 0, 48); }
}
