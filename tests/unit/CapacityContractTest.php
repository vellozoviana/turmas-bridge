<?php
declare(strict_types=1);
namespace TurmasBridge\Tests\Unit;
use PHPUnit\Framework\TestCase;
use TurmasBridge\Capacity\Capacity_Command;
use TurmasBridge\Capacity\Capacity_Controller;
use TurmasBridge\Auth\Canonical_Request;
use TurmasBridge\Auth\Request_Authenticator;
use TurmasBridge\Config\Secret_Provider;
use TurmasBridge\Api\Bridge_Controller;

final class CapacityContractTest extends TestCase {
	private function vector(): array { return json_decode(file_get_contents(__DIR__ . '/../../docs/fixtures/p3-capacity-contract.json'), true, 512, JSON_THROW_ON_ERROR); }
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new \wpdb();
		$GLOBALS['turmas_bridge_test_options'] = array(Secret_Provider::OPTION_NAME => 'synthetic-capacity-contract-only');
		$GLOBALS['turmas_bridge_test_ssl'] = true;
		$GLOBALS['turmas_bridge_test_add_option_failure'] = false;
	}
	public function test_exact_epf_wire_vector_authenticates_and_binds_idempotency(): void {
		$v = $this->vector(); $c = Capacity_Command::validate($v['command']);
		self::assertSame($v['command'], $c);
		self::assertSame($v['body'], wp_json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		self::assertSame($v['canonical'], Canonical_Request::build('POST', '/turmas-bridge/v1/capacidades', array(), '1760000000', 'synthetic-p3-capacity-vector', $v['body'], $c['operation_key']));
		$r = new \WP_REST_Request('POST', '/turmas-bridge/v1/capacidades'); $r->set_body($v['body']);
		$r->set_header('Idempotency-Key', $c['operation_key']);
		$r->set_header(Request_Authenticator::TIMESTAMP_HEADER, '1760000000');
		$r->set_header(Request_Authenticator::NONCE_HEADER, 'synthetic-p3-capacity-vector');
		$r->set_header(Request_Authenticator::SIGNATURE_HEADER, $v['signature']);
		$a = new Request_Authenticator(null, null, static fn (): int => 1760000000);
		self::assertTrue($a->authenticate($r));
		self::assertSame(401, $a->authenticate($r)->get_error_data()['status'], 'A network nonce is not reusable even for command replay.');
		$r->set_header('Idempotency-Key', 'different-synthetic-key');
		self::assertSame(401, $a->authenticate($r)->get_error_data()['status']);
	}
	public function test_routes_are_authenticated_and_status_is_get_only(): void {
		$GLOBALS['turmas_bridge_test_routes'] = array(); Capacity_Controller::register_routes();
		self::assertCount(3, $GLOBALS['turmas_bridge_test_routes']);
		foreach ($GLOBALS['turmas_bridge_test_routes'] as $i => $r) {
			self::assertSame(array(Bridge_Controller::class, 'authenticate'), $r['arguments']['permission_callback']);
			self::assertSame($i === 1 ? 'GET' : 'POST', $r['arguments']['methods']);
		}
	}
	public function test_missing_or_mismatched_identity_never_reaches_capacity_service(): void {
		$r = new \WP_REST_Request('POST', '/turmas-bridge/v1/capacidades'); $r->set_body($this->vector()['body']);
		self::assertSame(409, Capacity_Controller::apply($r)->get_error_data()['status']);
		$r->set_header('Idempotency-Key', $this->vector()['command']['operation_key']); $r->set_param('operation_key', 'different');
		self::assertSame(409, Capacity_Controller::reconcile($r)->get_error_data()['status']);
		$r->set_body('{invalid');
		self::assertSame(422, Capacity_Controller::apply($r)->get_error_data()['status']);
	}
	public function test_invalid_signature_is_rejected_without_payload_disclosure(): void {
		$r = new \WP_REST_Request('POST', '/turmas-bridge/v1/capacidades');
		$r->set_body($this->vector()['body']); $r->set_header('Idempotency-Key', $this->vector()['command']['operation_key']);
		$r->set_header(Request_Authenticator::TIMESTAMP_HEADER, '1760000000'); $r->set_header(Request_Authenticator::NONCE_HEADER, 'synthetic-bad-signature-vector'); $r->set_header(Request_Authenticator::SIGNATURE_HEADER, 'v2=' . str_repeat('0', 64));
		self::assertSame(401, (new Request_Authenticator(null, null, static fn (): int => 1760000000))->authenticate($r)->get_error_data()['status']);
	}
}
