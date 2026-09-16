<?php

declare(strict_types=1);

namespace TurmasBridge\Publications;

interface Publication_Command_Store {
	public const RESERVED = 'RESERVED';
	public const MATERIALIZING = 'MATERIALIZING';
	public const SUCCEEDED = 'SUCCEEDED';
	public const RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';
	public const ACQUIRED = 'ACQUIRED';
	public const EXISTING_SAME_HASH = 'EXISTING_SAME_HASH';
	public const EXISTING_DIFFERENT_HASH = 'EXISTING_DIFFERENT_HASH';
	public const STORAGE_FAILURE = 'STORAGE_FAILURE';

	/** @return array<string, mixed>|null */
	public function find(string $idempotency_key): ?array;
	/** @return array{result:string,record:?array} */
	public function reserve(string $idempotency_key, string $payload_hash, string $publication_key): array;
	/** @return array{result:string,record:?array} */
	public function begin_materialization(string $idempotency_key, string $payload_hash, string $publication_key): array;
	/** @param array<string, mixed> $response */
	public function succeed(string $idempotency_key, string $payload_hash, int $status, array $response): bool;
	public function return_to_reserved(string $idempotency_key, string $payload_hash, string $error_code): bool;
	public function require_reconciliation(string $idempotency_key, string $payload_hash, string $error_code): bool;
	/** @return array{result:string,record:?array} */
	public function recover_reserved(string $idempotency_key, string $payload_hash, string $expected_updated_at): array;
	/** @return array{result:string,record:?array} */
	public function reconcile_stale_materializing(string $idempotency_key, string $payload_hash, string $expected_updated_at): array;
}
