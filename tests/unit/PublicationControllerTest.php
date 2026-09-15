<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Publications\Publication_Command_Store;
use TurmasBridge\Publications\Publication_Controller;
use TurmasBridge\Materialization\Publication_Materializer;

final class PublicationControllerTest extends TestCase {
	public function test_valid_command_is_accepted_then_replayed_without_reprocessing(): void {
		$store = new Memory_Command_Store();
		$controller = new Publication_Controller($store, new Fake_Materializer());
		$first = $controller->receive($this->request($this->payload()));
		$again = $controller->receive($this->request($this->payload()));
		self::assertSame(201, $first->get_status());
		self::assertFalse($first->get_data()['idempotent_replay']);
		self::assertTrue($again->get_data()['idempotent_replay']);
		self::assertSame(1, $store->writes);
	}

	public function test_same_key_with_different_payload_is_conflict(): void {
		$store = new Memory_Command_Store();
		$controller = new Publication_Controller($store, new Fake_Materializer());
		$controller->receive($this->request($this->payload()));
		$changed = $this->payload(); $changed['classes'][0]['capacity'] = 31;
		$result = $controller->receive($this->request($changed));
		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_idempotency_conflict', $result->get_error_code());
		self::assertSame(409, $result->get_error_data()['status']);
	}

	/** @dataProvider invalid_payloads */
	public function test_invalid_contract_is_rejected(array $payload, string $expected): void {
		$result = (new Publication_Controller(new Memory_Command_Store(), new Fake_Materializer()))->receive($this->request($payload, 'idempotency-invalid-0001'));
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
		self::assertSame('turmas_bridge_idempotency_key_required', (new Publication_Controller(new Memory_Command_Store(), new Fake_Materializer()))->receive($missing)->get_error_code());
		$invalid = new \WP_REST_Request('POST', '/turmas-bridge/v1/publicacoes');
		$invalid->set_header('Idempotency-Key', 'idempotency-json-00001'); $invalid->set_body('{');
		self::assertSame('turmas_bridge_invalid_json', (new Publication_Controller(new Memory_Command_Store(), new Fake_Materializer()))->receive($invalid)->get_error_code());
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
	public int $writes = 0;
	public function find(string $idempotency_key): ?array { return $this->records[$idempotency_key] ?? null; }
	public function record(string $idempotency_key, string $payload_hash, int $status, array $response): bool { $this->writes++; $this->records[$idempotency_key] = array('payload_hash' => $payload_hash, 'response_status' => $status, 'response_body' => json_encode($response)); return true; }
}

final class Fake_Materializer implements Publication_Materializer {
	public function materialize(array $payload, string $payload_hash): array|\WP_Error { return array('schema_version' => '1', 'publication_key' => $payload['publication']['publication_key'], 'status' => 'materialized', 'form_id' => 412, 'idempotent_replay' => false); }
}
