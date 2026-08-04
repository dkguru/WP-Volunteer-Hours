<?php
/**
 * Admin: Volunteer Hours menu — Projects, All Entries, Reports.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	/**
	 * Restore backup CSV from an uploaded temp file.
	 *
	 * @param string $tmp Path to uploaded CSV file.
	 * @param bool   $replace Whether to delete existing data first.
	 * @param bool   $preserve_ids Whether to preserve original numeric IDs (requires replace=true).
	 * @return array ['errors' => array(), 'projects' => int, 'entries' => int]
	 */
	public static function restore_backup_from_file( $tmp, $replace = false, $preserve_ids = false ) {
		$result = array( 'errors' => array(), 'projects' => 0, 'entries' => 0 );
		if ( ! is_readable( $tmp ) ) {
			$result['errors'][] = __( 'Uploaded file not readable.', 'volunteer-hours' );
			return $result;
		}

		if ( $preserve_ids && ! $replace ) {
			$result['errors'][] = __( 'Preserve IDs requires Replace existing data option.', 'volunteer-hours' );
			$preserve_ids = false;
		}

		if ( false === ( $fh = fopen( $tmp, 'r' ) ) ) {
			$result['errors'][] = __( 'Could not open uploaded file.', 'volunteer-hours' );
			return $result;
		}

		$projects = array();
		$entries = array();
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			if ( empty( $row ) || empty( $row[0] ) ) {
				continue;
			}
			$first = trim( $row[0] );
			if ( 'type' === strtolower( $first ) ) {
				// header row; skip
				continue;
			}
			if ( 'project' === strtolower( $first ) ) {
				$old_id = isset( $row[1] ) ? (int) $row[1] : 0;
				$name = isset( $row[2] ) ? $row[2] : '';
				$active = isset( $row[3] ) ? (int) $row[3] : 1;
				$created_at = isset( $row[4] ) ? $row[4] : current_time( 'mysql' );
				$projects[ $old_id ] = array( 'name' => $name, 'active' => $active, 'created_at' => $created_at );
				continue;
			}
			if ( 'entry' === strtolower( $first ) ) {
				$entries[] = array(
					'old_id' => isset( $row[1] ) ? (int) $row[1] : 0,
					'user_id' => isset( $row[2] ) ? (int) $row[2] : 0,
					'work_date' => isset( $row[3] ) ? $row[3] : '',
					'hours' => isset( $row[4] ) ? $row[4] : 0,
					'description' => isset( $row[5] ) ? $row[5] : '',
					'reviewed' => isset( $row[6] ) ? (int) $row[6] : 0,
					'paid' => isset( $row[7] ) ? (int) $row[7] : 0,
					'created_at' => isset( $row[8] ) ? $row[8] : current_time( 'mysql' ),
					'updated_at' => isset( $row[9] ) ? $row[9] : current_time( 'mysql' ),
					'project_ids' => isset( $row[10] ) ? $row[10] : '',
				);
				continue;
			}
		}
		fclose( $fh );

		global $wpdb;
		$p = $wpdb->prefix;

		// Optionally clear existing data
		if ( $replace ) {
			$wpdb->query( "DELETE FROM {$p}vh_entry_projects" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$p}vh_entries" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$p}vh_projects" ); // phpcs:ignore
		}

		// Insert projects and keep mapping from old_id to new_id
		$proj_map = array();
		foreach ( $projects as $old => $pr ) {
			if ( $preserve_ids && $old > 0 ) {
				$ok = $wpdb->insert( $p . 'vh_projects', array( 'id' => $old, 'name' => $pr['name'], 'active' => $pr['active'], 'created_at' => $pr['created_at'] ), array( '%d', '%s', '%d', '%s' ) );
				if ( false === $ok ) {
					$result['errors'][] = sprintf( __( 'Failed to insert project %s (old id %d): %s', 'volunteer-hours' ), $pr['name'], $old, $wpdb->last_error );
					continue;
				}
				$proj_map[ $old ] = (int) $old;
			} else {
				$ok = $wpdb->insert( $p . 'vh_projects', array( 'name' => $pr['name'], 'active' => $pr['active'], 'created_at' => $pr['created_at'] ), array( '%s', '%d', '%s' ) );
				if ( false === $ok ) {
					$result['errors'][] = sprintf( __( 'Failed to insert project %s: %s', 'volunteer-hours' ), $pr['name'], $wpdb->last_error );
					continue;
				}
				$proj_map[ $old ] = (int) $wpdb->insert_id;
			}
			$result['projects']++;
		}

		// If preserving IDs, bump auto_increment to max id + 1 for projects
		if ( $preserve_ids ) {
			$max = $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$p}vh_projects" ); // phpcs:ignore
			if ( $max ) {
				$wpdb->query( "ALTER TABLE {$p}vh_projects AUTO_INCREMENT = " . ( (int) $max + 1 ) ); // phpcs:ignore
			}
		}

		// Insert entries and entry_projects
		foreach ( $entries as $en ) {
			if ( $preserve_ids && $en['old_id'] > 0 ) {
				$ok = $wpdb->insert( $p . 'vh_entries', array( 'id' => $en['old_id'], 'user_id' => $en['user_id'], 'work_date' => $en['work_date'], 'hours' => $en['hours'], 'description' => $en['description'], 'reviewed' => $en['reviewed'], 'paid' => $en['paid'], 'created_at' => $en['created_at'], 'updated_at' => $en['updated_at'] ), array( '%d', '%d', '%s', '%f', '%s', '%d', '%d', '%s', '%s' ) );
				if ( false === $ok ) {
					$result['errors'][] = sprintf( __( 'Failed to insert entry old id %d: %s', 'volunteer-hours' ), $en['old_id'], $wpdb->last_error );
					continue;
				}
				$new_eid = (int) $en['old_id'];
			} else {
				$ok = $wpdb->insert( $p . 'vh_entries', array( 'user_id' => $en['user_id'], 'work_date' => $en['work_date'], 'hours' => $en['hours'], 'description' => $en['description'], 'reviewed' => $en['reviewed'], 'paid' => $en['paid'], 'created_at' => $en['created_at'], 'updated_at' => $en['updated_at'] ), array( '%d', '%s', '%f', '%s', '%d', '%d', '%s', '%s' ) );
				if ( false === $ok ) {
					$result['errors'][] = sprintf( __( 'Failed to insert entry for user %d: %s', 'volunteer-hours' ), $en['user_id'], $wpdb->last_error );
					continue;
				}
				$new_eid = (int) $wpdb->insert_id;
			}

			// Handle project links
			if ( ! empty( $en['project_ids'] ) ) {
				$old_pids = array_filter( array_map( 'intval', explode( ',', $en['project_ids'] ) ) );
				foreach ( $old_pids as $op ) {
					if ( isset( $proj_map[ $op ] ) ) {
						$wpdb->insert( $p . 'vh_entry_projects', array( 'entry_id' => $new_eid, 'project_id' => $proj_map[ $op ] ), array( '%d', '%d' ) );
					}
				}
			}
			$result['entries']++;
		}

		// If preserving IDs, bump auto_increment to max id + 1 for entries
		if ( $preserve_ids ) {
			$max = $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$p}vh_entries" ); // phpcs:ignore
			if ( $max ) {
				$wpdb->query( "ALTER TABLE {$p}vh_entries AUTO_INCREMENT = " . ( (int) $max + 1 ) ); // phpcs:ignore
			}
		}

		return $result;
	}

