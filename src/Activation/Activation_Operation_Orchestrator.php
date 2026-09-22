<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

/** Internal ledger orchestration only; it never activates a Gravity Form. */
final class Activation_Operation_Orchestrator {
	public function __construct(private Activation_Operation_Store $store) {}
	/** @return array<string,mixed>|null */
	public function find_by_operation_key(string $operation_key): ?array { return $this->store->find_by_operation_key($operation_key); }
	/** @return array<string,mixed>|null */
	public function find_by_id(int $id): ?array { return $this->store->find_by_id($id); }
	public function reserve(string $operation_key, string $publication_key, int $gravity_form_id, string $snapshot_fingerprint): Activation_Operation_Reservation { return $this->store->reserve($operation_key, $publication_key, $gravity_form_id, $snapshot_fingerprint); }
	public function claim(int $id): Activation_Operation_Transition { return $this->store->transition($id, Activation_Operation_State::PENDING, Activation_Operation_State::IN_PROGRESS); }
	/** @param array<string,mixed> $evidence */
	public function succeed(int $id, array $evidence): Activation_Operation_Transition { return $this->store->transition($id, Activation_Operation_State::IN_PROGRESS, Activation_Operation_State::SUCCEEDED, null, $evidence); }
	public function fail_before_mutation(int $id, string $error_code): Activation_Operation_Transition { return $this->store->transition($id, Activation_Operation_State::IN_PROGRESS, Activation_Operation_State::FAILED, $error_code); }
	/** @param array<string,mixed> $evidence */
	public function require_reconciliation(int $id, string $error_code, array $evidence = array()): Activation_Operation_Transition { return $this->store->transition($id, Activation_Operation_State::IN_PROGRESS, Activation_Operation_State::RECONCILIATION_REQUIRED, $error_code, $evidence); }
	public function recover_stale(int $id, string $expected_updated_at): Activation_Operation_Transition { return $this->store->recover_stale($id, $expected_updated_at); }
}
