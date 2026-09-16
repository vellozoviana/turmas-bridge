<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Auth\Canonical_Request;
use TurmasBridge\Auth\Request_Authenticator;
use TurmasBridge\Config\Secret_Provider;

final class RequestAuthenticatorTest extends TestCase {
	private const NOW = 1760000000;
	private const SECRET = 'bridge-test-secret-only';

	protected function setUp(): void {
		$GLOBALS['turmas_bridge_test_options'] = array(Secret_Provider::OPTION_NAME => self::SECRET);
		$GLOBALS['turmas_bridge_test_transients'] = array();
		$GLOBALS['turmas_bridge_test_add_option_failure'] = false;
		$GLOBALS['turmas_bridge_test_ssl'] = true;
	}

	public function test_valid_signature_is_accepted_once(): void {
		$request = $this->signed_request();
		$authenticator = $this->authenticator();

		self::assertTrue($authenticator->authenticate($request));
		$replay = $authenticator->authenticate($request);
		self::assertInstanceOf(\WP_Error::class, $replay);
		self::assertSame('turmas_bridge_unauthorized', $replay->get_error_code());
	}

	public function test_invalid_secret_signature_is_rejected(): void {
		$result = $this->authenticator()->authenticate($this->signed_request(secret: 'wrong-secret'));

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame(401, $result->get_error_data()['status']);
	}

	public function test_missing_headers_are_rejected(): void {
		$result = $this->authenticator()->authenticate(new \WP_REST_Request('GET', '/turmas-bridge/v1/ping'));

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_unauthorized', $result->get_error_code());
	}

	public function test_missing_shared_secret_is_reported_without_disclosing_configuration(): void {
		$GLOBALS['turmas_bridge_test_options'] = array();
		$result = $this->authenticator()->authenticate($this->signed_request());

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_not_configured', $result->get_error_code());
		self::assertSame(503, $result->get_error_data()['status']);
		self::assertStringNotContainsString(self::SECRET, $result->get_error_message());
	}

	public function test_expired_and_future_timestamps_are_rejected(): void {
		foreach (array(self::NOW - 301, self::NOW + 301) as $timestamp) {
			$result = $this->authenticator()->authenticate($this->signed_request(timestamp: $timestamp, nonce: 'nonce-timestamp-' . $timestamp));
			self::assertInstanceOf(\WP_Error::class, $result);
		}
	}

	public function test_malformed_nonce_and_nonce_storage_failure_are_rejected(): void {
		self::assertInstanceOf(\WP_Error::class, $this->authenticator()->authenticate($this->signed_request(nonce: 'short')));
		$GLOBALS['turmas_bridge_test_add_option_failure'] = true;
		self::assertInstanceOf(\WP_Error::class, $this->authenticator()->authenticate($this->signed_request(nonce: 'nonce-storage-failure-01')));
	}

	public function test_path_query_method_and_body_are_part_of_signature(): void {
		$path_changed = $this->signed_request();
		$path_changed->set_route('/turmas-bridge/v1/formularios/7');
		self::assertInstanceOf(\WP_Error::class, $this->authenticator()->authenticate($path_changed));

		$query_changed = $this->signed_request(nonce: 'nonce-query-change-0001', query: array('a' => '1'));
		$query_changed->set_query_params(array('a' => '2'));
		self::assertInstanceOf(\WP_Error::class, $this->authenticator()->authenticate($query_changed));

		$method_changed = $this->signed_request(nonce: 'nonce-method-change-001');
		$method_changed->set_method('POST');
		self::assertInstanceOf(\WP_Error::class, $this->authenticator()->authenticate($method_changed));

		$body_changed = $this->signed_request(nonce: 'nonce-body-change-00001', body: '{"safe":true}');
		$body_changed->set_body('{"safe":false}');
		self::assertInstanceOf(\WP_Error::class, $this->authenticator()->authenticate($body_changed));
	}

	public function test_insecure_transport_is_rejected_without_explicit_local_override(): void {
		$GLOBALS['turmas_bridge_test_ssl'] = false;
		$result = $this->authenticator()->authenticate($this->signed_request());

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_https_required', $result->get_error_code());
		self::assertSame(403, $result->get_error_data()['status']);
	}

	public function test_canonical_query_is_order_independent_and_excludes_wordpress_routing_parameter(): void {
		self::assertSame(
			'a=1&b=2',
			Canonical_Request::canonical_query(array('b' => '2', 'rest_route' => '/turmas-bridge/v1/ping', 'a' => '1'))
		);
	}

	public function test_nested_query_fixture_matches_the_canonical_hmac_contract(): void {
		$fixture = $this->nested_fixture();
		$canonical = Canonical_Request::build((string) $fixture['method'], (string) $fixture['route'], (array) $fixture['query'], (string) $fixture['timestamp'], (string) $fixture['nonce'], (string) $fixture['body']);

		self::assertSame((string) $fixture['body_hash'], hash('sha256', (string) $fixture['body']));
		self::assertSame((string) $fixture['canonical_request'], $canonical);
		self::assertSame((string) $fixture['signature'], 'v1=' . hash_hmac('sha256', $canonical, (string) $fixture['test_secret']));
	}

	public function test_authenticator_uses_timing_safe_signature_comparison(): void {
		$source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Auth/Request_Authenticator.php');

		self::assertStringContainsString('hash_equals($expected, $signature)', $source);
	}

	public function test_bridge_does_not_write_authentication_data_to_error_log(): void {
		$source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Auth/Request_Authenticator.php');

		self::assertStringNotContainsString('error_log(', $source);
	}

	private function authenticator(): Request_Authenticator {
		return new Request_Authenticator(clock: static fn (): int => self::NOW);
	}

	/** @return array<string, mixed> */
	private function nested_fixture(): array {
		$decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/fixtures/fase-12b-hmac-v1-nested-query.json'), true);
		self::assertIsArray($decoded);

		return $decoded;
	}

	/** @param array<string, mixed> $query */
	private function signed_request(string $secret = self::SECRET, int $timestamp = self::NOW, string $nonce = 'nonce-valid-request-0001', string $body = '', array $query = array()): \WP_REST_Request {
		$request = new \WP_REST_Request('GET', '/turmas-bridge/v1/ping');
		$request->set_query_params($query);
		$request->set_body($body);
		$timestamp_text = (string) $timestamp;
		$canonical = Canonical_Request::build('GET', '/turmas-bridge/v1/ping', $query, $timestamp_text, $nonce, $body);
		$request->set_header(Request_Authenticator::TIMESTAMP_HEADER, $timestamp_text);
		$request->set_header(Request_Authenticator::NONCE_HEADER, $nonce);
		$request->set_header(Request_Authenticator::SIGNATURE_HEADER, 'v1=' . hash_hmac('sha256', $canonical, $secret));

		return $request;
	}
}
