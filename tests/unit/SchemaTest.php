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
		$GLOBALS['wpdb'] = new \wpdb();
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
		self::assertSame(Schema::VERSION, $GLOBALS['turmas_bridge_test_options'][Schema::OPTION_NAME]);
	}

	public function test_repeated_upgrade_is_idempotent_and_old_rows_default_to_succeeded(): void {
		Schema::install();
		Schema::install();
		$state_updates = array_filter($GLOBALS['turmas_bridge_test_database_queries'], static fn (string $query): bool => str_contains($query, "SET state = 'SUCCEEDED'"));

		self::assertCount(2, $state_updates);
		self::assertCount(6, $GLOBALS['turmas_bridge_test_dbdelta']);
	}

	private function idempotency_statement(): string {
		foreach ($GLOBALS['turmas_bridge_test_dbdelta'] as $statement) if (str_contains($statement, 'turmas_bridge_idempotency')) return $statement;
		self::fail('Schema de idempotência não encontrado.');
	}
	private function inventory_statement(): string {
		foreach ($GLOBALS['turmas_bridge_test_dbdelta'] as $statement) if (str_contains($statement, 'turmas_bridge_inventory_resources')) return $statement;
		self::fail('Schema de mapeamento de inventário não encontrado.');
	}
}
