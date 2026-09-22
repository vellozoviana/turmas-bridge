<?php

declare(strict_types=1);

namespace TurmasBridge\Publications;

/** Read-only verification contract for a Form that is expected to be active. */
interface Post_Activation_Status_Provider {
	/** @return array<string,mixed>|\WP_Error */
	public function read_post_activation(string $publication_key): array|\WP_Error;
}
