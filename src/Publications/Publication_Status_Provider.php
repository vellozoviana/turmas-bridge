<?php

declare(strict_types=1);

namespace TurmasBridge\Publications;

interface Publication_Status_Provider {
	/** @return array<string,mixed>|\WP_Error */
	public function read(string $publication_key): array|\WP_Error;
}
