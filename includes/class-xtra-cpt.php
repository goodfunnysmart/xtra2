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
	public const META_VIDEO    = 'video_embed';

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
				'public'          => true,
				'publicly_queryable' => true,
				'show_ui'         => true,
				'show_in_menu'    => 'xtra',
				'show_in_rest'    => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'has_archive'     => false,
				'rewrite'         => array( 'slug' => 'staff-position' ),
				'query_var'       => true,
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
		$days = array( 1, 2, 3, 4, 5, 6, 7 );
		$start = isset( $raw['start'] ) ? (int) $raw['start'] : $default['start'];
		$end   = isset( $raw['end'] ) ? (int) $raw['end'] : $default['end'];
		$start = max( 0, min( 23, $start ) );
		$end   = max( 1, min( 24, $end ) );
		if ( $start >= $end ) {
			$end = min( 24, $start + 1 );
		}

		$show_map = array();
		$paid_map = array();
		if ( isset( $raw['show'] ) && is_array( $raw['show'] ) ) {
			$show_map = $raw['show'];
			$paid_map = isset( $raw['paid'] ) && is_array( $raw['paid'] ) ? $raw['paid'] : array();
		} elseif ( isset( $raw['sponsorable'] ) && is_array( $raw['sponsorable'] ) ) {
			// Migrate legacy sponsorable format
			foreach ( $raw['sponsorable'] as $k => $v ) {
				if ( $v ) {
					$show_map[ $k ] = true;
				}
			}
		} else {
			// Backward compatibility: default Mon-Fri start to end-1 are shown and available.
			for ( $d = 1; $d <= 5; $d++ ) {
				for ( $h = $start; $h < $end; $h++ ) {
					$show_map[ $d . '-' . $h ] = true;
				}
			}
		}

		return array(
			'days' => $days,
			'show' => $show_map,
			'paid' => $paid_map,
		);
	}

	/**
	 * Check if a cell is shown on the public grid.
	 */
	public static function is_shown( array $schedule, int $dow, int $hour ): bool {
		if ( isset( $schedule['show'] ) && is_array( $schedule['show'] ) ) {
			return ! empty( $schedule['show'][ $dow . '-' . $hour ] );
		}
		// Fallback for legacy
		return self::is_sponsorable_legacy( $schedule, $dow, $hour );
	}

	/**
	 * Check if a cell is marked as Paid / covered by outside funding.
	 */
	public static function is_paid( array $schedule, int $dow, int $hour ): bool {
		if ( isset( $schedule['paid'] ) && is_array( $schedule['paid'] ) ) {
			return ! empty( $schedule['paid'][ $dow . '-' . $hour ] );
		}
		return false;
	}

	/**
	 * Legacy sponsorable check.
	 */
	public static function is_sponsorable_legacy( array $schedule, int $dow, int $hour ): bool {
		if ( isset( $schedule['sponsorable'] ) && is_array( $schedule['sponsorable'] ) ) {
			return ! empty( $schedule['sponsorable'][ $dow . '-' . $hour ] );
		}
		$start = isset( $schedule['start'] ) ? (int) $schedule['start'] : 9;
		$end   = isset( $schedule['end'] ) ? (int) $schedule['end'] : 15;
		return $dow >= 1 && $dow <= 5 && $hour >= $start && $hour < $end;
	}

	/**
	 * Hours in schedule.
	 *
	 * @return array<int, int>
	 */
	public static function hours_in_schedule( array $schedule ): array {
		$hours = array();
		$map   = ! empty( $schedule['show'] ) ? $schedule['show'] : ( ! empty( $schedule['sponsorable'] ) ? $schedule['sponsorable'] : array() );
		if ( ! empty( $map ) ) {
			$min_h = 24;
			$max_h = -1;
			foreach ( $map as $key => $val ) {
				if ( $val ) {
					$parts = explode( '-', $key );
					if ( isset( $parts[1] ) ) {
						$h = (int) $parts[1];
						if ( $h < $min_h ) {
							$min_h = $h;
						}
						if ( $h > $max_h ) {
							$max_h = $h;
						}
					}
				}
			}
			if ( $min_h <= $max_h ) {
				for ( $h = $min_h; $h <= $max_h; $h++ ) {
					$hours[] = $h;
				}
				return $hours;
			}
		}
		for ( $h = 9; $h < 15; $h++ ) {
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
			'video_embed'          => (string) get_post_meta( $post_id, self::META_VIDEO, true ),
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
				'<p><strong>%s</strong><br /><code>[sponsor_position id="%d"]</code></p>' .
				'<p><strong>%s</strong><br /><code>[sponsor_timeslot_grid id="%d"]</code></p>' .
				'<p class="description">%s</p>',
				esc_html__( 'Full Component Shortcode:', 'xtra' ),
				(int) $post->ID,
				esc_html__( 'Timeslot Grid Only Shortcode:', 'xtra' ),
				(int) $post->ID,
				esc_html__( 'Paste on any page. The public grid never shows donor names.', 'xtra' )
			);
		} else {
			echo '<p>' . esc_html__( 'Save the position to get shortcodes.', 'xtra' ) . '</p>';
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

		echo '<div class="xtra-metabox">';

		echo '<p><label for="xtra_org_name"><strong>' . esc_html__( 'Organisation name', 'xtra' ) . '</strong></label><br />';
		printf(
			'<input type="text" class="widefat" id="xtra_org_name" name="xtra_org_name" value="%s" />',
			esc_attr( $meta['org_name'] )
		);
		echo '</p>';

		echo '<p><label for="xtra_video_embed"><strong>' . esc_html__( 'Video embed URL (YouTube / Vimeo embed link)', 'xtra' ) . '</strong></label><br />';
		$video_val = $meta['video_embed'];
		if ( str_contains( $video_val, 'watch?v=' ) ) {
			$video_val = str_replace( 'watch?v=', 'embed/', $video_val );
		} elseif ( str_contains( $video_val, 'youtu.be/' ) ) {
			$video_val = str_replace( 'youtu.be/', 'www.youtube.com/embed/', $video_val );
		}
		printf(
			'<input type="url" class="widefat" id="xtra_video_embed" name="xtra_video_embed" value="%s" placeholder="https://www.youtube.com/embed/..." />',
			esc_attr( $video_val )
		);
		echo '<span class="description"> ' . esc_html__( 'Optional video player embed URL displayed below the description and image.', 'xtra' ) . '</span></p>';

		echo '<p><label for="xtra_hourly_monthly_rate"><strong>' . esc_html__( 'Monthly price per weekly hour (AUD)', 'xtra' ) . '</strong></label><br />';
		printf(
			'<input type="number" min="1" step="0.01" id="xtra_hourly_monthly_rate" name="xtra_hourly_monthly_rate" value="%s" />',
			esc_attr( $rate_aud )
		);
		echo '<span class="description"> ' . esc_html__( 'One cell = this amount each month. Two cells = twice this. Never multiplied by 4.33.', 'xtra' ) . '</span></p>';

		echo '<p><label for="xtra_weekly_hour_target"><strong>' . esc_html__( 'Weekly hour target (fundraising goal)', 'xtra' ) . '</strong></label><br />';
		printf(
			'<input type="number" min="1" step="1" id="xtra_weekly_hour_target" name="xtra_weekly_hour_target" value="%s" />',
			esc_attr( (string) $target )
		);
		echo '<span class="description"> ' . esc_html__( 'Shown in the admin list as sponsored hours / this target. Does not change the public grid.', 'xtra' ) . '</span></p>';

		echo '<fieldset class="xtra-schedule"><legend><strong>' . esc_html__( 'Schedule Matrix (Hours & Days)', 'xtra' ) . '</strong></legend>';
		if ( $frozen ) {
			echo '<p class="xtra-frozen-notice">' . esc_html__( 'Note: Hours that currently have active live sponsorships are locked to protect existing subscriptions. You can freely add new hours or days!', 'xtra' ) . '</p>';
		}

		echo '<p><strong>' . esc_html__( 'Schedule Matrix (Tick Show to display on public grid, and Paid if covered by outside funding source)', 'xtra' ) . '</strong></p>';
		echo '<div style="overflow-x:auto; margin-bottom: 1rem;"><table class="widefat" style="width:auto; min-width:700px;"><thead><tr><th>Hour</th>';
		$day_names = Xtra_Plugin::day_names();
		foreach ( $day_names as $num => $label ) {
			echo '<th style="text-align:center;">' . esc_html( $label ) . '<br /><span style="font-size:10px; font-weight:normal; color:#666;">Show | Paid</span></th>';
		}
		echo '</tr></thead><tbody>';

		$start_all = 6;
		$end_all   = 22;
		$sched_arr = $meta['schedule'];
		for ( $h = $start_all; $h < $end_all; $h++ ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( Xtra_Plugin::hour_label( $h ) ) . '</strong></td>';
			for ( $d = 1; $d <= 7; $d++ ) {
				$is_shown = self::is_shown( $sched_arr, $d, $h );
				$is_paid  = self::is_paid( $sched_arr, $d, $h );

				// Check if this specific cell has a live sponsorship (excluding pending)
				$cell_row = Xtra_Db::live_row_for_cell( (int) $post->ID, $d, $h );
				if ( $cell_row && $cell_row->status === 'pending' ) {
					$cell_row = null;
				}
				$cell_disabled = $cell_row ? ' disabled="disabled"' : '';

				$effective_paid = $is_paid || $cell_row;
				$chk_show = $is_shown ? ' checked="checked"' : '';
				$chk_paid = $effective_paid ? ' checked="checked"' : '';
				
				$title_parts = array();
				if ( $cell_row && ! empty( $cell_row->donor_name ) ) {
					$title_parts[] = "Sponsored by '" . $cell_row->donor_name . "'";
				} elseif ( $cell_row ) {
					$title_parts[] = 'Sponsored via website';
				}
				if ( $is_paid ) {
					$title_parts[] = 'Covered by Outside Funding';
				}
				if ( empty( $title_parts ) ) {
					$title_parts[] = 'Covered by Outside Funding';
				}
				$title_attr = implode( ' | ', $title_parts );
				$cell_note = ' title="' . esc_attr( $title_attr ) . '"';

				printf(
					'<td style="text-align:center; white-space:nowrap; %8$s"%7$s><label title="Show on grid"><input type="checkbox" name="xtra_show[%1$d_%2$d]" value="1"%3$s%4$s /></label> &nbsp; <label title="%9$s"><input type="checkbox" name="xtra_paid[%1$d_%2$d]" value="1"%5$s%4$s style="accent-color: #28a745;" /></label>%6$s</td>',
					$d,
					$h,
					$chk_show,
					$cell_disabled,
					$chk_paid,
					$cell_row ? '<input type="hidden" name="xtra_show[' . $d . '_' . $h . ']" value="1" /><input type="hidden" name="xtra_paid[' . $d . '_' . $h . ']" value="1" />' : '',
					$cell_note,
					( $is_paid || $cell_row ) ? 'background-color: #e6f4ea;' : '',
					esc_attr( $title_attr )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="description">' . esc_html__( 'Slot length is one hour. Public grid hours automatically wrap around your shown hours.', 'xtra' ) . '</p>';
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

		$video = isset( $_POST['xtra_video_embed'] ) ? esc_url_raw( wp_unslash( $_POST['xtra_video_embed'] ) ) : '';
		update_post_meta( $post_id, self::META_VIDEO, $video );

		$aud = isset( $_POST['xtra_hourly_monthly_rate'] ) ? (float) wp_unslash( $_POST['xtra_hourly_monthly_rate'] ) : 0;
		$cents = (int) round( $aud * 100 );
		if ( $cents < 1 ) {
			$cents = 1;
		}
		update_post_meta( $post_id, self::META_RATE, $cents );

		$target = isset( $_POST['xtra_weekly_hour_target'] ) ? (int) wp_unslash( $_POST['xtra_weekly_hour_target'] ) : 0;
		if ( $target < 1 ) {
			$target = 1;
		}
		update_post_meta( $post_id, self::META_TARGET, $target );

		$old_meta = self::get_meta( $post_id );
		$old_schedule = $old_meta['schedule'];

		$days = array( 1, 2, 3, 4, 5, 6, 7 );
		if ( isset( $_POST['xtra_schedule_days'] ) && is_array( $_POST['xtra_schedule_days'] ) ) {
			$days = array();
			foreach ( $_POST['xtra_schedule_days'] as $d ) {
				$d = (int) $d;
				if ( $d >= 1 && $d <= 7 ) {
					$days[] = $d;
				}
			}
		}

		$show_post = isset( $_POST['xtra_show'] ) && is_array( $_POST['xtra_show'] ) ? $_POST['xtra_show'] : array();
		$paid_post = isset( $_POST['xtra_paid'] ) && is_array( $_POST['xtra_paid'] ) ? $_POST['xtra_paid'] : array();
		
		$show_map = array();
		$paid_map = array();
		for ( $h = 6; $h < 22; $h++ ) {
			for ( $d = 1; $d <= 7; $d++ ) {
				$key = $d . '_' . $h;
				$cell_str = $d . '-' . $h;

				// If this cell has a live sponsorship (excluding pending)
				$has_live = Xtra_Db::live_row_for_cell( $post_id, $d, $h );
				if ( $has_live && $has_live->status === 'pending' ) {
					$has_live = null;
				}
				if ( $has_live ) {
					$show_map[ $cell_str ] = true;
					$paid_map[ $cell_str ] = isset( $old_schedule['paid'][ $cell_str ] ) ? $old_schedule['paid'][ $cell_str ] : true;
					continue;
				}

				if ( ! empty( $show_post[ $key ] ) ) {
					$show_map[ $cell_str ] = true;
				}
				if ( ! empty( $paid_post[ $key ] ) ) {
					$paid_map[ $cell_str ] = true;
				}
			}
		}

		$schedule = array(
			'days' => $days,
			'show' => $show_map,
			'paid' => $paid_map,
		);
		update_post_meta( $post_id, self::META_SCHEDULE, wp_json_encode( $schedule ) );
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
				$new['xtra_org']            = __( 'Organisation', 'xtra' );
				$new['xtra_rate']           = __( 'Rate / hour', 'xtra' );
				$new['xtra_progress']       = __( 'Sponsored', 'xtra' );
				$new['xtra_shortcode']      = __( 'Shortcode (Full)', 'xtra' );
				$new['xtra_grid_shortcode'] = __( 'Shortcode (Grid)', 'xtra' );
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
			case 'xtra_grid_shortcode':
				printf( '<code>[sponsor_timeslot_grid id="%d"]</code>', $post_id );
				break;
		}
	}
}
