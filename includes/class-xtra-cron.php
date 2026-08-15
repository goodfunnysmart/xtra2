<?php
/**
 * Hourly maintenance: expire pending, release due cancels.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron hooks.
 */
class Xtra_Cron {

	public const HOOK = 'xtra_hourly_maintenance';

	/**
	 * Register hook listener.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule on activation.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	/**
	 * Unschedule on deactivation.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Hourly job. Grid render also runs these so the UI never waits on Cron.
	 */
	public static function run(): void {
		Xtra_Db::expire_pending( 0 );
		Xtra_Db::release_due_cancels( 0 );
	}
}
