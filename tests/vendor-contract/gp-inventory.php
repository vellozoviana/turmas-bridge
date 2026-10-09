<?php
/** Isolated contract harness: real GP Inventory source; simulated WP/GF storage, never the LAB. */
declare(strict_types=1);

$package = $argv[1] ?? ''; $contract = $argv[2] ?? '';
$zip = new ZipArchive();
if ($zip->open($package) !== true) throw new RuntimeException('Authorized package unavailable.');
$header = $zip->getFromName('gp-inventory/gp-inventory.php');
if (! is_string($header) || ! preg_match('/Version:\s*([^\r\n]+)/', $header, $match) || trim($match[1]) !== '1.0.32') throw new RuntimeException('Expected GP Inventory 1.0.32.');
$zip->close();
define('GP_INVENTORY_VERSION', '1.0.32');
$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (! $condition) throw new RuntimeException($message); $checks++; }

$hooks = array(); $meta = array(); $cache_flushes = array();
function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$hook][$priority][] = array($callback, $args); }
function add_action($hook, $callback, $priority = 10, $args = 1) { add_filter($hook, $callback, $priority, $args); }
function remove_filter($hook, $callback, $priority = 10) { foreach ($GLOBALS['hooks'][$hook][$priority] ?? array() as $i => $row) if ($row[0] === $callback) unset($GLOBALS['hooks'][$hook][$priority][$i]); }
function apply_filters($hook, $value, ...$args) { $rows = $GLOBALS['hooks'][$hook] ?? array(); ksort($rows); foreach ($rows as $callbacks) foreach ($callbacks as [$callback, $count]) $value = $callback(...array_slice(array_merge(array($value), $args), 0, $count)); return $value; }
function gf_apply_filters($hook, $value, ...$args) { for ($i = 1; $i <= count($hook); $i++) $value = apply_filters(implode('_', array_slice($hook, 0, $i)), $value, ...$args); return $value; }
function do_action($hook, ...$args) { $rows = $GLOBALS['hooks'][$hook] ?? array(); ksort($rows); foreach ($rows as $callbacks) foreach ($callbacks as [$callback, $count]) $callback(...array_slice($args, 0, $count)); }
function rgar($value, $key, $default = null) { return $value[$key] ?? $default; }
function rgpost($key) { return $_POST[$key] ?? ''; }
function rgget($key) { return ''; }
function get_post_meta($id, $key, $single = false) { $value = $GLOBALS['meta'][$id][$key] ?? ($single ? '' : array()); return $single ? $value : (array) $value; }
function add_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key][] = $value; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; return true; }
function get_post_type($id) { return $id === 801 ? 'gpi_resource' : null; }
function get_posts($args) { return array(801); }
function wp_insert_post($args, $error = false) { $GLOBALS['created_post'] = $args; return 801; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_cache_flush_group($group) { $GLOBALS['cache_flushes'][] = $group; }
function wp_validate_boolean($value) { return filter_var($value, FILTER_VALIDATE_BOOLEAN); }
function esc_sql($value) { return addslashes((string) $value); }
class WP_Error { public function __construct(public $code = '', public $message = '', public $data = array()) {} }
#[AllowDynamicProperties]
class GF_Field implements ArrayAccess {
	public function __construct(array $data) { foreach ($data as $key => $value) $this->{$key} = $value; }
	public function offsetExists(mixed $offset): bool { return isset($this->{$offset}); }
	public function offsetGet(mixed $offset): mixed { return $this->{$offset} ?? null; }
	public function offsetSet(mixed $offset, mixed $value): void { $this->{$offset} = $value; }
	public function offsetUnset(mixed $offset): void { unset($this->{$offset}); }
	public function get_input_type() { return $this->type; }
}
class GFAPI {
	public static array $forms = array();
	public static function get_form($id) { return self::$forms[$id] ?? false; }
	public static function update_form($form) { self::$forms[$form['id']] = $form; return true; }
	public static function get_field($form, $id) { return GFFormsModel::get_field($form, $id); }
}
class GFFormsModel {
	public static function get_field($form, $id) { if (! is_array($form)) $form = GFAPI::get_form($form); foreach ($form['fields'] ?? array() as $field) if ((string) $field->id === (string) $id) return $field; return null; }
	public static function get_input_type($field) { return $field->get_input_type(); }
}
class GFCommon { public static function is_product_field($type) { return $type === 'product'; } }
class ContractWpdb {
	public string $prefix = 'wp_'; public string $postmeta = 'wp_postmeta'; public string $last_error = ''; public array $queries = array(); public array $rows = array();
	public function prepare($query, ...$values) { if (isset($values[0]) && is_array($values[0])) $values = $values[0]; $i = 0; return preg_replace_callback('/%[ds]/', static function ($m) use (&$i, $values) { $value = $values[$i++]; return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'"; }, $query); }
	public function query($sql) { return 1; }
	public function get_results($sql, $output) { $this->queries[] = $sql; return $this->rows; }
}
$wpdb = new ContractWpdb();
foreach (array('class-inventory-type.php', 'class-inventory-type-simple.php', 'class-inventory-type-advanced.php', 'class-inventory-type-choices.php', 'class-resources.php') as $file) require 'zip://' . $package . '#gp-inventory/includes/' . $file;
spl_autoload_register(static function ($class) { if (str_starts_with($class, 'TurmasBridge\\')) { $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, 13)) . '.php'; if (is_file($file)) require $file; } });
$fields = array();
foreach (array('04' => 3, '05' => 4, '11' => 5) as $cre => $id) $fields[] = new GF_Field(array('id' => $id, 'formId' => 800, 'type' => 'select', 'adminLabel' => 'turma_cre_' . $cre, 'gpiInventory' => 'advanced', 'gpiResource' => '801', 'gpiResourcePropertyMap' => array(), 'choices' => array(array('value' => '2095:UNIT:01.01', 'inventory_limit' => 5)), 'isRequired' => true, 'conditionalLogic' => array('actionType' => 'show', 'logicType' => 'all', 'rules' => array(array('fieldId' => 6, 'operator' => 'is', 'value' => (string) $cre)))));
GFAPI::$forms[800] = array('id' => 800, 'is_active' => false, 'turmasBridgeCreExclusive' => true, 'fields' => $fields);
$meta[801] = array('gpi_field' => array('800_3', '800_4', '800_5'), 'gpi_inventory_limit' => '5', 'gpi_choice_based' => '0', 'gpi_properties' => array());
$choices = gp_inventory_type_choices();
add_filter('gpi_limit_by_paid_entries_only', '__return_false');
function __return_false() { return false; }
function vendor_query($field): array { $choices = gp_inventory_type_choices(); try { $choices->add_query_hooks($field); return $choices->get_claimed_inventory_query($field); } finally { $choices->remove_query_hooks(); } }

switch ($contract) {
	case 'resource':
		$model = (new ReflectionClass(GP_Inventory_Resources::class))->newInstanceWithoutConstructor();
		check(GP_Inventory_Resources::RESOURCE_POST_TYPE === 'gpi_resource', 'Resource post type changed.');
		check($model->get_resource_inventory_limit(801) === 5, 'Capacity meta changed.');
		check($model->get_resource_properties(801) === array(), 'Properties changed.');
		check(! $model->is_resource_choice_based(801), 'Resource choice mode changed.'); break;
	case 'bindings':
		$meta[801]['gpi_field'] = array();
		$choices->attach_advanced_fields_to_resource(GFAPI::$forms[800]); $choices->attach_advanced_fields_to_resource(GFAPI::$forms[800]);
		check($meta[801]['gpi_field'] === array('800_3', '800_4', '800_5'), 'Binding duplication or format change.');
		check(count($choices->get_resource_fields(801)) === 3, 'Resource fields not resolved.'); break;
	case 'shared_query':
		GFAPI::$forms[900] = array('id' => 900, 'fields' => array(new GF_Field(array_merge(get_object_vars($fields[0]), array('formId' => 900, 'id' => 7)))));
		$meta[801]['gpi_field'][] = '900_7';
		foreach ($fields as $field) { $query = vendor_query($field); foreach (array(3, 4, 5, 7) as $id) check(str_contains($query['where'], "em.meta_key = '$id'"), 'Shared field omitted.'); check(str_contains($query['where'], "'active', 'archived'"), 'Entry states changed.'); }
		break;
	case 'fallback':
		$meta[801]['gpi_field'] = array('bad', array('malformed'), '999_1', '800_99');
		check(count($choices->get_resource_fields(801, 800)) === 3, 'Same-form recovery changed.');
		check(count($choices->get_resource_fields(801)) === 0, 'Malformed index trusted.');
		$query = vendor_query($fields[0]); check(str_contains($query['where'], "em.meta_key = '5'"), 'Fallback not reflected in query.'); break;
	case 'quantity':
		$query = vendor_query($fields[0]);
		check(str_contains($query['select'], 'SUM(IF(em_quantity.meta_value IS NOT NULL, em_quantity.meta_value, 1)'), 'Quantity semantics changed.');
		check(str_contains($query['join'], 'LEFT JOIN wp_gf_entry_meta em_quantity'), 'Missing no-quantity alias.');
		add_filter('gpi_quantity_input_ids', static fn ($ids, $field) => array($field->id + 10), 10, 2);
		$query = vendor_query($fields[0]); check(str_contains($query['join'], 'INNER JOIN wp_gf_entry_meta em_quantity'), 'Quantity join changed.');
		foreach (array(13, 14, 15) as $id) check(str_contains($query['join'], "IN ( $id )"), 'Shared quantity input omitted.'); break;
	case 'cleanup':
		for ($i = 0; $i < 3; $i++) vendor_query($fields[$i]);
		check(array_sum(array_map('count', $hooks['gpi_query'] ?? array())) === 0, 'Vendor query hooks leaked.');
		check(isset($hooks['gform_pre_validation'][11]), 'Vendor pre-validation priority changed.');
		check(isset($hooks['gform_validation'][9], $hooks['gform_validation'][10]), 'Vendor validation priority changed.'); break;
	case 'cache':
		$choices->flush_choice_count_cache(GFAPI::$forms[800]); check($cache_flushes === array('gpi_800'), 'Cache scope changed.'); break;
	case 'multi_cre':
		GFAPI::$forms[800]['fields'][] = new GF_Field(array('id' => 6, 'type' => 'select', 'adminLabel' => 'turmas_cre_selector', 'isRequired' => true, 'placeholder' => 'Selecione', 'choices' => array(array('value' => '04'), array('value' => '05'), array('value' => '11'))));
		foreach (array('04' => 3, '05' => 4, '11' => 5) as $cre => $id) { $result = TurmasBridge\Choices\Cre_Submission_Guard::inspect(GFAPI::$forms[800], array('input_6' => (string) $cre, 'input_' . $id => '2095:UNIT:01.01')); check($result['valid'] && count($result['post']) === 2, 'One-CRE submission rejected.'); }
		$result = TurmasBridge\Choices\Cre_Submission_Guard::inspect(GFAPI::$forms[800], array('input_6' => '04', 'input_3' => '2095:UNIT:01.01', 'input_4' => '2095:UNIT:01.01'));
		check(! $result['valid'] && count($result['post']) === 1, 'Multiple claims accepted.'); break;
	case 'adapter':
	case 'synchronize':
		$identity = TurmasBridge\Inventory\Resource_Identity::from_class_key('2095:UNIT:01.01'); $representations = array();
		foreach (array('04' => 3, '05' => 4, '11' => 5) as $cre => $id) $representations[] = new TurmasBridge\Inventory\Resource_Representation((string) $cre, (string) $id, $identity->class_key(), $identity);
		$desired = $contract === 'synchronize' ? 4 : 5;
		$plan = new TurmasBridge\Inventory\Resource_Plan($identity, $desired, $representations, 800);
		$wpdb->rows = array(array('entry_id' => '30', 'class_choice' => $identity->class_key(), 'consumed_quantity' => '2', 'malformed_quantity_count' => '0'), array('entry_id' => '31', 'class_choice' => $identity->class_key(), 'consumed_quantity' => '1', 'malformed_quantity_count' => '0'));
		$operations = new TurmasBridge\Inventory\WordPress_GP_Inventory_Operations(); check($operations->is_available(), 'Actual vendor unavailable.');
		$state = $contract === 'synchronize' ? $operations->synchronize($plan, 801) : $operations->inspect($plan, 801);
		check($state['healthy'] && $state['capacity'] === $desired && $state['consumed'] === 3, 'Adapter quantity/dedupe changed.');
		check($meta[801]['gpi_inventory_limit'] === (string) $desired, 'Resource capacity not persisted.');
		check(count($wpdb->queries) === 3, 'Not all representations read.');
		foreach ($wpdb->queries as $query) { check(str_contains($query, 'GROUP BY e.id, em.meta_value'), 'Entry grouping missing.'); check(str_contains($query, 'em_quantity'), 'Vendor quantity alias missing.'); }
		check(array_sum(array_map('count', $hooks['gpi_query'] ?? array())) === 0, 'Adapter hook cleanup failed.'); break;
	default: throw new RuntimeException('Unknown contract.');
}
echo json_encode(array('version' => GP_INVENTORY_VERSION, 'contract' => $contract, 'checks' => $checks), JSON_THROW_ON_ERROR) . "\n";
