<?php
/**
 * REST routes: lock, checkout, Stripe webhook.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public and webhook REST API.
 */
class Xtra_Rest {

	public const NS = 'xtra/v1';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/lock',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'lock' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/checkout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'checkout' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Logged-out donors: valid wp_rest nonce is enough.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function public_permission( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}
		return (bool) wp_verify_nonce( (string) $nonce, 'wp_rest' );
	}

	/**
	 * Lock selected cells as pending.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function lock( WP_REST_Request $request ) {
		if ( ! Xtra_Plugin::stripe_configured() ) {
			return new WP_Error(
				'not_configured',
				__( 'Payments are not configured yet. You can view the grid, but checkout is unavailable.', 'xtra' ),
				array( 'status' => 400 )
			);
		}

		$position_id = absint( $request->get_param( 'position_id' ) );
		$cells_in    = $request->get_param( 'cells' );
		if ( $position_id < 1 || get_post_type( $position_id ) !== Xtra_Cpt::POST_TYPE ) {
			return new WP_Error( 'bad_position', __( 'That position was not found.', 'xtra' ), array( 'status' => 404 ) );
		}
		if ( ! is_array( $cells_in ) || empty( $cells_in ) ) {
			return new WP_Error( 'no_cells', __( 'Select at least one hour.', 'xtra' ), array( 'status' => 400 ) );
		}

		$meta     = Xtra_Cpt::get_meta( $position_id );
		$schedule = $meta['schedule'];
		$hours    = Xtra_Cpt::hours_in_schedule( $schedule );
		$days     = $schedule['days'];
		$rate     = (int) $meta['hourly_monthly_rate'];
		if ( $rate < 1 ) {
			return new WP_Error( 'no_rate', __( 'This position does not have a monthly rate set.', 'xtra' ), array( 'status' => 400 ) );
		}

		$cells = array();
		$seen  = array();
		foreach ( $cells_in as $cell ) {
			if ( ! is_array( $cell ) ) {
				continue;
			}
			$dow  = isset( $cell['dow'] ) ? (int) $cell['dow'] : 0;
			$hour = isset( $cell['hour'] ) ? (int) $cell['hour'] : -1;
			if ( ! in_array( $dow, $days, true ) || ! in_array( $hour, $hours, true ) ) {
				return new WP_Error( 'bad_cell', __( 'One of the selected hours is outside this timetable.', 'xtra' ), array( 'status' => 400 ) );
			}
			$key = $dow . '-' . $hour;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$cells[]      = array(
				'dow'  => $dow,
				'hour' => $hour,
			);
		}

		$ids = Xtra_Db::lock_cells( $position_id, $cells, $rate );
		if ( is_wp_error( $ids ) ) {
			$ids->add_data( array( 'status' => 409 ) );
			return $ids;
		}

		$token = wp_generate_password( 32, false, false );
		set_transient(
			'xtra_lock_' . $token,
			array(
				'ids'          => $ids,
				'position_id'  => $position_id,
				'created'      => time(),
			),
			Xtra_Plugin::pending_minutes() * 60
		);

		$amount = $rate * count( $ids );

		return rest_ensure_response(
			array(
				'ok'             => true,
				'token'          => $token,
				'sponsorship_ids'=> $ids,
				'count'          => count( $ids ),
				'amount_cents'   => $amount,
				'amount_label'   => Xtra_Plugin::format_aud( $amount ),
				'pending_minutes'=> Xtra_Plugin::pending_minutes(),
			)
		);
	}

	/**
	 * Create Stripe Checkout Session for a lock token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function checkout( WP_REST_Request $request ) {
		if ( ! Xtra_Plugin::stripe_configured() ) {
			return new WP_Error(
				'not_configured',
				__( 'Payments are not configured yet. You can view the grid, but checkout is unavailable.', 'xtra' ),
				array( 'status' => 400 )
			);
		}

		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$lock  = $token !== '' ? get_transient( 'xtra_lock_' . $token ) : false;
		if ( ! is_array( $lock ) || empty( $lock['ids'] ) ) {
			return new WP_Error(
				'lock_expired',
				__( 'Your reservation has expired. Please select the hours again.', 'xtra' ),
				array( 'status' => 410 )
			);
		}

		$first = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
		$last  = sanitize_text_field( (string) $request->get_param( 'last_name' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$address = sanitize_text_field( (string) $request->get_param( 'address' ) );
		$suburb  = sanitize_text_field( (string) $request->get_param( 'suburb' ) );
		$state   = strtoupper( sanitize_text_field( (string) $request->get_param( 'state' ) ) );
		$postcode = sanitize_text_field( (string) $request->get_param( 'postcode' ) );
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$terms   = (bool) $request->get_param( 'terms' );
		$return  = esc_url_raw( (string) $request->get_param( 'return_url' ) );

		if ( $first === '' || $last === '' ) {
			return new WP_Error( 'name', __( 'Please enter your first and last name.', 'xtra' ), array( 'status' => 400 ) );
		}
		if ( $email === '' || ! is_email( $email ) ) {
			return new WP_Error( 'email', __( 'Please enter a valid email address.', 'xtra' ), array( 'status' => 400 ) );
		}
		if ( $address === '' || $suburb === '' || $state === '' || $postcode === '' ) {
			return new WP_Error(
				'address',
				__( 'Please enter your postal address. We need this for donation receipts.', 'xtra' ),
				array( 'status' => 400 )
			);
		}
		if ( ! $terms ) {
			return new WP_Error(
				'terms',
				__( 'Please accept the terms and conditions.', 'xtra' ),
				array( 'status' => 400 )
			);
		}

		$rows = Xtra_Db::get_rows_by_ids( $lock['ids'] );
		$live = array();
		foreach ( $rows as $row ) {
			if ( empty( $row->ended_at ) && $row->status === 'pending' ) {
				$live[] = $row;
			}
		}
		if ( count( $live ) !== count( $lock['ids'] ) ) {
			return new WP_Error(
				'lock_expired',
				__( 'Your reservation has expired. Please select the hours again.', 'xtra' ),
				array( 'status' => 410 )
			);
		}

		$donor = array(
			'name'     => trim( $first . ' ' . $last ),
			'email'    => $email,
			'phone'    => $phone,
			'address'  => $address,
			'suburb'   => $suburb,
			'state'    => $state,
			'postcode' => $postcode,
			'message'  => $message,
		);

		$sep     = str_contains( $return, '?' ) ? '&' : '?';
		$success = $return . $sep . 'xtra_success=1&session_id={CHECKOUT_SESSION_ID}';
		$cancel  = $return . $sep . 'xtra_cancelled=1';

		$session = Xtra_Stripe::create_checkout_session( $live, $donor, $success, $cancel );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		if ( empty( $session['url'] ) ) {
			return new WP_Error( 'no_url', __( 'Stripe did not return a checkout URL.', 'xtra' ), array( 'status' => 502 ) );
		}

		$fresh = Xtra_Db::get_rows_by_ids( $lock['ids'] );
		Xtra_Mail::checkout_received( $fresh );

		return rest_ensure_response(
			array(
				'ok'  => true,
				'url' => (string) $session['url'],
			)
		);
	}

	/**
	 * Stripe webhook. Signature required.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function webhook( WP_REST_Request $request ) {
		$payload   = $request->get_body();
		$sig       = (string) $request->get_header( 'stripe-signature' );
		$secret    = (string) Xtra_Plugin::options()['stripe_webhook_secret'];

		error_log( 'Xtra Webhook Received. Sig present: ' . ( $sig !== '' ? 'yes' : 'no' ) );

		if ( $secret === '' ) {
			error_log( 'Xtra Webhook Error: No secret configured.' );
			return new WP_Error( 'no_secret', __( 'Webhook secret is not configured.', 'xtra' ), array( 'status' => 500 ) );
		}
		if ( ! Xtra_Stripe::verify_signature( $payload, $sig, $secret ) ) {
			error_log( 'Xtra Webhook Error: Invalid Stripe signature.' );
			return new WP_Error( 'bad_sig', __( 'Invalid Stripe signature.', 'xtra' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			error_log( 'Xtra Webhook Error: Invalid JSON.' );
			return new WP_Error( 'bad_json', __( 'Invalid JSON.', 'xtra' ), array( 'status' => 400 ) );
		}

		error_log( 'Xtra Webhook Event: ' . ( $event['type'] ?? 'unknown' ) );

		$result = Xtra_Stripe::handle_event( $event );
		if ( is_wp_error( $result ) ) {
			error_log( 'Xtra Webhook Error: ' . $result->get_error_message() );
			return $result;
		}

		error_log( 'Xtra Webhook Handled Successfully.' );
		return rest_ensure_response( array( 'received' => true ) );
	}
}
