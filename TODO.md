# pmpro-paypal — Next Steps

Status: v1.0 built with offsite redirect checkout. Pushed for David Parker's review (Feb 12, 2026). No end-to-end payment tests completed yet.

---

## Testing

### Sandbox Checkout Testing
- [ ] **Test one-time payment checkout.** Complete a full checkout for a non-recurring level. Verify redirect to PayPal, approval, redirect back, and checkout completion (via webhook or token order fallback). Confirm `payment_transaction_id` (capture ID), `paypal_order_id`, and `paypal_capture_id` are recorded in order meta.
- [ ] **Test recurring subscription checkout.** Complete a checkout for a recurring level. Verify Product and Plan are lazily created at PayPal, subscription is created, redirect works, and checkout completes. Check that `subscription_transaction_id` is set and Product/Plan IDs are stored in level meta for reuse.
- [ ] **Test cancelled checkout.** Click "Check Out with PayPal", then cancel at PayPal's approval page. Verify user is returned to the checkout page and the token order remains in `token` status.
- [ ] **Test failed checkouts.** Use a declined sandbox buyer account. Verify error handling and that the checkout page remains usable.
- [ ] **Test discount codes.** Apply a discount code that changes the price and verify a new Plan is created for the adjusted amount (Plans are keyed by price hash). Test codes that make the level free — should fall through to the standard submit button.
- [ ] **Test tax-inclusive amounts.** Verify that tax amounts from `$order->getTaxForPrice()` are correctly included in both one-time PayPal orders and subscription plan pricing.
- [ ] **Test profile start date.** Verify that `pmpro_calculate_profile_start_date()` is called for subscriptions and the resulting `start_time` is correctly passed to PayPal.

### Webhook Testing
- [ ] **Test CHECKOUT.ORDER.APPROVED webhook.** Complete a one-time checkout and verify the webhook captures the order and completes checkout via `pmpro_complete_async_checkout()`.
- [ ] **Test BILLING.SUBSCRIPTION.ACTIVATED webhook.** Complete a subscription checkout and verify the webhook finds the initial transaction and completes checkout.
- [ ] **Test token order fallback.** Verify that when the user returns from PayPal before the webhook fires, `check_token_order()` polls PayPal and completes checkout. Test both one-time and subscription paths.
- [ ] **Test recurring payment webhooks.** Trigger (or wait for) a `PAYMENT.SALE.COMPLETED` event from a sandbox subscription renewal. Verify `pmpro_handle_recurring_payment_succeeded_at_gateway()` creates a renewal order.
- [ ] **Test cancellation webhooks.** Cancel a subscription from the PayPal dashboard. Verify `BILLING.SUBSCRIPTION.CANCELLED` webhook fires and updates PMPro subscription status.
- [ ] **Test payment failure webhooks.** Simulate a `BILLING.SUBSCRIPTION.PAYMENT.FAILED` event. Verify failure handler is called.
- [ ] **Test refund webhooks.** Refund a payment from PayPal dashboard. Verify order is marked refunded and emails are sent.
- [ ] **Test webhook signature verification.** Confirm verification works in live mode (currently bypassed in sandbox when no webhook_id is set).

### Cancellation and Refund Testing
- [ ] **Test cancellation from PMPro admin.** Cancel a subscription via PMPro admin and verify the PayPal subscription is cancelled via API.
- [ ] **Test cancellation by member.** Verify member self-service cancellation calls `cancel_subscription()`.
- [ ] **Test refund from PMPro admin.** Process a refund via PMPro admin. Verify PayPal capture is refunded, order status updates, and emails send.
- [ ] **Test subscription sync.** Trigger `update_subscription_info()` and verify it pulls status, next_payment_date, billing_amount, and cycle info from PayPal.

### Legacy Gateway Coexistence
- [ ] **Test with WPP slug rename branch.** Run `paypal-wpp-rename` branch of PMPro core alongside this plugin. Verify old WPP gateway doesn't conflict with new `paypal` gateway.
- [ ] **Test with PayPal Express.** Verify existing `paypalexpress` subscriptions continue IPN renewals while new `paypal` gateway handles new sign-ups.

---

## Open Questions

### Existing Webhook Registrations
Adding `CHECKOUT.ORDER.APPROVED` to the webhook events array only affects new registrations. **Sites with an existing webhook will need to delete and re-register** (delete the `pmpro_paypal_webhook_id` option and save settings). Should we add an upgrade routine that detects this and re-registers automatically?

### Code Organization
**Should we move some code from `pmpro-paypal.php` into the gateway class?** Currently the main plugin file handles webhook REST route registration, `pmpro_is_ready` filter, and the webhook callback. The Stripe add-on keeps similar code in the main file, so this may be fine as-is.

---

## Resolved Questions

These were open in the original TODO and are now resolved by the offsite checkout rewrite:

- **Confirmation Page Flow** — Resolved. The form submits normally through PMPro's standard flow. No special confirmation page handling needed.
- **Checkout Page Display** — Resolved. Payment information fields are hidden (`__return_false`). A "Check Out with PayPal" submit button replaces the old Smart Buttons container.
- **User Creation Before Checkout** — Resolved. PMPro creates the user and order *before* `process()` runs, which then creates the PayPal order/subscription. This is the standard offsite redirect pattern.
- **Offsite Checkout** — Resolved. Implemented as the primary (and only) checkout method. No onsite Smart Buttons option.

---

## Bugs Fixed During Development
These are already resolved but documented for reference:

- **`pmpro_get_currency()` returns array, not string.** Fixed by using `$pmpro_currency` global. Found in 4 places (3 in gateway class, 1 in API client).
- **`is_user_logged_in()` check blocked non-logged-in users.** Both original AJAX handlers had login checks that prevented new users from checking out. No longer relevant — AJAX handlers were removed in the offsite rewrite.

---

## Future Enhancements
Items out of scope for v1.0 but worth tracking:

- [ ] **Apple Pay / Google Pay** via PayPal's additional funding sources.
- [ ] **Advanced Card Fields** — let users enter card numbers on-page, processed through PayPal (would require SCA/3DS handling).
- [ ] **PayPal Connect / OAuth onboarding** — one-click merchant setup instead of manual API credentials (similar to Stripe Connect).
- [ ] **WPP upgrade routine** — automated migration of gateway slug for sites on old Website Payments Pro.
- [ ] **Subscription migration** — evaluate whether existing PayPal Express recurring profiles can be migrated to REST API subscriptions (likely not, per David).
