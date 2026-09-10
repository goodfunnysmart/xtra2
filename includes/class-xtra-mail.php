<?php
/**
 * Hard-coded wp_mail templates (Australian English).
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Outbound email.
 */
class Xtra_Mail {

	/**
	 * Send a message using plugin from-name / from-email.
	 */
	public static function send( string $to, string $subject, string $body ): bool {
		if ( $to === '' || ! is_email( $to ) ) {
			return false;
		}
		$opts = Xtra_Plugin::options();
		$from_name  = sanitize_text_field( (string) $opts['from_name'] );
		$from_email = sanitize_email( (string) $opts['from_email'] );
		if ( $from_email === '' ) {
			$from_email = (string) get_option( 'admin_email' );
		}

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);

		$subject = apply_filters( 'xtra_mail_subject', $subject, $to );
		$body    = apply_filters( 'xtra_mail_body', $body, $to );

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Org + role labels from a row.
	 *
	 * @param object $row Sponsorship row.
	 * @return array{org:string, role:string}
	 */
	private static function context_from_row( object $row ): array {
		$position_id = (int) $row->position_id;
		$org         = (string) get_post_meta( $position_id, Xtra_Cpt::META_ORG, true );
		$role        = get_the_title( $position_id );
		if ( $org === '' ) {
			$org = get_bloginfo( 'name' );
		}
		if ( $role === '' ) {
			$role = __( 'staff position', 'xtra' );
		}
		return array(
			'org'  => $org,
			'role' => $role,
		);
	}

	/**
	 * Bullet list of cells.
	 *
	 * @param array<int, object> $rows Rows.
	 */
	private static function hours_list( array $rows ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = '- ' . Xtra_Plugin::cell_label( (int) $row->dow, (int) $row->hour );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Monthly total for a set of rows (rate × hours, stored per cell).
	 *
	 * @param array<int, object> $rows Rows.
	 */
	private static function monthly_total( array $rows ): int {
		$total = 0;
		foreach ( $rows as $row ) {
			$total += (int) $row->amount_cents;
		}
		return $total;
	}

	/**
	 * Checkout received — cells are pending.
	 *
	 * @param array<int, object> $rows Rows.
	 */
	public static function checkout_received( array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}
		$first   = $rows[0];
		$ctx     = self::context_from_row( $first );
		$minutes = Xtra_Plugin::pending_minutes();
		$subject = sprintf(
			/* translators: %s organisation name */
			__( 'We have reserved your hours — %s', 'xtra' ),
			$ctx['org']
		);
		$body = sprintf(
			"Hello %s,\n\nThank you for choosing to sponsor hours of the %s at %s.\n\nWe have reserved the following hours for %d minutes while you complete payment:\n\n%s\n\nMonthly amount: %s AUD\n\nIf payment is not completed in time, the hours will be released for others.\n\n%s\n",
			$first->donor_name !== '' ? $first->donor_name : __( 'there', 'xtra' ),
			$ctx['role'],
			$ctx['org'],
			$minutes,
			self::hours_list( $rows ),
			Xtra_Plugin::format_aud( self::monthly_total( $rows ) ),
			$ctx['org']
		);
		self::send( (string) $first->donor_email, $subject, $body );
	}

	/**
	 * Payment confirmed.
	 *
	 * @param array<int, object> $rows        Rows.
	 * @param string             $portal_url  Customer portal URL.
	 * @return bool Whether wp_mail reported success.
	 */
	public static function payment_confirmed( array $rows, string $portal_url ): bool {
		if ( empty( $rows ) ) {
			return false;
		}
		$first   = $rows[0];
		$ctx     = self::context_from_row( $first );
		$subject = sprintf(
			/* translators: %s organisation name */
			__( 'Your monthly sponsorship is confirmed — %s', 'xtra' ),
			$ctx['org']
		);
		$portal_block = $portal_url !== ''
			? sprintf(
				"To update your payment details or cancel (effective at the end of the calendar month), use this customer portal:\n%s\n\n",
				$portal_url
			)
			: '';
		$body = sprintf(
			"Hello %s,\n\nYour sponsorship is confirmed. You are funding the following hours every week:\n\n%s\n\nMonthly amount: %s AUD\n\n%sThank you for supporting this work.\n\n%s\n",
			$first->donor_name !== '' ? $first->donor_name : __( 'there', 'xtra' ),
			self::hours_list( $rows ),
			Xtra_Plugin::format_aud( self::monthly_total( $rows ) ),
			$portal_block,
			$ctx['org']
		);
		return self::send( (string) $first->donor_email, $subject, $body );
	}

