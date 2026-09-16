<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

final class Capacity_Change_Decider {
	public function decide(int $current_capacity, int $consumed, int $requested_capacity): Capacity_Change {
		if ($current_capacity < 0 || $consumed < 0 || $requested_capacity < 1 || $consumed > $current_capacity) {
			throw new \InvalidArgumentException('Os valores de capacidade e consumo são inválidos.');
		}
		if ($requested_capacity === $current_capacity) return new Capacity_Change(Capacity_Change::NO_CHANGE, $consumed, $requested_capacity);
		if ($requested_capacity > $current_capacity) return new Capacity_Change(Capacity_Change::INCREASE, $consumed, $requested_capacity);
		if ($requested_capacity < $consumed) return new Capacity_Change(Capacity_Change::DECREASE_BELOW_CONSUMED, $consumed, $requested_capacity);
		return new Capacity_Change(Capacity_Change::DECREASE_ALLOWED, $consumed, $requested_capacity);
	}
}
