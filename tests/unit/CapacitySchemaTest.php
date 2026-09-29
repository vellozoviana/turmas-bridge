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
		self::assertCount(4, $GLOBALS['turmas_bridge_test_dbdelta']);
		self::assertSame($GLOBALS['turmas_bridge_test_dbdelta'][0], $GLOBALS['turmas_bridge_test_dbdelta'][2]);
		self::assertSame($GLOBALS['turmas_bridge_test_dbdelta'][1], $GLOBALS['turmas_bridge_test_dbdelta'][3]);
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
		self::assertStringContainsString('expected_representations_json longtext DEFAULT NULL', $GLOBALS['turmas_bridge_test_dbdelta'][1]);
		self::assertDoesNotMatchRegularExpression('/DROP|TRUNCATE|DELETE|INSERT INTO|UPDATE /i', $sql);
	}
	/** @dataProvider failures */
	public function test_incomplete_schema_does_not_advance_version(string $failure): void {
		$GLOBALS['turmas_bridge_test_options'][Schema::OPTION_NAME] = '0.8.0';
		$GLOBALS['wpdb']->failure = $failure;
		Schema::install(); self::assertSame('0.8.0', get_option(Schema::OPTION_NAME));
	}
	public static function failures(): array { return array(array('engine'), array('missing-column'), array('wrong-type'), array('wrong-nullability'), array('wrong-index-columns'), array('missing-unique'), array('unexpected-unique-index'), array('error')); }
	public function test_benign_additional_index_does_not_block_schema_readiness(): void {
		$GLOBALS['turmas_bridge_test_options'][Schema::OPTION_NAME] = '0.8.0';
		$GLOBALS['wpdb']->failure = 'extra-index';
		Schema::install();
		self::assertSame('0.9.0', get_option(Schema::OPTION_NAME));
	}
}
final class CapacitySchemaDatabase extends \wpdb {
	public string $failure = '';
	public array $writes = array();
	public function prepare(string $query, mixed ...$arguments): string { return str_replace('%s', "'" . (string) ($arguments[0] ?? '') . "'", $query); }
	public function get_var(string $sql): mixed {
		if ($this->failure === 'error') { $this->last_error = 'synthetic schema failure'; return null; }
		if (str_contains($sql, 'information_schema.TABLES')) return $this->failure === 'engine' ? null : 'InnoDB';
		return null;
	}
	public function get_results(string $sql, string $output = ''): array {
		if ($this->failure === 'error') { $this->last_error = 'synthetic schema failure'; return array(); }
		if (str_contains($sql, 'information_schema.COLUMNS')) {
			$table = str_contains($sql, 'turmas_bridge_inventory_resources') ? 'inventory' : 'capacity';
			$columns = $table === 'inventory' ? array('expected_representations_json' => array('longtext', true, null, '')) : array(
				'id'=>array('bigint(20) unsigned',false,null,'auto_increment'),'operation_key'=>array('varchar(80)',false,null,''),'publication_key'=>array('varchar(80)',false,null,''),'class_key'=>array('varchar(120)',false,null,''),'source_row_version'=>array('bigint(20) unsigned',false,null,''),'desired_capacity'=>array('int(10) unsigned',false,null,''),'command_json'=>array('longtext',false,null,''),'payload_hash'=>array('char(64)',false,null,''),'state'=>array('varchar(32)',false,null,''),'attempts'=>array('int(10) unsigned',false,'0',''),'revision'=>array('bigint(20) unsigned',false,'1',''),'capacity_before'=>array('int(10) unsigned',true,null,''),'consumed_before'=>array('int(10) unsigned',true,null,''),'capacity_after'=>array('int(10) unsigned',true,null,''),'consumed_after'=>array('int(10) unsigned',true,null,''),'error_code'=>array('varchar(100)',true,null,''),'evidence_json'=>array('longtext',true,null,''),'created_at'=>array('datetime',false,null,''),'updated_at'=>array('datetime',false,null,'')
			);
			if ($table === 'capacity' && $this->failure === 'missing-column') unset($columns['class_key']);
			if ($table === 'capacity' && $this->failure === 'wrong-type') $columns['desired_capacity'][0] = 'int(10)';
			if ($table === 'capacity' && $this->failure === 'wrong-nullability') $columns['state'][1] = true;
			$rows = array();
			foreach ($columns as $name => [$type, $nullable, $default, $extra]) $rows[] = array('COLUMN_NAME'=>$name,'COLUMN_TYPE'=>$type,'IS_NULLABLE'=>$nullable?'YES':'NO','COLUMN_DEFAULT'=>$default,'EXTRA'=>$extra);
			return $rows;
		}
		if (str_contains($sql, 'information_schema.STATISTICS')) {
			$indexes = array('PRIMARY'=>array(true,array('id')),'operation_key'=>array(true,array('operation_key')),'class_version'=>array(true,array('class_key','source_row_version')),'publication_key'=>array(false,array('publication_key')));
			if ($this->failure === 'extra-index') $indexes['benign_lookup'] = array(false, array('updated_at'));
			if ($this->failure === 'unexpected-unique-index') $indexes['unexpected_unique'] = array(true, array('payload_hash'));
			if ($this->failure === 'wrong-index-columns') $indexes['class_version'][1] = array('source_row_version','class_key');
			if ($this->failure === 'missing-unique') $indexes['operation_key'][0] = false;
			$rows = array();
			foreach ($indexes as $name => [$unique,$index_columns]) foreach ($index_columns as $sequence => $column) $rows[] = array('INDEX_NAME'=>$name,'NON_UNIQUE'=>$unique?0:1,'SEQ_IN_INDEX'=>$sequence+1,'COLUMN_NAME'=>$column);
			return $rows;
		}
		return array();
	}
	public function query(string $sql): int|false { $this->writes[] = $sql; return 1; }
}
