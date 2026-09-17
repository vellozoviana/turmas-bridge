<?php

declare(strict_types=1);

/**
 * Declared only for static analysis. Production receives GFAPI from Gravity Forms.
 */
class GFAPI {
	public static function get_form(int $form_id): mixed {}
	public static function duplicate_form(int $form_id): mixed {}
	public static function update_form(array $form): mixed {}
}

/**
 * Declared only for static analysis. Production receives this helper from GP Inventory.
 */
function gp_inventory_type_choices(): mixed {}
