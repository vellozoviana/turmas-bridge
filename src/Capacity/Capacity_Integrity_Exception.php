<?php

declare(strict_types=1);

namespace TurmasBridge\Capacity;

/** A mismatch against persisted materialization evidence requires reconciliation. */
final class Capacity_Integrity_Exception extends \RuntimeException {
	public function __construct(string $message, private string $integrity_code = 'INVENTORY_INTEGRITY_UNVERIFIED', ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
	}
	public function integrity_code(): string { return $this->integrity_code; }
}
