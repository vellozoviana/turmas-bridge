<?php

declare(strict_types=1);

namespace TurmasBridge\Auth;

use TurmasBridge\Config\Secret_Provider;
use TurmasBridge\Environment;

final class Request_Authenticator {
	public const TIMESTAMP_HEADER = 'x-turmas-bridge-timestamp';
	public const NONCE_HEADER = 'x-turmas-bridge-nonce';
	public const SIGNATURE_HEADER = 'x-turmas-bridge-signature';
	public const MAX_CLOCK_SKEW_SECONDS = 300;

	private Secret_Provider $secrets;
	private Nonce_Store $nonces;
	/** @var callable():int */
	private $clock;

	public function __construct(?Secret_Provider $secrets = null, ?Nonce_Store $nonces = null, ?callable $clock = null) {
		$this->secrets = $secrets ?? new Secret_Provider();
		$this->clock = $clock ?? static fn (): int => time();
		$this->nonces = $nonces ?? new Nonce_Store($this->clock);
	}

	/** @return true|\WP_Error */
	public function authenticate(\WP_REST_Request $request): true|\WP_Error {
		if (! Environment::allows_request_transport()) {
			return $this->error('turmas_bridge_https_required', 'A conexão HTTPS é obrigatória.', 403);
		}
		if (! $this->secrets->is_configured()) {
			return $this->error('turmas_bridge_not_configured', 'A ponte não está configurada.', 503);
		}

		$timestamp = trim((string) $request->get_header(self::TIMESTAMP_HEADER));
		$nonce = trim((string) $request->get_header(self::NONCE_HEADER));
		$signature = trim((string) $request->get_header(self::SIGNATURE_HEADER));
		$method = strtoupper($request->get_method());
		$idempotency_key = $method === 'POST' ? trim((string) $request->get_header('idempotency-key')) : null;
		$signature_version = Canonical_Request::signature_version($method, $idempotency_key);
		if (! preg_match('/^\d{10}$/', $timestamp) || ! preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $nonce) || ($method === 'POST' && $idempotency_key === '') || ! preg_match('/^' . preg_quote($signature_version, '/') . '=[a-f0-9]{64}$/', $signature)) {
			return $this->unauthorized();
		}

		$now = (int) call_user_func($this->clock);
		if (abs($now - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
			return $this->unauthorized();
		}

		$canonical = Canonical_Request::build(
			$request->get_method(),
			$request->get_route(),
			$request->get_query_params(),
			$timestamp,
			$nonce,
			(string) $request->get_body(),
			$idempotency_key
		);
		$expected = $signature_version . '=' . hash_hmac('sha256', $canonical, $this->secrets->get());
		if (! hash_equals($expected, $signature)) {
			return $this->unauthorized();
		}
		if (! $this->nonces->claim($nonce, (int) $timestamp + self::MAX_CLOCK_SKEW_SECONDS)) {
			return $this->unauthorized();
		}

		return true;
	}

	private function unauthorized(): \WP_Error {
		return $this->error('turmas_bridge_unauthorized', 'Autenticação da ponte inválida.', 401);
	}

	private function error(string $code, string $message, int $status): \WP_Error {
		return new \WP_Error($code, $message, array('status' => $status));
	}
}
