<?php
declare(strict_types=1);
namespace TurmasBridge\Reconciliation;
final class Reconciliation_Result {
	public function __construct(private int $http_status, private string $code, private string $state, private array $payload = array()) {}
	public function http_status(): int { return $this->http_status; } public function code(): string { return $this->code; } public function state(): string { return $this->state; }
	/** @return array<string,mixed> */ public function to_array(): array { return array_merge(array('ok' => $this->http_status < 400, 'code' => $this->code, 'state' => $this->state), $this->payload); }
}
