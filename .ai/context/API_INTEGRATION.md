# API Integration — Razorpay PrestaShop

## SDK Setup

The Razorpay PHP SDK v2.8.1 is **bundled** at `razorpay/razorpay-sdk/`. No Composer installation required.

```php
// Loaded via require_once in each file that needs it
require_once __DIR__.'/razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;

// Instantiate (factory method in main class)
$api = new Api($keyId, $keySecret);

// Or via module helper
$api = $this->getRazorpayApiInstance();   // razorpay.php:530
$api = (new Razorpay(false))->getRazorpayApiInstance();  // from controllers
```

Setting app details (for Razorpay analytics):
```php
$api->setAppDetails('Prestashop', $this->module->version);
// e.g., setAppDetails('Prestashop', '2.5.8')
```

---

## All Razorpay API Calls

### 1. Create Razorpay Order
**File**: `controllers/front/order.php:54`
**When**: Customer clicks "Pay"

```php
$order = $api->order->create([
    'amount'          => "10000",    // integer string, paise
    'currency'        => "INR",      // ISO 4217 currency code
    'payment_capture' => 1,          // 1=auto-capture, 0=authorize-only
    'receipt'         => "42",       // cart ID as string (shown in dashboard)
]);
// Returns: Razorpay Order object with $order->id = "order_xxxxxxxxxx"
```

**Error handling**: `catch (\Razorpay\Api\Errors\BadRequestError $e)`

---

### 2. Fetch Payment Details
**File**: `controllers/front/validation.php:68`
**When**: After customer returns from checkout modal

```php
$payment = $api->payment->fetch($paymentId);
// $paymentId = "pay_xxxxxxxxxx" from $_REQUEST['razorpay_payment_id']

// Fields used:
$payment->id      // "pay_xxxxxxxxxx"
$payment->method  // "card", "netbanking", "upi", "wallet", etc.
$payment->amount  // paise (e.g., 10000)
```

**Error handling**: `catch (\Razorpay\Api\Errors\BadRequestError $e)`

---

### 3. Verify Payment Signature
**File**: `controllers/front/validation.php:88`
**When**: After payment, before creating PrestaShop order

```php
$api->utility->verifyPaymentSignature([
    'razorpay_order_id'  => $_SESSION['rzp_order_id'],   // "order_xxx"
    'razorpay_payment_id' => $paymentId,                  // "pay_xxx"
    'razorpay_signature' => $_POST['razorpay_signature'],  // HMAC-SHA256
]);
// Signature = HMAC-SHA256(order_id + "|" + payment_id, key_secret)
// Throws SignatureVerificationError on mismatch
```

**Error handling**: `catch (\Razorpay\Api\Errors\SignatureVerificationError $e)`

---

### 4. Verify Webhook Signature
**File**: `razorpay-webhook.php:67`
**When**: Every incoming webhook request

```php
$this->api->utility->verifyWebhookSignature(
    $rawPost,                                  // raw request body string
    $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'],     // from request header
    $razorpayWebhookSecret                     // RAZORPAY_WEBHOOK_SECRET config
);
// Signature = HMAC-SHA256(raw_body, webhook_secret)
// Throws SignatureVerificationError on mismatch
```

**Error handling**: `catch (Errors\SignatureVerificationError $e)` → HTTP 400, `exit`

---

### 5. Update Payment Notes
**File**: `razorpay.php:277`, `razorpay-webhook.php:278`
**When**: After PrestaShop order is created (both frontend and webhook paths)

```php
$api->payment->fetch($paymentId)->edit([
    'notes' => [
        'prestashop_order_id' => $psOrderId,   // int
        'prestashop_cart_id'  => $cartId,       // int
    ]
]);
// Links Razorpay payment to PS order for reconciliation
```

**Error handling**: `catch (\Razorpay\Api\Errors\BadRequestError $e)` — non-fatal, logged at level 4/3

---

### 6. List Webhooks (Paginated)
**File**: `razorpay.php:448`
**When**: `autoEnableWebhook()` — on admin save or daily re-check

```php
$webhook = $api->request->request('GET', 'webhooks?count=10&skip=' . $skipCount);
// Returns: { count: N, items: [{ id, url, events: {...}, active }] }
// Paginated 10 at a time until $webhook['count'] < 10
```

---

### 7. Create Webhook
**File**: `razorpay.php:493`
**When**: No existing webhook found for this URL

```php
$api->request->request('POST', 'webhooks/', [
    'url'    => $webhookUrl,
    'active' => true,
    'events' => [
        'payment.authorized' => true,
        'order.paid'         => true,
    ],
    'secret' => $webhookSecret,
]);
```

---

### 8. Update Webhook
**File**: `razorpay.php:488`
**When**: Existing webhook found for this URL

```php
$api->request->request('PUT', 'webhooks/' . $webhookId, [
    'url'    => $webhookUrl,
    'active' => true,
    'events' => $this->webhookEvents,
    'secret' => $webhookSecret,
]);
```

---

## Error Exceptions

All Razorpay SDK exceptions are in the `Razorpay\Api\Errors` namespace:

| Exception | When thrown | HTTP status |
|---|---|---|
| `BadRequestError` | Invalid API request, payment not found, etc. | 4xx |
| `SignatureVerificationError` | HMAC mismatch in payment or webhook signature | — |
| `ServerError` | Razorpay server issues | 5xx |
| `GatewayError` | Bank/payment gateway error | — |

---

## API Credentials

- **Live**: `rzp_live_xxxx` / `<secret>`
- **Test**: `rzp_test_xxxx` / `<secret>`

Stored in PrestaShop configuration as `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET`.

The SDK auto-selects live vs. test mode based on the key prefix.

---

## SDK Internal Structure

```
razorpay-sdk/
├── Razorpay.php          ← Autoloader (require this file)
├── src/
│   ├── Api.php           ← Main client (new Api($keyId, $keySecret))
│   ├── Request.php       ← HTTP transport wrapper
│   ├── Utility.php       ← verifyPaymentSignature(), verifyWebhookSignature()
│   ├── Order.php         ← $api->order->create(), fetch(), etc.
│   ├── Payment.php       ← $api->payment->fetch(), edit(), capture(), etc.
│   ├── Refund.php        ← $api->refund->create(), fetch()
│   └── [other entities]
└── libs/
    └── Requests-1.8.0/   ← HTTP client library
```
