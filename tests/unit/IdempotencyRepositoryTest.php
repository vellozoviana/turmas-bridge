<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Publications\Idempotency_Repository;
use TurmasBridge\Publications\Publication_Command_Store;

final class IdempotencyRepositoryTest extends TestCase {
	public function test_atomic_insert_reserves_once_and_duplicate_reads_existing_hash(): void {
		$database = new Idempotency_Test_Database(); $repository = new Idempotency_Repository($database);
		$first = $repository->reserve('idempotency-repository-01', str_repeat('a', 64), '2027:MT1');
		$again = $repository->reserve('idempotency-repository-01', str_repeat('a', 64), '2027:MT1');

		self::assertSame(Publication_Command_Store::ACQUIRED, $first['result']);
		self::assertSame(Publication_Command_Store::EXISTING_SAME_HASH, $again['result']);
		self::assertSame(2, $database->inserts); self::assertSame(Publication_Command_Store::RESERVED, $database->records['idempotency-repository-01']['state']);
	}

	public function test_duplicate_key_with_another_hash_is_conflict_without_overwriting_the_record(): void {
		$database = new Idempotency_Test_Database(); $repository = new Idempotency_Repository($database);
		$repository->reserve('idempotency-repository-02', str_repeat('a', 64), '2027:MT1');
		$result = $repository->reserve('idempotency-repository-02', str_repeat('b', 64), '2027:MT2');

		self::assertSame(Publication_Command_Store::EXISTING_DIFFERENT_HASH, $result['result']);
		self::assertSame(str_repeat('a', 64), $database->records['idempotency-repository-02']['payload_hash']);
	}

	public function test_storage_failure_fails_closed_when_no_duplicate_record_can_be_read(): void {
		$database = new Idempotency_Test_Database(); $database->fail_insert = true;
		$result = (new Idempotency_Repository($database))->reserve('idempotency-repository-03', str_repeat('a', 64), '2027:MT1');

		self::assertSame(Publication_Command_Store::STORAGE_FAILURE, $result['result']);
	}

	public function test_begin_and_recovery_use_compare_and_set_state_transitions(): void {
		$database = new Idempotency_Test_Database(); $repository = new Idempotency_Repository($database); $hash = str_repeat('a', 64);
		$repository->reserve('idempotency-repository-04', $hash, '2027:MT1');
		$record = $repository->find('idempotency-repository-04');

		self::assertSame(Publication_Command_Store::ACQUIRED, $repository->recover_reserved('idempotency-repository-04', $hash, (string) $record['updated_at'])['result']);
		self::assertSame(Publication_Command_Store::ACQUIRED, $repository->begin_materialization('idempotency-repository-04', $hash, '2027:MT1')['result']);
		self::assertSame(Publication_Command_Store::MATERIALIZING, $database->records['idempotency-repository-04']['state']);
	}
}

final class Idempotency_Test_Database extends \wpdb {
	/** @var array<string,array<string,mixed>> */ public array $records = array();
	/** @var list<mixed> */ private array $arguments = array();
	public int $inserts = 0; public bool $fail_insert = false; public bool $fail_query = false;
	public function prepare(string $query, mixed ...$arguments): string { $this->arguments = $arguments; return $query; }
	public function insert(string $table, array $data): int|false { $this->inserts++; if ($this->fail_insert || isset($this->records[(string) $data['idempotency_key']])) return false; $this->records[(string) $data['idempotency_key']] = $data; return 1; }
	public function get_row(string $query, string $output = ''): mixed { $key = (string) ($this->arguments[0] ?? ''); return $this->records[$key] ?? null; }
	public function query(string $query): int|false {
		if ($this->fail_query) return false;
		$args = $this->arguments;
		if (str_contains($query, 'SET updated_at = %s, last_error_code = NULL')) { $record = $this->records[(string) $args[1]] ?? null; if (! is_array($record) || (string) $record['payload_hash'] !== (string) $args[2] || (string) $record['state'] !== (string) $args[3] || (string) $record['updated_at'] !== (string) $args[4]) return 0; $this->records[(string) $args[1]] = array_merge($record, array('updated_at' => $args[0], 'last_error_code' => null)); return 1; }
		if (str_contains($query, "last_error_code = %s") && str_contains($query, 'turmas_bridge_stale_materializing')) return 0;
		if (str_contains($query, 'SET state = %s, publication_key = %s')) return $this->transition((string) $args[4], (string) $args[5], (string) $args[6], (string) $args[6], array('state' => $args[0], 'publication_key' => $args[1], 'processing_started_at' => $args[2], 'updated_at' => $args[3]));
		if (str_contains($query, 'SET state = %s, response_status = %d, response_body = %s')) return $this->transition((string) $args[4], (string) $args[5], (string) $args[6], (string) $args[6], array('state' => $args[0], 'response_status' => $args[1], 'response_body' => $args[2], 'updated_at' => $args[3]));
		return 0;
	}
	/** @param array<string,mixed> $changes */
	private function transition(string $key, string $hash, string $expected_state, string $unused, array $changes): int { $record = $this->records[$key] ?? null; if (! is_array($record) || (string) $record['payload_hash'] !== $hash || (string) $record['state'] !== $expected_state) return 0; $this->records[$key] = array_merge($record, $changes); return 1; }
}
