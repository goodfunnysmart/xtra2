<?php
/**
 * Sponsorship table and cell locking.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data access for hour sponsorships.
 */
class Xtra_Db {

	/**
	 * Prefixed table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hour_sponsorships';
	}

	/**
	 * Create or upgrade the table via dbDelta.
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			position_id bigint(20) unsigned NOT NULL,
			dow tinyint(3) unsigned NOT NULL,
			hour tinyint(3) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			donor_name varchar(191) NOT NULL DEFAULT '',
			donor_email varchar(191) NOT NULL DEFAULT '',
			donor_phone varchar(50) DEFAULT NULL,
			message text,
			amount_cents int(11) NOT NULL DEFAULT 0,
			stripe_customer_id varchar(64) DEFAULT NULL,
			stripe_subscription_id varchar(64) DEFAULT NULL,
			stripe_session_id varchar(64) DEFAULT NULL,
			pending_until datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			cancel_at datetime DEFAULT NULL,
			ended_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY position_cell (position_id, dow, hour),
			KEY live_lookup (position_id, ended_at),
			KEY stripe_sub (stripe_subscription_id),
			KEY stripe_session (stripe_session_id),
			KEY pending_until (pending_until)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Drop the table (uninstall).
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Expire pending rows whose window has passed.
	 *
	 * @param int $position_id Optional position filter. 0 = all.
	 */
	public static function expire_pending( int $position_id = 0 ): void {
		global $wpdb;
		$table = self::table();
		$now   = Xtra_Plugin::now_mysql();

		if ( $position_id > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET ended_at = %s
					WHERE position_id = %d
						AND status = %s
						AND ended_at IS NULL
						AND pending_until IS NOT NULL
						AND pending_until < %s",
					$now,
					$position_id,
					'pending',
					$now
				)
			);
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET ended_at = %s
				WHERE status = %s
					AND ended_at IS NULL
					AND pending_until IS NOT NULL
					AND pending_until < %s",
				$now,
				'pending',
				$now
			)
		);
	}

	/**
	 * Release cancelling rows whose cancel_at has arrived. Emails donors.
	 *
	 * @param int $position_id Optional position filter. 0 = all.
	 */
	public static function release_due_cancels( int $position_id = 0 ): void {
		global $wpdb;
		$table = self::table();
		$now   = Xtra_Plugin::now_mysql();

		$sql = "SELECT * FROM {$table}
			WHERE status = %s
				AND ended_at IS NULL
				AND cancel_at IS NOT NULL
				AND cancel_at <= %s";
		$args = array( 'cancelling', $now );

		if ( $position_id > 0 ) {
			$sql   .= ' AND position_id = %d';
			$args[] = $position_id;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		if ( ! $rows ) {
			return;
		}

		$by_sub = array();
		foreach ( $rows as $row ) {
			$wpdb->update(
				$table,
				array( 'ended_at' => $now ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
			$key = $row->stripe_subscription_id ? (string) $row->stripe_subscription_id : 'row-' . $row->id;
			if ( ! isset( $by_sub[ $key ] ) ) {
				$by_sub[ $key ] = array();
			}
			$by_sub[ $key ][] = $row;
		}

		foreach ( $by_sub as $group ) {
			Xtra_Mail::cell_released( $group );
		}
	}

	/**
	 * Live rows for a position (ended_at IS NULL), after expiry/release.
	 *
	 * @return array<int, object>
	 */
	public static function get_live_rows( int $position_id ): array {
		self::expire_pending( $position_id );
		self::release_due_cancels( $position_id );

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE position_id = %d AND ended_at IS NULL",
				$position_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Map "dow-hour" => row for live cells.
	 *
	 * @return array<string, object>
	 */
	public static function live_cell_map( int $position_id ): array {
		$map = array();
		foreach ( self::get_live_rows( $position_id ) as $row ) {
			$map[ (int) $row->dow . '-' . (int) $row->hour ] = $row;
		}
		return $map;
	}

	/**
	 * Count sponsored (including cancelling) live hours.
	 */
	public static function sponsored_count( int $position_id ): int {
		$n = 0;
		foreach ( self::get_live_rows( $position_id ) as $row ) {
			if ( in_array( $row->status, array( 'sponsored', 'cancelling' ), true ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Whether any non-ended sponsorship exists for this position (freezes schedule).
	 */
	public static function has_live_sponsorships( int $position_id ): bool {
		global $wpdb;
		$table = self::table();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE position_id = %d AND ended_at IS NULL LIMIT 1",
				$position_id
			)
		);
		return ! empty( $found );
	}

	/**
	 * Lock cells as pending inside a transaction. One live row per cell.
	 *
	 * @param int                  $position_id Position CPT ID.
	 * @param array<int, array{dow:int, hour:int}> $cells Cells to lock.
	 * @param int                  $amount_cents Per-cell snapshot of the rate.
	 * @return array<int, int>|WP_Error Inserted IDs or error.
	 */
	public static function lock_cells( int $position_id, array $cells, int $amount_cents ) {
		global $wpdb;

		if ( empty( $cells ) ) {
			return new WP_Error( 'empty', __( 'Select at least one hour.', 'xtra' ) );
		}

		$table   = self::table();
		$now     = Xtra_Plugin::now_mysql();
		$minutes = Xtra_Plugin::pending_minutes();
		// pending_until is stored in WP local time, matching created_at.
		$until = wp_date( 'Y-m-d H:i:s', time() + ( $minutes * 60 ) );

		$wpdb->query( 'START TRANSACTION' );

		self::expire_pending( $position_id );
		self::release_due_cancels( $position_id );

		foreach ( $cells as $cell ) {
			$dow  = (int) $cell['dow'];
			$hour = (int) $cell['hour'];
			$live = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					WHERE position_id = %d AND dow = %d AND hour = %d AND ended_at IS NULL
					LIMIT 1
					FOR UPDATE",
					$position_id,
					$dow,
					$hour
				)
			);
			if ( $live ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error(
					'taken',
					__( 'One or more of those hours is no longer available. Please refresh and try again.', 'xtra' )
				);
			}
		}

		$ids = array();
		foreach ( $cells as $cell ) {
			$ok = $wpdb->insert(
				$table,
				array(
					'position_id'  => $position_id,
					'dow'          => (int) $cell['dow'],
					'hour'         => (int) $cell['hour'],
					'status'       => 'pending',
					'donor_name'   => '',
					'donor_email'  => '',
					'donor_phone'  => null,
					'message'      => null,
					'amount_cents' => $amount_cents,
					'pending_until'=> $until,
					'created_at'   => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( ! $ok ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'insert_failed', __( 'Could not reserve those hours. Please try again.', 'xtra' ) );
			}
			$ids[] = (int) $wpdb->insert_id;
		}

		$wpdb->query( 'COMMIT' );
		return $ids;
	}

	/**
	 * Fetch rows by IDs.
	 *
	 * @param array<int, int> $ids IDs.
	 * @return array<int, object>
	 */
	public static function get_rows_by_ids( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table    = self::table();
		$in       = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$prepared = $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$in})", $ids );
		$rows     = $wpdb->get_results( $prepared );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows sharing a Stripe subscription.
	 *
	 * @return array<int, object>
	 */
	public static function get_rows_by_subscription( string $subscription_id ): array {
		global $wpdb;
		if ( $subscription_id === '' ) {
			return array();
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE stripe_subscription_id = %s AND ended_at IS NULL",
				$subscription_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update a row by ID.
	 *
	 * @param int                  $id     Row ID.
	 * @param array<string, mixed> $fields Fields.
	 */
	public static function update_row( int $id, array $fields ): bool {
		global $wpdb;
		$result = $wpdb->update( self::table(), $fields, array( 'id' => $id ), null, array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Live row for a specific cell, excluding an optional ID.
	 */
	public static function live_row_for_cell( int $position_id, int $dow, int $hour, int $exclude_id = 0 ) {
		global $wpdb;
		$table = self::table();
		if ( $exclude_id > 0 ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE position_id = %d AND dow = %d AND hour = %d AND ended_at IS NULL AND id <> %d
					LIMIT 1",
					$position_id,
					$dow,
					$hour,
					$exclude_id
				)
			);
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE position_id = %d AND dow = %d AND hour = %d AND ended_at IS NULL
				LIMIT 1",
				$position_id,
				$dow,
				$hour
			)
		);
	}

	/**
	 * Admin list query.
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array{rows: array<int, object>, total: int}
	 */
	public static function query_admin( array $args ): array {
		global $wpdb;
		$table = self::table();

		$where = '1=1';
		$params = array();

		if ( ! empty( $args['position_id'] ) ) {
			$where   .= ' AND position_id = %d';
			$params[] = (int) $args['position_id'];
		}
		if ( ! empty( $args['status'] ) && $args['status'] !== 'all' ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		if ( empty( $args['include_ended'] ) ) {
			$where .= ' AND ended_at IS NULL';
		}

		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Mark a group of live rows as cancelling at month end.
	 *
	 * @param array<int, object> $rows Rows.
	 * @param string             $cancel_at MySQL datetime.
	 */
	public static function mark_cancelling( array $rows, string $cancel_at ): void {
		foreach ( $rows as $row ) {
			if ( ! empty( $row->ended_at ) ) {
				continue;
			}
			self::update_row(
				(int) $row->id,
				array(
					'status'    => 'cancelling',
					'cancel_at' => $cancel_at,
				)
			);
		}
	}
}
