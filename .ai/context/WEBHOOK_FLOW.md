# Webhook Flow — Razorpay PrestaShop

## Purpose of Webhooks

Webhooks are a **fallback and safety net** for the frontend payment flow. If a customer's browser crashes, network drops, or any client-side failure occurs after the payment is taken by Razorpay but before the frontend reaches `/razorpay/validation`, the webhook ensures the PrestaShop order is still created.

---

## Webhook Endpoint

**URL**: `https://yourshop.com/index.php?fc=module&module=razorpay&controller=webhook`
(or SEO-friendly: `https://yourshop.com/razorpay/webhook`)

**Method**: POST
**Header required**: `X-Razorpay-Signature: <hmac-sha256>`
**Body**: JSON (Razorpay webhook payload)

---

## Auto-Setup Mechanism

The module automatically creates/updates the webhook endpoint in the Razorpay account.

**Triggered when**:
1. Admin saves settings (`_postProcess()` → `autoEnableWebhook()`)
2. Customer clicks "Pay" and webhook hasn't been verified in the last 24 hours

**`autoEnableWebhook()` logic** (`razorpay.php:415`):
```
1. Build webhook URL using getModuleLink()
2. Check if domain has public IP (skip localhost/private ranges)
3. Generate or reuse RAZORPAY_WEBHOOK_SECRET
4. Paginate through existing webhooks (10 at a time) looking for matching URL
5. If found: PUT /webhooks/{id} (update events + secret)
6. If not found: POST /webhooks/ (create new)
7. Store RAZORPAY_WEBHOOK_LAST_VERIFY = time()
8. Set ENABLE_RAZORPAY_WEBHOOK = 'on'
```

**Note**: If domain resolves to a private IP (`127.x.x.x`, `192.168.x.x`, `10.x.x.x`), webhook setup is skipped and `ENABLE_RAZORPAY_WEBHOOK` is set to `'off'`. Use ngrok or a live server for testing.

---

## Supported Events

| Event | Handler | Action |
|---|---|---|
| `order.paid` | `orderPaid()` | Creates PrestaShop order if not already created |
| `payment.authorized` | `paymentAuthorized()` | No-op (returns immediately) |
| `payment.failed` | `paymentFailed()` | No-op (returns immediately) |

---

## Complete Webhook Processing Flow

### Entry Point (`controllers/front/webhook.php`)
```php
$webhook = new RZP_Webhook();
$webhook->process();
```

### `RZP_Webhook::process()` (`razorpay-webhook.php:42`)

```
1. Read raw POST body: file_get_contents('php://input')
2. Decode JSON — return silently if invalid JSON
3. Check ENABLE_RAZORPAY_WEBHOOK === 'on' and event is set
4. Check HTTP_X_RAZORPAY_SIGNATURE header is present
5. Read RAZORPAY_WEBHOOK_SECRET from config
6. Call $api->utility->verifyWebhookSignature($rawPost, $signature, $secret)
   → On failure: log error, return HTTP 400, exit
7. Switch on $data['event']:
   - 'order.paid'          → orderPaid($data)
   - 'payment.authorized'  → paymentAuthorized($data) [no-op]
   - 'payment.failed'      → paymentFailed($data) [no-op]
   - default               → return
```

### `RZP_Webhook::orderPaid()` (`razorpay-webhook.php:128`)

**Payload data extracted**:
```php
$cartId            = $data['payload']['payment']['entity']['notes']['prestashop_cart_id'];
$rzpOrderId        = $data['payload']['order']['entity']['id'];
$razorpayPaymentId = $data['payload']['payment']['entity']['id'];
```

**Step 1: Check razorpay_sales_order table**
```sql
SELECT entity_id, order_placed, webhook_count, order_id, webhook_first_notified_at
FROM razorpay_sales_order
WHERE cart_id = $cartId AND rzp_order_id = '$rzpOrderId'
```

**Step 2: If record exists and `order_placed = 1`**
→ Log "Quote order is inactive", `exit` (order already created)

