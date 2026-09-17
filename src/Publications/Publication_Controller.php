<?php

declare(strict_types=1);

namespace TurmasBridge\Publications;

use TurmasBridge\Materialization\Materialization_Service;
use TurmasBridge\Materialization\Publication_Materializer;
use TurmasBridge\Materialization\Materialization_Repository;
use TurmasBridge\Materialization\WordPress_Gravity_Forms_Gateway;
use TurmasBridge\Choices\Publication_Choice_Preparer;
use TurmasBridge\Choices\Publication_Choice_Service;
use TurmasBridge\Inventory\Publication_Inventory_Preparer;
use TurmasBridge\Inventory\Publication_Inventory_Service;

final class Publication_Controller {
	public const IDEMPOTENCY_HEADER = 'idempotency-key';
	public const RESERVED_STALE_SECONDS = 120;
	private Publication_Command_Store $store;
	private Publication_Materializer $materializer;
	private Publication_Choice_Preparer $choices;
	private Publication_Inventory_Preparer $inventory;
	/** @var callable():int */
	private $clock;

	public function __construct(?Publication_Command_Store $store = null, ?Publication_Materializer $materializer = null, ?Publication_Choice_Preparer $choices = null, ?callable $clock = null, ?Publication_Inventory_Preparer $inventory = null) { $this->store = $store ?? new Idempotency_Repository(); $this->materializer = $materializer ?? new Materialization_Service(); $this->choices = $choices ?? new Publication_Choice_Service(new Materialization_Repository(), new WordPress_Gravity_Forms_Gateway()); $this->inventory = $inventory ?? new Publication_Inventory_Service(); $this->clock = $clock ?? static fn (): int => time(); }

	/** @return \WP_REST_Response|\WP_Error */
	public function receive(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$key = trim((string) $request->get_header(self::IDEMPOTENCY_HEADER));
		if (! preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $key)) return $this->error('turmas_bridge_idempotency_key_required', 'A chave de idempotência é obrigatória.', 400);
		$body = (string) $request->get_body();
		$hash = hash('sha256', $body);
		try { $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { return $this->error('turmas_bridge_invalid_json', 'O corpo JSON é inválido.', 400); }
		$payload = Publication_Payload::validate($decoded);
		if (is_wp_error($payload)) return $payload;
		$reservation = $this->store->reserve($key, $hash, (string) $payload['publication']['publication_key']);
		if ($reservation['result'] === Publication_Command_Store::EXISTING_DIFFERENT_HASH) return $this->error('turmas_bridge_idempotency_conflict', 'A chave de idempotência já foi usada com outro conteúdo.', 409);
		if ($reservation['result'] === Publication_Command_Store::STORAGE_FAILURE) return $this->error('turmas_bridge_command_store_failed', 'Não foi possível reservar o comando.', 500);
		if ($reservation['result'] === Publication_Command_Store::EXISTING_SAME_HASH) {
			$record = (array) $reservation['record'];
			if ((string) ($record['state'] ?? '') !== Publication_Command_Store::RESERVED) return $this->existing($record);
			if (! $this->is_stale($record) && (string) ($record['last_error_code'] ?? '') === '') return $this->processing();
			$recovery = $this->store->recover_reserved($key, $hash, (string) ($record['updated_at'] ?? ''));
			if ($recovery['result'] !== Publication_Command_Store::ACQUIRED) return $this->recovery_result($recovery);
		}
		$begin = $this->store->begin_materialization($key, $hash, (string) $payload['publication']['publication_key']);
		if ($begin['result'] !== Publication_Command_Store::ACQUIRED) {
			if ($begin['result'] === Publication_Command_Store::EXISTING_DIFFERENT_HASH) return $this->error('turmas_bridge_idempotency_conflict', 'A chave de idempotência já foi usada com outro conteúdo.', 409);
			if ($begin['result'] === Publication_Command_Store::EXISTING_SAME_HASH) return $this->existing((array) $begin['record']);
			return $this->error('turmas_bridge_command_store_failed', 'Não foi possível iniciar o comando.', 500);
		}
		$response = $this->materializer->materialize($payload, $hash);
		if (is_wp_error($response)) {
			if ($this->safe_pre_side_effect_error($response) && $this->store->return_to_reserved($key, $hash, $response->get_error_code())) return $response;
			if ($this->store->require_reconciliation($key, $hash, $response->get_error_code())) return $this->error('turmas_bridge_reconciliation_required', 'A materialização requer reconciliação antes de nova tentativa.', 409);
			return $this->error('turmas_bridge_command_store_failed', 'Não foi possível registrar o resultado do comando.', 500);
		}
		$choice_result = $this->choices->prepare($payload);
		if (is_wp_error($choice_result)) {
			if ($this->store->require_reconciliation($key, $hash, $choice_result->get_error_code())) return $this->error('turmas_bridge_reconciliation_required', 'A materialização requer reconciliação antes de nova tentativa.', 409);
			return $this->error('turmas_bridge_command_store_failed', 'Não foi possível registrar o resultado do comando.', 500);
		}
		$inventory_result = $this->inventory->prepare($payload);
		if (is_wp_error($inventory_result)) {
			if ($this->store->require_reconciliation($key, $hash, $inventory_result->get_error_code())) return $this->error('turmas_bridge_reconciliation_required', 'A preparação de inventário requer reconciliação antes de nova tentativa.', 409);
			return $this->error('turmas_bridge_command_store_failed', 'Não foi possível registrar o resultado do comando.', 500);
		}
		$response['choices_prepared'] = true;
		$response['inventory_prepared'] = true;
		if (! $this->store->succeed($key, $hash, 201, $response)) {
			if ($this->store->require_reconciliation($key, $hash, 'turmas_bridge_success_persist_failed')) return $this->error('turmas_bridge_reconciliation_required', 'A materialização requer reconciliação antes de nova tentativa.', 409);
			return $this->error('turmas_bridge_command_store_failed', 'Não foi possível concluir o comando.', 500);
		}
		return new \WP_REST_Response($response, 201);
	}

