<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Snapshot supplied by the future inventory authority; Bridge does not persist consumption. */
final class Inventory_Resource {
	private Resource_Identity $identity;
	private int $capacity;
	private int $consumed;
	private ?int $resource_id;
	/** @var list<Resource_Representation> */
	private array $representations;

	/** @param list<Resource_Representation> $representations */
	public function __construct(Resource_Identity $identity, int $capacity, int $consumed, array $representations, ?int $resource_id = null) {
		if ($capacity < 1 || $consumed < 0 || ($resource_id !== null && $resource_id < 1)) throw new \InvalidArgumentException('O snapshot de Resource possui capacidade, consumo ou identificador inválido.');
		$this->identity = $identity;
		$this->capacity = $capacity;
		$this->consumed = $consumed;
		$this->representations = $representations;
		$this->resource_id = $resource_id;
	}

	public function identity(): Resource_Identity { return $this->identity; }
	public function capacity(): int { return $this->capacity; }
	public function consumed(): int { return $this->consumed; }
	public function remaining(): int { return $this->capacity - $this->consumed; }
	public function resource_id(): ?int { return $this->resource_id; }
	/** @return list<Resource_Representation> */
	public function representations(): array { return $this->representations; }
}
