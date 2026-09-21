<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

final class Activation_Result {
	public const ACTIVATED = 'ACTIVATED';
	public const BLOCKED = 'BLOCKED';
	public const RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';
	public const FAILED = 'FAILED';
	public const UNKNOWN = 'UNKNOWN';

	/** @param array<string,mixed> $evidence */
	public function __construct(private string $status, private string $code, private string $message, private array $evidence = array()) {}
	public function status(): string { return $this->status; }
	public function code(): string { return $this->code; }
	public function message(): string { return $this->message; }
	/** @return array<string,mixed> */
	public function evidence(): array { return $this->evidence; }
	/** @return array{status:string,code:string,message:string,evidence:array<string,mixed>} */
	public function to_array(): array { return array('status' => $this->status, 'code' => $this->code, 'message' => $this->message, 'evidence' => $this->evidence); }
}
