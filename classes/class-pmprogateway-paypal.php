<?php
defined( 'ABSPATH' ) || exit;

// Load base gateway class.
require_once PMPRO_DIR . '/classes/gateways/class.pmprogateway.php';

// Register on init.
add_action( 'init', array( 'PMProGateway_paypal', 'init' ) );

class PMProGateway_paypal extends PMProGateway {

	/**
	 * Constructor.
	 */
	public function __construct( $gateway = null ) {
		$this->gateway = $gateway;
		return $this->gateway;
	}

	/**
	 * Run on WP init.
	 */
	public static function init() {
		// Register gateway.
		add_filter( 'pmpro_gateways', array( 'PMProGateway_paypal', 'pmpro_gateways' ) );

		// Only add checkout hooks if this gateway is active.
		$gateway = pmpro_getGateway();
		if ( 'paypal' === $gateway ) {
			add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_paypal', 'pmpro_required_billing_fields' ) );
			add_filter( 'pmpro_include_billing_address_fields', '__return_false' );
			add_filter( 'pmpro_include_payment_information_fields', '__return_false' );
			add_filter( 'pmpro_checkout_default_submit_button', array( 'PMProGateway_paypal', 'pmpro_checkout_default_submit_button' ) );
		}

		// Refund hook.
		add_filter( 'pmpro_process_refund_paypal', array( 'PMProGateway_paypal', 'process_refund' ), 10, 2 );
	}

	/**
	 * Add to gateways list.
	 */
	public static function pmpro_gateways( $gateways ) {
		if ( empty( $gateways['paypal'] ) ) {
			$gateways['paypal'] = __( 'PayPal', 'pmpro-paypal' );
		}
		return $gateways;
	}

	/**
	 * Feature support.
	 */
	public static function supports( $feature ) {
		$supports = array(
			'subscription_sync'        => true,
			'payment_method_updates'   => false,
			'check_token_orders'       => true,
		);
		return empty( $supports[ $feature ] ) ? false : $supports[ $feature ];
	}

	// ---------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------

