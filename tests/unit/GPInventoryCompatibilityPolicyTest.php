<?php
declare(strict_types=1);
namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Inventory\GP_Inventory_Compatibility_Policy;

final class GPInventoryCompatibilityPolicyTest extends TestCase {
	/** @dataProvider validated_versions */
	public function test_only_validated_versions_are_supported(string $version): void { self::assertTrue((new GP_Inventory_Compatibility_Policy($version, false))->is_supported()); }
	public static function validated_versions(): array { return array(array('1.0.29'), array('1.0.32')); }
	/** @dataProvider unsupported_versions */
	public function test_unvalidated_versions_fail_closed(?string $version): void { self::assertFalse((new GP_Inventory_Compatibility_Policy($version, false))->is_supported()); }
	public static function unsupported_versions(): array { return array(array('1.0.30'), array('1.0.31'), array('1.0.33'), array('1.0.28'), array(null), array('not-a-version')); }
}
