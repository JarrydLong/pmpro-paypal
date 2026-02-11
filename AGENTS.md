# AGENTS.md — Paid Memberships Pro - PayPal Gateway

> Plugin: `pmpro-paypal` v1.0
> Gateway slug: `paypal`
> Namespace: none (procedural + single gateway class)
> Requires: Paid Memberships Pro (latest)

---

## 1. Plugin Structure

```
pmpro-paypal/
  pmpro-paypal.php                       # Main plugin file, bootstrap, webhook route registration
  classes/
    class-paypal-api.php                 # PayPal REST API client (all HTTP communication)
    class-pmprogateway-paypal.php        # Gateway class (extends PMProGateway)
  includes/
    webhook-handler.php                  # Webhook event processing (loaded on demand)
  js/
    pmpro-paypal.js                      # PayPal JS SDK Smart Buttons rendering + checkout flow
  readme.txt                             # WordPress plugin readme
```

No vendor dependencies, no Composer, no build step. All PayPal API communication uses `wp_remote_post()` / `wp_remote_request()` directly.

---

## 2. Architecture

**Two-API approach for payments:**

| Payment Type | API | Endpoint |
|-------------|-----|----------|
| One-time | Orders V2 | `POST /v2/checkout/orders` + capture |
| Recurring | Subscriptions API v1 | `POST /v1/billing/subscriptions` |

**Supporting APIs:**

| API | Endpoint | Purpose |
|-----|----------|---------|
| OAuth2 | `POST /v1/oauth2/token` | Authentication (client credentials) |
| Catalog Products | `POST /v1/catalogs/products` | One product per membership level |
| Billing Plans | `POST /v1/billing/plans` | One plan per unique price/cycle combo |
| Webhooks | `POST /v1/notifications/webhooks` | Auto-registration of webhook listener |
| Webhook Verify | `POST /v1/notifications/verify-webhook-signature` | Signature verification |
| Refunds | `POST /v2/payments/captures/{id}/refund` | Process refunds |

**Base URLs:**
- Sandbox: `https://api-m.sandbox.paypal.com`
- Production: `https://api-m.paypal.com`

**Partner Attribution:** `PayPal-Partner-Attribution-Id: PaidMembershipsPro_SP` sent on every API request.

**Frontend:** PayPal JS SDK Smart Payment Buttons — onsite popup checkout. The customer never leaves the checkout page. The SDK is loaded from `https://www.paypal.com/sdk/js` with parameters for client-id, currency, intent (capture or subscription), and components.

---

## 3. Key Files

### `pmpro-paypal.php` (bootstrap)

- Defines constants: `PMPRO_PAYPAL_VERSION`, `PMPRO_PAYPAL_DIR`, `PMPRO_PAYPAL_URL`, `PMPRO_PAYPAL_BASENAME`.
- Gates on `PMPRO_DIR` (PMPro must be active).
- Loads API client and gateway class on `plugins_loaded` at priority 20.
- Registers webhook REST route (`pmpro-paypal/v1/webhook`) on `rest_api_init`.
- Hooks `pmpro_is_ready` filter to set `$pmpro_gateway_ready` global. This is required because PMPro core's gateway-ready check doesn't know about add-on gateways — without this filter, the checkout page would show "gateway not configured."

### `classes/class-pmprogateway-paypal.php` (gateway class)

**Class:** `PMProGateway_paypal extends PMProGateway`

Registered via `add_action('init', ...)`. All methods are static except `process()`, `process_order()`, `process_subscription()`, `cancel_subscription()`, and `update_subscription_info()`.

#### Registration (`init()`)

Hooks registered for all gateways:
- `pmpro_gateways` — adds `'paypal' => 'PayPal'` to the gateway dropdown.
- AJAX endpoints — `wp_ajax_` and `wp_ajax_nopriv_` for both `pmpro_paypal_create_order` and `pmpro_paypal_create_subscription`. Both logged-in and non-logged-in users are supported because PMPro creates user accounts during checkout.
- `pmpro_process_refund_paypal` — refund processing hook.

Hooks registered only when `paypal` is the active gateway:
- `pmpro_required_billing_fields` — removes CC and billing address fields.
- `pmpro_include_billing_address_fields` — returns false.
- `pmpro_include_payment_information_fields` — replaces payment fields with PayPal button container + hidden fields.
- `pmpro_checkout_default_submit_button` — hides default submit button (PayPal buttons handle form submission). Shows normal submit for free levels.
- `pmpro_checkout_preheader` — enqueues PayPal JS SDK and plugin JS, localizes script data.
- `pmpro_checkout_order` — captures `paypal_order_id` / `paypal_subscription_id` from `$_REQUEST` into the order object.

