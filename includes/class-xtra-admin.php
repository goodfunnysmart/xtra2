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
		echo '</td></tr>';
		echo '<tr><th><label for="xtra_terms">' . esc_html__( 'Terms URL', 'xtra' ) . '</label></th><td>';
		printf(
			'<input type="url" class="regular-text" id="xtra_terms" name="%s[terms_url]" value="%s" />',
			esc_attr( Xtra_Plugin::OPTION_KEY ),
			esc_attr( (string) $opts['terms_url'] )
		);
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
		echo '<h1>' . esc_html__( 'Sponsors', 'xtra' ) . '</h1>';
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
		echo '<th>' . esc_html__( 'Actions', 'xtra' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $result['rows'] ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No sponsorships yet.', 'xtra' ) . '</td></tr>';
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
