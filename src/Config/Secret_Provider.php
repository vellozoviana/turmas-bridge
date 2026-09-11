<?php

declare(strict_types=1);

namespace TurmasBridge\Config;

final class Secret_Provider {
	public const OPTION_NAME = 'turmas_bridge_shared_secret';
	public const CONSTANT_NAME = 'TURMAS_BRIDGE_SHARED_SECRET';

	public function get(): string {
		if (defined(self::CONSTANT_NAME)) {
			return trim((string) constant(self::CONSTANT_NAME));
		}

		return trim((string) get_option(self::OPTION_NAME, ''));
	}

	public function is_configured(): bool {
		return $this->get() !== '';
	}

	public function uses_constant(): bool {
		return defined(self::CONSTANT_NAME) && trim((string) constant(self::CONSTANT_NAME)) !== '';
	}

	public function save(string $secret): bool {
		if ($this->uses_constant()) {
			return false;
		}

		$secret = trim($secret);
		if ($secret === '') {
			return false;
		}

		return update_option(self::OPTION_NAME, $secret, false);
	}
}
