<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TurmasBridge\Auth\Canonical_Request;
use TurmasBridge\Auth\Request_Authenticator;
use TurmasBridge\Config\Secret_Provider;

final class HmacCommandSecurityTest extends TestCase {
	private const NOW = 1760000000;
	private const KEY = 'synthetic-command-key-0001';
	private const SECRET = 'public-synthetic-security-test-secret';

	protected function setUp(): void {
		$GLOBALS['turmas_bridge_test_options'] = array(Secret_Provider::OPTION_NAME => self::SECRET);
		$GLOBALS['turmas_bridge_test_ssl'] = true;
		$GLOBALS['turmas_bridge_test_add_option_failure'] = false;
		$GLOBALS['turmas_bridge_test_database_queries'] = array();
	}

	#[DataProvider('command_routes')]
	public function test_all_command_routes_require_and_accept_v2(string $route): void {
		self::assertTrue($this->auth()->authenticate($this->request(route: $route)));
	}

	public static function command_routes(): iterable {
		yield 'materialization' => array('/turmas-bridge/v1/publicacoes');
		yield 'activation' => array('/turmas-bridge/v1/publicacoes/2097:E2F/activation');
		yield 'reconciliation' => array('/turmas-bridge/v1/operacoes/publish-2097:E2F-v1/reconciliation');
	}

	#[DataProvider('mutations')]
	public function test_signed_components_cannot_be_mutated(string $component): void {
		$request = $this->request();
		switch ($component) {
			case 'body': $request->set_body('{"safe":false}'); break;
			case 'key': $request->set_header('Idempotency-Key', 'synthetic-command-key-0002'); break;
			case 'route': $request->set_route('/turmas-bridge/v1/publicacoes/2097:E2F/activation'); break;
			case 'method': $request->set_method('GET'); break;
			case 'unsupported method': $request->set_method('DELETE'); break;
			case 'query': $request->set_query_params(array('tampered' => 'yes')); break;
			case 'timestamp': $request->set_header(Request_Authenticator::TIMESTAMP_HEADER, (string) (self::NOW + 1)); break;
			case 'nonce': $request->set_header(Request_Authenticator::NONCE_HEADER, 'synthetic-replaced-nonce-0001'); break;
		}
		$this->assert_rejected_without_nonce_claim($request);
	}

	public static function mutations(): iterable {
		foreach (array('body', 'key', 'route', 'method', 'unsupported method', 'query', 'timestamp', 'nonce') as $component) yield $component => array($component);
	}

	#[DataProvider('invalid_header_values')]
	public function test_invalid_idempotency_header_is_rejected_before_nonce_or_ledger_write(string|array $value): void {
		$request = $this->request();
		$request->set_header('Idempotency-Key', $value);
		$this->assert_rejected_without_nonce_claim($request);
	}

	public static function invalid_header_values(): iterable {
		yield 'missing' => array(array());
		yield 'empty' => array('');
		yield 'whitespace' => array(" \t");
		yield 'newline suffix previously stripped' => array(self::KEY . "\n");
		yield 'CR prefix previously stripped' => array("\r" . self::KEY);
		yield 'null suffix previously stripped' => array(self::KEY . "\0");
		yield 'internal space' => array('synthetic command key');
		yield 'internal tab' => array("synthetic\tcommand-key");
		yield 'over length' => array(str_repeat('a', 129));
		yield 'multiple identical values' => array(array(self::KEY, self::KEY));
		yield 'multiple different values' => array(array(self::KEY, 'other-key'));
		yield 'merged duplicate' => array(self::KEY . ',' . self::KEY);
	}

	public function test_header_name_case_and_outer_http_whitespace_are_normalized_once(): void {
		$request = $this->request();
		$request->set_header('iDeMpOtEnCy-KeY', " \t" . self::KEY . "\t ");
		self::assertTrue($this->auth()->authenticate($request));
	}

	public function test_duplicate_authentication_headers_are_rejected_even_if_equal(): void {
		foreach (array(Request_Authenticator::TIMESTAMP_HEADER, Request_Authenticator::NONCE_HEADER, Request_Authenticator::SIGNATURE_HEADER) as $header) {
			$request = $this->request();
			$request->add_header($header, $request->get_header($header));
			$this->assert_rejected_without_nonce_claim($request);
		}
	}

