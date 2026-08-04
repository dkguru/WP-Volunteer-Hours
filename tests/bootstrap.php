<?php
/**
 * PHPUnit bootstrap for plugin tests.
 *
 * This file expects the WP test library to be available via the WP_TESTS_DIR
 * environment variable. See TESTING.md for setup notes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $tests_dir ) {
	// Fallback: common location when using wp-cli scaffold or other setups.
	$tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! is_dir( $tests_dir ) ) {
	fwrite( STDERR, "Could not find WP test library. Set WP_TESTS_DIR environment variable to its path." );
	exit(1);
}

// Load necessary test functions and bootstrap.
require_once $tests_dir . '/includes/functions.php';
require_once $tests_dir . '/includes/bootstrap.php';

// Load the plugin file.
require_once dirname( __DIR__ ) . '/wp-volunteer-hours.php';

// Make sure exports class is loaded.
require_once dirname( __DIR__ ) . '/includes/class-vh-export.php';

// Utilities for tests can be added here.
