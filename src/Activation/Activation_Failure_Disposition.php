<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

/** Small public retry-safety contract derived from the durable activation ledger. */
final class Activation_Failure_Disposition {
	public const NO_MUTATION = 'NO_MUTATION';
	public const MUTATION_UNCERTAIN = 'MUTATION_UNCERTAIN';
	public const MUTATION_CONFIRMED = 'MUTATION_CONFIRMED';
	public const RETRYABLE = 'RETRYABLE';
	public const NOT_RETRYABLE = 'NOT_RETRYABLE';
	public const RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';

	/** @param array<string,mixed> $evidence @return array{mutation_safety:string,retry_disposition:string} */
	public static function for_record(string $state, string $error_code, array $evidence): array {
		$mutation = $evidence['activation_mutation'] ?? null;
		$mutation_state = is_array($mutation) && Activation_Mutation_Evidence::is_valid_persisted($mutation) ? (string) ($mutation['state'] ?? '') : '';
		if ($state === Activation_Operation_State::SUCCEEDED || $mutation_state === Activation_Mutation_Evidence::MUTATION_CONFIRMED) {
			return array('mutation_safety' => self::MUTATION_CONFIRMED, 'retry_disposition' => $state === Activation_Operation_State::SUCCEEDED ? self::NOT_RETRYABLE : self::RECONCILIATION_REQUIRED);
		}
		if (in_array($state, array(Activation_Operation_State::IN_PROGRESS, Activation_Operation_State::RECONCILIATION_REQUIRED), true) || $mutation_state !== Activation_Mutation_Evidence::NO_MUTATION_EVIDENCE) {
			return array('mutation_safety' => self::MUTATION_UNCERTAIN, 'retry_disposition' => self::RECONCILIATION_REQUIRED);
		}
		if ($state === Activation_Operation_State::FAILED) {
			$retryable_codes = array('GRAVITY_FORMS_UNAVAILABLE', 'STATUS_UNAVAILABLE', 'LOCK_UNAVAILABLE', 'PUBLICATION_NOT_MATERIALIZED', 'FORM_NOT_INACTIVE', 'INVENTORY_NOT_HEALTHY');
			return array('mutation_safety' => self::NO_MUTATION, 'retry_disposition' => in_array($error_code, $retryable_codes, true) ? self::RETRYABLE : self::NOT_RETRYABLE);
		}
		return array('mutation_safety' => self::NO_MUTATION, 'retry_disposition' => self::NOT_RETRYABLE);
	}
}
