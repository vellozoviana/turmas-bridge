<?php
declare(strict_types=1);
namespace TurmasBridge\Capacity;

use TurmasBridge\Api\Bridge_Controller;

final class Capacity_Controller {
	public static function register_routes(): void {
		register_rest_route('turmas-bridge/v1', '/capacidades', array('methods' => 'POST', 'permission_callback' => array(Bridge_Controller::class, 'authenticate'), 'callback' => array(self::class, 'apply')));
		register_rest_route('turmas-bridge/v1', '/capacidades/(?P<operation_key>capacity-[a-f0-9]{64})', array('methods' => 'GET', 'permission_callback' => array(Bridge_Controller::class, 'authenticate'), 'callback' => array(self::class, 'status')));
		register_rest_route('turmas-bridge/v1', '/capacidades/(?P<operation_key>capacity-[a-f0-9]{64})/reconciliation', array('methods' => 'POST', 'permission_callback' => array(Bridge_Controller::class, 'authenticate'), 'callback' => array(self::class, 'reconcile')));
		register_rest_route('turmas-bridge/v1', '/capacidades/(?P<operation_key>capacity-[a-f0-9]{64})/retry', array('methods' => 'POST', 'permission_callback' => array(Bridge_Controller::class, 'authenticate'), 'callback' => array(self::class, 'retry')));
	}
	public static function apply(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { return self::command($r, false, false); }
	public static function reconcile(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { return self::command($r, true, false); }
	public static function retry(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { return self::command($r, false, true); }
	private static function command(\WP_REST_Request $r, bool $reconcile, bool $retry): \WP_REST_Response|\WP_Error {
		$c = Capacity_Command::validate(json_decode($r->get_body(), true));
		if (is_wp_error($c)) return $c;
		if ($r->get_header('idempotency-key') !== $c['operation_key'] || (($reconcile || $retry) && $r->get_param('operation_key') !== $c['operation_key'])) return new \WP_Error('capacity_identity_conflict', 'Identidade divergente.', array('status' => 409));
		$result = (new Capacity_Service())->execute($c, $reconcile, $retry);
		return is_wp_error($result) ? $result : new \WP_REST_Response($result, 200);
	}
	public static function status(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
		$record = (new Capacity_Repository())->find((string) $r->get_param('operation_key'));
		return $record ? new \WP_REST_Response(Capacity_Service::response($record), 200) : new \WP_Error('capacity_not_found', 'Operação não encontrada.', array('status' => 404));
	}
}
