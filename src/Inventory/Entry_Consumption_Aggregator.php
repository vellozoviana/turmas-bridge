<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

use TurmasBridge\Capacity\Capacity_Integrity_Exception;

/** Deduplicates shared-Resource representations while preserving vendor quantity. */
final class Entry_Consumption_Aggregator {
	/**
	 * @param list<mixed> $representations Vendor query output is untrusted and validated at runtime.
	 */
	public static function aggregate(array $representations): int {
		$entry_quantities = array();
		foreach ($representations as $representation) {
			if (! isset($representation['choice_value']) || ! is_string($representation['choice_value']) || ! isset($representation['rows']) || ! is_array($representation['rows'])) {
				throw new Capacity_Integrity_Exception('Vendor representation result is malformed.', 'CONSUMPTION_RESULT_MALFORMED');
			}
			foreach ($representation['rows'] as $row) {
				if (! is_array($row) || ! isset($row['entry_id'], $row['class_choice'], $row['consumed_quantity'], $row['malformed_quantity_count'])) {
					throw new Capacity_Integrity_Exception('Vendor consumption row is malformed.', 'CONSUMPTION_RESULT_MALFORMED');
				}
				$id = filter_var($row['entry_id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => PHP_INT_MAX)));
				$quantity = filter_var($row['consumed_quantity'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => PHP_INT_MAX)));
				$malformed = filter_var($row['malformed_quantity_count'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => PHP_INT_MAX)));
				if ($id === false || $quantity === false || $malformed === false || $malformed !== 0 || ! is_string($row['class_choice'])) {
					throw new Capacity_Integrity_Exception('Vendor returned an invalid Entry identity or quantity.', 'CONSUMPTION_RESULT_MALFORMED');
				}
				if ($row['class_choice'] !== $representation['choice_value']) continue;
				$entry_id = (int) $id;
				if (isset($entry_quantities[$entry_id]) && $entry_quantities[$entry_id] !== (int) $quantity) {
					throw new Capacity_Integrity_Exception('Shared Resource representations disagree on an Entry quantity.', 'CONSUMPTION_REPRESENTATION_CONFLICT');
				}
				$entry_quantities[$entry_id] = (int) $quantity;
			}
		}
		$total = array_sum($entry_quantities);
		if (! is_int($total)) throw new Capacity_Integrity_Exception('Vendor consumption total is outside the supported integer range.', 'CONSUMPTION_TOTAL_INVALID');
		return $total;
	}
}
