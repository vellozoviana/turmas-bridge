<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

interface Inventory_Mapping_Store {
	/** @return array<string,mixed>|null */
	public function find(Resource_Identity $identity): ?array;
	public function reserve(Resource_Identity $identity, int $form_id): bool;
	public function discard_provisioning(Resource_Identity $identity): bool;
	public function resource_created(Resource_Identity $identity, int $resource_id, int $form_id): bool;
	public function healthy(Resource_Identity $identity, int $form_id): bool;
	public function reconciliation_required(Resource_Identity $identity, string $error_code): bool;
	public function acquire_lock(Resource_Identity $identity): bool;
	public function release_lock(Resource_Identity $identity): void;
}
