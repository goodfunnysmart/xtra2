<?php
/**
 * Staff position custom post type and metaboxes.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * CPT staff_position.
 */
class Xtra_Cpt {

	public const POST_TYPE = 'staff_position';

	public const META_ORG      = 'org_name';
	public const META_TARGET   = 'weekly_hour_target';
	public const META_RATE     = 'hourly_monthly_rate';
	public const META_SCHEDULE = 'schedule';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metaboxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	/**
	 * Register the CPT. Shown in admin as Positions.
	 */
	public static function register(): void {
		$labels = array(
			'name'               => __( 'Positions', 'xtra' ),
			'singular_name'      => __( 'Position', 'xtra' ),
			'add_new'            => __( 'Add Position', 'xtra' ),
			'add_new_item'       => __( 'Add Position', 'xtra' ),
			'edit_item'          => __( 'Edit Position', 'xtra' ),
			'new_item'           => __( 'New Position', 'xtra' ),
			'view_item'          => __( 'View Position', 'xtra' ),
			'search_items'       => __( 'Search Positions', 'xtra' ),
			'not_found'          => __( 'No positions found.', 'xtra' ),
			'not_found_in_trash' => __( 'No positions found in Trash.', 'xtra' ),
			'all_items'          => __( 'Positions', 'xtra' ),
			'menu_name'          => __( 'Positions', 'xtra' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'xtra',
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/**
	 * Default Mon–Fri 9–15 schedule.
	 *
	 * @return array{days: array<int, int>, start: int, end: int}
	 */
	public static function default_schedule(): array {
		return array(
			'days'  => array( 1, 2, 3, 4, 5 ),
			'start' => 9,
			'end'   => 15,
		);
	}

	/**
	 * Normalise schedule JSON/array.
	 *
	 * @param mixed $raw Raw meta.
	 * @return array{days: array<int, int>, start: int, end: int}
	 */
	public static function parse_schedule( $raw ): array {
		$default = self::default_schedule();
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		if ( ! is_array( $raw ) ) {
			return $default;
		}
		$days = array();
		if ( isset( $raw['days'] ) && is_array( $raw['days'] ) ) {
			foreach ( $raw['days'] as $d ) {
				$d = (int) $d;
				if ( $d >= 1 && $d <= 5 ) {
					$days[] = $d;
				}
			}
		}
		$days  = array_values( array_unique( $days ) );
		$start = isset( $raw['start'] ) ? (int) $raw['start'] : $default['start'];
		$end   = isset( $raw['end'] ) ? (int) $raw['end'] : $default['end'];
		$start = max( 0, min( 23, $start ) );
		$end   = max( 1, min( 24, $end ) );
		if ( $start >= $end ) {
			$end = min( 24, $start + 1 );
		}
		if ( empty( $days ) ) {
			$days = $default['days'];
		}
		return array(
			'days'  => $days,
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Hours in [start, end).
	 *
	 * @return array<int, int>
	 */
	public static function hours_in_schedule( array $schedule ): array {
		$hours = array();
		for ( $h = (int) $schedule['start']; $h < (int) $schedule['end']; $h++ ) {
			$hours[] = $h;
		}
		return $hours;
	}

	/**
	 * Position meta bundle.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_meta( int $post_id ): array {
		$rate = (int) get_post_meta( $post_id, self::META_RATE, true );
		return array(
			'org_name'             => (string) get_post_meta( $post_id, self::META_ORG, true ),
			'weekly_hour_target'   => (int) get_post_meta( $post_id, self::META_TARGET, true ),
			'hourly_monthly_rate'  => $rate,
			'schedule'             => self::parse_schedule( get_post_meta( $post_id, self::META_SCHEDULE, true ) ),
		);
	}

	/**
	 * Metaboxes on the position editor.
	 */
	public static function metaboxes(): void {
		add_meta_box(
			'xtra_position_details',
			__( 'Sponsorship details', 'xtra' ),
			array( __CLASS__, 'render_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'xtra_position_shortcode',
			__( 'Shortcode', 'xtra' ),
			array( __CLASS__, 'render_shortcode_metabox' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Shortcode copy box.
	 */
	public static function render_shortcode_metabox( WP_Post $post ): void {
		if ( $post->ID ) {
			printf(
				'<p><code>[sponsor_position id="%d"]</code></p><p class="description">%s</p>',
				(int) $post->ID,
				esc_html__( 'Paste this on any page. The public grid never shows donor names.', 'xtra' )
			);
		} else {
			echo '<p>' . esc_html__( 'Save the position to get a shortcode.', 'xtra' ) . '</p>';
		}
	}

	/**
	 * Main editor fields.
	 */
	public static function render_metabox( WP_Post $post ): void {
		wp_nonce_field( 'xtra_position_meta', 'xtra_position_meta_nonce' );

		$meta     = self::get_meta( (int) $post->ID );
		$frozen   = $post->ID && Xtra_Db::has_live_sponsorships( (int) $post->ID );
		$rate_aud = $meta['hourly_monthly_rate'] > 0 ? number_format( $meta['hourly_monthly_rate'] / 100, 2, '.', '' ) : '45.00';
		$target   = $meta['weekly_hour_target'] > 0 ? (int) $meta['weekly_hour_target'] : 35;
		$start    = (int) $meta['schedule']['start'];
		$end      = (int) $meta['schedule']['end'];

		echo '<div class="xtra-metabox">';

		echo '<p><label for="xtra_org_name"><strong>' . esc_html__( 'Organisation name', 'xtra' ) . '</strong></label><br />';
		printf(
			'<input type="text" class="widefat" id="xtra_org_name" name="xtra_org_name" value="%s" />',
			esc_attr( $meta['org_name'] )
		);
		echo '</p>';

		echo '<p><label for="xtra_weekly_hour_target"><strong>' . esc_html__( 'Weekly hour target', 'xtra' ) . '</strong></label><br />';
		printf(
			'<input type="number" min="1" max="168" id="xtra_weekly_hour_target" name="xtra_weekly_hour_target" value="%d" />',
			$target
		);
		echo '<span class="description"> ' . esc_html__( 'Denominator for “X of 35 hours sponsored”.', 'xtra' ) . '</span></p>';

		echo '<p><label for="xtra_hourly_monthly_rate"><strong>' . esc_html__( 'Monthly price per weekly hour (AUD)', 'xtra' ) . '</strong></label><br />';
		printf(
			'<input type="number" min="1" step="0.01" id="xtra_hourly_monthly_rate" name="xtra_hourly_monthly_rate" value="%s" />',
			esc_attr( $rate_aud )
		);
		echo '<span class="description"> ' . esc_html__( 'One cell = this amount each month. Two cells = twice this. Never multiplied by 4.33.', 'xtra' ) . '</span></p>';

		echo '<fieldset class="xtra-schedule"><legend><strong>' . esc_html__( 'Schedule (Monday–Friday, one-hour cells)', 'xtra' ) . '</strong></legend>';
		if ( $frozen ) {
			echo '<p class="xtra-frozen-notice">' . esc_html__( 'The schedule is frozen because at least one live sponsorship exists. Changing hours under active subscriptions is not supported.', 'xtra' ) . '</p>';
		}
		$disabled = $frozen ? ' disabled="disabled"' : '';
		echo '<p><label for="xtra_schedule_start">' . esc_html__( 'Start hour', 'xtra' ) . '</label> ';
		echo '<select id="xtra_schedule_start" name="xtra_schedule_start"' . $disabled . '>';
		for ( $h = 0; $h <= 23; $h++ ) {
			printf(
				'<option value="%d"%s>%s</option>',
				$h,
				selected( $start, $h, false ),
				esc_html( Xtra_Plugin::hour_label( $h ) )
			);
		}
		echo '</select></p>';
		echo '<p><label for="xtra_schedule_end">' . esc_html__( 'End hour (exclusive)', 'xtra' ) . '</label> ';
		echo '<select id="xtra_schedule_end" name="xtra_schedule_end"' . $disabled . '>';
		for ( $h = 1; $h <= 24; $h++ ) {
			printf(
				'<option value="%d"%s>%s</option>',
				$h,
				selected( $end, $h, false ),
				esc_html( $h === 24 ? __( '24:00 (midnight)', 'xtra' ) : Xtra_Plugin::hour_label( $h ) )
			);
		}
		echo '</select>';
		echo '<span class="description"> ' . esc_html__( '9:00 to 15:00 produces cells 9, 10, 11, 12, 13 and 14.', 'xtra' ) . '</span></p>';
		echo '<p class="description">' . esc_html__( 'Days are Monday to Friday. Slot length is one hour.', 'xtra' ) . '</p>';
		echo '</fieldset>';

		echo '</div>';
	}

	/**
	 * Persist metabox fields.
	 */
	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['xtra_position_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['xtra_position_meta_nonce'] ) ), 'xtra_position_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $post->post_type !== self::POST_TYPE ) {
			return;
		}

		$org = isset( $_POST['xtra_org_name'] ) ? sanitize_text_field( wp_unslash( $_POST['xtra_org_name'] ) ) : '';
		update_post_meta( $post_id, self::META_ORG, $org );

		$target = isset( $_POST['xtra_weekly_hour_target'] ) ? absint( $_POST['xtra_weekly_hour_target'] ) : 0;
		if ( $target < 1 ) {
			$target = 1;
		}
		update_post_meta( $post_id, self::META_TARGET, $target );

		$aud = isset( $_POST['xtra_hourly_monthly_rate'] ) ? (float) wp_unslash( $_POST['xtra_hourly_monthly_rate'] ) : 0;
		$cents = (int) round( $aud * 100 );
		if ( $cents < 1 ) {
			$cents = 1;
		}
		update_post_meta( $post_id, self::META_RATE, $cents );

		if ( ! Xtra_Db::has_live_sponsorships( $post_id ) ) {
			$start = isset( $_POST['xtra_schedule_start'] ) ? absint( $_POST['xtra_schedule_start'] ) : 9;
			$end   = isset( $_POST['xtra_schedule_end'] ) ? absint( $_POST['xtra_schedule_end'] ) : 15;
			$schedule = self::parse_schedule(
				array(
					'days'  => array( 1, 2, 3, 4, 5 ),
					'start' => $start,
					'end'   => $end,
				)
			);
			update_post_meta( $post_id, self::META_SCHEDULE, wp_json_encode( $schedule ) );
		}
	}

	/**
	 * Admin list columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['xtra_org']      = __( 'Organisation', 'xtra' );
				$new['xtra_rate']     = __( 'Rate / hour', 'xtra' );
				$new['xtra_progress'] = __( 'Sponsored', 'xtra' );
				$new['xtra_shortcode'] = __( 'Shortcode', 'xtra' );
			}
		}
		return $new;
	}

	/**
	 * Admin list column values.
	 */
	public static function column_content( string $column, int $post_id ): void {
		$meta = self::get_meta( $post_id );
		switch ( $column ) {
			case 'xtra_org':
				echo esc_html( $meta['org_name'] );
				break;
			case 'xtra_rate':
				echo esc_html( Xtra_Plugin::format_aud( (int) $meta['hourly_monthly_rate'] ) . '/mo' );
				break;
			case 'xtra_progress':
				$have = Xtra_Db::sponsored_count( $post_id );
				$want = max( 1, (int) $meta['weekly_hour_target'] );
				echo esc_html( $have . ' / ' . $want );
				break;
			case 'xtra_shortcode':
				printf( '<code>[sponsor_position id="%d"]</code>', $post_id );
				break;
		}
	}
}
