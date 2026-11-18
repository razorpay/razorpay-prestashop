# Razorpay PrestaShop Module — Developer Quick Reference

Module root: `razorpay/` | Version: 2.5.8 | PS: 1.7+

---

## 1. Add a New Configuration Key

Configuration keys are stored in PrestaShop's `ps_configuration` table via the `Configuration` class. Four touch-points required for each new key:

**Step 1 — Load in constructor** (`razorpay.php:__construct()`)
```php
$config = Configuration::getMultiple([
    'RAZORPAY_KEY_ID',
    'RAZORPAY_KEY_SECRET',
    'YOUR_NEW_KEY',        // add here
]);

if (array_key_exists('YOUR_NEW_KEY', $config)) {
    $this->YOUR_PROPERTY = $config['YOUR_NEW_KEY'];
}
```

**Step 2 — Save on form submit** (`razorpay.php:_postProcess()`)
```php
Configuration::updateValue('YOUR_NEW_KEY', Tools::getValue('FORM_FIELD_NAME'));
```

**Step 3 — Display in admin form** (`razorpay.php:_displayForm()`)
```php
$this->smarty->assign([
    'yourNewKeyValue' => Configuration::get('YOUR_NEW_KEY'),
]);
// Then add the field in views/templates/admin/admin.tpl
```

**Step 4 — Clean up on uninstall** (`razorpay.php:uninstall()`)
```php
Configuration::deleteByName('YOUR_NEW_KEY');
```

Existing keys for reference: `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_PAYMENT_ACTION`, `ENABLE_RAZORPAY_WEBHOOK`, `RAZORPAY_WEBHOOK_SECRET`, `RAZORPAY_WEBHOOK_WAIT_TIME`, `RAZORPAY_WEBHOOK_LAST_VERIFY`.

---

## 2. Register a New PrestaShop Hook

**Step 1 — Register in install()** (`razorpay.php:install()`)
```php
if (parent::install() and
    $this->registerHook('header') and
    $this->registerHook('orderConfirmation') and
    $this->registerHook('paymentOptions') and
    $this->registerHook('paymentReturn') and
    $this->registerHook('yourNewHook'))   // add here, chained with 'and'
{
    return true;
}
```

**Step 2 — Add the handler method** in the same `razorpay.php` class. Naming convention is `hook` + PascalCase hook name:
```php
public function hookYourNewHook($params)
{
    // $params contents depend on the hook — check PS documentation
    // for what is injected at this hook point
}
```

Existing hooks as examples:
- `razorpay.php:hookHeader()` — injects JS and passes checkout vars to front-end
- `razorpay.php:hookPaymentOptions()` — returns the `PaymentOption` object shown at checkout
- `razorpay.php:hookOrderConfirmation()` — fires after order is confirmed, used to update payment notes
- `razorpay.php:hookPaymentReturn()` — renders the post-payment Smarty template

---

## 3. Make a Razorpay API Call

**Get the API instance** — always go through `razorpay.php:getRazorpayApiInstance()`, which reads credentials from the already-loaded config properties:
```php
$api = (new Razorpay(false))->getRazorpayApiInstance();
// Returns: new \Razorpay\Api\Api($this->KEY_ID, $this->KEY_SECRET)
```

From inside the `Razorpay` class itself, use `$this->getRazorpayApiInstance()`.

**Entity access via magic `__get`** (`razorpay-sdk/src/Api.php:__get()`): property access on `$api` instantiates the matching class under `Razorpay\Api\`. Available entities in `razorpay-sdk/src/`:

| Property | Class file | Common methods |
|----------|-----------|----------------|
| `$api->order` | `Order.php` | `create($data)`, `fetch($id)`, `all($options)` |
| `$api->payment` | `Payment.php` | `fetch($id)`, `edit($data)`, `capture($data)` |
| `$api->refund` | `Refund.php` | `create($data)`, `fetch($id)` |
| `$api->utility` | `Utility.php` | `verifyWebhookSignature()`, `verifyPaymentSignature()` |
| `$api->transfer` | `Transfer.php` | `create($data)`, `fetch($id)` |

**Usage examples from the module:**
```php
// Create an order (order.php:postProcess())
$order = $api->order->create([
    'amount'          => $amountInPaise,   // multiply rupees * 100, no decimals
    'currency'        => 'INR',
    'payment_capture' => 1,                // 0 = authorize-only
    'receipt'         => (string) $cartId,
]);
$rzpOrderId = $order->id;

// Fetch a payment and update notes (razorpay.php:hookOrderConfirmation())
$api->payment->fetch($paymentId)->edit([
    'notes' => ['prestashop_order_id' => $orderId]
]);

// Verify webhook signature (razorpay-webhook.php:process())
$api->utility->verifyWebhookSignature($rawPostBody, $signatureHeader, $webhookSecret);
```

**Error handling** — catch `\Razorpay\Api\Errors\BadRequestError` for API-level errors (invalid params, auth failures). Catch `\Exception` for network/unexpected errors. Log with `Logger::addLog($message, $level)` where level 4 = error.

---

## 4. Test a Webhook Locally

**Webhook URL pattern:**
```
https://{your-shop-domain}/module/razorpay/webhook
```
The URL is generated at runtime via `$this->context->link->getModuleLink('razorpay', 'webhook', [], true)` — find it in the admin settings page under "Webhook URL".

**Why localhost won't work:** `razorpay.php:autoEnableWebhook()` checks that the shop domain resolves to a public IPv4 address. If `gethostbyname()` returns a private/reserved range IP, it sets `ENABLE_RAZORPAY_WEBHOOK` to `'off'` and logs the failure. Use a tunnel (ngrok, localtunnel) that exposes a public URL.

**Required headers:**
```
Content-Type: application/json
X-Razorpay-Signature: <hmac-sha256-hex>
```

**Generate a valid test signature** (`razorpay-sdk/src/Utility.php:verifySignature()`):

The algorithm is: `HMAC-SHA256(rawRequestBody, webhookSecret)` encoded as lowercase hex.

```bash
# Shell — replace SECRET with value from RAZORPAY_WEBHOOK_SECRET config key
BODY='{"event":"order.paid","payload":{"payment":{"entity":{"id":"pay_test","amount":50000,"method":"card","notes":{"prestashop_cart_id":"42"}}},"order":{"entity":{"id":"order_test"}}}}'
SECRET="your_webhook_secret_here"
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
echo $SIG
```

```php
// PHP equivalent
$sig = hash_hmac('sha256', $rawBody, $webhookSecret);
```

**Minimal `order.paid` test payload:**
```json
{
  "event": "order.paid",
  "payload": {
    "payment": {
      "entity": {
        "id": "pay_testXXXXXXXX",
        "amount": 50000,
        "method": "card",
        "notes": {
          "prestashop_cart_id": "42"
        }
      }
    },
    "order": {
      "entity": {
        "id": "order_testXXXXXXXX"
      }
    }
  }
}
```

**What the webhook handler checks** (`razorpay-webhook.php:process()`):

1. `ENABLE_RAZORPAY_WEBHOOK` config = `'on'` (string, not boolean)
2. `HTTP_X_RAZORPAY_SIGNATURE` header present
3. `RAZORPAY_WEBHOOK_SECRET` config non-empty
4. HMAC-SHA256 signature matches raw POST body
5. `event` field matches one of: `payment.authorized`, `payment.failed`, `order.paid`

The wait-time guard (`RAZORPAY_WEBHOOK_WAIT_TIME`, default 300 s) causes `order.paid` to return HTTP 409 on the first delivery within that window — this is intentional, giving the front-end flow time to complete first. Second delivery after the wait succeeds.
