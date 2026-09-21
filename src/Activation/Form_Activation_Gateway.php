<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

interface Form_Activation_Gateway {
	public function is_available(): bool;
	/** @return array<string,mixed>|null */
	public function read(int $form_id): ?array;
	/** @return Form_Activation_Outcome|\WP_Error */
	public function activate(int $form_id): Form_Activation_Outcome|\WP_Error;
}
