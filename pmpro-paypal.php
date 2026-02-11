<?php
/**
 * Plugin Name: PayPal for Paid Memberships Pro
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-paypal/
 * Description: Modern PayPal integration using Orders V2 and Subscriptions API v1 with Smart Payment Buttons.
 * Version: 1.0
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * Text Domain: pmpro-paypal
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'PMPRO_PAYPAL_VERSION', '1.0' );
define( 'PMPRO_PAYPAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMPRO_PAYPAL_URL', plugin_dir_url( __FILE__ ) );
define( 'PMPRO_PAYPAL_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Bootstrap the plugin after PMPro is loaded.
 */
function pmpro_paypal_init() {
	// Gate on PMPro being active.
	if ( ! defined( 'PMPRO_DIR' ) ) {
		return;
	}

	// Load classes.
	require_once PMPRO_PAYPAL_DIR . 'classes/class-paypal-api.php';
	require_once PMPRO_PAYPAL_DIR . 'classes/class-pmprogateway-paypal.php';

	// Register webhook REST route.
	add_action( 'rest_api_init', 'pmpro_paypal_register_webhook_route' );
}
add_action( 'plugins_loaded', 'pmpro_paypal_init', 20 );

/**
 * Register webhook REST route.
 */
function pmpro_paypal_register_webhook_route() {
	register_rest_route( 'pmpro-paypal/v1', '/webhook', array(
		'methods'             => 'POST',
		'callback'            => 'pmpro_paypal_webhook_callback',
		'permission_callback' => '__return_true',
	) );
}

/**
 * Webhook callback — delegates to the handler.
 */
function pmpro_paypal_webhook_callback( $request ) {
	require_once PMPRO_PAYPAL_DIR . 'includes/webhook-handler.php';
	return pmpro_paypal_handle_webhook( $request );
}

/**
 * Set gateway_ready based on credentials.
 *
 * PMPro core doesn't know about add-on gateways, so the gateway_ready
 * global defaults to false. We hook pmpro_is_ready to set it.
 */
function pmpro_paypal_gateway_ready( $r ) {
	global $pmpro_gateway_ready;
	$gateway = pmpro_getGateway();
	if ( 'paypal' === $gateway ) {
		$environment = get_option( 'pmpro_gateway_environment' );
		$client_id   = get_option( 'pmpro_paypal_client_id' );
		$secret      = get_option( 'pmpro_paypal_client_secret' );
		$pmpro_gateway_ready = ! empty( $environment ) && ! empty( $client_id ) && ! empty( $secret );
		if ( $pmpro_gateway_ready ) {
			$r = true;
		}
	}
	return $r;
}
add_filter( 'pmpro_is_ready', 'pmpro_paypal_gateway_ready' );
