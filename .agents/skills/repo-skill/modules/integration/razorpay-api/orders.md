# Razorpay Orders API Integration

Every Orders API call this module makes, with payloads and field origins.

---

## 1. Create Order

**Where:** `order.php:postProcess()`
**When:** Customer reaches checkout and the frontend JS POSTs to the `/razorpay/order` controller.

**Request payload:**

| Field | Value | Source |
|---|---|---|
| `amount` | integer, paise (no decimal) | `$this->context->cart->getOrderTotal() * 100`, formatted with `number_format(..., 0, "", "")` |
| `currency` | ISO 4217 code | `$this->context->currency->iso_code` |
| `payment_capture` | `1` or `0` | `1` when `RAZORPAY_PAYMENT_ACTION` config equals `"capture"`, otherwise `0` |
| `receipt` | cart ID as string | `(string) $this->context->cart->id` |

**Guard:** The call is skipped entirely if `$currency->iso_code` is in `Razorpay::CURRENCY_NOT_ALLOWED` (`['KWD', 'OMR', 'BHD']`). A 400 is returned and the error is logged.

**Response fields used:**

| Field | Used for |
|---|---|
| `$order->id` | Stored in `$_SESSION['rzp_order_id']`; inserted or updated in `razorpay_sales_order.rzp_order_id`; returned to the browser as `rzp_order_id` |

**Local side-effect:** On success the module upserts a row in `razorpay_sales_order(cart_id, rzp_order_id, amount_paid=0.00)`. If a row for that `cart_id` already exists (page refresh / back-button), it updates `rzp_order_id` in place.

---

## 2. Fetch Order

The module **never fetches an existing Razorpay order** after creation. There is no `$api->order->fetch()` call anywhere in the codebase. Order identity is recovered from the session or the `razorpay_sales_order` table, not from the API.

---

## 3. Update Payment Notes (hookOrderConfirmation)

**Where:** `razorpay.php:hookOrderConfirmation()`
**Entity updated:** `payment` (not `order`) — calls `payment->fetch($paymentId)->edit()`
**When triggered:** PrestaShop fires the `orderConfirmation` hook after the order-confirmation page loads, which happens after `validation.php` redirects there.

**Notes written:**

```php
array(
    'notes' => array(
        'prestashop_order_id' => $order->id,
        'prestashop_cart_id'  => $order->id_cart,
    )
)
```

**Field origins:**
- `prestashop_order_id` — `$params['order']->id` (PrestaShop internal order integer ID)
- `prestashop_cart_id` — `$params['order']->id_cart` (PrestaShop cart integer ID)
- `$paymentId` — `$order->getOrderPayments()[0]->transaction_id` (the Razorpay payment ID stored when the order was validated)

**Failure handling:** Catches `BadRequestError` and logs it at level 4. Does not block the confirmation page.

---

## 4. Auto-Webhook Registration (autoEnableWebhook)

**Where:** `razorpay.php:autoEnableWebhook()`
**Triggered by:** `order.php:postProcess()` on every checkout load when `RAZORPAY_WEBHOOK_LAST_VERIFY` is empty **or** older than 86 400 seconds (24 h). Also triggered once on admin settings save (`_postProcess()`).

**Localhost guard:** Resolves the store domain via `gethostbyname()`. If the resolved IP is private/reserved, sets `ENABLE_RAZORPAY_WEBHOOK=off` and returns early — no API calls are made.

### Step 1 — List existing webhooks

Paginates through account webhooks in batches of 10 until all pages are consumed:

```
GET webhooks?count=10&skip=0
GET webhooks?count=10&skip=10
...  (continues while response.count == 10)
```

No request body. Scans `items[].url` to find a match for the store's webhook URL.

### Step 2 — Create or Update

If **no matching webhook found** (`POST`):

```json
{
  "url":    "<store webhook URL>",
  "active": true,
  "events": {
    "payment.authorized": true,
    "order.paid":         true
  },
  "secret": "<RAZORPAY_WEBHOOK_SECRET>"
}
```

If **matching webhook found** (`PUT webhooks/{id}`): same payload structure. Additionally merges any already-enabled events that belong to `$webhookSupportedEvents` (`payment.authorized`, `payment.failed`, `order.paid`) — so enabling `payment.failed` manually in the Razorpay dashboard is preserved.

**Secret generation:** If `RAZORPAY_WEBHOOK_SECRET` config is empty, `createWebhookSecret()` generates a 20-character random string from the alphanumeric+symbol pool `'0123456789ABCDEF...abcdef...'` via `str_shuffle`. The secret is persisted to config before the API call.

**State written after success:**
- `RAZORPAY_WEBHOOK_LAST_VERIFY` = `time()`
- `ENABLE_RAZORPAY_WEBHOOK` = `'on'`

**Route used:** All HTTP calls go through `webhookAPI()` → `$api->request->request($method, $url, $data)`.
