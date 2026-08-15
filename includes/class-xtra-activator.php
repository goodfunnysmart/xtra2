<?php
/**
 * Activation, deactivation, and demo seed.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle hooks.
 */
class Xtra_Activator {

	/**
	 * Activate: CPT, table, options, demo position and page.
	 */
	public static function activate(): void {
		Xtra_Cpt::register();
		Xtra_Db::create_table();

		if ( false === get_option( Xtra_Plugin::OPTION_KEY, false ) ) {
			add_option( Xtra_Plugin::OPTION_KEY, Xtra_Plugin::defaults() );
		}

		Xtra_Cron::schedule();
		self::maybe_seed_demo();
		flush_rewrite_rules();
	}

	/**
	 * Deactivate: unschedule cron only. Data stays.
	 */
	public static function deactivate(): void {
		Xtra_Cron::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Seed Perkins High School Chaplaincy if the site has no positions yet.
	 */
	public static function maybe_seed_demo(): void {
		$existing = get_posts(
			array(
				'post_type'      => Xtra_Cpt::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$position_id = 0;
		if ( empty( $existing ) ) {
			$position_id = wp_insert_post(
				array(
					'post_type'    => Xtra_Cpt::POST_TYPE,
					'post_title'   => 'Chaplain',
					'post_status'  => 'publish',
					'post_content' => 'The chaplain provides spiritual and emotional support for students at Perkins High School. Sponsoring an hour funds that time every week, so young people have a trusted adult to talk to during the school day.',
				),
				true
			);
			if ( is_wp_error( $position_id ) || ! $position_id ) {
				return;
			}
			$position_id = (int) $position_id;
			update_post_meta( $position_id, Xtra_Cpt::META_ORG, 'Perkins High School Chaplaincy' );
			update_post_meta( $position_id, Xtra_Cpt::META_TARGET, 35 );
			update_post_meta( $position_id, Xtra_Cpt::META_RATE, 4500 );
			update_post_meta(
				$position_id,
				Xtra_Cpt::META_SCHEDULE,
				wp_json_encode( Xtra_Cpt::default_schedule() )
			);
		} else {
			$position_id = (int) $existing[0];
		}

		$found = get_posts(
			array(
				'post_type'      => 'page',
				'title'          => 'Perkins High School Chaplaincy',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( ! empty( $found ) ) {
			return;
		}

		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Perkins High School Chaplaincy',
				'post_status'  => 'publish',
				'post_content' => '[sponsor_position id="' . $position_id . '"]',
			)
		);
	}
}
