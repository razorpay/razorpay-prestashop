# Low-Level Design — Razorpay PrestaShop Extension

## Class Design

### Razorpay (razorpay/razorpay.php)

```
Razorpay extends PaymentModule
├── Properties
│   ├── private $_html: string          — Admin panel HTML buffer
│   ├── private $KEY_ID: string|null    — API Key ID from config
│   ├── private $KEY_SECRET: string|null — API Key Secret from config
│   ├── public $ENABLE_WEBHOOK: string|null
│   ├── public $WEBHOOK_SECRET: string|null
│   └── private $_postErrors: array
│
├── Constants
│   ├── RAZORPAY_CHECKOUT_URL: string   — CDN URL for checkout.js
│   ├── CAPTURE: 'capture'
│   ├── AUTHORIZE: 'authorize'
│   ├── PAYMENT_AUTHORIZED: 'payment.authorized'
│   ├── PAYMENT_FAILED: 'payment.failed'
│   ├── ORDER_PAID: 'order.paid'
│   └── CURRENCY_NOT_ALLOWED: ['KWD', 'OMR', 'BHD']
│
└── Methods
    ├── __construct()                   — Load config, set PS module metadata
    ├── install()                       — Create DB table, register hooks
    ├── uninstall()                     — Delete config, drop DB table
    ├── getContent()                    — Admin panel router
    ├── _displayForm()                  — Assign Smarty vars, render admin.tpl
    ├── _postValidation()               — Validate admin form submit
    ├── _postProcess()                  — Save settings, call autoEnableWebhook
    ├── hookHeader()                    — Inject JS assets + vars on order page
    ├── hookPaymentOptions()            — Register Razorpay payment option
    ├── hookOrderConfirmation()         — Update payment notes with PS order ID
    ├── hookPaymentReturn()             — Render confirmation page
    ├── autoEnableWebhook()             — Create/update webhook via Razorpay API
    ├── webhookAPI($method, $url, $data) — HTTP wrapper for webhook management
    ├── createWebhookSecret()           — Generate 20-char random secret
    └── getRazorpayApiInstance()        — Factory: new Api($KEY_ID, $KEY_SECRET)
```

### RZP_Webhook (razorpay/razorpay-webhook.php)

```
RZP_Webhook
├── Properties
│   ├── protected $razorpay: Razorpay   — Module instance (new Razorpay(false))
│   └── protected $api: Api             — SDK API client
│
└── Methods
    ├── __construct()                   — Initialize Razorpay + Api instances
    ├── process()                       — Main entry: verify signature, route event
    ├── paymentAuthorized(array $data)  — No-op handler
    ├── paymentFailed(array $data)      — No-op handler
    └── orderPaid(array $data)          — Full order creation logic with wait-time
```

### Controllers

```
RazorpayOrderModuleFrontController extends ModuleFrontController
└── postProcess()  — Create Razorpay order, return JSON

RazorpayValidationModuleFrontController extends ModuleFrontController
└── postProcess()  — Verify signature, create PS order, redirect

RazorpayWebhookModuleFrontController extends ModuleFrontController
└── postProcess()  — Instantiate RZP_Webhook, call process()
```

---

## Key Algorithms

### Amount Conversion
```php
// Float cart total → integer paise for Razorpay API
$paise = (int) number_format(($cartTotal * 100), 0, "", "");
// e.g., 1500.50 → 150050

// Integer paise → decimal for storage
$decimal = number_format($paise / 100, 2, ".", "");
// e.g., 150050 → "1500.50"
```

### Webhook Signature Verification
```
Input: rawBody (string), signature (hex), secret (string)
Algorithm: HMAC-SHA256(rawBody, secret)
Compare: computedHex === signature (constant-time comparison in SDK)
```

### Payment Signature Verification
```
Input: rzpOrderId, rzpPaymentId, signature (hex), keySecret
Payload: rzpOrderId + "|" + rzpPaymentId
Algorithm: HMAC-SHA256(payload, keySecret)
Compare: computedHex === signature
```

### Webhook Wait-Time Guard
```
on webhook arrival:
  if webhook_count == 0:
    first_time = now()
    webhook_first_notified_at = first_time
  else:
    first_time = saved webhook_first_notified_at

  webhook_count += 1

  elapsed = now() - first_time
  if elapsed < RAZORPAY_WEBHOOK_WAIT_TIME (default 300):
    return HTTP 409  // too early, Razorpay retries
  else:
    proceed to create order
```

---

## Database Interactions

### Write Paths

| Operation | Table | File | When |
|---|---|---|---|
| INSERT (cart + rzp_order) | razorpay_sales_order | order.php | Order creation |
| UPDATE (rzp_order if cart exists) | razorpay_sales_order | order.php | Order creation |
| UPDATE (payment_id + by_frontend) | razorpay_sales_order | validation.php | Payment success |
| UPDATE (webhook_first_notified_at) | razorpay_sales_order | razorpay-webhook.php | First webhook |
| UPDATE (webhook_count) | razorpay_sales_order | razorpay-webhook.php | Each webhook |
| UPDATE (by_webhook + order_placed) | razorpay_sales_order | razorpay-webhook.php | Webhook creates order |

### Read Paths

| Query | File | Purpose |
|---|---|---|
| SELECT entity_id WHERE cart_id | order.php | Check if row exists before insert |
| SELECT entity_id, order_placed, ... WHERE cart_id + rzp_order_id | razorpay-webhook.php | Full state check |
| SELECT rzp_order_id WHERE cart_id | validation.php | Fallback if session lost |
| SELECT entity_id WHERE cart_id + rzp_order_id | validation.php | Get row for update |

---

## JavaScript Architecture (script.js)

```
DOMContentLoaded
├── Create #razorpay-pay-button (hidden, CSS class 'not-shown')
├── Insert button before #payment-confirmation div
├── Extract customer phone from prestashop.customer.addresses[]
│
├── Event: #checkout-payment-step change
│   └── If Razorpay radio selected AND terms accepted:
│       ├── Show #razorpay-pay-button (class → 'shown')
│       └── CSS hides original #payment-confirmation button
│
└── Event: #razorpay-pay-button click
    ├── POST to order_controller (get rzp_order_id)
    └── On success: renderPaymentFrame(response)
        ├── Build options object with customer prefill
        ├── new Razorpay(options).open()
        └── handler(obj):  [called on payment success]
            ├── Inject razorpay_signature hidden input
            ├── Add razorpay_payment_id to form action URL
            └── Click original submit button → POST to validation_controller
```

---

## Error Handling Strategy

| Layer | Error Type | Handling |
|---|---|---|
| order.php | Razorpay API error | Catch BadRequestError, return JSON error message |
| order.php | Invalid currency | Log warning, return JSON error message |
| validation.php | Cart invalid | Redirect to order step 1 |
| validation.php | Module disabled | die() with translated message |
| validation.php | Signature mismatch | Log level 4, echo error HTML, exit |
| validation.php | Payment fetch fails | Log level 4, echo error HTML, exit |
| razorpay-webhook.php | Signature mismatch | Log level 4, HTTP 400, exit |
| razorpay-webhook.php | Order already placed | Log info, exit |
| razorpay-webhook.php | Wait time not elapsed | Log info, HTTP 409, exit |
| razorpay.php | Notes update fails | Log (non-fatal), continue |
| autoEnableWebhook | Localhost detected | Log warning, set ENABLE=off, return |
