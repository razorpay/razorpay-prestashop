# Razorpay Webhook Integration

---

## 1. Webhook Endpoint

**URL pattern:** `https://<store-domain>/index.php?fc=module&module=razorpay&controller=webhook`
Generated at runtime by `$this->context->link->getModuleLink('razorpay', 'webhook', [], true)`.

**Request method:** POST

**Headers Razorpay sends:**

| Header | Value |
|---|---|
| `X-Razorpay-Signature` | HMAC-SHA256 hex digest of the raw POST body, keyed with the webhook secret |
| `Content-Type` | `application/json` |

The module reads `$_SERVER['HTTP_X_RAZORPAY_SIGNATURE']` (PHP uppercases and prefixes HTTP headers).

---

## 2. Handled Events

| Event | Handler | Behavior |
|---|---|---|
| `order.paid` | `razorpay-webhook.php:orderPaid()` | Full implementation — creates or completes a PrestaShop order |
| `payment.authorized` | `razorpay-webhook.php:paymentAuthorized()` | Stub — returns immediately, does nothing |
| `payment.failed` | `razorpay-webhook.php:paymentFailed()` | Stub — returns immediately, does nothing |
| Any other event | `process()` default branch | Returns without action |

---

## 3. order.paid Payload Fields Accessed

The handler accesses these paths inside `$data` (parsed from raw JSON body):

| Path | Variable | Used for |
|---|---|---|
| `payload.payment.entity.notes.prestashop_cart_id` | `$cartId` | Looks up the cart in PrestaShop and in `razorpay_sales_order` |
| `payload.order.entity.id` | `$rzpOrderId` | Matched against `razorpay_sales_order.rzp_order_id` for deduplication |
| `payload.payment.entity.id` | `$razorpayPaymentId` | Stored as transaction ID; used for payment notes update |
| `payload.payment.entity.amount` | `$amount_paid` | Divided by 100 and stored in `razorpay_sales_order.amount_paid` |
| `payload.payment.entity.method` | (inline) | Builds `"razorpay.<method>"` payment method label on the PrestaShop order |

**Dependency:** `prestashop_cart_id` in payment notes must be set. It is written by `order.php` when the Razorpay order is created (stored via checkout JS passing it through the order notes at creation time), and by both notes-update calls after order placement. If this note is missing, `$cartId` will be null and order processing will fail silently.

---

## 4. Auto-Registration

**Triggered by:** `autoEnableWebhook()` in `razorpay.php`, called on every checkout page load when more than 24 hours have passed since last verification, and immediately on admin settings save.

**What gets registered in Razorpay:**

| Field | Value |
|---|---|
| `url` | Store's full webhook URL (HTTPS) |
| `active` | `true` |
| `events.payment.authorized` | `true` (always enabled) |
| `events.order.paid` | `true` (always enabled) |
| `secret` | Value of `RAZORPAY_WEBHOOK_SECRET` config; auto-generated if empty |

`payment.failed` is in `$webhookSupportedEvents` (for merge preservation) but is **not** in `$webhookEvents` (the default create/update payload) — it will not be enabled unless it was already active in Razorpay before the module registered.

**Localhost prevention:** If the domain resolves to a private or reserved IP, `autoEnableWebhook()` aborts early and sets `ENABLE_RAZORPAY_WEBHOOK=off`. No API calls are made. This prevents registration failures for development environments.

---

## 5. Retry Behavior and HTTP Status Codes

The webhook handler returns specific HTTP status codes that control Razorpay's retry logic:

| Status | Condition | Razorpay behavior |
|---|---|---|
| `409 Conflict` | `order.paid` arrives before the webhook wait window has elapsed (within `RAZORPAY_WEBHOOK_WAIT_TIME` seconds of first notification, default 300 s) | Razorpay retries the delivery |
| `200 OK` | Event processed successfully (implicit — PHP exits without setting a 4xx/5xx) | Razorpay marks delivery as successful, stops retrying |
| `400 Bad Request` | Signature verification fails | Razorpay stops retrying this delivery |

**Why the 409 window exists:** The frontend validation flow (`validation.php`) and the webhook can race to create the PrestaShop order for the same cart. The module defers webhook processing for `RAZORPAY_WEBHOOK_WAIT_TIME` seconds (default 300) from the first webhook notification to give the frontend flow time to complete first. If the frontend wins, the webhook detects `order_placed=1` in `razorpay_sales_order` and exits cleanly. If the webhook wins after the wait, it creates the order itself.

**Deduplication guard:** Before the wait check, if `razorpay_sales_order.order_placed = 1` for the cart/order pair, the handler exits immediately with 200 — the order is already done, no retry needed.
