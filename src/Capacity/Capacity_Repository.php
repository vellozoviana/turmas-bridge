<?php
declare(strict_types=1);
namespace TurmasBridge\Capacity;

final class Capacity_Repository implements Capacity_Store {
	private \wpdb $db;
	private string $table;
	public function __construct(?\wpdb $db = null) { global $wpdb; $this->db = $db ?? $wpdb; $this->table = $this->db->prefix . 'turmas_bridge_capacity_operations'; }
	public function find(string $key): ?array { $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE operation_key = %s", $key), 'ARRAY_A'); return is_array($r) ? $r : null; }
	public function reserve(array $c): bool {
		$now = current_time('mysql', true);
		return $this->db->insert($this->table, array('operation_key' => $c['operation_key'], 'publication_key' => $c['publication_key'], 'class_key' => $c['class_key'], 'source_row_version' => $c['source_row_version'], 'desired_capacity' => $c['desired_capacity'], 'command_json' => wp_json_encode($c), 'payload_hash' => Capacity_Command::hash($c), 'state' => 'PENDING', 'attempts' => 0, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now)) === 1;
	}
	public function conflict(array $c): ?string {
		$rows = $this->db->get_results($this->db->prepare("SELECT operation_key, source_row_version, state FROM {$this->table} WHERE class_key = %s AND operation_key <> %s", $c['class_key'], $c['operation_key']), 'ARRAY_A');
		if ($this->db->last_error !== '') return 'STORE_UNAVAILABLE';
		foreach ($rows as $r) {
			if (in_array($r['state'], array('APPLYING', 'RECONCILIATION_REQUIRED'), true)) return 'PRIOR_OPERATION_UNRESOLVED';
			if ((int) $r['source_row_version'] >= $c['source_row_version']) return 'SOURCE_VERSION_CONFLICT';
		}
		return null;
	}
	public function transition(array $r, string $state, array $evidence, ?string $error, bool $attempt = false): ?array {
		if (! Capacity_State::allows((string) $r['state'], $state)) return null;
		$history = json_decode((string) (($r['evidence_json'] ?? '') ?: '[]'), true);
		if (! is_array($history)) return null;
		$now = current_time('mysql', true);
		$history[] = array('state' => $state, 'error_code' => $error, 'at' => $now, 'evidence' => $evidence);
		$data = array('state' => $state, 'error_code' => $error, 'attempts' => (int) $r['attempts'] + ($attempt ? 1 : 0), 'revision' => (int) $r['revision'] + 1, 'evidence_json' => wp_json_encode($history), 'updated_at' => $now);
		foreach (array('capacity_before', 'consumed_before', 'capacity_after', 'consumed_after') as $key) if (isset($evidence[$key])) $data[$key] = $evidence[$key];
		if ($this->db->update($this->table, $data, array('operation_key' => $r['operation_key'], 'state' => $r['state'], 'revision' => $r['revision'])) !== 1) return null;
		return array_merge($r, $data);
	}
}
