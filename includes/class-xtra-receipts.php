<?php
/**
 * Receipt numbers and financial-year summaries (Australian FY: 1 July – 30 June).
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receipt helpers.
 */
class Xtra_Receipts {

	public const RECEIPT_COUNTER_KEY = 'xtra_receipt_counter';

	/**
	 * Australian financial year options from 2025-26 through the current year.
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function financial_year_options(): array {
		$options = array();
		$current = self::current_financial_year();
		$start   = 2025;
		$end     = (int) substr( $current, 0, 4 );

		for ( $year = $start; $year <= $end; ++$year ) {
			$key           = $year . '-' . substr( (string) ( $year + 1 ), -2 );
			$options[ $key ] = self::financial_year_label( $key );
		}

		return $options;
	}

	/**
	 * Current Australian financial year key, e.g. 2025-26.
	 */
	public static function current_financial_year(): string {
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$year = (int) $now->format( 'Y' );
		$month = (int) $now->format( 'n' );
		if ( $month < 7 ) {
			--$year;
		}
		return $year . '-' . substr( (string) ( $year + 1 ), -2 );
	}

	/**
	 * Human label for a financial year key.
	 */
	public static function financial_year_label( string $fy ): string {
		$bounds = self::financial_year_bounds( $fy );
		if ( ! $bounds ) {
			return $fy;
		}
		return sprintf(
			/* translators: 1: FY key, 2: start date, 3: end date */
			__( '%1$s (%2$s – %3$s)', 'xtra' ),
			$fy,
			wp_date( 'j M Y', $bounds['start_ts'] ),
			wp_date( 'j M Y', $bounds['end_ts'] )
		);
	}

