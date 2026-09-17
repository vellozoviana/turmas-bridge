<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

final class Resource_Representation {
	private string $cre;
	private string $field_id;
	private string $choice_value;
	private string $choice_text;

	public function __construct(string $cre, string $field_id, string $choice_value, Resource_Identity $identity, string $choice_text = '') {
		if (! preg_match('/^\d{2}$/', $cre) || $field_id === '' || $choice_value !== $identity->class_key()) {
			throw new \InvalidArgumentException('A representação do Resource é inválida.');
		}
		$this->cre = $cre;
		$this->field_id = $field_id;
		$this->choice_value = $choice_value;
		$this->choice_text = $choice_text;
	}

	public function cre(): string { return $this->cre; }
	public function field_id(): string { return $this->field_id; }
	public function choice_value(): string { return $this->choice_value; }
	public function choice_text(): string { return $this->choice_text; }
	/** @return array{cre:string,field_id:string,choice_value:string} */
	public function to_array(): array { return array('cre' => $this->cre, 'field_id' => $this->field_id, 'choice_value' => $this->choice_value); }
}