class VH_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_donation' ) );
		add_action( 'wp_ajax_vh_dismiss_donation', array( $this, 'ajax_dismiss_donation' ) );
	}

	/**
	 * Where donations go. Filter 'vh_donation_url' to point somewhere else.
	 */
	public static function donation_url() {
		return apply_filters( 'vh_donation_url', 'https://donorbox.org/linp' );
	}

	/**
	 * Hide the donation message for this user for three months.
	 */
	private function snooze_donation() {
		update_user_meta( get_current_user_id(), 'vh_donation_snoozed_until', time() + ( 3 * MONTH_IN_SECONDS ) );
	}

	/**
	 * No-JavaScript fallback: the close box is a plain link carrying a nonce.
	 */
	public function maybe_dismiss_donation() {
		if ( empty( $_GET['vh_dismiss_donation'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['vh_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['vh_nonce'] ), 'vh_dismiss_donation' ) ) {
			return;
		}
		$this->snooze_donation();
		wp_safe_redirect( remove_query_arg( array( 'vh_dismiss_donation', 'vh_nonce' ) ) );
		exit;
	}

	/**
	 * Same thing over AJAX, so the message fades out without reloading the page.
	 */
	public function ajax_dismiss_donation() {
		check_ajax_referer( 'vh_dismiss_donation', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$this->snooze_donation();
		wp_send_json_success();
	}

	/**
	 * A friendly, dismissible note asking for support. Hidden for three
	 * months once closed, per user.
	 */
	private function donation_notice() {
		$until = (int) get_user_meta( get_current_user_id(), 'vh_donation_snoozed_until', true );
		if ( $until > time() ) {
			return;
		}

		$nonce      = wp_create_nonce( 'vh_dismiss_donation' );
		$close_link = add_query_arg(
			array(
				'vh_dismiss_donation' => 1,
				'vh_nonce'            => $nonce,
			)
		);
		?>
		<div class="vh-donate" id="vh-donate">
			<a class="vh-donate-close" href="<?php echo esc_url( $close_link ); ?>"
				aria-label="<?php esc_attr_e( 'Hide this message for three months', 'volunteer-hours' ); ?>"
				title="<?php esc_attr_e( 'Hide this message for three months', 'volunteer-hours' ); ?>">&times;</a>

			<img class="vh-donate-logo" src="<?php echo esc_url( VH_PLUGIN_URL . 'assets/logo.png' ); ?>" alt="" />

			<div class="vh-donate-body">
				<h2><?php esc_html_e( 'Support the work behind this plugin', 'volunteer-hours' ); ?></h2>
				<p>
					<?php esc_html_e( 'Volunteer Hours is built and maintained by Living Islands, a small nonprofit working to preserve and share Pacific Island culture. The plugin is free, and it stays that way because of people who chip in.', 'volunteer-hours' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'If it saves you time each month, a donation of any size helps us keep improving it — and funds the community programs your volunteers are logging hours for.', 'volunteer-hours' ); ?>
				</p>
				<p class="vh-donate-actions">
					<a class="button button-primary" href="<?php echo esc_url( self::donation_url() ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Support Living Islands', 'volunteer-hours' ); ?>
					</a>
					<a class="vh-donate-later" href="<?php echo esc_url( $close_link ); ?>"><?php esc_html_e( 'Maybe later', 'volunteer-hours' ); ?></a>
				</p>
			</div>
		</div>
		<script>
		( function () {
			var box = document.getElementById( 'vh-donate' );
			if ( ! box ) { return; }
			box.addEventListener( 'click', function ( e ) {
				var el = e.target.closest( '.vh-donate-close, .vh-donate-later' );
				if ( ! el ) { return; }
				e.preventDefault();
				box.style.display = 'none';
				var body = new URLSearchParams();
				body.append( 'action', 'vh_dismiss_donation' );
				body.append( 'nonce', '<?php echo esc_js( $nonce ); ?>' );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.catch( function () { window.location.href = el.getAttribute( 'href' ); } );
			} );
		}() );
		</script>
		<?php
	}

	public function menu() {
		add_menu_page(
			__( 'Volunteer Hours', 'volunteer-hours' ),
			__( 'Volunteer Hours', 'volunteer-hours' ),
			'manage_options',
			'vh-reports',
			array( $this, 'page_reports' ),
			'dashicons-clock',
			26
		);
		add_submenu_page( 'vh-reports', __( 'Reports', 'volunteer-hours' ), __( 'Reports', 'volunteer-hours' ), 'manage_options', 'vh-reports', array( $this, 'page_reports' ) );
		add_submenu_page( 'vh-reports', __( 'All Entries', 'volunteer-hours' ), __( 'All Entries', 'volunteer-hours' ), 'manage_options', 'vh-entries', array( $this, 'page_entries' ) );
		add_submenu_page( 'vh-reports', __( 'Projects', 'volunteer-hours' ), __( 'Projects', 'volunteer-hours' ), 'manage_options', 'vh-projects', array( $this, 'page_projects' ) );
	}

	/**
	 * Process admin form posts (projects CRUD, entry delete/update).
	 */
	public function handle_actions() {
		if ( empty( $_POST['vh_admin_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['vh_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['vh_nonce'] ), 'vh_admin' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'volunteer-hours' ) );
		}

		$action   = sanitize_key( $_POST['vh_admin_action'] );
		// Build the current URL from HTTP_HOST + REQUEST_URI to avoid duplicating
		// the path when WordPress is in a subdirectory. Fall back to the
		// plugin admin page URL if unavailable.
		if ( isset( $_SERVER['REQUEST_URI'] ) && isset( $_SERVER['HTTP_HOST'] ) ) { // phpcs:ignore
			$scheme  = is_ssl() ? 'https://' : 'http://';
			$current = $scheme . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore
		} else {
			$current = admin_url( 'admin.php?page=vh-projects' );
		}
		$redirect = remove_query_arg( array( 'vh_msg', 'vh_edit' ), $current );
		$msg      = '';

		switch ( $action ) {
			case 'add_project':
				$res = VH_Data::add_project( isset( $_POST['vh_name'] ) ? wp_unslash( $_POST['vh_name'] ) : '' ); // phpcs:ignore
				$msg = is_wp_error( $res ) ? $res->get_error_message() : __( 'Project added.', 'volunteer-hours' );
				break;

			case 'rename_project':
				$res = VH_Data::rename_project( (int) $_POST['vh_id'], isset( $_POST['vh_name'] ) ? wp_unslash( $_POST['vh_name'] ) : '' ); // phpcs:ignore
				$msg = is_wp_error( $res ) ? $res->get_error_message() : __( 'Project renamed.', 'volunteer-hours' );
				break;

			case 'toggle_project':
				VH_Data::set_project_active( (int) $_POST['vh_id'], ! empty( $_POST['vh_active'] ) );
				$msg = __( 'Project updated.', 'volunteer-hours' );
				break;

			case 'delete_project':
				$res = VH_Data::delete_project( (int) $_POST['vh_id'] );
				$msg = is_wp_error( $res ) ? $res->get_error_message() : __( 'Project deleted.', 'volunteer-hours' );
				break;

			case 'restore_backup':
				// Handle uploaded backup CSV and restore data.
				if ( empty( $_FILES['vh_backup_file'] ) || ! is_uploaded_file( $_FILES['vh_backup_file']['tmp_name'] ) ) {
					$msg = __( 'No backup file uploaded.', 'volunteer-hours' );
					break;
				}
				$replace = ! empty( $_POST['vh_restore_replace'] );
				$tmp = $_FILES['vh_backup_file']['tmp_name'];
				$preserve = ! empty( $_POST['vh_restore_preserve_ids'] );
				$result = self::restore_backup_from_file( $tmp, $replace, $preserve );
				if ( ! empty( $result['errors'] ) ) {
					$msg = __( 'Restore completed with errors:', 'volunteer-hours' ) . ' ' . implode( ' ; ', $result['errors'] );
				} else {
					$msg = __( 'Backup restored successfully.', 'volunteer-hours' );
				}
				break;

			case 'repair':
				vh_activate();
				$msg = __( 'Tables checked and repaired.', 'volunteer-hours' );
				break;

			case 'delete_entry':
				VH_Data::delete_entry( (int) $_POST['vh_id'] );
				$msg = __( 'Entry deleted.', 'volunteer-hours' );
				break;

			case 'set_status':
				$field = isset( $_POST['vh_field'] ) ? sanitize_key( $_POST['vh_field'] ) : '';
				$res   = VH_Data::set_entry_status( (int) $_POST['vh_id'], $field, ! empty( $_POST['vh_value'] ) );
				$msg   = is_wp_error( $res ) ? $res->get_error_message() : __( 'Status updated.', 'volunteer-hours' );
				break;

			case 'update_entry':
				$entry = VH_Data::get_entry( (int) $_POST['vh_id'] );
				if ( $entry ) {
					$res = VH_Data::save_entry(
						array(
							'id'          => $entry->id,
							'user_id'     => $entry->user_id,
							'work_date'   => isset( $_POST['vh_date'] ) ? sanitize_text_field( wp_unslash( $_POST['vh_date'] ) ) : '',
							'hours'       => isset( $_POST['vh_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['vh_hours'] ) ) : '',
							'description' => isset( $_POST['vh_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vh_description'] ) ) : '',
							'project_ids' => isset( $_POST['vh_projects'] ) ? (array) $_POST['vh_projects'] : array(),
						)
					);
					$msg = is_wp_error( $res ) ? $res->get_error_message() : __( 'Entry updated.', 'volunteer-hours' );
				}
				break;
		}

		wp_safe_redirect( add_query_arg( 'vh_msg', rawurlencode( $msg ), $redirect ) );
		exit;
	}

	private function notice() {
		if ( ! empty( $_GET['vh_msg'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['vh_msg'] ) ) ) . '</p></div>';
		}
	}

	private function header( $title ) {
		echo '<div class="wrap vh-admin">';
		echo '<h1><img class="vh-logo" src="' . esc_url( VH_PLUGIN_URL . 'assets/logo.png' ) . '" alt="" /> ' . esc_html( $title ) . '</h1>';
		$this->notice();
		$this->donation_notice();
	}

	/* ---------------- Projects ---------------- */

	public function page_projects() {
		$projects = VH_Data::get_projects();
		$edit_id  = isset( $_GET['vh_edit'] ) ? (int) $_GET['vh_edit'] : 0;
		$this->header( __( 'Projects', 'volunteer-hours' ) );
		?>
		<p><?php esc_html_e( 'This list feeds the project checkboxes on the volunteer form. Deactivate a project to hide it from the form while keeping its history.', 'volunteer-hours' ); ?></p>

		<form method="post" class="vh-inline-form">
			<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
			<input type="hidden" name="vh_admin_action" value="add_project" />
			<input type="text" name="vh_name" placeholder="<?php esc_attr_e( 'New project name', 'volunteer-hours' ); ?>" required />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Add project', 'volunteer-hours' ); ?></button>
		</form>

		<table class="widefat striped vh-admin-table">
			<thead><tr>
				<th><?php esc_html_e( 'Project', 'volunteer-hours' ); ?></th>
				<th><?php esc_html_e( 'Status', 'volunteer-hours' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'volunteer-hours' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $projects ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No projects yet. Add the first one above.', 'volunteer-hours' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $projects as $pr ) : ?>
				<tr>
					<td>
						<?php if ( $edit_id === (int) $pr->id ) : ?>
							<form method="post" class="vh-inline-form">
								<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
								<input type="hidden" name="vh_admin_action" value="rename_project" />
								<input type="hidden" name="vh_id" value="<?php echo esc_attr( $pr->id ); ?>" />
								<input type="text" name="vh_name" value="<?php echo esc_attr( $pr->name ); ?>" required />
								<button type="submit" class="button"><?php esc_html_e( 'Save', 'volunteer-hours' ); ?></button>
								<a href="<?php echo esc_url( remove_query_arg( 'vh_edit' ) ); ?>"><?php esc_html_e( 'Cancel', 'volunteer-hours' ); ?></a>
							</form>
						<?php else : ?>
							<?php echo esc_html( $pr->name ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo $pr->active ? esc_html__( 'Active', 'volunteer-hours' ) : esc_html__( 'Inactive', 'volunteer-hours' ); ?></td>
					<td>
						<a href="<?php echo esc_url( add_query_arg( 'vh_edit', (int) $pr->id ) ); ?>"><?php esc_html_e( 'Rename', 'volunteer-hours' ); ?></a>
						<form method="post" class="vh-inline">
							<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
							<input type="hidden" name="vh_admin_action" value="toggle_project" />
							<input type="hidden" name="vh_id" value="<?php echo esc_attr( $pr->id ); ?>" />
							<input type="hidden" name="vh_active" value="<?php echo $pr->active ? '' : '1'; ?>" />
							<button type="submit" class="button-link"><?php echo $pr->active ? esc_html__( 'Deactivate', 'volunteer-hours' ) : esc_html__( 'Activate', 'volunteer-hours' ); ?></button>
						</form>
						<form method="post" class="vh-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this project?', 'volunteer-hours' ) ); ?>');">
							<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
							<input type="hidden" name="vh_admin_action" value="delete_project" />
							<input type="hidden" name="vh_id" value="<?php echo esc_attr( $pr->id ); ?>" />
							<button type="submit" class="button-link vh-danger"><?php esc_html_e( 'Delete', 'volunteer-hours' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/* ---------------- All Entries ---------------- */

	public function page_entries() {
		$month      = isset( $_GET['vh_month'] ) && preg_match( '/^\d{4}-\d{2}$/', wp_unslash( $_GET['vh_month'] ) ) ? sanitize_text_field( wp_unslash( $_GET['vh_month'] ) ) : wp_date( 'Y-m' ); // phpcs:ignore
		$user_id    = isset( $_GET['vh_user'] ) ? (int) $_GET['vh_user'] : 0;
		$project_id = isset( $_GET['vh_project'] ) ? (int) $_GET['vh_project'] : 0;
		$status     = isset( $_GET['vh_status'] ) && in_array( $_GET['vh_status'], array( 'pending', 'reviewed', 'paid' ), true ) ? sanitize_key( $_GET['vh_status'] ) : ''; // phpcs:ignore
		$from       = $month . '-01';
		$to         = gmdate( 'Y-m-t', strtotime( $from ) );
		$edit_id    = isset( $_GET['vh_edit'] ) ? (int) $_GET['vh_edit'] : 0;

		$projects = VH_Data::get_projects();

		global $wpdb;
		$wpdb->last_error = '';
		$entries          = VH_Data::get_entries(
			array(
				'from'       => $from,
				'to'         => $to,
				'user_id'    => $user_id,
				'project_id' => $project_id,
				'status'     => $status,
			)
		);
		$db_error = $wpdb->last_error;
		$total    = 0;
		foreach ( $entries as $e ) {
			$total += (float) $e->hours;
		}

		$this->header( __( 'All Entries', 'volunteer-hours' ) );
		if ( $db_error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Database error while loading entries:', 'volunteer-hours' ) . ' ' . esc_html( $db_error ) . '</p></div>';
		}
		?>
		<form method="get" class="vh-inline-form">
			<input type="hidden" name="page" value="vh-entries" />
			<label><?php esc_html_e( 'Month', 'volunteer-hours' ); ?> <input type="month" name="vh_month" value="<?php echo esc_attr( $month ); ?>" /></label>
			<label><?php esc_html_e( 'User', 'volunteer-hours' ); ?>
				<?php
				wp_dropdown_users(
					array(
						'name'              => 'vh_user',
						'selected'          => $user_id,
						'show_option_all'   => __( 'All users', 'volunteer-hours' ),
						'option_none_value' => 0,
					)
				);
				?>
			</label>
			<label><?php esc_html_e( 'Project', 'volunteer-hours' ); ?>
				<select name="vh_project">
					<option value="0"><?php esc_html_e( 'All projects', 'volunteer-hours' ); ?></option>
					<?php foreach ( $projects as $pr ) : ?>
						<option value="<?php echo esc_attr( $pr->id ); ?>" <?php selected( $project_id, (int) $pr->id ); ?>><?php echo esc_html( $pr->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Status', 'volunteer-hours' ); ?>
				<select name="vh_status">
					<option value=""><?php esc_html_e( 'All statuses', 'volunteer-hours' ); ?></option>
					<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Awaiting review', 'volunteer-hours' ); ?></option>
					<option value="reviewed" <?php selected( $status, 'reviewed' ); ?>><?php esc_html_e( 'Reviewed, unpaid', 'volunteer-hours' ); ?></option>
					<option value="paid" <?php selected( $status, 'paid' ); ?>><?php esc_html_e( 'Paid', 'volunteer-hours' ); ?></option>
				</select>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'volunteer-hours' ); ?></button>
			<a class="button" href="<?php echo esc_url( VH_Export::entries_csv_url( $month, $user_id, $project_id, $status ) ); ?>"><?php esc_html_e( 'Export CSV', 'volunteer-hours' ); ?></a>
		</form>

		<table class="widefat striped vh-admin-table">
			<thead><tr>
				<th><?php esc_html_e( 'Date', 'volunteer-hours' ); ?></th>
				<th><?php esc_html_e( 'User', 'volunteer-hours' ); ?></th>
				<th><?php esc_html_e( 'Hours', 'volunteer-hours' ); ?></th>
				<th><?php esc_html_e( 'Projects', 'volunteer-hours' ); ?></th>
				<th><?php esc_html_e( 'Description', 'volunteer-hours' ); ?></th>
				<th><?php esc_html_e( 'Reviewed', 'volunteer-hours' ); ?></th>
				<th><?php esc_html_e( 'Paid', 'volunteer-hours' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'volunteer-hours' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $entries ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No entries match this filter.', 'volunteer-hours' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $entries as $e ) : ?>
				<?php if ( $edit_id === (int) $e->id ) : ?>
					<tr>
						<td colspan="8">
							<?php $full = VH_Data::get_entry( $e->id ); ?>
							<form method="post" class="vh-inline-form">
								<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
								<input type="hidden" name="vh_admin_action" value="update_entry" />
								<input type="hidden" name="vh_id" value="<?php echo esc_attr( $e->id ); ?>" />
								<strong><?php echo esc_html( VH_Data::user_label( $e->user_id ) ); ?></strong>
								<input type="date" name="vh_date" value="<?php echo esc_attr( $full->work_date ); ?>" required />
								<input type="number" name="vh_hours" value="<?php echo esc_attr( $full->hours ); ?>" step="0.25" min="0.25" max="24" required />
								<?php foreach ( $projects as $pr ) : ?>
									<label class="vh-project"><input type="checkbox" name="vh_projects[]" value="<?php echo esc_attr( $pr->id ); ?>" <?php checked( in_array( (int) $pr->id, $full->project_ids, true ) ); ?> /> <?php echo esc_html( $pr->name ); ?></label>
								<?php endforeach; ?>
								<input type="text" name="vh_description" value="<?php echo esc_attr( $full->description ); ?>" size="40" />
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'volunteer-hours' ); ?></button>
								<a href="<?php echo esc_url( remove_query_arg( 'vh_edit' ) ); ?>"><?php esc_html_e( 'Cancel', 'volunteer-hours' ); ?></a>
							</form>
						</td>
					</tr>
				<?php else : ?>
					<tr>
						<td><?php echo esc_html( $e->work_date ); ?></td>
						<td><?php echo esc_html( VH_Data::user_label( $e->user_id ) ); ?></td>
						<td><?php echo esc_html( VH_Frontend::fmt_hours( $e->hours ) ); ?></td>
						<td><?php echo esc_html( $e->project_names ); ?></td>
						<td><?php echo esc_html( $e->description ); ?></td>
						<td>
							<form method="post" class="vh-inline">
								<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
								<input type="hidden" name="vh_admin_action" value="set_status" />
								<input type="hidden" name="vh_field" value="reviewed" />
								<input type="hidden" name="vh_id" value="<?php echo esc_attr( $e->id ); ?>" />
								<input type="hidden" name="vh_value" value="<?php echo $e->reviewed ? '' : '1'; ?>" />
								<input type="checkbox" onchange="this.form.submit()" <?php checked( (int) $e->reviewed, 1 ); ?>
									title="<?php esc_attr_e( 'Reviewed / approved', 'volunteer-hours' ); ?>" />
							</form>
						</td>
						<td>
							<form method="post" class="vh-inline">
								<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
								<input type="hidden" name="vh_admin_action" value="set_status" />
								<input type="hidden" name="vh_field" value="paid" />
								<input type="hidden" name="vh_id" value="<?php echo esc_attr( $e->id ); ?>" />
								<input type="hidden" name="vh_value" value="<?php echo $e->paid ? '' : '1'; ?>" />
								<input type="checkbox" onchange="this.form.submit()" <?php checked( (int) $e->paid, 1 ); ?>
									<?php disabled( ! (int) $e->reviewed && ! (int) $e->paid ); ?>
									title="<?php esc_attr_e( 'Paid (requires review first)', 'volunteer-hours' ); ?>" />
							</form>
						</td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( 'vh_edit', (int) $e->id ) ); ?>"><?php esc_html_e( 'Edit', 'volunteer-hours' ); ?></a>
							<form method="post" class="vh-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this entry?', 'volunteer-hours' ) ); ?>');">
								<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
								<input type="hidden" name="vh_admin_action" value="delete_entry" />
								<input type="hidden" name="vh_id" value="<?php echo esc_attr( $e->id ); ?>" />
								<button type="submit" class="button-link vh-danger"><?php esc_html_e( 'Delete', 'volunteer-hours' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
			</tbody>
			<tfoot><tr>
				<th><?php esc_html_e( 'Total', 'volunteer-hours' ); ?></th>
				<th></th>
				<th><?php echo esc_html( VH_Frontend::fmt_hours( $total ) ); ?></th>
				<th colspan="5"></th>
			</tr></tfoot>
		</table>
		</div>
		<?php
	}

	/* ---------------- Reports ---------------- */

	private function diagnostics_box() {
		global $wpdb;
		$tables = array( 'vh_projects', 'vh_entries', 'vh_entry_projects' );
		$rows   = array();
		foreach ( $tables as $t ) {
			$full   = $wpdb->prefix . $t;
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			$count  = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full}" ) : null; // phpcs:ignore
			$rows[] = array( $full, $exists, $count );
		}
		$cols_ok = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}vh_entries LIKE %s", 'reviewed' ) ); // phpcs:ignore
		?>
		<div class="vh-diagnostics" style="background:#fff;border:1px solid #c3c4c7;padding:10px 14px;margin:12px 0;max-width:640px;">
			<strong><?php esc_html_e( 'Plugin status', 'volunteer-hours' ); ?></strong>
			<table style="margin-top:6px;">
				<tr><td style="padding-right:14px;"><?php esc_html_e( 'Plugin file version', 'volunteer-hours' ); ?></td><td><code><?php echo esc_html( VH_VERSION ); ?></code></td></tr>
				<tr><td style="padding-right:14px;"><?php esc_html_e( 'Database schema version', 'volunteer-hours' ); ?></td><td><code><?php echo esc_html( (string) get_option( 'vh_db_version', '—' ) ); ?></code></td></tr>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td style="padding-right:14px;"><code><?php echo esc_html( $r[0] ); ?></code></td>
						<td><?php echo $r[1] ? esc_html( sprintf( /* translators: %d: row count */ __( 'OK — %d rows', 'volunteer-hours' ), $r[2] ) ) : '<span style="color:#b32d2e;font-weight:600;">' . esc_html__( 'MISSING', 'volunteer-hours' ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				<tr><td style="padding-right:14px;"><?php esc_html_e( 'Review/paid columns', 'volunteer-hours' ); ?></td><td><?php echo $cols_ok ? esc_html__( 'OK', 'volunteer-hours' ) : '<span style="color:#b32d2e;font-weight:600;">' . esc_html__( 'MISSING', 'volunteer-hours' ) . '</span>'; ?></td></tr>
				<tr><td style="padding-right:14px;"><?php esc_html_e( 'Database server', 'volunteer-hours' ); ?></td><td><code><?php echo esc_html( $wpdb->get_var( 'SELECT VERSION()' ) ); ?></code></td></tr>
			</table>
			<form method="post" style="margin-top:8px;">
				<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
				<input type="hidden" name="vh_admin_action" value="repair" />
				<button type="submit" class="button"><?php esc_html_e( 'Repair / rebuild tables', 'volunteer-hours' ); ?></button>
				<span class="description"><?php esc_html_e( 'Safe to run — creates any missing tables/columns, never deletes data.', 'volunteer-hours' ); ?></span>
			</form>
		</div>
		<?php
	}

	public function page_reports() {
		$from = isset( $_GET['vh_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['vh_from'] ) ) ? sanitize_text_field( wp_unslash( $_GET['vh_from'] ) ) : wp_date( 'Y-m-01' ); // phpcs:ignore
		$to   = isset( $_GET['vh_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['vh_to'] ) ) ? sanitize_text_field( wp_unslash( $_GET['vh_to'] ) ) : wp_date( 'Y-m-t' ); // phpcs:ignore

		$per_user    = VH_Data::report_hours_per_user( $from, $to );
		$per_project = VH_Data::report_hours_per_project( $from, $to );

		$this->header( __( 'Reports', 'volunteer-hours' ) );
		?>
		<form method="get" class="vh-inline-form">
			<input type="hidden" name="page" value="vh-reports" />
			<label><?php esc_html_e( 'From', 'volunteer-hours' ); ?> <input type="date" name="vh_from" value="<?php echo esc_attr( $from ); ?>" /></label>
			<label><?php esc_html_e( 'To', 'volunteer-hours' ); ?> <input type="date" name="vh_to" value="<?php echo esc_attr( $to ); ?>" /></label>
			<button type="submit" class="button"><?php esc_html_e( 'Run report', 'volunteer-hours' ); ?></button>
			<span style="margin-left:12px;"><a class="button" href="<?php echo esc_url( VH_Export::unpaid_csv_url( $from, $to ) ); ?>"><?php esc_html_e( 'Export unpaid hours (CSV)', 'volunteer-hours' ); ?></a></span>
			<span style="margin-left:8px;"><a class="button" href="<?php echo esc_url( VH_Export::backup_csv_url() ); ?>"><?php esc_html_e( 'Backup all data (CSV)', 'volunteer-hours' ); ?></a></span>
		</form>

		<form method="post" enctype="multipart/form-data" style="margin-top:10px;">
			<?php wp_nonce_field( 'vh_admin', 'vh_nonce' ); ?>
			<input type="hidden" name="vh_admin_action" value="restore_backup" />
			<label><?php esc_html_e( 'Restore from backup CSV', 'volunteer-hours' ); ?> <input type="file" name="vh_backup_file" accept="text/csv" required /></label>
			<label style="margin-left:8px;"><input type="checkbox" name="vh_restore_replace" value="1" /> <?php esc_html_e( 'Replace existing data (delete current)', 'volunteer-hours' ); ?></label>
			<label style="margin-left:8px;"><input type="checkbox" name="vh_restore_preserve_ids" value="1" /> <?php esc_html_e( 'Preserve original numeric IDs (requires Replace)', 'volunteer-hours' ); ?></label>
			<button type="submit" class="button" style="margin-left:8px;"><?php esc_html_e( 'Restore', 'volunteer-hours' ); ?></button>
		</form>

		<div class="vh-report-cols">
			<div>
				<h2><?php esc_html_e( 'Hours per user', 'volunteer-hours' ); ?></h2>
				<p><a class="button" href="<?php echo esc_url( VH_Export::report_csv_url( 'users', $from, $to ) ); ?>"><?php esc_html_e( 'Export CSV', 'volunteer-hours' ); ?></a></p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'User', 'volunteer-hours' ); ?></th><th><?php esc_html_e( 'Entries', 'volunteer-hours' ); ?></th><th><?php esc_html_e( 'Hours', 'volunteer-hours' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $per_user ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No hours in this period.', 'volunteer-hours' ); ?></td></tr>
					<?php endif; ?>
					<?php
					$sum = 0;
					foreach ( $per_user as $row ) :
						$sum += (float) $row->total_hours;
						?>
						<tr>
							<td><?php echo esc_html( VH_Data::user_label( $row->user_id ) ); ?></td>
							<td><?php echo esc_html( $row->entry_count ); ?></td>
							<td><?php echo esc_html( VH_Frontend::fmt_hours( $row->total_hours ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot><tr><th><?php esc_html_e( 'Total', 'volunteer-hours' ); ?></th><th></th><th><?php echo esc_html( VH_Frontend::fmt_hours( $sum ) ); ?></th></tr></tfoot>
				</table>
			</div>
			<div>
				<h2><?php esc_html_e( 'Hours per project', 'volunteer-hours' ); ?></h2>
				<p><a class="button" href="<?php echo esc_url( VH_Export::report_csv_url( 'projects', $from, $to ) ); ?>"><?php esc_html_e( 'Export CSV', 'volunteer-hours' ); ?></a></p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Project', 'volunteer-hours' ); ?></th><th><?php esc_html_e( 'Entries', 'volunteer-hours' ); ?></th><th><?php esc_html_e( 'Hours', 'volunteer-hours' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $per_project ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No hours in this period.', 'volunteer-hours' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $per_project as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->name ); ?></td>
							<td><?php echo esc_html( $row->entry_count ); ?></td>
							<td><?php echo esc_html( VH_Frontend::fmt_hours( $row->total_hours ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Entries that span several projects count fully toward each project, so this column can sum to more than the per-user total.', 'volunteer-hours' ); ?></p>
			</div>
		</div>
		</div>
		<?php
		$this->diagnostics_box();
	}
}
