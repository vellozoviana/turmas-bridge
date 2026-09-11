<?php

declare(strict_types=1);

namespace TurmasBridge\Auth;

final class Canonical_Request {
	public const CONTRACT_VERSION = 'v1';

	/** @param array<string, mixed> $query */
	public static function build(string $method, string $route, array $query, string $timestamp, string $nonce, string $body): string {
		return implode("\n", array(
			self::CONTRACT_VERSION,
			strtoupper($method),
			self::normalise_route($route),
			self::canonical_query($query),
			$timestamp,
			$nonce,
			hash('sha256', $body),
		));
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
