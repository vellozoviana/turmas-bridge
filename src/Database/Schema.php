<?php

declare(strict_types=1);

namespace TurmasBridge\Database;

final class Schema {
	public const VERSION = '0.2.0';
	public const OPTION_NAME = 'turmas_bridge_schema_version';

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'turmas_bridge_idempotency';
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta("CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			idempotency_key varchar(128) NOT NULL,
			payload_hash char(64) NOT NULL,
			response_status smallint(5) unsigned NOT NULL,
			response_body longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY created_at (created_at)
		) {$charset_collate};");
		update_option(self::OPTION_NAME, self::VERSION, false);
	}
}
