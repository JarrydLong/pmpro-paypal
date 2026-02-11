# pmpro-paypal — Next Steps

Status: v1.0 built and passing basic admin/settings verification. Initial checkout page renders PayPal buttons. No end-to-end payment tests completed yet.

---

## Testing

### Sandbox Checkout Testing
- [ ] **Test one-time payment checkout.** Complete a full checkout for a non-recurring level. Verify PayPal order is created, captured, and the PMPro order records the correct `payment_transaction_id` (capture ID) and `paypal_order_id` / `paypal_capture_id` in order meta.
- [ ] **Test recurring subscription checkout.** Complete a checkout for a recurring level. Verify Product and Plan are lazily created at PayPal, subscription is created, and PMPro records the `subscription_transaction_id`. Check that the Product/Plan IDs are stored in level meta for reuse.
- [ ] **Test failed checkouts.** Cancel mid-popup, use a declined sandbox card, test with invalid credentials. Verify error messages surface correctly and the checkout page remains usable (no stuck state, no orphaned PayPal objects).
- [ ] **Test discount codes.** Apply a discount code that changes the price and verify a new Plan is created for the adjusted amount (since Plans are keyed by price hash). Test codes that make the level free — should fall through to the free-level submit button.
- [ ] **Test checkout page variations.** Verify behavior with different PMPro checkout page configurations: with/without billing fields shown by other plugins, with multiple membership levels, with custom checkout page templates.

### Webhook Testing
- [ ] **Test recurring payment webhooks.** Trigger (or wait for) a `PAYMENT.SALE.COMPLETED` event from a sandbox subscription renewal. Verify `pmpro_handle_recurring_payment_succeeded_at_gateway()` creates a renewal order in PMPro. Requires webhook URL reachable by PayPal (use ngrok or similar for local dev).
- [ ] **Test cancellation webhooks.** Cancel a subscription from the PayPal dashboard. Verify `BILLING.SUBSCRIPTION.CANCELLED` webhook fires and `pmpro_handle_subscription_cancellation_at_gateway()` updates the PMPro subscription status.
- [ ] **Test payment failure webhooks.** Simulate a `BILLING.SUBSCRIPTION.PAYMENT.FAILED` event. Verify `pmpro_handle_recurring_payment_failure_at_gateway()` is called.
- [ ] **Test refund webhooks.** Refund a payment from the PayPal dashboard. Verify the order is marked refunded in PMPro and refund emails are sent.
- [ ] **Test webhook signature verification.** Confirm that webhook verification works in live mode (currently bypassed in sandbox when no webhook_id is set).

### Cancellation and Refund Testing
- [ ] **Test cancellation from PMPro admin.** Cancel a subscription via PMPro admin and verify the PayPal subscription is cancelled via API.
- [ ] **Test cancellation by member.** If PMPro allows member self-service cancellation, verify it calls our `cancel_subscription()` method.
- [ ] **Test refund from PMPro admin.** Process a refund via PMPro's admin order interface. Verify the PayPal capture is refunded via API, order status updates, and emails send.
- [ ] **Test subscription sync.** Trigger `update_subscription_info()` and verify it correctly pulls status, next_payment_date, billing_amount, and cycle info from PayPal.

### Legacy Gateway Coexistence
- [ ] **Test with WPP slug rename branch.** Run the `paypal-wpp-rename` branch of PMPro core alongside this plugin. Verify the old WPP gateway (now `paypalwpp`) continues to function for any existing WPP sites and doesn't conflict with the new `paypal` gateway.
- [ ] **Test with PayPal Express.** Verify an existing `paypalexpress` subscription continues to process IPN renewals while the new `paypal` gateway is active for new sign-ups. Confirm no IPN/webhook cross-talk.

---

## Open Questions

### Confirmation Page Flow
PMPro recently introduced a confirmation/review step before final checkout. **Do we need to initiate PayPal payments from the new confirmation page instead of (or in addition to) the initial checkout page?** If so, the JS SDK and button rendering would need to move or be duplicated on the confirmation page, and the AJAX order/subscription creation would happen later in the flow.

### Code Organization
**Should we move some code from `pmpro-paypal.php` into the gateway class?** Currently the main plugin file handles:
- Webhook REST route registration
- The `pmpro_is_ready` filter for gateway readiness
- The webhook callback function

The Stripe add-on keeps similar code in the main file, so this may be fine as-is. But if the convention for newer add-ons has changed, we should align.

### Checkout Page Display
**Do we want to remove the "Payment Information" heading or otherwise adjust the display at checkout?** Currently we render a `<h2>Payment Information</h2>` heading above the PayPal button container. Since there are no input fields (just a button), the heading may feel out of place. Options:
- Remove the heading entirely.
- Change it to something like "Pay with PayPal".
- Keep it for visual consistency with other gateways.

### User Creation Before Checkout
**Should we update the checkout flow to create users before attempting the PayPal checkout, to match other recent PMPro gateways and support abandoned cart tracking?** Currently the user account is created after `process()` succeeds. If we create the user first:
- We could track abandoned carts (user created but payment not completed).
- The AJAX handlers could access user data (email, etc.) without relying on `wp_get_current_user()`.
- This would match how newer PMPro gateways (Stripe) handle the flow.
- Tradeoff: creates user records for people who never complete payment.

### Offsite Checkout
The current implementation is onsite only (PayPal popup via JS SDK). **Do we want to add offsite checkout as an option?** The same APIs support it — instead of rendering Smart Buttons, we'd redirect users to PayPal's approval URL and handle the return. Jason's original notes indicate a preference for offsite checkout. This could be:
- An admin setting to choose onsite vs offsite.
- Always offsite (simpler, no JS SDK dependency).
- Onsite with offsite fallback.

### Payment Request Buttons
Jason's original priorities list mentions supporting **Apple Pay, Google Pay, and other payment request buttons** through PayPal. PayPal's PPCP platform supports these as additional funding sources in the JS SDK. This would be a future enhancement — the current `components=buttons` parameter could be expanded to include these.

---

## Bugs Fixed During Initial Testing
These are already resolved but documented for reference:

- **`pmpro_get_currency()` returns array, not string.** The PayPal JS SDK URL was being built with the full currency object instead of just `USD`. Fixed by using `$pmpro_currency` global instead. Found in 4 places (3 in gateway class, 1 in API client).
- **`is_user_logged_in()` check blocked non-logged-in users.** Both AJAX handlers had an explicit login check that prevented new users from completing checkout (PMPro creates accounts after payment). Removed the check; nonce verification is sufficient.

---

## Future Enhancements
Items from the original plan and research that are out of scope for v1.0 but worth tracking:

- [ ] **Offsite checkout option** — redirect to PayPal instead of popup (see Open Questions above).
- [ ] **Apple Pay / Google Pay** via PayPal's additional funding sources.
- [ ] **Advanced Card Fields** — let users enter card numbers on-page, processed through PayPal (would require SCA/3DS handling).
- [ ] **PayPal Connect / OAuth onboarding** — one-click merchant setup instead of manual API credentials (similar to Stripe Connect).
- [ ] **WPP upgrade routine** — for sites currently using the old Website Payments Pro gateway, an automated migration of the gateway slug in the database.
- [ ] **Subscription migration** — evaluate whether existing PayPal Express recurring payment profiles can be migrated to REST API subscriptions (likely not, per David).
