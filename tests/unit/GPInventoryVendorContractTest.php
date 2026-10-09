<?php
declare(strict_types=1);
namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Runs licensed vendor source in a separate PHP process, without WordPress or a database. */
final class GPInventoryVendorContractTest extends TestCase {
	/** @dataProvider contracts */
	public function test_real_vendor_contract(string $contract): void {
		$package = getenv('TURMAS_BRIDGE_GP_INVENTORY_PACKAGE');
		if ($package === false || $package === '') self::markTestSkipped('Provide the authorized GP Inventory 1.0.32 ZIP to run vendor contracts.');
		self::assertFileExists($package);
		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../vendor-contract/gp-inventory.php') . ' ' . escapeshellarg($package) . ' ' . escapeshellarg($contract);
		$output = array(); $status = 0;
		exec($command . ' 2>&1', $output, $status);
		self::assertSame(0, $status, implode("\n", $output));
		$result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('1.0.32', $result['version']);
		self::assertSame($contract, $result['contract']);
		self::assertGreaterThan(0, $result['checks']);
	}
	public static function contracts(): iterable {
		foreach (array('resource', 'bindings', 'shared_query', 'fallback', 'quantity', 'cleanup', 'cache', 'multi_cre', 'adapter', 'synchronize') as $contract) yield $contract => array($contract);
	}
}
