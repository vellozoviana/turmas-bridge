<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Choices\Resource_Plan_Builder;
use TurmasBridge\Inventory\Capacity_Change;
use TurmasBridge\Inventory\Capacity_Change_Decider;
use TurmasBridge\Inventory\Inventory_Gateway;
use TurmasBridge\Inventory\Inventory_Resource;
use TurmasBridge\Inventory\Resource_Identity;
use TurmasBridge\Inventory\Resource_Plan;
use TurmasBridge\Inventory\Resource_Representation;

final class InventoryDomainTest extends TestCase {
	public function test_one_class_in_one_cre_has_one_resource_plan_with_its_total_capacity(): void {
		$plans = $this->builder()->build(array($this->class_payload(array('01'))), $this->field_map());

		self::assertCount(1, $plans);
		self::assertSame(30, $plans[0]['capacity']);
		self::assertCount(1, $plans[0]['representations']);
	}

	public function test_one_class_across_three_cres_has_one_plan_and_one_total_capacity(): void {
		$plans = $this->builder()->build(array($this->class_payload(array('01', '02', '03'))), $this->field_map());
		self::assertCount(1, $plans); self::assertSame(30, $plans[0]['capacity']); self::assertCount(3, $plans[0]['representations']); self::assertSame('2027:MT1:01.01', $plans[0]['representations'][0]['choice_value']);
	}

	public function test_two_classes_produce_two_logical_resources_without_capacity_multiplication(): void {
		$plans = $this->builder()->build(array($this->class_payload(array('01', '02')), $this->class_payload(array('03'), '02.01', 20)), $this->field_map());
		self::assertCount(2, $plans); self::assertSame(array(30, 20), array_column($plans, 'capacity'));
	}

	public function test_duplicate_class_key_is_rejected_instead_of_creating_multiple_plans(): void {
		$result = $this->builder()->build(array($this->class_payload(array('01')), $this->class_payload(array('02'))), $this->field_map());
		self::assertInstanceOf(\WP_Error::class, $result); self::assertSame('turmas_bridge_invalid_resource_plan', $result->get_error_code());
	}

	public function test_resource_identity_is_stable_when_choice_text_changes(): void {
		$identity = Resource_Identity::from_class_key('2027:MT1:01.01');
		self::assertSame('2027:MT1', $identity->publication_key()); self::assertSame('2027:MT1:01.01', $identity->class_key()); self::assertSame('2027:MT1|2027:MT1:01.01', $identity->value());
	}

	public function test_resource_plan_fingerprint_is_independent_of_representation_order(): void {
		$identity = Resource_Identity::from_class_key('2027:MT1:01.01');
		$first = new Resource_Plan($identity, 30, array(new Resource_Representation('02', '11', '2027:MT1:01.01', $identity), new Resource_Representation('01', '10', '2027:MT1:01.01', $identity)));
		$second = new Resource_Plan($identity, 30, array(new Resource_Representation('01', '10', '2027:MT1:01.01', $identity), new Resource_Representation('02', '11', '2027:MT1:01.01', $identity)));
		self::assertSame($first->fingerprint(), $second->fingerprint());
	}

	public function test_cre_order_does_not_change_the_logical_resource_plan(): void {
		$first = $this->builder()->build(array($this->class_payload(array('03', '01', '02'))), $this->field_map());
		$second = $this->builder()->build(array($this->class_payload(array('01', '02', '03'))), $this->field_map());

		self::assertSame($first, $second);
	}

	public function test_presentation_and_non_inventory_metadata_do_not_change_resource_identity_or_plan(): void {
		$first = $this->class_payload(array('01', '02'));
		$first['short_name'] = 'MT1 01.01 SEG MANHÃ';
		$first['full_name'] = 'Primeiro local';
		$first['cycles'] = array('2027-03-01');
		$second = $first;
		$second['short_name'] = 'Nome humano alterado';
		$second['full_name'] = 'Outro local e endereço';
		$second['cycles'] = array('2027-04-01');

		self::assertSame(
			$this->builder()->build(array($first), $this->field_map()),
			$this->builder()->build(array($second), $this->field_map())
		);
	}

	public function test_changing_class_key_changes_resource_identity(): void {
		$first = Resource_Identity::from_class_key('2027:MT1:01.01');
		$second = Resource_Identity::from_class_key('2027:MT1:02.01');

		self::assertNotSame($first->value(), $second->value());
	}

	public function test_capacity_decisions_preserve_consumption_rules(): void {
		$decider = new Capacity_Change_Decider();
		$increase = $decider->decide(30, 20, 40); self::assertSame(Capacity_Change::INCREASE, $increase->decision()); self::assertSame(20, $increase->remaining_after_change()); self::assertTrue($increase->requires_mutation());
		$decrease = $decider->decide(30, 20, 25); self::assertSame(Capacity_Change::DECREASE_ALLOWED, $decrease->decision()); self::assertSame(5, $decrease->remaining_after_change());
		$equal = $decider->decide(30, 20, 20); self::assertSame(Capacity_Change::DECREASE_ALLOWED, $equal->decision()); self::assertSame(0, $equal->remaining_after_change());
		$below = $decider->decide(30, 20, 15); self::assertSame(Capacity_Change::DECREASE_BELOW_CONSUMED, $below->decision()); self::assertFalse($below->requires_mutation());
		$unchanged = $decider->decide(30, 20, 30); self::assertSame(Capacity_Change::NO_CHANGE, $unchanged->decision()); self::assertFalse($unchanged->requires_mutation());
	}

