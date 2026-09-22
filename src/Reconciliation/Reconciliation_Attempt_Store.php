<?php
declare(strict_types=1);
namespace TurmasBridge\Reconciliation;
interface Reconciliation_Attempt_Store {
	/** @return array<string,mixed>|null */ public function find_by_key(string $reconciliation_key): ?array;
	/** @return array<string,mixed>|null */ public function find_latest_for_activation(string $activation_operation_key): ?array;
	public function reserve(string $reconciliation_key, string $activation_operation_key, string $publication_key, int $form_id, string $fingerprint): Reconciliation_Attempt_Reservation;
	public function claim(int $id): Reconciliation_Attempt_Transition;
	/** @param array<string,mixed> $evidence */ public function complete(int $id, string $expected_state, string $state, string $result_code, array $evidence): Reconciliation_Attempt_Transition;
}
