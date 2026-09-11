<?php

declare(strict_types=1);

namespace TurmasBridge\Auth;

final class Nonce_Store {
	public const TTL_SECONDS = 300;

	public function claim(string $nonce): bool {
		$key = 'turmas_bridge_nonce_' . hash('sha256', $nonce);
		if (false !== get_transient($key)) {
			return false;
		}

		set_transient($key, '1', self::TTL_SECONDS);

		return true;
	}
}
