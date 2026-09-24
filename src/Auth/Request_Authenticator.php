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

		$timestamp = $this->single_header($request, self::TIMESTAMP_HEADER);
		$nonce = $this->single_header($request, self::NONCE_HEADER);
		$signature = $this->single_header($request, self::SIGNATURE_HEADER);
		$method = strtoupper($request->get_method());
		$idempotency_key = $method === 'POST' ? Canonical_Request::normalise_idempotency_key($this->single_header($request, 'idempotency-key')) : null;
		$signature_version = Canonical_Request::signature_version($method);
		if (! in_array($method, array('GET', 'POST'), true) || $timestamp === null || $nonce === null || $signature === null || ! preg_match('/\A\d{10}\z/', $timestamp) || ! preg_match('/\A[A-Za-z0-9._~-]{16,128}\z/', $nonce) || ($method === 'POST' && $idempotency_key === null) || ! preg_match('/\A' . preg_quote($signature_version, '/') . '=[a-f0-9]{64}\z/', $signature)) {
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

	private function single_header(\WP_REST_Request $request, string $name): ?string {
		$values = $request->get_header_as_array($name);
		if (! is_array($values) || count($values) !== 1 || ! is_string($values[0] ?? null)) return null;
		return trim($values[0], " \t");
	}

	private function unauthorized(): \WP_Error {
		return $this->error('turmas_bridge_unauthorized', 'Autenticação da ponte inválida.', 401);
	}

	private function error(string $code, string $message, int $status): \WP_Error {
		return new \WP_Error($code, $message, array('status' => $status));
	}
}