	public function test_version_labels_and_canonical_version_cannot_be_swapped_or_downgraded(): void {
		$request = $this->request();
		$digest = substr($request->get_header(Request_Authenticator::SIGNATURE_HEADER), 3);
		foreach (array('v1=', 'v3=', '', 'v02=') as $prefix) {
			$request->set_header(Request_Authenticator::SIGNATURE_HEADER, $prefix . $digest);
			$this->assert_rejected_without_nonce_claim($request);
		}
		$legacy = implode("\n", array('v1', 'POST', $request->get_route(), '', (string) self::NOW, 'synthetic-auth-nonce-0001', hash('sha256', $request->get_body())));
		foreach (array('v1=', 'v2=') as $prefix) {
			$request->set_header(Request_Authenticator::SIGNATURE_HEADER, $prefix . hash_hmac('sha256', $legacy, self::SECRET));
			$this->assert_rejected_without_nonce_claim($request);
		}
		$request->set_header('Idempotency-Key', '');
		$request->set_header(Request_Authenticator::SIGNATURE_HEADER, 'v1=' . hash_hmac('sha256', $legacy, self::SECRET));
		$this->assert_rejected_without_nonce_claim($request);
	}

	public function test_post_expired_timestamp_and_reused_nonce_fail_but_new_auth_envelope_passes(): void {
		$this->assert_rejected_without_nonce_claim($this->request(timestamp: self::NOW - 301));
		$first = $this->request();
		self::assertTrue($this->auth()->authenticate($first));
		$this->assert_rejected_without_nonce_claim($first);
		$again = $this->request(timestamp: self::NOW + 1, nonce: 'synthetic-auth-nonce-0002');
		self::assertSame($first->get_header('Idempotency-Key'), $again->get_header('Idempotency-Key'));
		self::assertNotSame($first->get_header(Request_Authenticator::SIGNATURE_HEADER), $again->get_header(Request_Authenticator::SIGNATURE_HEADER));
		self::assertTrue($this->auth()->authenticate($again));
	}

	public function test_new_verifier_accepts_v1_status_get_and_rejects_v2_get(): void {
		$request = new \WP_REST_Request('GET', '/turmas-bridge/v1/publicacoes/2097:E2F');
		$nonce = 'synthetic-status-nonce-0001';
		$request->set_header(Request_Authenticator::TIMESTAMP_HEADER, (string) self::NOW);
		$request->set_header(Request_Authenticator::NONCE_HEADER, $nonce);
		$canonical = Canonical_Request::build('GET', $request->get_route(), array(), (string) self::NOW, $nonce, '');
		$request->set_header(Request_Authenticator::SIGNATURE_HEADER, 'v2=' . hash_hmac('sha256', $canonical, self::SECRET));
		$this->assert_rejected_without_nonce_claim($request);
		$request->set_header(Request_Authenticator::SIGNATURE_HEADER, 'v1=' . hash_hmac('sha256', $canonical, self::SECRET));
		self::assertTrue($this->auth()->authenticate($request));
	}

	private function assert_rejected_without_nonce_claim(\WP_REST_Request $request): void {
		$before = $GLOBALS['turmas_bridge_test_options'];
		$result = $this->auth()->authenticate($request);
		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('turmas_bridge_unauthorized', $result->get_error_code());
		self::assertSame(array('status' => 401), $result->get_error_data());
		self::assertSame('Autenticação da ponte inválida.', $result->get_error_message());
		self::assertSame($before, $GLOBALS['turmas_bridge_test_options']);
		self::assertSame(array(), $GLOBALS['turmas_bridge_test_database_queries']);
	}

	private function auth(): Request_Authenticator { return new Request_Authenticator(clock: static fn (): int => self::NOW); }

	private function request(string $route = '/turmas-bridge/v1/publicacoes', int $timestamp = self::NOW, string $nonce = 'synthetic-auth-nonce-0001'): \WP_REST_Request {
		$request = new \WP_REST_Request('POST', $route);
		$request->set_body('{"safe":true}');
		$request->set_header('Idempotency-Key', self::KEY);
		$request->set_header(Request_Authenticator::TIMESTAMP_HEADER, (string) $timestamp);
		$request->set_header(Request_Authenticator::NONCE_HEADER, $nonce);
		$canonical = Canonical_Request::build('POST', $route, array(), (string) $timestamp, $nonce, $request->get_body(), self::KEY);
		$request->set_header(Request_Authenticator::SIGNATURE_HEADER, 'v2=' . hash_hmac('sha256', $canonical, self::SECRET));
		return $request;
	}
}
