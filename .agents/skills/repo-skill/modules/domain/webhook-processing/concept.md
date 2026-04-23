# Webhook Processing — Concept

## What a Webhook Is in This Context

A webhook is an HTTP POST sent directly from Razorpay's servers to the PS module endpoint (`/module/razorpay/webhook`). There is no browser or customer session involved. The entry point is `webhook.php:postProcess()` which instantiates `RZP_Webhook` and calls `razorpay-webhook.php:process()`.

The webhook serves as a **fallback order-creation path** for cases where the customer closes the browser, loses connectivity, or the frontend redirect never completes after payment succeeds on Razorpay's side.

---

## Event Types

| Event | Constant | Handler | Status |
|-------|----------|---------|--------|
| `order.paid` | `Razorpay::ORDER_PAID` | `razorpay-webhook.php:orderPaid()` | Active — creates/confirms orders |
| `payment.authorized` | `Razorpay::PAYMENT_AUTHORIZED` | `razorpay-webhook.php:paymentAuthorized()` | Stub — returns immediately, no-op |
| `payment.failed` | `Razorpay::PAYMENT_FAILED` | `razorpay-webhook.php:paymentFailed()` | Stub — returns immediately, no-op |

Only `order.paid` has business logic. The other two are registered in the switch statement but do nothing. This means failed payments are never acted on via webhook — the frontend is solely responsible for failure handling.

---

## Deduplication Model

The `razorpay_sales_order` table is the single source of truth for whether an order has been placed. The row lifecycle is:

1. **Row created** — `order.php:postProcess()` inserts the row with `order_placed = 0` at the moment the customer is shown the Razorpay checkout modal (before payment).
2. **Order placed via frontend** — `validation.php:postProcess()` sets `order_placed = 1`, `by_frontend = 1`.
3. **Order placed via webhook** — `razorpay-webhook.php:orderPaid()` sets `order_placed = 1`, `by_webhook = 1`.

The `order_placed` flag is the deduplication gate. When `orderPaid()` queries the row and finds `order_placed = 1`, it logs and calls `exit` immediately — preventing a second `validateOrder()` call for the same cart.

The table has a `UNIQUE KEY` on `(cart_id, rzp_payment_id)` as a database-level backstop, but the `order_placed` check is the primary guard.

---

## Race Condition: Webhook vs. Frontend

The 300-second wait exists precisely to handle this scenario:

```
Razorpay Server                  Customer Browser          PrestaShop Module
      |                                 |                          |
      |--- payment captured ----------->|                          |
      |                                 |-- POST /webhook -------->|
      |                                 |                    orderPaid() called
      |                                 |                    order_placed = 0
      |                                 |                    webhook_count = 0 -> 1
      |                                 |                    webhook_first_notified_at = T
      |                                 |                    (time() - T) < 300s
      |                                 |                    HTTP 409 exit
      |                                 |                          |
      |                                 |-- POST /validation ----->|
      |                                 |                    verifyPaymentSignature()
      |                                 |                    validateOrder() called
      |                                 |                    order_placed = 1, by_frontend = 1
      |                                 |<-- redirect to confirmation page ----------|
      |                                 |                          |
      |--- retry webhook (if needed) -->|                          |
      |                                 |-- POST /webhook -------->|
      |                                 |                    order_placed = 1 -> exit immediately
```

**What happens when the wait time is exceeded (webhook wins):**

If no frontend request arrives within 300 seconds of `webhook_first_notified_at`, the webhook proceeds to `orderPaid()`. It then checks `Order::getOrderByCartId()`:

- If a PrestaShop order **exists** but has no payments recorded, it transitions the order state to `PS_OS_PAYMENT` via `$order->setCurrentState()`.
- If no PrestaShop order exists at all, it calls `validateOrder()` directly, creating one from scratch.

---

## Webhook Wait Time

