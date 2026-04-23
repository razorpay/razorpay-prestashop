# GitHub Copilot Instructions — Razorpay PrestaShop

## Project
Razorpay PrestaShop Payment Extension v2.5.8
PHP payment module for PrestaShop 1.7.0.0–9.0.3

## Code Style
- PHP with Allman-style braces (opening `{` on new line)
- 4-space indentation
- `camelCase` for method names
- `UPPER_SNAKE_CASE` for configuration constants
- Access config: `Configuration::get('KEY')` / `Configuration::updateValue('KEY', $val)`
- Log: `Logger::addLog($message, $level)` (1=info, 3=warn, 4=error)

## Architecture

**Main module**: `razorpay/razorpay.php` — `Razorpay extends PaymentModule`
**Webhook processor**: `razorpay/razorpay-webhook.php` — `RZP_Webhook` class
**Order controller**: `razorpay/controllers/front/order.php` — creates Razorpay order, returns JSON
**Validation controller**: `razorpay/controllers/front/validation.php` — verifies payment, creates PS order
**Frontend**: `razorpay/script.js` — opens Razorpay Checkout modal

## Critical Patterns

### Amounts (always paise as integer)
```php
// Correct
$amount = number_format(($cart->getOrderTotal() * 100), 0, "", "");

// Wrong — never use float
$amount = $cart->getOrderTotal() * 100;
```

### Signature Verification (always before order creation)
```php
$api->utility->verifyPaymentSignature([
    'razorpay_order_id'  => $rzpOrderId,
    'razorpay_payment_id' => $paymentId,
    'razorpay_signature' => $signature,
]);
```

### Duplicate Prevention
```php
if ($salesOrderData['order_placed']) {
    exit; // order already created
}
```

### Database Access
```php
$db = \Db::getInstance();
// IDs: always cast to int
$db->getValue("SELECT col FROM t WHERE id = " . (int)$id);
// Strings: always use pSQL()
$db->execute("INSERT INTO t (col) VALUES ('" . pSQL($str) . "')");
```

### Instantiate Razorpay Without PS Context
```php
$api = (new Razorpay(false))->getRazorpayApiInstance();
```

## Do Not
- Do not expose `KEY_SECRET` to JavaScript or templates
- Do not skip signature verification
- Do not pass float amounts to Razorpay API
- Do not remove the webhook wait-time guard (300s default)
- Do not call `new Razorpay()` (without `false`) in controllers
