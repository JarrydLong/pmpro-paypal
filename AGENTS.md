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
    pmpro-paypal.js                      # Empty (kept for backwards compat, no longer used)
  readme.txt                             # WordPress plugin readme
```

No vendor dependencies, no Composer, no build step. All PayPal API communication uses `wp_remote_post()` / `wp_remote_request()` directly.

---

## 2. Architecture

**Offsite redirect checkout:** The checkout form submits first (user + order created via standard PMPro flow, all hooks/filters run), then the user is redirected to PayPal for approval. A webhook completes the checkout asynchronously via `pmpro_complete_async_checkout()`. If the user returns before the webhook fires, `check_token_order()` polls PayPal to complete checkout.

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

Registered via `add_action('init', ...)`. All methods are static except `process()`, `check_token_order()`, `cancel_subscription()`, and `update_subscription_info()`.

#### Registration (`init()`)

Hooks registered for all gateways:
- `pmpro_gateways` — adds `'paypal' => 'PayPal'` to the gateway dropdown.
- `pmpro_process_refund_paypal` — refund processing hook.

Hooks registered only when `paypal` is the active gateway:
- `pmpro_required_billing_fields` — removes CC and billing address fields.
- `pmpro_include_billing_address_fields` — returns false.
- `pmpro_include_payment_information_fields` — returns false (no on-page payment fields; user enters payment info at PayPal).
- `pmpro_checkout_default_submit_button` — replaces default submit with a "Check Out with PayPal" button. Shows normal submit for free levels or non-PayPal gateways.

#### Settings

PMPro calls `PMProGateway_paypal::show_settings_fields()` and `PMProGateway_paypal::save_settings_fields()` directly via `call_user_func()` from `adminpages/paymentsettings.php`. No hooks needed — PMPro resolves the class name from the gateway slug.

**Options stored (per-environment):**
- `pmpro_paypal_client_id_live` / `pmpro_paypal_client_id_sandbox` — PayPal Client ID
- `pmpro_paypal_client_secret_live` / `pmpro_paypal_client_secret_sandbox` — PayPal Client Secret
- `pmpro_paypal_webhook_id_live` / `pmpro_paypal_webhook_id_sandbox` — Auto-registered webhook ID (set automatically on first credential save)

Option names are resolved via `PMProGateway_paypal::get_option_names()` based on the current `pmpro_gateway_environment`. A one-time migration in `maybe_migrate_legacy_options()` moves pre-1.1 unsuffixed options to the current environment's scoped keys.

#### Feature Support

```php
'subscription_sync'      => true,   // update_subscription_info() is implemented
'payment_method_updates' => false,  // PayPal manages payment methods
'check_token_orders'     => true,   // check_token_order() polls PayPal when user returns before webhook
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

**Setup fee:** The `initial_payment` amount is always set as `setup_fee` in the Plan's `payment_preferences`. PayPal charges the setup fee immediately at subscription activation, while the first regular billing cycle starts at `start_time` (one period out). This means the setup fee represents the initial payment regardless of whether it equals the recurring amount.

**Trial support:** PMPro trial periods map to PayPal `billing_cycles` with `tenure_type: TRIAL`.

**Tax handling:** `process()` clones the level with tax-inclusive amounts (via `$order->getTaxForPrice()`) before passing to `get_or_create_plan()`. This means the Plan's billing cycles and setup fee include tax.

**`get_or_create_product($level)`:**
1. Check level meta for existing product_id.
2. Verify product exists at PayPal (`GET /v1/catalogs/products/{id}`).
3. If missing, create new product (`type: SERVICE`, `category: SOFTWARE`).

#### Checkout Processing — Offsite Redirect

**`process(&$order)`** handles the offsite redirect:

1. Free levels return `true` immediately (no redirect needed).
2. Sets `$order->status = 'token'` and calls `$order->saveOrder()`.
3. Calls `pmpro_save_checkout_data_to_order($order)` to preserve checkout data for async completion.
4. Calculates tax-inclusive amounts via `$order->getTaxForPrice()`.

