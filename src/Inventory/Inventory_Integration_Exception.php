<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Controlled failure at the compatibility-sensitive GP Inventory boundary. */
final class Inventory_Integration_Exception extends \RuntimeException {
	private string $error_code;
	private bool $ambiguous_outcome;

	public function __construct(string $error_code, string $message, bool $ambiguous_outcome = false) {
		parent::__construct($message);
		$this->error_code = $error_code;
		$this->ambiguous_outcome = $ambiguous_outcome;
	}

	public function error_code(): string { return $this->error_code; }
	public function outcome_is_ambiguous(): bool { return $this->ambiguous_outcome; }
}
