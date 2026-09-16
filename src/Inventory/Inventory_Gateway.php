<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Technology-neutral boundary. No production GP Inventory adapter exists in 12D-B0. */
interface Inventory_Gateway {
	public function find(Resource_Identity $identity): ?Inventory_Resource;
	public function ensure(Resource_Plan $plan): Inventory_Resource;
	public function update_capacity(Resource_Identity $identity, int $capacity): Inventory_Resource;
	/** @param list<Resource_Representation> $representations */
	public function synchronize_representations(Resource_Identity $identity, array $representations): Inventory_Resource;
}
