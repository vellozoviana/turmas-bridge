<?php
declare(strict_types=1);
namespace TurmasBridge\Capacity;

interface Capacity_Gateway {
	public function lock(string $class_key): bool;
	public function unlock(string $class_key): void;
	/** @return array<string,mixed> Fresh observation; never modifies inventory. */
	public function inspect(array $command): array;
	/** Returns a safe blocker before any write, or null after attempted writes. Throws on uncertain effects. */
	public function write(array $command): ?string;
}
