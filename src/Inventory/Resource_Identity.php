<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Stable identity for one logical Turma inventory pool. */
final class Resource_Identity {
	private string $publication_key;
	private string $class_key;

	private function __construct(string $publication_key, string $class_key) {
		$this->publication_key = $publication_key;
		$this->class_key = $class_key;
	}

	public static function from_class_key(string $class_key): self {
		if (! preg_match('/^(\d{4}:[A-Z0-9_-]{1,50}):(\d{2}\.\d{2})$/', $class_key, $matches)) {
			throw new \InvalidArgumentException('A identidade do Resource deve usar uma class_key válida.');
		}
		return new self($matches[1], $class_key);
	}

	public function publication_key(): string { return $this->publication_key; }
	public function class_key(): string { return $this->class_key; }
	public function value(): string { return $this->publication_key . '|' . $this->class_key; }
}
