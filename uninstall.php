<?php
/**
 * Uninstall Volunteer Hours: remove the three custom tables and options.
 * Runs only when the plugin is deleted from the Plugins screen.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vh_entry_projects" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vh_entries" );        // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vh_projects" );       // phpcs:ignore

delete_option( 'vh_db_version' );
