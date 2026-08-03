<?php
/**
 * CSV exports (admin-post endpoints).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VH_Export {

	public function __construct() {
		add_action( 'admin_post_vh_export_my_hours', array( $this, 'export_my_hours' ) );
		add_action( 'admin_post_vh_export_entries', array( $this, 'export_entries' ) );
		add_action( 'admin_post_vh_export_report', array( $this, 'export_report' ) );
	}

	/* ---------- URL builders ---------- */

	public static function entries_csv_url( $month, $user_id = 0, $project_id = 0, $status = '' ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'vh_export_entries',
					'vh_month'   => $month,
					'vh_user'    => $user_id,
					'vh_project' => $project_id,
					'vh_status'  => $status,
				),
				admin_url( 'admin-post.php' )
			),
			'vh_export_admin',
			'vh_nonce'
		);
	}

	public static function report_csv_url( $which, $from, $to ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'vh_export_report',
					'vh_which' => $which,
					'vh_from'  => $from,
					'vh_to'    => $to,
				),
				admin_url( 'admin-post.php' )
			),
			'vh_export_admin',
			'vh_nonce'
		);
	}

	/* ---------- Handlers ---------- */

	/**
	 * A volunteer exporting their own month.
	 */
	public function export_my_hours() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in.', 'volunteer-hours' ) );
		}
		check_admin_referer( 'vh_export_my', 'vh_nonce' );

		$month = isset( $_GET['vh_month'] ) && preg_match( '/^\d{4}-\d{2}$/', wp_unslash( $_GET['vh_month'] ) ) ? sanitize_text_field( wp_unslash( $_GET['vh_month'] ) ) : wp_date( 'Y-m' ); // phpcs:ignore
		$from  = $month . '-01';
		$to    = gmdate( 'Y-m-t', strtotime( $from ) );

		$entries = VH_Data::get_entries(
			array(
				'user_id' => get_current_user_id(),
				'from'    => $from,
				'to'      => $to,
			)
		);

		$rows   = array( array( 'Date', 'Hours', 'Projects', 'Description', 'Status' ) );
		$total  = 0;
		foreach ( $entries as $e ) {
			$total  += (float) $e->hours;
			$rows[] = array( $e->work_date, VH_Frontend::fmt_hours( $e->hours ), $e->project_names, $e->description, VH_Data::status_label( $e ) );
		}
		$rows[] = array( 'Total', VH_Frontend::fmt_hours( $total ), '', '', '' );

		$this->send_csv( 'my-hours-' . $month . '.csv', $rows );
	}

	/**
	 * Admin: all entries for a filtered month.
	 */
	public function export_entries() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'volunteer-hours' ) );
		}
		check_admin_referer( 'vh_export_admin', 'vh_nonce' );

		$month      = isset( $_GET['vh_month'] ) && preg_match( '/^\d{4}-\d{2}$/', wp_unslash( $_GET['vh_month'] ) ) ? sanitize_text_field( wp_unslash( $_GET['vh_month'] ) ) : wp_date( 'Y-m' ); // phpcs:ignore
		$user_id    = isset( $_GET['vh_user'] ) ? (int) $_GET['vh_user'] : 0;
		$project_id = isset( $_GET['vh_project'] ) ? (int) $_GET['vh_project'] : 0;
		$from       = $month . '-01';
		$to         = gmdate( 'Y-m-t', strtotime( $from ) );

		$status  = isset( $_GET['vh_status'] ) ? sanitize_key( $_GET['vh_status'] ) : '';
		$entries = VH_Data::get_entries(
			array(
				'from'       => $from,
				'to'         => $to,
				'user_id'    => $user_id,
				'project_id' => $project_id,
				'status'     => $status,
			)
		);

		$rows = array( array( 'Date', 'User', 'Hours', 'Projects', 'Description', 'Reviewed', 'Paid' ) );
		foreach ( $entries as $e ) {
			$rows[] = array( $e->work_date, VH_Data::user_label( $e->user_id ), VH_Frontend::fmt_hours( $e->hours ), $e->project_names, $e->description, (int) $e->reviewed ? 'Yes' : 'No', (int) $e->paid ? 'Yes' : 'No' );
		}

		$this->send_csv( 'volunteer-entries-' . $month . '.csv', $rows );
	}

	/**
	 * Admin: aggregate reports (per user / per project) for a date range.
	 */
	public function export_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'volunteer-hours' ) );
		}
		check_admin_referer( 'vh_export_admin', 'vh_nonce' );

		$which = isset( $_GET['vh_which'] ) && 'projects' === $_GET['vh_which'] ? 'projects' : 'users';
		$from  = isset( $_GET['vh_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['vh_from'] ) ) ? sanitize_text_field( wp_unslash( $_GET['vh_from'] ) ) : wp_date( 'Y-m-01' ); // phpcs:ignore
		$to    = isset( $_GET['vh_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['vh_to'] ) ) ? sanitize_text_field( wp_unslash( $_GET['vh_to'] ) ) : wp_date( 'Y-m-t' ); // phpcs:ignore

		if ( 'projects' === $which ) {
			$data = VH_Data::report_hours_per_project( $from, $to );
			$rows = array( array( 'Project', 'Entries', 'Hours', 'From', 'To' ) );
			foreach ( $data as $r ) {
				$rows[] = array( $r->name, $r->entry_count, VH_Frontend::fmt_hours( $r->total_hours ), $from, $to );
			}
		} else {
			$data = VH_Data::report_hours_per_user( $from, $to );
			$rows = array( array( 'User', 'Entries', 'Hours', 'From', 'To' ) );
			foreach ( $data as $r ) {
				$rows[] = array( VH_Data::user_label( $r->user_id ), $r->entry_count, VH_Frontend::fmt_hours( $r->total_hours ), $from, $to );
			}
		}

		$this->send_csv( 'hours-per-' . $which . '-' . $from . '-to-' . $to . '.csv', $rows );
	}

	/* ---------- Output ---------- */

	/**
	 * Stream rows as a CSV download. Cells are formula-escaped for Excel safety.
	 */
	private function send_csv( $filename, $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens non-ASCII names correctly.
		fwrite( $out, "\xEF\xBB\xBF" );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( $this, 'escape_cell' ), $row ) );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	/**
	 * Prevent CSV formula injection when the file is opened in Excel.
	 */
	private function escape_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