	public function test_fake_gateway_is_idempotent_and_keeps_consumption_external_to_the_plan(): void {
		$gateway = new Inventory_Gateway_Fake(); $plan = $this->plan(array('01', '02', '03'));
		$first = $gateway->ensure($plan); $second = $gateway->ensure($plan);
		self::assertSame(1, $gateway->resource_count()); self::assertSame(30, $first->capacity()); self::assertSame(0, $first->consumed()); self::assertSame($first, $second); self::assertCount(3, $second->representations());
	}

	public function test_capacity_and_representation_changes_keep_the_resource_identity(): void {
		$gateway = new Inventory_Gateway_Fake(); $resource = $gateway->ensure($this->plan(array('01', '02'))); $gateway->set_consumed($resource->identity(), 20);
		$changed = $gateway->update_capacity($resource->identity(), 40); self::assertSame($resource->identity()->value(), $changed->identity()->value()); self::assertSame(20, $changed->consumed()); self::assertSame(20, $changed->remaining());
		$reduced = $gateway->update_capacity($resource->identity(), 25); self::assertSame($resource->identity()->value(), $reduced->identity()->value()); self::assertSame(20, $reduced->consumed()); self::assertSame(5, $reduced->remaining());
		$synchronized = $gateway->synchronize_representations($resource->identity(), $this->plan(array('01'))->representations()); self::assertSame($resource->identity()->value(), $synchronized->identity()->value()); self::assertCount(1, $synchronized->representations());
	}

	public function test_adding_a_cre_adds_a_representation_without_creating_a_second_resource(): void {
		$gateway = new Inventory_Gateway_Fake(); $resource = $gateway->ensure($this->plan(array('01')));
		$expanded = $gateway->synchronize_representations($resource->identity(), $this->plan(array('01', '02', '03'))->representations());

		self::assertSame(1, $gateway->resource_count());
		self::assertSame($resource->identity()->value(), $expanded->identity()->value());
		self::assertCount(3, $expanded->representations());
	}

	public function test_fake_gateway_rejects_capacity_below_external_consumption(): void {
		$gateway = new Inventory_Gateway_Fake(); $resource = $gateway->ensure($this->plan(array('01'))); $gateway->set_consumed($resource->identity(), 20);

		$this->expectException(\InvalidArgumentException::class);
		$gateway->update_capacity($resource->identity(), 15);
	}

	private function builder(): Resource_Plan_Builder { return new Resource_Plan_Builder(); }
	/** @param list<string> $cres @return array<string,mixed> */
	private function class_payload(array $cres, string $code = '01.01', int $capacity = 30): array { return array('class_key' => '2027:MT1:' . $code, 'capacity' => $capacity, 'cres' => $cres); }
	/** @return array<string,array{id:string,index:int}> */
	private function field_map(): array { return array('01' => array('id' => '10', 'index' => 0), '02' => array('id' => '11', 'index' => 1), '03' => array('id' => '12', 'index' => 2)); }
	/** @param list<string> $cres */
	private function plan(array $cres): Resource_Plan { $identity = Resource_Identity::from_class_key('2027:MT1:01.01'); $representations = array(); foreach ($cres as $cre) $representations[] = new Resource_Representation($cre, $this->field_map()[$cre]['id'], $identity->class_key(), $identity); return new Resource_Plan($identity, 30, $representations); }
}

final class Inventory_Gateway_Fake implements Inventory_Gateway {
	/** @var array<string,Inventory_Resource> */ private array $resources = array();
	public function find(Resource_Identity $identity): ?Inventory_Resource { return $this->resources[$identity->value()] ?? null; }
	public function ensure(Resource_Plan $plan): Inventory_Resource { $existing = $this->find($plan->identity()); if ($existing) return $existing; $resource = new Inventory_Resource($plan->identity(), $plan->capacity(), 0, $plan->representations()); $this->resources[$plan->identity()->value()] = $resource; return $resource; }
	public function update_capacity(Resource_Identity $identity, int $capacity): Inventory_Resource { $resource = $this->required($identity); $decision = (new Capacity_Change_Decider())->decide($resource->capacity(), $resource->consumed(), $capacity); if ($decision->decision() === Capacity_Change::DECREASE_BELOW_CONSUMED) throw new \InvalidArgumentException('A capacidade solicitada é inferior ao consumo externo.'); if (! $decision->requires_mutation()) return $resource; $replacement = new Inventory_Resource($identity, $capacity, $resource->consumed(), $resource->representations()); $this->resources[$identity->value()] = $replacement; return $replacement; }
	/** @param list<Resource_Representation> $representations */
	public function synchronize_representations(Resource_Identity $identity, array $representations): Inventory_Resource { $resource = $this->required($identity); $replacement = new Inventory_Resource($identity, $resource->capacity(), $resource->consumed(), $representations); $this->resources[$identity->value()] = $replacement; return $replacement; }
	public function set_consumed(Resource_Identity $identity, int $consumed): void { $resource = $this->required($identity); $this->resources[$identity->value()] = new Inventory_Resource($identity, $resource->capacity(), $consumed, $resource->representations()); }
	public function resource_count(): int { return count($this->resources); }
	private function required(Resource_Identity $identity): Inventory_Resource { $resource = $this->find($identity); if (! $resource) throw new \LogicException('Resource de teste não encontrado.'); return $resource; }
}
