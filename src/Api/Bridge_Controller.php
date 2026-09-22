<?php

declare(strict_types=1);

namespace TurmasBridge\Api;

use TurmasBridge\Auth\Request_Authenticator;
use TurmasBridge\Activation\Activation_Operation_Orchestrator;
use TurmasBridge\Activation\Activation_Operation_Repository;
use TurmasBridge\Activation\Form_Activation_Service;
use TurmasBridge\Activation\Remote_Activation_Command_Service;
use TurmasBridge\Gravity\Template_Manifest;
use TurmasBridge\Publications\Publication_Controller;
use TurmasBridge\Publications\Publication_Status_Reader;
use TurmasBridge\Reconciliation\Reconciliation_Attempt_Repository;
use TurmasBridge\Reconciliation\Reconciliation_Service;

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
		register_rest_route('turmas-bridge/v1', '/publicacoes/(?P<publication_key>[0-9]{4}:[A-Z0-9_-]{1,50})', array(
			'methods' => 'GET',
			'permission_callback' => array(self::class, 'authenticate'),
			'callback' => array(self::class, 'publication_status'),
		));
		register_rest_route('turmas-bridge/v1', '/publicacoes/(?P<publication_key>[0-9]{4}:[A-Z0-9_-]{1,50})/activation', array(
			'methods' => 'POST',
			'permission_callback' => array(self::class, 'authenticate'),
			'callback' => array(self::class, 'activation'),
		));
		register_rest_route('turmas-bridge/v1', '/operacoes/(?P<operation_key>[A-Za-z0-9:_-]{1,128})', array(
			'methods' => 'GET',
			'permission_callback' => array(self::class, 'authenticate'),
			'callback' => array(self::class, 'activation_status'),
		));
		register_rest_route('turmas-bridge/v1', '/operacoes/(?P<operation_key>[A-Za-z0-9:_-]{1,128})/reconciliation', array(
			'methods' => 'POST',
			'permission_callback' => array(self::class, 'authenticate'),
			'callback' => array(self::class, 'reconciliation'),
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

	/** @return \WP_REST_Response|\WP_Error */
	public static function publication_status(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$key = (string) $request->get_param('publication_key');
		$result = (new Publication_Status_Reader())->read($key);
		return is_wp_error($result) ? $result : new \WP_REST_Response(array('ok' => true, 'publication' => $result, 'request_id' => wp_generate_uuid4()), 200);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function activation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$body = (string) $request->get_body();
		try { $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { return new \WP_Error('turmas_bridge_invalid_json', 'O corpo JSON é inválido.', array('status' => 400)); }
		if (! is_array($decoded)) return new \WP_Error('turmas_bridge_invalid_activation_request', 'O corpo da ativação deve ser um objeto JSON.', array('status' => 400));
		$path_key = (string) $request->get_param('publication_key'); $header_key = trim((string) $request->get_header('idempotency-key'));
		if (! preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $header_key)) return new \WP_Error('turmas_bridge_idempotency_key_required', 'A chave de idempotência é obrigatória.', array('status' => 400));
		if ((string) ($decoded['publication_key'] ?? '') !== $path_key || (string) ($decoded['operation_key'] ?? '') !== $header_key) return new \WP_Error('turmas_bridge_activation_identity_conflict', 'A identidade do caminho, corpo e header diverge.', array('status' => 409));
		$result = self::activation_service()->execute($decoded);
		return new \WP_REST_Response($result->to_array(), $result->http_status());
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function activation_status(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = self::activation_service()->status((string) $request->get_param('operation_key'));
		$data = $result->to_array();
		$reconciliation = (new Reconciliation_Attempt_Repository())->find_latest_for_activation((string) $request->get_param('operation_key'));
		$data['reconciliation'] = is_array($reconciliation) ? array('reconciliation_key' => (string) ($reconciliation['reconciliation_key'] ?? ''), 'state' => (string) ($reconciliation['state'] ?? ''), 'result_code' => $reconciliation['result_code'] ?? null, 'evidence' => self::decode_evidence($reconciliation['evidence_json'] ?? null)) : null;
		return new \WP_REST_Response($data, $result->http_status());
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function reconciliation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$body = (string) $request->get_body();
		try { $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { return new \WP_Error('turmas_bridge_invalid_json', 'O corpo JSON é inválido.', array('status' => 400)); }
		if (! is_array($decoded)) return new \WP_Error('turmas_bridge_invalid_reconciliation_request', 'O corpo da reconciliação deve ser um objeto JSON.', array('status' => 400));
		$path_key = (string) $request->get_param('operation_key');
		$header_key = trim((string) $request->get_header('idempotency-key'));
		$body_key = (string) ($decoded['reconciliation_key'] ?? '');
		if (! preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $header_key)) return new \WP_Error('turmas_bridge_idempotency_key_required', 'A chave de idempotência é obrigatória.', array('status' => 400));
		if ($body_key !== $header_key || (string) ($decoded['activation_operation_key'] ?? '') !== $path_key) return new \WP_Error('turmas_bridge_reconciliation_identity_conflict', 'A identidade do caminho, corpo e header diverge.', array('status' => 409));
		$result = self::reconciliation_service()->execute($decoded);
		return new \WP_REST_Response($result->to_array(), $result->http_status());
	}

	private static function activation_service(): Remote_Activation_Command_Service {
		$repository = new Activation_Operation_Repository();
		return new Remote_Activation_Command_Service(new Activation_Operation_Orchestrator($repository), new Form_Activation_Service());
	}

	private static function reconciliation_service(): Reconciliation_Service {
		return new Reconciliation_Service(new Activation_Operation_Repository(), new Reconciliation_Attempt_Repository(), new Publication_Status_Reader());
	}

	/** @return array<string,mixed> */
	private static function decode_evidence(mixed $json): array { if (! is_string($json) || $json === '') return array(); $decoded = json_decode($json, true); return is_array($decoded) ? $decoded : array(); }
}
