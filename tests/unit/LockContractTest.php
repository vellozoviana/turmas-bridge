<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Inventory\Inventory_Mapping_Repository;
use TurmasBridge\Inventory\Resource_Identity;
use TurmasBridge\Materialization\Materialization_Repository;

final class LockContractTest extends TestCase {
	public function test_inventory_lock_names_are_bounded_and_distinct_for_different_valid_class_keys(): void {
		$repository = new Inventory_Mapping_Repository(new Lock_Test_Database());
		$method = new \ReflectionMethod($repository, 'lock_name');
		$method->setAccessible(true);
		$first = (string) $method->invoke($repository, Resource_Identity::from_class_key('2099:' . str_repeat('A', 50) . ':01.01'));
		$second = (string) $method->invoke($repository, Resource_Identity::from_class_key('2099:' . str_repeat('B', 50) . ':01.01'));

		self::assertLessThanOrEqual(64, strlen($first));
		self::assertLessThanOrEqual(64, strlen($second));
		self::assertNotSame($first, $second);
	}

	public function test_materialization_choice_lock_names_are_bounded_and_distinct(): void {
		$repository = new Materialization_Repository(new Lock_Test_Database());
		$method = new \ReflectionMethod($repository, 'choice_lock_name');
		$method->setAccessible(true);
		$first = (string) $method->invoke($repository, '2099:' . str_repeat('A', 50));
		$second = (string) $method->invoke($repository, '2099:' . str_repeat('B', 50));

		self::assertLessThanOrEqual(64, strlen($first));
		self::assertLessThanOrEqual(64, strlen($second));
		self::assertNotSame($first, $second);
	}
}

final class Lock_Test_Database extends \wpdb {}
