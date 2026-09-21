<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Read-only evidence about the Resources belonging to one materialization. */
interface Inventory_Status_Gateway {
	/** @return array{status:string,resources:list<array<string,mixed>>,blockers:list<array{code:string,message:string}>} */
	public function read(string $publication_key, int $form_id, array $form): array;
}
