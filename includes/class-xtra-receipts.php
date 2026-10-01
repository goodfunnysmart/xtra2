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
	 * Short label for an FY key, e.g. "2026-27" → "FY 2026–27".
	 */
	public static function financial_year_short_label( string $fy ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $fy, $m ) ) {
			return $fy;
		}
		return 'FY ' . $m[1] . "\u{2013}" . $m[2];
	}

	/**
	 * FY key for a start year, e.g. 2026 → "2026-27".
	 */
	public static function financial_year_key( int $start_year ): string {
		return $start_year . '-' . substr( (string) ( $start_year + 1 ), -2 );
	}

	/**
	 * Whether a string is a valid FY key.
	 */
	public static function is_financial_year( string $fy ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}$/', $fy ) && null !== self::financial_year_bounds( $fy );
	}

	/**
	 * FY dropdown options built from the payment data: the current FY plus every
	 * FY that has payments, newest first.
	 *
	 * @param bool $success_only Only FYs with successful payments.
	 * @return array<string, string> Key => label (e.g. "FY 2026–27 (1 Jul 2026 – 30 Jun 2027)").
	 */
	public static function data_financial_year_options( bool $success_only = false ): array {
		$years   = Xtra_Db::payment_fy_start_years( $success_only );
		$years[] = (int) substr( self::current_financial_year(), 0, 4 );
		$years   = array_unique( $years );
		rsort( $years );
		$out = array();
		foreach ( $years as $year ) {
			$key   = self::financial_year_key( (int) $year );
			$b     = self::financial_year_bounds( $key );
			$label = self::financial_year_short_label( $key );
			if ( $b ) {
				$label .= ' (' . wp_date( 'j M Y', $b['start_ts'] ) . " \u{2013} " . wp_date( 'j M Y', $b['end_ts'] ) . ')';
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	/**
	 * Shared branding header (logo, issuer name, ABN, address) for HTML emails.
	 *
	 * @param array<string, mixed> $issuer issuer_details().
	 * @return array<int, string> HTML parts.
	 */
	private static function branding_header_parts( array $issuer ): array {
		$org_name = $issuer['name'] !== '' ? $issuer['name'] : get_bloginfo( 'name' );
		$parts    = array();
		if ( $issuer['logo_url'] !== '' ) {
			$parts[] = sprintf(
				'<p style="margin:0 0 16px;"><img src="%s" alt="%s" style="max-width:220px;height:auto;" /></p>',
				esc_url( $issuer['logo_url'] ),
				esc_attr( $org_name )
			);
		}
		$parts[] = '<p style="margin:0 0 4px;font-size:18px;font-weight:bold;">' . esc_html( $org_name ) . '</p>';
		if ( $issuer['abn'] !== '' ) {
			$parts[] = '<p style="margin:0 0 4px;">' . esc_html( sprintf( __( 'ABN: %s', 'xtra' ), $issuer['abn'] ) ) . '</p>';
		}
		if ( $issuer['address'] !== '' ) {
			$parts[] = '<p style="margin:0 0 16px;white-space:pre-line;">' . esc_html( $issuer['address'] ) . '</p>';
		} else {
			$parts[] = '<p style="margin:0 0 16px;"></p>';
		}
		return $parts;
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
		$opts    = Xtra_Plugin::options();
		$logo_id = absint( $opts['receipt_logo_id'] ?? 0 );
		$logo_url = '';
		if ( $logo_id > 0 ) {
			$url = wp_get_attachment_image_url( $logo_id, 'large' );
			if ( ! $url ) {
				$url = wp_get_attachment_image_url( $logo_id, 'medium' );
			}
			$logo_url = $url ? (string) $url : '';
		}
		return array(
			'name'     => (string) ( $opts['receipt_issuer_name'] ?? '' ),
			'abn'      => (string) ( $opts['receipt_abn'] ?? '' ),
			'address'  => (string) ( $opts['receipt_address'] ?? '' ),
			'dgr'      => (string) ( $opts['receipt_dgr_statement'] ?? '' ),
			'logo_id'  => $logo_id,
			'logo_url' => $logo_url,
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
	 * HTML body for a single payment receipt (email).
	 *
	 * @param object $payment Payment row.
	 */
	public static function payment_receipt_html( object $payment ): string {
		$issuer = self::issuer_details();
		$number = ! empty( $payment->receipt_number )
			? (string) $payment->receipt_number
			: Xtra_Db::ensure_receipt_number( (int) $payment->id );

		$paid_date = mysql2date( get_option( 'date_format' ), (string) $payment->paid_at );
		$hours     = (string) $payment->hour_labels;
		$position  = $payment->position_id ? get_the_title( (int) $payment->position_id ) : '';
		$org_name  = $issuer['name'] !== '' ? $issuer['name'] : get_bloginfo( 'name' );
		$donor     = (string) $payment->donor_name !== '' ? (string) $payment->donor_name : __( 'Donor', 'xtra' );
		$amount    = Xtra_Plugin::format_aud( (int) $payment->amount_cents );

		$parts = array();
		$parts[] = '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222;max-width:640px;margin:0 auto;padding:24px;">';

		$parts   = array_merge( $parts, self::branding_header_parts( $issuer ) );

		$parts[] = '<h2 style="margin:0 0 12px;font-size:17px;">' . esc_html__( 'Donation receipt', 'xtra' ) . '</h2>';
		$parts[] = '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Receipt number:', 'xtra' ) . '</strong> ' . esc_html( $number ) . '</p>';
		$parts[] = '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Date of payment:', 'xtra' ) . '</strong> ' . esc_html( $paid_date ) . '</p>';
		$parts[] = '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Received from:', 'xtra' ) . '</strong> ' . esc_html( $donor ) . '</p>';
		$parts[] = '<p style="margin:0 0 16px;"><strong>' . esc_html__( 'Amount received:', 'xtra' ) . '</strong> ' . esc_html( $amount ) . ' AUD</p>';

		if ( $position !== '' || $hours !== '' ) {
			$parts[] = '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'For:', 'xtra' ) . '</strong></p>';
			if ( $position !== '' ) {
				$parts[] = '<p style="margin:0 0 4px;">' . esc_html( sprintf( __( 'sponsorship of %s', 'xtra' ), $position ) ) . '</p>';
			}
			if ( $hours !== '' ) {
				$parts[] = '<p style="margin:0 0 16px;">' . esc_html( $hours ) . '</p>';
			} else {
				$parts[] = '<p style="margin:0 0 16px;"></p>';
			}
		}

		if ( $issuer['dgr'] !== '' ) {
			$parts[] = '<p style="margin:0 0 16px;white-space:pre-line;">' . esc_html( $issuer['dgr'] ) . '</p>';
		}

		$parts[] = '<p style="margin:0 0 8px;">' . esc_html__( 'Thank you for your support.', 'xtra' ) . '</p>';
		$parts[] = '</body></html>';

		return implode( "\n", $parts );
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
			__( 'Annual donation summary — %s', 'xtra' ),
			self::financial_year_short_label( $fy )
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
			$number = ! empty( $payment->receipt_number ) ? (string) $payment->receipt_number : '';
			$lines[] = sprintf(
				'- %s · %s · %s',
				mysql2date( get_option( 'date_format' ), (string) $payment->paid_at ),
				Xtra_Plugin::format_aud( (int) $payment->amount_cents ),
				$number !== '' ? $number : (string) $payment->stripe_invoice_id
			);
			$for = self::payment_for_label( $payment );
			if ( $for !== '' ) {
				$lines[] = '  ' . $for;
			}
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s total amount */
			__( 'Total for %1$s: %2$s AUD', 'xtra' ),
			self::financial_year_short_label( $fy ),
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

	/**
	 * "Position — hours" label for a payment row.
	 *
	 * @param object $payment Payment row.
	 */
	public static function payment_for_label( object $payment ): string {
		$position = ! empty( $payment->position_id ) ? html_entity_decode( (string) get_the_title( (int) $payment->position_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		$hours    = isset( $payment->hour_labels ) ? (string) $payment->hour_labels : '';
		$bits     = array_filter( array( $position, $hours ), static fn( $v ) => $v !== '' );
		return implode( " \u{2014} ", $bits );
	}

	/**
	 * HTML body for an annual financial-year donation summary (same branding as the tax receipt).
	 * Does not assign receipt numbers (safe for preview); the mailer assigns them before sending.
	 *
	 * @param array<int, object> $payments Successful payments in the FY, oldest first.
	 */
	public static function annual_summary_html( string $fy, string $donor_name, string $donor_email, array $payments ): string {
		$issuer   = self::issuer_details();
		$org_name = $issuer['name'] !== '' ? $issuer['name'] : get_bloginfo( 'name' );
		$donor    = $donor_name !== '' ? $donor_name : $donor_email;
		$bounds   = self::financial_year_bounds( $fy );
		$period   = $bounds ? wp_date( 'j M Y', $bounds['start_ts'] ) . " \u{2013} " . wp_date( 'j M Y', $bounds['end_ts'] ) : '';
		$fy_label = self::financial_year_short_label( $fy );

		$cell = 'padding:6px 8px;border-bottom:1px solid #e2e2e2;text-align:left;vertical-align:top;';
		$head = 'padding:6px 8px;border-bottom:2px solid #222;text-align:left;font-size:13px;';

		$parts   = array();
		$parts[] = '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222;max-width:640px;margin:0 auto;padding:24px;">';
		$parts   = array_merge( $parts, self::branding_header_parts( $issuer ) );

		$parts[] = '<h2 style="margin:0 0 12px;font-size:17px;">' . esc_html( sprintf( /* translators: %s FY label */ __( 'Donation summary %s', 'xtra' ), $fy_label ) ) . '</h2>';
		if ( $period !== '' ) {
			$parts[] = '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Financial year:', 'xtra' ) . '</strong> ' . esc_html( $period ) . '</p>';
		}
		$parts[] = '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Received from:', 'xtra' ) . '</strong> ' . esc_html( $donor ) . '</p>';
		if ( $donor_email !== '' && $donor_email !== $donor ) {
			$parts[] = '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Email:', 'xtra' ) . '</strong> ' . esc_html( $donor_email ) . '</p>';
		}
		$parts[] = '<p style="margin:0 0 16px;"><strong>' . esc_html__( 'Issued:', 'xtra' ) . '</strong> ' . esc_html( wp_date( get_option( 'date_format' ) ) ) . '</p>';

		$parts[] = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0 0 16px;font-size:14px;">';
		$parts[] = '<thead><tr>'
			. '<th style="' . $head . '">' . esc_html__( 'Date', 'xtra' ) . '</th>'
			. '<th style="' . $head . '">' . esc_html__( 'For', 'xtra' ) . '</th>'
			. '<th style="' . $head . '">' . esc_html__( 'Receipt / invoice', 'xtra' ) . '</th>'
			. '<th style="' . $head . 'text-align:right;">' . esc_html__( 'Amount (AUD)', 'xtra' ) . '</th>'
			. '</tr></thead><tbody>';

		$total = 0;
		foreach ( $payments as $payment ) {
			$total  += (int) $payment->amount_cents;
			$number  = ! empty( $payment->receipt_number ) ? (string) $payment->receipt_number : '';
			$invoice = isset( $payment->stripe_invoice_id ) ? (string) $payment->stripe_invoice_id : '';
			$ref     = $number !== '' ? esc_html( $number ) : esc_html__( '(assigned on send)', 'xtra' );
			if ( $invoice !== '' ) {
				$ref .= '<br><span style="color:#666;font-size:12px;">' . esc_html( $invoice ) . '</span>';
			}
			$for = self::payment_for_label( $payment );
			$parts[] = '<tr>'
				. '<td style="' . $cell . 'white-space:nowrap;">' . esc_html( mysql2date( get_option( 'date_format' ), (string) $payment->paid_at ) ) . '</td>'
				. '<td style="' . $cell . '">' . esc_html( $for !== '' ? $for : __( 'Sponsorship', 'xtra' ) ) . '</td>'
				. '<td style="' . $cell . '">' . $ref . '</td>'
				. '<td style="' . $cell . 'text-align:right;white-space:nowrap;">' . esc_html( Xtra_Plugin::format_aud( (int) $payment->amount_cents ) ) . '</td>'
				. '</tr>';
		}

		$parts[] = '</tbody><tfoot><tr>'
			. '<td colspan="3" style="padding:8px;border-top:2px solid #222;font-weight:bold;">' . esc_html( sprintf( /* translators: %s FY label */ __( 'Total for %s', 'xtra' ), $fy_label ) ) . '</td>'
			. '<td style="padding:8px;border-top:2px solid #222;font-weight:bold;text-align:right;white-space:nowrap;">' . esc_html( Xtra_Plugin::format_aud( $total ) ) . '</td>'
			. '</tr></tfoot></table>';

		if ( $issuer['dgr'] !== '' ) {
			$parts[] = '<p style="margin:0 0 16px;white-space:pre-line;">' . esc_html( $issuer['dgr'] ) . '</p>';
		}

		$parts[] = '<p style="margin:0 0 8px;">' . esc_html__( 'Thank you for your support. Please retain this summary for your records.', 'xtra' ) . '</p>';
		$parts[] = '<p style="margin:0 0 8px;color:#666;font-size:13px;">' . esc_html( $org_name ) . '</p>';
		$parts[] = '</body></html>';

		return implode( "\n", $parts );
	}
}
