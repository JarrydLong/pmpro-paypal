<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handle incoming PayPal webhook.
 *
 * @param WP_REST_Request $request The REST request.
 * @return WP_REST_Response
 */
function pmpro_paypal_handle_webhook( $request ) {
	// Set webhook context.
	if ( function_exists( 'pmpro_doing_webhook' ) ) {
		pmpro_doing_webhook( 'paypal', true );
	}

	$body = $request->get_body();
	$event = json_decode( $body, true );

	if ( empty( $event ) || empty( $event['event_type'] ) ) {
		return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
	}

	// Verify webhook signature.
	$verified = pmpro_paypal_verify_webhook( $request, $event );
	if ( ! $verified ) {
		return new WP_REST_Response( array( 'error' => 'Signature verification failed' ), 401 );
	}

	$event_type = $event['event_type'];
	$resource   = $event['resource'] ?? array();

	// Route by event type.
	switch ( $event_type ) {
		case 'CHECKOUT.ORDER.APPROVED':
			$message = pmpro_paypal_handle_checkout_order_approved( $resource );
			break;

		case 'BILLING.SUBSCRIPTION.ACTIVATED':
			$message = pmpro_paypal_handle_subscription_activated( $resource );
			break;

		case 'PAYMENT.SALE.COMPLETED':
			$message = pmpro_paypal_handle_sale_completed( $resource );
			break;

		case 'PAYMENT.CAPTURE.COMPLETED':
			// Captures are handled by CHECKOUT.ORDER.APPROVED webhook. Log only.
			$message = 'Capture completed. Already processed via checkout order approval.';
			break;

		case 'PAYMENT.CAPTURE.REFUNDED':
		case 'PAYMENT.SALE.REFUNDED':
			$message = pmpro_paypal_handle_refund( $resource, $event_type );
			break;

		case 'BILLING.SUBSCRIPTION.CANCELLED':
		case 'BILLING.SUBSCRIPTION.SUSPENDED':
		case 'BILLING.SUBSCRIPTION.EXPIRED':
			$message = pmpro_paypal_handle_subscription_cancelled( $resource );
			break;

		case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
			$message = pmpro_paypal_handle_payment_failed( $resource );
			break;

		case 'BILLING.SUBSCRIPTION.RE-ACTIVATED':
			$message = pmpro_paypal_handle_subscription_reactivated( $resource );
			break;

		default:
			$message = 'Unhandled event type: ' . $event_type;
			break;
	}

	/**
	 * Fires after a PayPal webhook event is processed.
	 *
	 * @param string $event_type The PayPal event type.
	 * @param array  $resource The event resource data.
	 * @param string $message The processing result message.
	 */
	do_action( 'pmpro_paypal_webhook_processed', $event_type, $resource, $message );

	return new WP_REST_Response( array( 'message' => $message ), 200 );
}

/**
 * Verify PayPal webhook signature.
 *
 * @param WP_REST_Request $request The REST request.
 * @param array           $event The decoded event.
 * @return bool
 */
function pmpro_paypal_verify_webhook( $request, $event ) {
	$webhook_id = get_option( PMProGateway_paypal::get_webhook_id_option_name() );
	if ( empty( $webhook_id ) ) {
		// No webhook ID stored — can't verify. Allow in sandbox for testing.
		return 'sandbox' === get_option( 'pmpro_gateway_environment' );
	}

	$headers = $request->get_headers();

	$verify_args = array(
		'auth_algo'         => $headers['paypal_auth_algo'][0] ?? '',
		'cert_url'          => $headers['paypal_cert_url'][0] ?? '',
		'transmission_id'   => $headers['paypal_transmission_id'][0] ?? '',
		'transmission_sig'  => $headers['paypal_transmission_sig'][0] ?? '',
		'transmission_time' => $headers['paypal_transmission_time'][0] ?? '',
		'webhook_id'        => $webhook_id,
		'webhook_event'     => $event,
	);

	$api    = new PMPro_PayPal_API();
	$result = $api->verify_webhook_signature( $verify_args );

	if ( is_wp_error( $result ) ) {
		return false;
	}

	return ( $result['verification_status'] ?? '' ) === 'SUCCESS';
}

