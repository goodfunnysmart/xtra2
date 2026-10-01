<?php
/**
 * Admin menus: settings and sponsors.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin UI.
 */
class Xtra_Admin {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_xtra_schedule_cancel', array( __CLASS__, 'handle_schedule_cancel' ) );
		add_action( 'admin_post_xtra_cancel_now', array( __CLASS__, 'handle_cancel_now' ) );
		add_action( 'admin_post_xtra_clear_pending', array( __CLASS__, 'handle_clear_pending' ) );
		add_action( 'admin_post_xtra_resend_confirm', array( __CLASS__, 'handle_resend_confirm' ) );
		add_action( 'admin_post_xtra_resend_receipt', array( __CLASS__, 'handle_resend_receipt' ) );
		add_action( 'admin_post_xtra_export_sponsors', array( __CLASS__, 'handle_export_sponsors' ) );
		add_action( 'admin_post_xtra_export_payments', array( __CLASS__, 'handle_export_payments' ) );
		add_action( 'admin_post_xtra_donation_summaries', array( __CLASS__, 'handle_donation_summaries' ) );
		add_action( 'admin_notices', array( __CLASS__, 'uninstall_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( XTRA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Top-level Xtra menu.
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'Xtra', 'xtra' ),
			__( 'Xtra', 'xtra' ),
			'manage_options',
			'xtra',
			array( __CLASS__, 'render_settings' ),
			'dashicons-clock',
			26
		);
		add_submenu_page(
			'xtra',
			__( 'Settings', 'xtra' ),
			__( 'Settings', 'xtra' ),
			'manage_options',
			'xtra',
			array( __CLASS__, 'render_settings' )
		);
		add_submenu_page(
			'xtra',
			__( 'Sponsors', 'xtra' ),
			__( 'Sponsors', 'xtra' ),
			'manage_options',
			'xtra-sponsors',
			array( __CLASS__, 'render_sponsors' )
		);
		add_submenu_page(
			'xtra',
			__( 'Payments', 'xtra' ),
			__( 'Payments', 'xtra' ),
			'manage_options',
			'xtra-payments',
			array( __CLASS__, 'render_payments' )
		);
		add_submenu_page(
			'xtra',
			__( 'Donation summaries', 'xtra' ),
			__( 'Donation summaries', 'xtra' ),
			'manage_options',
			'xtra-donation-summaries',
			array( __CLASS__, 'render_donation_summaries' )
		);
	}

	/**
	 * Settings API group (values saved manually to mask secrets).
	 */
	public static function register_settings(): void {
		register_setting(
			'xtra_settings',
			Xtra_Plugin::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
				'default'           => Xtra_Plugin::defaults(),
			)
		);
	}

	/**
	 * Merge posted options, keeping secrets if the replace field is empty.
	 *
	 * @param mixed $input Posted.
	 * @return array<string, mixed>
	 */
	public static function sanitize_options( $input ): array {
		$current = Xtra_Plugin::options();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$out = $current;

		if ( isset( $input['stripe_pk'] ) ) {
			$pk = sanitize_text_field( (string) $input['stripe_pk'] );
			if ( $pk !== '' ) {
				$out['stripe_pk'] = $pk;
			}
		}

		foreach ( array( 'stripe_sk', 'stripe_webhook_secret' ) as $secret_key ) {
			if ( ! isset( $input[ $secret_key ] ) ) {
				continue;
			}
			$val = trim( (string) $input[ $secret_key ] );
			if ( $val !== '' && ! str_starts_with( $val, '••••' ) ) {
				$out[ $secret_key ] = sanitize_text_field( $val );
			}
		}

		if ( isset( $input['from_name'] ) ) {
			$out['from_name'] = sanitize_text_field( (string) $input['from_name'] );
		}
		if ( isset( $input['from_email'] ) ) {
			$email = sanitize_email( (string) $input['from_email'] );
			if ( $email !== '' ) {
				$out['from_email'] = $email;
			}
		}
		if ( isset( $input['pending_minutes'] ) ) {
			$mins = absint( $input['pending_minutes'] );
			if ( $mins < 5 ) {
				$mins = 5;
			}
			if ( $mins > 120 ) {
				$mins = 120;
			}
			$out['pending_minutes'] = $mins;
		}
		if ( isset( $input['terms_url'] ) ) {
			$out['terms_url'] = esc_url_raw( (string) $input['terms_url'] );
		}
		if ( isset( $input['privacy_url'] ) ) {
			$out['privacy_url'] = esc_url_raw( (string) $input['privacy_url'] );
		}

		if ( isset( $input['receipt_logo_id'] ) ) {
			$out['receipt_logo_id'] = absint( $input['receipt_logo_id'] );
		}
		if ( isset( $input['receipt_issuer_name'] ) ) {
			$out['receipt_issuer_name'] = sanitize_text_field( (string) $input['receipt_issuer_name'] );
		}
		if ( isset( $input['receipt_abn'] ) ) {
			$out['receipt_abn'] = sanitize_text_field( (string) $input['receipt_abn'] );
		}
		if ( isset( $input['receipt_address'] ) ) {
			$out['receipt_address'] = sanitize_textarea_field( (string) $input['receipt_address'] );
		}
		if ( isset( $input['receipt_dgr_statement'] ) ) {
			$out['receipt_dgr_statement'] = sanitize_textarea_field( (string) $input['receipt_dgr_statement'] );
		}

		return $out;
	}

	/**
	 * Mask a secret for a form value. Empty if none stored.
	 */
	public static function mask_secret( string $secret ): string {
		$len = strlen( $secret );
		if ( $len < 8 ) {
			return '';
		}
		return '••••••••' . substr( $secret, -4 );
	}

	/**
	 * Admin CSS/JS.
	 */
	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		$load   = false;
		if ( $screen && ( $screen->post_type === Xtra_Cpt::POST_TYPE || str_starts_with( (string) $screen->id, 'xtra_page_' ) || $screen->id === 'toplevel_page_xtra' ) ) {
			$load = true;
		}
		if ( str_contains( $hook, 'xtra' ) ) {
			$load = true;
		}
		if ( ! $load ) {
			return;
		}
		wp_enqueue_style(
			'xtra-admin',
			XTRA_URL . 'assets/css/xtra-admin.css',
			array(),
			XTRA_VERSION
		);
		if ( $screen && $screen->id === 'toplevel_page_xtra' ) {
			wp_enqueue_media();
		}
		wp_enqueue_script(
			'xtra-admin',
			XTRA_URL . 'assets/js/xtra-admin.js',
			array(),
			XTRA_VERSION,
			true
		);
	}

	/**
	 * Settings page.
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'xtra' ) );
		}

		$opts    = Xtra_Plugin::options();
		$webhook = rest_url( 'xtra/v1/webhook' );

		echo '<div class="wrap xtra-settings">';
		echo '<h1>' . esc_html__( 'Xtra settings', 'xtra' ) . '</h1>';
		echo '<p>' . esc_html__( 'Donors sponsor weekly hours of a staff position on a monthly Stripe subscription. The public grid never shows who paid.', 'xtra' ) . '</p>';

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'xtra' ) . '</p></div>';
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'xtra_settings' );

		echo '<h2>' . esc_html__( 'Stripe', 'xtra' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Calls use wp_remote_post to the Stripe REST API. There is no Composer dependency. Use test keys until webhooks are proven.', 'xtra' ) . '</p>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th><label for="xtra_stripe_pk">' . esc_html__( 'Publishable key', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="text" class="regular-text" id="xtra_stripe_pk" name="%s[stripe_pk]" value="%s" autocomplete="off" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( (string) $opts['stripe_pk'] )
		);
		echo '</td></tr>';

		echo '<tr><th><label for="xtra_stripe_sk">' . esc_html__( 'Secret key', 'xtra' ) . '</label></th><td>';
		$sk_mask = self::mask_secret( (string) $opts['stripe_sk'] );
		printf(
			'<input type="password" class="regular-text" id="xtra_stripe_sk" name="%s[stripe_sk]" value="" placeholder="%s" autocomplete="new-password" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( $sk_mask !== '' ? sprintf( /* translators: last 4 of key */ __( 'Stored value ending %s — enter a new key to replace', 'xtra' ), substr( (string) $opts['stripe_sk'], -4 ) ) : __( 'sk_test_…', 'xtra' ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the stored secret.', 'xtra' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="xtra_stripe_wh">' . esc_html__( 'Webhook signing secret', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="password" class="regular-text" id="xtra_stripe_wh" name="%s[stripe_webhook_secret]" value="" placeholder="%s" autocomplete="new-password" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( (string) $opts['stripe_webhook_secret'] !== '' ? __( 'Stored — enter a new secret to replace', 'xtra' ) : __( 'whsec_…', 'xtra' ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the stored secret.', 'xtra' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Webhook URL', 'xtra' ) . '</th><td>';
		echo '<code>' . esc_html( $webhook ) . '</code>';
		echo '<p class="description">' . esc_html__( 'In the Stripe Dashboard, send checkout.session.completed, invoice.paid, invoice.payment_failed, and customer.subscription.deleted to this URL.', 'xtra' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';

		echo '<h2>' . esc_html__( 'Email', 'xtra' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="xtra_from_name">' . esc_html__( 'From name', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="text" class="regular-text" id="xtra_from_name" name="%s[from_name]" value="%s" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( (string) $opts['from_name'] )
		);
		echo '</td></tr>';
		echo '<tr><th><label for="xtra_from_email">' . esc_html__( 'From email', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="email" class="regular-text" id="xtra_from_email" name="%s[from_email]" value="%s" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( (string) $opts['from_email'] )
		);
		echo '</td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Checkout', 'xtra' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="xtra_pending">' . esc_html__( 'Pending reservation (minutes)', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="number" min="5" max="120" id="xtra_pending" name="%s[pending_minutes]" value="%d" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			(int) $opts['pending_minutes']
		);
		echo '<p class="description">' . esc_html__( 'Default 15. Abandoned checkouts release hours after this window. Stripe Checkout sessions cannot expire sooner than 30 minutes; if payment lands after expiry and the cell was retaken, the webhook will not double-book.', 'xtra' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Hour-start emails (for sponsors who opt in) need WP-Cron, or a real system cron hitting wp-cron.php, roughly every 5 minutes. The plugin registers a 5-minute schedule for that.', 'xtra' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th><label for="xtra_terms">' . esc_html__( 'Terms URL', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="url" class="regular-text" id="xtra_terms" name="%s[terms_url]" value="%s" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( (string) $opts['terms_url'] )
		);
		echo '</td></tr>';
		echo '<tr><th><label for="xtra_privacy">' . esc_html__( 'Privacy Policy URL', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="url" class="regular-text" id="xtra_privacy" name="%s[privacy_url]" value="%s" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( (string) $opts['privacy_url'] )
		);
		echo '<p class="description">' . esc_html__( 'Shown as a link on checkout near the terms checkbox (not a separate required tick). If left blank, the WordPress Privacy Policy page is used when one is assigned.', 'xtra' ) . '</p>';
		echo '</td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Donation receipts', 'xtra' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Shown on tax receipt emails sent after each paid invoice. Use free-form receipt text for any DGR or tax wording your organisation needs.', 'xtra' ) . '</p>';
		echo '<table class="form-table" role="presentation">';

		$logo_id  = absint( $opts['receipt_logo_id'] ?? 0 );
		$logo_url = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		echo '<tr><th>' . esc_html__( 'Organisation logo', 'xtra' ) . '</th><td>';
		printf(
			'<input type="hidden" id="xtra_receipt_logo_id" name="%s[receipt_logo_id]" value="%d" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			$logo_id
		);
		echo '<div id="xtra_receipt_logo_preview" style="margin-bottom:8px;">';
		if ( $logo_url ) {
			printf( '<img src="%s" alt="" style="max-width:180px;height:auto;display:block;" />', esc_url( $logo_url ) );
		}
		echo '</div>';
		echo '<button type="button" class="button" id="xtra_receipt_logo_select">' . esc_html__( 'Select logo', 'xtra' ) . '</button> ';
		echo '<button type="button" class="button" id="xtra_receipt_logo_remove"' . ( $logo_id ? '' : ' style="display:none;"' ) . '>' . esc_html__( 'Remove', 'xtra' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Optional. Chosen from the Media Library; shown at the top of HTML tax receipts.', 'xtra' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="xtra_receipt_issuer_name">' . esc_html__( 'Organisation name', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="text" class="regular-text" id="xtra_receipt_issuer_name" name="%s[receipt_issuer_name]" value="%s" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( (string) $opts['receipt_issuer_name'] )
		);
		echo '</td></tr>';

		echo '<tr><th><label for="xtra_receipt_abn">' . esc_html__( 'ABN', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="text" class="regular-text" id="xtra_receipt_abn" name="%s[receipt_abn]" value="%s" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( (string) $opts['receipt_abn'] )
		);
		echo '</td></tr>';

		echo '<tr><th><label for="xtra_receipt_address">' . esc_html__( 'Address', 'xtra' ) . '</label></th><td>';
		printf(
			'<textarea class="large-text" rows="3" id="xtra_receipt_address" name="%s[receipt_address]">%s</textarea>',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_textarea( (string) $opts['receipt_address'] )
		);
		echo '</td></tr>';

		echo '<tr><th><label for="xtra_receipt_dgr">' . esc_html__( 'Receipt text (DGR / tax wording)', 'xtra' ) . '</label></th><td>';
		printf(
			'<textarea class="large-text" rows="5" id="xtra_receipt_dgr" name="%s[receipt_dgr_statement]">%s</textarea>',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_textarea( (string) $opts['receipt_dgr_statement'] )
		);
		echo '<p class="description">' . esc_html__( 'Free-form text that appears on every receipt email.', 'xtra' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';

		submit_button( __( 'Save settings', 'xtra' ) );
		echo '</form>';

		$last_mail = get_option( 'xtra_last_confirm_mail', null );
		echo '<h2>' . esc_html__( 'Last confirmation mail attempt', 'xtra' ) . '</h2>';
		echo '<div class="xtra-last-confirm-mail" style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;max-width:640px;">';
		if ( ! is_array( $last_mail ) || empty( $last_mail['time'] ) ) {
			echo '<p class="description">' . esc_html__( 'No confirmation mail attempt has been recorded yet.', 'xtra' ) . '</p>';
		} else {
			$tz   = wp_timezone();
			$dt   = ( new DateTimeImmutable( '@' . (int) $last_mail['time'] ) )->setTimezone( $tz );
			$when = $dt->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
			$sid  = isset( $last_mail['session_id'] ) ? (string) $last_mail['session_id'] : '';
			$prefix = $sid !== '' ? ( strlen( $sid ) > 12 ? substr( $sid, 0, 12 ) . '…' : $sid ) : '—';
			echo '<table class="form-table" role="presentation" style="margin:0;">';
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Time', 'xtra' ), esc_html( $when ) );
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Email', 'xtra' ), esc_html( (string) ( $last_mail['email'] ?? '' ) ) );
			printf( '<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__( 'Result', 'xtra' ), esc_html( (string) ( $last_mail['result'] ?? '' ) ) );
			printf( '<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__( 'Session id', 'xtra' ), esc_html( $prefix ) );
			if ( ! empty( $last_mail['error'] ) ) {
				printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Error', 'xtra' ), esc_html( (string) $last_mail['error'] ) );
			}
			if ( isset( $last_mail['row_count'] ) ) {
				printf( '<tr><th>%s</th><td>%d</td></tr>', esc_html__( 'Rows', 'xtra' ), (int) $last_mail['row_count'] );
			}
			echo '</table>';
		}
		echo '</div>';

		echo '<div class="xtra-uninstall-warn notice notice-warning inline"><p><strong>' . esc_html__( 'Uninstall warning.', 'xtra' ) . '</strong> ';
		echo esc_html__( 'Deleting this plugin does not cancel live Stripe subscriptions. Donors will keep being billed until you cancel those subscriptions in Stripe.', 'xtra' );
		echo '</p></div>';

		echo '</div>';
	}

	/**
	 * Sponsors table (the super-user list). Names stay here, never on the public grid.
	 */
	public static function render_sponsors(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'xtra' ) );
		}

		$position_id = isset( $_GET['position_id'] ) ? absint( $_GET['position_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $_GET['xtra_notice'] ) && $_GET['xtra_notice'] === 'cancelled' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cancel at month-end has been scheduled.', 'xtra' ) . '</p></div>';
		}
		if ( isset( $_GET['xtra_notice'] ) && $_GET['xtra_notice'] === 'now_cancelled' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Subscription cancelled immediately and hours released.', 'xtra' ) . '</p></div>';
		}
		if ( isset( $_GET['xtra_notice'] ) && $_GET['xtra_notice'] === 'pending_cleared' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Pending reservation cleared and hours released.', 'xtra' ) . '</p></div>';
		}
		if ( isset( $_GET['xtra_notice'] ) && $_GET['xtra_notice'] === 'confirm_resent' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Confirmation email resent.', 'xtra' ) . '</p></div>';
		}
		if ( isset( $_GET['xtra_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['xtra_error'] ) ) ) . '</p></div>';
		}

		$positions = get_posts(
			array(
				'post_type'      => Xtra_Cpt::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$result = Xtra_Db::query_admin(
			array(
				'position_id' => $position_id,
				'status'      => $status,
				'page'        => $page,
				'per_page'    => 50,
			)
		);

		echo '<div class="wrap xtra-sponsors">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Sponsors', 'xtra' ) . '</h1> ';
		printf(
			'<a class="page-title-action xtra-export-csv" href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xtra_export_sponsors' ), 'xtra_export_sponsors' ) ),
			esc_html__( 'Export CSV', 'xtra' )
		);
		echo '<hr class="wp-header-end" />';
		echo '<p>' . esc_html__( 'This list is the super-user group. Names are never shown on the public grid.', 'xtra' ) . '</p>';

		echo '<form method="get" class="xtra-filters">';
		echo '<input type="hidden" name="page" value="xtra-sponsors" />';
		echo '<label>' . esc_html__( 'Position', 'xtra' ) . ' <select name="position_id">';
		echo '<option value="0">' . esc_html__( 'All positions', 'xtra' ) . '</option>';
		foreach ( $positions as $p ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $p->ID,
				selected( $position_id, (int) $p->ID, false ),
				esc_html( $p->post_title )
			);
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Status', 'xtra' ) . ' <select name="status">';
		foreach ( array(
			'all'        => __( 'Live (all)', 'xtra' ),
			'pending'    => __( 'Pending', 'xtra' ),
			'sponsored'  => __( 'Sponsored', 'xtra' ),
			'cancelling' => __( 'Cancelling', 'xtra' ),
		) as $val => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $val ),
				selected( $status, $val, false ),
				esc_html( $label )
			);
		}
		echo '</select></label> ';
		submit_button( __( 'Filter', 'xtra' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped xtra-sponsors-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Hour', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Position', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Subscription', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Monthly', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'News', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Hour emails', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'xtra' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $result['rows'] ) ) {
			echo '<tr><td colspan="10">' . esc_html__( 'No sponsorships yet.', 'xtra' ) . '</td></tr>';
		}

		foreach ( $result['rows'] as $row ) {
			$cancel_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=xtra_schedule_cancel&subscription=' . rawurlencode( (string) $row->stripe_subscription_id ) ),
				'xtra_schedule_cancel'
			);
			$cancel_now_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=xtra_cancel_now&subscription=' . rawurlencode( (string) $row->stripe_subscription_id ) ),
				'xtra_cancel_now'
			);
			$clear_pending_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=xtra_clear_pending&row_id=' . absint( $row->id ) ),
				'xtra_clear_pending_' . absint( $row->id )
			);
			echo '<tr>';
			echo '<td>' . esc_html( $row->donor_name ) . '</td>';
			echo '<td><a href="mailto:' . esc_attr( $row->donor_email ) . '">' . esc_html( $row->donor_email ) . '</a></td>';
			echo '<td>' . esc_html( Xtra_Plugin::cell_label( (int) $row->dow, (int) $row->hour ) ) . '</td>';
			echo '<td>' . esc_html( get_the_title( (int) $row->position_id ) ) . '</td>';
			echo '<td>' . esc_html( $row->status );
			if ( $row->status === 'cancelling' && $row->cancel_at ) {
				echo '<br /><span class="description">' . esc_html( sprintf( /* translators: date */ __( 'until %s', 'xtra' ), mysql2date( get_option( 'date_format' ), $row->cancel_at ) ) ) . '</span>';
			}
			echo '</td>';
			echo '<td><code>' . esc_html( (string) $row->stripe_subscription_id ) . '</code></td>';
			echo '<td>' . esc_html( Xtra_Plugin::format_aud( (int) $row->amount_cents ) ) . '</td>';
			$news = isset( $row->opt_in_news ) ? (int) $row->opt_in_news : 1;
			$hour = isset( $row->opt_in_hour_start ) ? (int) $row->opt_in_hour_start : 0;
			echo '<td>' . esc_html( $news ? __( 'Yes', 'xtra' ) : __( 'No', 'xtra' ) ) . '</td>';
			echo '<td>' . esc_html( $hour ? __( 'Yes', 'xtra' ) : __( 'No', 'xtra' ) ) . '</td>';
			echo '<td>';
			if ( in_array( $row->status, array( 'sponsored', 'cancelling' ), true ) ) {
				$resend_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=xtra_resend_confirm&row_id=' . absint( $row->id ) ),
					'xtra_resend_confirm_' . absint( $row->id )
				);
				printf(
					'<a class="button" href="%s" style="margin-right:5px;">%s</a>',
					esc_url( $resend_url ),
					esc_html__( 'Resend confirmation', 'xtra' )
				);
				if ( $row->stripe_subscription_id ) {
					printf(
						'<a class="button xtra-cancel-month-btn" href="%s" style="margin-right:5px;">%s</a>',
						esc_url( $cancel_url ),
						esc_html__( 'Cancel month-end', 'xtra' )
					);
					printf(
						'<a class="button button-link-delete" href="%s" onclick="return confirm(\'%s\');">%s</a>',
						esc_url( $cancel_now_url ),
						esc_attr__( 'Are you sure you want to cancel this subscription immediately and free up the hours?', 'xtra' ),
						esc_html__( 'Cancel NOW', 'xtra' )
					);
				}
			} elseif ( $row->status === 'pending' && empty( $row->ended_at ) ) {
				printf(
					'<a class="button button-link-delete" href="%s" onclick="return confirm(\'%s\');">%s</a>',
					esc_url( $clear_pending_url ),
					esc_attr__( 'Are you sure you want to clear this pending reservation and free up the hours?', 'xtra' ),
					esc_html__( 'Clear pending', 'xtra' )
				);
			} else {
				echo '&mdash;';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$total_pages = (int) ceil( $result['total'] / 50 );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $total_pages,
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
	}


	/**
	 * Payments table — recent invoice payments and resend tax receipt.
	 */
	public static function render_payments(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'xtra' ) );
		}

		echo '<div class="wrap xtra-payments">';
		echo '<h1>' . esc_html__( 'Payments', 'xtra' ) . '</h1>';
		echo '<p>' . esc_html__( 'Recent Stripe invoice payments recorded by Xtra. Tax receipts are emailed automatically after invoice.paid when not already sent.', 'xtra' ) . '</p>';

		if ( isset( $_GET['xtra_notice'] ) && 'receipt_resent' === $_GET['xtra_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Tax receipt resent.', 'xtra' ) . '</p></div>';
		}
		if ( ! empty( $_GET['xtra_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['xtra_error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="xtra-export-form" style="margin:12px 0;">';
		echo '<input type="hidden" name="action" value="xtra_export_payments" />';
		wp_nonce_field( 'xtra_export_payments', '_wpnonce', false );
		echo '<label for="xtra_export_fy">' . esc_html__( 'Financial year', 'xtra' ) . '</label> ';
		echo '<select name="fy" id="xtra_export_fy">';
		echo '<option value="all">' . esc_html__( 'All', 'xtra' ) . '</option>';
		foreach ( Xtra_Receipts::data_financial_year_options() as $fy_key => $fy_label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $fy_key ), esc_html( $fy_label ) );
		}
		echo '</select> ';
		submit_button( __( 'Export CSV', 'xtra' ), 'secondary', '', false );
		echo '</form>';

		$payments = Xtra_Db::list_payments( 100 );
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Paid at', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Donor', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Receipt #', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Position', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Receipt sent', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'xtra' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $payments ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No payments recorded yet.', 'xtra' ) . '</td></tr>';
		} else {
			foreach ( $payments as $payment ) {
				$paid = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $payment->paid_at );
				$donor = trim( (string) $payment->donor_name );
				$email = (string) $payment->donor_email;
				$donor_cell = esc_html( $donor !== '' ? $donor : '—' );
				if ( $email !== '' ) {
					$donor_cell .= '<br><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
				}
				$position = $payment->position_id ? get_the_title( (int) $payment->position_id ) : '—';
				$receipt  = ! empty( $payment->receipt_number ) ? (string) $payment->receipt_number : '—';
				$sent_at  = ! empty( $payment->receipt_sent_at )
					? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $payment->receipt_sent_at )
					: '—';

				$resend = wp_nonce_url(
					admin_url( 'admin-post.php?action=xtra_resend_receipt&payment_id=' . (int) $payment->id ),
					'xtra_resend_receipt_' . (int) $payment->id
				);

				echo '<tr>';
				echo '<td>' . esc_html( $paid ) . '</td>';
				echo '<td>' . $donor_cell . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
				echo '<td>' . esc_html( Xtra_Plugin::format_aud( (int) $payment->amount_cents ) ) . '</td>';
				echo '<td><code>' . esc_html( $receipt ) . '</code></td>';
				echo '<td>' . esc_html( $position !== '' ? $position : '—' ) . '</td>';
				echo '<td>' . esc_html( $sent_at ) . '</td>';
				echo '<td><a class="button button-small" href="' . esc_url( $resend ) . '">' . esc_html__( 'Resend tax receipt', 'xtra' ) . '</a></td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Max donation summaries sent per click (synchronous; keeps requests well under PHP/host timeouts).
	 */
	public const SUMMARY_SEND_CAP = 40;

	/**
	 * Guard a CSV cell against formula injection: prefix ' when it starts with = + - @ (or tab / CR).
	 *
	 * @param mixed $value Cell value.
	 */
	public static function csv_safe( $value ): string {
		$value = (string) $value;
		if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Send CSV download headers and the UTF-8 BOM; return the output handle.
	 *
	 * @return resource
	 */
	private static function csv_start( string $filename ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		return $out;
	}

	/**
	 * Write one CSV row with injection guarding.
	 *
	 * @param resource          $out   Handle.
	 * @param array<int, mixed> $cells Cells.
	 */
	private static function csv_row( $out, array $cells ): void {
		fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $cells ), ',', '"', '' );
	}

	/**
	 * Cents → "45.00" for CSV.
	 */
	private static function csv_money( int $cents ): string {
		return number_format( $cents / 100, 2, '.', '' );
	}

	/**
	 * Plain (entity-decoded) post title for CSV / text.
	 */
	private static function plain_title( int $post_id ): string {
		if ( $post_id < 1 ) {
			return '';
		}
		return html_entity_decode( (string) get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * FY key ("2026-27") for a MySQL datetime in site time.
	 */
	private static function fy_for_mysql( string $mysql ): string {
		$ts = strtotime( $mysql );
		if ( ! $ts ) {
			return '';
		}
		$year  = (int) gmdate( 'Y', $ts );
		$month = (int) gmdate( 'n', $ts );
		if ( $month < 7 ) {
			--$year;
		}
		return Xtra_Receipts::financial_year_key( $year );
	}

	/**
	 * Admin-post: stream every sponsorship row as CSV.
	 */
	public static function handle_export_sponsors(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'xtra' ) );
		}
		check_admin_referer( 'xtra_export_sponsors' );

		$rows   = Xtra_Db::sponsorships_for_export();
		$days   = Xtra_Plugin::day_names();
		$titles = array();
		$orgs   = array();

		$out = self::csv_start( 'xtra-sponsors-' . wp_date( 'Y-m-d-His' ) . '.csv' );
		self::csv_row(
			$out,
			array( 'id', 'position_id', 'position', 'organisation', 'day', 'hour', 'cell', 'status', 'live', 'sponsor_name', 'email', 'phone', 'address', 'suburb', 'state', 'postcode', 'message', 'amount_aud', 'stripe_customer_id', 'stripe_subscription_id', 'stripe_session_id', 'opt_in_news', 'opt_in_hour_start', 'pending_until', 'created_at', 'cancel_at', 'ended_at' )
		);
		foreach ( $rows as $row ) {
			$pid = (int) $row->position_id;
			if ( ! isset( $titles[ $pid ] ) ) {
				$titles[ $pid ] = self::plain_title( $pid );
				$orgs[ $pid ]   = $pid ? (string) get_post_meta( $pid, Xtra_Cpt::META_ORG, true ) : '';
			}
			$dow = (int) $row->dow;
			self::csv_row(
				$out,
				array(
					(int) $row->id,
					$pid,
					$titles[ $pid ],
					$orgs[ $pid ],
					$days[ $dow ] ?? (string) $dow,
					Xtra_Plugin::hour_label( (int) $row->hour ),
					Xtra_Plugin::cell_label( $dow, (int) $row->hour ),
					(string) $row->status,
					empty( $row->ended_at ) ? 'yes' : 'no',
					(string) $row->donor_name,
					(string) $row->donor_email,
					(string) ( $row->donor_phone ?? '' ),
					(string) ( $row->donor_address ?? '' ),
					(string) ( $row->donor_suburb ?? '' ),
					(string) ( $row->donor_state ?? '' ),
					(string) ( $row->donor_postcode ?? '' ),
					(string) ( $row->message ?? '' ),
					self::csv_money( (int) $row->amount_cents ),
					(string) ( $row->stripe_customer_id ?? '' ),
					(string) ( $row->stripe_subscription_id ?? '' ),
					(string) ( $row->stripe_session_id ?? '' ),
					isset( $row->opt_in_news ) ? (int) $row->opt_in_news : 1,
					isset( $row->opt_in_hour_start ) ? (int) $row->opt_in_hour_start : 0,
					(string) ( $row->pending_until ?? '' ),
					(string) ( $row->created_at ?? '' ),
					(string) ( $row->cancel_at ?? '' ),
					(string) ( $row->ended_at ?? '' ),
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Admin-post: stream payments as CSV, optionally for one financial year.
	 */
	public static function handle_export_payments(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'xtra' ) );
		}
		check_admin_referer( 'xtra_export_payments' );

		$fy     = isset( $_GET['fy'] ) ? sanitize_text_field( wp_unslash( $_GET['fy'] ) ) : 'all';
		$start  = '';
		$end    = '';
		$suffix = 'all';
		if ( $fy !== '' && $fy !== 'all' ) {
			$bounds = Xtra_Receipts::financial_year_bounds( $fy );
			if ( ! $bounds ) {
				wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'That financial year is not valid.', 'xtra' ) ), admin_url( 'admin.php?page=xtra-payments' ) ) );
				exit;
			}
			$start  = $bounds['start'];
			$end    = $bounds['end'];
			$suffix = 'fy' . $fy;
		}

		$payments = Xtra_Db::payments_for_export( $start, $end );
		$titles   = array();

		$out = self::csv_start( 'xtra-payments-' . $suffix . '-' . wp_date( 'Y-m-d-His' ) . '.csv' );
		self::csv_row(
			$out,
			array( 'payment_id', 'date', 'financial_year', 'sponsor_name', 'email', 'position', 'hours', 'amount', 'currency', 'stripe_invoice_id', 'receipt_number', 'status', 'receipt_sent_at', 'stripe_customer_id', 'stripe_subscription_id' )
		);
		foreach ( $payments as $p ) {
			$pid = (int) ( $p->position_id ?? 0 );
			if ( ! isset( $titles[ $pid ] ) ) {
				$titles[ $pid ] = self::plain_title( $pid );
			}
			self::csv_row(
				$out,
				array(
					(int) $p->id,
					(string) $p->paid_at,
					Xtra_Receipts::financial_year_short_label( self::fy_for_mysql( (string) $p->paid_at ) ),
					(string) $p->donor_name,
					(string) $p->donor_email,
					$titles[ $pid ],
					(string) ( $p->hour_labels ?? '' ),
					self::csv_money( (int) $p->amount_cents ),
					strtoupper( ! empty( $p->currency ) ? (string) $p->currency : 'aud' ),
					(string) $p->stripe_invoice_id,
					(string) ( $p->receipt_number ?? '' ),
					! empty( $p->status ) ? (string) $p->status : 'paid',
					(string) ( $p->receipt_sent_at ?? '' ),
					(string) ( $p->stripe_customer_id ?? '' ),
					(string) ( $p->stripe_subscription_id ?? '' ),
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Donation summaries page: FY picker, donor table, send / preview / CSV.
	 */
	public static function render_donation_summaries(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'xtra' ) );
		}

		$options = Xtra_Receipts::data_financial_year_options( true );
		$current = Xtra_Receipts::current_financial_year();
		$fy      = isset( $_GET['fy'] ) ? sanitize_text_field( wp_unslash( $_GET['fy'] ) ) : $current; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $options[ $fy ] ) ) {
			$fy = $current;
		}
		$bounds   = Xtra_Receipts::financial_year_bounds( $fy );
		$donors   = $bounds ? Xtra_Db::donor_summaries_fy( $bounds['start'], $bounds['end'] ) : array();
		$last     = Xtra_Db::summary_last_sent_map( $fy );
		$fy_label = Xtra_Receipts::financial_year_short_label( $fy );
		$page_url = add_query_arg(
			array(
				'page' => 'xtra-donation-summaries',
				'fy'   => $fy,
			),
			admin_url( 'admin.php' )
		);
		$dt_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<div class="wrap xtra-donation-summaries">';
		echo '<h1>' . esc_html__( 'Donation summaries', 'xtra' ) . '</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s comma-separated status values */
				__( 'Annual donation summaries per Australian financial year (1 July – 30 June), one per donor email, branded like the tax receipt. Only successful payments are counted (payment status: %s). Re-sending is allowed and every send is logged.', 'xtra' ),
				implode( ', ', Xtra_Db::success_statuses() )
			)
		) . '</p>';

		// Result notice from the last send (one-shot, per user).
		$result_key = 'xtra_summary_result_' . get_current_user_id();
		$result     = get_transient( $result_key );
		if ( is_array( $result ) ) {
			delete_transient( $result_key );
			$sent    = (array) ( $result['sent'] ?? array() );
			$failed  = (array) ( $result['failed'] ?? array() );
			$skipped = (array) ( $result['skipped'] ?? array() );
			$capped  = (int) ( $result['capped'] ?? 0 );
			$class   = empty( $failed ) ? 'notice-success' : ( empty( $sent ) ? 'notice-error' : 'notice-warning' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p><strong>' . esc_html(
				sprintf(
					/* translators: 1: FY label, 2: sent count, 3: failed count */
					__( 'Donation summaries %1$s: %2$d sent, %3$d failed.', 'xtra' ),
					Xtra_Receipts::financial_year_short_label( (string) ( $result['fy'] ?? $fy ) ),
					count( $sent ),
					count( $failed )
				)
			) . '</strong></p>';
			if ( ! empty( $sent ) ) {
				echo '<p>' . esc_html__( 'Sent to:', 'xtra' ) . ' ' . esc_html( implode( ', ', $sent ) ) . '</p>';
			}
			if ( ! empty( $failed ) ) {
				echo '<p>' . esc_html__( 'Failed (wp_mail returned false):', 'xtra' ) . ' ' . esc_html( implode( ', ', $failed ) ) . '</p>';
			}
			if ( ! empty( $skipped ) ) {
				echo '<p>' . esc_html__( 'Skipped (no successful payments in this financial year):', 'xtra' ) . ' ' . esc_html( implode( ', ', $skipped ) ) . '</p>';
			}
			if ( $capped > 0 ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: 1: number not sent, 2: cap */
						__( '%1$d selected donors were not sent because each click sends at most %2$d. Select them again and send.', 'xtra' ),
						$capped,
						self::SUMMARY_SEND_CAP
					)
				) . '</p>';
			}
			echo '</div>';
		}
		if ( ! empty( $_GET['xtra_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['xtra_error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// FY picker.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="xtra-fy-filter" style="margin:12px 0;">';
		echo '<input type="hidden" name="page" value="xtra-donation-summaries" />';
		echo '<label for="xtra_summary_fy"><strong>' . esc_html__( 'Financial year', 'xtra' ) . '</strong></label> ';
		echo '<select name="fy" id="xtra_summary_fy" onchange="this.form.submit()">';
		foreach ( $options as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $fy, $key, false ), esc_html( $label ) );
		}
		echo '</select> ';
		submit_button( __( 'Show', 'xtra' ), 'secondary', '', false );
		echo '</form>';

		// Preview (rendered HTML, not sent).
		$preview_email = isset( $_GET['preview_email'] ) ? strtolower( sanitize_email( wp_unslash( $_GET['preview_email'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $preview_email !== '' && $bounds ) {
			$nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'xtra_summary_preview' );
			$p_list   = $nonce_ok ? Xtra_Db::successful_payments_for_donor( $preview_email, $bounds['start'], $bounds['end'] ) : array();
			echo '<div id="xtra-summary-preview" class="xtra-summary-preview" style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:780px;">';
			if ( ! $nonce_ok ) {
				echo '<p>' . esc_html__( 'Preview link expired. Choose Preview again.', 'xtra' ) . '</p>';
			} elseif ( empty( $p_list ) ) {
				echo '<p>' . esc_html__( 'No successful payments for that donor in this financial year.', 'xtra' ) . '</p>';
			} else {
				$p_name = '';
				foreach ( $donors as $d ) {
					if ( $d['email'] === $preview_email ) {
						$p_name = $d['name'];
						break;
					}
				}
				$html  = Xtra_Receipts::annual_summary_html( $fy, $p_name, $preview_email, $p_list );
				$plain = Xtra_Receipts::annual_summary_body( $fy, $p_name, $preview_email, $p_list );
				echo '<h2 style="margin-top:0;">' . esc_html(
					sprintf(
						/* translators: 1: donor email, 2: FY label */
						__( 'Preview for %1$s (%2$s) — not sent', 'xtra' ),
						$preview_email,
						$fy_label
					)
				) . '</h2>';
				echo '<p><strong>' . esc_html__( 'To:', 'xtra' ) . '</strong> ' . esc_html( $preview_email ) . '<br><strong>' . esc_html__( 'Subject:', 'xtra' ) . '</strong> ' . esc_html( Xtra_Mail::annual_summary_subject( $fy ) ) . '</p>';
				echo '<iframe title="' . esc_attr__( 'Donation summary preview', 'xtra' ) . '" sandbox="" srcdoc="' . esc_attr( $html ) . '" style="width:100%;height:680px;border:1px solid #dcdcde;background:#fff;"></iframe>';
				echo '<details style="margin-top:8px;"><summary>' . esc_html__( 'Plain-text version', 'xtra' ) . '</summary><pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;">' . esc_html( $plain ) . '</pre></details>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
				echo '<input type="hidden" name="action" value="xtra_donation_summaries" />';
				echo '<input type="hidden" name="fy" value="' . esc_attr( $fy ) . '" />';
				echo '<input type="hidden" name="emails[]" value="' . esc_attr( $preview_email ) . '" />';
				wp_nonce_field( 'xtra_donation_summaries' );
				printf(
					'<button type="submit" class="button button-primary" name="xtra_do" value="send" onclick="return confirm(\'%s\');">%s</button> ',
					esc_attr( sprintf( /* translators: %s email */ __( 'Send this summary to %s now?', 'xtra' ), $preview_email ) ),
					esc_html__( 'Send to this donor', 'xtra' )
				);
				echo '<a class="button" href="' . esc_url( $page_url ) . '">' . esc_html__( 'Close preview', 'xtra' ) . '</a>';
				echo '</form>';
			}
			echo '</div>';
		}

		// Donor table + bulk actions.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="xtra-summaries-form">';
		echo '<input type="hidden" name="action" value="xtra_donation_summaries" />';
		echo '<input type="hidden" name="fy" value="' . esc_attr( $fy ) . '" />';
		wp_nonce_field( 'xtra_donation_summaries' );

		echo '<div class="tablenav top" style="height:auto;margin:8px 0;">';
		printf(
			'<button type="submit" class="button button-primary" name="xtra_do" value="send" onclick="return confirm(\'%s\');">%s</button> ',
			esc_attr__( 'Send the donation summary email to every ticked donor now?', 'xtra' ),
			esc_html__( 'Send summary to selected', 'xtra' )
		);
		echo '<button type="submit" class="button" name="xtra_do" value="preview">' . esc_html__( 'Preview', 'xtra' ) . '</button> ';
		echo '<button type="submit" class="button" name="xtra_do" value="csv">' . esc_html__( 'Download summaries CSV', 'xtra' ) . '</button> ';
		$all_csv = wp_nonce_url( admin_url( 'admin-post.php?action=xtra_donation_summaries&xtra_do=csv&fy=all' ), 'xtra_donation_summaries' );
		echo '<a href="' . esc_url( $all_csv ) . '" style="margin-left:6px;">' . esc_html__( 'CSV for all years', 'xtra' ) . '</a>';
		echo '</div>';

		echo '<table class="widefat striped xtra-summaries-table"><thead><tr>';
		echo '<td class="check-column" style="padding:8px 10px;"><input type="checkbox" id="xtra-summaries-select-all" aria-label="' . esc_attr__( 'Select all', 'xtra' ) . '" /></td>';
		echo '<th>' . esc_html__( 'Name', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Payments', 'xtra' ) . '</th>';
		echo '<th>' . esc_html( sprintf( /* translators: %s FY label */ __( 'Total paid (%s)', 'xtra' ), $fy_label ) ) . '</th>';
		echo '<th>' . esc_html__( 'Last summary sent', 'xtra' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'xtra' ) . '</th>';
		echo '</tr></thead><tbody>';

		$sum_count = 0;
		$sum_total = 0;
		if ( empty( $donors ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No successful payments in this financial year.', 'xtra' ) . '</td></tr>';
		}
		$preview_nonce = wp_create_nonce( 'xtra_summary_preview' );
		foreach ( $donors as $d ) {
			$sum_count += $d['payment_count'];
			$sum_total += $d['total_cents'];
			$sent_cell  = '—';
			if ( isset( $last[ $d['email'] ] ) ) {
				$sent_cell = mysql2date( $dt_fmt, $last[ $d['email'] ]['sent_at'] );
				if ( $last[ $d['email'] ]['count'] > 1 ) {
					$sent_cell .= ' (' . sprintf( /* translators: %d count */ _n( '%d send', '%d sends', $last[ $d['email'] ]['count'], 'xtra' ), $last[ $d['email'] ]['count'] ) . ')';
				}
			}
			$preview_url = add_query_arg(
				array(
					'preview_email' => rawurlencode( $d['email'] ),
					'_wpnonce'      => $preview_nonce,
				),
				$page_url
			) . '#xtra-summary-preview';
			echo '<tr>';
			echo '<th scope="row" class="check-column" style="padding:8px 10px;"><input type="checkbox" class="xtra-summary-cb" name="emails[]" value="' . esc_attr( $d['email'] ) . '" /></th>';
			echo '<td>' . esc_html( $d['name'] !== '' ? $d['name'] : '—' ) . '</td>';
			echo '<td><a href="mailto:' . esc_attr( $d['email'] ) . '">' . esc_html( $d['email'] ) . '</a></td>';
			echo '<td>' . (int) $d['payment_count'] . '</td>';
			echo '<td>' . esc_html( Xtra_Plugin::format_aud( $d['total_cents'] ) ) . '</td>';
			echo '<td>' . esc_html( $sent_cell ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $preview_url ) . '">' . esc_html__( 'Preview', 'xtra' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody>';
		if ( ! empty( $donors ) ) {
			echo '<tfoot><tr><td></td><th>' . esc_html( sprintf( /* translators: %d donors */ _n( '%d donor', '%d donors', count( $donors ), 'xtra' ), count( $donors ) ) ) . '</th><td></td>';
			echo '<th>' . (int) $sum_count . '</th><th>' . esc_html( Xtra_Plugin::format_aud( $sum_total ) ) . '</th><td></td><td></td></tr></tfoot>';
		}
		echo '</table>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d cap */
				__( 'Sends run immediately, at most %d donors per click so large selections do not time out. Preview uses the first ticked donor. Download summaries CSV covers every donor in the selected financial year.', 'xtra' ),
				self::SUMMARY_SEND_CAP
			)
		) . '</p>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Admin-post: donation summaries (send / preview / CSV).
	 */
	public static function handle_donation_summaries(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'xtra' ) );
		}
		check_admin_referer( 'xtra_donation_summaries' );

		$do       = isset( $_REQUEST['xtra_do'] ) ? sanitize_key( wp_unslash( $_REQUEST['xtra_do'] ) ) : '';
		$fy       = isset( $_REQUEST['fy'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['fy'] ) ) : '';
		$redirect = add_query_arg(
			array(
				'page' => 'xtra-donation-summaries',
				'fy'   => $fy,
			),
			admin_url( 'admin.php' )
		);

		if ( 'csv' === $do ) {
			self::export_summaries_csv( $fy );
			exit;
		}

		if ( ! Xtra_Receipts::is_financial_year( $fy ) ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'That financial year is not valid.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$emails = array();
		if ( isset( $_POST['emails'] ) && is_array( $_POST['emails'] ) ) {
			foreach ( wp_unslash( $_POST['emails'] ) as $raw ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$email = strtolower( sanitize_email( (string) $raw ) );
				if ( $email !== '' && is_email( $email ) ) {
					$emails[ $email ] = true;
				}
			}
		}
		$emails = array_keys( $emails );

		if ( 'preview' === $do ) {
			if ( empty( $emails ) ) {
				wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Tick a donor to preview.', 'xtra' ) ), $redirect ) );
				exit;
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'preview_email' => rawurlencode( $emails[0] ),
						'_wpnonce'      => wp_create_nonce( 'xtra_summary_preview' ),
					),
					$redirect
				) . '#xtra-summary-preview'
			);
			exit;
		}

		if ( 'send' !== $do || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Unknown action.', 'xtra' ) ), $redirect ) );
			exit;
		}
		if ( empty( $emails ) ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Tick at least one donor to send to.', 'xtra' ) ), $redirect ) );
			exit;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$bounds = Xtra_Receipts::financial_year_bounds( $fy );
		$index  = array();
		foreach ( Xtra_Db::donor_summaries_fy( $bounds['start'], $bounds['end'] ) as $d ) {
			$index[ $d['email'] ] = $d;
		}

		$batch   = array_slice( $emails, 0, self::SUMMARY_SEND_CAP );
		$capped  = count( $emails ) - count( $batch );
		$sent    = array();
		$failed  = array();
		$skipped = array();

		foreach ( $batch as $email ) {
			$payments = isset( $index[ $email ] ) ? Xtra_Db::successful_payments_for_donor( $email, $bounds['start'], $bounds['end'] ) : array();
			if ( empty( $payments ) ) {
				$skipped[] = $email;
				continue;
			}
			$total = 0;
			foreach ( $payments as $payment ) {
				$total += (int) $payment->amount_cents;
			}
			$ok = Xtra_Mail::annual_summary( $fy, (string) $index[ $email ]['name'], $email, $payments );
			Xtra_Db::log_summary_send( $email, $fy, $ok, count( $payments ), $total );
			if ( $ok ) {
				$sent[] = $email;
			} else {
				$failed[] = $email;
			}
		}

		set_transient(
			'xtra_summary_result_' . get_current_user_id(),
			array(
				'fy'      => $fy,
				'sent'    => $sent,
				'failed'  => $failed,
				'skipped' => $skipped,
				'capped'  => $capped,
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Stream the summaries CSV: one row per donor per FY (one FY, or "all").
	 */
	private static function export_summaries_csv( string $fy ): void {
		if ( $fy === 'all' ) {
			$keys = array();
			foreach ( Xtra_Db::payment_fy_start_years( true ) as $year ) {
				$keys[] = Xtra_Receipts::financial_year_key( (int) $year );
			}
			$last = Xtra_Db::summary_last_sent_map( '' );
		} else {
			if ( ! Xtra_Receipts::is_financial_year( $fy ) ) {
				wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'That financial year is not valid.', 'xtra' ) ), admin_url( 'admin.php?page=xtra-donation-summaries' ) ) );
				exit;
			}
			$keys = array( $fy );
			$last = array();
			foreach ( Xtra_Db::summary_last_sent_map( $fy ) as $email => $info ) {
				$last[ $fy . '|' . $email ] = $info;
			}
		}

		$out = self::csv_start( 'xtra-donation-summaries-' . ( $fy === 'all' ? 'all' : 'fy' . $fy ) . '-' . wp_date( 'Y-m-d' ) . '.csv' );
		self::csv_row(
			$out,
			array( 'financial_year', 'fy_start', 'fy_end', 'donor_name', 'email', 'payment_count', 'total_amount', 'currency', 'first_payment', 'last_payment', 'last_summary_sent_at', 'summaries_sent' )
		);
		foreach ( $keys as $key ) {
			$bounds = Xtra_Receipts::financial_year_bounds( $key );
			if ( ! $bounds ) {
				continue;
			}
			foreach ( Xtra_Db::donor_summaries_fy( $bounds['start'], $bounds['end'] ) as $d ) {
				$info = $last[ $key . '|' . $d['email'] ] ?? null;
				self::csv_row(
					$out,
					array(
						Xtra_Receipts::financial_year_short_label( $key ),
						substr( $bounds['start'], 0, 10 ),
						substr( $bounds['end'], 0, 10 ),
						$d['name'],
						$d['email'],
						$d['payment_count'],
						self::csv_money( $d['total_cents'] ),
						'AUD',
						$d['first_paid'],
						$d['last_paid'],
						$info ? $info['sent_at'] : '',
						$info ? $info['count'] : 0,
					)
				);
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Admin-post: resend a payment tax receipt email.
	 */
	public static function handle_resend_receipt(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'xtra' ) );
		}

		$payment_id = isset( $_GET['payment_id'] ) ? absint( $_GET['payment_id'] ) : 0;
		check_admin_referer( 'xtra_resend_receipt_' . $payment_id );

		$redirect = admin_url( 'admin.php?page=xtra-payments' );

		if ( $payment_id < 1 ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Missing payment.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$payment = Xtra_Db::get_payment( $payment_id );
		if ( ! $payment ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Payment not found.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$sent = Xtra_Mail::payment_receipt( $payment );
		if ( ! $sent ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Tax receipt failed to send (wp_mail returned false).', 'xtra' ) ), $redirect ) );
			exit;
		}

		Xtra_Db::update_payment(
			$payment_id,
			array(
				'receipt_sent_at' => Xtra_Plugin::now_mysql(),
			)
		);

		wp_safe_redirect( add_query_arg( 'xtra_notice', 'receipt_resent', $redirect ) );
		exit;
	}

	/**
	 * Admin-post: schedule cancel at end of calendar month for a whole subscription.
	 */
	public static function handle_schedule_cancel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'xtra' ) );
		}
		check_admin_referer( 'xtra_schedule_cancel' );

		$sub = isset( $_GET['subscription'] ) ? sanitize_text_field( wp_unslash( $_GET['subscription'] ) ) : '';
		$redirect = admin_url( 'admin.php?page=xtra-sponsors' );

		if ( $sub === '' ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Missing subscription.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$rows = Xtra_Db::get_rows_by_subscription( $sub );
		if ( empty( $rows ) ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'No live hours for that subscription.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$end       = Xtra_Plugin::end_of_cancel_month();
		$cancel_at = $end->format( 'Y-m-d H:i:s' );

		$stripe = Xtra_Stripe::cancel_at( $sub, $end->getTimestamp() );
		if ( is_wp_error( $stripe ) ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( $stripe->get_error_message() ), $redirect ) );
			exit;
		}

		Xtra_Db::mark_cancelling( $rows, $cancel_at );
		Xtra_Mail::cancel_scheduled( $rows, $cancel_at );

		wp_safe_redirect( add_query_arg( 'xtra_notice', 'cancelled', $redirect ) );
		exit;
	}

	/**
	 * Admin-post: cancel subscription immediately and free hours.
	 */
	public static function handle_cancel_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'xtra' ) );
		}
		check_admin_referer( 'xtra_cancel_now' );

		$sub = isset( $_GET['subscription'] ) ? sanitize_text_field( wp_unslash( $_GET['subscription'] ) ) : '';
		$redirect = admin_url( 'admin.php?page=xtra-sponsors' );

		if ( $sub === '' ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Missing subscription.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$rows = Xtra_Db::get_rows_by_subscription( $sub );
		if ( empty( $rows ) ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'No live hours for that subscription.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$stripe = Xtra_Stripe::cancel_now( $sub );
		if ( is_wp_error( $stripe ) ) {
			$err_msg = $stripe->get_error_message();
			if ( ! str_contains( strtolower( $err_msg ), 'no such subscription' ) ) {
				wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( $err_msg ), $redirect ) );
				exit;
			}
		}

		$now = Xtra_Plugin::now_mysql();
		foreach ( $rows as $row ) {
			Xtra_Db::update_row(
				(int) $row->id,
				array(
					'ended_at'               => $now,
					'status'                 => 'cancelled',
					'stripe_subscription_id' => '',
				)
			);
		}
		Xtra_Mail::cell_released( $rows );

		wp_safe_redirect( add_query_arg( 'xtra_notice', 'now_cancelled', $redirect ) );
		exit;
	}



	/**
	 * Admin-post: resend payment confirmation email for a sponsored row's session/subscription.
	 * Bypasses the per-session transient so a missed mail can be forced.
	 */
	public static function handle_resend_confirm(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'xtra' ) );
		}

		$row_id = isset( $_GET['row_id'] ) ? absint( $_GET['row_id'] ) : 0;
		check_admin_referer( 'xtra_resend_confirm_' . $row_id );

		$redirect = admin_url( 'admin.php?page=xtra-sponsors' );

		if ( $row_id < 1 ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Missing sponsorship row.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$found = Xtra_Db::get_rows_by_ids( array( $row_id ) );
		if ( empty( $found ) ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Sponsorship row not found.', 'xtra' ) ), $redirect ) );
			exit;
		}
		$row = $found[0];

		$rows = array();
		$session_id = isset( $row->stripe_session_id ) ? (string) $row->stripe_session_id : '';
		$sub_id     = isset( $row->stripe_subscription_id ) ? (string) $row->stripe_subscription_id : '';

		if ( $session_id !== '' ) {
			$all = Xtra_Db::get_rows_by_stripe_session( $session_id );
			foreach ( $all as $candidate ) {
				if ( empty( $candidate->ended_at ) && in_array( $candidate->status, array( 'sponsored', 'cancelling' ), true ) ) {
					$rows[] = $candidate;
				}
			}
		}
		if ( empty( $rows ) && $sub_id !== '' ) {
			$rows = Xtra_Db::get_rows_by_subscription( $sub_id );
		}
		if ( empty( $rows ) ) {
			// Fall back to the single loaded row if it is still live.
			if ( empty( $row->ended_at ) && in_array( $row->status, array( 'sponsored', 'cancelling' ), true ) ) {
				$rows = array( $row );
			}
		}

		if ( empty( $rows ) ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'No live sponsorship rows to confirm.', 'xtra' ) ), $redirect ) );
			exit;
		}

		if ( $session_id !== '' ) {
			delete_transient( 'xtra_paid_mail_' . $session_id );
		}

		// Best-effort Billing Portal link (5s timeout). Resend still sends if portal empty.
		$portal = '';
		foreach ( $rows as $candidate ) {
			$cid = isset( $candidate->stripe_customer_id ) ? (string) $candidate->stripe_customer_id : '';
			if ( $cid !== '' ) {
				$portal = Xtra_Stripe::portal_url( $cid, home_url( '/' ) );
				break;
			}
		}
		$sent = Xtra_Mail::payment_confirmed( $rows, $portal );
		$first_email = isset( $rows[0]->donor_email ) ? (string) $rows[0]->donor_email : '';
		update_option(
			'xtra_last_confirm_mail',
			array(
				'time'       => time(),
				'session_id' => $session_id,
				'row_count'  => count( $rows ),
				'email'      => $first_email,
				'result'     => $sent ? 'sent' : 'failed',
				'error'      => $sent ? '' : 'admin resend: wp_mail returned false',
			),
			false
		);

		if ( ! $sent ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Confirmation email failed to send (wp_mail returned false).', 'xtra' ) ), $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'xtra_notice', 'confirm_resent', $redirect ) );
		exit;
	}

	/**
	 * Admin-post: clear a pending reservation immediately (same effect as expiry).
	 * Does not call Stripe — pending rows have no live subscription.
	 */
	public static function handle_clear_pending(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'xtra' ) );
		}

		$row_id = isset( $_GET['row_id'] ) ? absint( $_GET['row_id'] ) : 0;
		check_admin_referer( 'xtra_clear_pending_' . $row_id );

		$redirect = admin_url( 'admin.php?page=xtra-sponsors' );

		if ( $row_id < 1 ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'Missing pending row.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$rows = Xtra_Db::get_rows_by_ids( array( $row_id ) );
		if ( empty( $rows ) ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'That pending reservation was not found.', 'xtra' ) ), $redirect ) );
			exit;
		}

		$row = $rows[0];
		if ( $row->status !== 'pending' || ! empty( $row->ended_at ) ) {
			wp_safe_redirect( add_query_arg( 'xtra_error', rawurlencode( __( 'That row is not a live pending reservation.', 'xtra' ) ), $redirect ) );
			exit;
		}

		Xtra_Db::update_row(
			(int) $row->id,
			array(
				'ended_at' => Xtra_Plugin::now_mysql(),
			)
		);

		wp_safe_redirect( add_query_arg( 'xtra_notice', 'pending_cleared', $redirect ) );
		exit;
	}

	/**
	 * Plugins-screen warning about Stripe surviving uninstall.
	 */
	public static function uninstall_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'plugins' ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Xtra_Plugin::stripe_configured() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Xtra:</strong> ';
		echo esc_html__( 'Uninstalling this plugin will not cancel live Stripe subscriptions. Cancel them in Stripe first if you intend to stop billing.', 'xtra' );
		echo '</p></div>';
	}

	/**
	 * Settings shortcut on the plugins list.
	 *
	 * @param array<int, string> $links Links.
	 * @return array<int, string>
	 */
	public static function action_links( array $links ): array {
		$url     = admin_url( 'admin.php?page=xtra' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'xtra' ) . '</a>';
		return $links;
	}
}
