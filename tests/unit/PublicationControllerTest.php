<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Publications\Publication_Command_Store;
use TurmasBridge\Publications\Publication_Controller;
use TurmasBridge\Materialization\Publication_Materializer;
use TurmasBridge\Choices\Publication_Choice_Preparer;
use TurmasBridge\Inventory\Publication_Inventory_Preparer;

final class PublicationControllerTest extends TestCase {
	protected function setUp(): void { $GLOBALS['turmas_bridge_publication_order'] = array(); }

	public function test_valid_command_is_accepted_then_replayed_without_reprocessing(): void {
		$store = new Memory_Command_Store();
		$controller = new Publication_Controller($store, new Fake_Materializer(), new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer());
		$first = $controller->receive($this->request($this->payload()));
		$again = $controller->receive($this->request($this->payload()));
		self::assertSame(201, $first->get_status());
		self::assertFalse($first->get_data()['idempotent_replay']);
		self::assertTrue($again->get_data()['idempotent_replay']);
		self::assertSame(1, $store->writes);
		self::assertSame(array('reserve', 'begin', 'materialize', 'choices', 'inventory', 'succeed', 'reserve'), $GLOBALS['turmas_bridge_publication_order']);
	}

	public function test_same_key_with_different_payload_is_conflict(): void {
		$store = new Memory_Command_Store();
		$controller = new Publication_Controller($store, new Fake_Materializer(), new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer());
		$controller->receive($this->request($this->payload()));
		$changed = $this->payload(); $changed['classes'][0]['capacity'] = 31;
		$result = $controller->receive($this->request($changed));
		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_idempotency_conflict', $result->get_error_code());
		self::assertSame(409, $result->get_error_data()['status']);
	}

	/** @dataProvider invalid_payloads */
	public function test_invalid_contract_is_rejected(array $payload, string $expected): void {
		$result = (new Publication_Controller(new Memory_Command_Store(), new Fake_Materializer(), new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer()))->receive($this->request($payload, 'idempotency-invalid-0001'));
		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame($expected, $result->get_error_code());
	}

	/** @return iterable<string, array{array<string,mixed>,string}> */
	public static function invalid_payloads(): iterable {
		$base = self::base_payload();
		$schema = $base; $schema['schema_version'] = '9'; yield 'schema' => array($schema, 'turmas_bridge_invalid_schema_version');
		$key = $base; $key['publication']['publication_key'] = 'broken'; yield 'publication key' => array($key, 'turmas_bridge_invalid_publication_key');
		$duplicate = $base; $duplicate['classes'][] = $base['classes'][0]; yield 'duplicate class' => array($duplicate, 'turmas_bridge_invalid_class_key');
		$capacity = $base; $capacity['classes'][0]['capacity'] = 0; yield 'capacity' => array($capacity, 'turmas_bridge_invalid_capacity');
		$cres = $base; $cres['classes'][0]['cres'] = array('01', '01'); yield 'duplicate cres' => array($cres, 'turmas_bridge_invalid_cres');
		$cycles = $base; array_pop($cycles['classes'][0]['cycles']); yield 'cycles' => array($cycles, 'turmas_bridge_invalid_cycles');
		$date = $base; $date['classes'][0]['cycles'][0] = '2027-02-30'; yield 'date' => array($date, 'turmas_bridge_invalid_cycles');
		$class = $base; $class['classes'][0]['class_key'] = '2027:MT1:99.99'; yield 'class key' => array($class, 'turmas_bridge_invalid_class_key');
	}

	public function test_missing_key_and_invalid_json_are_controlled_errors(): void {
		$missing = new \WP_REST_Request('POST', '/turmas-bridge/v1/publicacoes');
		$missing->set_body('{}');
		self::assertSame('turmas_bridge_idempotency_key_required', (new Publication_Controller(new Memory_Command_Store(), new Fake_Materializer(), new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer()))->receive($missing)->get_error_code());
		$invalid = new \WP_REST_Request('POST', '/turmas-bridge/v1/publicacoes');
		$invalid->set_header('Idempotency-Key', 'idempotency-json-00001'); $invalid->set_body('{');
		self::assertSame('turmas_bridge_invalid_json', (new Publication_Controller(new Memory_Command_Store(), new Fake_Materializer(), new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer()))->receive($invalid)->get_error_code());
	}

