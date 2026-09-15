<?php

declare(strict_types=1);

namespace TurmasBridge\Publications;

use TurmasBridge\Materialization\Materialization_Service;
use TurmasBridge\Materialization\Publication_Materializer;

final class Publication_Controller {
	public const IDEMPOTENCY_HEADER = 'idempotency-key';
	private Publication_Command_Store $store;
	private Publication_Materializer $materializer;

	public function __construct(?Publication_Command_Store $store = null, ?Publication_Materializer $materializer = null) { $this->store = $store ?? new Idempotency_Repository(); $this->materializer = $materializer ?? new Materialization_Service(); }

	/** @return \WP_REST_Response|\WP_Error */
	public function receive(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$key = trim((string) $request->get_header(self::IDEMPOTENCY_HEADER));
		if (! preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $key)) return $this->error('turmas_bridge_idempotency_key_required', 'A chave de idempotência é obrigatória.', 400);
		$body = (string) $request->get_body();
		$hash = hash('sha256', $body);
		$previous = $this->store->find($key);
		if ($previous) {
			if (! hash_equals((string) $previous['payload_hash'], $hash)) return $this->error('turmas_bridge_idempotency_conflict', 'A chave de idempotência já foi usada com outro conteúdo.', 409);
			return $this->replay($previous);
		}
		try { $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { return $this->error('turmas_bridge_invalid_json', 'O corpo JSON é inválido.', 400); }
		$payload = Publication_Payload::validate($decoded);
		if (is_wp_error($payload)) return $payload;
		$response = $this->materializer->materialize($payload, $hash);
		if (is_wp_error($response)) return $response;
		if (! $this->store->record($key, $hash, 201, $response)) {
			$raced = $this->store->find($key);
			if ($raced) return hash_equals((string) $raced['payload_hash'], $hash) ? $this->replay($raced) : $this->error('turmas_bridge_idempotency_conflict', 'A chave de idempotência já foi usada com outro conteúdo.', 409);
			return $this->error('turmas_bridge_command_store_failed', 'Não foi possível registrar o comando.', 500);
		}
		return new \WP_REST_Response($response, 201);
	}

	/** @param array<string, mixed> $record @return \WP_REST_Response|\WP_Error */
	private function replay(array $record): \WP_REST_Response|\WP_Error {
		try { $response = json_decode((string) $record['response_body'], true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { return $this->error('turmas_bridge_command_store_failed', 'Não foi possível recuperar o comando anterior.', 500); }
		if (! is_array($response)) return $this->error('turmas_bridge_command_store_failed', 'Não foi possível recuperar o comando anterior.', 500);
		$response['idempotent_replay'] = true;
		return new \WP_REST_Response($response, (int) $record['response_status']);
	}

	private function error(string $code, string $message, int $status): \WP_Error { return new \WP_Error($code, $message, array('status' => $status)); }
}
