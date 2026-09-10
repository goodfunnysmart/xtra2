<?php
/**
 * WP-Cron: hourly maintenance + 5-minute hour-start emails.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron hooks.
 */
class Xtra_Cron {

	public const HOOK = 'xtra_hourly_maintenance';

	public const HOOK_HOUR_START = 'xtra_hour_start_emails';

	/**
	 * Register hook listeners and custom schedules.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::HOOK_HOUR_START, array( __CLASS__, 'run_hour_start_emails' ) );
		// Ensure both events exist after plugin updates (idempotent).
		self::schedule();
	}

	/**
	 * Register a 5-minute interval for hour-start emails.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['xtra_five_minutes'] ) ) {
			$schedules['xtra_five_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 minutes (Xtra hour-start emails)', 'xtra' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule on activation.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
		if ( ! wp_next_scheduled( self::HOOK_HOUR_START ) ) {
			wp_schedule_event( time() + 60, 'xtra_five_minutes', self::HOOK_HOUR_START );
		}
	}

	/**
	 * Unschedule on deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK_HOUR_START );
	}

	/**
	 * Hourly job. Grid render also runs these so the UI never waits on Cron.
	 */
	public static function run(): void {
		Xtra_Db::expire_pending( 0 );
		Xtra_Db::release_due_cancels( 0 );
	}

	/**
	 * Send hour-start emails in the first 10 minutes of the matching hour.
	 */
	public static function run_hour_start_emails(): void {
		$minute = (int) wp_date( 'i' );
		if ( $minute < 0 || $minute > 9 ) {
			return;
		}

		$dow  = (int) wp_date( 'N' ); // 1=Mon … 7=Sun
		$hour = (int) wp_date( 'G' ); // 0–23
		$key_hour = wp_date( 'YmdH' );

		$rows = Xtra_Db::rows_for_hour_start( $dow, $hour );
		foreach ( $rows as $row ) {
			$row_id = (int) $row->id;
			$tkey   = 'xtra_hour_start_' . $row_id . '_' . $key_hour;
			if ( get_transient( $tkey ) ) {
				continue;
			}
			$ok = Xtra_Mail::hour_start( $row );
			if ( $ok ) {
				set_transient( $tkey, 1, 2 * HOUR_IN_SECONDS );
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'Xtra hour_start mail failed for row %d', $row_id ) );
			}
		}
	}
}
