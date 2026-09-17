<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Coordinates mapping, locks and conservative reconciliation around GP internals. */
final class GP_Inventory_Adapter implements Inventory_Gateway {
	private Inventory_Mapping_Store $mappings;
	private GP_Inventory_Operations $operations;

	public function __construct(?Inventory_Mapping_Store $mappings = null, ?GP_Inventory_Operations $operations = null) {
		$this->mappings = $mappings ?? new Inventory_Mapping_Repository();
		$this->operations = $operations ?? new WordPress_GP_Inventory_Operations();
	}
	public function find(Resource_Identity $identity): ?Inventory_Resource {
		$mapping = $this->mappings->find($identity);
		if (! $mapping || (int) ($mapping['resource_id'] ?? 0) < 1 || (string) ($mapping['status'] ?? '') !== 'HEALTHY') return null;
		return new Inventory_Resource($identity, 1, 0, array(), (int) $mapping['resource_id']);
	}
	public function ensure(Resource_Plan $plan): Inventory_Resource {
		if ($plan->form_id() === null) throw new Inventory_Integration_Exception('turmas_bridge_inventory_form_required', 'O formulário materializado é obrigatório para configurar o inventário.');
		if (! $this->operations->is_available()) throw new Inventory_Integration_Exception('turmas_bridge_gp_inventory_unavailable', 'Gravity Forms, Gravity Perks ou GP Inventory Advanced não estão disponíveis.');
		$identity = $plan->identity();
		if (! $this->mappings->acquire_lock($identity)) throw new Inventory_Integration_Exception('turmas_bridge_inventory_locked', 'O Resource desta Turma já está em processamento.');
		try {
			$mapping = $this->mappings->find($identity);
			$created = false;
			if (! $mapping) {
				if (! $this->mappings->reserve($identity, $plan->form_id())) throw new Inventory_Integration_Exception('turmas_bridge_inventory_mapping_reserve_failed', 'Não foi possível reservar o mapeamento do Resource.');
				$resource_id = $this->operations->find_resource($identity);
				if ($resource_id === null) {
					try { $resource_id = $this->operations->create_resource($identity); } catch (Inventory_Integration_Exception $error) { if (! $error->outcome_is_ambiguous()) $this->mappings->discard_provisioning($identity); throw $error; }
				}
				if (! $this->mappings->resource_created($identity, $resource_id, $plan->form_id())) throw new Inventory_Integration_Exception('turmas_bridge_inventory_mapping_persist_failed', 'O Resource foi criado e requer reconciliação.');
				$mapping = $this->mappings->find($identity);
				$created = true;
			}
			$resource_id = (int) ($mapping['resource_id'] ?? 0);
			if ($resource_id < 1 || ! $this->operations->resource_exists($resource_id)) {
				$this->mappings->reconciliation_required($identity, 'turmas_bridge_inventory_resource_missing');
				throw new Inventory_Integration_Exception('turmas_bridge_inventory_reconciliation_required', 'O mapeamento existente não possui um Resource externo utilizável.');
			}
			$observed = $this->operations->inspect($plan, $resource_id);
			if ($plan->capacity() < $observed['consumed']) {
				throw new Inventory_Integration_Exception('turmas_bridge_capacity_below_consumed', 'A capacidade solicitada é inferior ao consumo externo observado.');
			}
			if (! $observed['healthy'] && in_array((string) ($observed['reason'] ?? ''), array('field_resource_drift', 'choice_value_drift', 'representation_missing', 'form_active'), true)) {
				$this->mappings->reconciliation_required($identity, (string) $observed['reason']);
				throw new Inventory_Integration_Exception('turmas_bridge_inventory_reconciliation_required', 'O Resource possui drift semântico e exige reconciliação manual.');
			}
			if (! $created && $observed['healthy'] && $observed['capacity'] === $plan->capacity()) {
				if (! $this->mappings->healthy($identity, $plan->form_id())) throw new Inventory_Integration_Exception('turmas_bridge_inventory_mapping_persist_failed', 'O Resource está saudável, mas o mapeamento requer reconciliação.');
				return new Inventory_Resource($identity, $observed['capacity'], $observed['consumed'], $plan->representations(), $resource_id);
			}
			$state = $this->operations->synchronize($plan, $resource_id);
			if (! $state['healthy']) {
				$this->mappings->reconciliation_required($identity, (string) ($state['reason'] ?? 'turmas_bridge_inventory_drift'));
				throw new Inventory_Integration_Exception('turmas_bridge_inventory_reconciliation_required', 'O Resource exige reconciliação antes de ser considerado saudável.');
			}
			if ($plan->capacity() < $state['consumed']) {
				$this->mappings->reconciliation_required($identity, 'turmas_bridge_capacity_below_consumed_after_sync');
				throw new Inventory_Integration_Exception('turmas_bridge_capacity_below_consumed', 'O consumo mudou durante a sincronização; a redução foi bloqueada.');
			}
			if (! $this->mappings->healthy($identity, $plan->form_id())) throw new Inventory_Integration_Exception('turmas_bridge_inventory_mapping_persist_failed', 'O Resource foi sincronizado e requer reconciliação de mapeamento.');
			return new Inventory_Resource($identity, $state['capacity'], $state['consumed'], $plan->representations(), $resource_id);
		} catch (Inventory_Integration_Exception $error) {
			if ($error->error_code() !== 'turmas_bridge_capacity_below_consumed') $this->mappings->reconciliation_required($identity, $error->error_code());
			throw $error;
		} finally { $this->mappings->release_lock($identity); }
	}
}
