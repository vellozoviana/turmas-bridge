<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

final class Activation_Operation_Transition {
	public const UPDATED = 'UPDATED';
	public const STATE_MISMATCH = 'STATE_MISMATCH';
	public const NOT_FOUND = 'NOT_FOUND';
	public const ERROR = 'ERROR';

	/** @param array<string,mixed>|null $record */
	public function __construct(private string $result, private ?array $record = null, private ?string $error_code = null) {}
	public function result(): string { return $this->result; }
	/** @return array<string,mixed>|null */
	public function record(): ?array { return $this->record; }
	public function error_code(): ?string { return $this->error_code; }
}
