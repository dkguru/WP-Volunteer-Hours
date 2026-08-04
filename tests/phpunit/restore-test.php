<?php

/**
 * Tests for backup/restore functionality.
 */

class VH_Restore_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Create admin user context
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_restore_preserve_ids() {
		global $wpdb;
		$p = $wpdb->prefix;

		// Build a CSV backup in temp
		$tmp = tempnam( sys_get_temp_dir(), 'vhbk' );
		// Build CSV content manually to avoid direct fopen/fputcsv in tests.
		$rows = array();
		$rows[] = array( 'type', 'id', 'name', 'active', 'created_at' );
		$rows[] = array( 'project', 123, 'Restored Project', 1, current_time( 'mysql' ) );
		$rows[] = array( 'type', 'id', 'user_id', 'work_date', 'hours', 'description', 'reviewed', 'paid', 'created_at', 'updated_at', 'project_ids' );
		$rows[] = array( 'entry', 456, $this->admin_id, '2024-01-01', 1.5, 'Restore test', 0, 0, current_time( 'mysql' ), current_time( 'mysql' ), '123' );
		$lines = array();
		foreach ( $rows as $r ) {
			$escaped = array_map( function( $v ) { return '"' . str_replace( '"', '""', (string) $v ) . '"'; }, $r );
			$lines[] = implode( ',', $escaped );
		}
		// Use WP_Filesystem if available to write the temp file for tests.
		if ( function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
			global $wp_filesystem;
			$wp_filesystem->put_contents( $tmp, implode( "\n", $lines ), FS_CHMOD_FILE );
		} else {
			file_put_contents( $tmp, implode( "\n", $lines ) );
		}

		// Call the restore helper with replace and preserve_ids
		$res = VH_Admin::restore_backup_from_file( $tmp, true, true );
		// Use WP file API to delete temporary file if available.
		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $tmp );
		} else {
			@unlink( $tmp );
		}

		$this->assertEmpty( $res['errors'], 'Restore reported errors: ' . implode( '; ', $res['errors'] ) );
		$this->assertEquals( 1, $res['projects'] );
		$this->assertEquals( 1, $res['entries'] );

		// Verify project exists with id 123
		$projects_table = $p . 'vh_projects';
		$proj = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE id = %d", 123 ) ); // phpcs:ignore
		$this->assertNotEmpty( $proj );

		// Verify entry exists with id 456
		$entries_table = $p . 'vh_entries';
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$entries_table} WHERE id = %d", 456 ) ); // phpcs:ignore
		$this->assertNotEmpty( $entry );
	}
}
