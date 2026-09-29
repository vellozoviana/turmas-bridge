<?php
declare(strict_types=1);
namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Inventory\GP_Inventory_Compatibility_Policy;

final class GPInventoryCompatibilityPolicyTest extends TestCase {
	public function test_only_validated_version_is_supported(): void { self::assertTrue((new GP_Inventory_Compatibility_Policy('1.0.29', false))->is_supported()); }
	/** @dataProvider unsupported_versions */
	public function test_unvalidated_versions_fail_closed(?string $version): void { self::assertFalse((new GP_Inventory_Compatibility_Policy($version, false))->is_supported()); }
	public static function unsupported_versions(): array { return array(array('1.0.30'), array('1.0.28'), array(null), array('not-a-version')); }
}
