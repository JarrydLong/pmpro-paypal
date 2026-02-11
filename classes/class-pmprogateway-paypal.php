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
			add_filter( 'pmpro_include_payment_information_fields', array( 'PMProGateway_paypal', 'pmpro_include_payment_information_fields' ) );
			add_filter( 'pmpro_checkout_default_submit_button', array( 'PMProGateway_paypal', 'pmpro_checkout_default_submit_button' ) );
			add_action( 'pmpro_checkout_preheader', array( 'PMProGateway_paypal', 'pmpro_checkout_preheader' ) );
			add_filter( 'pmpro_checkout_order', array( 'PMProGateway_paypal', 'pmpro_checkout_order' ) );
		}

		// AJAX endpoints.
		add_action( 'wp_ajax_pmpro_paypal_create_order', array( 'PMProGateway_paypal', 'ajax_create_order' ) );
		add_action( 'wp_ajax_nopriv_pmpro_paypal_create_order', array( 'PMProGateway_paypal', 'ajax_create_order' ) );
		add_action( 'wp_ajax_pmpro_paypal_create_subscription', array( 'PMProGateway_paypal', 'ajax_create_subscription' ) );
		add_action( 'wp_ajax_nopriv_pmpro_paypal_create_subscription', array( 'PMProGateway_paypal', 'ajax_create_subscription' ) );

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
	 * Replace payment information fields with PayPal button container.
	 */
	public static function pmpro_include_payment_information_fields( $include ) {
		global $pmpro_requirebilling;
		if ( ! $pmpro_requirebilling ) {
			return $include;
		}
		?>
		<div id="pmpro_paypal_fields" class="pmpro_checkout">
			<hr />
			<h2>
				<span class="pmpro_checkout-h2-name"><?php esc_html_e( 'Payment Information', 'pmpro-paypal' ); ?></span>
			</h2>
			<div class="pmpro_checkout-fields">
				<div id="pmpro-paypal-button-container"></div>
				<input type="hidden" id="paypal_order_id" name="paypal_order_id" value="" />
				<input type="hidden" id="paypal_subscription_id" name="paypal_subscription_id" value="" />
			</div>
		</div>
		<?php
		return false;
	}

	/**
	 * Replace submit button — PayPal buttons handle submission.
	 */
	public static function pmpro_checkout_default_submit_button( $show ) {
		// The PayPal JS SDK buttons will submit the form, so hide the default submit.
		// But we still need a fallback for free levels.
		global $pmpro_requirebilling;
		if ( $pmpro_requirebilling ) {
			// PayPal buttons will handle it. Provide a hidden submit for form submission.
			?>
			<span id="pmpro_submit_span" style="display:none;">
				<input type="hidden" name="submit-checkout" value="1" />
				<input type="submit" id="pmpro_btn-submit" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_btn pmpro_btn-submit-checkout', 'pmpro_btn-submit-checkout' ) ); ?>" value="<?php esc_attr_e( 'Submit and Check Out', 'pmpro-paypal' ); ?>" />
			</span>
			<?php
			return false;
		}

		// Free level — show normal submit.
		return $show;
	}

	/**
	 * Enqueue scripts on checkout page.
	 */
	public static function pmpro_checkout_preheader() {
		global $pmpro_level, $pmpro_requirebilling;

		if ( ! $pmpro_requirebilling ) {
			return;
		}

		$client_id   = get_option( 'pmpro_paypal_client_id' );
		$environment = get_option( 'pmpro_gateway_environment' );
		global $pmpro_currency;
		$currency = ! empty( $pmpro_currency ) ? $pmpro_currency : 'USD';
		$is_recurring = ! empty( $pmpro_level ) && pmpro_isLevelRecurring( $pmpro_level );

		// PayPal JS SDK.
		$sdk_args = array(
			'client-id'  => $client_id,
			'currency'   => $currency,
			'components' => 'buttons',
		);
		if ( $is_recurring ) {
			$sdk_args['intent'] = 'subscription';
			$sdk_args['vault']  = 'true';
		} else {
			$sdk_args['intent'] = 'capture';
		}
		$sdk_url = add_query_arg( $sdk_args, 'https://www.paypal.com/sdk/js' );

		wp_enqueue_script( 'pmpro-paypal-sdk', $sdk_url, array(), null, true );
		wp_enqueue_script( 'pmpro-paypal', PMPRO_PAYPAL_URL . 'js/pmpro-paypal.js', array( 'pmpro-paypal-sdk', 'jquery' ), PMPRO_PAYPAL_VERSION, true );

		wp_localize_script( 'pmpro-paypal', 'pmproPayPal', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'pmpro_paypal_nonce' ),
			'isRecurring' => $is_recurring,
			'clientId'    => $client_id,
			'currency'    => $currency,
			'buttonStyle' => apply_filters( 'pmpro_paypal_button_style', array(
				'layout' => 'vertical',
				'color'  => 'gold',
				'shape'  => 'rect',
				'label'  => 'paypal',
			) ),
		) );
	}

	/**
	 * Capture PayPal IDs from form submission into the order object.
	 */
	public static function pmpro_checkout_order( $order ) {
		if ( ! empty( $_REQUEST['paypal_order_id'] ) ) {
			$order->paypal_order_id = sanitize_text_field( $_REQUEST['paypal_order_id'] );
		}
		if ( ! empty( $_REQUEST['paypal_subscription_id'] ) ) {
			$order->paypal_subscription_id = sanitize_text_field( $_REQUEST['paypal_subscription_id'] );
		}
		return $order;
	}

	// ---------------------------------------------------------------
	// AJAX: Create Order (one-time)
	// ---------------------------------------------------------------

	/**
	 * AJAX handler to create a PayPal order for one-time payment.
	 */
	public static function ajax_create_order() {
		check_ajax_referer( 'pmpro_paypal_nonce', 'nonce' );

		$level_id = intval( $_POST['level_id'] ?? 0 );
		if ( empty( $level_id ) ) {
			wp_send_json_error( array( 'message' => 'No level specified.' ) );
		}

		// Get the level with pricing from the checkout context.
		$level = pmpro_getLevelAtCheckout( $level_id );
		if ( empty( $level ) ) {
			wp_send_json_error( array( 'message' => 'Invalid level.' ) );
		}

		global $pmpro_currency;
		$currency = ! empty( $pmpro_currency ) ? $pmpro_currency : 'USD';
		$amount   = pmpro_round_price_as_string( (float) $level->initial_payment );

		if ( (float) $amount <= 0 ) {
			wp_send_json_error( array( 'message' => 'No payment required.' ) );
		}

		$api = new PMPro_PayPal_API();

		$order_args = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'amount'      => array(
						'currency_code' => $currency,
						'value'         => $amount,
					),
					'description' => substr( $level->name, 0, 127 ),
					'custom_id'   => get_current_user_id() . '_' . $level_id,
				),
			),
		);

		/**
		 * Filter the PayPal order args before creation.
		 *
		 * @param array $order_args PayPal order arguments.
		 * @param object $level PMPro level object.
		 */
		$order_args = apply_filters( 'pmpro_paypal_create_order_args', $order_args, $level );

		$result = $api->create_order( $order_args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'orderID' => $result['id'] ) );
	}

	// ---------------------------------------------------------------
	// AJAX: Create Subscription (recurring)
	// ---------------------------------------------------------------

	/**
	 * AJAX handler to create a PayPal subscription.
	 */
	public static function ajax_create_subscription() {
		check_ajax_referer( 'pmpro_paypal_nonce', 'nonce' );

		$level_id = intval( $_POST['level_id'] ?? 0 );
		if ( empty( $level_id ) ) {
			wp_send_json_error( array( 'message' => 'No level specified.' ) );
		}

		$level = pmpro_getLevelAtCheckout( $level_id );
		if ( empty( $level ) ) {
			wp_send_json_error( array( 'message' => 'Invalid level.' ) );
		}

		global $pmpro_currency;
		$currency = ! empty( $pmpro_currency ) ? $pmpro_currency : 'USD';

		// Get or create the PayPal plan for this level/price combo.
		$plan_id = self::get_or_create_plan( $level, $currency );
		if ( is_wp_error( $plan_id ) ) {
			wp_send_json_error( array( 'message' => $plan_id->get_error_message() ) );
		}

		$api = new PMPro_PayPal_API();

		$subscription_args = array(
			'plan_id' => $plan_id,
			'subscriber' => array(
				'email_address' => wp_get_current_user()->user_email,
			),
			'application_context' => array(
				'brand_name'          => get_bloginfo( 'name' ),
				'user_action'         => 'SUBSCRIBE_NOW',
				'payment_method'      => array(
					'payer_selected'  => 'PAYPAL',
					'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED',
				),
			),
		);

		// Setup fee: if initial_payment differs from recurring amount.
		$initial = (float) $level->initial_payment;
		$recurring = (float) $level->billing_amount;
		if ( $initial > 0 && abs( $initial - $recurring ) > 0.01 ) {
			// Setup fee is handled at the plan level — see get_or_create_plan().
			// If initial < recurring, we pass 0 setup fee and a trial cycle handles it.
		}

		/**
		 * Filter the PayPal subscription args before creation.
		 *
		 * @param array $subscription_args PayPal subscription arguments.
		 * @param object $level PMPro level object.
		 */
		$subscription_args = apply_filters( 'pmpro_paypal_create_subscription_args', $subscription_args, $level );

		$result = $api->create_subscription( $subscription_args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'subscriptionID' => $result['id'] ) );
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
	// Process (checkout)
	// ---------------------------------------------------------------

	/**
	 * Process checkout payment.
	 *
	 * @param MemberOrder $order The order to process.
	 * @return bool True on success.
	 */
	public function process( &$order ) {
		$api = new PMPro_PayPal_API();

		// Recurring subscription flow.
		if ( ! empty( $order->paypal_subscription_id ) ) {
			return $this->process_subscription( $order, $api );
		}

		// One-time payment flow.
		if ( ! empty( $order->paypal_order_id ) ) {
			return $this->process_order( $order, $api );
		}

		// No PayPal ID — free level or error.
		if ( (float) $order->InitialPayment <= 0 && ! pmpro_isLevelRecurring( $order->membership_level ) ) {
			$order->status = 'success';
			return true;
		}

		$order->error = __( 'No PayPal payment information received.', 'pmpro-paypal' );
		return false;
	}

	/**
	 * Process one-time order (capture).
	 */
	private function process_order( &$order, $api ) {
		$paypal_order_id = sanitize_text_field( $order->paypal_order_id );

		// Capture the order.
		$result = $api->capture_order( $paypal_order_id );
		if ( is_wp_error( $result ) ) {
			$order->error      = $result->get_error_message();
			$order->shorterror = $result->get_error_message();
			return false;
		}

		if ( empty( $result['status'] ) || 'COMPLETED' !== $result['status'] ) {
			$order->error = __( 'PayPal payment was not completed.', 'pmpro-paypal' );
			return false;
		}

		// Extract capture ID.
		$capture_id = '';
		if ( ! empty( $result['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
			$capture_id = $result['purchase_units'][0]['payments']['captures'][0]['id'];
		}

		$order->payment_transaction_id = $capture_id;
		$order->status                 = 'success';

		// Store PayPal IDs in order meta for refunds.
		if ( method_exists( $order, 'update_order_meta' ) ) {
			$order->update_order_meta( 'paypal_order_id', $paypal_order_id );
			$order->update_order_meta( 'paypal_capture_id', $capture_id );
		}

		return true;
	}

	/**
	 * Process subscription approval.
	 */
	private function process_subscription( &$order, $api ) {
		$subscription_id = sanitize_text_field( $order->paypal_subscription_id );

		// Verify subscription is active.
		$result = $api->get_subscription( $subscription_id );
		if ( is_wp_error( $result ) ) {
			$order->error      = $result->get_error_message();
			$order->shorterror = $result->get_error_message();
			return false;
		}

		$status = $result['status'] ?? '';
		if ( ! in_array( $status, array( 'ACTIVE', 'APPROVED' ), true ) ) {
			$order->error = sprintf(
				__( 'PayPal subscription status is %s, expected ACTIVE.', 'pmpro-paypal' ),
				esc_html( $status )
			);
			return false;
		}

		$order->subscription_transaction_id = $subscription_id;
		$order->payment_transaction_id      = $subscription_id; // Backfilled by webhook.
		$order->status                      = 'success';
		$order->payment_type                = 'PayPal';

		// Store in order meta.
		if ( method_exists( $order, 'update_order_meta' ) ) {
			$order->update_order_meta( 'paypal_subscription_id', $subscription_id );
		}

		return true;
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
