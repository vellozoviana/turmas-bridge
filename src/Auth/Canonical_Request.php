<?php

declare(strict_types=1);

namespace TurmasBridge\Auth;

final class Canonical_Request {
	public const CONTRACT_VERSION = 'v1';
	public const COMMAND_CONTRACT_VERSION = 'v2';

	public static function signature_version(string $method): string {
		return strtoupper($method) === 'POST' ? self::COMMAND_CONTRACT_VERSION : self::CONTRACT_VERSION;
	}

	/** HTTP optional whitespace is insignificant; token bytes are case-sensitive. */
	public static function normalise_idempotency_key(?string $value): ?string {
		if ($value === null) return null;
		$value = trim($value, " \t");
		return preg_match('/\A[A-Za-z0-9:._~-]{1,128}\z/', $value) === 1 ? $value : null;
	}

	/** @param array<string, mixed> $query */
	public static function build(string $method, string $route, array $query, string $timestamp, string $nonce, string $body, ?string $idempotency_key = null): string {
		$parts = array(
			self::signature_version($method),
			strtoupper($method),
			self::normalise_route($route),
			self::canonical_query($query),
			$timestamp,
			$nonce,
		);
		if (strtoupper($method) === 'POST') {
			$idempotency_key = self::normalise_idempotency_key($idempotency_key);
			if ($idempotency_key === null) throw new \InvalidArgumentException('POST requires a valid Idempotency-Key.');
			$parts[] = 'idempotency-key:' . $idempotency_key;
		}
		$parts[] = hash('sha256', $body);
		return implode("\n", $parts);
	}

	/** @param array<string, mixed> $query */
	public static function canonical_query(array $query): string {
		unset($query['rest_route']);
		$query = self::sort_recursively($query);

		return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	private static function normalise_route(string $route): string {
		$route = '/' . ltrim(trim($route), '/');

		return $route === '/' ? $route : rtrim($route, '/');
	}

	/** @param array<string, mixed> $value @return array<string, mixed> */
	private static function sort_recursively(array $value): array {
		ksort($value, SORT_STRING);
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = self::sort_recursively($item);
			}
		}

		return $value;
	}
}
