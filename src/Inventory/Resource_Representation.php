<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

final class Resource_Representation {
	private string $cre;
	private string $field_id;
	private string $choice_value;

	public function __construct(string $cre, string $field_id, string $choice_value, Resource_Identity $identity) {
		if (! preg_match('/^\d{2}$/', $cre) || $field_id === '' || $choice_value !== $identity->class_key()) {
			throw new \InvalidArgumentException('A representação do Resource é inválida.');
		}
		$this->cre = $cre;
		$this->field_id = $field_id;
		$this->choice_value = $choice_value;
	}

	public function cre(): string { return $this->cre; }
	/** @return array{cre:string,field_id:string,choice_value:string} */
	public function to_array(): array { return array('cre' => $this->cre, 'field_id' => $this->field_id, 'choice_value' => $this->choice_value); }
}
