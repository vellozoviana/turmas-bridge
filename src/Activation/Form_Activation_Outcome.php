<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

final class Form_Activation_Outcome {
	public function __construct(private string $before_state, private bool $mutation_attempted, private string $after_state, private bool $verification_complete, private ?string $error_code = null) {}
	public function before_state(): string { return $this->before_state; }
	public function mutation_attempted(): bool { return $this->mutation_attempted; }
	public function after_state(): string { return $this->after_state; }
	public function verification_complete(): bool { return $this->verification_complete; }
	public function error_code(): ?string { return $this->error_code; }
	/** @return array<string,mixed> */
	public function evidence(): array { return array('before_state' => $this->before_state, 'mutation_attempted' => $this->mutation_attempted, 'after_state' => $this->after_state, 'verification_complete' => $this->verification_complete, 'error_code' => $this->error_code); }
}