	/**
	 * Display settings fields.
	 */
	public static function show_settings_fields() {
		$client_id      = get_option( 'pmpro_paypal_client_id' );
		$client_secret  = get_option( 'pmpro_paypal_client_secret' );
		$webhook_id     = get_option( 'pmpro_paypal_webhook_id' );
		$webhook_url    = rest_url( 'pmpro-paypal/v1/webhook' );
		?>
		<div id="pmpro_paypal" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'PayPal Settings', 'pmpro-paypal' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class="form-table">
					<tbody>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label for="paypal_client_id"><?php esc_html_e( 'Client ID', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<input type="text" id="paypal_client_id" name="paypal_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text code" />
							</td>
						</tr>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label for="paypal_client_secret"><?php esc_html_e( 'Client Secret', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<input type="text" id="paypal_client_secret" name="paypal_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" autocomplete="off" class="regular-text code pmpro-admin-secure-key" />
							</td>
						</tr>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label><?php esc_html_e( 'Webhook URL', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<p><code><?php echo esc_html( $webhook_url ); ?></code></p>
								<?php if ( ! empty( $webhook_id ) ) : ?>
									<p class="description"><?php printf( esc_html__( 'Webhook ID: %s (auto-registered)', 'pmpro-paypal' ), esc_html( $webhook_id ) ); ?></p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'Webhook will be auto-registered when credentials are saved.', 'pmpro-paypal' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Save settings and auto-register webhook.
	 */
	public static function save_settings_fields() {
		if ( isset( $_REQUEST['paypal_client_id'] ) ) {
			update_option( 'pmpro_paypal_client_id', sanitize_text_field( $_REQUEST['paypal_client_id'] ) );
		}
		if ( isset( $_REQUEST['paypal_client_secret'] ) ) {
			update_option( 'pmpro_paypal_client_secret', sanitize_text_field( $_REQUEST['paypal_client_secret'] ) );
		}

		// Auto-register webhook if credentials are set and no webhook yet.
		$client_id = get_option( 'pmpro_paypal_client_id' );
		$secret    = get_option( 'pmpro_paypal_client_secret' );
		if ( ! empty( $client_id ) && ! empty( $secret ) ) {
			self::maybe_register_webhook();
		}
	}

	/**
	 * Register webhook at PayPal if not already registered.
	 */
	public static function maybe_register_webhook() {
		$webhook_id = get_option( 'pmpro_paypal_webhook_id' );
		if ( ! empty( $webhook_id ) ) {
			return;
		}

		$api = new PMPro_PayPal_API();
		$webhook_url = rest_url( 'pmpro-paypal/v1/webhook' );

		// Require HTTPS for PayPal webhooks.
		if ( strpos( $webhook_url, 'https://' ) !== 0 ) {
			return;
		}

		$events = array(
			'CHECKOUT.ORDER.APPROVED',
			'PAYMENT.SALE.COMPLETED',
			'PAYMENT.SALE.REFUNDED',
			'PAYMENT.CAPTURE.COMPLETED',
			'PAYMENT.CAPTURE.REFUNDED',
			'BILLING.SUBSCRIPTION.ACTIVATED',
			'BILLING.SUBSCRIPTION.CANCELLED',
			'BILLING.SUBSCRIPTION.SUSPENDED',
			'BILLING.SUBSCRIPTION.EXPIRED',
			'BILLING.SUBSCRIPTION.RE-ACTIVATED',
			'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
		);

		$result = $api->create_webhook( $webhook_url, $events );
		if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) {
			update_option( 'pmpro_paypal_webhook_id', sanitize_text_field( $result['id'] ) );
		}
	}

	// ---------------------------------------------------------------
	// Checkout Modifications
	// ---------------------------------------------------------------

	/**
	 * Remove billing address fields from required fields.
	 */
	public static function pmpro_required_billing_fields( $fields ) {
		// Remove CC and billing address fields.
		$remove = array(
			'bfirstname', 'blastname', 'baddress1', 'bcity',
			'bstate', 'bzipcode', 'bcountry', 'bphone',
			'CardType', 'AccountNumber', 'ExpirationMonth',
			'ExpirationYear', 'CVV',
		);
		foreach ( $remove as $field ) {
			unset( $fields[ $field ] );
		}
		return $fields;
	}

	/**
	 * Show a "Check Out with PayPal" submit button.
	 */
	public static function pmpro_checkout_default_submit_button( $show ) {
		global $gateway, $pmpro_requirebilling;
		?>
		<span id="pmpro_paypal_checkout" <?php if ( 'paypal' !== $gateway || ! $pmpro_requirebilling ) { ?>style="display: none;"<?php } ?>>
			<input type="hidden" name="submit-checkout" value="1" />
			<button type="submit" id="pmpro_btn-submit-paypal" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_btn pmpro_btn-submit-checkout pmpro_btn-submit-checkout-paypal' ) ); ?>">
				<?php esc_html_e( 'Check Out with PayPal', 'pmpro-paypal' ); ?>
			</button>
		</span>

		<span id="pmpro_submit_span" <?php if ( 'paypal' === $gateway && $pmpro_requirebilling ) { ?>style="display: none;"<?php } ?>>
			<input type="hidden" name="submit-checkout" value="1" />
			<input type="submit" id="pmpro_btn-submit" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_btn pmpro_btn-submit-checkout', 'pmpro_btn-submit-checkout' ) ); ?>" value="<?php if ( $pmpro_requirebilling ) { esc_attr_e( 'Submit and Check Out', 'pmpro-paypal' ); } else { esc_attr_e( 'Submit and Confirm', 'pmpro-paypal' ); } ?>" />
		</span>
		<?php

		return false;
	}

	// ---------------------------------------------------------------
	// Plan Management (Lazy Creation)
	// ---------------------------------------------------------------

	/**
	 * Get or create a PayPal billing plan for the given level + price combo.
	 *
	 * @param object $level PMPro level with billing info.
	 * @param string $currency Currency code.
	 * @return string|WP_Error Plan ID or error.
	 */
	public static function get_or_create_plan( $level, $currency ) {
		$environment = get_option( 'pmpro_gateway_environment' );
		$api = new PMPro_PayPal_API();

		// Ensure product exists.
		$product_id = self::get_or_create_product( $level );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		// Build params hash for this unique price/cycle combo.
		$initial    = pmpro_round_price_as_string( (float) $level->initial_payment );
		$recurring  = pmpro_round_price_as_string( (float) $level->billing_amount );
		$cycle_num  = intval( $level->cycle_number );
		$cycle_per  = strtoupper( $level->cycle_period ?? 'MONTH' );
		$trial_amt  = pmpro_round_price_as_string( (float) ( $level->trial_amount ?? 0 ) );
		$trial_lim  = intval( $level->trial_limit ?? 0 );
		$bill_lim   = intval( $level->billing_limit ?? 0 );

		$hash_input = "{$recurring}_{$cycle_num}_{$cycle_per}_{$initial}_{$trial_amt}_{$trial_lim}_{$bill_lim}_{$currency}";
		$plan_hash  = md5( $hash_input );

		// Check existing plans stored in level meta.
		$meta_key  = 'paypal_plans' . ( 'sandbox' === $environment ? '_sandbox' : '' );
		$plans_map = get_pmpro_membership_level_meta( $level->id, $meta_key, true );
		if ( ! is_array( $plans_map ) ) {
			$plans_map = array();
		}

		// If we have a cached plan ID for this hash, verify it exists at PayPal.
		if ( ! empty( $plans_map[ $plan_hash ] ) ) {
			$existing = $api->get_plan( $plans_map[ $plan_hash ] );
			if ( ! is_wp_error( $existing ) && ! empty( $existing['status'] ) && 'ACTIVE' === $existing['status'] ) {
				return $plans_map[ $plan_hash ];
			}
			// Plan no longer valid at PayPal — remove from map.
			unset( $plans_map[ $plan_hash ] );
		}

		// Build billing cycles.
		$billing_cycles = array();
		$sequence       = 1;

		// Trial cycle.
		if ( $trial_lim > 0 && (float) $trial_amt >= 0 ) {
			$billing_cycles[] = array(
				'frequency'      => array(
					'interval_unit'  => self::map_cycle_period( $cycle_per ),
					'interval_count' => $cycle_num,
				),
				'tenure_type'    => 'TRIAL',
				'sequence'       => $sequence++,
				'total_cycles'   => $trial_lim,
				'pricing_scheme' => array(
					'fixed_price' => array(
						'value'         => $trial_amt,
						'currency_code' => $currency,
					),
				),
			);
		}

		// Regular cycle.
		$regular_cycle = array(
			'frequency'      => array(
				'interval_unit'  => self::map_cycle_period( $cycle_per ),
				'interval_count' => $cycle_num,
			),
			'tenure_type'    => 'REGULAR',
			'sequence'       => $sequence,
			'pricing_scheme' => array(
				'fixed_price' => array(
					'value'         => $recurring,
					'currency_code' => $currency,
				),
			),
		);
		if ( $bill_lim > 0 ) {
			$regular_cycle['total_cycles'] = $bill_lim;
		} else {
			$regular_cycle['total_cycles'] = 0; // Infinite.
		}
		$billing_cycles[] = $regular_cycle;

		// Setup fee (when initial payment differs from recurring).
		$setup_fee = array(
			'value'         => '0.00',
			'currency_code' => $currency,
		);
		if ( (float) $initial > 0 && abs( (float) $initial - (float) $recurring ) > 0.01 ) {
			$setup_fee = array(
				'value'         => $initial,
				'currency_code' => $currency,
			);
		}

		// Plan description.
		$plan_name = substr( $level->name . ' - ' . get_bloginfo( 'name' ), 0, 127 );

		$plan_args = array(
			'product_id'          => $product_id,
			'name'                => $plan_name,
			'billing_cycles'      => $billing_cycles,
			'payment_preferences' => array(
				'auto_bill_outstanding'     => true,
				'setup_fee'                 => $setup_fee,
				'setup_fee_failure_action'  => 'CANCEL',
				'payment_failure_threshold' => 1,
			),
		);

		/**
		 * Filter the PayPal plan args before creation.
		 *
		 * @param array $plan_args PayPal plan arguments.
		 * @param object $level PMPro level object.
		 */
		$plan_args = apply_filters( 'pmpro_paypal_create_plan_args', $plan_args, $level );

		$result = $api->create_plan( $plan_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['id'] ) ) {
			return new WP_Error( 'pmpro_paypal_plan_error', __( 'Could not create PayPal plan.', 'pmpro-paypal' ) );
		}

		// Store in level meta.
		$plans_map[ $plan_hash ] = $result['id'];
		update_pmpro_membership_level_meta( $level->id, $meta_key, $plans_map );

		return $result['id'];
	}

	/**
	 * Get or create a PayPal catalog product for a membership level.
	 *
	 * @param object $level PMPro level object.
	 * @return string|WP_Error Product ID or error.
	 */
	public static function get_or_create_product( $level ) {
		$environment = get_option( 'pmpro_gateway_environment' );
		$meta_key    = 'paypal_product_id' . ( 'sandbox' === $environment ? '_sandbox' : '' );
		$product_id  = get_pmpro_membership_level_meta( $level->id, $meta_key, true );

		$api = new PMPro_PayPal_API();

		// Verify product still exists at PayPal.
		if ( ! empty( $product_id ) ) {
			$existing = $api->get_product( $product_id );
			if ( ! is_wp_error( $existing ) && ! empty( $existing['id'] ) ) {
				return $product_id;
			}
			// Product gone — fall through to create.
		}

		$product_args = array(
			'name'        => substr( $level->name, 0, 127 ),
			'type'        => 'SERVICE',
			'category'    => 'SOFTWARE',
			'description' => substr( __( 'Membership level at ', 'pmpro-paypal' ) . get_bloginfo( 'name' ), 0, 256 ),
		);

		/**
		 * Filter the PayPal product args before creation.
		 *
		 * @param array $product_args PayPal product arguments.
		 * @param object $level PMPro level object.
		 */
		$product_args = apply_filters( 'pmpro_paypal_create_product_args', $product_args, $level );

		$result = $api->create_product( $product_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['id'] ) ) {
			return new WP_Error( 'pmpro_paypal_product_error', __( 'Could not create PayPal product.', 'pmpro-paypal' ) );
		}

		update_pmpro_membership_level_meta( $level->id, $meta_key, $result['id'] );

		return $result['id'];
	}

	/**
	 * Map PMPro cycle period to PayPal interval unit.
	 *
	 * @param string $period PMPro period (Day, Week, Month, Year).
	 * @return string PayPal interval unit.
	 */
	private static function map_cycle_period( $period ) {
		$map = array(
			'DAY'   => 'DAY',
			'WEEK'  => 'WEEK',
			'MONTH' => 'MONTH',
			'YEAR'  => 'YEAR',
		);
		$period = strtoupper( $period );
		return $map[ $period ] ?? 'MONTH';
	}

	// ---------------------------------------------------------------
	// Process (checkout — offsite redirect)
	// ---------------------------------------------------------------

	/**
	 * Process checkout payment via offsite redirect to PayPal.
	 *
	 * The form submits first (user + order created via standard PMPro flow),
	 * then we redirect to PayPal for approval. Webhook completes checkout
	 * via pmpro_complete_async_checkout().
	 *
	 * @param MemberOrder $order The order to process.
	 * @return bool Always false (checkout completed async via webhook).
	 */
	public function process( &$order ) {
		// Free level — no payment needed.
		if ( (float) $order->InitialPayment <= 0 && ! pmpro_isLevelRecurring( $order->membership_level ) ) {
			$order->status = 'success';
			return true;
		}

		// Prepare for offsite async payment.
		$order->status = 'token';
		$order->saveOrder();
		pmpro_save_checkout_data_to_order( $order );

		$api   = new PMPro_PayPal_API();
		$level = $order->getMembershipLevelAtCheckout();

		global $pmpro_currency;
		$currency = ! empty( $pmpro_currency ) ? $pmpro_currency : 'USD';

		// Calculate initial payment amount with tax.
		$initial_subtotal       = $order->subtotal;
		$initial_tax            = $order->getTaxForPrice( $initial_subtotal );
		$initial_payment_amount = pmpro_round_price( (float) $initial_subtotal + (float) $initial_tax );

		if ( pmpro_isLevelRecurring( $level ) ) {
			// --- Recurring subscription flow ---

			// Calculate recurring amount with tax.
			$recurring_subtotal       = $level->billing_amount;
			$recurring_tax            = $order->getTaxForPrice( $recurring_subtotal );
			$recurring_payment_amount = pmpro_round_price( (float) $recurring_subtotal + (float) $recurring_tax );

			// Build a level clone with tax-inclusive amounts for plan creation.
			$plan_level = clone $level;
			$plan_level->initial_payment = $initial_payment_amount;
			$plan_level->billing_amount  = $recurring_payment_amount;
			if ( ! empty( $plan_level->trial_amount ) ) {
				$trial_tax = $order->getTaxForPrice( $plan_level->trial_amount );
				$plan_level->trial_amount = pmpro_round_price( (float) $plan_level->trial_amount + (float) $trial_tax );
			}

			// Get or create PayPal product and plan.
			$plan_id = self::get_or_create_plan( $plan_level, $currency );
			if ( is_wp_error( $plan_id ) ) {
				$order->error      = $plan_id->get_error_message();
				$order->shorterror = $plan_id->get_error_message();
				return false;
			}

			// Calculate profile start date (applies pmpro_set_profile_date filter).
			$profile_start_date = pmpro_calculate_profile_start_date( $order, 'c' );

			$subscription_args = array(
				'plan_id'    => $plan_id,
				'start_time' => $profile_start_date,
				'subscriber' => array(
					'email_address' => $order->Email,
				),
				'application_context' => array(
					'brand_name'          => get_bloginfo( 'name' ),
					'shipping_preference' => 'NO_SHIPPING',
					'user_action'         => 'SUBSCRIBE_NOW',
					'return_url'          => apply_filters( 'pmpro_confirmation_url', add_query_arg( 'pmpro_level', $level->id, pmpro_url( 'confirmation' ) ), $order->user_id, $level ),
					'cancel_url'          => add_query_arg( 'pmpro_level', $level->id, pmpro_url( 'checkout' ) ),
				),
			);

			/**
			 * Filter the PayPal subscription args before creation.
			 *
			 * @param array  $subscription_args PayPal subscription arguments.
			 * @param object $level             PMPro level object.
			 */
			$subscription_args = apply_filters( 'pmpro_paypal_create_subscription_args', $subscription_args, $level );

			$result = $api->create_subscription( $subscription_args );
			if ( is_wp_error( $result ) ) {
				$order->error      = $result->get_error_message();
				$order->shorterror = $result->get_error_message();
				return false;
			}

			// Save subscription ID to order.
			$order->subscription_transaction_id = $result['id'];
			$order->saveOrder();

			// Find approve link and redirect.
			$links = $result['links'] ?? array();
			foreach ( $links as $link ) {
				if ( 'approve' === ( $link['rel'] ?? '' ) ) {
					wp_redirect( $link['href'] );
					exit;
				}
			}

			$order->error      = __( 'Could not find PayPal approval link.', 'pmpro-paypal' );
			$order->shorterror = $order->error;
			return false;

		} else {
			// --- One-time payment flow ---

			$order_args = array(
				'intent'         => 'CAPTURE',
				'purchase_units' => array(
					array(
						'amount' => array(
							'currency_code' => $currency,
							'value'         => (string) $initial_payment_amount,
						),
						'description' => substr( $level->name, 0, 127 ),
					),
				),
				'payment_source' => array(
					'paypal' => array(
						'experience_context' => array(
							'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
							'shipping_preference'       => 'NO_SHIPPING',
							'user_action'               => 'PAY_NOW',
							'return_url'                => apply_filters( 'pmpro_confirmation_url', add_query_arg( 'pmpro_level', $level->id, pmpro_url( 'confirmation' ) ), $order->user_id, $level ),
							'cancel_url'                => add_query_arg( 'pmpro_level', $level->id, pmpro_url( 'checkout' ) ),
						),
					),
				),
			);

			/**
			 * Filter the PayPal order args before creation.
			 *
			 * @param array  $order_args PayPal order arguments.
			 * @param object $level      PMPro level object.
			 */
			$order_args = apply_filters( 'pmpro_paypal_create_order_args', $order_args, $level );

			$result = $api->create_order( $order_args );
			if ( is_wp_error( $result ) ) {
				$order->error      = $result->get_error_message();
				$order->shorterror = $result->get_error_message();
				return false;
			}

			// Save PayPal order ID to order meta.
			update_pmpro_membership_order_meta( $order->id, 'paypal_order_id', $result['id'] );

			// Find payer-action or approve link and redirect.
			$links = $result['links'] ?? array();
			foreach ( $links as $link ) {
				if ( in_array( $link['rel'] ?? '', array( 'payer-action', 'approve' ), true ) ) {
					wp_redirect( $link['href'] );
					exit;
				}
			}

			$order->error      = __( 'Could not find PayPal approval link.', 'pmpro-paypal' );
			$order->shorterror = $order->error;
			return false;
		}
	}

	// ---------------------------------------------------------------
	// Check Token Orders (async checkout completion)
	// ---------------------------------------------------------------

	/**
	 * Check whether the payment for a token order has been completed at PayPal.
	 *
	 * Called by PMPro core when the user returns from PayPal before the
	 * webhook fires. Polls PayPal to check order/subscription status
	 * and completes checkout if ready.
	 *
	 * @param MemberOrder $order The token order to check.
	 * @return true|string True on success, error message string on failure.
	 */
	public function check_token_order( $order ) {
		if ( 'token' !== $order->status ) {
			return __( 'This is not a token order.', 'pmpro-paypal' );
		}

		$api = new PMPro_PayPal_API();

		// Check for one-time payment via PayPal order ID in meta.
		$paypal_order_id = get_pmpro_membership_order_meta( $order->id, 'paypal_order_id', true );

		if ( empty( $paypal_order_id ) && empty( $order->subscription_transaction_id ) ) {
			return __( 'No PayPal order ID or subscription transaction ID found.', 'pmpro-paypal' );
		}

		if ( ! empty( $paypal_order_id ) ) {
			// --- One-time payment ---

			$paypal_order = $api->get_order( $paypal_order_id );
			if ( is_wp_error( $paypal_order ) ) {
				return __( 'Could not get order information.', 'pmpro-paypal' ) . ' ' . $paypal_order->get_error_message();
			}

			// If approved but not captured, capture it.
			if ( 'APPROVED' === ( $paypal_order['status'] ?? '' ) ) {
				$capture_result = $api->capture_order( $paypal_order_id );
				if ( is_wp_error( $capture_result ) ) {
					return __( 'Could not capture payment.', 'pmpro-paypal' ) . ' ' . $capture_result->get_error_message();
				}
				$paypal_order = $capture_result;
			}

			if ( 'COMPLETED' !== ( $paypal_order['status'] ?? '' ) ) {
				return __( 'Order is not yet completed.', 'pmpro-paypal' );
			}

			// Set payment transaction ID from capture.
			if ( ! empty( $paypal_order['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
				$order->payment_transaction_id = $paypal_order['purchase_units'][0]['payments']['captures'][0]['id'];
			}
		} else {
			// --- Subscription ---

			$result = $api->get_subscription( $order->subscription_transaction_id );
			if ( is_wp_error( $result ) ) {
				return __( 'Could not get subscription information.', 'pmpro-paypal' ) . ' ' . $result->get_error_message();
			}

			if ( 'ACTIVE' !== ( $result['status'] ?? '' ) ) {
				return __( 'Subscription is not yet active.', 'pmpro-paypal' );
			}

			// Try to get the initial payment transaction ID.
			$create_time = $result['create_time'] ?? '';
			if ( ! empty( $create_time ) ) {
				$start_time   = date( 'c', strtotime( $create_time ) - 3600 );
				$end_time     = date( 'c', strtotime( $create_time ) + 3600 );
				$transactions = $api->get_subscription_transactions( $order->subscription_transaction_id, $start_time, $end_time );
				if ( ! is_wp_error( $transactions ) && ! empty( $transactions['transactions'][0]['id'] ) ) {
					$order->payment_transaction_id = $transactions['transactions'][0]['id'];
				}
			}
		}

		// Complete the checkout.
		pmpro_pull_checkout_data_from_order( $order );
		return pmpro_complete_async_checkout( $order );
	}

	// ---------------------------------------------------------------
	// Cancel Subscription
	// ---------------------------------------------------------------

	/**
	 * Cancel a subscription at the PayPal gateway.
	 *
	 * @param PMPro_Subscription $subscription The subscription object.
	 * @return bool True on success.
	 */
	public function cancel_subscription( $subscription ) {
		$api = new PMPro_PayPal_API();
		$sub_id = $subscription->get_subscription_transaction_id();

		if ( empty( $sub_id ) ) {
			return false;
		}

		$result = $api->cancel_subscription( $sub_id, __( 'Cancelled by site admin or member.', 'pmpro-paypal' ) );

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			// Treat "already cancelled" as success.
			if ( strpos( strtolower( $error_message ), 'already' ) !== false
				|| strpos( strtolower( $error_message ), 'cancel' ) !== false ) {
				return true;
			}
			return false;
		}

		return true;
	}

	// ---------------------------------------------------------------
	// Update Subscription Info (Sync)
	// ---------------------------------------------------------------

	/**
	 * Sync subscription info from PayPal.
	 *
	 * @param PMPro_Subscription $subscription The subscription object.
	 * @return string|null Error message or null on success.
	 */
	public function update_subscription_info( $subscription ) {
		$api    = new PMPro_PayPal_API();
		$sub_id = $subscription->get_subscription_transaction_id();

		if ( empty( $sub_id ) ) {
			return __( 'No subscription transaction ID.', 'pmpro-paypal' );
		}

		$result = $api->get_subscription( $sub_id );
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		$update_array = array();

		// Map status.
		$paypal_status = $result['status'] ?? '';
		if ( in_array( $paypal_status, array( 'ACTIVE', 'APPROVED' ), true ) ) {
			$update_array['status'] = 'active';
		} else {
			$update_array['status'] = 'cancelled';
		}

		// Next payment date.
		if ( ! empty( $result['billing_info']['next_billing_time'] ) ) {
			$update_array['next_payment_date'] = date( 'Y-m-d H:i:s', strtotime( $result['billing_info']['next_billing_time'] ) );
		}

		// Billing amount from plan info.
		if ( ! empty( $result['billing_info']['last_payment']['amount']['value'] ) ) {
			$update_array['billing_amount'] = $result['billing_info']['last_payment']['amount']['value'];
		}

		// Cycle info from plan definition.
		if ( ! empty( $result['plan_id'] ) ) {
			$plan = $api->get_plan( $result['plan_id'] );
			if ( ! is_wp_error( $plan ) && ! empty( $plan['billing_cycles'] ) ) {
				foreach ( $plan['billing_cycles'] as $cycle ) {
					if ( 'REGULAR' === ( $cycle['tenure_type'] ?? '' ) ) {
						$update_array['cycle_number'] = $cycle['frequency']['interval_count'] ?? 1;
						$period = $cycle['frequency']['interval_unit'] ?? 'MONTH';
						$update_array['cycle_period'] = ucfirst( strtolower( $period ) );
						break;
					}
				}
			}
		}

		if ( ! empty( $update_array ) ) {
			$subscription->set( $update_array );
		}

		return null;
	}

	// ---------------------------------------------------------------
	// Refund
	// ---------------------------------------------------------------

	/**
	 * Process a refund via PayPal.
	 *
	 * @param bool $refunded Whether the refund was already processed.
	 * @param MemberOrder $order The order to refund.
	 * @return bool True on success.
	 */
	public static function process_refund( $refunded, $order ) {
		if ( $refunded ) {
			return $refunded;
		}

		$api = new PMPro_PayPal_API();

		// Get the capture ID from order meta.
		$capture_id = '';
		if ( method_exists( $order, 'get_order_meta' ) ) {
			$capture_id = $order->get_order_meta( 'paypal_capture_id', true );
		}

		// Fallback: for subscription payments, use payment_transaction_id.
		if ( empty( $capture_id ) && ! empty( $order->payment_transaction_id ) ) {
			$capture_id = $order->payment_transaction_id;
		}

		if ( empty( $capture_id ) ) {
			$order->error = __( 'No capture ID found for this order.', 'pmpro-paypal' );
			return false;
		}

		// Determine if this is a subscription payment (PAYMENT.SALE) or one-time (PAYMENT.CAPTURE).
		// Subscription payments use sale IDs (start with a number), captures use capture IDs.
		// Try refund_capture first, fall back to refund_sale if it fails.
		$result = $api->refund_capture( $capture_id );

		if ( is_wp_error( $result ) ) {
			$order->error = $result->get_error_message();
			return false;
		}

		$order->status = 'refunded';

		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( sprintf(
				__( 'Refund processed successfully. PayPal refund ID: %s', 'pmpro-paypal' ),
				$result['id'] ?? 'N/A'
			) );
		}

		$order->saveOrder();

		// Send refund emails.
		$user = get_userdata( $order->user_id );
		if ( $user ) {
			$pmproemail = new PMProEmail();
			$pmproemail->sendRefundedEmail( $user, $order );

			$pmproemail = new PMProEmail();
			$pmproemail->sendRefundedAdminEmail( $user, $order );
		}

		return true;
	}
}
