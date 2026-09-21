<?php

declare(strict_types=1);

/**
 * Declared only for static analysis. Production receives GFAPI from Gravity Forms.
 */
class GFAPI {
	public static mixed $form = null;
	public static mixed $update_result = null;
	public static mixed $update_property_result = null;
	/** @var list<array<string,mixed>> */
	public static array $updated_forms = array();
	/** @var list<array{form_id:int,property:string,value:mixed}> */
	public static array $updated_properties = array();
	public static function reset(): void { self::$form = null; self::$update_result = null; self::$update_property_result = null; self::$updated_forms = array(); self::$updated_properties = array(); }
	public static function get_form(int $form_id): mixed { return self::$form; }
	public static function duplicate_form(int $form_id): mixed {}
	public static function update_form(array $form): mixed { self::$updated_forms[] = $form; self::$form = $form; return self::$update_result; }
	public static function update_form_property(int $form_id, string $property, mixed $value): mixed { self::$updated_properties[] = array('form_id' => $form_id, 'property' => $property, 'value' => $value); if (is_array(self::$form) && $property === 'is_active') self::$form['is_active'] = (bool) $value; return self::$update_property_result; }
}

/**
 * Declared only for static analysis. Production receives this helper from GP Inventory.
 */
function gp_inventory_type_choices(): mixed {}
