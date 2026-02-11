/**
 * PayPal Smart Payment Buttons for PMPro.
 *
 * Renders PayPal buttons in the checkout form and handles
 * both one-time (Orders) and recurring (Subscriptions) flows.
 */
(function($) {
	'use strict';

	var pmproPayPalButtons = null;

	/**
	 * Initialize PayPal buttons.
	 */
	function initPayPalButtons() {
		var container = document.getElementById('pmpro-paypal-button-container');
		if (!container || typeof paypal === 'undefined') {
			return;
		}

		// Clear any existing buttons.
		container.innerHTML = '';

		var buttonConfig = {
			style: pmproPayPal.buttonStyle || {
				layout: 'vertical',
				color: 'gold',
				shape: 'rect',
				label: 'paypal'
			},

			onError: function(err) {
				console.error('PayPal button error:', err);
				alert('An error occurred with PayPal. Please try again.');
			},

			onCancel: function() {
				// User closed the PayPal popup. Nothing to do.
			}
		};

		if (pmproPayPal.isRecurring) {
			// Subscription flow.
			buttonConfig.createSubscription = function(data, actions) {
				return createSubscription();
			};
			buttonConfig.onApprove = function(data) {
				onSubscriptionApprove(data);
			};
		} else {
			// One-time payment flow.
			buttonConfig.createOrder = function(data, actions) {
				return createOrder();
			};
			buttonConfig.onApprove = function(data) {
				onOrderApprove(data);
			};
		}

		pmproPayPalButtons = paypal.Buttons(buttonConfig);

		if (pmproPayPalButtons.isEligible()) {
			pmproPayPalButtons.render('#pmpro-paypal-button-container');
		} else {
			container.innerHTML = '<p>PayPal is not available for this transaction.</p>';
		}
	}

	/**
	 * Get the current level ID from the checkout form.
	 */
	function getLevelId() {
		// Try hidden field first, then URL parameter.
		var levelInput = document.querySelector('input[name="level"]') ||
		                 document.querySelector('input[name="pmpro_level"]');
		if (levelInput) {
			return levelInput.value;
		}
		// Fallback: URL parameter.
		var params = new URLSearchParams(window.location.search);
		return params.get('level') || params.get('pmpro_level') || '';
	}

	/**
	 * Create a PayPal order (one-time payment).
	 * Returns a promise that resolves with the order ID.
	 */
	function createOrder() {
		return new Promise(function(resolve, reject) {
			$.ajax({
				url: pmproPayPal.ajaxUrl,
				method: 'POST',
				data: {
					action: 'pmpro_paypal_create_order',
					nonce: pmproPayPal.nonce,
					level_id: getLevelId()
				},
				success: function(response) {
					if (response.success && response.data.orderID) {
						resolve(response.data.orderID);
					} else {
						var msg = (response.data && response.data.message) ?
						          response.data.message : 'Could not create PayPal order.';
						reject(new Error(msg));
						alert(msg);
					}
				},
				error: function() {
					reject(new Error('Network error creating PayPal order.'));
					alert('Network error. Please try again.');
				}
			});
		});
	}

	/**
	 * Create a PayPal subscription (recurring).
	 * Returns a promise that resolves with the subscription ID.
	 */
	function createSubscription() {
		return new Promise(function(resolve, reject) {
			$.ajax({
				url: pmproPayPal.ajaxUrl,
				method: 'POST',
				data: {
					action: 'pmpro_paypal_create_subscription',
					nonce: pmproPayPal.nonce,
					level_id: getLevelId()
				},
				success: function(response) {
					if (response.success && response.data.subscriptionID) {
						resolve(response.data.subscriptionID);
					} else {
						var msg = (response.data && response.data.message) ?
						          response.data.message : 'Could not create PayPal subscription.';
						reject(new Error(msg));
						alert(msg);
					}
				},
				error: function() {
					reject(new Error('Network error creating PayPal subscription.'));
					alert('Network error. Please try again.');
				}
			});
		});
	}

	/**
	 * Handle order approval — set hidden field and submit form.
	 */
	function onOrderApprove(data) {
		$('#paypal_order_id').val(data.orderID);
		submitCheckoutForm();
	}

	/**
	 * Handle subscription approval — set hidden field and submit form.
	 */
	function onSubscriptionApprove(data) {
		$('#paypal_subscription_id').val(data.subscriptionID);
		submitCheckoutForm();
	}

	/**
	 * Submit the PMPro checkout form programmatically.
	 */
	function submitCheckoutForm() {
		var form = document.getElementById('pmpro_form');
		if (!form) {
			form = document.querySelector('form.pmpro_form');
		}
		if (!form) {
			form = $('input[name="submit-checkout"]').closest('form')[0];
		}

		if (form) {
			// Ensure the submit-checkout value is set.
			var submitInput = form.querySelector('input[name="submit-checkout"]');
			if (!submitInput) {
				submitInput = document.createElement('input');
				submitInput.type = 'hidden';
				submitInput.name = 'submit-checkout';
				submitInput.value = '1';
				form.appendChild(submitInput);
			} else {
				submitInput.value = '1';
			}

			// Show the submit span momentarily (in case PMPro checks visibility).
			$('#pmpro_submit_span').show();

			form.submit();
		}
	}

	// Initialize on DOM ready.
	$(document).ready(function() {
		// Small delay to ensure PayPal SDK is fully loaded.
		setTimeout(initPayPalButtons, 100);
	});

	// Re-initialize if PMPro triggers a level change (dynamic checkout).
	$(document).on('pmpro_checkout_level_change', function() {
		// Reload the page to get new SDK params (intent may change).
		// PayPal SDK can't switch between capture and subscription intent dynamically.
		if (typeof pmproPayPal !== 'undefined') {
			window.location.reload();
		}
	});

})(jQuery);
