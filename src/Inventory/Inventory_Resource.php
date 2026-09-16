<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Snapshot supplied by the future inventory authority; Bridge does not persist consumption. */
final class Inventory_Resource {
	private Resource_Identity $identity;
	private int $capacity;
	private int $consumed;
	/** @var list<Resource_Representation> */
	private array $representations;

	/** @param list<Resource_Representation> $representations */
	public function __construct(Resource_Identity $identity, int $capacity, int $consumed, array $representations) {
		if ($capacity < 1 || $consumed < 0 || $consumed > $capacity) throw new \InvalidArgumentException('O snapshot de Resource possui capacidade ou consumo inválido.');
		$this->identity = $identity;
		$this->capacity = $capacity;
		$this->consumed = $consumed;
		$this->representations = $representations;
	}

	public function identity(): Resource_Identity { return $this->identity; }
	public function capacity(): int { return $this->capacity; }
	public function consumed(): int { return $this->consumed; }
	public function remaining(): int { return $this->capacity - $this->consumed; }
	/** @return list<Resource_Representation> */
	public function representations(): array { return $this->representations; }
}