#### Settings

PMPro calls `PMProGateway_paypal::show_settings_fields()` and `PMProGateway_paypal::save_settings_fields()` directly via `call_user_func()` from `adminpages/paymentsettings.php`. No hooks needed — PMPro resolves the class name from the gateway slug.

**Options stored:**
- `pmpro_paypal_client_id` — PayPal Client ID
- `pmpro_paypal_client_secret` — PayPal Client Secret
- `pmpro_paypal_webhook_id` — Auto-registered webhook ID (set automatically on first credential save)

#### Feature Support

```php
'subscription_sync'      => true,   // update_subscription_info() is implemented
'payment_method_updates' => false,  // PayPal manages payment methods
```

#### Plan Management (Lazy Creation)

Mirrors the Stripe gateway's Product/Price pattern:

- **One Catalog Product per level** — stored in level meta (`paypal_product_id` / `paypal_product_id_sandbox`). Created lazily on first checkout. Verified at PayPal before reuse.
- **One Plan per unique price/cycle/trial combo** — PayPal Plans are immutable, so each unique `(amount, cycle_period, cycle_number, initial_payment, trial_amount, trial_limit, billing_limit, currency)` combination gets its own Plan. Plan IDs stored as a hash-keyed JSON map in level meta (`paypal_plans` / `paypal_plans_sandbox`).

This handles discount codes, prorated amounts, and custom pricing — each unique combo lazily creates a new Plan.

**`get_or_create_plan($level, $currency)`:**
1. Build MD5 hash from billing parameters.
2. Check level meta for existing plan_id matching this hash.
3. If found, verify plan exists at PayPal (`GET /v1/billing/plans/{id}`). If still ACTIVE, use it.
4. If not found or invalid: create new Plan with billing cycles + setup fee. Store plan_id in level meta map.

**Setup fee:** When `initial_payment` differs from `billing_amount`, the difference is set as `setup_fee` in the Plan's `payment_preferences`.

**Trial support:** PMPro trial periods map to PayPal `billing_cycles` with `tenure_type: TRIAL`.

**`get_or_create_product($level)`:**
1. Check level meta for existing product_id.
2. Verify product exists at PayPal (`GET /v1/catalogs/products/{id}`).
3. If missing, create new product (`type: SERVICE`, `category: SOFTWARE`).

#### Checkout Processing

**`process(&$order)`** — routes based on which PayPal ID is present:

| Scenario | Method | What Happens |
|----------|--------|-------------|
| `paypal_order_id` set | `process_order()` | Captures order via API, validates COMPLETED status, extracts capture ID, stores in order meta |
| `paypal_subscription_id` set | `process_subscription()` | Verifies subscription ACTIVE/APPROVED via API, sets `subscription_transaction_id` |
| Neither set, free level | — | Returns success directly |
| Neither set, paid level | — | Returns error |

**Important:** Initial checkouts are fully synchronous. Webhooks are NOT required for initial checkout completion — both one-time and recurring flows complete the capture/verification in the `process()` call before the page finishes loading.

#### AJAX Handlers

**`ajax_create_order()`** — one-time payment flow:
1. Verify nonce.
2. Get level at checkout with `pmpro_getLevelAtCheckout()`.
3. Create PayPal Order via `POST /v2/checkout/orders` with `intent: CAPTURE`.
4. Return `orderID` to JS.

**`ajax_create_subscription()`** — recurring flow:
1. Verify nonce.
2. Get level at checkout.
3. Call `get_or_create_plan()` to ensure Product + Plan exist.
4. Create PayPal Subscription via `POST /v1/billing/subscriptions`.
5. Return `subscriptionID` to JS.

Both handlers support logged-in and non-logged-in users (PMPro creates accounts during checkout, so users may not be authenticated when the PayPal button fires).

#### Cancellation

**`cancel_subscription($subscription)`:**
- Calls `POST /v1/billing/subscriptions/{id}/cancel`.
- Treats "already cancelled" PayPal errors as success.

#### Subscription Sync

**`update_subscription_info($subscription)`:**
- Fetches subscription from PayPal (`GET /v1/billing/subscriptions/{id}`).
- Maps: ACTIVE/APPROVED → active, everything else → cancelled.
- Sets `next_payment_date` from `billing_info.next_billing_time`.
- Sets `billing_amount` from `billing_info.last_payment.amount.value`.
- Fetches plan to get `cycle_number` / `cycle_period` from the REGULAR billing cycle.

#### Refund

