<?php

declare(strict_types=1);

namespace TurmasBridge\Api;

use TurmasBridge\Auth\Request_Authenticator;
use TurmasBridge\Gravity\Template_Manifest;
use TurmasBridge\Publications\Publication_Controller;

final class Bridge_Controller {
	public static function register_routes(): void {
		register_rest_route('turmas-bridge/v1', '/ping', array(
			'methods' => 'GET',
			'permission_callback' => array(self::class, 'authenticate'),
			'callback' => array(self::class, 'ping'),
		));
		register_rest_route('turmas-bridge/v1', '/formularios/(?P<id>\\d+)', array(
			'methods' => 'GET',
			'permission_callback' => array(self::class, 'authenticate'),
			'callback' => array(self::class, 'template'),
		));
		register_rest_route('turmas-bridge/v1', '/publicacoes', array(
			'methods' => 'POST',
			'permission_callback' => array(self::class, 'authenticate'),
			'callback' => array(self::class, 'publication'),
		));
	}

	/** @return true|\WP_Error */
	public static function authenticate(\WP_REST_Request $request): true|\WP_Error {
		return (new Request_Authenticator())->authenticate($request);
	}

	public static function ping(\WP_REST_Request $request): \WP_REST_Response {
		return new \WP_REST_Response(array(
			'ok' => true,
			'bridge_version' => TURMAS_BRIDGE_VERSION,
			'api_version' => TURMAS_BRIDGE_API_VERSION,
			'gravity_forms_available' => (new Template_Manifest())->is_available(),
			'request_id' => wp_generate_uuid4(),
		), 200);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function template(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$id = (string) $request->get_param('id');
		if (! ctype_digit($id) || (int) $id <= 0) {
			return new \WP_Error('turmas_bridge_invalid_template_id', 'O ID do formulário-modelo é inválido.', array('status' => 400));
		}

		$manifest = (new Template_Manifest())->for_form((int) $id);
		if (is_wp_error($manifest)) {
			return $manifest;
		}

		return new \WP_REST_Response(array(
			'ok' => true,
			'api_version' => TURMAS_BRIDGE_API_VERSION,
			'template' => $manifest,
			'request_id' => wp_generate_uuid4(),
		), 200);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function publication(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return (new Publication_Controller())->receive($request);
	}
}
