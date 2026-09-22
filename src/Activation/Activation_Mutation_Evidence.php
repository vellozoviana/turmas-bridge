<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

/** Server-generated, bounded evidence about this operation's activation mutation. */
final class Activation_Mutation_Evidence {
	public const NO_MUTATION_EVIDENCE = 'NO_MUTATION_EVIDENCE';
	public const MUTATION_ATTEMPTED = 'MUTATION_ATTEMPTED';
	public const OUTCOME_UNCERTAIN = 'OUTCOME_UNCERTAIN';
	public const MUTATION_CONFIRMED = 'MUTATION_CONFIRMED';
	private const VERSION = 1;

	private function __construct(private string $state, private ?int $form_id, private string $source, private string $observed_form_state) {}
	public function state(): string { return $this->state; }

	public static function none(): self { return new self(self::NO_MUTATION_EVIDENCE, null, 'b2_pre_mutation', 'unknown'); }
	public static function attempted(int $form_id): self { return new self(self::MUTATION_ATTEMPTED, $form_id > 0 ? $form_id : null, 'b2_gateway_error', 'unknown'); }
	public static function uncertain(int $form_id, string $source = 'b2_post_read_unavailable'): self { return new self(self::OUTCOME_UNCERTAIN, $form_id > 0 ? $form_id : null, $source, 'unknown'); }

	/**
	 * Confirms only the conjunction of a successful gateway outcome and an
	 * immediate observation of the same expected Form active.
	 *
	 * @param array<string,mixed> $post_read
	 */
	public static function from_verified_gateway(Form_Activation_Outcome $outcome, string $publication_key, int $expected_form_id, array $post_read): self {
		$confirmed = $expected_form_id > 0
			&& $outcome->before_state() === 'INACTIVE'
			&& $outcome->mutation_attempted()
			&& $outcome->after_state() === 'ACTIVE'
			&& $outcome->verification_complete()
			&& $outcome->error_code() === null
			&& (string) ($post_read['publication_key'] ?? '') === $publication_key
			&& (int) ($post_read['form_id'] ?? 0) === $expected_form_id
			&& (string) ($post_read['form_state'] ?? '') === 'active';

		return $confirmed
			? new self(self::MUTATION_CONFIRMED, $expected_form_id, 'b2_gateway_success_and_post_read', 'active')
			: self::uncertain($expected_form_id, 'b2_post_read_unconfirmed');
	}

	/** @param mixed $value */
	public static function is_confirmed_persisted(mixed $value, int $expected_form_id): bool {
		if (! is_array($value) || count($value) !== 5) return false;
		return ($value['version'] ?? null) === self::VERSION
			&& ($value['state'] ?? null) === self::MUTATION_CONFIRMED
			&& ($value['form_id'] ?? null) === $expected_form_id
			&& $expected_form_id > 0
			&& ($value['source'] ?? null) === 'b2_gateway_success_and_post_read'
			&& ($value['observed_form_state'] ?? null) === 'active';
	}

	/** @param mixed $value */
	public static function is_valid_persisted(mixed $value): bool {
		if (! is_array($value) || count($value) !== 5 || ($value['version'] ?? null) !== self::VERSION) return false;
		$state = $value['state'] ?? null; $form_id = $value['form_id'] ?? null; $source = $value['source'] ?? null; $observed = $value['observed_form_state'] ?? null;
		if (! is_string($state) || ! in_array($state, array(self::NO_MUTATION_EVIDENCE, self::MUTATION_ATTEMPTED, self::OUTCOME_UNCERTAIN, self::MUTATION_CONFIRMED), true)) return false;
		if ($state === self::NO_MUTATION_EVIDENCE) return $form_id === null && $source === 'b2_pre_mutation' && $observed === 'unknown';
		if (! is_int($form_id) || $form_id < 1 || ! is_string($source) || ! is_string($observed)) return false;
		if ($state === self::MUTATION_ATTEMPTED) return $source === 'b2_gateway_error' && $observed === 'unknown';
		if ($state === self::OUTCOME_UNCERTAIN) return in_array($source, array('b2_gateway_error', 'b2_post_read_unavailable', 'b2_post_read_unconfirmed', 'b2_gateway_unconfirmed'), true) && $observed === 'unknown';
		return $source === 'b2_gateway_success_and_post_read' && $observed === 'active';
	}

	/** @return array{version:int,state:string,form_id:?int,source:string,observed_form_state:string} */
	public function to_array(): array { return array('version' => self::VERSION, 'state' => $this->state, 'form_id' => $this->form_id, 'source' => $this->source, 'observed_form_state' => $this->observed_form_state); }
}