| Scenario | What Happens |
|----------|-------------|
| One-time payment | Creates PayPal order (Orders V2) with `payment_source.paypal.experience_context` (return/cancel URLs). Saves `paypal_order_id` to order meta. Redirects to `payer-action` or `approve` link. |
| Recurring subscription | Clones level with tax-inclusive amounts. Creates/gets Plan via `get_or_create_plan()`. Calls `pmpro_calculate_profile_start_date($order, 'c')`. Creates subscription with return/cancel URLs. Saves `subscription_transaction_id`. Redirects to `approve` link. |

Always returns `false` — checkout is completed asynchronously by the webhook or `check_token_order()`.

**Return/cancel URLs:**
- Return: `pmpro_url('confirmation')` with `pmpro_level` parameter, filtered via `pmpro_confirmation_url`
- Cancel: `pmpro_url('checkout')` with `pmpro_level` parameter

#### Check Token Order

**`check_token_order($order)`:**

Called by PMPro core when the user returns from PayPal before the webhook fires (because `check_token_orders => true` in `supports()`).

- **One-time:** Gets order from PayPal, captures if APPROVED, checks for COMPLETED, extracts capture ID as `payment_transaction_id`.
- **Subscription:** Gets subscription from PayPal, checks for ACTIVE, fetches initial transaction ID via `get_subscription_transactions()`.
- Then: `pmpro_pull_checkout_data_from_order()` + `pmpro_complete_async_checkout()`.

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
| `get_subscription_transactions($id, $start, $end)` | GET | `/v1/billing/subscriptions/{id}/transactions` |
| `cancel_subscription($id, $reason)` | POST | `/v1/billing/subscriptions/{id}/cancel` |
| `create_webhook($url, $events)` | POST | `/v1/notifications/webhooks` |
| `delete_webhook($id)` | DELETE | `/v1/notifications/webhooks/{id}` |
| `verify_webhook_signature($args)` | POST | `/v1/notifications/verify-webhook-signature` |

### `includes/webhook-handler.php`

Loaded on demand when the webhook endpoint receives a POST. Functions are procedural (not class-based).

**Endpoint:** `POST /wp-json/pmpro-paypal/v1/webhook` — registered via WP REST API with `permission_callback: __return_true` (PayPal signs requests; verification is handled in the callback).

**Verification:** `pmpro_paypal_verify_webhook()` calls PayPal's `verify-webhook-signature` API with the transmission headers and event body. In sandbox with no webhook_id stored, verification is bypassed for easier testing.

**Event routing:**

| Event | Handler | What It Does |
|-------|---------|--------------|
| `CHECKOUT.ORDER.APPROVED` | `pmpro_paypal_handle_checkout_order_approved()` | Captures order, finds PMPro token order by `paypal_order_id` meta, sets `payment_transaction_id`, calls `pmpro_complete_async_checkout()` |
| `BILLING.SUBSCRIPTION.ACTIVATED` | `pmpro_paypal_handle_subscription_activated()` | Finds token order by `subscription_transaction_id`, gets initial transaction ID, calls `pmpro_complete_async_checkout()` |
| `PAYMENT.SALE.COMPLETED` | `pmpro_paypal_handle_sale_completed()` | `pmpro_handle_recurring_payment_succeeded_at_gateway()` |
| `PAYMENT.CAPTURE.COMPLETED` | — | Log only (capture handled by CHECKOUT.ORDER.APPROVED) |
| `PAYMENT.CAPTURE.REFUNDED` | `pmpro_paypal_handle_refund()` | Updates order status, sends emails |
| `PAYMENT.SALE.REFUNDED` | `pmpro_paypal_handle_refund()` | Updates order status, sends emails |
| `BILLING.SUBSCRIPTION.CANCELLED` | `pmpro_paypal_handle_subscription_cancelled()` | `pmpro_handle_subscription_cancellation_at_gateway()` |
| `BILLING.SUBSCRIPTION.SUSPENDED` | `pmpro_paypal_handle_subscription_cancelled()` | `pmpro_handle_subscription_cancellation_at_gateway()` |
| `BILLING.SUBSCRIPTION.EXPIRED` | `pmpro_paypal_handle_subscription_cancelled()` | `pmpro_handle_subscription_cancellation_at_gateway()` |
| `BILLING.SUBSCRIPTION.PAYMENT.FAILED` | `pmpro_paypal_handle_payment_failed()` | `pmpro_handle_recurring_payment_failure_at_gateway()` |
| `BILLING.SUBSCRIPTION.RE-ACTIVATED` | `pmpro_paypal_handle_subscription_reactivated()` | Sets PMPro subscription status to active |

