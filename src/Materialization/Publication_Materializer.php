<?php

declare(strict_types=1);

namespace TurmasBridge\Materialization;

interface Publication_Materializer {
	/** @param array<string, mixed> $payload @return array<string, mixed>|\WP_Error */
	public function materialize(array $payload, string $payload_hash): array|\WP_Error;
}