/**
 * Handle CHECKOUT.ORDER.APPROVED — one-time payment checkout completion.
 *
 * Captures the approved order at PayPal, finds the PMPro token order,
 * and completes checkout via pmpro_complete_async_checkout().
 */
function pmpro_paypal_handle_checkout_order_approved( $resource ) {
	global $wpdb;

	$paypal_order_id = $resource['id'] ?? '';
	if ( empty( $paypal_order_id ) ) {
		return 'No PayPal order ID in event.';
	}

	$api = new PMPro_PayPal_API();

	// Get the latest order data from PayPal.
	$paypal_order = $api->get_order( $paypal_order_id );
	if ( is_wp_error( $paypal_order ) ) {
		return 'Error getting order: ' . $paypal_order->get_error_message();
	}

	// Find the PMPro order by paypal_order_id meta.
	$pmpro_order_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT pmpro_membership_order_id FROM {$wpdb->pmpro_membership_ordermeta} WHERE meta_key = 'paypal_order_id' AND meta_value = %s LIMIT 1",
		$paypal_order_id
	) );

	if ( empty( $pmpro_order_id ) ) {
		return 'No PMPro order found for PayPal order ' . $paypal_order_id;
	}

	$morder = new MemberOrder( $pmpro_order_id );
	if ( empty( $morder->id ) ) {
		return 'Could not load PMPro order #' . $pmpro_order_id;
	}

	// Capture the order if still in APPROVED status.
	if ( 'APPROVED' === ( $paypal_order['status'] ?? '' ) ) {
		$capture_result = $api->capture_order( $paypal_order_id );
		if ( is_wp_error( $capture_result ) ) {
			return 'Error capturing payment for order #' . $morder->id . ': ' . $capture_result->get_error_message();
		}
		$paypal_order = $capture_result;
	}

	if ( 'COMPLETED' !== ( $paypal_order['status'] ?? '' ) ) {
		return 'Order not yet completed. Status: ' . ( $paypal_order['status'] ?? 'unknown' );
	}

	// Set payment transaction ID from capture.
	if ( ! empty( $paypal_order['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
		$morder->payment_transaction_id = $paypal_order['purchase_units'][0]['payments']['captures'][0]['id'];
		if ( method_exists( $morder, 'update_order_meta' ) ) {
			$morder->update_order_meta( 'paypal_capture_id', $morder->payment_transaction_id );
		}
	}
	$morder->saveOrder();

	// Complete checkout if still in token status.
	if ( 'token' === $morder->status ) {
		pmpro_pull_checkout_data_from_order( $morder );
		if ( pmpro_complete_async_checkout( $morder ) ) {
			return 'Order #' . $morder->id . ' completed successfully.';
		} else {
			return 'Order #' . $morder->id . ' failed to complete.';
		}
	}

	return 'Order #' . $morder->id . ' already completed (status: ' . $morder->status . ').';
}

/**
 * Handle BILLING.SUBSCRIPTION.ACTIVATED — subscription checkout completion.
 *
 * Finds the PMPro token order by subscription transaction ID,
 * gets the initial payment transaction, and completes checkout
 * via pmpro_complete_async_checkout().
 */
function pmpro_paypal_handle_subscription_activated( $resource ) {
	$subscription_id = $resource['id'] ?? '';
	if ( empty( $subscription_id ) ) {
		return 'No subscription ID in activation event.';
	}

	$gateway_env = get_option( 'pmpro_gateway_environment', 'sandbox' );
	$api = new PMPro_PayPal_API();

	// Find the token order by subscription_transaction_id.
	$morder = null;
	if ( method_exists( 'MemberOrder', 'get_order' ) ) {
		$morder = MemberOrder::get_order( array(
			'gateway'                     => 'paypal',
			'gateway_environment'         => 'sandbox' === $gateway_env ? 'sandbox' : 'live',
			'status'                      => 'token',
			'subscription_transaction_id' => $subscription_id,
		) );
	}

	if ( empty( $morder ) || empty( $morder->id ) ) {
		return 'Token order not found for subscription ' . $subscription_id;
	}

	// Try to get the initial payment transaction ID.
	$create_time = $resource['create_time'] ?? '';
	if ( ! empty( $create_time ) ) {
		$start_time   = date( 'c', strtotime( $create_time ) - 3600 );
		$end_time     = date( 'c', strtotime( $create_time ) + 3600 );
		$transactions = $api->get_subscription_transactions( $subscription_id, $start_time, $end_time );
		if ( ! is_wp_error( $transactions ) && ! empty( $transactions['transactions'][0]['id'] ) ) {
			$morder->payment_transaction_id = $transactions['transactions'][0]['id'];
			$morder->saveOrder();
		}
	}

	// Complete the checkout.
	pmpro_pull_checkout_data_from_order( $morder );
	if ( pmpro_complete_async_checkout( $morder ) ) {
		return 'Order #' . $morder->id . ' completed successfully.';
	} else {
		return 'Order #' . $morder->id . ' failed to complete.';
	}
}

/**
 * Handle PAYMENT.SALE.COMPLETED — recurring subscription payment.
 */
function pmpro_paypal_handle_sale_completed( $resource ) {
	$sale_id         = $resource['id'] ?? '';
	$billing_id      = $resource['billing_agreement_id'] ?? '';
	$amount          = $resource['amount']['total'] ?? $resource['amount']['value'] ?? '';
	$currency        = $resource['amount']['currency'] ?? $resource['amount']['currency_code'] ?? '';
	$gateway_env     = get_option( 'pmpro_gateway_environment', 'sandbox' );

	if ( empty( $billing_id ) ) {
		return 'No billing agreement ID in sale. Skipping.';
	}

	// Build order data for the gateway request handler.
	$order_data = array(
		'gateway'                     => 'paypal',
		'gateway_environment'         => 'sandbox' === $gateway_env ? 'sandbox' : 'live',
		'subscription_transaction_id' => $billing_id,
		'payment_transaction_id'      => $sale_id,
		'total'                       => $amount,
		'payment_type'                => 'PayPal',
	);

	if ( function_exists( 'pmpro_handle_recurring_payment_succeeded_at_gateway' ) ) {
		return pmpro_handle_recurring_payment_succeeded_at_gateway( $order_data );
	}

	return 'pmpro_handle_recurring_payment_succeeded_at_gateway not available.';
}

/**
 * Handle PAYMENT.CAPTURE.REFUNDED / PAYMENT.SALE.REFUNDED.
 */
function pmpro_paypal_handle_refund( $resource, $event_type ) {
	// For capture refunds, get the parent capture ID.
	// For sale refunds, get the parent sale ID.
	$refund_id = $resource['id'] ?? '';

	// Find the original transaction.
	$links = $resource['links'] ?? array();
	$parent_id = '';
	foreach ( $links as $link ) {
		if ( in_array( $link['rel'] ?? '', array( 'up', 'sale', 'capture' ), true ) ) {
			// Extract ID from the href.
			$parts = explode( '/', $link['href'] ?? '' );
			$parent_id = end( $parts );
			break;
		}
	}

	if ( empty( $parent_id ) ) {
		return 'Could not determine parent transaction for refund.';
	}

	// Find the PMPro order by payment transaction ID.
	$morder = new MemberOrder();
	$morder->getMemberOrderByPaymentTransactionID( $parent_id );

	if ( empty( $morder ) || empty( $morder->id ) ) {
		return 'No matching order found for refund (transaction: ' . $parent_id . ').';
	}

	if ( 'refunded' === $morder->status ) {
		return 'Order already marked as refunded.';
	}

	// Determine if this is a partial or full refund.
	$refund_amount = $resource['amount']['total'] ?? $resource['amount']['value'] ?? '';
	$order_total   = $morder->total;
	$is_partial    = ! empty( $refund_amount ) && ! empty( $order_total )
		&& abs( (float) $refund_amount - (float) $order_total ) > 0.01;

	if ( $is_partial ) {
		// Partial refund — add a note but don't change order status or send emails.
		if ( method_exists( $morder, 'add_order_note' ) ) {
			$morder->add_order_note( sprintf(
				'PayPal webhook: Partial refund of %s received. Refund ID: %s',
				$refund_amount,
				$refund_id
			) );
		}
		$morder->saveOrder();
		return 'Order #' . $morder->id . ' partial refund noted.';
	}

	// Full refund.
	$morder->status = 'refunded';

	if ( method_exists( $morder, 'add_order_note' ) ) {
		$morder->add_order_note( sprintf(
			'PayPal webhook: Order refunded. Refund ID: %s',
			$refund_id
		) );
	}

	$morder->saveOrder();

	// Send refund emails for full refunds only.
	$user = get_userdata( $morder->user_id );
	if ( $user ) {
		$pmproemail = new PMProEmail();
		$pmproemail->sendRefundedEmail( $user, $morder );

		$pmproemail = new PMProEmail();
		$pmproemail->sendRefundedAdminEmail( $user, $morder );
	}

	return 'Order #' . $morder->id . ' marked as refunded.';
}

/**
 * Handle subscription cancellation/suspension/expiration.
 */
function pmpro_paypal_handle_subscription_cancelled( $resource ) {
	$subscription_id = $resource['id'] ?? '';
	$gateway_env     = get_option( 'pmpro_gateway_environment', 'sandbox' );

	if ( empty( $subscription_id ) ) {
		return 'No subscription ID in event.';
	}

	if ( function_exists( 'pmpro_handle_subscription_cancellation_at_gateway' ) ) {
		return pmpro_handle_subscription_cancellation_at_gateway(
			$subscription_id,
			'paypal',
			'sandbox' === $gateway_env ? 'sandbox' : 'live'
		);
	}

	return 'pmpro_handle_subscription_cancellation_at_gateway not available.';
}

/**
 * Handle subscription payment failure.
 */
function pmpro_paypal_handle_payment_failed( $resource ) {
	$subscription_id = $resource['id'] ?? '';
	$gateway_env     = get_option( 'pmpro_gateway_environment', 'sandbox' );

	if ( empty( $subscription_id ) ) {
		return 'No subscription ID in failed payment event.';
	}

	$order_data = array(
		'gateway'                     => 'paypal',
		'gateway_environment'         => 'sandbox' === $gateway_env ? 'sandbox' : 'live',
		'subscription_transaction_id' => $subscription_id,
	);

	if ( function_exists( 'pmpro_handle_recurring_payment_failure_at_gateway' ) ) {
		return pmpro_handle_recurring_payment_failure_at_gateway( $order_data );
	}

	return 'pmpro_handle_recurring_payment_failure_at_gateway not available.';
}

/**
 * Handle subscription re-activation.
 */
function pmpro_paypal_handle_subscription_reactivated( $resource ) {
	$subscription_id = $resource['id'] ?? '';

	if ( empty( $subscription_id ) ) {
		return 'No subscription ID in re-activation event.';
	}

	// Find the PMPro subscription.
	$gateway_env = get_option( 'pmpro_gateway_environment', 'sandbox' );
	if ( class_exists( 'PMPro_Subscription' ) ) {
		$subscription = PMPro_Subscription::get_subscription_from_subscription_transaction_id(
			$subscription_id,
			'paypal',
			'sandbox' === $gateway_env ? 'sandbox' : 'live'
		);

		if ( ! empty( $subscription ) ) {
			$subscription->set( array( 'status' => 'active' ) );
			return 'Subscription ' . $subscription_id . ' re-activated.';
		}
	}

	return 'Could not find subscription ' . $subscription_id . ' for re-activation.';
}
