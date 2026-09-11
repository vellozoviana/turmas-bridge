<?php

declare(strict_types=1);

namespace TurmasBridge;

final class Environment {
	public static function allows_request_transport(): bool {
		if (is_ssl()) {
			return true;
		}

		return defined('TURMAS_BRIDGE_ALLOW_INSECURE_LOCAL')
			&& constant('TURMAS_BRIDGE_ALLOW_INSECURE_LOCAL') === true
			&& in_array(wp_get_environment_type(), array('local', 'development'), true);
	}
}
