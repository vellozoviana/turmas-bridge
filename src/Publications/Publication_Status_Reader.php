<?php

declare(strict_types=1);

namespace TurmasBridge\Publications;

use TurmasBridge\Materialization\Gravity_Forms_Gateway;
use TurmasBridge\Materialization\Materialization_Repository;
use TurmasBridge\Materialization\Materialization_Store;
use TurmasBridge\Materialization\WordPress_Gravity_Forms_Gateway;

/** Read-only, conservative view of a publication's effective local state. */
final class Publication_Status_Reader {
	private ?Materialization_Store $store;
	private ?Gravity_Forms_Gateway $gravity;

	public function __construct(?Materialization_Store $store = null, ?Gravity_Forms_Gateway $gravity = null) {
		$this->store = $store;
		$this->gravity = $gravity;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function read(string $publication_key): array|\WP_Error {
		if (! preg_match('/^\d{4}:[A-Z0-9_-]{1,50}$/', $publication_key)) {
			return new \WP_Error('turmas_bridge_invalid_publication_key', 'A chave da Publicação é inválida.', array('status' => 400));
		}
		$this->store ??= new Materialization_Repository();
		$this->gravity ??= new WordPress_Gravity_Forms_Gateway();

		$record = $this->store->find($publication_key);
		if (! $record) {
			return new \WP_Error('turmas_bridge_publication_not_found', 'A Publicação não foi encontrada.', array('status' => 404));
		}

		$form_id = (int) ($record['form_id'] ?? 0);
		$form_state = 'not_materialized';
		if ($form_id > 0) {
			$form = $this->gravity->form($form_id);
			if (! is_array($form)) {
				$form_state = 'missing';
			} elseif (! empty($form['is_active'])) {
				$form_state = 'active';
			} else {
				$form_state = 'inactive';
			}
		}

		$status = (string) ($record['status'] ?? '');
		$effective = $status === 'MATERIALIZED' && $form_state === 'inactive' ? 'MATERIALIZED' : ($status === 'FAILED' ? 'FAILED' : 'RECONCILIATION_REQUIRED');

		return array(
			'publication_key' => $publication_key,
			'status' => $status,
			'effective_state' => $effective,
			'form_id' => $form_id > 0 ? $form_id : null,
			'form_state' => $form_state,
			'error_code' => $record['error_code'] ?? null,
			'updated_at' => (string) ($record['updated_at'] ?? ''),
		);
	}
}
