<?php
/**
 * Stripe REST API via wp_remote_post. No Composer, no stripe-php.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe helper.
 */
class Xtra_Stripe {

	public const API_BASE = 'https://api.stripe.com/v1';

	/**
	 * Low-level request. Body is form-encoded nested arrays.
	 *
	 * @param string               $method GET|POST|DELETE.
	 * @param string               $path   Path beginning with /.
	 * @param array<string, mixed> $body   Form fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function request( string $method, string $path, array $body = array() ) {
		$opts = Xtra_Plugin::options();
		$sk   = (string) $opts['stripe_sk'];
		if ( $sk === '' ) {
			return new WP_Error( 'no_stripe', __( 'Payments are not configured.', 'xtra' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $sk,
				'Stripe-Version' => '2024-06-20',
			),
		);
		if ( ! empty( $body ) && $method !== 'GET' ) {
			$args['body'] = $body;
		} elseif ( ! empty( $body ) && $method === 'GET' ) {
			$path .= ( str_contains( $path, '?' ) ? '&' : '?' ) . http_build_query( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'stripe_bad_json', __( 'Unexpected response from Stripe.', 'xtra' ) );
		}
		if ( $code >= 400 ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Stripe request failed.', 'xtra' );
			return new WP_Error( 'stripe_error', $message, array( 'status' => $code, 'body' => $data ) );
		}
		return $data;
	}

	/**
	 * Create a subscription Checkout Session.
	 *
	 * @param array<int, object> $rows        Pending rows.
	 * @param array<string, mixed> $donor     Donor fields.
	 * @param string             $success_url Success URL.
	 * @param string             $cancel_url  Cancel URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_checkout_session( array $rows, array $donor, string $success_url, string $cancel_url ) {
		if ( empty( $rows ) ) {
			return new WP_Error( 'empty', __( 'No hours to check out.', 'xtra' ) );
		}

		$first        = $rows[0];
		$position_id  = (int) $first->position_id;
		$meta         = Xtra_Cpt::get_meta( $position_id );
		$role         = get_the_title( $position_id );
		$org          = $meta['org_name'] !== '' ? $meta['org_name'] : get_bloginfo( 'name' );
		$n            = count( $rows );
		$amount       = 0;
		$ids          = array();
		$labels       = array();
		foreach ( $rows as $row ) {
			$amount  += (int) $row->amount_cents;
			$ids[]    = (int) $row->id;
			$labels[] = Xtra_Plugin::cell_label( (int) $row->dow, (int) $row->hour );
		}

		$minutes    = Xtra_Plugin::pending_minutes();
		$expires_at = time() + max( 1800, $minutes * 60 ); // Stripe minimum is 30 minutes.

		$product_name = sprintf(
			/* translators: 1: hour count, 2: role, 3: org */
			_n( '%1$d weekly hour — %2$s (%3$s)', '%1$d weekly hours — %2$s (%3$s)', $n, 'xtra' ),
			$n,
			$role,
			$org
		);

		$id_csv = implode( ',', $ids );

		$body = array(
			'mode'                => 'subscription',
			'success_url'         => $success_url,
			'cancel_url'          => $cancel_url,
			'expires_at'          => $expires_at,
			'client_reference_id' => 'xtra-' . $position_id . '-' . $ids[0],
			'customer_email'      => (string) $donor['email'],
			'metadata'            => array(
				'xtra_sponsorship_ids' => $id_csv,
				'xtra_position_id'     => (string) $position_id,
			),
			'subscription_data'   => array(
				'metadata' => array(
					'xtra_sponsorship_ids' => $id_csv,
					'xtra_position_id'     => (string) $position_id,
				),
			),
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'aud',
						'unit_amount'  => $amount,
						'recurring'    => array(
							'interval' => 'month',
						),
						'product_data' => array(
							'name'        => $product_name,
							'description' => implode( ', ', $labels ),
						),
					),
				),
			),
		);

		$session = self::request( 'POST', '/checkout/sessions', $body );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$session_id = isset( $session['id'] ) ? (string) $session['id'] : '';
		foreach ( $ids as $id ) {
			Xtra_Db::update_row(
				$id,
				array(
					'stripe_session_id' => $session_id,
					'donor_name'        => (string) $donor['name'],
					'donor_email'       => (string) $donor['email'],
					'donor_phone'       => $donor['phone'] !== '' ? (string) $donor['phone'] : null,
					'message'           => $donor['message'] !== '' ? (string) $donor['message'] : null,
				)
			);
		}

		return $session;
	}

	/**
	 * Billing portal session for a customer.
	 *
	 * @return string URL or empty.
	 */
	public static function portal_url( string $customer_id, string $return_url ): string {
		if ( $customer_id === '' ) {
			return '';
		}
		$session = self::request(
			'POST',
			'/billing_portal/sessions',
			array(
				'customer'   => $customer_id,
				'return_url' => $return_url,
			)
		);
		if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
			return '';
		}
		return (string) $session['url'];
	}

	/**
	 * Set Stripe cancel_at unix timestamp on a subscription.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function cancel_at( string $subscription_id, int $timestamp ) {
		return self::request(
			'POST',
			'/subscriptions/' . rawurlencode( $subscription_id ),
			array(
				'cancel_at' => $timestamp,
			)
		);
	}

	/**
	 * Immediately cancel a subscription (conflict / do-not-double-book).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function cancel_now( string $subscription_id ) {
		return self::request( 'DELETE', '/subscriptions/' . rawurlencode( $subscription_id ) );
	}

	/**
	 * Verify Stripe-Signature header against the raw payload.
	 */
	public static function verify_signature( string $payload, string $header, string $secret ): bool {
		if ( $secret === '' || $header === '' ) {
			return false;
		}
		$parts = array();
		foreach ( explode( ',', $header ) as $piece ) {
			$piece = trim( $piece );
			if ( str_contains( $piece, '=' ) ) {
				list( $k, $v ) = explode( '=', $piece, 2 );
				$parts[ $k ]   = $v;
			}
		}
		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return false;
		}
		$timestamp = (int) $parts['t'];
		if ( abs( time() - $timestamp ) > 300 ) {
			return false;
		}
		$signed    = $timestamp . '.' . $payload;
		$expected  = hash_hmac( 'sha256', $signed, $secret );
		return hash_equals( $expected, $parts['v1'] );
	}

	/**
	 * Idempotent webhook dispatcher.
	 *
	 * @param array<string, mixed> $event Event object.
	 * @return true|WP_Error
	 */
	public static function handle_event( array $event ) {
		$event_id = isset( $event['id'] ) ? (string) $event['id'] : '';
		$type     = isset( $event['type'] ) ? (string) $event['type'] : '';
		if ( $event_id !== '' && self::already_processed( $event_id ) ) {
			return true;
		}

		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] )
			? $event['data']['object']
			: array();

		switch ( $type ) {
			case 'checkout.session.completed':
				$result = self::on_checkout_completed( $object );
				break;
			case 'invoice.payment_failed':
				$result = self::on_payment_failed( $object );
				break;
			case 'customer.subscription.deleted':
				$result = self::on_subscription_deleted( $object );
				break;
			default:
				$result = true;
				break;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $event_id !== '' ) {
			self::mark_processed( $event_id );
		}
		return true;
	}

	/**
	 * checkout.session.completed → Sponsored. Never double-book.
	 *
	 * @param array<string, mixed> $session Session.
	 * @return true|WP_Error
	 */
	private static function on_checkout_completed( array $session ) {
		$ids_csv = '';
		if ( ! empty( $session['metadata']['xtra_sponsorship_ids'] ) ) {
			$ids_csv = (string) $session['metadata']['xtra_sponsorship_ids'];
		}
		$ids = array_filter( array_map( 'intval', explode( ',', $ids_csv ) ) );
		if ( empty( $ids ) ) {
			return true;
		}

		$rows = Xtra_Db::get_rows_by_ids( $ids );
		if ( empty( $rows ) ) {
			return true;
		}

		$already = true;
		foreach ( $rows as $row ) {
			if ( $row->status !== 'sponsored' && $row->status !== 'cancelling' ) {
				$already = false;
				break;
			}
		}
		if ( $already ) {
			return true;
		}

		$conflict = false;
		foreach ( $rows as $row ) {
			if ( ! empty( $row->ended_at ) ) {
				$other = Xtra_Db::live_row_for_cell(
					(int) $row->position_id,
					(int) $row->dow,
					(int) $row->hour,
					(int) $row->id
				);
				if ( $other ) {
					$conflict = true;
					break;
				}
			}
		}

		$customer_id = self::object_id( $session['customer'] ?? '' );
		$sub_id      = self::object_id( $session['subscription'] ?? '' );
		$session_id  = isset( $session['id'] ) ? (string) $session['id'] : '';

		if ( $conflict ) {
			if ( $sub_id !== '' ) {
				self::cancel_now( $sub_id );
			}
			self::refund_session( $session );
			return true;
		}

		foreach ( $rows as $row ) {
			Xtra_Db::update_row(
				(int) $row->id,
				array(
					'status'                 => 'sponsored',
					'pending_until'          => null,
					'ended_at'               => null,
					'stripe_customer_id'     => $customer_id,
					'stripe_subscription_id' => $sub_id,
					'stripe_session_id'      => $session_id !== '' ? $session_id : $row->stripe_session_id,
				)
			);
		}

		$fresh = Xtra_Db::get_rows_by_ids( $ids );
		$return_url = home_url( '/' );
		$portal     = self::portal_url( $customer_id, $return_url );
		Xtra_Mail::payment_confirmed( $fresh, $portal );
		return true;
	}

	/**
	 * invoice.payment_failed → one grace, then release.
	 *
	 * @param array<string, mixed> $invoice Invoice.
	 * @return true|WP_Error
	 */
	private static function on_payment_failed( array $invoice ) {
		$sub_id = self::object_id( $invoice['subscription'] ?? '' );
		if ( $sub_id === '' ) {
			return true;
		}
		$rows = Xtra_Db::get_rows_by_subscription( $sub_id );
		if ( empty( $rows ) ) {
			return true;
		}

		$counts = get_option( Xtra_Plugin::FAIL_COUNTS_KEY, array() );
		if ( ! is_array( $counts ) ) {
			$counts = array();
		}
		$counts[ $sub_id ] = isset( $counts[ $sub_id ] ) ? (int) $counts[ $sub_id ] + 1 : 1;
		update_option( Xtra_Plugin::FAIL_COUNTS_KEY, $counts, false );

		if ( $counts[ $sub_id ] <= 1 ) {
			return true;
		}

		$now = Xtra_Plugin::now_mysql();
		foreach ( $rows as $row ) {
			Xtra_Db::update_row(
				(int) $row->id,
				array(
					'ended_at' => $now,
				)
			);
		}
		Xtra_Mail::cell_released( $rows );
		return true;
	}

	/**
	 * customer.subscription.deleted → cancel_at end of calendar month, release when due.
	 *
	 * @param array<string, mixed> $subscription Subscription.
	 * @return true|WP_Error
	 */
	private static function on_subscription_deleted( array $subscription ) {
		$sub_id = self::object_id( $subscription['id'] ?? $subscription );
		if ( $sub_id === '' ) {
			return true;
		}
		$rows = Xtra_Db::get_rows_by_subscription( $sub_id );
		if ( empty( $rows ) ) {
			return true;
		}

		$end      = Xtra_Plugin::end_of_cancel_month();
		$cancel_at = $end->format( 'Y-m-d H:i:s' );
		$now       = Xtra_Plugin::now_mysql();

		if ( $end->getTimestamp() <= time() ) {
			foreach ( $rows as $row ) {
				Xtra_Db::update_row( (int) $row->id, array( 'ended_at' => $now, 'status' => 'cancelling', 'cancel_at' => $cancel_at ) );
			}
			Xtra_Mail::cell_released( $rows );
			return true;
		}

		Xtra_Db::mark_cancelling( $rows, $cancel_at );
		Xtra_Mail::cancel_scheduled( $rows, $cancel_at );
		return true;
	}

	/**
	 * Best-effort refund when a completed session cannot take the cells.
	 *
	 * @param array<string, mixed> $session Session.
	 */
	private static function refund_session( array $session ): void {
		$pi = '';
		if ( ! empty( $session['payment_intent'] ) && is_string( $session['payment_intent'] ) ) {
			$pi = $session['payment_intent'];
		} elseif ( ! empty( $session['invoice'] ) ) {
			$invoice_id = is_string( $session['invoice'] ) ? $session['invoice'] : '';
			if ( $invoice_id !== '' ) {
				$invoice = self::request( 'GET', '/invoices/' . rawurlencode( $invoice_id ) );
				if ( ! is_wp_error( $invoice ) && ! empty( $invoice['payment_intent'] ) && is_string( $invoice['payment_intent'] ) ) {
					$pi = $invoice['payment_intent'];
				}
			}
		}
		if ( $pi !== '' ) {
			self::request( 'POST', '/refunds', array( 'payment_intent' => $pi ) );
		}
	}

	/**
	 * Stripe id that may be a string or expanded object.
	 *
	 * @param mixed $value Value.
	 */
	public static function object_id( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) && ! empty( $value['id'] ) ) {
			return (string) $value['id'];
		}
		return '';
	}

	private static function already_processed( string $event_id ): bool {

		$ids = get_option( Xtra_Plugin::PROCESSED_EVENTS, array() );
		return is_array( $ids ) && in_array( $event_id, $ids, true );
	}

	/**
	 * Remember an event id (cap at 500).
	 */
	private static function mark_processed( string $event_id ): void {
		$ids = get_option( Xtra_Plugin::PROCESSED_EVENTS, array() );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}
		$ids[] = $event_id;
		$ids   = array_values( array_unique( $ids ) );
		if ( count( $ids ) > 500 ) {
			$ids = array_slice( $ids, -500 );
		}
		update_option( Xtra_Plugin::PROCESSED_EVENTS, $ids, false );
	}
}
