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
		$inventory_resources = $wpdb->prefix . 'turmas_bridge_inventory_resources';
		dbDelta("CREATE TABLE {$inventory_resources} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			publication_key varchar(80) NOT NULL,
			class_key varchar(120) NOT NULL,
			resource_id bigint(20) unsigned DEFAULT NULL,
			form_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL,
			last_error_code varchar(100) DEFAULT NULL,
			last_reconciled_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY class_key (class_key),
			KEY publication_key (publication_key),
			UNIQUE KEY uq_resource_id (resource_id),
			KEY status (status)
		) {$charset_collate};");
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
		if ($db->last_error !== '') return false;
		$table = $db->prefix . 'turmas_bridge_capacity_operations';
		if ($db->get_var($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table)) !== 'InnoDB') return false;
		$columns = "'id','operation_key','publication_key','class_key','source_row_version','desired_capacity','command_json','payload_hash','state','attempts','revision','capacity_before','consumed_before','capacity_after','consumed_after','error_code','evidence_json','created_at','updated_at'";
		if ((int) $db->get_var($db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN ($columns)", $table)) !== 19) return false;
		return (int) $db->get_var($db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND NON_UNIQUE=0 AND INDEX_NAME IN ('PRIMARY','operation_key','class_version')", $table)) === 4;
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
}