**Checkout completion webhooks (CHECKOUT.ORDER.APPROVED and BILLING.SUBSCRIPTION.ACTIVATED):**
Both use the same pattern: find the PMPro token order, set `payment_transaction_id`, call `pmpro_pull_checkout_data_from_order()` then `pmpro_complete_async_checkout()`. For subscriptions, the initial transaction ID is fetched via `get_subscription_transactions()` (1 hour window around `create_time`). For one-time orders, the capture ID is extracted from the captured order response.

**Refund handling:** Extracts parent transaction ID from the refund resource's `links` array (rel: `up`, `sale`, or `capture`), finds the PMPro order by `payment_transaction_id`, marks it refunded, and sends refund emails.

**Action hook:** `pmpro_paypal_webhook_processed` fires after every event with `$event_type`, `$resource`, and `$message`.

---

## 4. Checkout Flow (End-to-End)

### One-Time Payment

```
Page Load
  └→ pmpro_include_payment_information_fields → false (no on-page payment fields)
  └→ pmpro_checkout_default_submit_button → "Check Out with PayPal" button

User fills out checkout form and clicks "Check Out with PayPal"
  └→ Form submits normally to PMPro

Server: PMPro checkout preheader processes form
  └→ Creates user account (if new)
  └→ Creates order with status = 'review'
  └→ Calls process(&$order)
      └→ Sets $order->status = 'token', saves order
      └→ Calls pmpro_save_checkout_data_to_order($order)
      └→ Calculates tax-inclusive initial amount
      └→ Creates PayPal order via Orders V2 API
      └→ Saves paypal_order_id to order meta
      └→ wp_redirect() to PayPal payer-action URL → exit

User approves payment at PayPal → redirected to confirmation page

Two paths to checkout completion:

  Path A: Webhook fires first (typical)
    └→ CHECKOUT.ORDER.APPROVED webhook received
    └→ Captures order at PayPal
    └→ Finds PMPro order by paypal_order_id meta
    └→ Sets payment_transaction_id = capture ID
    └→ pmpro_pull_checkout_data_from_order() + pmpro_complete_async_checkout()

  Path B: User returns first (check_token_order)
    └→ PMPro core calls check_token_order() on confirmation page
    └→ Gets order from PayPal, captures if APPROVED
    └→ Sets payment_transaction_id = capture ID
    └→ pmpro_pull_checkout_data_from_order() + pmpro_complete_async_checkout()
```

### Recurring Subscription

