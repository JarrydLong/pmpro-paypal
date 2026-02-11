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
		case 'PAYMENT.SALE.COMPLETED':
			$message = pmpro_paypal_handle_sale_completed( $resource );
			break;

		case 'PAYMENT.CAPTURE.COMPLETED':
			// One-time captures are already handled at checkout. Log only.
			$message = 'Capture completed. Already processed at checkout.';
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

		case 'BILLING.SUBSCRIPTION.ACTIVATED':
			$message = 'Subscription activated. Already tracked from checkout.';
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
	$webhook_id = get_option( 'pmpro_paypal_webhook_id' );
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

	$morder->status = 'refunded';

	if ( method_exists( $morder, 'add_order_note' ) ) {
		$morder->add_order_note( sprintf(
			'PayPal webhook: Order refunded. Refund ID: %s',
			$refund_id
		) );
	}

	$morder->saveOrder();

	// Send refund emails.
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
