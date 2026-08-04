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
		add_action( 'admin_post_vh_export_unpaid', array( $this, 'export_unpaid' ) );
		add_action( 'admin_post_vh_export_backup', array( $this, 'export_backup' ) );
	}

	/* ---------- URL builders ---------- */

	public static function entries_csv_url( $month, $user_id = 0, $project_id = 0, $status = '' ) {
		return self::build_export_url( 'vh_export_entries', array(
			'vh_month'   => $month,
			'vh_user'    => $user_id,
			'vh_project' => $project_id,
			'vh_status'  => $status,
		) );
	}

	public static function unpaid_csv_url( $from, $to ) {
		return self::build_export_url( 'vh_export_unpaid', array(
			'vh_from' => $from,
			'vh_to'   => $to,
		) );
	}

	public static function backup_csv_url( $from = '', $to = '' ) {
		// from/to optional; export will include full dataset when empty
		$params = array();
		if ( $from ) {
			$params['vh_from'] = $from;
		}
		if ( $to ) {
			$params['vh_to'] = $to;
		}
		return self::build_export_url( 'vh_export_backup', $params );
	}

	public static function report_csv_url( $which, $from, $to ) {
		return self::build_export_url( 'vh_export_report', array(
			'vh_which' => $which,
			'vh_from'  => $from,
			'vh_to'    => $to,
		) );
	}

	/**
	 * Helper to build admin-post export URLs with nonce.
	 *
	 * @param string $action admin_post action name.
	 * @param array  $params additional query args.
	 * @return string
	 */
	private static function build_export_url( $action, $params = array() ) {
		$params = (array) $params;
		$params['action'] = $action;
		return wp_nonce_url( add_query_arg( $params, admin_url( 'admin-post.php' ) ), 'vh_export_admin', 'vh_nonce' );
	}

	/**
	 * Admin: export all unpaid hours (paid = 0) grouped by user and sorted by date.
	 */
	public function export_unpaid() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-volunteer-hours' ) );
		}
		check_admin_referer( 'vh_export_admin', 'vh_nonce' );

		$from = isset( $_GET['vh_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['vh_from'] ) ) ? sanitize_text_field( wp_unslash( $_GET['vh_from'] ) ) : wp_date( 'Y-m-01' ); // phpcs:ignore
		$to   = isset( $_GET['vh_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['vh_to'] ) ) ? sanitize_text_field( wp_unslash( $_GET['vh_to'] ) ) : wp_date( 'Y-m-t' ); // phpcs:ignore

		$entries = VH_Data::get_entries(
			array(
				'from' => $from,
				'to'   => $to,
			)
		);

		// Keep only unpaid entries (paid == 0).
		$unpaid = array_filter( $entries, function( $e ) {
			return empty( $e->paid );
		} );

		// Sort flat: first by user display name, then by work_date asc, then id.
		usort( $unpaid, function( $a, $b ) {
			$ua = VH_Data::user_label( (int) $a->user_id );
			$ub = VH_Data::user_label( (int) $b->user_id );
			$cmp = strcasecmp( $ua, $ub );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			if ( $a->work_date === $b->work_date ) {
				return $a->id <=> $b->id;
			}
			return strcmp( $a->work_date, $b->work_date );
		} );

		// Flat CSV: one row per entry with User column, sorted by user then date.
		$rows = array( array( 'Date', 'Entry ID', 'User', 'User Email', 'Hours', 'Projects', 'Description', 'Reviewed' ) );
		foreach ( $unpaid as $e ) {
			$user = get_userdata( $e->user_id );
			$email = $user ? $user->user_email : '';
			$rows[] = array( $e->work_date, (int) $e->id, VH_Data::user_label( $e->user_id ), $email, VH_Frontend::fmt_hours( $e->hours ), $e->project_names, $e->description, ( (int) $e->reviewed ) ? 'Yes' : 'No' );
		}

		$this->send_csv( 'unpaid-hours-' . $from . '-to-' . $to . '.csv', $rows );
	}

	/**
	 * Export complete plugin data for backup (projects, entries, entry_projects).
	 * CSV format: rows begin with a type column: 'project' or 'entry'.
	 * Project rows: project,id,name,active,created_at
	 * Entry rows: entry,id,user_id,work_date,hours,description,reviewed,paid,created_at,updated_at,project_ids
	 */
	public function export_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-volunteer-hours' ) );
		}
		check_admin_referer( 'vh_export_admin', 'vh_nonce' );

		global $wpdb;
		$p = $wpdb->prefix;

		// Projects first
		$projects = $wpdb->get_results( "SELECT id, name, active, created_at FROM {$p}vh_projects ORDER BY id ASC" ); // phpcs:ignore
		$rows = array();
		$rows[] = array( 'type', 'id', 'name', 'active', 'created_at' );
		foreach ( $projects as $pr ) {
			$rows[] = array( 'project', (int) $pr->id, $pr->name, (int) $pr->active, $pr->created_at );
		}

		// Entries next; include project ids as comma-separated old ids
		$entries = $wpdb->get_results( "SELECT * FROM {$p}vh_entries ORDER BY id ASC" ); // phpcs:ignore
		$rows[] = array( 'type', 'id', 'user_id', 'work_date', 'hours', 'description', 'reviewed', 'paid', 'created_at', 'updated_at', 'project_ids' );
		foreach ( $entries as $e ) {
			$proj_ids = $wpdb->get_col( $wpdb->prepare( "SELECT project_id FROM {$p}vh_entry_projects WHERE entry_id = %d ORDER BY project_id ASC", (int) $e->id ) ); // phpcs:ignore
			$rows[] = array( 'entry', (int) $e->id, (int) $e->user_id, $e->work_date, $e->hours, $e->description, (int) $e->reviewed, (int) $e->paid, $e->created_at, $e->updated_at, implode( ',', $proj_ids ) );
		}

		$this->send_csv( 'vh-backup-' . gmdate( 'Y-m-d' ) . '.csv', $rows );
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
		// UTF-8 BOM so Excel opens non-ASCII names correctly.
		echo "\xEF\xBB\xBF";
		$out = fopen('php://output', 'w');
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
