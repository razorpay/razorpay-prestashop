# Claude Code — Razorpay PrestaShop Project Memory

This file is auto-loaded by Claude Code at session start.

## Project
Razorpay PrestaShop Payment Extension v2.5.8
PHP module for PrestaShop 1.7.0.0–9.0.3

## Full Context
See root `AGENTS.md` for complete architecture, flows, and conventions.
See root `CLAUDE.md` for Claude-specific rules and model selection.

## Quick Reference

### Critical Files
```
razorpay/razorpay.php                     — Main module class
razorpay/razorpay-webhook.php             — RZP_Webhook class
razorpay/controllers/front/order.php      — Creates Razorpay order (JSON API)
razorpay/controllers/front/validation.php — Verifies payment, creates PS order
razorpay/script.js                        — Checkout modal JS
```

### Amount Rule
```php
// CORRECT
$amount = number_format(($total * 100), 0, "", "");  // "10000" for ₹100

// WRONG — never do this
$amount = $total * 100;  // could be float
```

### Never Skip Signature Verification
```php
// payment flow (validation.php)
$api->utility->verifyPaymentSignature($attributes);  // must run before validateOrder()

// webhook flow (razorpay-webhook.php)
$api->utility->verifyWebhookSignature($post, $signature, $secret);  // must run before orderPaid()
```

### Duplicate Prevention Pattern
```php
// Always check before validateOrder()
if ($salesOrderData['order_placed']) {
    exit;  // already done
}
```

### DB Access Pattern
```php
$db = \Db::getInstance();
$db->getValue("SELECT col FROM table WHERE id = " . (int)$id);
$db->execute("INSERT ... VALUES ('$safeStr')", false);  // use pSQL() for strings
```

### Use Razorpay(false) in Controllers
```php
// In controllers and webhook — NOT new Razorpay()
$api = (new Razorpay(false))->getRazorpayApiInstance();
```
