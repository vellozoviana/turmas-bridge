<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Api\Bridge_Controller;
use TurmasBridge\Auth\Canonical_Request;
use TurmasBridge\Auth\Request_Authenticator;
use TurmasBridge\Config\Secret_Provider;

final class BridgeControllerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['turmas_bridge_test_options'] = array(Secret_Provider::OPTION_NAME => 'controller-test-secret');
		$GLOBALS['turmas_bridge_test_transients'] = array();
		$GLOBALS['turmas_bridge_test_ssl'] = true;
		$GLOBALS['turmas_bridge_test_routes'] = array();
	}

	public function test_routes_are_authenticated_and_activation_is_post_while_status_is_get(): void {
		Bridge_Controller::register_routes();

		self::assertCount(6, $GLOBALS['turmas_bridge_test_routes']);
		foreach ($GLOBALS['turmas_bridge_test_routes'] as $index => $route) {
			self::assertSame(in_array($index, array(2, 4), true) ? 'POST' : 'GET', $route['arguments']['methods']);
			self::assertSame(array(Bridge_Controller::class, 'authenticate'), $route['arguments']['permission_callback']);
		}
		self::assertStringContainsString('/activation', $GLOBALS['turmas_bridge_test_routes'][4]['route']);
		self::assertStringContainsString('/operacoes/', $GLOBALS['turmas_bridge_test_routes'][5]['route']);
	}

	public function test_publication_status_rejects_invalid_key_without_external_access(): void {
		$request = $this->signed_request('/turmas-bridge/v1/publicacoes/invalida');
		$request->set_param('publication_key', 'invalida');
		$result = Bridge_Controller::publication_status($request);

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_invalid_publication_key', $result->get_error_code());
	}

	public function test_activation_rejects_malformed_json_before_service_boundary(): void {
		$request = new \WP_REST_Request('POST', '/turmas-bridge/v1/publicacoes/2099:E2F/activation'); $request->set_param('publication_key', '2099:E2F'); $request->set_body('{invalid');
		$result = Bridge_Controller::activation($request);

		self::assertInstanceOf(\WP_Error::class, $result); self::assertSame('turmas_bridge_invalid_json', $result->get_error_code()); self::assertSame(400, $result->get_error_data()['status']);
	}

	public function test_activation_rejects_header_and_body_identity_divergence_before_service_boundary(): void {
		$request = new \WP_REST_Request('POST', '/turmas-bridge/v1/publicacoes/2099:E2F/activation'); $request->set_param('publication_key', '2099:E2F'); $request->set_header('Idempotency-Key', 'operation-a'); $request->set_body((string) json_encode(array('schema_version' => '1', 'operation_key' => 'operation-b', 'publication_key' => '2099:E2F', 'expected_form_id' => 10, 'snapshot_fingerprint' => str_repeat('a', 64))));
		$result = Bridge_Controller::activation($request);

		self::assertInstanceOf(\WP_Error::class, $result); self::assertSame('turmas_bridge_activation_identity_conflict', $result->get_error_code()); self::assertSame(409, $result->get_error_data()['status']);
	}

	public function test_activation_requires_idempotency_header_before_service_boundary(): void {
		$request = new \WP_REST_Request('POST', '/turmas-bridge/v1/publicacoes/2099:E2F/activation'); $request->set_param('publication_key', '2099:E2F'); $request->set_body((string) json_encode(array('schema_version' => '1', 'operation_key' => 'operation-a', 'publication_key' => '2099:E2F', 'expected_form_id' => 10, 'snapshot_fingerprint' => str_repeat('a', 64))));
		$result = Bridge_Controller::activation($request);

		self::assertInstanceOf(\WP_Error::class, $result); self::assertSame('turmas_bridge_idempotency_key_required', $result->get_error_code()); self::assertSame(400, $result->get_error_data()['status']);
	}

	public function test_ping_reports_availability_without_sensitive_configuration(): void {
		$response = Bridge_Controller::ping($this->signed_request('/turmas-bridge/v1/ping'));
		$data = $response->get_data();

		self::assertSame(200, $response->get_status());
		self::assertSame(array('ok', 'bridge_version', 'api_version', 'gravity_forms_available', 'request_id'), array_keys($data));
		self::assertFalse($data['gravity_forms_available']);
		self::assertStringNotContainsString('controller-test-secret', (string) json_encode($data));
	}

	public function test_ping_permission_callback_blocks_unauthenticated_request(): void {
		$result = Bridge_Controller::authenticate(new \WP_REST_Request('GET', '/turmas-bridge/v1/ping'));

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_unauthorized', $result->get_error_code());
	}

	public function test_template_rejects_invalid_id_before_reading_gravity_forms(): void {
		$request = $this->signed_request('/turmas-bridge/v1/formularios/0');
		$request->set_param('id', '0');
		$result = Bridge_Controller::template($request);

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_invalid_template_id', $result->get_error_code());
	}

	public function test_template_returns_controlled_error_when_gravity_forms_is_unavailable(): void {
		$request = $this->signed_request('/turmas-bridge/v1/formularios/1');
		$request->set_param('id', '1');
		$result = Bridge_Controller::template($request);

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_gravity_forms_unavailable', $result->get_error_code());
	}

	private function signed_request(string $route): \WP_REST_Request {
		$timestamp = (string) time();
		$nonce = 'nonce-controller-test-01';
		$request = new \WP_REST_Request('GET', $route);
		$canonical = Canonical_Request::build('GET', $route, array(), $timestamp, $nonce, '');
		$request->set_header(Request_Authenticator::TIMESTAMP_HEADER, $timestamp);
		$request->set_header(Request_Authenticator::NONCE_HEADER, $nonce);
		$request->set_header(Request_Authenticator::SIGNATURE_HEADER, 'v1=' . hash_hmac('sha256', $canonical, 'controller-test-secret'));

		return $request;
	}
}
