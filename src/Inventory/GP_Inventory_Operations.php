<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Anti-corruption layer for GP Inventory internals. Not a public vendor API. */
interface GP_Inventory_Operations {
	public function is_available(): bool;
	public function find_resource(Resource_Identity $identity): ?int;
	public function resource_exists(int $resource_id): bool;
	public function create_resource(Resource_Identity $identity): int;
	/** @return array{capacity:int,consumed:int,healthy:bool,reason:?string} */
	public function inspect(Resource_Plan $plan, int $resource_id): array;
	/** @return array{capacity:int,consumed:int,healthy:bool,reason:?string} */
	public function synchronize(Resource_Plan $plan, int $resource_id): array;
}
