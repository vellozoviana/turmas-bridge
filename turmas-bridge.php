<?php
/**
 * Plugin Name: Turmas Bridge
 * Description: Ponte HTTPS autenticada para a integração entre TURMAS-EPF e o site público.
 * Version: 0.9.3
 * Author: Turmas EPF
 * Text Domain: turmas-bridge
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('TURMAS_BRIDGE_VERSION', '0.9.3');
define('TURMAS_BRIDGE_API_VERSION', 'v1');
define('TURMAS_BRIDGE_FILE', __FILE__);
define('TURMAS_BRIDGE_DIR', plugin_dir_path(__FILE__));

spl_autoload_register(
	static function (string $class): void {
		$prefix = 'TurmasBridge\\';
		if (! str_starts_with($class, $prefix)) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		$path = TURMAS_BRIDGE_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
		if (is_readable($path)) {
			require_once $path;
		}
	}
);

register_activation_hook(TURMAS_BRIDGE_FILE, array('TurmasBridge\\Plugin', 'activate'));
TurmasBridge\Plugin::register();
