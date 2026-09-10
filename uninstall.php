<?php
/**
 * Uninstall Xtra.
 *
 * Deletes CPT posts and meta, drops sponsorship and payments tables, removes options,
 * and deletes the seeded demo page if present.
 * Does NOT cancel live Stripe subscriptions. Those keep billing until cancelled in Stripe.
 *
 * @package Xtra
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'xtra_options' );
delete_option( 'xtra_fail_counts' );
delete_option( 'xtra_processed_events' );
delete_option( 'xtra_db_version' );
delete_option( 'xtra_receipt_counter' );

$plugin_dir = dirname( __FILE__ );
$db_file    = $plugin_dir . '/includes/class-xtra-db.php';
if ( file_exists( $db_file ) ) {
	require_once $db_file;
	if ( class_exists( 'Xtra_Db' ) ) {
		Xtra_Db::drop_table();
		Xtra_Db::drop_payments_table();
	}
} else {
	$table = $wpdb->prefix . 'hour_sponsorships';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is constructed from the prefix only.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

	$payments = $wpdb->prefix . 'xtra_payments';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is constructed from the prefix only.
	$wpdb->query( "DROP TABLE IF EXISTS {$payments}" );
}

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

// Seeded demo page only (see Xtra_Activator::maybe_seed_demo).
$demo_pages = get_posts(
	array(
		'post_type'      => 'page',
		'title'          => 'Perkins High School Chaplaincy',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
if ( is_array( $demo_pages ) ) {
	foreach ( $demo_pages as $page_id ) {
		wp_delete_post( (int) $page_id, true );
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