	/** @param array<string,mixed> $record @return \WP_REST_Response|\WP_Error */
	private function existing(array $record): \WP_REST_Response|\WP_Error {
		$state = (string) ($record['state'] ?? Publication_Command_Store::SUCCEEDED);
		if ($state === Publication_Command_Store::SUCCEEDED) return $this->replay($record);
		if ($state === Publication_Command_Store::RECONCILIATION_REQUIRED) return $this->error('turmas_bridge_reconciliation_required', 'A materialização requer reconciliação antes de nova tentativa.', 409);
		if ($state === Publication_Command_Store::MATERIALIZING && $this->is_stale($record)) {
			$reconciliation = $this->store->reconcile_stale_materializing((string) $record['idempotency_key'], (string) $record['payload_hash'], (string) ($record['updated_at'] ?? ''));
			if ($reconciliation['result'] === Publication_Command_Store::ACQUIRED) return $this->error('turmas_bridge_reconciliation_required', 'A materialização requer reconciliação antes de nova tentativa.', 409);
			return $this->recovery_result($reconciliation);
		}
		return $this->processing();
	}

	/** @param array{result:string,record:?array} $result @return \WP_REST_Response|\WP_Error */
	private function recovery_result(array $result): \WP_REST_Response|\WP_Error {
		if ($result['result'] === Publication_Command_Store::STORAGE_FAILURE) return $this->error('turmas_bridge_command_store_failed', 'Não foi possível recuperar o comando.', 500);
		if ($result['result'] === Publication_Command_Store::EXISTING_DIFFERENT_HASH) return $this->error('turmas_bridge_idempotency_conflict', 'A chave de idempotência já foi usada com outro conteúdo.', 409);
		return $this->existing((array) $result['record']);
	}

	private function processing(): \WP_REST_Response { return new \WP_REST_Response(array('status' => 'processing', 'idempotent_replay' => true), 202); }
	/** @param array<string,mixed> $record */
	private function is_stale(array $record): bool { $updated = strtotime((string) ($record['updated_at'] ?? '') . ' UTC'); return $updated !== false && (int) call_user_func($this->clock) - $updated >= self::RESERVED_STALE_SECONDS; }
	private function safe_pre_side_effect_error(\WP_Error $error): bool { return in_array($error->get_error_code(), array('turmas_bridge_gravity_forms_unavailable', 'turmas_bridge_invalid_template'), true); }

	/** @param array<string, mixed> $record @return \WP_REST_Response|\WP_Error */
	private function replay(array $record): \WP_REST_Response|\WP_Error {
		try { $response = json_decode((string) $record['response_body'], true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { return $this->error('turmas_bridge_command_store_failed', 'Não foi possível recuperar o comando anterior.', 500); }
		if (! is_array($response)) return $this->error('turmas_bridge_command_store_failed', 'Não foi possível recuperar o comando anterior.', 500);
		$response['idempotent_replay'] = true;
		return new \WP_REST_Response($response, (int) $record['response_status']);
	}

	private function error(string $code, string $message, int $status): \WP_Error { return new \WP_Error($code, $message, array('status' => $status)); }
}
