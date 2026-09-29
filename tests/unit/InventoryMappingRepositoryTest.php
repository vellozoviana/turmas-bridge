<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Inventory\Inventory_Mapping_Repository;
use TurmasBridge\Inventory\Resource_Identity;
use TurmasBridge\Inventory\Resource_Plan;
use TurmasBridge\Inventory\Resource_Representation;

final class InventoryMappingRepositoryTest extends TestCase {
	public function test_expected_representation_reference_survives_repository_restart(): void {
		$db = new InventoryMappingReferenceDatabase();
		$identity = Resource_Identity::from_class_key('2099:UNIT:01.01');
		$plan = new Resource_Plan($identity, 5, array(
			new Resource_Representation('01', '4', $identity->class_key(), $identity),
			new Resource_Representation('02', '8', $identity->class_key(), $identity),
		), 42);
		self::assertTrue((new Inventory_Mapping_Repository($db))->healthy($identity, 42, $plan));

		// A newly constructed repository reads only the durable row, not process memory.
		$reloaded = (new Inventory_Mapping_Repository($db))->find($identity);
		self::assertIsArray($reloaded);
		self::assertSame(array_map(static fn (Resource_Representation $representation): array => $representation->to_array(), $plan->representations()), json_decode((string) $reloaded['expected_representations_json'], true));
	}
}

final class InventoryMappingReferenceDatabase extends \wpdb {
	public array $row = array('class_key' => '2099:UNIT:01.01');
	public function update(string $table, array $data, array $where): int|false { $this->row = array_merge($this->row, $data); return 1; }
	public function get_row(string $query, string $output = ''): mixed { return $this->row; }
}
