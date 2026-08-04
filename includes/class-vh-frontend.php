<?php
/**
 * [volunteer_hours] shortcode: register hours, list/edit/delete own entries, print sheet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VH_Frontend {

	public function __construct() {
		add_shortcode( 'volunteer_hours', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'template_redirect', array( $this, 'maybe_print_view' ) );
	}

	/**
	 * Handle form submissions (add / update / delete) before output.
	 */
	public function handle_post() {
		if ( ! is_user_logged_in() || empty( $_POST['vh_action'] ) ) {
			return;
		}
		$action = sanitize_key( $_POST['vh_action'] );
		$nonce_field = 'vh_nonce_' . $action;
		$nonce_action = 'vh_front_' . $action;
		if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $nonce_field ] ), $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'volunteer-hours' ) );
		}

		$user_id  = get_current_user_id();
		$action   = sanitize_key( $_POST['vh_action'] );
		// Rebuild the current page URL from the host + request URI.
		// Using home_url( REQUEST_URI ) duplicates the path when WordPress
		// is installed in a subdirectory (e.g. site at example.com/vh/), so
		// construct the URL from the HTTP_HOST and REQUEST_URI instead.
		if ( isset( $_SERVER['REQUEST_URI'] ) && isset( $_SERVER['HTTP_HOST'] ) ) { // phpcs:ignore
			$scheme  = is_ssl() ? 'https://' : 'http://';
			$current = $scheme . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore
		} else {
			$current = home_url();
		}
		$redirect = remove_query_arg( array( 'vh_edit', 'vh_msg' ), $current );

		if ( 'save' === $action ) {
			$entry_id = isset( $_POST['vh_entry_id'] ) ? (int) $_POST['vh_entry_id'] : 0;

			// Editing: entry must belong to the current user.
			if ( $entry_id ) {
				$existing = VH_Data::get_entry( $entry_id );
				if ( ! $existing || (int) $existing->user_id !== $user_id ) {
				wp_die( esc_html__( 'You can only edit your own entries.', 'volunteer-hours' ) );
				}
				if ( (int) $existing->paid ) {
					wp_safe_redirect( add_query_arg( 'vh_msg', rawurlencode( __( 'This entry has been paid and can no longer be changed. Please contact an administrator.', 'volunteer-hours' ) ), $redirect ) );
					exit;
				}
			}

			$result = VH_Data::save_entry(
				array(
					'id'          => $entry_id,
					'user_id'     => $user_id,
					'work_date'   => isset( $_POST['vh_date'] ) ? sanitize_text_field( wp_unslash( $_POST['vh_date'] ) ) : '',
					'hours'       => isset( $_POST['vh_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['vh_hours'] ) ) : '',
					'description' => isset( $_POST['vh_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vh_description'] ) ) : '',
					'project_ids' => isset( $_POST['vh_projects'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['vh_projects'] ) ) : array(),
				)
			);

			if ( is_wp_error( $result ) ) {
				$redirect = add_query_arg( 'vh_msg', rawurlencode( $result->get_error_message() ), $redirect );
			} else {
				$redirect = add_query_arg( 'vh_msg', $entry_id ? 'updated' : 'saved', $redirect );
			}
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( 'delete' === $action ) {
			$entry_id = isset( $_POST['vh_entry_id'] ) ? (int) $_POST['vh_entry_id'] : 0;
			$existing = VH_Data::get_entry( $entry_id );
			if ( ! $existing || (int) $existing->user_id !== $user_id ) {
			wp_die( esc_html__( 'You can only delete your own entries.', 'volunteer-hours' ) );
			}
			if ( (int) $existing->paid ) {
				wp_safe_redirect( add_query_arg( 'vh_msg', rawurlencode( __( 'This entry has been paid and can no longer be deleted. Please contact an administrator.', 'volunteer-hours' ) ), $redirect ) );
				exit;
			}
			VH_Data::delete_entry( $entry_id );
			wp_safe_redirect( add_query_arg( 'vh_msg', 'deleted', $redirect ) );
			exit;
		}
	}

	/**
	 * Month currently selected on the front end (Y-m).
	 */
	private function selected_month() {
		$month = isset( $_GET['vh_month'] ) ? sanitize_text_field( wp_unslash( $_GET['vh_month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter, no state is changed. State-changing requests in this plugin verify a nonce before this point.
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = wp_date( 'Y-m' );
		}
		return $month;
	}

	private function month_bounds( $month ) {
		$from = $month . '-01';
		$to   = gmdate( 'Y-m-t', strtotime( $from ) );
		return array( $from, $to );
	}

	/**
	 * Shortcode output.
	 */
	public function render() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . sprintf(
			/* translators: %s: login link */
			esc_html__( 'Please %s to register your volunteer hours.', 'volunteer-hours' ),
				'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'log in', 'volunteer-hours' ) . '</a>'
			) . '</p>';
		}

		$user_id  = get_current_user_id();
		$projects = VH_Data::get_projects( true );
		$month    = $this->selected_month();
		list( $from, $to ) = $this->month_bounds( $month );
		$entries  = VH_Data::get_entries(
			array(
				'user_id' => $user_id,
				'from'    => $from,
				'to'      => $to,
			)
		);
		$total = 0;
		foreach ( $entries as $e ) {
			$total += (float) $e->hours;
		}

		// Editing an existing entry?
		$edit = null;
		if ( isset( $_GET['vh_edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter, no state is changed. State-changing requests in this plugin verify a nonce before this point.
			$candidate = VH_Data::get_entry( (int) $_GET['vh_edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter, no state is changed. State-changing requests in this plugin verify a nonce before this point.
			if ( $candidate && (int) $candidate->user_id === $user_id && ! (int) $candidate->paid ) {
				$edit = $candidate;
			}
		}

		ob_start();
		echo '<div class="vh-wrap">';

		$this->notice();
		$this->render_form( $projects, $edit );
		$this->render_my_hours( $entries, $month, $total );

		echo '</div>';
		return ob_get_clean();
	}

	private function notice() {
		if ( empty( $_GET['vh_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter, no state is changed. State-changing requests in this plugin verify a nonce before this point.
			return;
		}
		$msg = sanitize_text_field( wp_unslash( $_GET['vh_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter, no state is changed. State-changing requests in this plugin verify a nonce before this point.
			$map = array(
				'saved'   => __( 'Hours registered.', 'volunteer-hours' ),
				'updated' => __( 'Entry updated.', 'volunteer-hours' ),
				'deleted' => __( 'Entry deleted.', 'volunteer-hours' ),
			);
		$text  = isset( $map[ $msg ] ) ? $map[ $msg ] : $msg;
		$class = isset( $map[ $msg ] ) ? 'vh-notice' : 'vh-notice vh-error';
		echo '<p class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</p>';
	}

	private function render_form( $projects, $edit ) {
		$is_edit = (bool) $edit;
		?>
		<h3><?php echo $is_edit ? esc_html__( 'Edit entry', 'volunteer-hours' ) : esc_html__( 'Register hours', 'volunteer-hours' ); ?></h3>
		<?php if ( empty( $projects ) ) : ?>
			<p><?php esc_html_e( 'No projects have been set up yet. Please ask an administrator to add projects first.', 'volunteer-hours' ); ?></p>
			<?php
			return;
		endif;
		?>
		<form method="post" class="vh-form">
			<?php wp_nonce_field( 'vh_front_save', 'vh_nonce_save' ); ?>
			<?php if ( $is_edit ) : ?>
				<?php wp_nonce_field( 'vh_front_delete', 'vh_nonce_delete' ); ?>
			<?php endif; ?>
			<input type="hidden" name="vh_action" value="save" />
			<input type="hidden" name="vh_entry_id" value="<?php echo esc_attr( $is_edit ? $edit->id : 0 ); ?>" />

			<p>
				<label for="vh_date"><?php esc_html_e( 'Date worked', 'volunteer-hours' ); ?></label><br />
				<input type="date" id="vh_date" name="vh_date" required
					value="<?php echo esc_attr( $is_edit ? $edit->work_date : wp_date( 'Y-m-d' ) ); ?>"
					max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
			</p>
			<p>
				<label for="vh_hours"><?php esc_html_e( 'Hours worked', 'volunteer-hours' ); ?></label><br />
				<input type="number" id="vh_hours" name="vh_hours" required step="0.25" min="0.25" max="24"
					value="<?php echo esc_attr( $is_edit ? $edit->hours : '' ); ?>" />
			</p>
			<p>
				<label><?php esc_html_e( 'Projects (check all that apply)', 'volunteer-hours' ); ?></label><br />
				<?php
				$checked_ids = $is_edit ? $edit->project_ids : array();
				foreach ( $projects as $pr ) :
					?>
					<label class="vh-project">
						<input type="checkbox" name="vh_projects[]" value="<?php echo esc_attr( $pr->id ); ?>"
							<?php checked( in_array( (int) $pr->id, $checked_ids, true ) ); ?> />
						<?php echo esc_html( $pr->name ); ?>
					</label><br />
				<?php endforeach; ?>
			</p>
			<p>
				<label for="vh_description"><?php esc_html_e( 'Description of work', 'volunteer-hours' ); ?></label><br />
				<textarea id="vh_description" name="vh_description" rows="3" cols="50"><?php echo esc_textarea( $is_edit ? $edit->description : '' ); ?></textarea>
			</p>
			<p>
				<button type="submit"><?php echo $is_edit ? esc_html__( 'Update entry', 'volunteer-hours' ) : esc_html__( 'Register hours', 'volunteer-hours' ); ?></button>
				<?php if ( $is_edit ) : ?>
				<a href="<?php echo esc_url( remove_query_arg( array( 'vh_edit', 'vh_msg' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'volunteer-hours' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	private function render_my_hours( $entries, $month, $total ) {
		$base = remove_query_arg( array( 'vh_edit', 'vh_msg' ) );
		?>
			<h3><?php esc_html_e( 'My hours', 'volunteer-hours' ); ?></h3>
		<form method="get" class="vh-month-filter">
			<?php
			// Preserve non-vh query args (e.g. page id on plain permalinks).
			foreach ( $_GET as $k => $v ) { // phpcs:ignore
				if ( is_string( $v ) && 0 !== strpos( $k, 'vh_' ) ) {
					echo '<input type="hidden" name="' . esc_attr( sanitize_key( $k ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $v ) ) ) . '" />';
				}
			}
			?>
				<label for="vh_month"><?php esc_html_e( 'Month', 'volunteer-hours' ); ?></label>
			<input type="month" id="vh_month" name="vh_month" value="<?php echo esc_attr( $month ); ?>" />
				<button type="submit"><?php esc_html_e( 'Show', 'volunteer-hours' ); ?></button>
		</form>

		<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No hours registered for this month.', 'volunteer-hours' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="vh-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'volunteer-hours' ); ?></th>
					<th><?php esc_html_e( 'Hours', 'volunteer-hours' ); ?></th>
					<th><?php esc_html_e( 'Projects', 'volunteer-hours' ); ?></th>
					<th><?php esc_html_e( 'Description', 'volunteer-hours' ); ?></th>
					<th><?php esc_html_e( 'Status', 'volunteer-hours' ); ?></th>
					<th class="vh-noprint"><?php esc_html_e( 'Actions', 'volunteer-hours' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $e ) : ?>
					<tr>
						<td><?php echo esc_html( $e->work_date ); ?></td>
						<td><?php echo esc_html( self::fmt_hours( $e->hours ) ); ?></td>
						<td><?php echo esc_html( $e->project_names ); ?></td>
						<td><?php echo esc_html( $e->description ); ?></td>
						<td class="vh-status-<?php echo (int) $e->paid ? 'paid' : ( (int) $e->reviewed ? 'reviewed' : 'pending' ); ?>"><?php echo esc_html( VH_Data::status_label( $e ) ); ?></td>
						<td class="vh-noprint">
							<?php if ( (int) $e->paid ) : ?>
								&mdash;
							<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'vh_edit', (int) $e->id, $base ) ); ?>"><?php esc_html_e( 'Edit', 'volunteer-hours' ); ?></a>
					<form method="post" class="vh-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this entry?', 'volunteer-hours' ) ); ?>');">
									<?php wp_nonce_field( 'vh_front', 'vh_nonce' ); ?>
									<input type="hidden" name="vh_action" value="delete" />
									<input type="hidden" name="vh_entry_id" value="<?php echo esc_attr( $e->id ); ?>" />
						<button type="submit" class="vh-link"><?php esc_html_e( 'Delete', 'volunteer-hours' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th><?php esc_html_e( 'Total', 'volunteer-hours' ); ?></th>
					<th><?php echo esc_html( self::fmt_hours( $total ) ); ?></th>
					<th colspan="4"></th>
				</tr>
			</tfoot>
		</table>

		<p class="vh-noprint">
			<a class="vh-button" href="<?php echo esc_url( self::my_csv_url( $month ) ); ?>"><?php esc_html_e( 'Download CSV', 'volunteer-hours' ); ?></a>
			<a class="vh-button" href="<?php echo esc_url( add_query_arg( array( 'vh_print' => 1, 'vh_month' => $month ), $base ) ); ?>"><?php esc_html_e( 'Print / Save as PDF', 'volunteer-hours' ); ?></a>
		</p>
		<?php
	}

	public static function my_csv_url( $month ) {
		// Nonce names come from VH_Export so this URL always matches the verifier.
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'vh_export_my_hours',
					'vh_month' => $month,
				),
				admin_url( 'admin-post.php' )
			),
			VH_Export::nonce_action( 'vh_export_my_hours' ),
			VH_Export::nonce_field( 'vh_export_my_hours' )
		);
	}

	/**
	 * Clean, printable monthly sheet for the current user (?vh_print=1).
	 * The browser's print dialog handles Save-as-PDF.
	 */
	public function maybe_print_view() {
		if ( empty( $_GET['vh_print'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter, no state is changed. State-changing requests in this plugin verify a nonce before this point.
			return;
		}
		$user_id = get_current_user_id();
		$month   = $this->selected_month();
		list( $from, $to ) = $this->month_bounds( $month );
		$entries = VH_Data::get_entries(
			array(
				'user_id' => $user_id,
				'from'    => $from,
				'to'      => $to,
			)
		);
		$total = 0;
		foreach ( $entries as $e ) {
			$total += (float) $e->hours;
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8" />
<title><?php esc_html_e( 'Volunteer hours', 'volunteer-hours' ); ?> — <?php echo esc_html( $month ); ?></title>
<style>
body { font-family: Arial, Helvetica, sans-serif; margin: 2em; color: #111; }
table { border-collapse: collapse; width: 100%; margin-top: 1em; }
th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; font-size: 14px; }
tfoot th { background: #eee; }
@media print { .vh-noprint { display: none; } }
</style>
</head>
<body>
<h1><?php esc_html_e( 'Volunteer hours', 'volunteer-hours' ); ?></h1>
<p>
	<strong><?php esc_html_e( 'Volunteer:', 'volunteer-hours' ); ?></strong> <?php echo esc_html( VH_Data::user_label( $user_id ) ); ?><br />
	<strong><?php esc_html_e( 'Month:', 'volunteer-hours' ); ?></strong> <?php echo esc_html( $month ); ?><br />
	<strong><?php esc_html_e( 'Generated:', 'volunteer-hours' ); ?></strong> <?php echo esc_html( wp_date( 'Y-m-d H:i' ) ); ?>
</p>
<table>
	<thead>
		<tr>
			<th><?php esc_html_e( 'Date', 'volunteer-hours' ); ?></th>
			<th><?php esc_html_e( 'Hours', 'volunteer-hours' ); ?></th>
			<th><?php esc_html_e( 'Projects', 'volunteer-hours' ); ?></th>
			<th><?php esc_html_e( 'Description', 'volunteer-hours' ); ?></th>
			<th><?php esc_html_e( 'Status', 'volunteer-hours' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $entries as $e ) : ?>
		<tr>
			<td><?php echo esc_html( $e->work_date ); ?></td>
			<td><?php echo esc_html( self::fmt_hours( $e->hours ) ); ?></td>
			<td><?php echo esc_html( $e->project_names ); ?></td>
			<td><?php echo esc_html( $e->description ); ?></td>
			<td><?php echo esc_html( VH_Data::status_label( $e ) ); ?></td>
		</tr>
		<?php endforeach; ?>
		<?php if ( empty( $entries ) ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'No hours registered for this month.', 'volunteer-hours' ); ?></td></tr>
		<?php endif; ?>
	</tbody>
	<tfoot>
		<tr>
			<th><?php esc_html_e( 'Total', 'volunteer-hours' ); ?></th>
			<th><?php echo esc_html( self::fmt_hours( $total ) ); ?></th>
			<th colspan="3"></th>
		</tr>
	</tfoot>
</table>
<p class="vh-noprint">
	<button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'volunteer-hours' ); ?></button>
	<a href="<?php echo esc_url( remove_query_arg( 'vh_print' ) ); ?>"><?php esc_html_e( 'Back', 'volunteer-hours' ); ?></a>
</p>
</body>
</html>
		<?php
		exit;
	}

	public static function fmt_hours( $hours ) {
		return rtrim( rtrim( number_format( (float) $hours, 2, '.', '' ), '0' ), '.' );
	}
}
