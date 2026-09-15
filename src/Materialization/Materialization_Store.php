<?php

declare(strict_types=1);

namespace TurmasBridge\Materialization;

interface Materialization_Store {
	/** @return array<string, mixed>|null */
	public function find(string $publication_key): ?array;
	public function reserve(string $publication_key, int $template_id, string $payload_hash): bool;
	/** @param list<string> $field_ids */
	public function materialized(string $publication_key, int $form_id, array $field_ids): bool;
	public function failed(string $publication_key, ?int $form_id, string $error_code): bool;
	public function choice_fingerprint(string $publication_key, string $fingerprint): bool;
	public function acquire_choice_lock(string $publication_key): bool;
	public function release_choice_lock(string $publication_key): void;
}