**`process_refund($refunded, $order)`:**
- Hooked on `pmpro_process_refund_paypal` filter.
- Gets capture ID from order meta (`paypal_capture_id`), falls back to `payment_transaction_id`.
- Calls `POST /v2/payments/captures/{id}/refund`.
- Updates order status to 'refunded', adds order note, sends refund emails.

### `classes/class-paypal-api.php` (API client)

**Class:** `PMPro_PayPal_API`

Stateless client — instantiated fresh for each operation. Reads credentials from `pmpro_paypal_client_id` / `pmpro_paypal_client_secret` options and environment from `pmpro_gateway_environment`.

**Authentication:** OAuth2 client credentials flow via `POST /v1/oauth2/token`. Access token cached in transient (`pmpro_paypal_token_{environment}`) for up to 8 hours (token expiry minus 5-minute buffer).

**HTTP Transport (`request()`):**
- Uses `wp_remote_request()` with 60s timeout.
- JSON request/response bodies.
- Headers: `Authorization: Bearer {token}`, `Content-Type: application/json`, `PayPal-Partner-Attribution-Id: PaidMembershipsPro_SP`, `Prefer: return=representation`.
- Retries on 5xx errors: 3 attempts with exponential backoff (200ms, 400ms, 800ms).
- 4xx errors: no retry, returns `WP_Error` with extracted error message.
- 204 No Content: returns `array('status' => 'success')` (used for cancel operations).

**Error extraction:** `extract_error_message()` parses PayPal's error response format, checking `details[].description`, `details[].issue`, `message`, and `error_description` fields.

**API Methods:**

| Method | HTTP | Endpoint |
|--------|------|----------|
| `create_order($args)` | POST | `/v2/checkout/orders` |
| `capture_order($id)` | POST | `/v2/checkout/orders/{id}/capture` |
| `get_order($id)` | GET | `/v2/checkout/orders/{id}` |
| `refund_capture($id, $amount)` | POST | `/v2/payments/captures/{id}/refund` |
| `create_product($args)` | POST | `/v1/catalogs/products` |
| `get_product($id)` | GET | `/v1/catalogs/products/{id}` |
| `create_plan($args)` | POST | `/v1/billing/plans` |
| `get_plan($id)` | GET | `/v1/billing/plans/{id}` |
| `deactivate_plan($id)` | POST | `/v1/billing/plans/{id}/deactivate` |
| `create_subscription($args)` | POST | `/v1/billing/subscriptions` |
| `get_subscription($id)` | GET | `/v1/billing/subscriptions/{id}` |
| `cancel_subscription($id, $reason)` | POST | `/v1/billing/subscriptions/{id}/cancel` |
| `create_webhook($url, $events)` | POST | `/v1/notifications/webhooks` |
| `delete_webhook($id)` | DELETE | `/v1/notifications/webhooks/{id}` |
| `verify_webhook_signature($args)` | POST | `/v1/notifications/verify-webhook-signature` |

### `includes/webhook-handler.php`

Loaded on demand when the webhook endpoint receives a POST. Functions are procedural (not class-based).

**Endpoint:** `POST /wp-json/pmpro-paypal/v1/webhook` — registered via WP REST API with `permission_callback: __return_true` (PayPal signs requests; verification is handled in the callback).

**Verification:** `pmpro_paypal_verify_webhook()` calls PayPal's `verify-webhook-signature` API with the transmission headers and event body. In sandbox with no webhook_id stored, verification is bypassed for easier testing.

**Event routing:**

| Event | Handler | PMPro Function Called |
|-------|---------|----------------------|
| `PAYMENT.SALE.COMPLETED` | `pmpro_paypal_handle_sale_completed()` | `pmpro_handle_recurring_payment_succeeded_at_gateway()` |
| `PAYMENT.CAPTURE.COMPLETED` | — | Log only (already captured at checkout) |
| `PAYMENT.CAPTURE.REFUNDED` | `pmpro_paypal_handle_refund()` | Updates order status, sends emails |
| `PAYMENT.SALE.REFUNDED` | `pmpro_paypal_handle_refund()` | Updates order status, sends emails |
| `BILLING.SUBSCRIPTION.CANCELLED` | `pmpro_paypal_handle_subscription_cancelled()` | `pmpro_handle_subscription_cancellation_at_gateway()` |
| `BILLING.SUBSCRIPTION.SUSPENDED` | `pmpro_paypal_handle_subscription_cancelled()` | `pmpro_handle_subscription_cancellation_at_gateway()` |
| `BILLING.SUBSCRIPTION.EXPIRED` | `pmpro_paypal_handle_subscription_cancelled()` | `pmpro_handle_subscription_cancellation_at_gateway()` |
| `BILLING.SUBSCRIPTION.PAYMENT.FAILED` | `pmpro_paypal_handle_payment_failed()` | `pmpro_handle_recurring_payment_failure_at_gateway()` |
| `BILLING.SUBSCRIPTION.ACTIVATED` | — | Log only (already tracked from checkout) |
| `BILLING.SUBSCRIPTION.RE-ACTIVATED` | `pmpro_paypal_handle_subscription_reactivated()` | Sets PMPro subscription status to active |

