<?php
/**
 * Public shortcode, assets, and grid markup.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end.
 */
class Xtra_Public {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_shortcode( 'sponsor_position', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register (not always enqueue) front-end assets.
	 */
	public static function register_assets(): void {
		wp_register_style(
			'xtra-public',
			XTRA_URL . 'assets/css/xtra-public.css',
			array(),
			XTRA_VERSION
		);
		wp_register_script(
			'xtra-public',
			XTRA_URL . 'assets/js/xtra-public.js',
			array(),
			XTRA_VERSION,
			true
		);
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, 'sponsor_position' ) ) {
			wp_enqueue_style( 'xtra-public' );
			wp_enqueue_script( 'xtra-public' );
		}
	}

	/**
	 * [sponsor_position id="123"]
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'sponsor_position'
		);
		$position_id = absint( $atts['id'] );
		if ( $position_id < 1 || get_post_type( $position_id ) !== Xtra_Cpt::POST_TYPE ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="xtra-error">' . esc_html__( 'Xtra: that position was not found. Check the shortcode id.', 'xtra' ) . '</p>';
			}
			return '';
		}

		$post = get_post( $position_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="xtra-error">' . esc_html__( 'Xtra: this position is not published.', 'xtra' ) . '</p>';
			}
			return '';
		}

		wp_enqueue_style( 'xtra-public' );
		wp_enqueue_script( 'xtra-public' );

		// After Stripe Checkout, confirm payment here so the grid updates even if the webhook is delayed or unreachable (e.g. local dev).
		if ( isset( $_GET['xtra_success'], $_GET['session_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$stripe_session = sanitize_text_field( wp_unslash( (string) $_GET['session_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			Xtra_Stripe::confirm_checkout_session( $stripe_session );
		}

		$meta      = Xtra_Cpt::get_meta( $position_id );
		$schedule  = $meta['schedule'];
		$hours     = Xtra_Cpt::hours_in_schedule( $schedule );
		$days      = $schedule['days'];
		$rate      = (int) $meta['hourly_monthly_rate'];
		$target    = max( 0, (int) $meta['weekly_hour_target'] );
		$live      = Xtra_Db::live_cell_map( $position_id );
		$sponsored = 0;
		foreach ( $live as $row ) {
			if ( in_array( $row->status, array( 'sponsored', 'cancelling' ), true ) ) {
				++$sponsored;
			}
		}
		$pct = $target > 0 ? min( 100, (int) round( ( $sponsored / $target ) * 100 ) ) : 0;

		$opts     = Xtra_Plugin::options();
		$configured = Xtra_Plugin::stripe_configured();

		wp_localize_script(
			'xtra-public',
			'xtraPublic',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'xtra/v1/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'positionId'   => $position_id,
				'rateCents'    => $rate,
				'rateLabel'    => Xtra_Plugin::format_aud( $rate ),
				'configured'   => $configured,
				'termsUrl'     => (string) $opts['terms_url'],
				'returnUrl'    => esc_url_raw( get_permalink() ? get_permalink() : home_url( '/' ) ),
				'i18n'         => array(
					'continue'     => __( 'Continue', 'xtra' ),
					'pay'          => __( 'Pay', 'xtra' ),
					'hours'        => __( 'hours', 'xtra' ),
					'hour'         => __( 'hour', 'xtra' ),
					'monthly'      => __( 'Monthly total', 'xtra' ),
					'selectPrompt' => __( 'Select hours on the grid to sponsor them every week.', 'xtra' ),
					'notConfigured'=> __( 'Payments are not configured yet. You can view and select hours, but checkout is unavailable.', 'xtra' ),
					'error'        => __( 'Something went wrong. Please try again.', 'xtra' ),
					'back'         => __( 'Back to the grid', 'xtra' ),
				),
			)
		);

		ob_start();
		self::render( $post, $meta, $days, $hours, $live, $sponsored, $target, $pct, $rate, $configured, $opts );
		return (string) ob_get_clean();
	}

	/**
	 * Markup.
	 *
	 * @param WP_Post              $post       Position.
	 * @param array<string, mixed> $meta       Meta.
	 * @param array<int, int>      $days       DOW list.
	 * @param array<int, int>      $hours      Hours.
	 * @param array<string, object> $live      Live cell map.
	 * @param int                  $sponsored  Sponsored count.
	 * @param int                  $target     Target.
	 * @param int                  $pct        Percent.
	 * @param int                  $rate       Cents.
	 * @param bool                 $configured Stripe ready.
	 * @param array<string, mixed> $opts       Options.
	 */
	private static function render( WP_Post $post, array $meta, array $days, array $hours, array $live, int $sponsored, int $target, int $pct, int $rate, bool $configured, array $opts ): void {
		$day_labels = Xtra_Plugin::day_labels();
		$org        = (string) $meta['org_name'];
		$thumb      = get_the_post_thumbnail( $post, 'large', array( 'class' => 'xtra-photo' ) );

		$success   = isset( $_GET['xtra_success'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cancelled = isset( $_GET['xtra_cancelled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$permalink = get_permalink();
		echo '<div class="xtra"';
		echo ' data-position="' . esc_attr( (string) $post->ID ) . '"';
		echo ' data-rest-url="' . esc_url( rest_url( 'xtra/v1/' ) ) . '"';
		echo ' data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"';
		echo ' data-rate="' . esc_attr( (string) $rate ) . '"';
		echo ' data-configured="' . ( $configured ? '1' : '0' ) . '"';
		echo ' data-return-url="' . esc_url( $permalink ? $permalink : home_url( '/' ) ) . '"';
		echo ' data-terms-url="' . esc_url( (string) $opts['terms_url'] ) . '"';
		echo '>';

		if ( $success ) {
			echo '<div class="xtra-banner xtra-banner-ok" role="status">';
			echo '<p><strong>' . esc_html__( 'Thank you.', 'xtra' ) . '</strong> ';
			echo esc_html__( 'Thank you for sponsoring. Your hours are shown as sponsored on the grid below. You will receive a confirmation email shortly.', 'xtra' );
			echo '</p></div>';
		} elseif ( $cancelled ) {
			echo '<div class="xtra-banner xtra-banner-info" role="status">';
			echo '<p>' . esc_html__( 'Checkout was cancelled. Your reserved hours will be released shortly if you do not complete payment.', 'xtra' ) . '</p>';
			echo '</div>';
		}

		if ( ! $configured ) {
			echo '<div class="xtra-banner xtra-banner-warn" role="status">';
			echo '<p>' . esc_html__( 'Payments are not configured yet. You can view and select hours, but checkout is unavailable.', 'xtra' ) . '</p>';
			echo '</div>';
		}

		echo '<header class="xtra-head">';
		if ( $thumb ) {
			echo '<div class="xtra-head-photo">' . $thumb . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp thumbnail.
		}
		echo '<div class="xtra-head-copy">';
		if ( $org !== '' ) {
			echo '<p class="xtra-org">' . esc_html( $org ) . '</p>';
		}
		echo '<h2 class="xtra-title">' . esc_html( get_the_title( $post ) ) . '</h2>';
		$content = apply_filters( 'the_content', $post->post_content );
		if ( $content !== '' ) {
			echo '<div class="xtra-desc">' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></header>';

		echo '<div class="xtra-progress" aria-label="' . esc_attr__( 'Sponsorship progress', 'xtra' ) . '">';
		echo '<p class="xtra-progress-label">';
		printf(
			/* translators: 1: sponsored hours, 2: target */
			esc_html__( '%1$s of %2$s hours sponsored', 'xtra' ),
			esc_html( (string) $sponsored ),
			esc_html( (string) $target )
		);
		echo '</p>';
		echo '<div class="xtra-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $pct ) . '">';
		echo '<div class="xtra-progress-fill" style="width:' . esc_attr( (string) $pct ) . '%"></div>';
		echo '</div></div>';

		echo '<div class="xtra-layout">';
		echo '<div class="xtra-grid-wrap">';
		echo '<table class="xtra-grid" role="grid">';
		echo '<thead><tr><th class="xtra-grid-corner"></th>';
		foreach ( $days as $dow ) {
			echo '<th scope="col">' . esc_html( $day_labels[ $dow ] ?? (string) $dow ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $hours as $hour ) {
			echo '<tr>';
			echo '<th scope="row">' . esc_html( Xtra_Plugin::hour_label( $hour ) ) . '</th>';
			foreach ( $days as $dow ) {
				$key = $dow . '-' . $hour;
				$row = $live[ $key ] ?? null;
				self::cell( $dow, $hour, $row, $rate );
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<ul class="xtra-legend">';
		echo '<li><span class="xtra-swatch xtra-swatch-avail"></span>' . esc_html__( 'Available', 'xtra' ) . '</li>';
		echo '<li><span class="xtra-swatch xtra-swatch-sel"></span>' . esc_html__( 'Selected', 'xtra' ) . '</li>';
		echo '<li><span class="xtra-swatch xtra-swatch-pend"></span>' . esc_html__( 'Reserved', 'xtra' ) . '</li>';
		echo '<li><span class="xtra-swatch xtra-swatch-spon"></span>' . esc_html__( 'Sponsored', 'xtra' ) . '</li>';
		echo '</ul>';
		echo '</div>';

		echo '<aside class="xtra-sidebar">';
		echo '<div class="xtra-card" data-panel="select">';
		echo '<h3>' . esc_html__( 'Your hours', 'xtra' ) . '</h3>';
		echo '<p class="xtra-sidebar-prompt">' . esc_html__( 'Select hours on the grid to sponsor them every week.', 'xtra' ) . '</p>';
		echo '<p class="xtra-sidebar-count"><span data-count>0</span> <span data-count-label>' . esc_html__( 'hours', 'xtra' ) . '</span></p>';
		echo '<p class="xtra-sidebar-total">' . esc_html__( 'Monthly total', 'xtra' ) . ' <strong data-total>' . esc_html( Xtra_Plugin::format_aud( 0 ) ) . '</strong></p>';
		echo '<p class="xtra-sidebar-note">' . esc_html__( 'Each hour is billed monthly, not multiplied by weeks in a month.', 'xtra' ) . '</p>';
		echo '<button type="button" class="xtra-btn xtra-btn-primary" data-action="continue"' . disabled( $configured, false, false ) . '>';
		echo esc_html__( 'Continue', 'xtra' );
		echo '</button>';
		echo '<p class="xtra-form-error" data-error hidden></p>';
		echo '</div>';

		echo '<div class="xtra-card xtra-checkout" data-panel="checkout" hidden>';
		echo '<button type="button" class="xtra-back" data-action="back">&larr; ' . esc_html__( 'Back to the grid', 'xtra' ) . '</button>';
		echo '<h3>' . esc_html__( 'Checkout', 'xtra' ) . '</h3>';
		echo '<p class="xtra-checkout-summary" data-checkout-summary></p>';
		echo '<form class="xtra-form" data-form="checkout" novalidate>';
		echo '<div class="xtra-fields-2">';
		echo '<p><label for="xtra_first">' . esc_html__( 'First name', 'xtra' ) . '</label>';
		echo '<input type="text" id="xtra_first" name="first_name" required autocomplete="given-name" /></p>';
		echo '<p><label for="xtra_last">' . esc_html__( 'Last name', 'xtra' ) . '</label>';
		echo '<input type="text" id="xtra_last" name="last_name" required autocomplete="family-name" /></p>';
		echo '</div>';
		echo '<p><label for="xtra_email">' . esc_html__( 'Email', 'xtra' ) . '</label>';
		echo '<input type="email" id="xtra_email" name="email" required autocomplete="email" /></p>';
		echo '<fieldset class="xtra-address"><legend>' . esc_html__( 'Postal address (for donation receipts)', 'xtra' ) . '</legend>';
		echo '<p><label for="xtra_address">' . esc_html__( 'Street address', 'xtra' ) . '</label>';
		echo '<input type="text" id="xtra_address" name="address" required autocomplete="street-address" /></p>';
		echo '<div class="xtra-fields-2">';
		echo '<p><label for="xtra_suburb">' . esc_html__( 'Suburb', 'xtra' ) . '</label>';
		echo '<input type="text" id="xtra_suburb" name="suburb" required autocomplete="address-level2" /></p>';
		echo '<p><label for="xtra_state">' . esc_html__( 'State', 'xtra' ) . '</label>';
		echo '<select id="xtra_state" name="state" required autocomplete="address-level1">';
		echo '<option value="">' . esc_html__( 'Select…', 'xtra' ) . '</option>';
		foreach ( array( 'ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA' ) as $st ) {
			printf( '<option value="%s">%s</option>', esc_attr( $st ), esc_html( $st ) );
		}
		echo '</select></p>';
		echo '</div>';
		echo '<p><label for="xtra_postcode">' . esc_html__( 'Postcode', 'xtra' ) . '</label>';
		echo '<input type="text" id="xtra_postcode" name="postcode" required autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" /></p>';
		echo '</fieldset>';
		echo '<p><label for="xtra_phone">' . esc_html__( 'Phone (optional)', 'xtra' ) . '</label>';
		echo '<input type="tel" id="xtra_phone" name="phone" autocomplete="tel" /></p>';
		echo '<p><label for="xtra_message">' . esc_html__( 'Private message to the organisation (optional)', 'xtra' ) . '</label>';
		echo '<textarea id="xtra_message" name="message" rows="3"></textarea></p>';
		echo '<p class="xtra-terms"><label>';
		echo '<input type="checkbox" name="terms" value="1" required /> ';
		$terms_url = (string) $opts['terms_url'];
		if ( $terms_url !== '' ) {
			printf(
				/* translators: %s terms URL */
				wp_kses(
					__( 'I agree to the <a href="%s" target="_blank" rel="noopener noreferrer">terms of this monthly sponsorship</a>.', 'xtra' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				),
				esc_url( $terms_url )
			);
		} else {
			echo esc_html__( 'I agree to the terms of this monthly sponsorship. Hours are billed monthly until cancelled at the end of a calendar month.', 'xtra' );
		}
		echo '</label></p>';
		echo '<button type="submit" class="xtra-btn xtra-btn-primary">' . esc_html__( 'Pay', 'xtra' ) . '</button>';
		echo '<p class="xtra-form-error" data-error hidden></p>';
		echo '</form>';
		echo '</div>';
		echo '</aside>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * One grid cell. Public copy never includes a donor name.
	 *
	 * @param object|null $row Live row.
	 */
	private static function cell( int $dow, int $hour, $row, int $rate ): void {
		$label = Xtra_Plugin::cell_label( $dow, $hour );
		if ( ! $row ) {
			printf(
				'<td class="xtra-cell xtra-cell-available"><button type="button" class="xtra-cell-btn" data-dow="%d" data-hour="%d" data-status="available" aria-pressed="false" aria-label="%s"><span class="xtra-cell-time">%s</span><span class="xtra-cell-price">%s</span></button></td>',
				$dow,
				$hour,
				esc_attr( sprintf( /* translators: cell */ __( 'Available: %s', 'xtra' ), $label ) ),
				esc_html( Xtra_Plugin::hour_label( $hour ) ),
				esc_html( Xtra_Plugin::format_aud( $rate ) . '/mo' )
			);
			return;
		}

		$status = (string) $row->status;
		if ( $status === 'pending' ) {
			printf(
				'<td class="xtra-cell xtra-cell-pending"><div class="xtra-cell-static" data-status="pending" aria-label="%s"><span class="xtra-cell-time">%s</span><span class="xtra-cell-state">%s</span></div></td>',
				esc_attr( sprintf( /* translators: cell */ __( 'Reserved: %s', 'xtra' ), $label ) ),
				esc_html( Xtra_Plugin::hour_label( $hour ) ),
				esc_html__( 'Reserved', 'xtra' )
			);
			return;
		}

		// sponsored and cancelling both look taken. No name, initials, or badge.
		printf(
			'<td class="xtra-cell xtra-cell-sponsored"><div class="xtra-cell-static" data-status="sponsored" aria-label="%s"><span class="xtra-cell-time">%s</span><span class="xtra-cell-state">%s</span></div></td>',
			esc_attr( sprintf( /* translators: cell */ __( 'Sponsored: %s', 'xtra' ), $label ) ),
			esc_html( Xtra_Plugin::hour_label( $hour ) ),
			esc_html__( 'Sponsored', 'xtra' )
		);
	}
}