	public function test_processing_record_does_not_start_a_second_materialization(): void {
		$store = new Memory_Command_Store(); $payload = $this->payload(); $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES); $hash = hash('sha256', $body);
		$store->records['idempotency-processing-01'] = array('idempotency_key' => 'idempotency-processing-01', 'payload_hash' => $hash, 'state' => Publication_Command_Store::MATERIALIZING, 'updated_at' => '2025-10-09 08:53:20', 'response_status' => 202, 'response_body' => '{"status":"processing"}');
		$materializer = new Fake_Materializer();
		$result = (new Publication_Controller($store, $materializer, new Fake_Choice_Preparer(), static fn (): int => 1760000000, new Fake_Inventory_Preparer()))->receive($this->request($payload, 'idempotency-processing-01'));

		self::assertSame(202, $result->get_status()); self::assertSame(0, $materializer->calls);
	}

	public function test_stale_reserved_is_recovered_by_compare_and_set_before_materialization(): void {
		$store = new Memory_Command_Store(); $payload = $this->payload(); $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES); $hash = hash('sha256', $body);
		$store->records['idempotency-stale-reserved'] = array('idempotency_key' => 'idempotency-stale-reserved', 'payload_hash' => $hash, 'state' => Publication_Command_Store::RESERVED, 'updated_at' => '2025-10-09 08:50:00', 'response_status' => 202, 'response_body' => '{"status":"processing"}');
		$materializer = new Fake_Materializer();
		$result = (new Publication_Controller($store, $materializer, new Fake_Choice_Preparer(), static fn (): int => 1760000000, new Fake_Inventory_Preparer()))->receive($this->request($payload, 'idempotency-stale-reserved'));

		self::assertSame(201, $result->get_status()); self::assertSame(1, $materializer->calls); self::assertContains('recover_reserved', $store->events);
	}

	public function test_ambiguous_materialization_failure_requires_reconciliation_and_never_retries(): void {
		$store = new Memory_Command_Store(); $materializer = new Fake_Materializer(); $materializer->error = new \WP_Error('turmas_bridge_clone_outcome_unknown', 'Erro fictício.');
		$controller = new Publication_Controller($store, $materializer, new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer()); $key = 'idempotency-ambiguous-01';

		self::assertSame('turmas_bridge_reconciliation_required', $controller->receive($this->request($this->payload(), $key))->get_error_code());
		self::assertSame('turmas_bridge_reconciliation_required', $controller->receive($this->request($this->payload(), $key))->get_error_code());
		self::assertSame(1, $materializer->calls);
	}

	public function test_safe_pre_side_effect_error_returns_to_reserved_for_a_later_retry(): void {
		$store = new Memory_Command_Store(); $materializer = new Fake_Materializer(); $materializer->error = new \WP_Error('turmas_bridge_gravity_forms_unavailable', 'Indisponível.');
		$controller = new Publication_Controller($store, $materializer, new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer()); $key = 'idempotency-safe-retry-01';

		self::assertSame('turmas_bridge_gravity_forms_unavailable', $controller->receive($this->request($this->payload(), $key))->get_error_code());
		$materializer->error = null;
		self::assertSame(201, $controller->receive($this->request($this->payload(), $key))->get_status());
		self::assertSame(2, $materializer->calls);
	}

	public function test_materializing_write_failure_does_not_start_the_side_effect(): void {
		$store = new Memory_Command_Store(); $store->fail_begin = true; $materializer = new Fake_Materializer();
		$result = (new Publication_Controller($store, $materializer, new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer()))->receive($this->request($this->payload(), 'idempotency-begin-failure'));

		self::assertSame('turmas_bridge_command_store_failed', $result->get_error_code()); self::assertSame(0, $materializer->calls);
	}

	public function test_stale_materializing_becomes_reconciliation_required_without_a_clone(): void {
		$store = new Memory_Command_Store(); $payload = $this->payload(); $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES); $hash = hash('sha256', $body);
		$store->records['idempotency-stale-materializing'] = array('idempotency_key' => 'idempotency-stale-materializing', 'payload_hash' => $hash, 'state' => Publication_Command_Store::MATERIALIZING, 'updated_at' => '2025-10-09 08:50:00', 'response_status' => 202, 'response_body' => '{"status":"processing"}');
		$materializer = new Fake_Materializer();
		$result = (new Publication_Controller($store, $materializer, new Fake_Choice_Preparer(), static fn (): int => 1760000000, new Fake_Inventory_Preparer()))->receive($this->request($payload, 'idempotency-stale-materializing'));

		self::assertSame('turmas_bridge_reconciliation_required', $result->get_error_code()); self::assertSame(0, $materializer->calls); self::assertSame(Publication_Command_Store::RECONCILIATION_REQUIRED, $store->records['idempotency-stale-materializing']['state']);
	}

	public function test_succeeded_persistence_failure_requires_reconciliation_without_retrying(): void {
		$store = new Memory_Command_Store(); $store->fail_succeed = true; $materializer = new Fake_Materializer(); $key = 'idempotency-success-store-fail';
		$controller = new Publication_Controller($store, $materializer, new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer());

		self::assertSame('turmas_bridge_reconciliation_required', $controller->receive($this->request($this->payload(), $key))->get_error_code());
		self::assertSame('turmas_bridge_reconciliation_required', $controller->receive($this->request($this->payload(), $key))->get_error_code());
		self::assertSame(1, $materializer->calls);
	}

	public function test_ten_same_key_retries_replay_one_succeeded_materialization(): void {
		$store = new Memory_Command_Store(); $materializer = new Fake_Materializer(); $controller = new Publication_Controller($store, $materializer, new Fake_Choice_Preparer(), null, new Fake_Inventory_Preparer());
		$first = $controller->receive($this->request($this->payload(), 'idempotency-ten-retries-01'));

		self::assertSame(201, $first->get_status());
		for ($attempt = 0; $attempt < 10; $attempt++) self::assertTrue($controller->receive($this->request($this->payload(), 'idempotency-ten-retries-01'))->get_data()['idempotent_replay']);
		self::assertSame(1, $materializer->calls);
	}

	/** @param array<string,mixed> $payload */
	private function request(array $payload, string $key = 'idempotency-valid-0001'): \WP_REST_Request {
		$request = new \WP_REST_Request('POST', '/turmas-bridge/v1/publicacoes');
		$request->set_header('Idempotency-Key', $key);
		$request->set_body((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
		return $request;
	}

	/** @return array<string,mixed> */
	private function payload(): array { return self::base_payload(); }
	/** @return array<string,mixed> */
	private static function base_payload(): array {
		return array('schema_version' => '1', 'publication' => array('year' => 2027, 'formation_code' => 'MT1', 'publication_key' => '2027:MT1'), 'classes' => array(array('class_key' => '2027:MT1:01.01', 'class_code' => '01.01', 'short_name' => 'MT1 01.01 SEG MANHÃ', 'full_name' => 'MT1 01.01 SEG MANHÃ - Local - Rua', 'capacity' => 30, 'cres' => array('01', '02'), 'cycles' => array('2027-03-01','2027-03-08','2027-03-15','2027-03-22','2027-03-29','2027-04-05','2027-04-12','2027-04-19'))));
	}
}

final class Memory_Command_Store implements Publication_Command_Store {
	/** @var array<string,array<string,mixed>> */ public array $records = array();
	/** @var list<string> */ public array $events = array(); public int $writes = 0; public bool $fail_begin = false; public bool $fail_succeed = false;
	public function find(string $idempotency_key): ?array { return $this->records[$idempotency_key] ?? null; }
	public function reserve(string $idempotency_key, string $payload_hash, string $publication_key): array { $this->events[] = 'reserve'; $GLOBALS['turmas_bridge_publication_order'][] = 'reserve'; if (isset($this->records[$idempotency_key])) return $this->existing($idempotency_key, $payload_hash); $this->records[$idempotency_key] = array('idempotency_key' => $idempotency_key, 'payload_hash' => $payload_hash, 'publication_key' => $publication_key, 'state' => self::RESERVED, 'updated_at' => '2025-10-09 08:53:20', 'response_status' => 202, 'response_body' => '{"status":"processing"}', 'last_error_code' => ''); return array('result' => self::ACQUIRED, 'record' => null); }
	public function begin_materialization(string $idempotency_key, string $payload_hash, string $publication_key): array { $this->events[] = 'begin'; $GLOBALS['turmas_bridge_publication_order'][] = 'begin'; if ($this->fail_begin) return array('result' => self::STORAGE_FAILURE, 'record' => null); $record = $this->records[$idempotency_key] ?? null; if (! is_array($record) || $record['payload_hash'] !== $payload_hash || $record['state'] !== self::RESERVED) return $this->existing($idempotency_key, $payload_hash); $this->records[$idempotency_key]['state'] = self::MATERIALIZING; $this->records[$idempotency_key]['updated_at'] = '2025-10-09 08:53:20'; return array('result' => self::ACQUIRED, 'record' => null); }
	public function succeed(string $idempotency_key, string $payload_hash, int $status, array $response): bool { $this->events[] = 'succeed'; $GLOBALS['turmas_bridge_publication_order'][] = 'succeed'; if ($this->fail_succeed) return false; $record = $this->records[$idempotency_key] ?? null; if (! is_array($record) || $record['payload_hash'] !== $payload_hash || $record['state'] !== self::MATERIALIZING) return false; $this->writes++; $this->records[$idempotency_key]['state'] = self::SUCCEEDED; $this->records[$idempotency_key]['response_status'] = $status; $this->records[$idempotency_key]['response_body'] = (string) json_encode($response); return true; }
	public function return_to_reserved(string $idempotency_key, string $payload_hash, string $error_code): bool { $this->events[] = 'return_reserved'; if (! isset($this->records[$idempotency_key])) return false; $this->records[$idempotency_key]['state'] = self::RESERVED; $this->records[$idempotency_key]['last_error_code'] = $error_code; return true; }
	public function require_reconciliation(string $idempotency_key, string $payload_hash, string $error_code): bool { $this->events[] = 'reconcile'; if (! isset($this->records[$idempotency_key])) return false; $this->records[$idempotency_key]['state'] = self::RECONCILIATION_REQUIRED; $this->records[$idempotency_key]['last_error_code'] = $error_code; return true; }
	public function recover_reserved(string $idempotency_key, string $payload_hash, string $expected_updated_at): array { $this->events[] = 'recover_reserved'; $record = $this->records[$idempotency_key] ?? null; if (! is_array($record) || $record['payload_hash'] !== $payload_hash || $record['state'] !== self::RESERVED || $record['updated_at'] !== $expected_updated_at) return $this->existing($idempotency_key, $payload_hash); $this->records[$idempotency_key]['updated_at'] = '2025-10-09 08:53:20'; $this->records[$idempotency_key]['last_error_code'] = ''; return array('result' => self::ACQUIRED, 'record' => null); }
	public function reconcile_stale_materializing(string $idempotency_key, string $payload_hash, string $expected_updated_at): array { $this->events[] = 'reconcile_stale'; $record = $this->records[$idempotency_key] ?? null; if (! is_array($record) || $record['payload_hash'] !== $payload_hash || $record['state'] !== self::MATERIALIZING || $record['updated_at'] !== $expected_updated_at) return $this->existing($idempotency_key, $payload_hash); $this->records[$idempotency_key]['state'] = self::RECONCILIATION_REQUIRED; return array('result' => self::ACQUIRED, 'record' => null); }
	/** @return array{result:string,record:?array} */
	private function existing(string $idempotency_key, string $payload_hash): array { $record = $this->records[$idempotency_key] ?? null; if (! is_array($record)) return array('result' => self::STORAGE_FAILURE, 'record' => null); return array('result' => hash_equals((string) $record['payload_hash'], $payload_hash) ? self::EXISTING_SAME_HASH : self::EXISTING_DIFFERENT_HASH, 'record' => $record); }
}

final class Fake_Materializer implements Publication_Materializer {
	public int $calls = 0; public ?\WP_Error $error = null;
	public function materialize(array $payload, string $payload_hash): array|\WP_Error { $this->calls++; $GLOBALS['turmas_bridge_publication_order'][] = 'materialize'; return $this->error ?? array('schema_version' => '1', 'publication_key' => $payload['publication']['publication_key'], 'status' => 'materialized', 'form_id' => 412, 'idempotent_replay' => false); }
}

final class Fake_Choice_Preparer implements Publication_Choice_Preparer {
	public function prepare(array $payload): array|\WP_Error { $GLOBALS['turmas_bridge_publication_order'][] = 'choices'; return array('publication_key' => $payload['publication']['publication_key'], 'status' => 'choices_prepared', 'idempotent_replay' => false, 'resource_plans' => array()); }
}

final class Fake_Inventory_Preparer implements Publication_Inventory_Preparer {
	public function prepare(array $payload): array|\WP_Error { $GLOBALS['turmas_bridge_publication_order'][] = 'inventory'; return array('publication_key' => $payload['publication']['publication_key'], 'status' => 'inventory_prepared', 'resources' => array()); }
}