**Refund handling:** Extracts parent transaction ID from the refund resource's `links` array (rel: `up`, `sale`, or `capture`), finds the PMPro order by `payment_transaction_id`, marks it refunded, and sends refund emails.

**Action hook:** `pmpro_paypal_webhook_processed` fires after every event with `$event_type`, `$resource`, and `$message`.

### `js/pmpro-paypal.js` (frontend)

jQuery-based. Wrapped in an IIFE with `'use strict'`.

**Localized data** (via `wp_localize_script` as `pmproPayPal`):
- `ajaxUrl` — WordPress admin-ajax.php URL
- `nonce` — AJAX nonce for `pmpro_paypal_nonce`
- `isRecurring` — boolean, determines button behavior
- `clientId` — PayPal Client ID
- `currency` — currency code (e.g., `USD`)
- `buttonStyle` — filterable via `pmpro_paypal_button_style`

**Button rendering (`initPayPalButtons()`):**
- Renders into `#pmpro-paypal-button-container`.
- If `isRecurring`: configures `createSubscription` + `onApprove` callbacks.
- If not recurring: configures `createOrder` + `onApprove` callbacks.
- Checks `isEligible()` before rendering; shows fallback message if not eligible.

**Checkout flow:**
1. User clicks PayPal button → popup opens.
2. `createOrder`/`createSubscription` callback fires AJAX to server → returns order/subscription ID.
3. User approves in PayPal popup.
4. `onApprove` sets hidden field value (`paypal_order_id` or `paypal_subscription_id`) and calls `submitCheckoutForm()`.
5. `submitCheckoutForm()` finds the PMPro checkout form, ensures `submit-checkout` hidden input exists, and calls `form.submit()`.

**Level change handling:** Listens for `pmpro_checkout_level_change` event and reloads the page. The PayPal SDK can't switch between `capture` and `subscription` intent dynamically.

---

## 4. Checkout Flow (End-to-End)

### One-Time Payment

```
Page Load
  └→ pmpro_checkout_preheader() enqueues PayPal SDK with intent=capture
  └→ pmpro_include_payment_information_fields() renders button container + hidden fields
  └→ pmpro_checkout_default_submit_button() hides default submit

JS: initPayPalButtons()
  └→ paypal.Buttons({ createOrder, onApprove }).render('#pmpro-paypal-button-container')

User clicks PayPal button
  └→ createOrder() → AJAX POST to wp_ajax_pmpro_paypal_create_order
      └→ Server: pmpro_getLevelAtCheckout() → create PayPal Order → return orderID
  └→ PayPal popup opens, user approves

onApprove(data)
  └→ Set #paypal_order_id hidden field
  └→ submitCheckoutForm() → form.submit()

Server: PMPro checkout preheader processes form
  └→ pmpro_checkout_order() captures paypal_order_id into $order
  └→ process() → process_order()
      └→ capture_order() via API
      └→ Validate status === COMPLETED
      └→ Extract capture_id, store in order meta
      └→ Set payment_transaction_id = capture_id
      └→ Return true → checkout completes
```

### Recurring Subscription

```
Page Load
  └→ pmpro_checkout_preheader() enqueues PayPal SDK with intent=subscription, vault=true

User clicks PayPal button
  └→ createSubscription() → AJAX POST to wp_ajax_pmpro_paypal_create_subscription
      └→ Server: get_or_create_plan() → ensure Product + Plan exist
      └→ create_subscription() via API → return subscriptionID
  └→ PayPal popup opens, user approves

onApprove(data)
  └→ Set #paypal_subscription_id hidden field
  └→ submitCheckoutForm() → form.submit()

Server: process() → process_subscription()
  └→ get_subscription() via API → verify ACTIVE/APPROVED
  └→ Set subscription_transaction_id
  └→ Return true → checkout completes

Later: PayPal sends PAYMENT.SALE.COMPLETED webhook for each renewal
  └→ pmpro_handle_recurring_payment_succeeded_at_gateway() records the payment
```

---

## 5. Legacy Coexistence

This plugin runs alongside the legacy PayPal gateways in PMPro core:

