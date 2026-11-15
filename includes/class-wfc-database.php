<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WFC_Database {
	const VERSION = '0.2.0';

	public static function table( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'wfc_' . $suffix;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$stats   = self::table( 'snapshots' );
		$claims  = self::table( 'claims' );

		$sql_stats = "CREATE TABLE {$stats} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			instance_id varchar(64) NOT NULL,
			upload_id char(36) DEFAULT NULL,
			recorded_at datetime NOT NULL,
			payload longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY instance_upload (instance_id, upload_id),
			KEY instance_recorded (instance_id, recorded_at),
			KEY recorded_at (recorded_at)
		) {$charset};";

		$sql_claims = "CREATE TABLE {$claims} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			instance_id varchar(64) NOT NULL,
			claim_key varchar(191) NOT NULL,
			app_id bigint(20) unsigned NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			claimed_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY instance_claim (instance_id, claim_key),
			KEY claimed_at (claimed_at),
			KEY app_id (app_id)
		) {$charset};";

		dbDelta( $sql_stats );
		dbDelta( $sql_claims );

		if ( false === get_option( 'wfc_settings' ) ) {
			add_option(
				'wfc_settings',
				array(
					'token_hash' => '',
					'instance_label' => 'FarmWatch',
					'retention_days' => 365,
				),
				'',
				false
			);
		}

		update_option( 'wfc_db_version', self::VERSION );
	}
}
