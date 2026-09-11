<?php

declare(strict_types=1);

namespace TurmasBridge;

use TurmasBridge\Admin\Settings_Page;
use TurmasBridge\Api\Bridge_Controller;

final class Plugin {
	public static function register(): void {
		add_action('rest_api_init', array(Bridge_Controller::class, 'register_routes'));
		add_action('admin_menu', array(Settings_Page::class, 'register_page'));
		add_action('admin_init', array(Settings_Page::class, 'handle_submission'));
	}
}
