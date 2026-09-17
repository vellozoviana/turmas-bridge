<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Technology-neutral boundary for one logical shared-capacity Resource. */
interface Inventory_Gateway {
	public function find(Resource_Identity $identity): ?Inventory_Resource;
	public function ensure(Resource_Plan $plan): Inventory_Resource;
}