**Step 3: Wait-time guard (first webhook attempt)**
```
if webhook_count == 0:
    SET webhook_first_notified_at = time()
    SET rzp_payment_id = $razorpayPaymentId
SET webhook_count = webhook_count + 1
```
```
if (time() - webhook_first_notified_at) < RAZORPAY_WEBHOOK_WAIT_TIME (default 300):
    Log "Order processing is active, webhook attempt N"
    Return HTTP 409
    exit
```

This gives the frontend **5 minutes** to complete its flow before the webhook takes over.

**Step 4: Check if PS order already exists for this cart**
```php
$orderId = Order::getOrderByCartId($cart->id);
```
If order exists but has no payments: update order state to `PS_OS_PAYMENT`, exit.
If order exists with payments: exit (already complete).

**Step 5: Create fresh order**
```php
$this->razorpay->validateOrder(
    $cart->id,
    (int) Configuration::get('PS_OS_PAYMENT'),
    $cart->getOrderTotal(true, Cart::BOTH),
    "razorpay." . $paymentMethod,
    "Payment by Razorpay using " . $paymentMethod,
    ['transaction_id' => $razorpayPaymentId],
    NULL,
    false,
    $customer->secure_key
);
```

**Step 6: Update payment notes with PS order ID**
```php
$api->payment->fetch($razorpayPaymentId)->edit([
    'notes' => [
        'prestashop_order_id' => $this->razorpay->currentOrder,
        'prestashop_cart_id'  => $cart->id,
    ]
]);
```

**Step 7: Update razorpay_sales_order**
```sql
UPDATE razorpay_sales_order
SET rzp_payment_id='pay_xxx', amount_paid=100.00,
    order_id=123, by_webhook=1, order_placed=1
WHERE cart_id=42 AND entity_id=5
```

**Step 8: `exit`** (graceful exit after successful processing)

---

## Race Condition Handling

The most critical scenario: frontend and webhook both try to create an order.

```
Timeline:
  T+0s   Customer completes payment
  T+1s   Webhook arrives (order.paid)
           → records webhook_first_notified_at = T+1
           → returns HTTP 409 (too early)
  T+2s   Frontend hits /razorpay/validation
           → verifies signature, creates PS order
           → sets order_placed=1, by_frontend=1
  T+5m   Webhook retried by Razorpay
           → checks order_placed=1 → exits immediately (duplicate prevention)
```

```
Failure scenario (frontend fails):
  T+0s   Customer completes payment
  T+1s   Webhook arrives → records first_notified_at, returns 409
  T+2s   Frontend crashes (browser close, network error)
  T+5m   Webhook retried
           → wait time (300s) has elapsed
           → checks Order::getOrderByCartId() → no order
           → creates PS order, sets by_webhook=1
```

---

## Webhook Secret

- Auto-generated: 20 characters from alphanumeric + special characters
- Stored as: `RAZORPAY_WEBHOOK_SECRET` in `ps_configuration`
- Used for HMAC-SHA256 signature verification of all incoming webhook requests
- Displayed (copy-only) in admin settings panel

---

## HTTP Response Codes

| Code | When |
|---|---|
| 200 | Webhook processed successfully (or event not handled) |
| 400 | Signature verification failed |
| 409 | Order exists / too early (wait time not elapsed) — Razorpay will retry |

---

## Logging

All webhook activity is logged via `Logger::addLog()`:

| Message | Level |
|---|---|
| `"Razorpay Webhook: Quote order is inactive for cartID: X"` | 1 |
| `"Razorpay Webhook: Order processing is active for cartID: X, attempt N"` | 1 |
| `"Order creation Failed for Cart# X, Error: Y"` | 1 |
| `"Record inserted in razorpay_sales_order table"` | 1 |
| `"Razorpay payment notes update failed..."` | 3 |
| `"Error: {"message":"...", "event":"razorpay.wc.signature.verify_failed"}"` | 4 |

View logs: PrestaShop Admin → Advanced Parameters → Logs.
