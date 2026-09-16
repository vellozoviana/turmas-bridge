<?php

declare(strict_types=1);

namespace TurmasBridge;

use TurmasBridge\Admin\Settings_Page;
use TurmasBridge\Auth\Nonce_Store;
use TurmasBridge\Api\Bridge_Controller;
use TurmasBridge\Database\Schema;

final class Plugin {
	public static function register(): void {
		add_action('plugins_loaded', array(self::class, 'maybe_upgrade'));
		add_action('rest_api_init', array(Bridge_Controller::class, 'register_routes'));
		add_action('admin_menu', array(Settings_Page::class, 'register_page'));
		add_action('admin_init', array(Settings_Page::class, 'handle_submission'));
		add_action(Nonce_Store::CLEANUP_HOOK, array(Nonce_Store::class, 'cleanup_expired'));
	}

	public static function activate(): void {
		Schema::install();
		Nonce_Store::schedule_cleanup();
	}

	public static function maybe_upgrade(): void {
		Nonce_Store::schedule_cleanup();
		if (Schema::VERSION !== get_option(Schema::OPTION_NAME)) {
			Schema::install();
		}
	}
}
