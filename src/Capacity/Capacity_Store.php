<?php
declare(strict_types=1);
namespace TurmasBridge\Capacity;

interface Capacity_Store {
	public function find(string $key): ?array;
	public function reserve(array $command): bool;
	/** Returns null only when no competing/newer/unresolved command exists. */
	public function conflict(array $command): ?string;
	public function transition(array $record, string $state, array $evidence, ?string $error, bool $attempt = false): ?array;
}
