<?php
declare(strict_types=1);
namespace TurmasBridge\Tests\Capacity;
use PHPUnit\Framework\TestCase;
use TurmasBridge\Database\Schema;

/** Offline migration contract with wpdb/dbDelta doubles; not a physical MySQL migration. */
final class CapacitySchemaTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new CapacitySchemaDatabase();
		$GLOBALS['turmas_bridge_test_options'] = array();
		$GLOBALS['turmas_bridge_test_dbdelta'] = array();
	}
	public function test_clean_install_and_repeated_upgrade_preserve_legacy_data_contract(): void {
		Schema::install();
		self::assertSame('0.9.0', get_option(Schema::OPTION_NAME));
		self::assertGreaterThan(1, count($GLOBALS['turmas_bridge_test_dbdelta']));
		$GLOBALS['turmas_bridge_test_options'][Schema::OPTION_NAME] = '0.8.0';
		$GLOBALS['turmas_bridge_test_dbdelta'] = array(); $GLOBALS['wpdb']->writes = array();
		Schema::install(); Schema::install();
		self::assertSame('0.9.0', get_option(Schema::OPTION_NAME));
		self::assertCount(2, $GLOBALS['turmas_bridge_test_dbdelta']);
		self::assertSame($GLOBALS['turmas_bridge_test_dbdelta'][0], $GLOBALS['turmas_bridge_test_dbdelta'][1]);
		self::assertSame(array(), $GLOBALS['wpdb']->writes, 'Upgrade must not issue legacy DML/ALTER.');
		$sql = $GLOBALS['turmas_bridge_test_dbdelta'][0];
		self::assertStringContainsString('CREATE TABLE wp_turmas_bridge_capacity_operations', $sql);
		self::assertStringContainsString('ENGINE=InnoDB', $sql);
		self::assertStringContainsString('state varchar(32) NOT NULL', $sql);
		self::assertStringContainsString('attempts int(10) unsigned NOT NULL DEFAULT 0', $sql);
		self::assertStringContainsString('revision bigint(20) unsigned NOT NULL DEFAULT 1', $sql);
		self::assertStringContainsString('created_at datetime NOT NULL', $sql);
		self::assertStringContainsString('updated_at datetime NOT NULL', $sql);
		self::assertStringContainsString('error_code varchar(100) DEFAULT NULL', $sql);
		self::assertStringContainsString('UNIQUE KEY operation_key (operation_key)', $sql);
		self::assertDoesNotMatchRegularExpression('/DROP|TRUNCATE|DELETE|INSERT INTO|UPDATE /i', $sql);
	}
	/** @dataProvider failures */
	public function test_incomplete_schema_does_not_advance_version(string $failure): void {
		$GLOBALS['turmas_bridge_test_options'][Schema::OPTION_NAME] = '0.8.0';
		$GLOBALS['wpdb']->failure = $failure;
		Schema::install(); self::assertSame('0.8.0', get_option(Schema::OPTION_NAME));
	}
	public static function failures(): array { return array(array('engine'), array('columns'), array('indexes'), array('error')); }
}
final class CapacitySchemaDatabase extends \wpdb {
	public string $failure = '';
	public array $writes = array();
	public function get_var(string $sql): mixed {
		if ($this->failure === 'error') { $this->last_error = 'synthetic schema failure'; return null; }
		if (str_contains($sql, 'information_schema.TABLES')) return $this->failure === 'engine' ? null : 'InnoDB';
		if (str_contains($sql, 'information_schema.COLUMNS')) return $this->failure === 'columns' ? 0 : 19;
		if (str_contains($sql, 'information_schema.STATISTICS')) return $this->failure === 'indexes' ? 0 : 4;
		return null;
	}
	public function query(string $sql): int|false { $this->writes[] = $sql; return 1; }
}
