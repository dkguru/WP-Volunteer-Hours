<?php
/**
 * Plugin Name:       Volunteer Hours
 * Plugin URI:        https://livingislands.org/
 * Description:       Lets logged-in volunteers register hours against the organization's projects, view and correct their own entries, and gives administrators per-user and per-project hour reports with CSV export. Use the [volunteer_hours] shortcode on any page.
 * Version:           1.1.5
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Living Islands
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       volunteer-hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VH_VERSION', '1.1.5' );
define( 'VH_PLUGIN_FILE', __FILE__ );
define( 'VH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once VH_PLUGIN_DIR . 'includes/class-vh-data.php';
require_once VH_PLUGIN_DIR . 'includes/class-vh-frontend.php';
require_once VH_PLUGIN_DIR . 'includes/class-vh-admin.php';
require_once VH_PLUGIN_DIR . 'includes/class-vh-export.php';

/**
 * Create the three tables on activation.
 */
function vh_activate() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$p       = $wpdb->prefix;

	dbDelta( "CREATE TABLE {$p}vh_projects (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(190) NOT NULL,
		active TINYINT(1) NOT NULL DEFAULT 1,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY active (active)
	) $charset;" );

	dbDelta( "CREATE TABLE {$p}vh_entries (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT(20) UNSIGNED NOT NULL,
		work_date DATE NOT NULL,
		hours DECIMAL(6,2) NOT NULL,
		description TEXT NOT NULL,
		reviewed TINYINT(1) NOT NULL DEFAULT 0,
		paid TINYINT(1) NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY work_date (work_date),
		KEY reviewed (reviewed),
		KEY paid (paid)
	) $charset;" );

	dbDelta( "CREATE TABLE {$p}vh_entry_projects (
		entry_id BIGINT(20) UNSIGNED NOT NULL,
		project_id BIGINT(20) UNSIGNED NOT NULL,
		PRIMARY KEY  (entry_id, project_id),
		KEY project_id (project_id)
	) $charset;" );

	update_option( 'vh_db_version', VH_VERSION );
}
register_activation_hook( __FILE__, 'vh_activate' );

/**
 * If the plugin files were updated in place (no re-activation), bring the schema up to date.
 */
function vh_maybe_upgrade() {
	global $wpdb;
	$tables_ok = true;
	foreach ( array( 'vh_projects', 'vh_entries', 'vh_entry_projects' ) as $t ) {
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) ) ) {
			$tables_ok = false;
			break;
		}
	}
	if ( ! $tables_ok || get_option( 'vh_db_version' ) !== VH_VERSION ) {
		vh_activate();
	}
}
add_action( 'plugins_loaded', 'vh_maybe_upgrade', 5 );

/**
 * Boot.
 */
function vh_init() {
	new VH_Frontend();
	new VH_Export();
	if ( is_admin() ) {
		new VH_Admin();
	}
}
add_action( 'plugins_loaded', 'vh_init' );

/**
 * Minimal stylesheet, front and back.
 */
function vh_enqueue_styles() {
	wp_enqueue_style( 'vh-styles', VH_PLUGIN_URL . 'assets/vh.css', array(), VH_VERSION );
}
add_action( 'wp_enqueue_scripts', 'vh_enqueue_styles' );
add_action( 'admin_enqueue_scripts', 'vh_enqueue_styles' );