| Gateway | Slug | API | Notifications | Status |
|---------|------|-----|--------------|--------|
| PayPal (this plugin) | `paypal` | REST (Orders V2, Subscriptions v1) | Webhooks | Active / New |
| PayPal Express | `paypalexpress` | NVP/SOAP | IPN | Legacy |
| Website Payments Pro | `paypalwpp` | NVP/SOAP | IPN | Legacy / Deprecated |

- Existing PPE/WPP subscribers keep their current gateway slug and IPN handler. No migration needed.
- New sign-ups use the `paypal` gateway and webhooks.
- IPN and Webhooks are separate notification systems — no cross-talk. REST API subscriptions only trigger webhooks, not IPN.
- The `paypal` slug was freed by renaming Website Payments Pro from `paypal` to `paypalwpp` in PMPro core.

---

## 6. Filters and Actions

### Filters

| Filter | Location | Purpose |
|--------|----------|---------|
| `pmpro_paypal_button_style` | `pmpro_checkout_preheader()` | Customize PayPal button appearance (layout, color, shape, label) |
| `pmpro_paypal_create_order_args` | `ajax_create_order()` | Modify PayPal order arguments before creation |
| `pmpro_paypal_create_subscription_args` | `ajax_create_subscription()` | Modify PayPal subscription arguments before creation |
| `pmpro_paypal_create_plan_args` | `get_or_create_plan()` | Modify PayPal plan arguments before creation |
| `pmpro_paypal_create_product_args` | `get_or_create_product()` | Modify PayPal product arguments before creation |
| `pmpro_process_refund_paypal` | `init()` | Refund processing (hooked by this plugin) |

### Actions

| Action | Location | Purpose |
|--------|----------|---------|
| `pmpro_paypal_webhook_processed` | `webhook-handler.php` | Fires after every webhook event is processed. Receives `$event_type`, `$resource`, `$message`. |

---

## 7. Data Storage

### WordPress Options

| Option Key | Value |
|-----------|-------|
| `pmpro_paypal_client_id` | PayPal Client ID |
| `pmpro_paypal_client_secret` | PayPal Client Secret |
| `pmpro_paypal_webhook_id` | Auto-registered webhook ID |

### Transients

| Transient Key | TTL | Value |
|--------------|-----|-------|
| `pmpro_paypal_token_sandbox` | ~8 hours | OAuth2 access token (sandbox) |
| `pmpro_paypal_token_live` | ~8 hours | OAuth2 access token (live) |

### Level Meta (per membership level)

| Meta Key | Value |
|---------|-------|
| `paypal_product_id` | PayPal Catalog Product ID (live) |
| `paypal_product_id_sandbox` | PayPal Catalog Product ID (sandbox) |
| `paypal_plans` | JSON map: `{ md5_hash: plan_id, ... }` (live) |
| `paypal_plans_sandbox` | JSON map: `{ md5_hash: plan_id, ... }` (sandbox) |

### Order Meta (per PMPro order)

| Meta Key | Value |
|---------|-------|
| `paypal_order_id` | PayPal Order ID (one-time payments) |
| `paypal_capture_id` | PayPal Capture ID (one-time payments, used for refunds) |
| `paypal_subscription_id` | PayPal Subscription ID (recurring) |

---

## 8. Development Notes

### Adding a New Webhook Event

1. Add the event type string to the `$events` array in `maybe_register_webhook()`.
2. Add a `case` in the `switch` block in `pmpro_paypal_handle_webhook()`.
3. Write a handler function in `webhook-handler.php`.
4. If the webhook was already registered, delete the old one from PayPal and clear the `pmpro_paypal_webhook_id` option to trigger re-registration.

### Testing Without Webhooks

Initial checkouts work without a reachable webhook URL. Both one-time and recurring flows complete synchronously. Webhooks are only needed for post-checkout events: renewals, cancellations, payment failures, and refunds.

For local development, use a tunnel service (e.g., ngrok) to expose the webhook endpoint, or test webhook handling by simulating events.

### Environment Separation

Product IDs, Plan IDs, webhook IDs, and OAuth tokens are all stored separately per environment (sandbox vs live). Switching environments in PMPro settings will use the correct set of stored data.

### PMPro Gateway Contract

Key methods from the base `PMProGateway` class that this plugin implements:

| Method | Required | Implemented |
|--------|----------|-------------|
| `process(&$order)` | Yes | Yes |
| `cancel_subscription($subscription)` | For recurring | Yes |
| `update_subscription_info($subscription)` | For sync | Yes |
| `supports($feature)` | Optional | Yes |
| `show_settings_fields()` | Yes | Yes |
| `save_settings_fields()` | Yes | Yes |
