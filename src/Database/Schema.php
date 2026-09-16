<?php

declare(strict_types=1);

namespace TurmasBridge\Database;

final class Schema {
	public const VERSION = '0.5.0';
	public const OPTION_NAME = 'turmas_bridge_schema_version';

	public static function install(): void {
		global $wpdb;
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
		update_option(self::OPTION_NAME, self::VERSION, false);
	}
}
