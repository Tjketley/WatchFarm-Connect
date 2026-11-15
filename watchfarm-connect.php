<?php
/**
 * Plugin Name: WatchFarm Connect
 * Description: Private, one-way statistics receiver and dashboard for FarmWatch.
 * Version: 0.2.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: WatchFarm
 * License: Apache-2.0
 * Text Domain: watchfarm-connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WFC_VERSION', '0.2.0' );
define( 'WFC_FILE', __FILE__ );
define( 'WFC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WFC_URL', plugin_dir_url( __FILE__ ) );

require_once WFC_DIR . 'includes/class-wfc-database.php';
require_once WFC_DIR . 'includes/class-wfc-auth.php';
require_once WFC_DIR . 'includes/class-wfc-rest.php';
require_once WFC_DIR . 'includes/class-wfc-admin.php';

register_activation_hook( __FILE__, array( 'WFC_Database', 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( get_option( 'wfc_db_version' ) !== WFC_Database::VERSION ) {
			WFC_Database::install();
		}
		WFC_REST::init();
		WFC_Admin::init();
	}
);
