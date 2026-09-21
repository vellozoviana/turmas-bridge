<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

interface Activation_Operation_Store {
	/** @return array<string,mixed>|null */
	public function find_by_operation_key(string $operation_key): ?array;
	/** @return array<string,mixed>|null */
	public function find_by_id(int $id): ?array;
	public function reserve(string $operation_key, string $publication_key, int $gravity_form_id, string $snapshot_fingerprint): Activation_Operation_Reservation;
	/** @param array<string,mixed> $evidence */
	public function transition(int $id, string $expected_state, string $new_state, ?string $error_code = null, array $evidence = array()): Activation_Operation_Transition;
	public function recover_stale(int $id, string $expected_updated_at): Activation_Operation_Transition;
}
