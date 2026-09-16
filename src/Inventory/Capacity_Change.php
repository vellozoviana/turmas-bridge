<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

final class Capacity_Change {
	public const NO_CHANGE = 'NO_CHANGE';
	public const INCREASE = 'INCREASE';
	public const DECREASE_ALLOWED = 'DECREASE_ALLOWED';
	public const DECREASE_BELOW_CONSUMED = 'DECREASE_BELOW_CONSUMED';

	private string $decision;
	private int $consumed;
	private int $requested_capacity;

	public function __construct(string $decision, int $consumed, int $requested_capacity) {
		$this->decision = $decision;
		$this->consumed = $consumed;
		$this->requested_capacity = $requested_capacity;
	}
	public function decision(): string { return $this->decision; }
	public function requires_mutation(): bool { return $this->decision === self::INCREASE || $this->decision === self::DECREASE_ALLOWED; }
	public function remaining_after_change(): int { return $this->requested_capacity - $this->consumed; }
}