```
User fills out checkout form and clicks "Check Out with PayPal"
  └→ Form submits normally to PMPro

Server: process(&$order)
  └→ Sets $order->status = 'token', saves order
  └→ Calls pmpro_save_checkout_data_to_order($order)
  └→ Calculates tax-inclusive amounts (initial + recurring + trial)
  └→ get_or_create_plan() with tax-inclusive level clone
  └→ pmpro_calculate_profile_start_date($order, 'c') for start_time
  └→ Creates PayPal subscription via Subscriptions API
  └→ Saves subscription_transaction_id on order
  └→ wp_redirect() to PayPal approve URL → exit

User approves subscription at PayPal → redirected to confirmation page

Two paths to checkout completion:

  Path A: Webhook fires first
    └→ BILLING.SUBSCRIPTION.ACTIVATED webhook received
    └→ Finds token order by subscription_transaction_id
    └→ Gets initial transaction ID via subscription transactions API
    └→ pmpro_pull_checkout_data_from_order() + pmpro_complete_async_checkout()

  Path B: User returns first
    └→ check_token_order() polls subscription status
    └→ If ACTIVE, gets initial transaction ID
    └→ pmpro_pull_checkout_data_from_order() + pmpro_complete_async_checkout()

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
| `pmpro_paypal_create_order_args` | `process()` | Modify PayPal order arguments before creation |
| `pmpro_paypal_create_subscription_args` | `process()` | Modify PayPal subscription arguments before creation |
| `pmpro_paypal_create_plan_args` | `get_or_create_plan()` | Modify PayPal plan arguments before creation |
| `pmpro_paypal_create_product_args` | `get_or_create_product()` | Modify PayPal product arguments before creation |
| `pmpro_process_refund_paypal` | `init()` | Refund processing (hooked by this plugin) |
| `pmpro_confirmation_url` | `process()` | PayPal return URL after approval |

### Actions

| Action | Location | Purpose |
|--------|----------|---------|
| `pmpro_paypal_webhook_processed` | `webhook-handler.php` | Fires after every webhook event is processed. Receives `$event_type`, `$resource`, `$message`. |

---

## 7. Data Storage

### WordPress Options

| Option Key | Value |
|-----------|-------|
| `pmpro_paypal_client_id_live` / `_sandbox` | PayPal Client ID (per-environment) |
| `pmpro_paypal_client_secret_live` / `_sandbox` | PayPal Client Secret (per-environment) |
| `pmpro_paypal_webhook_id_live` / `_sandbox` | Auto-registered webhook ID (per-environment) |

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

Note: `subscription_transaction_id` is stored directly on the order object (not in order meta).

---

## 8. Development Notes

### Adding a New Webhook Event

1. Add the event type string to the `$events` array in `maybe_register_webhook()`.
2. Add a `case` in the `switch` block in `pmpro_paypal_handle_webhook()`.
3. Write a handler function in `webhook-handler.php`.
4. If the webhook was already registered, delete the old one from PayPal and clear the environment-specific webhook ID option (e.g., `pmpro_paypal_webhook_id_live` or `pmpro_paypal_webhook_id_sandbox`) to trigger re-registration.

### Testing Without Webhooks

The `check_token_order()` method provides a fallback: when the user returns from PayPal, PMPro core calls this method to poll PayPal and complete checkout if the payment is ready. This means basic checkout testing works without a reachable webhook URL. However, webhooks are required for: renewals, cancellations, payment failures, refunds, and completing checkouts where the user doesn't return to the site.

For local development, use a tunnel service (e.g., ngrok) to expose the webhook endpoint, or test webhook handling by simulating events.

### Environment Separation

All PayPal state is stored per-environment: Client ID, Client Secret, webhook ID, Product IDs, Plan IDs, and OAuth tokens. Switching environments in PMPro settings will use the correct set of stored data. Credentials and webhook IDs are resolved via `PMProGateway_paypal::get_option_names()`. OAuth tokens are cached in transients keyed by environment and are automatically cleared when credentials are saved.

### PMPro Gateway Contract

Key methods from the base `PMProGateway` class that this plugin implements:

| Method | Required | Implemented |
|--------|----------|-------------|
| `process(&$order)` | Yes | Yes (offsite redirect) |
| `check_token_order($order)` | For async gateways | Yes |
| `cancel_subscription($subscription)` | For recurring | Yes |
| `update_subscription_info($subscription)` | For sync | Yes |
| `supports($feature)` | Optional | Yes |
| `show_settings_fields()` | Yes | Yes |
| `save_settings_fields()` | Yes | Yes |
