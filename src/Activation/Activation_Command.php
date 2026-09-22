<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

interface Activation_Command {
	public function activate(string $publication_key, int $expected_form_id, string $operation_key, string $snapshot_fingerprint): Activation_Result;
}
