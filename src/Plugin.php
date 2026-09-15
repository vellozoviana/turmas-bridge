<?php

declare(strict_types=1);

namespace TurmasBridge;

use TurmasBridge\Admin\Settings_Page;
use TurmasBridge\Api\Bridge_Controller;
use TurmasBridge\Database\Schema;

final class Plugin {
	public static function register(): void {
		add_action('plugins_loaded', array(self::class, 'maybe_upgrade'));
		add_action('rest_api_init', array(Bridge_Controller::class, 'register_routes'));
		add_action('admin_menu', array(Settings_Page::class, 'register_page'));
		add_action('admin_init', array(Settings_Page::class, 'handle_submission'));
	}

	public static function activate(): void {
		Schema::install();
	}

	public static function maybe_upgrade(): void {
		if (Schema::VERSION !== get_option(Schema::OPTION_NAME)) {
			Schema::install();
		}
	}
}
