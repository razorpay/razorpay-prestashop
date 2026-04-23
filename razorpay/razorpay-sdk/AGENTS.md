# Razorpay SDK — AGENTS.md

## Overview

Bundled Razorpay PHP SDK v2.8.1. Do not edit — update by replacing the SDK directory.

- **Entry point:** `Razorpay.php` (PSR-4 autoloader for `Razorpay\Api` namespace)
- **Full doc:** `.agents/skills/repo-skill/modules/technical/sdk/api-client.md`

## Instantiation

```php
// Always use module factory — never instantiate directly
$api = $this->module->getRazorpayApiInstance();
// or from a controller:
$api = $this->module->getRazorpayApiInstance();
```

## API Entity Classes (`src/`)

| Class | Endpoint | Key Methods |
|-------|----------|-------------|
| `Order` | `orders/` | `create()`, `fetch()`, `edit()`, `payments()` |
| `Payment` | `payments/` | `fetch()`, `edit()`, `refund()`, `capture()` |
| `Refund` | `refunds/` | `fetch()`, `create()`, `all()` |
| `Utility` | — | `verifyPaymentSignature()`, `verifyWebhookSignature()` |

## Magic Dispatch

`$api->payment` calls `Api::__get('payment')` which instantiates `Razorpay\Api\Payment`. Creates a **new object each call** — no state sharing between two `$api->payment` accesses.

## Raw HTTP Calls

For webhook management (no entity class exists):
```php
$api->request->request('GET', 'webhooks?count=10');
$api->request->request('POST', 'webhooks/', $data);
$api->request->request('PUT', "webhooks/$id", $data);
```

## Static Credential Gotcha

`Api::$key` and `Api::$secret` are PHP statics. Overwritten on every `new Api($key, $secret)`. Safe for single-merchant use only.
