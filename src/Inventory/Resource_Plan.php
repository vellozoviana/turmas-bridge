<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Desired configuration for one shared capacity pool, without external writes. */
final class Resource_Plan {
	private Resource_Identity $identity;
	private int $capacity;
	/** @var list<Resource_Representation> */
	private array $representations;

	/** @param list<Resource_Representation> $representations */
	public function __construct(Resource_Identity $identity, int $capacity, array $representations) {
		if ($capacity < 1 || $representations === array()) {
			throw new \InvalidArgumentException('O plano de Resource deve ter capacidade positiva e representações.');
		}
		$seen = array();
		foreach ($representations as $representation) {
			if (! $representation instanceof Resource_Representation || isset($seen[$representation->cre()])) {
				throw new \InvalidArgumentException('O plano de Resource possui representações inválidas ou repetidas.');
			}
			$seen[$representation->cre()] = true;
		}
		usort($representations, static fn (Resource_Representation $left, Resource_Representation $right): int => $left->cre() <=> $right->cre());
		$this->identity = $identity;
		$this->capacity = $capacity;
		$this->representations = $representations;
	}

	public function identity(): Resource_Identity { return $this->identity; }
	public function capacity(): int { return $this->capacity; }
	/** @return list<Resource_Representation> */
	public function representations(): array { return $this->representations; }
	/** @return array{class_key:string,capacity:int,representations:list<array{cre:string,field_id:string,choice_value:string}>} */
	public function to_array(): array {
		return array('class_key' => $this->identity->class_key(), 'capacity' => $this->capacity, 'representations' => array_map(static fn (Resource_Representation $representation): array => $representation->to_array(), $this->representations));
	}
	public function fingerprint(): string {
		$encoded = json_encode($this->to_array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (! is_string($encoded)) throw new \LogicException('Não foi possível calcular o fingerprint do plano de Resource.');
		return hash('sha256', $encoded);
	}
}
