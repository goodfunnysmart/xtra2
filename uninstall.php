<?php
/**
 * Uninstall Xtra.
 *
 * Deletes CPT posts and meta, drops the sponsorships table, and removes options.
 * Does NOT cancel live Stripe subscriptions. Those keep billing until cancelled in Stripe.
 *
 * @package Xtra
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'xtra_options' );
delete_option( 'xtra_fail_counts' );
delete_option( 'xtra_processed_events' );

$table = $wpdb->prefix . 'hour_sponsorships';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is constructed from the prefix only.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

$ids = get_posts(
	array(
		'post_type'      => 'staff_position',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

if ( is_array( $ids ) ) {
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

wp_clear_scheduled_hook( 'xtra_hourly_maintenance' );

$lock_like = $wpdb->esc_like( '_transient_xtra_lock_' ) . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$lock_like,
		$wpdb->esc_like( '_transient_timeout_xtra_lock_' ) . '%'
	)
);
