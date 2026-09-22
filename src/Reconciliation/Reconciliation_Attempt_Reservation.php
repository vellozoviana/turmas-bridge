<?php
declare(strict_types=1);
namespace TurmasBridge\Reconciliation;
final class Reconciliation_Attempt_Reservation {
	public const CREATED = 'CREATED'; public const EXISTING_MATCH = 'EXISTING_MATCH'; public const CONFLICT = 'CONFLICT'; public const ERROR = 'ERROR';
	/** @param array<string,mixed>|null $record */ public function __construct(private string $result, private ?array $record = null, private ?string $error_code = null) {}
	public function result(): string { return $this->result; }
	/** @return array<string,mixed>|null */ public function record(): ?array { return $this->record; }
	public function error_code(): ?string { return $this->error_code; }
}
