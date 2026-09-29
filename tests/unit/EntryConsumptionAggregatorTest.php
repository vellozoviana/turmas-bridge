<?php
declare(strict_types=1);
namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Capacity\Capacity_Integrity_Exception;
use TurmasBridge\Inventory\Entry_Consumption_Aggregator;

final class EntryConsumptionAggregatorTest extends TestCase {
	private const VALUE = '2095:UNIT:01.01';
	private function row(int|string $id, int|string $quantity, int|string $malformed = 0): array { return array('entry_id' => $id, 'class_choice' => self::VALUE, 'consumed_quantity' => $quantity, 'malformed_quantity_count' => $malformed); }
	private function reps(array ...$rows): array { return array_map(static fn (array $items): array => array('choice_value' => self::VALUE, 'rows' => $items), $rows); }
	public function test_one_entry_consumes_one(): void { self::assertSame(1, Entry_Consumption_Aggregator::aggregate($this->reps(array($this->row(100, 1))))); }
	public function test_one_entry_quantity_three_is_preserved(): void { self::assertSame(3, Entry_Consumption_Aggregator::aggregate($this->reps(array($this->row(100, 3))))); }
	public function test_same_entry_quantity_three_across_representations_counts_once(): void { self::assertSame(3, Entry_Consumption_Aggregator::aggregate($this->reps(array($this->row(100, 3)), array($this->row(100, 3))))); }
	public function test_distinct_entries_quantities_two_and_three_sum_to_five(): void { self::assertSame(5, Entry_Consumption_Aggregator::aggregate($this->reps(array($this->row(100, 2), $this->row(101, 3))))); }
	public function test_quantity_disagreement_for_same_entry_fails_closed(): void { $this->expectException(Capacity_Integrity_Exception::class); Entry_Consumption_Aggregator::aggregate($this->reps(array($this->row(100, 3)), array($this->row(100, 2)))); }
	public function test_malformed_quantity_fails_closed(): void { $this->expectException(Capacity_Integrity_Exception::class); Entry_Consumption_Aggregator::aggregate($this->reps(array($this->row(100, 'not-a-number', 1)))); }
	public function test_missing_vendor_quantity_fails_closed(): void { $this->expectException(Capacity_Integrity_Exception::class); Entry_Consumption_Aggregator::aggregate($this->reps(array(array('entry_id' => 100, 'class_choice' => self::VALUE)))); }
	public function test_malformed_entry_identity_fails_closed(): void { $this->expectException(Capacity_Integrity_Exception::class); Entry_Consumption_Aggregator::aggregate($this->reps(array($this->row('bad', 1)))); }
}
