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
	public static function request( string $method, string $path, array $body = array(), int $timeout = 30 ) {
		$opts = Xtra_Plugin::options();
		$sk   = (string) $opts['stripe_sk'];
		if ( $sk === '' ) {
			return new WP_Error( 'no_stripe', __( 'Payments are not configured.', 'xtra' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => max( 1, $timeout ),
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
		$until      = wp_date( 'Y-m-d H:i:s', $expires_at );
		foreach ( $ids as $id ) {
			Xtra_Db::update_row(
				$id,
				array(
					'stripe_session_id' => $session_id,
					'donor_name'        => (string) $donor['name'],
					'donor_email'       => (string) $donor['email'],
					'donor_phone'       => $donor['phone'] !== '' ? (string) $donor['phone'] : null,
					'donor_address'     => (string) ( $donor['address'] ?? '' ),
					'donor_suburb'      => (string) ( $donor['suburb'] ?? '' ),
					'donor_state'       => (string) ( $donor['state'] ?? '' ),
					'donor_postcode'    => (string) ( $donor['postcode'] ?? '' ),
					'message'           => $donor['message'] !== '' ? (string) $donor['message'] : null,
					'pending_until'     => $until,
				)
			);
		}

		return $session;
	}

	/**
	 * Confirm a completed Checkout Session on return from Stripe (webhook fallback).
	 */
	public static function confirm_checkout_session( string $session_id ): bool {
		if ( $session_id === '' || ! str_starts_with( $session_id, 'cs_' ) ) {
			return false;
		}
		if ( ! Xtra_Plugin::stripe_configured() ) {
			return false;
		}

		$session = self::request( 'GET', '/checkout/sessions/' . rawurlencode( $session_id ) );
		if ( is_wp_error( $session ) ) {
			return false;
		}

		$status         = isset( $session['status'] ) ? (string) $session['status'] : '';
		$payment_status = isset( $session['payment_status'] ) ? (string) $session['payment_status'] : '';
		if ( $status !== 'complete' || $payment_status !== 'paid' ) {
			return false;
		}

		$result = self::apply_checkout_completed( $session );
		return ! is_wp_error( $result );
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
		// Short timeout so a slow portal call cannot starve payment_confirmed mail.
		$session = self::request(
			'POST',
			'/billing_portal/sessions',
			array(
				'customer'   => $customer_id,
				'return_url' => $return_url,
			),
			5
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
				if ( is_wp_error( $result ) ) {
					error_log( 'Xtra Checkout Completed Error: ' . $result->get_error_message() );
				}
				break;
			case 'invoice.paid':
				$result = self::on_invoice_paid( $object );
				if ( is_wp_error( $result ) ) {
					error_log( 'Xtra Invoice Paid Error: ' . $result->get_error_message() );
				}
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
		return self::apply_checkout_completed( $session );
	}

	/**
	 * Mark pending rows as sponsored for a paid Checkout Session.
	 *
	 * @param array<string, mixed> $session Session.
	 * @return true|WP_Error
	 */
	public static function apply_checkout_completed( array $session ) {
		$rows        = self::rows_for_session( $session );
		$session_id  = isset( $session['id'] ) ? (string) $session['id'] : '';
		$customer_id = self::object_id( $session['customer'] ?? '' );
		$sub_id      = self::object_id( $session['subscription'] ?? '' );

		if ( empty( $rows ) ) {
			// Fallback: update any pending rows for this session_id directly if rows_for_session was empty.
			if ( $session_id !== '' ) {
				global $wpdb;
				$table = Xtra_Db::table();
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE stripe_session_id = %s ORDER BY id ASC",
						$session_id
					)
				);
			}
			if ( empty( $rows ) ) {
				return true;
			}
		}

		$ids = array_map(
			static function ( $row ) {
				return (int) $row->id;
			},
			$rows
		);

		$already = true;
		foreach ( $rows as $row ) {
			if ( $row->status !== 'sponsored' && $row->status !== 'cancelling' ) {
				$already = false;
				break;
			}
		}
		if ( $already ) {
			$fresh = Xtra_Db::get_rows_by_ids( $ids );
			self::maybe_send_payment_confirmed( $fresh, '', $session_id );
			return true;
		}

		$conflict = false;
		foreach ( $rows as $row ) {
			if ( ! empty( $row->ended_at ) && $row->status !== 'pending' ) {
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

		// Retrieve customer object from Stripe to get full name and email if missing from session
		$cust_name  = '';
		$cust_email = '';
		if ( ! empty( $session['customer_details']['name'] ) && $session['customer_details']['name'] !== 'Any Name' ) {
			$cust_name = (string) $session['customer_details']['name'];
		}
		if ( ! empty( $session['customer_details']['email'] ) ) {
			$cust_email = (string) $session['customer_details']['email'];
		}
		
		// Also check existing row donor_name and donor_email
		foreach ( $rows as $row ) {
			if ( ! empty( $row->donor_name ) && $row->donor_name !== 'Any Name' && $cust_name === '' ) {
				$cust_name = (string) $row->donor_name;
			}
			if ( ! empty( $row->donor_email ) && $cust_email === '' ) {
				$cust_email = (string) $row->donor_email;
			}
		}

		if ( ( $cust_name === '' || $cust_email === '' ) && $customer_id !== '' ) {
			$cust_obj = self::request( 'GET', '/customers/' . rawurlencode( $customer_id ) );
			if ( ! is_wp_error( $cust_obj ) ) {
				if ( $cust_name === '' && ! empty( $cust_obj['name'] ) && $cust_obj['name'] !== 'Any Name' ) {
					$cust_name = (string) $cust_obj['name'];
				}
				if ( $cust_email === '' && ! empty( $cust_obj['email'] ) ) {
					$cust_email = (string) $cust_obj['email'];
				}
			}
		}

		// Final fallback: if cust_name is still empty, check if any row has a stored donor_name from the form
		if ( $cust_name === '' ) {
			foreach ( $rows as $row ) {
				if ( ! empty( $row->donor_name ) ) {
					$cust_name = (string) $row->donor_name;
					break;
				}
			}
		}

		if ( $conflict ) {
			if ( $sub_id !== '' ) {
				self::cancel_now( $sub_id );
			}
			self::refund_session( $session );
			return true;
		}

		foreach ( $rows as $row ) {
			$update_data = array(
				'status'                 => 'sponsored',
				'pending_until'          => null,
				'ended_at'               => null,
				'stripe_customer_id'     => $customer_id,
				'stripe_subscription_id' => $sub_id,
			);
			if ( $cust_name !== '' && $cust_name !== 'Any Name' ) {
				$update_data['donor_name'] = $cust_name;
			}
			if ( $cust_email !== '' ) {
				$update_data['donor_email'] = $cust_email;
			}

			$updated = Xtra_Db::update_row( (int) $row->id, $update_data );
		}

		$fresh = Xtra_Db::get_rows_by_ids( $ids );
		self::maybe_send_payment_confirmed( $fresh, '', $session_id );
		return true;
	}

	/**
	 * Send payment_confirmed once per Checkout Session (idempotent).
	 *
	 * @param array<int, object> $rows       Rows.
	 * @param string             $portal     Customer portal URL (may be empty).
	 * @param string             $session_id Checkout Session id.
	 */
	private static function maybe_send_payment_confirmed( array $rows, string $portal, string $session_id ): void {
		if ( empty( $rows ) ) {
			error_log( 'Xtra: payment_confirmed skipped — empty rows for session ' . $session_id );
			return;
		}
		if ( $session_id === '' ) {
			error_log( 'Xtra: payment_confirmed skipped — empty session_id' );
			return;
		}
		$key = 'xtra_paid_mail_' . $session_id;
		if ( get_transient( $key ) ) {
			return;
		}
		$sent = Xtra_Mail::payment_confirmed( $rows, $portal );
		if ( $sent ) {
			set_transient( $key, 1, 30 * DAY_IN_SECONDS );
		} else {
			error_log( 'Xtra: payment_confirmed mail failed for session ' . $session_id );
		}
	}

	/**
	 * Resolve sponsorship rows from Checkout Session metadata or session id.
	 *
	 * @param array<string, mixed> $session Session.
	 * @return array<int, object>
	 */
	private static function rows_for_session( array $session ): array {
		$ids_csv = '';
		if ( ! empty( $session['metadata']['xtra_sponsorship_ids'] ) ) {
			$ids_csv = (string) $session['metadata']['xtra_sponsorship_ids'];
		} elseif ( ! empty( $session['subscription_data']['metadata']['xtra_sponsorship_ids'] ) ) {
			$ids_csv = (string) $session['subscription_data']['metadata']['xtra_sponsorship_ids'];
		} elseif ( ! empty( $session['subscription'] ) && is_string( $session['subscription'] ) ) {
			// If subscription is just an ID string, fetch subscription object to check metadata.
			$sub_obj = self::request( 'GET', '/subscriptions/' . rawurlencode( $session['subscription'] ) );
			if ( ! is_wp_error( $sub_obj ) && ! empty( $sub_obj['metadata']['xtra_sponsorship_ids'] ) ) {
				$ids_csv = (string) $sub_obj['metadata']['xtra_sponsorship_ids'];
			}
		}
		$ids = array_filter( array_map( 'intval', explode( ',', $ids_csv ) ) );
		if ( ! empty( $ids ) ) {
			$rows = Xtra_Db::get_rows_by_ids( $ids );
			if ( ! empty( $rows ) ) {
				return $rows;
			}
		}

		$session_id = isset( $session['id'] ) ? (string) $session['id'] : '';
		if ( $session_id !== '' ) {
			$rows = Xtra_Db::get_rows_by_stripe_session( $session_id );
			if ( ! empty( $rows ) ) {
				return $rows;
			}
		}

		$sub_id = self::object_id( $session['subscription'] ?? '' );
		if ( $sub_id !== '' ) {
			global $wpdb;
			$table = Xtra_Db::table();
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE stripe_subscription_id = %s ORDER BY id ASC",
					$sub_id
				)
			);
			if ( is_array( $rows ) && ! empty( $rows ) ) {
				return $rows;
			}
		}

		// Final fallback: match most recent pending rows for this email if session email matches.
		$customer_email = isset( $session['customer_details']['email'] ) ? (string) $session['customer_email'] : '';
		if ( $customer_email === '' && ! empty( $session['customer_email'] ) ) {
			$customer_email = (string) $session['customer_email'];
		}
		if ( $customer_email !== '' ) {
			global $wpdb;
			$table = Xtra_Db::table();
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE donor_email = %s AND status = 'pending' ORDER BY id DESC LIMIT 5",
					$customer_email
				)
			);
			if ( is_array( $rows ) && ! empty( $rows ) ) {
				return $rows;
			}
		}

		return array();
	}

	/**
	 * Record a paid Stripe invoice in the local payments table.
	 *
	 * @param array<string, mixed> $invoice Invoice object.
	 * @return int Payment row id, or 0 if skipped.
	 */
	public static function record_invoice_payment( array $invoice ): int {
		$invoice_id = isset( $invoice['id'] ) ? (string) $invoice['id'] : '';
		if ( $invoice_id === '' || ! str_starts_with( $invoice_id, 'in_' ) ) {
			return 0;
		}

		$amount = isset( $invoice['amount_paid'] ) ? (int) $invoice['amount_paid'] : 0;
		if ( $amount < 1 ) {
			return 0;
		}

		$sub_id = self::object_id( $invoice['subscription'] ?? '' );
		if ( $sub_id === '' ) {
			return 0;
		}

		$rows = Xtra_Db::get_rows_by_subscription( $sub_id );
		if ( empty( $rows ) ) {
			$rows = self::ended_rows_by_subscription( $sub_id );
		}
		if ( empty( $rows ) ) {
			return 0;
		}

		$first = $rows[0];
		$labels = array();
		foreach ( $rows as $row ) {
			$labels[] = Xtra_Plugin::cell_label( (int) $row->dow, (int) $row->hour );
		}

		$paid_at = time();
		if ( ! empty( $invoice['status_transitions']['paid_at'] ) ) {
			$paid_at = (int) $invoice['status_transitions']['paid_at'];
		} elseif ( ! empty( $invoice['created'] ) ) {
			$paid_at = (int) $invoice['created'];
		}

		$customer_id = self::object_id( $invoice['customer'] ?? $first->stripe_customer_id ?? '' );
		$donor_name  = (string) $first->donor_name;
		$donor_email = (string) $first->donor_email;

		if ( ( $donor_name === '' || $donor_email === '' ) && $customer_id !== '' ) {
			$customer = self::request( 'GET', '/customers/' . rawurlencode( $customer_id ) );
			if ( ! is_wp_error( $customer ) ) {
				if ( $donor_email === '' && ! empty( $customer['email'] ) ) {
					$donor_email = (string) $customer['email'];
				}
				if ( $donor_name === '' && ! empty( $customer['name'] ) ) {
					$donor_name = (string) $customer['name'];
				}
			}
		}

		return Xtra_Db::insert_payment(
			array(
				'stripe_invoice_id'      => $invoice_id,
				'stripe_customer_id'     => $customer_id,
				'stripe_subscription_id' => $sub_id,
				'donor_name'             => $donor_name,
				'donor_email'            => $donor_email,
				'amount_cents'           => $amount,
				'paid_at'                => wp_date( 'Y-m-d H:i:s', $paid_at ),
				'position_id'            => (int) $first->position_id,
				'hour_labels'            => implode( ', ', $labels ),
			)
		);
	}

	/**
	 * Pull paid invoices from Stripe for a financial year into the local payments table.
	 *
	 * @return int|WP_Error Number of new payments recorded.
	 */
	public static function sync_payments_for_fy( string $fy ) {
		if ( ! Xtra_Plugin::stripe_configured() ) {
			return new WP_Error( 'no_stripe', __( 'Payments are not configured.', 'xtra' ) );
		}

		$bounds = Xtra_Receipts::financial_year_bounds( $fy );
		if ( ! $bounds ) {
			return new WP_Error( 'bad_fy', __( 'That financial year is not valid.', 'xtra' ) );
		}

		$created        = 0;
		$starting_after = null;

		do {
			$params = array(
				'status'         => 'paid',
				'limit'          => 100,
				'created[gte]'   => $bounds['start_ts'],
				'created[lte]'   => $bounds['end_ts'],
			);
			if ( $starting_after ) {
				$params['starting_after'] = $starting_after;
			}

			$response = self::request( 'GET', '/invoices', $params );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
			foreach ( $data as $invoice ) {
				if ( ! is_array( $invoice ) ) {
					continue;
				}
				$invoice_id = isset( $invoice['id'] ) ? (string) $invoice['id'] : '';
				$existed      = false;
				if ( $invoice_id !== '' ) {
					global $wpdb;
					$table   = Xtra_Db::payments_table();
					$existed = (bool) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$table} WHERE stripe_invoice_id = %s",
							$invoice_id
						)
					);
				}
				$id = self::record_invoice_payment( $invoice );
				if ( $id > 0 && ! $existed ) {
					++$created;
				}
			}

			$has_more = ! empty( $response['has_more'] );
			if ( $has_more && ! empty( $data ) ) {
				$last           = end( $data );
				$starting_after = is_array( $last ) && ! empty( $last['id'] ) ? (string) $last['id'] : null;
			} else {
				$starting_after = null;
			}
		} while ( $starting_after );

		return $created;
	}

	/**
	 * Sponsorship rows for a subscription, including ended rows (for payment history).
	 *
	 * @return array<int, object>
	 */
	private static function ended_rows_by_subscription( string $subscription_id ): array {
		global $wpdb;
		$table = Xtra_Db::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE stripe_subscription_id = %s ORDER BY id ASC",
				$subscription_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * invoice.paid → record payment for receipts.
	 *
	 * @param array<string, mixed> $invoice Invoice.
	 * @return true|WP_Error
	 */
	private static function on_invoice_paid( array $invoice ) {
		self::record_invoice_payment( $invoice );
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