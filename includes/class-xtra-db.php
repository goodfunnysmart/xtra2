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
			donor_address varchar(191) NOT NULL DEFAULT '',
			donor_suburb varchar(100) NOT NULL DEFAULT '',
			donor_state varchar(10) NOT NULL DEFAULT '',
			donor_postcode varchar(10) NOT NULL DEFAULT '',
			message text,
			opt_in_news tinyint(1) NOT NULL DEFAULT 1,
			opt_in_hour_start tinyint(1) NOT NULL DEFAULT 0,
			amount_cents int(11) NOT NULL DEFAULT 0,
			stripe_customer_id varchar(255) DEFAULT NULL,
			stripe_subscription_id varchar(255) DEFAULT NULL,
			stripe_session_id varchar(255) DEFAULT NULL,
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
	 * Payments table name.
	 */
	public static function payments_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'xtra_payments';
	}

	/**
	 * Create or upgrade the payments table via dbDelta.
	 */
	public static function create_payments_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::payments_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_invoice_id varchar(64) NOT NULL,
			stripe_customer_id varchar(64) DEFAULT NULL,
			stripe_subscription_id varchar(64) DEFAULT NULL,
			donor_name varchar(191) NOT NULL DEFAULT '',
			donor_email varchar(191) NOT NULL DEFAULT '',
			amount_cents int(11) NOT NULL DEFAULT 0,
			paid_at datetime NOT NULL,
			position_id bigint(20) unsigned DEFAULT NULL,
			hour_labels text,
			receipt_number varchar(32) DEFAULT NULL,
			receipt_sent_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'paid',
			currency varchar(3) NOT NULL DEFAULT 'aud',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_invoice (stripe_invoice_id),
			KEY paid_at (paid_at),
			KEY donor_email (donor_email),
			KEY stripe_sub (stripe_subscription_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Donation summary send-log table name.
	 */
	public static function summary_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'xtra_summary_log';
	}

	/**
	 * Create or upgrade the donation summary send-log table via dbDelta (0.1.16).
	 * One row per send attempt; re-sending is allowed, so this is a log, not a lock.
	 */
	public static function create_summary_log_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::summary_log_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			donor_email varchar(191) NOT NULL DEFAULT '',
			fy varchar(9) NOT NULL DEFAULT '',
			result varchar(20) NOT NULL DEFAULT 'sent',
			payment_count int(11) NOT NULL DEFAULT 0,
			total_cents int(11) NOT NULL DEFAULT 0,
			sent_by bigint(20) unsigned NOT NULL DEFAULT 0,
			sent_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email_fy (donor_email, fy),
			KEY fy (fy)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create/upgrade every plugin table.
	 */
	public static function create_all_tables(): void {
		self::create_table();
		self::create_payments_table();
		self::create_summary_log_table();
	}

	/**
	 * Run schema upgrades when the plugin version changes.
	 */
	public static function maybe_upgrade(): void {
		$db_version = (string) get_option( 'xtra_db_version', '0' );
		if ( version_compare( $db_version, XTRA_VERSION, '>=' ) ) {
			return;
		}
		self::create_all_tables();
		update_option( 'xtra_db_version', XTRA_VERSION, false );
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
	 * Drop the payments table (uninstall).
	 */
	public static function drop_payments_table(): void {
		global $wpdb;
		$table = self::payments_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Drop the donation summary log table (uninstall).
	 */
	public static function drop_summary_log_table(): void {
		global $wpdb;
		$table = self::summary_log_table();
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
					'position_id'       => $position_id,
					'dow'               => (int) $cell['dow'],
					'hour'              => (int) $cell['hour'],
					'status'            => 'pending',
					'donor_name'        => '',
					'donor_email'       => '',
					'donor_phone'       => null,
					'message'           => null,
					'opt_in_news'       => 1,
					'opt_in_hour_start' => 0,
					'amount_cents'      => $amount_cents,
					'pending_until'     => $until,
					'created_at'        => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
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
		$prepared = $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$in})", ...$ids );
		$rows     = $wpdb->get_results( $prepared );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Live rows tied to a Stripe Checkout Session.
	 *
	 * @return array<int, object>
	 */
	public static function get_rows_by_stripe_session( string $session_id ): array {
		global $wpdb;
		if ( $session_id === '' ) {
			return array();
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE stripe_session_id = %s ORDER BY id ASC",
				$session_id
			)
		);
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
		$formats = array();
		foreach ( $fields as $value ) {
			if ( is_int( $value ) ) {
				$formats[] = '%d';
			} elseif ( is_float( $value ) ) {
				$formats[] = '%f';
			} elseif ( null === $value ) {
				$formats[] = '%s';
			} else {
				$formats[] = '%s';
			}
		}
		$result = $wpdb->update( self::table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			error_log( 'Xtra DB Update Error: ' . $wpdb->last_error . ' | Query: ' . $wpdb->last_query );
		}
		return true; // Return true even if zero rows changed (e.g. data was identical) so the process continues.
	}

	/**
	 * Update donor contact fields on all live rows for a subscription.
	 *
	 * @param array<string, string> $fields Donor fields.
	 */
	public static function update_donor_for_subscription( string $subscription_id, array $fields ): void {
		if ( $subscription_id === '' ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		$allowed = array(
			'donor_name'         => '%s',
			'donor_email'        => '%s',
			'donor_phone'        => '%s',
			'donor_address'      => '%s',
			'donor_suburb'       => '%s',
			'donor_state'        => '%s',
			'donor_postcode'     => '%s',
			'opt_in_news'        => '%d',
			'opt_in_hour_start'  => '%d',
		);
		$set    = array();
		$values = array();
		foreach ( $allowed as $key => $format ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}
			$set[]    = "{$key} = {$format}";
			$values[] = $fields[ $key ];
		}
		if ( empty( $set ) ) {
			return;
		}
		$values[] = $subscription_id;
		$sql      = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE stripe_subscription_id = %s AND ended_at IS NULL';
		$wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Insert a payment row if the Stripe invoice is not already recorded.
	 *
	 * @param array<string, mixed> $data Payment fields.
	 * @return int Insert id, or existing id, or 0 on failure.
	 */
	public static function insert_payment( array $data ): int {
		global $wpdb;
		$table      = self::payments_table();
		$invoice_id = isset( $data['stripe_invoice_id'] ) ? (string) $data['stripe_invoice_id'] : '';
		if ( $invoice_id === '' ) {
			return 0;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE stripe_invoice_id = %s",
				$invoice_id
			)
		);
		if ( $existing ) {
			return (int) $existing;
		}

		$now = Xtra_Plugin::now_mysql();
		$row = array(
			'stripe_invoice_id'      => $invoice_id,
			'stripe_customer_id'     => isset( $data['stripe_customer_id'] ) ? (string) $data['stripe_customer_id'] : null,
			'stripe_subscription_id' => isset( $data['stripe_subscription_id'] ) ? (string) $data['stripe_subscription_id'] : null,
			'donor_name'             => isset( $data['donor_name'] ) ? (string) $data['donor_name'] : '',
			'donor_email'            => isset( $data['donor_email'] ) ? (string) $data['donor_email'] : '',
			'amount_cents'           => isset( $data['amount_cents'] ) ? (int) $data['amount_cents'] : 0,
			'paid_at'                => isset( $data['paid_at'] ) ? (string) $data['paid_at'] : $now,
			'position_id'            => isset( $data['position_id'] ) ? (int) $data['position_id'] : null,
			'hour_labels'            => isset( $data['hour_labels'] ) ? (string) $data['hour_labels'] : '',
			'receipt_number'         => isset( $data['receipt_number'] ) ? (string) $data['receipt_number'] : null,
			'status'                 => isset( $data['status'] ) && $data['status'] !== '' ? sanitize_key( (string) $data['status'] ) : 'paid',
			'currency'               => isset( $data['currency'] ) && $data['currency'] !== '' ? strtolower( substr( (string) $data['currency'], 0, 3 ) ) : 'aud',
			'created_at'             => $now,
		);

		$ok = $wpdb->insert(
			$table,
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch a payment by id.
	 *
	 * @return object|null
	 */
	public static function get_payment( int $id ) {
		global $wpdb;
		$table = self::payments_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			)
		);
	}


	/**
	 * Fetch a payment by Stripe invoice id.
	 *
	 * @return object|null
	 */
	public static function get_payment_by_invoice( string $invoice_id ) {
		global $wpdb;
		if ( $invoice_id === '' ) {
			return null;
		}
		$table = self::payments_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE stripe_invoice_id = %s",
				$invoice_id
			)
		);
	}

	/**
	 * Recent payments ordered by paid_at DESC.
	 *
	 * @return array<int, object>
	 */
	public static function list_payments( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$table = self::payments_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY paid_at DESC LIMIT %d",
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Assign a receipt number to a payment if it does not have one.
	 */
	public static function ensure_receipt_number( int $payment_id ): string {
		$payment = self::get_payment( $payment_id );
		if ( ! $payment ) {
			return '';
		}
		if ( ! empty( $payment->receipt_number ) ) {
			return (string) $payment->receipt_number;
		}
		$number = Xtra_Receipts::next_receipt_number();
		self::update_payment(
			$payment_id,
			array(
				'receipt_number' => $number,
			)
		);
		return $number;
	}

	/**
	 * Update a payment row.
	 *
	 * @param array<string, mixed> $fields Fields.
	 */
	public static function update_payment( int $id, array $fields ): bool {
		global $wpdb;
		$result = $wpdb->update( self::payments_table(), $fields, array( 'id' => $id ), null, array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Payments in an Australian financial year for one donor email.
	 *
	 * @return array<int, object>
	 */
	public static function payments_for_donor_fy( string $email, string $fy_start, string $fy_end ): array {
		global $wpdb;
		if ( $email === '' ) {
			return array();
		}
		$table = self::payments_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE donor_email = %s
					AND paid_at >= %s
					AND paid_at <= %s
				ORDER BY paid_at ASC",
				$email,
				$fy_start,
				$fy_end
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All payments in a financial year, grouped by donor email.
	 *
	 * @return array<int, object>
	 */
	public static function payments_in_fy( string $fy_start, string $fy_end ): array {
		global $wpdb;
		$table = self::payments_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE paid_at >= %s AND paid_at <= %s
				ORDER BY donor_email ASC, paid_at ASC",
				$fy_start,
				$fy_end
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Donor totals for a financial year.
	 *
	 * @return array<int, array{email:string, name:string, total_cents:int, payment_count:int}>
	 */
	public static function donor_totals_fy( string $fy_start, string $fy_end ): array {
		global $wpdb;
		$table = self::payments_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT donor_email AS email,
					MAX(donor_name) AS name,
					SUM(amount_cents) AS total_cents,
					COUNT(*) AS payment_count
				FROM {$table}
				WHERE paid_at >= %s AND paid_at <= %s AND donor_email <> ''
				GROUP BY donor_email
				ORDER BY name ASC",
				$fy_start,
				$fy_end
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'email'         => (string) $row['email'],
				'name'          => (string) $row['name'],
				'total_cents'   => (int) $row['total_cents'],
				'payment_count' => (int) $row['payment_count'],
			);
		}
		return $out;
	}

	/**
	 * Sponsorship rows grouped for the admin sponsors list.
	 *
	 * @param array<string, mixed> $args Filters (position_id, status).
	 * @return array<int, array<string, mixed>>
	 */
	public static function sponsor_groups( array $args ): array {
		$result = self::query_admin(
			array(
				'position_id'   => $args['position_id'] ?? 0,
				'status'        => $args['status'] ?? 'all',
				'page'          => 1,
				'per_page'      => 500,
				'include_ended' => false,
			)
		);

		$groups = array();
		foreach ( $result['rows'] as $row ) {
			$sub = (string) $row->stripe_subscription_id;
			$key = $sub !== '' ? 'sub:' . $sub : ( (string) $row->stripe_session_id !== '' ? 'sess:' . $row->stripe_session_id : 'row:' . $row->id );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'                    => $key,
					'donor_name'             => (string) $row->donor_name,
					'donor_email'            => (string) $row->donor_email,
					'status'                 => (string) $row->status,
					'stripe_subscription_id' => $sub,
					'stripe_session_id'      => (string) $row->stripe_session_id,
					'position_id'            => (int) $row->position_id,
					'hours'                  => array(),
					'monthly_cents'          => 0,
					'row_ids'                => array(),
				);
			}
			$groups[ $key ]['hours'][]         = Xtra_Plugin::cell_label( (int) $row->dow, (int) $row->hour );
			$groups[ $key ]['monthly_cents']  += (int) $row->amount_cents;
			$groups[ $key ]['row_ids'][]       = (int) $row->id;
			if ( (string) $row->donor_name !== '' ) {
				$groups[ $key ]['donor_name'] = (string) $row->donor_name;
			}
			if ( (string) $row->donor_email !== '' ) {
				$groups[ $key ]['donor_email'] = (string) $row->donor_email;
			}
			if ( $groups[ $key ]['status'] !== (string) $row->status ) {
				$groups[ $key ]['status'] = 'mixed';
			}
		}

		return array_values( $groups );
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

	/**
	 * Payment status values that count as a successful (received) payment for
	 * donation summaries. Rows are only recorded from Stripe invoice.paid with
	 * amount_paid > 0, stored as status "paid"; "succeeded" is accepted too.
	 *
	 * @return array<int, string>
	 */
	public static function success_statuses(): array {
		return (array) apply_filters( 'xtra_success_payment_statuses', array( 'paid', 'succeeded' ) );
	}

	/**
	 * SQL fragment "status IN (...)" for successful payments (already prepared).
	 */
	private static function success_status_sql(): string {
		global $wpdb;
		$statuses = array_values( array_filter( array_map( 'sanitize_key', self::success_statuses() ) ) );
		if ( empty( $statuses ) ) {
			$statuses = array( 'paid' );
		}
		$in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return $wpdb->prepare( "status IN ({$in}) AND amount_cents > 0", ...$statuses ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
	}

	/**
	 * Start years of financial years that have payments, newest first.
	 *
	 * @param bool $success_only Only count successful payments.
	 * @return array<int, int>
	 */
	public static function payment_fy_start_years( bool $success_only = false ): array {
		global $wpdb;
		$table = self::payments_table();
		$where = $success_only ? 'WHERE ' . self::success_status_sql() : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name internal, where prepared above.
		$years = $wpdb->get_col( "SELECT DISTINCT IF(MONTH(paid_at) >= 7, YEAR(paid_at), YEAR(paid_at) - 1) AS fy_start FROM {$table} {$where} ORDER BY fy_start DESC" );
		if ( ! is_array( $years ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $years ) ) );
	}

	/**
	 * All payments (optionally limited to a date range), oldest first. For CSV export.
	 *
	 * @return array<int, object>
	 */
	public static function payments_for_export( string $start = '', string $end = '' ): array {
		global $wpdb;
		$table = self::payments_table();
		if ( $start !== '' && $end !== '' ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE paid_at >= %s AND paid_at <= %s ORDER BY paid_at ASC, id ASC",
					$start,
					$end
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name internal.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY paid_at ASC, id ASC" );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every sponsorship row (including ended), oldest first. For CSV export.
	 *
	 * @return array<int, object>
	 */
	public static function sponsorships_for_export(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name internal.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Successful payments in a date range grouped by donor email (case-insensitive).
	 *
	 * @return array<int, array{email:string, name:string, total_cents:int, payment_count:int, first_paid:string, last_paid:string}>
	 */
	public static function donor_summaries_fy( string $fy_start, string $fy_end ): array {
		global $wpdb;
		$table   = self::payments_table();
		$success = self::success_status_sql();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name internal, status fragment prepared.
		$sql  = "SELECT LOWER(TRIM(donor_email)) AS email,
				SUBSTRING_INDEX(GROUP_CONCAT(donor_name ORDER BY paid_at DESC SEPARATOR '\n'), '\n', 1) AS name,
				SUM(amount_cents) AS total_cents,
				COUNT(*) AS payment_count,
				MIN(paid_at) AS first_paid,
				MAX(paid_at) AS last_paid
			FROM {$table}
			WHERE paid_at >= %s AND paid_at <= %s AND TRIM(donor_email) <> '' AND {$success}
			GROUP BY LOWER(TRIM(donor_email))
			ORDER BY name ASC, email ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $fy_start, $fy_end ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'email'         => (string) $row['email'],
				'name'          => (string) $row['name'],
				'total_cents'   => (int) $row['total_cents'],
				'payment_count' => (int) $row['payment_count'],
				'first_paid'    => (string) $row['first_paid'],
				'last_paid'     => (string) $row['last_paid'],
			);
		}
		return $out;
	}

	/**
	 * Successful payments for one donor email (case-insensitive) in a date range, oldest first.
	 *
	 * @return array<int, object>
	 */
	public static function successful_payments_for_donor( string $email, string $fy_start, string $fy_end ): array {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			return array();
		}
		$table   = self::payments_table();
		$success = self::success_status_sql();
		$sql     = "SELECT * FROM {$table}
			WHERE LOWER(TRIM(donor_email)) = %s AND paid_at >= %s AND paid_at <= %s AND {$success}
			ORDER BY paid_at ASC, id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $email, $fy_start, $fy_end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record a donation summary send attempt.
	 */
	public static function log_summary_send( string $email, string $fy, bool $sent, int $payment_count, int $total_cents ): void {
		global $wpdb;
		$wpdb->insert(
			self::summary_log_table(),
			array(
				'donor_email'   => strtolower( trim( $email ) ),
				'fy'            => $fy,
				'result'        => $sent ? 'sent' : 'failed',
				'payment_count' => $payment_count,
				'total_cents'   => $total_cents,
				'sent_by'       => get_current_user_id(),
				'sent_at'       => Xtra_Plugin::now_mysql(),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Last successful summary send per donor email for a financial year.
	 *
	 * @param string $fy FY key, or '' for every FY (keys become "fy|email").
	 * @return array<string, array{sent_at:string, count:int}>
	 */
	public static function summary_last_sent_map( string $fy = '' ): array {
		global $wpdb;
		$table = self::summary_log_table();
		if ( $fy !== '' ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT donor_email, fy, MAX(sent_at) AS last_sent, COUNT(*) AS sends FROM {$table} WHERE fy = %s AND result = 'sent' GROUP BY donor_email, fy",
					$fy
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name internal.
			$rows = $wpdb->get_results( "SELECT donor_email, fy, MAX(sent_at) AS last_sent, COUNT(*) AS sends FROM {$table} WHERE result = 'sent' GROUP BY donor_email, fy", ARRAY_A );
		}
		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$key         = $fy !== '' ? (string) $row['donor_email'] : $row['fy'] . '|' . $row['donor_email'];
				$map[ $key ] = array(
					'sent_at' => (string) $row['last_sent'],
					'count'   => (int) $row['sends'],
				);
			}
		}
		return $map;
	}

	/**
	 * Live sponsored rows opted in to hour-start emails for a given cell.
	 *
	 * @return array<int, object>
	 */
	public static function rows_for_hour_start( int $dow, int $hour ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE ended_at IS NULL
					AND status IN ('sponsored', 'cancelling')
					AND opt_in_hour_start = 1
					AND dow = %d
					AND hour = %d",
				$dow,
				$hour
			)
		);
		return is_array( $rows ) ? $rows : array();
	}
}


add_action( 'admin_init', function() {
	$current_db_version = get_option( 'xtra_db_version' );
	$xtra_version = defined( 'XTRA_VERSION' ) ? XTRA_VERSION : 'unknown';

	if ( $current_db_version !== $xtra_version ) {
		Xtra_Db::create_all_tables();
		update_option( 'xtra_db_version', $xtra_version, false );
	}
});
