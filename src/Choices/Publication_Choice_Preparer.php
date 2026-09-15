<?php

declare(strict_types=1);

namespace TurmasBridge\Choices;

interface Publication_Choice_Preparer {
	/** @param array<string,mixed> $payload @return array<string,mixed>|\WP_Error */
	public function prepare(array $payload): array|\WP_Error;
}