	/**
	 * Start/end MySQL datetimes in site timezone for an Australian FY key.
	 *
	 * @return array{start:string, end:string, start_ts:int, end_ts:int}|null
	 */
	public static function financial_year_bounds( string $fy ): ?array {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $fy, $matches ) ) {
			return null;
		}
		$start_year = (int) $matches[1];
		$tz         = wp_timezone();
		$start      = new DateTimeImmutable( $start_year . '-07-01 00:00:00', $tz );
		$end        = new DateTimeImmutable( ( $start_year + 1 ) . '-06-30 23:59:59', $tz );

		return array(
			'start'    => $start->format( 'Y-m-d H:i:s' ),
			'end'      => $end->format( 'Y-m-d H:i:s' ),
			'start_ts' => $start->getTimestamp(),
			'end_ts'   => $end->getTimestamp(),
		);
	}

	/**
	 * Next sequential receipt number.
	 */
	public static function next_receipt_number(): string {
		$counter = (int) get_option( self::RECEIPT_COUNTER_KEY, 0 );
		++$counter;
		update_option( self::RECEIPT_COUNTER_KEY, $counter, false );
		return sprintf( 'XTRA-%06d', $counter );
	}

	/**
	 * Issuer block from settings.
	 *
	 * @return array<string, string>
	 */
	public static function issuer_details(): array {
		$opts = Xtra_Plugin::options();
		return array(
			'name'    => (string) ( $opts['receipt_issuer_name'] ?? '' ),
			'abn'     => (string) ( $opts['receipt_abn'] ?? '' ),
			'address' => (string) ( $opts['receipt_address'] ?? '' ),
			'dgr'     => (string) ( $opts['receipt_dgr_statement'] ?? '' ),
		);
	}

	/**
	 * Plain-text body for a single payment receipt.
	 *
	 * @param object $payment Payment row.
	 */
	public static function payment_receipt_body( object $payment ): string {
		$issuer = self::issuer_details();
		$number = ! empty( $payment->receipt_number )
			? (string) $payment->receipt_number
			: Xtra_Db::ensure_receipt_number( (int) $payment->id );

		$paid_date = mysql2date( get_option( 'date_format' ), (string) $payment->paid_at );
		$hours     = (string) $payment->hour_labels;
		$position  = $payment->position_id ? get_the_title( (int) $payment->position_id ) : '';

		$lines   = array();
		$lines[] = $issuer['name'] !== '' ? $issuer['name'] : get_bloginfo( 'name' );
		if ( $issuer['abn'] !== '' ) {
			$lines[] = sprintf(
				/* translators: %s ABN */
				__( 'ABN: %s', 'xtra' ),
				$issuer['abn']
			);
		}
		if ( $issuer['address'] !== '' ) {
			$lines[] = $issuer['address'];
		}
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s receipt number */
			__( 'Receipt number: %s', 'xtra' ),
			$number
		);
		$lines[] = sprintf(
			/* translators: %s date */
			__( 'Date of payment: %s', 'xtra' ),
			$paid_date
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s donor name */
			__( 'Received from: %s', 'xtra' ),
			(string) $payment->donor_name !== '' ? (string) $payment->donor_name : __( 'Donor', 'xtra' )
		);
		$lines[] = sprintf(
			/* translators: %s amount */
			__( 'Amount received: %s AUD', 'xtra' ),
			Xtra_Plugin::format_aud( (int) $payment->amount_cents )
		);
		$lines[] = '';
		if ( $position !== '' ) {
			$lines[] = sprintf(
				/* translators: %s position title */
				__( 'For: sponsorship of %s', 'xtra' ),
				$position
			);
		}
		if ( $hours !== '' ) {
			$lines[] = $hours;
		}
		$lines[] = '';
		if ( $issuer['dgr'] !== '' ) {
			$lines[] = $issuer['dgr'];
			$lines[] = '';
		}
		$lines[] = __( 'Thank you for your support.', 'xtra' );

		return implode( "\n", $lines );
	}

	/**
	 * Plain-text body for an annual financial-year summary.
	 *
	 * @param array<int, object> $payments Payments in the FY.
	 */
	public static function annual_summary_body( string $fy, string $donor_name, string $donor_email, array $payments ): string {
		$issuer = self::issuer_details();
		$total  = 0;
		$lines  = array();

		$lines[] = $issuer['name'] !== '' ? $issuer['name'] : get_bloginfo( 'name' );
		if ( $issuer['abn'] !== '' ) {
			$lines[] = sprintf( __( 'ABN: %s', 'xtra' ), $issuer['abn'] );
		}
		if ( $issuer['address'] !== '' ) {
			$lines[] = $issuer['address'];
		}
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s financial year key */
			__( 'Annual donation summary — financial year %s', 'xtra' ),
			$fy
		);
		$lines[] = self::financial_year_label( $fy );
		$lines[] = '';
		$lines[] = sprintf( __( 'Donor: %s', 'xtra' ), $donor_name !== '' ? $donor_name : $donor_email );
		if ( $donor_email !== '' ) {
			$lines[] = sprintf( __( 'Email: %s', 'xtra' ), $donor_email );
		}
		$lines[] = '';
		$lines[] = __( 'Payments received:', 'xtra' );
		$lines[] = '';

		foreach ( $payments as $payment ) {
			$total += (int) $payment->amount_cents;
			$number = ! empty( $payment->receipt_number )
				? (string) $payment->receipt_number
				: Xtra_Db::ensure_receipt_number( (int) $payment->id );
			$lines[] = sprintf(
				'- %s · %s · %s',
				mysql2date( get_option( 'date_format' ), (string) $payment->paid_at ),
				Xtra_Plugin::format_aud( (int) $payment->amount_cents ),
				$number
			);
			if ( ! empty( $payment->hour_labels ) ) {
				$lines[] = '  ' . (string) $payment->hour_labels;
			}
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s total amount */
			__( 'Total for financial year %1$s: %2$s AUD', 'xtra' ),
			$fy,
			Xtra_Plugin::format_aud( $total )
		);
		$lines[] = '';
		if ( $issuer['dgr'] !== '' ) {
			$lines[] = $issuer['dgr'];
			$lines[] = '';
		}
		$lines[] = __( 'Please retain this summary for your records.', 'xtra' );

		return implode( "\n", $lines );
	}
}
