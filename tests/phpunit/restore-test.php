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
		$fh = fopen( $tmp, 'w' );
		// Projects header and one project with id 123
		fputcsv( $fh, array( 'type', 'id', 'name', 'active', 'created_at' ) );
		fputcsv( $fh, array( 'project', 123, 'Restored Project', 1, current_time( 'mysql' ) ) );
		// Entries header and one entry referencing project 123
		fputcsv( $fh, array( 'type', 'id', 'user_id', 'work_date', 'hours', 'description', 'reviewed', 'paid', 'created_at', 'updated_at', 'project_ids' ) );
		fputcsv( $fh, array( 'entry', 456, $this->admin_id, '2024-01-01', 1.5, 'Restore test', 0, 0, current_time( 'mysql' ), current_time( 'mysql' ), '123' ) );
		fclose( $fh );

		// Call the restore helper with replace and preserve_ids
		$res = VH_Admin::restore_backup_from_file( $tmp, true, true );
		@unlink( $tmp );

		$this->assertEmpty( $res['errors'], 'Restore reported errors: ' . implode( '; ', $res['errors'] ) );
		$this->assertEquals( 1, $res['projects'] );
		$this->assertEquals( 1, $res['entries'] );

		// Verify project exists with id 123
		$proj = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}vh_projects WHERE id = %d", 123 ) ); // phpcs:ignore
		$this->assertNotEmpty( $proj );

		// Verify entry exists with id 456
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}vh_entries WHERE id = %d", 456 ) ); // phpcs:ignore
		$this->assertNotEmpty( $entry );
	}
}
