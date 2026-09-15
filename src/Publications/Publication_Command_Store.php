<?php

declare(strict_types=1);

namespace TurmasBridge\Publications;

interface Publication_Command_Store {
	/** @return array<string, mixed>|null */
	public function find(string $idempotency_key): ?array;
	/** @param array<string, mixed> $response */
	public function record(string $idempotency_key, string $payload_hash, int $status, array $response): bool;
}