	/**
	 * Cancel scheduled for end of calendar month.
	 *
	 * @param array<int, object> $rows      Rows.
	 * @param string             $cancel_at MySQL datetime.
	 */
	public static function cancel_scheduled( array $rows, string $cancel_at ): void {
		if ( empty( $rows ) ) {
			return;
		}
		$first = $rows[0];
		$ctx   = self::context_from_row( $first );
		$date  = mysql2date( get_option( 'date_format' ), $cancel_at );
		$subject = sprintf(
			/* translators: 1: date, 2: organisation */
			__( 'Your sponsorship will end on %1$s — %2$s', 'xtra' ),
			$date,
			$ctx['org']
		);
		$body = sprintf(
			"Hello %s,\n\nYour monthly sponsorship of the %s at %s has been scheduled to end on %s.\n\nUntil then you continue to fund:\n\n%s\n\nMonthly amount until then: %s AUD\n\nThe hours will become available for others after that date.\n\n%s\n",
			$first->donor_name !== '' ? $first->donor_name : __( 'there', 'xtra' ),
			$ctx['role'],
			$ctx['org'],
			$date,
			self::hours_list( $rows ),
			Xtra_Plugin::format_aud( self::monthly_total( $rows ) ),
			$ctx['org']
		);
		self::send( (string) $first->donor_email, $subject, $body );
	}

	/**
	 * Cells released.
	 *
	 * @param array<int, object> $rows Rows.
	 */
	public static function cell_released( array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}
		$first   = $rows[0];
		$ctx     = self::context_from_row( $first );
		$subject = sprintf(
			/* translators: %s organisation name */
			__( 'Your sponsored hours have been released — %s', 'xtra' ),
			$ctx['org']
		);
		$body = sprintf(
			"Hello %s,\n\nThe following hours of the %s at %s are no longer sponsored and are available again:\n\n%s\n\nThank you for the support you have already given.\n\n%s\n",
			$first->donor_name !== '' ? $first->donor_name : __( 'there', 'xtra' ),
			$ctx['role'],
			$ctx['org'],
			self::hours_list( $rows ),
			$ctx['org']
		);
		self::send( (string) $first->donor_email, $subject, $body );
	}


	/**
	 * Hour-start notice for a single sponsored cell (opt-in only; caller filters).
	 *
	 * @param object $row Sponsorship row.
	 * @return bool Whether wp_mail reported success.
	 */
	public static function hour_start( object $row ): bool {
		$email = isset( $row->donor_email ) ? (string) $row->donor_email : '';
		if ( $email === '' || ! is_email( $email ) ) {
			return false;
		}
		$ctx   = self::context_from_row( $row );
		$dow   = (int) $row->dow;
		$hour  = (int) $row->hour;
		$days  = Xtra_Plugin::day_names();
		$day   = $days[ $dow ] ?? (string) $dow;
		$when  = $day . ' ' . Xtra_Plugin::hour_label( $hour );
		$name  = ( isset( $row->donor_name ) && $row->donor_name !== '' )
			? (string) $row->donor_name
			: __( 'there', 'xtra' );

		$subject = sprintf(
			/* translators: 1: role, 2: organisation */
			__( 'Your sponsored hour is starting — %1$s (%2$s)', 'xtra' ),
			$ctx['role'],
			$ctx['org']
		);
		$body = sprintf(
			"Hello %s,\n\nThe %s hour you sponsor at %s is starting now (%s).\n\nThank you for supporting this work.\n\n%s\n",
			$name,
			$ctx['role'],
			$ctx['org'],
			$when,
			$ctx['org']
		);
		return self::send( $email, $subject, $body );
	}

	/**
	 * Email a single payment receipt.
	 */
	public static function payment_receipt( object $payment ): bool {
		if ( empty( $payment->donor_email ) || ! is_email( (string) $payment->donor_email ) ) {
			return false;
		}
		Xtra_Db::ensure_receipt_number( (int) $payment->id );
		$payment = Xtra_Db::get_payment( (int) $payment->id );
		if ( ! $payment ) {
			return false;
		}
		$issuer  = Xtra_Receipts::issuer_details();
		$subject = sprintf(
			/* translators: 1: receipt number, 2: issuer name */
			__( 'Donation receipt %1$s — %2$s', 'xtra' ),
			(string) $payment->receipt_number,
			$issuer['name'] !== '' ? $issuer['name'] : get_bloginfo( 'name' )
		);
		return self::send(
			(string) $payment->donor_email,
			$subject,
			Xtra_Receipts::payment_receipt_body( $payment )
		);
	}

	/**
	 * Email an annual financial-year donation summary.
	 *
	 * @param array<int, object> $payments Payments in the FY.
	 */
	public static function annual_summary( string $fy, string $donor_name, string $donor_email, array $payments ): bool {
		if ( $donor_email === '' || ! is_email( $donor_email ) || empty( $payments ) ) {
			return false;
		}
		foreach ( $payments as $payment ) {
			Xtra_Db::ensure_receipt_number( (int) $payment->id );
		}
		$issuer  = Xtra_Receipts::issuer_details();
		$subject = sprintf(
			/* translators: 1: financial year, 2: issuer name */
			__( 'Donation summary %1$s — %2$s', 'xtra' ),
			$fy,
			$issuer['name'] !== '' ? $issuer['name'] : get_bloginfo( 'name' )
		);
		return self::send(
			$donor_email,
			$subject,
			Xtra_Receipts::annual_summary_body( $fy, $donor_name, $donor_email, $payments )
		);
	}
}