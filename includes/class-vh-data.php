<?php
/**
 * All database access for Volunteer Hours.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VH_Data {

	/* ---------- Projects ---------- */

	public static function get_projects( $active_only = false ) {
		global $wpdb;
		$sql = "SELECT * FROM {$wpdb->prefix}vh_projects";
		if ( $active_only ) {
			$sql .= ' WHERE active = 1';
		}
		$sql .= ' ORDER BY name ASC';
		return $wpdb->get_results( $sql );
	}

	public static function add_project( $name ) {
		global $wpdb;
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'vh_empty', __( 'Project name cannot be empty.', 'wp-volunteer-hours' ) );
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}vh_projects WHERE name = %s", $name ) );
		if ( $exists ) {
			return new WP_Error( 'vh_dup', __( 'A project with that name already exists.', 'wp-volunteer-hours' ) );
		}
		$wpdb->insert(
			$wpdb->prefix . 'vh_projects',
			array(
				'name'       => $name,
				'active'     => 1,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function rename_project( $id, $name ) {
		global $wpdb;
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'vh_empty', __( 'Project name cannot be empty.', 'wp-volunteer-hours' ) );
		}
		$wpdb->update( $wpdb->prefix . 'vh_projects', array( 'name' => $name ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
		return true;
	}

	public static function set_project_active( $id, $active ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'vh_projects', array( 'active' => $active ? 1 : 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
		return true;
	}

	/**
	 * Delete a project only when no entries reference it.
	 */
	public static function delete_project( $id ) {
		global $wpdb;
		$id   = (int) $id;
		$used = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}vh_entry_projects WHERE project_id = %d", $id ) );
		if ( $used > 0 ) {
			return new WP_Error( 'vh_in_use', __( 'This project has hours registered against it. Deactivate it instead of deleting.', 'wp-volunteer-hours' ) );
		}
		$wpdb->delete( $wpdb->prefix . 'vh_projects', array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/* ---------- Entries ---------- */

	/**
	 * Validate and save an entry. Returns entry id or WP_Error.
	 *
	 * @param array $args user_id, work_date (Y-m-d), hours, description, project_ids (array), id (optional, for update).
	 */
	public static function save_entry( $args ) {
		global $wpdb;

		$user_id     = (int) $args['user_id'];
		$work_date   = isset( $args['work_date'] ) ? sanitize_text_field( $args['work_date'] ) : '';
		$hours       = isset( $args['hours'] ) ? (float) $args['hours'] : 0;
		$description = isset( $args['description'] ) ? sanitize_textarea_field( $args['description'] ) : '';
		$project_ids = isset( $args['project_ids'] ) ? array_filter( array_map( 'intval', (array) $args['project_ids'] ) ) : array();
		$entry_id    = isset( $args['id'] ) ? (int) $args['id'] : 0;

		// Date must be a real Y-m-d date and not more than one day in the future.
		$dt = DateTime::createFromFormat( '!Y-m-d', $work_date, wp_timezone() );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $work_date ) {
			return new WP_Error( 'vh_date', __( 'Please provide a valid date.', 'wp-volunteer-hours' ) );
		}
		$tomorrow = new DateTime( 'tomorrow', wp_timezone() );
		if ( $dt > $tomorrow ) {
			return new WP_Error( 'vh_future', __( 'The date cannot be in the future.', 'wp-volunteer-hours' ) );
		}

		if ( $hours < 0.25 || $hours > 24 ) {
			return new WP_Error( 'vh_hours', __( 'Hours must be between 0.25 and 24.', 'wp-volunteer-hours' ) );
		}

		if ( empty( $project_ids ) ) {
			return new WP_Error( 'vh_projects', __( 'Please select at least one project.', 'wp-volunteer-hours' ) );
		}

		// Only allow project ids that actually exist.
		$placeholders = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );
		$valid_ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}vh_projects WHERE id IN ($placeholders)", $project_ids ) ); // phpcs:ignore
		$valid_ids    = array_map( 'intval', $valid_ids );
		if ( empty( $valid_ids ) ) {
			return new WP_Error( 'vh_projects', __( 'Please select at least one valid project.', 'wp-volunteer-hours' ) );
		}

		$now  = current_time( 'mysql' );
		$data = array(
			'user_id'     => $user_id,
			'work_date'   => $work_date,
			'hours'       => $hours,
			'description' => $description,
			'updated_at'  => $now,
		);
		$fmt  = array( '%d', '%s', '%f', '%s', '%s' );

		if ( $entry_id ) {
			// Any edit sends the entry back for review.
			$data['reviewed'] = 0;
			$data['paid']     = 0;
			$fmt[]            = '%d';
			$fmt[]            = '%d';
			$ok = $wpdb->update( $wpdb->prefix . 'vh_entries', $data, array( 'id' => $entry_id ), $fmt, array( '%d' ) );
			if ( false === $ok ) {
				/* translators: %s: database error text */
				return new WP_Error( 'vh_db', sprintf( __( 'The entry could not be saved (database error: %s). Please contact an administrator.', 'wp-volunteer-hours' ), $wpdb->last_error ) );
			}
			$wpdb->delete( $wpdb->prefix . 'vh_entry_projects', array( 'entry_id' => $entry_id ), array( '%d' ) );
		} else {
			$data['created_at'] = $now;
			$fmt[]              = '%s';
			$ok = $wpdb->insert( $wpdb->prefix . 'vh_entries', $data, $fmt );
			$entry_id = (int) $wpdb->insert_id;
			if ( false === $ok || ! $entry_id ) {
				/* translators: %s: database error text */
				return new WP_Error( 'vh_db', sprintf( __( 'The entry could not be saved (database error: %s). Please contact an administrator.', 'wp-volunteer-hours' ), $wpdb->last_error ) );
			}
		}

		foreach ( $valid_ids as $pid ) {
			$wpdb->insert(
				$wpdb->prefix . 'vh_entry_projects',
				array(
					'entry_id'   => $entry_id,
					'project_id' => $pid,
				),
				array( '%d', '%d' )
			);
		}

		return $entry_id;
	}

	/**
	 * Set the reviewed/paid flags on an entry.
	 * Rule: an entry cannot be marked paid until it is reviewed,
	 * and un-reviewing an entry also clears its paid flag.
	 */
	public static function set_entry_status( $id, $field, $value ) {
		global $wpdb;
		$id    = (int) $id;
		$value = $value ? 1 : 0;
		if ( ! in_array( $field, array( 'reviewed', 'paid' ), true ) ) {
			return new WP_Error( 'vh_field', __( 'Invalid status field.', 'wp-volunteer-hours' ) );
		}
		$entry = self::get_entry( $id );
		if ( ! $entry ) {
			return new WP_Error( 'vh_missing', __( 'Entry not found.', 'wp-volunteer-hours' ) );
		}

		if ( 'paid' === $field && $value && ! (int) $entry->reviewed ) {
			return new WP_Error( 'vh_order', __( 'Hours must be reviewed before they can be marked as paid.', 'wp-volunteer-hours' ) );
		}

		$data = array( $field => $value );
		if ( 'reviewed' === $field && ! $value ) {
			$data['paid'] = 0; // Un-reviewing clears paid.
		}
		$wpdb->update( $wpdb->prefix . 'vh_entries', $data, array( 'id' => $id ), array( '%d', '%d' ), array( '%d' ) );
		return true;
	}

	/**
	 * Human label for an entry's status.
	 */
	public static function status_label( $entry ) {
		if ( (int) $entry->paid ) {
			return __( 'Paid', 'wp-volunteer-hours' );
		}
		if ( (int) $entry->reviewed ) {
			return __( 'Reviewed', 'wp-volunteer-hours' );
		}
		return __( 'Pending', 'wp-volunteer-hours' );
	}

	public static function get_entry( $id ) {
		global $wpdb;
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}vh_entries WHERE id = %d", (int) $id ) );
		if ( $entry ) {
			$entry->project_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT project_id FROM {$wpdb->prefix}vh_entry_projects WHERE entry_id = %d", (int) $id ) ) );
		}
		return $entry;
	}

	public static function delete_entry( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( $wpdb->prefix . 'vh_entry_projects', array( 'entry_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'vh_entries', array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/**
	 * Fetch entries with their project names, filtered.
	 *
	 * @param array $filters user_id, project_id, from (Y-m-d), to (Y-m-d).
	 */
	public static function get_entries( $filters = array() ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'e.user_id = %d';
			$vals[]  = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[] = 'e.work_date >= %s';
			$vals[]  = $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[] = 'e.work_date <= %s';
			$vals[]  = $filters['to'];
		}
		if ( ! empty( $filters['project_id'] ) ) {
			$where[] = "e.id IN (SELECT entry_id FROM {$p}vh_entry_projects WHERE project_id = %d)";
			$vals[]  = (int) $filters['project_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			if ( 'pending' === $filters['status'] ) {
				$where[] = 'e.reviewed = 0';
			} elseif ( 'reviewed' === $filters['status'] ) {
				$where[] = 'e.reviewed = 1 AND e.paid = 0';
			} elseif ( 'paid' === $filters['status'] ) {
				$where[] = 'e.paid = 1';
			}
		}

		$sql = "SELECT e.*,
				( SELECT GROUP_CONCAT(pr.name ORDER BY pr.name SEPARATOR ', ')
				  FROM {$p}vh_entry_projects ep
				  INNER JOIN {$p}vh_projects pr ON pr.id = ep.project_id
				  WHERE ep.entry_id = e.id ) AS project_names
			FROM {$p}vh_entries e
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY e.work_date DESC, e.id DESC';

		if ( $vals ) {
			$sql = $wpdb->prepare( $sql, $vals ); // phpcs:ignore
		}
		// Placeholder no-op patch to touch file context.
		return $wpdb->get_results( $sql ); // phpcs:ignore
	}

	/**
	 * Hours per user for a date range. Each entry counted once.
	 */
	public static function report_hours_per_user( $from, $to ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.user_id, SUM(e.hours) AS total_hours, COUNT(*) AS entry_count
				FROM {$wpdb->prefix}vh_entries e
				WHERE e.work_date >= %s AND e.work_date <= %s
				GROUP BY e.user_id
				ORDER BY total_hours DESC",
				$from,
				$to
			)
		);
	}

	/**
	 * Hours per project for a date range. Multi-project entries count fully toward each project.
	 */
	public static function report_hours_per_project( $from, $to ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pr.id, pr.name, SUM(e.hours) AS total_hours, COUNT(e.id) AS entry_count
				FROM {$p}vh_projects pr
				INNER JOIN {$p}vh_entry_projects ep ON ep.project_id = pr.id
				INNER JOIN {$p}vh_entries e ON e.id = ep.entry_id
				WHERE e.work_date >= %s AND e.work_date <= %s
				GROUP BY pr.id, pr.name
				ORDER BY total_hours DESC",
				$from,
				$to
			)
		);
	}

	/**
	 * Display name for a user id, tolerating deleted accounts.
	 */
	public static function user_label( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			return $user->display_name;
		}
		/* translators: %d: numeric user id of a deleted account */
		return sprintf( __( 'Deleted user #%d', 'wp-volunteer-hours' ), (int) $user_id );
	}
}
