<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Database\Schema;

final class SchemaTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['turmas_bridge_test_dbdelta'] = array();
		$GLOBALS['turmas_bridge_test_database_queries'] = array();
		$GLOBALS['turmas_bridge_test_options'] = array();
		$GLOBALS['wpdb'] = new Schema_Install_Database();
	}

	public function test_fresh_install_creates_the_crash_safe_idempotency_schema(): void {
		Schema::install();
		$statement = $this->idempotency_statement();

		self::assertStringContainsString("state varchar(32) NOT NULL DEFAULT 'SUCCEEDED'", $statement);
		self::assertStringContainsString('publication_key varchar(80) DEFAULT NULL', $statement);
		self::assertStringContainsString('processing_started_at datetime DEFAULT NULL', $statement);
		self::assertStringContainsString('reconciliation_at datetime DEFAULT NULL', $statement);
		self::assertStringContainsString('UNIQUE KEY idempotency_key (idempotency_key)', $statement);
		self::assertStringContainsString('UNIQUE KEY class_key (class_key)', $this->inventory_statement());
		self::assertStringContainsString('resource_id bigint(20) unsigned DEFAULT NULL', $this->inventory_statement());
		self::assertStringContainsString('UNIQUE KEY uq_resource_id (resource_id)', $this->inventory_statement());
		self::assertStringContainsString('UNIQUE KEY operation_key (operation_key)', $this->activation_statement());
		self::assertStringContainsString('snapshot_fingerprint char(64) NOT NULL', $this->activation_statement());
		self::assertStringContainsString('KEY publication_key (publication_key)', $this->activation_statement());
		self::assertStringContainsString('UNIQUE KEY reconciliation_key (reconciliation_key)', $this->reconciliation_statement());
		self::assertStringContainsString('completed_at datetime DEFAULT NULL', $this->reconciliation_statement());
		self::assertStringContainsString('KEY activation_operation_key (activation_operation_key)', $this->reconciliation_statement());
		self::assertSame(Schema::VERSION, $GLOBALS['turmas_bridge_test_options'][Schema::OPTION_NAME]);
	}

	public function test_repeated_upgrade_is_idempotent_and_old_rows_default_to_succeeded(): void {
		Schema::install();
		Schema::install();
		$state_updates = array_filter($GLOBALS['turmas_bridge_test_database_queries'], static fn (string $query): bool => str_contains($query, "SET state = 'SUCCEEDED'"));

		self::assertCount(1, $state_updates);
		self::assertCount(8, $GLOBALS['turmas_bridge_test_dbdelta']);
		self::assertStringContainsString('UNIQUE KEY class_version (class_key,source_row_version)', Schema::capacity_statement('wp_', ''));
		self::assertStringContainsString('ENGINE=InnoDB', Schema::capacity_statement('wp_', ''));
	}

	private function idempotency_statement(): string {
		foreach ($GLOBALS['turmas_bridge_test_dbdelta'] as $statement) if (str_contains($statement, 'turmas_bridge_idempotency')) return $statement;
		self::fail('Schema de idempotência não encontrado.');
	}
	private function inventory_statement(): string {
		foreach ($GLOBALS['turmas_bridge_test_dbdelta'] as $statement) if (str_contains($statement, 'turmas_bridge_inventory_resources')) return $statement;
		self::fail('Schema de mapeamento de inventário não encontrado.');
	}
	private function activation_statement(): string {
		foreach ($GLOBALS['turmas_bridge_test_dbdelta'] as $statement) if (str_contains($statement, 'turmas_bridge_activation_operations')) return $statement;
		self::fail('Ledger de ativação não encontrado.');
	}
	private function reconciliation_statement(): string {
		foreach ($GLOBALS['turmas_bridge_test_dbdelta'] as $statement) if (str_contains($statement, 'turmas_bridge_reconciliation_attempts')) return $statement;
		self::fail('Schema de reconciliação não encontrado.');
	}
}
final class Schema_Install_Database extends \wpdb {
	public function prepare(string $query, mixed ...$arguments): string { return str_replace('%s', "'" . (string) ($arguments[0] ?? '') . "'", $query); }
	public function get_results(string $query, string $output = ''): array {
		if (str_contains($query, 'information_schema.COLUMNS')) {
			$columns = array(
				'id'=>array('bigint(20) unsigned',false,null,'auto_increment'),'operation_key'=>array('varchar(80)',false,null,''),'publication_key'=>array('varchar(80)',false,null,''),'class_key'=>array('varchar(120)',false,null,''),'source_row_version'=>array('bigint(20) unsigned',false,null,''),'desired_capacity'=>array('int(10) unsigned',false,null,''),'command_json'=>array('longtext',false,null,''),'payload_hash'=>array('char(64)',false,null,''),'state'=>array('varchar(32)',false,null,''),'attempts'=>array('int(10) unsigned',false,'0',''),'revision'=>array('bigint(20) unsigned',false,'1',''),'capacity_before'=>array('int(10) unsigned',true,null,''),'consumed_before'=>array('int(10) unsigned',true,null,''),'capacity_after'=>array('int(10) unsigned',true,null,''),'consumed_after'=>array('int(10) unsigned',true,null,''),'error_code'=>array('varchar(100)',true,null,''),'evidence_json'=>array('longtext',true,null,''),'created_at'=>array('datetime',false,null,''),'updated_at'=>array('datetime',false,null,'')
			);
			if (str_contains($query, 'turmas_bridge_inventory_resources')) $columns = array('expected_representations_json'=>array('longtext',true,null,''));
			$rows = array(); foreach ($columns as $name => [$type,$nullable,$default,$extra]) $rows[] = array('COLUMN_NAME'=>$name,'COLUMN_TYPE'=>$type,'IS_NULLABLE'=>$nullable?'YES':'NO','COLUMN_DEFAULT'=>$default,'EXTRA'=>$extra);
			return $rows;
		}
		if (str_contains($query, 'information_schema.STATISTICS')) {
			$indexes=array('PRIMARY'=>array(true,array('id')),'operation_key'=>array(true,array('operation_key')),'class_version'=>array(true,array('class_key','source_row_version')),'publication_key'=>array(false,array('publication_key')));
			$rows=array(); foreach($indexes as $name=>[$unique,$cols]) foreach($cols as $i=>$column) $rows[]=array('INDEX_NAME'=>$name,'NON_UNIQUE'=>$unique?0:1,'SEQ_IN_INDEX'=>$i+1,'COLUMN_NAME'=>$column);
			return $rows;
		}
		return array();
	}
}
