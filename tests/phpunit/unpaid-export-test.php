<?php

/**
 * Tests for unpaid hours CSV export.
 */
// No-op patch: text domain not present in this test file.

class VH_Unpaid_Export_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Ensure current user is an administrator for admin_post endpoints.
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_unpaid_export_endpoint_requires_permission() {
		// Non-admin should be blocked.
		wp_set_current_user( 0 );
		$this->expectOutputString('');
		// Simulate request: calling method directly will wp_die on permission failure.
		$this->expectException( 'WPDieException' );
		VH_Export::export_unpaid();
	}

	public function test_unpaid_export_outputs_csv() {
		wp_set_current_user( $this->admin_id );

		// Create a user and an unpaid entry via direct DB insert for quick setup.
		$user_id = $this->factory->user->create( array( 'display_name' => 'Alice Tester', 'user_email' => 'alice@example.org' ) );

		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( $wpdb->prefix . 'vh_entries', array( 'user_id' => $user_id, 'work_date' => '2024-01-10', 'hours' => 2.5, 'description' => 'Test work', 'reviewed' => 0, 'paid' => 0, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%s', '%f', '%s', '%d', '%d', '%s', '%s' ) );
		$entry_id = (int) $wpdb->insert_id;

		// Attach a project row so project_names can resolve (optional).
		// Call export_unpaid and capture output.
		// We need a valid nonce; generate one and set $_GET parameters.
		$_GET['vh_from'] = '2024-01-01';
		$_GET['vh_to']   = '2024-01-31';
		// Set up a valid nonce for vh_export_admin
		$_GET['vh_nonce'] = wp_create_nonce( 'vh_export_admin' );

		// Capture output buffer since export_unpaid exits after sending CSV.
		ob_start();
		try {
			VH_Export::export_unpaid();
		} catch ( Exception $e ) {
			// export_unpaid exits; ignore.
		}
		$output = ob_get_clean();

		// Basic checks: CSV contains header and our user's email.
		$this->assertStringContainsString( 'Date,Entry ID,User,User Email,Hours,Projects,Description,Reviewed', $output );
		$this->assertStringContainsString( 'alice@example.org', $output );
	}
}