- **Default:** 300 seconds (5 minutes), read from `Configuration::get('RAZORPAY_WEBHOOK_WAIT_TIME')`.
- **Configurable:** Admin can change via the module settings page; stored in PS configuration table.
- **Purpose:** Give the browser-based frontend flow enough time to complete before the webhook takes over. Avoids duplicate order creation in the common case where both paths race.
- **Countdown start:** The first time a webhook fires for a given `(cart_id, rzp_order_id)` pair — not when payment was captured.
- **Exceeded behavior:** The webhook proceeds to attempt order creation/confirmation.

Code: `razorpay-webhook.php:orderPaid()` — the condition `(time() - $firstNotifiedTime) < $webhookWaitTime` returns HTTP 409 while inside the window.

---

## Signature Verification

**Algorithm:** HMAC-SHA256

**Webhook flow:**
1. Razorpay sends the raw POST body and an `X-Razorpay-Signature` header.
2. `process()` passes the raw body, the header value, and the configured `RAZORPAY_WEBHOOK_SECRET` to `Utility.php:verifyWebhookSignature()`.
3. `verifyWebhookSignature()` calls `verifySignature()` which computes `hash_hmac('sha256', $payload, $secret)` and compares with `hash_equals()` to prevent timing attacks.
4. If verification fails, the process returns HTTP 400 and calls `exit`.

**Secret source:** `Configuration::get('RAZORPAY_WEBHOOK_SECRET')` — the merchant configures this in the module admin panel to match the secret set in the Razorpay Dashboard for the webhook endpoint.

**Guard ordering in `process()`:**
1. JSON must decode without error.
2. `ENABLE_RAZORPAY_WEBHOOK` must be `'on'`.
3. `$data['event']` must be non-empty.
4. `HTTP_X_RAZORPAY_SIGNATURE` header must be present.
5. `RAZORPAY_WEBHOOK_SECRET` must be non-empty (if secret is blank, the webhook silently passes through all guards and falls through — no order action taken, no error logged).

**Non-obvious:** If the merchant leaves `RAZORPAY_WEBHOOK_SECRET` blank, the signature check is skipped entirely (the `if (empty($razorpayWebhookSecret) === false)` block is never entered). This is a misconfiguration risk — unauthenticated POSTs would simply be ignored because no action runs inside the empty-secret branch.

Code: `razorpay-webhook.php:process()`, `razorpay-sdk/src/Utility.php:verifySignature()`

---

## Non-Obvious Constraints

| Rule | Why | Enforced At |
|------|-----|-------------|
| Webhook only creates orders if `order_placed = 0` | Prevents double-charging the customer's account in PS | `razorpay-webhook.php:orderPaid()` — explicit `exit` on `order_placed = 1` |
| `prestashop_cart_id` must be in Razorpay payment notes | Webhook has no session; cart identity comes only from payment metadata | `orderPaid()` reads `$data['payload']['payment']['entity']['notes']['prestashop_cart_id']` |
| Payment amount is divided by 100 | Razorpay API amounts are in paise (smallest currency unit); PS expects rupees | `orderPaid()` line: `number_format($data['payload']['payment']['entity']['amount']/100, ...)` |
| `by_webhook` and `by_frontend` flags are set exclusively | Audit trail showing which path created the order; never both set on same row | `validation.php` sets `by_frontend = 1`; `razorpay-webhook.php` sets `by_webhook = 1` |

---

## Gaps Requiring Human Verification

- The 300-second default wait time is hardcoded as a fallback but there is no documentation on whether Razorpay's own retry schedule aligns with it. If Razorpay retries at T+0, T+60, T+300, the third retry would be the first one to proceed — needs verification against Razorpay's webhook retry documentation.
- There is no database-level lock or transaction wrapping the `order_placed` check and the `validateOrder()` call. Under simultaneous webhook retries, two requests could both read `order_placed = 0` before either writes `1`. The `UNIQUE KEY` on `(cart_id, rzp_payment_id)` would catch the second insert, but the first `validateOrder()` call would have already run.
