<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Read-only evidence about the Resources belonging to one materialization. */
interface Inventory_Status_Gateway {
	/** @return array{status:string,resources:list<array<string,mixed>>,blockers:list<array{code:string,message:string}>} */
	public function read(string $publication_key, int $form_id, array $form): array;
	/** Same read-only evidence for the post-activation context. */
	/** @return array{status:string,resources:list<array<string,mixed>>,blockers:list<array{code:string,message:string}>} */
	public function read_post_activation(string $publication_key, int $form_id, array $form): array;
}
