# Razorpay Payments API Integration

Every Payments API call this module makes, plus what the module explicitly does NOT do.

---

## 1. Fetch Payment

**Where:** `validation.php:postProcess()` and `razorpay-webhook.php:orderPaid()`

**Why fetched in validation.php:** The `payment->method` field is not available from the browser POST. The module needs it to build the PrestaShop `payment_method` label (`"razorpay.netbanking"`, `"razorpay.card"`, etc.) that is stored on the order. The `payment->amount` field is also used to record `amount_paid` in `razorpay_sales_order`.

**Fields used from the response (validation.php):**

| Field | Used for |
|---|---|
| `$payment->id` | Stored as `transaction_id` in `$extraData`, written to PrestaShop order payments table |
| `$payment->method` | Appended to `"razorpay."` to build the payment method label |
| `$payment->amount` | Divided by 100 to get decimal amount, stored in `razorpay_sales_order.amount_paid` |

**Why fetched in webhook orderPaid:** Same reason — `method` is read from `$data['payload']['payment']['entity']['method']` (the webhook payload), so no extra API call is needed in the webhook path. The `fetch()` in `razorpay-webhook.php:orderPaid()` is called **only for the notes edit** that follows, not for reading payment data.

---

## 2. Verify Payment Signature (frontend flow)

**Where:** `validation.php:postProcess()` — `$api->utility->verifyPaymentSignature($attributes)`
**Implementation:** `razorpay-sdk/src/Utility.php:verifyPaymentSignature()`

**Inputs:**

| Attribute | Source |
|---|---|
| `razorpay_order_id` | `$_SESSION['rzp_order_id']` (set in `order.php`). Fallback: `razorpay_sales_order.rzp_order_id` queried by `cart_id` from `$_REQUEST['cart_id']` when session is missing |
| `razorpay_payment_id` | `$_REQUEST['razorpay_payment_id']` (posted by Razorpay checkout JS) |
| `razorpay_signature` | `$_POST['razorpay_signature']` (posted by Razorpay checkout JS) |

**Algorithm (`Utility.php:verifySignature()`):**

```
payload          = razorpay_order_id + "|" + razorpay_payment_id
expectedSignature = HMAC-SHA256(payload, key_secret)
verified          = hash_equals(expectedSignature, razorpay_signature)
```

The `key_secret` is the merchant's Razorpay API secret (`RAZORPAY_KEY_SECRET` config). Uses PHP `hash_equals()` when available; falls back to a constant-time XOR loop to mitigate timing attacks.

**On failure:** Throws `SignatureVerificationError`. The controller catches it, renders an inline error page with cart ID and payment ID, and calls `exit` — no PrestaShop order is created.

---

## 3. Verify Webhook Signature

**Where:** `razorpay-webhook.php:process()` — `$this->api->utility->verifyWebhookSignature(...)`
**Implementation:** `razorpay-sdk/src/Utility.php:verifyWebhookSignature()`

**Inputs:**

| Argument | Source |
|---|---|
| `$post` | Raw request body from `file_get_contents('php://input')` |
| `$actualSignature` | `$_SERVER['HTTP_X_RAZORPAY_SIGNATURE']` (Razorpay sends this header) |
| `$secret` | `Configuration::get('RAZORPAY_WEBHOOK_SECRET')` |

**Algorithm:**

```
expectedSignature = HMAC-SHA256(raw_post_body, webhook_secret)
verified          = hash_equals(expectedSignature, HTTP_X_RAZORPAY_SIGNATURE)
```

The payload signed is the **raw JSON body** (not parsed), preserving exact byte sequence.

**On failure:** Logs the error at level 4, returns HTTP 400, and calls `exit`. Processing stops; Razorpay will NOT retry on 400.

**Guard conditions** that skip verification entirely (and return without processing):
- JSON decode error
- `ENABLE_RAZORPAY_WEBHOOK` config is not `'on'`
- `HTTP_X_RAZORPAY_SIGNATURE` header is absent
- `RAZORPAY_WEBHOOK_SECRET` is empty

---

## 4. Update Payment Notes

The module writes notes to the Razorpay payment object in **two places**. Both call `$api->payment->fetch($paymentId)->edit(array('notes' => ...))`.

### 4a. After frontend order creation (hookOrderConfirmation)

**Where:** `razorpay.php:hookOrderConfirmation()`

```php
array('notes' => array(
    'prestashop_order_id' => $order->id,
    'prestashop_cart_id'  => $order->id_cart,
))
```

Fires when PrestaShop renders the order-confirmation page, after `validation.php` has already validated the order.

### 4b. After webhook order creation (orderPaid)

**Where:** `razorpay-webhook.php:orderPaid()`

```php
array('notes' => array(
    'prestashop_order_id' => $this->razorpay->currentOrder,
    'prestashop_cart_id'  => $cart->id,
))
```

Fires only when the webhook path creates a new order (i.e., the frontend did not complete first). The `prestashop_order_id` here is the ID returned by `validateOrder()` stored in `$this->razorpay->currentOrder`.

**Why notes are written:** The Razorpay dashboard shows these notes alongside the payment, giving support staff and merchants a direct link between a Razorpay payment and a PrestaShop order without cross-referencing two systems manually.

---

## 5. What This Module Does NOT Do

| Operation | Status | Reason |
|---|---|---|
| Capture payment | Not implemented | When `payment_capture=1` on the order, Razorpay auto-captures. When `payment_capture=0` (authorize mode), no capture call is ever sent by this module. |
| Refund | Not implemented | No `payment->refund()` call exists anywhere in the module. |
| Transfer | Not implemented | No transfer or route calls exist. |
| Fetch order from Razorpay | Not implemented | Order identity is tracked locally in `razorpay_sales_order`; no `order->fetch()` call is made. |
| Cancel payment | Not implemented | No cancellation flow exists. |
