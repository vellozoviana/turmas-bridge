<?php

declare(strict_types=1);

namespace TurmasBridge\Materialization;

interface Gravity_Forms_Gateway {
	public function is_available(): bool;
	/** @return array<string, mixed>|null */
	public function form(int $form_id): ?array;
	/** @return int|\WP_Error */
	public function duplicate_inactive(int $template_id, string $title, string $marker): int|\WP_Error;
	/** @param array<string, mixed> $form */
	public function update_form(array $form): bool|\WP_Error;
}
