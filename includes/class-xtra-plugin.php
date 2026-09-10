<?php
/**
 * Core plugin container and options.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton bootstrap.
 */
final class Xtra_Plugin {

	public const OPTION_KEY       = 'xtra_options';
	public const FAIL_COUNTS_KEY  = 'xtra_fail_counts';
	public const PROCESSED_EVENTS = 'xtra_processed_events';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Wire hooks.
	 */
	public function init(): void {
		Xtra_Db::maybe_upgrade();

		load_plugin_textdomain( 'xtra', false, dirname( plugin_basename( XTRA_FILE ) ) . '/languages' );

		Xtra_Cpt::init();
		Xtra_Admin::init();
		Xtra_Public::init();
		Xtra_Rest::init();
		Xtra_Cron::init();
	}

	/**
	 * Default option values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'stripe_pk'             => '',
			'stripe_sk'             => '',
			'stripe_webhook_secret' => '',
			'from_name'             => get_bloginfo( 'name' ),
			'from_email'            => (string) get_option( 'admin_email' ),
			'pending_minutes'       => 15,
			'terms_url'             => '',
			'privacy_url'           => '',
			'receipt_issuer_name'   => get_bloginfo( 'name' ),
			'receipt_abn'           => '',
			'receipt_address'       => '',
			'receipt_dgr_statement' => '',
		);
	}

	/**
	 * Merged saved options.
	 *
	 * @return array<string, mixed>
	 */
	public static function options(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Persist a subset of options.
	 *
	 * @param array<string, mixed> $values Values to merge.
	 */
	public static function update_options( array $values ): void {
		$current = self::options();
		update_option( self::OPTION_KEY, array_merge( $current, $values ) );
	}

	/**
	 * Whether both publishable and secret keys are present.
	 */
	public static function stripe_configured(): bool {
		$o = self::options();
		return ( $o['stripe_pk'] !== '' && $o['stripe_sk'] !== '' );
	}

	/**
	 * Pending lock window in minutes.
	 */
	public static function pending_minutes(): int {
		$minutes = (int) self::options()['pending_minutes'];
		if ( $minutes < 5 ) {
			$minutes = 5;
		}
		if ( $minutes > 120 ) {
			$minutes = 120;
		}
		return $minutes;
	}

	/**
	 * Format cents as AUD display (no currency code).
	 */
	public static function format_aud( int $cents ): string {
		return '$' . number_format( $cents / 100, 2, '.', ',' );
	}

	/**
	 * Weekday short labels, 1 = Monday.
	 *
	 * @return array<int, string>
	 */
	public static function day_labels(): array {
		return array(
			1 => __( 'Mon', 'xtra' ),
			2 => __( 'Tue', 'xtra' ),
			3 => __( 'Wed', 'xtra' ),
			4 => __( 'Thu', 'xtra' ),
			5 => __( 'Fri', 'xtra' ),
			6 => __( 'Sat', 'xtra' ),
			7 => __( 'Sun', 'xtra' ),
		);
	}

	/**
	 * Full weekday names, 1 = Monday.
	 *
	 * @return array<int, string>
	 */
	public static function day_names(): array {
		return array(
			1 => __( 'Monday', 'xtra' ),
			2 => __( 'Tuesday', 'xtra' ),
			3 => __( 'Wednesday', 'xtra' ),
			4 => __( 'Thursday', 'xtra' ),
			5 => __( 'Friday', 'xtra' ),
			6 => __( 'Saturday', 'xtra' ),
			7 => __( 'Sunday', 'xtra' ),
		);
	}

	/**
	 * Hour label, e.g. 9 → "9:00".
	 */
	public static function hour_label( int $hour ): string {
		return sprintf( '%d:00', $hour );
	}

	/**
	 * Cell label, e.g. "Tue 9:00".
	 */
	public static function cell_label( int $dow, int $hour ): string {
		$days = self::day_labels();
		$day  = $days[ $dow ] ?? (string) $dow;
		return $day . ' ' . self::hour_label( $hour );
	}

	/**
	 * End of current calendar month in the WordPress timezone.
	 * If that instant is under an hour away, use the end of next month.
	 */
	public static function end_of_cancel_month(): DateTimeImmutable {
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$end = $now->modify( 'last day of this month' )->setTime( 23, 59, 59 );
		if ( $end->getTimestamp() <= $now->getTimestamp() + 3600 ) {
			$end = $now->modify( 'last day of next month' )->setTime( 23, 59, 59 );
		}
		return $end;
	}

	/**
	 * MySQL datetime in WP local time.
	 */
	public static function now_mysql(): string {
		return current_time( 'mysql' );
	}
}
