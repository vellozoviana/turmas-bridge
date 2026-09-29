<?php

declare(strict_types=1);

namespace TurmasBridge\Database;

final class Schema {
	public const VERSION = '0.9.0';
	public const OPTION_NAME = 'turmas_bridge_schema_version';

	public static function install(): void {
		global $wpdb;
		// The immediately previous schema needs only the additive capacity ledger.
		if (in_array(get_option(self::OPTION_NAME), array('0.8.0', self::VERSION), true)) {
			$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
			if (is_readable($upgrade)) require_once $upgrade;
			dbDelta(self::capacity_statement($wpdb->prefix, $wpdb->get_charset_collate()));
			dbDelta(self::inventory_statement($wpdb->prefix, $wpdb->get_charset_collate()));
			if (self::capacity_ready($wpdb)) update_option(self::OPTION_NAME, self::VERSION, false);
			return;
		}
		$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
		if (is_readable($upgrade)) require_once $upgrade;
		$table = $wpdb->prefix . 'turmas_bridge_idempotency';
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta("CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			idempotency_key varchar(128) NOT NULL,
			payload_hash char(64) NOT NULL,
			state varchar(32) NOT NULL DEFAULT 'SUCCEEDED',
			publication_key varchar(80) DEFAULT NULL,
			choices_fingerprint char(64) DEFAULT NULL,
			response_status smallint(5) unsigned NOT NULL,
			response_body longtext NOT NULL,
			processing_started_at datetime DEFAULT NULL,
			reconciliation_at datetime DEFAULT NULL,
			last_error_code varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY created_at (created_at),
			KEY state (state),
			KEY publication_key (publication_key)
		) {$charset_collate};");
		$wpdb->query("UPDATE {$table} SET state = 'SUCCEEDED' WHERE state = '' OR state IS NULL");
		$materializations = $wpdb->prefix . 'turmas_bridge_materializations';
		dbDelta("CREATE TABLE {$materializations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			publication_key varchar(80) NOT NULL,
			template_id bigint(20) unsigned NOT NULL,
			form_id bigint(20) unsigned DEFAULT NULL,
			status varchar(24) NOT NULL,
			payload_hash char(64) NOT NULL,
			field_map_json longtext DEFAULT NULL,
			error_code varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY publication_key (publication_key),
			KEY form_id (form_id),
			KEY status (status)
		) {$charset_collate};");
		if (! in_array('choices_fingerprint', (array) $wpdb->get_col("SHOW COLUMNS FROM {$materializations}"), true)) {
			$wpdb->query("ALTER TABLE {$materializations} ADD COLUMN choices_fingerprint char(64) DEFAULT NULL AFTER payload_hash");
		}
		dbDelta(self::inventory_statement($wpdb->prefix, $charset_collate));
		$activation_operations = $wpdb->prefix . 'turmas_bridge_activation_operations';
		dbDelta("CREATE TABLE {$activation_operations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_key varchar(128) NOT NULL,
			publication_key varchar(80) NOT NULL,
			gravity_form_id bigint(20) unsigned NOT NULL,
			snapshot_fingerprint char(64) NOT NULL,
			state varchar(32) NOT NULL DEFAULT 'PENDING',
			error_code varchar(100) DEFAULT NULL,
			evidence_json longtext DEFAULT NULL,
			processing_started_at datetime DEFAULT NULL,
			reconciliation_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_key (operation_key),
			KEY publication_key (publication_key),
			KEY state (state),
			KEY gravity_form_id (gravity_form_id),
			KEY updated_at (updated_at)
		) {$charset_collate};");
		$reconciliation_attempts = $wpdb->prefix . 'turmas_bridge_reconciliation_attempts';
		dbDelta("CREATE TABLE {$reconciliation_attempts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reconciliation_key varchar(128) NOT NULL,
			activation_operation_key varchar(128) NOT NULL,
			publication_key varchar(80) NOT NULL,
			gravity_form_id bigint(20) unsigned NOT NULL,
			snapshot_fingerprint char(64) NOT NULL,
			state varchar(32) NOT NULL DEFAULT 'PENDING',
			result_code varchar(100) DEFAULT NULL,
			evidence_json longtext DEFAULT NULL,
			processing_started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reconciliation_key (reconciliation_key),
			KEY activation_operation_key (activation_operation_key),
			KEY state (state),
			KEY updated_at (updated_at)
		) {$charset_collate};");
		dbDelta(self::capacity_statement($wpdb->prefix, $charset_collate));
		// Do not advertise the upgrade if the new durable ledger could not be installed.
		if (! self::capacity_ready($wpdb)) return;
		update_option(self::OPTION_NAME, self::VERSION, false);
	}
	private static function capacity_ready(\wpdb $db): bool {
		$table = $db->prefix . 'turmas_bridge_capacity_operations';
		$capacity_columns = array(
			'id' => array('bigint unsigned', false, null, 'auto_increment'), 'operation_key' => array('varchar(80)', false, null, ''),
			'publication_key' => array('varchar(80)', false, null, ''), 'class_key' => array('varchar(120)', false, null, ''),
			'source_row_version' => array('bigint unsigned', false, null, ''), 'desired_capacity' => array('int unsigned', false, null, ''),
			'command_json' => array('longtext', false, null, ''), 'payload_hash' => array('char(64)', false, null, ''),
			'state' => array('varchar(32)', false, null, ''), 'attempts' => array('int unsigned', false, '0', ''),
			'revision' => array('bigint unsigned', false, '1', ''), 'capacity_before' => array('int unsigned', true, null, ''),
			'consumed_before' => array('int unsigned', true, null, ''), 'capacity_after' => array('int unsigned', true, null, ''),
			'consumed_after' => array('int unsigned', true, null, ''), 'error_code' => array('varchar(100)', true, null, ''),
			'evidence_json' => array('longtext', true, null, ''), 'created_at' => array('datetime', false, null, ''), 'updated_at' => array('datetime', false, null, ''),
		);
		$capacity_indexes = array('PRIMARY' => array(true, array('id')), 'operation_key' => array(true, array('operation_key')), 'class_version' => array(true, array('class_key', 'source_row_version')), 'publication_key' => array(false, array('publication_key')));
		$inventory_table = $db->prefix . 'turmas_bridge_inventory_resources';
		$inventory_column = array('expected_representations_json' => array('longtext', true, null, ''));
		return self::table_engine_is_innodb($db, $table)
			&& self::columns_match($db, $table, $capacity_columns)
			&& self::indexes_match($db, $table, $capacity_indexes)
			&& self::table_engine_is_innodb($db, $inventory_table)
			&& self::columns_match($db, $inventory_table, $inventory_column, false);
	}
	/** @param array<string,array{0:string,1:bool,2:?string,3:string}> $expected */
	private static function columns_match(\wpdb $db, string $table, array $expected, bool $exact = true): bool {
		$rows = $db->get_results($db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table), 'ARRAY_A');
		if ($db->last_error !== '' || ! is_array($rows)) return false;
		$actual = array();
		foreach ($rows as $row) if (is_array($row) && isset($row['COLUMN_NAME'])) $actual[(string) $row['COLUMN_NAME']] = $row;
		$actual_names = array_keys($actual); $expected_names = array_keys($expected); sort($actual_names, SORT_STRING); sort($expected_names, SORT_STRING);
		if ($exact && $actual_names !== $expected_names) return false;
		foreach ($expected as $name => [$type, $nullable, $default, $extra]) {
			$row = $actual[$name] ?? null;
			if (! is_array($row)) return false;
			$actual_type = strtolower((string) ($row['COLUMN_TYPE'] ?? ''));
			$actual_type = (string) preg_replace('/\\b(tinyint|smallint|mediumint|int|bigint)\\(\\d+\\)/', '$1', $actual_type);
			$expected_type = strtolower($type);
			$actual_default = array_key_exists('COLUMN_DEFAULT', $row) && $row['COLUMN_DEFAULT'] !== null ? (string) $row['COLUMN_DEFAULT'] : null;
			if ($actual_type !== $expected_type || ((string) ($row['IS_NULLABLE'] ?? '') === 'YES') !== $nullable || $actual_default !== $default || strtolower((string) ($row['EXTRA'] ?? '')) !== $extra) return false;
		}
		return true;
	}
	/** @param array<string,array{0:bool,1:list<string>}> $expected */
	private static function indexes_match(\wpdb $db, string $table, array $expected): bool {
		$rows = $db->get_results($db->prepare('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY INDEX_NAME,SEQ_IN_INDEX', $table), 'ARRAY_A');
		if ($db->last_error !== '' || ! is_array($rows)) return false;
		$actual = array();
		foreach ($rows as $row) {
			if (! is_array($row) || ! isset($row['INDEX_NAME'], $row['COLUMN_NAME'], $row['SEQ_IN_INDEX'], $row['NON_UNIQUE'])) continue;
			$name = (string) $row['INDEX_NAME'];
			$actual[$name]['unique'] = (int) $row['NON_UNIQUE'] === 0;
			$actual[$name]['columns'][(int) $row['SEQ_IN_INDEX']] = (string) $row['COLUMN_NAME'];
		}
		$actual_names = array_keys($actual); $expected_names = array_keys($expected); sort($actual_names, SORT_STRING); sort($expected_names, SORT_STRING);
		if ($actual_names !== $expected_names) return false;
		foreach ($expected as $name => [$unique, $columns]) {
			if (! isset($actual[$name]) || $actual[$name]['unique'] !== $unique) return false;
			ksort($actual[$name]['columns']);
			if (array_values($actual[$name]['columns']) !== $columns) return false;
		}
		return true;
	}
	private static function table_engine_is_innodb(\wpdb $db, string $table): bool {
		$engine = $db->get_var($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
		return $db->last_error === '' && strtoupper((string) $engine) === 'INNODB';
	}
	public static function capacity_statement(string $prefix, string $collate): string {
		return "CREATE TABLE {$prefix}turmas_bridge_capacity_operations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_key varchar(80) NOT NULL,
			publication_key varchar(80) NOT NULL,
			class_key varchar(120) NOT NULL,
			source_row_version bigint(20) unsigned NOT NULL,
			desired_capacity int(10) unsigned NOT NULL,
			command_json longtext NOT NULL,
			payload_hash char(64) NOT NULL,
			state varchar(32) NOT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			capacity_before int(10) unsigned DEFAULT NULL,
			consumed_before int(10) unsigned DEFAULT NULL,
			capacity_after int(10) unsigned DEFAULT NULL,
			consumed_after int(10) unsigned DEFAULT NULL,
			error_code varchar(100) DEFAULT NULL,
			evidence_json longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_key (operation_key),
			UNIQUE KEY class_version (class_key,source_row_version),
			KEY publication_key (publication_key)
		) ENGINE=InnoDB {$collate};";
	}
	public static function inventory_statement(string $prefix, string $collate): string {
		return "CREATE TABLE {$prefix}turmas_bridge_inventory_resources (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			publication_key varchar(80) NOT NULL,
			class_key varchar(120) NOT NULL,
			resource_id bigint(20) unsigned DEFAULT NULL,
			form_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL,
			expected_representations_json longtext DEFAULT NULL,
			last_error_code varchar(100) DEFAULT NULL,
			last_reconciled_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY class_key (class_key),
			KEY publication_key (publication_key),
			UNIQUE KEY uq_resource_id (resource_id),
			KEY status (status)
		) ENGINE=InnoDB {$collate};";
	}
}
