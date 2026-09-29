<?php
declare(strict_types=1);
namespace TurmasBridge\Capacity;

final class Capacity_Service {
	public function __construct(private Capacity_Store $store = new Capacity_Repository(), private Capacity_Gateway $gateway = new WordPress_Capacity_Gateway()) {}
	public function execute(mixed $input, bool $reconcile = false): array|\WP_Error {
		$c = Capacity_Command::validate($input);
		if (is_wp_error($c)) return $c;
		try { if (! $this->gateway->lock($c['class_key'])) return $this->error('CAPACITY_LOCKED', 409); }
		catch (\Throwable) { return $this->error('CAPACITY_LOCK_UNAVAILABLE', 503); }
		$release_failed = false;
		try { $result = $this->execute_locked($c, $reconcile); }
		finally {
			try { $this->gateway->unlock($c['class_key']); }
			catch (\Throwable) { $release_failed = true; }
		}
		return $release_failed ? $this->error('CAPACITY_UNLOCK_UNCONFIRMED', 503) : $result;
	}
	private function execute_locked(array $c, bool $reconcile): array|\WP_Error {
		$r = null; $possible_effect = false;
		try {
			$r = $this->store->find($c['operation_key']);
			if ($r && ! hash_equals((string) $r['payload_hash'], Capacity_Command::hash($c))) return $this->error('IDEMPOTENCY_CONFLICT', 409);
			if (! $r) {
				if ($reconcile) return $this->error('CAPACITY_OPERATION_NOT_FOUND', 404);
				$conflict = $this->store->conflict($c);
				if ($conflict !== null) return $this->error($conflict, 409);
				if (! $this->store->reserve($c)) return $this->error('CAPACITY_STORE_FAILED', 503);
				$r = $this->store->find($c['operation_key']);
			}
			if (! $r) return $this->error('CAPACITY_STORE_FAILED', 503);
			if ($r['state'] === 'APPLIED_VERIFIED' && ! $reconcile) return self::response($r, true);
			if ($r['state'] === 'FAILED' && ! $reconcile) return self::response($r, true);
			$possible_effect = in_array($r['state'], array('APPLYING', 'RECONCILIATION_REQUIRED', 'APPLIED_VERIFIED'), true);
			if ($possible_effect && ! $reconcile) return $this->finish($r, 'RECONCILIATION_REQUIRED', array(), 'EXPLICIT_RECONCILIATION_REQUIRED');
			$conflict = $this->store->conflict($c);
			if ($conflict !== null) return $this->error($conflict, 409);
			$before = $this->gateway->inspect($c);
			$evidence = array('observation' => $before);
			if (! $possible_effect) $evidence += array('capacity_before' => $before['capacity'] ?? null, 'consumed_before' => $before['consumed'] ?? null);
			if (! empty($before['form_active'])) return $this->finish($r, $possible_effect ? 'RECONCILIATION_REQUIRED' : 'BLOCKED_FORM_ACTIVE', $evidence, 'FORM_ACTIVE', true);
			if (! $this->healthy($before)) return $this->finish($r, 'RECONCILIATION_REQUIRED', $evidence, 'INVENTORY_DRIFT', true);
			if ($before['consumed'] > $c['desired_capacity']) return $this->finish($r, $possible_effect ? 'RECONCILIATION_REQUIRED' : 'BLOCKED_BELOW_CONSUMED', $evidence, 'CAPACITY_BELOW_CONSUMED', true);
			if ($reconcile || $before['capacity'] === $c['desired_capacity']) {
				$state = $before['capacity'] === $c['desired_capacity'] ? 'APPLIED_VERIFIED' : 'RECONCILIATION_REQUIRED';
				return $this->finish($r, $state, $evidence + array('capacity_after' => $before['capacity'], 'consumed_after' => $before['consumed']), $state === 'APPLIED_VERIFIED' ? null : 'CAPACITY_MISMATCH', true);
			}
			$started = $this->store->transition($r, 'APPLYING', $evidence, null, true);
			if (! $started) return $this->error('CAPACITY_STORE_FAILED', 503);
			$r = $started; $possible_effect = true;
			$blocker = $this->gateway->write($c);
			if ($blocker !== null) return $this->finish($r, $blocker === 'FORM_ACTIVE' ? 'BLOCKED_FORM_ACTIVE' : 'BLOCKED_BELOW_CONSUMED', array(), $blocker);
			$after = $this->gateway->inspect($c);
			$evidence = array('observation' => $after, 'capacity_after' => $after['capacity'] ?? null, 'consumed_after' => $after['consumed'] ?? null);
			if (! $this->healthy($after) || ! empty($after['form_active']) || $after['capacity'] !== $c['desired_capacity'] || $after['consumed'] > $c['desired_capacity'] || ($after['resource_id'] ?? null) !== ($before['resource_id'] ?? null) || ($after['bindings'] ?? null) !== ($before['bindings'] ?? null)) return $this->finish($r, 'RECONCILIATION_REQUIRED', $evidence, 'POST_WRITE_DIVERGENCE');
			return $this->finish($r, 'APPLIED_VERIFIED', $evidence, null);
		} catch (Capacity_Integrity_Exception) {
			if ($r) return $this->finish($r, 'RECONCILIATION_REQUIRED', array(), 'INVENTORY_INTEGRITY_UNVERIFIED', true);
			return $this->error('CAPACITY_INTEGRITY_UNVERIFIED', 503);
		} catch (\Throwable) {
			if ($r) return $this->finish($r, $possible_effect ? 'RECONCILIATION_REQUIRED' : 'FAILED', array(), $possible_effect ? 'EXTERNAL_EFFECT_UNCERTAIN' : 'INSPECTION_FAILED');
			return $this->error('CAPACITY_STORE_FAILED', 503);
		}
	}
	private function healthy(array $s): bool { return ($s['healthy'] ?? false) === true && is_int($s['capacity'] ?? null) && is_int($s['consumed'] ?? null) && $s['consumed'] >= 0 && ($s['resource_capacity'] ?? null) === $s['capacity']; }
	private function finish(array $r, string $state, array $e, ?string $error, bool $attempt = false): array|\WP_Error {
		try { $saved = $this->store->transition($r, $state, $e, $error, $attempt); }
		catch (\Throwable) { $saved = null; }
		return $saved ? self::response($saved) : $this->error('CAPACITY_RESULT_UNPERSISTED', 503);
	}
	public static function response(array $r, bool $replay = false): array {
		$c = json_decode((string) $r['command_json'], true);
		return array('operation_key' => $r['operation_key'], 'publication_key' => $r['publication_key'], 'class_key' => $r['class_key'], 'source_row_version' => (int) $r['source_row_version'], 'desired_capacity' => (int) $r['desired_capacity'], 'expected_form_id' => (int) ($c['expected_form_id'] ?? 0), 'state' => $r['state'], 'attempts' => (int) $r['attempts'], 'capacity_before' => isset($r['capacity_before']) ? (int) $r['capacity_before'] : null, 'consumed_before' => isset($r['consumed_before']) ? (int) $r['consumed_before'] : null, 'capacity_after' => isset($r['capacity_after']) ? (int) $r['capacity_after'] : null, 'consumed_after' => isset($r['consumed_after']) ? (int) $r['consumed_after'] : null, 'error_code' => $r['error_code'] ?? null, 'idempotent_replay' => $replay);
	}
	private function error(string $code, int $status): \WP_Error { return new \WP_Error($code, 'A operação de capacidade não pôde ser concluída com segurança.', array('status' => $status)); }
}
