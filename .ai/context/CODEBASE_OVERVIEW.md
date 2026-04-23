# Codebase Overview — Razorpay PrestaShop Payment Extension

## What This Module Does

This is a PrestaShop payment gateway module that integrates Razorpay's payment infrastructure. It enables Indian merchants to accept payments via Credit/Debit Cards, NetBanking, Wallets, and UPI using a **modal checkout overlay** — customers complete payment without ever leaving the merchant's website.

## Module Architecture

```
Razorpay PrestaShop Module
├── Module Class (razorpay.php)
│   ├── Admin configuration (hooks, settings panel)
│   ├── Checkout integration (hookHeader, hookPaymentOptions)
│   └── Webhook auto-setup (autoEnableWebhook)
│
├── Frontend Controllers
│   ├── order.php       — Creates Razorpay Order, returns JSON
│   ├── validation.php  — Verifies payment, creates PrestaShop order
│   └── webhook.php     — Receives server-to-server webhook events
│
├── JavaScript (script.js)
│   └── Checkout modal orchestration (show/hide button, open modal, submit form)
│
├── Webhook Processor (razorpay-webhook.php)
│   └── RZP_Webhook class — processes order.paid, payment.authorized, payment.failed
│
└── Bundled SDK (razorpay-sdk/)
    └── PHP client for Razorpay REST API
```

## Class Hierarchy

```
PaymentModule (PrestaShop core)
└── Razorpay                          razorpay/razorpay.php

ModuleFrontController (PrestaShop core)
├── RazorpayOrderModuleFrontController      controllers/front/order.php
├── RazorpayValidationModuleFrontController controllers/front/validation.php
└── RazorpayWebhookModuleFrontController    controllers/front/webhook.php

(standalone class)
└── RZP_Webhook                             razorpay/razorpay-webhook.php
```

## PrestaShop Hooks Used

| Hook | Method | Purpose |
|---|---|---|
| `header` | `hookHeader()` | Inject checkout.js + script.js + JS variables on order page |
| `paymentOptions` | `hookPaymentOptions()` | Register Razorpay as a payment method |
| `orderConfirmation` | `hookOrderConfirmation()` | Update Razorpay payment with PS order ID |
| `paymentReturn` | `hookPaymentReturn()` | Render order confirmation template |

## Data Flow Summary

```
Browser                   PrestaShop Server           Razorpay API
   |                             |                         |
   |-- select Razorpay --------->|                         |
   |-- click Pay --------------->|                         |
   |   POST /razorpay/order      |-- Orders API create --->|
   |<-- { rzp_order_id, amount } |<-- { id: "order_xxx" } |
   |                             |                         |
   |-- open modal (checkout.js) via Razorpay CDN           |
   |<-- payment modal (customer enters details) ---------->|
   |   payment completed         |                         |
   |-- POST /razorpay/validation |                         |
   |   (payment_id + signature)  |-- verify signature      |
   |                             |-- validateOrder()       |
   |<-- redirect to confirmation |                         |
   |                             |                         |
   |                (parallel)   |<-- order.paid webhook --|
   |                             |   RZP_Webhook::process()|
   |                             |   (fallback if frontend |
   |                             |    failed)              |
```

## Module Lifecycle

### Install (`razorpay.php::install()`)
1. Creates `razorpay_sales_order` table
2. Registers hooks: `header`, `orderConfirmation`, `paymentOptions`, `paymentReturn`

### Uninstall (`razorpay.php::uninstall()`)
1. Deletes all 7 configuration keys from `ps_configuration`
2. Drops `razorpay_sales_order` table

### Admin Settings Save (`razorpay.php::_postProcess()`)
1. Saves API keys, payment action, webhook settings
2. Calls `autoEnableWebhook()` which creates/updates webhook via Razorpay API

## Key File Responsibilities

### `razorpay/razorpay.php`
- Central module class
- Owns all PrestaShop hook implementations
- Manages configuration (read in `__construct`, write in `_postProcess`)
- Provides `getRazorpayApiInstance()` factory method
- Handles webhook auto-setup logic
- Renders admin settings via Smarty template

### `razorpay/controllers/front/order.php`
- Entry: HTTP POST from JavaScript
- Creates Razorpay Order via API
- Stores `cart_id` + `rzp_order_id` in `razorpay_sales_order`
- Stores `rzp_order_id` in PHP session
- Returns JSON response to JavaScript

### `razorpay/controllers/front/validation.php`
- Entry: Form POST from JavaScript (after payment modal)
- Verifies HMAC payment signature
- Calls `validateOrder()` to create PrestaShop order
- Updates `razorpay_sales_order` with `by_frontend=1`, `order_placed=1`
- Redirects to order confirmation

### `razorpay/razorpay-webhook.php`
- Entry: Called from `controllers/front/webhook.php`
- Verifies webhook HMAC signature from `X-Razorpay-Signature` header
- Routes to event handlers: `orderPaid()`, `paymentAuthorized()`, `paymentFailed()`
- `orderPaid()`: Implements wait-time guard, creates PrestaShop order if frontend missed it

### `razorpay/script.js`
- Runs on PrestaShop checkout page (`controller=order`)
- Injects a custom "Pay" button before the default submit button
- Shows/hides the button based on which payment method is selected
- On click: POSTs to `/razorpay/order`, opens Razorpay Checkout modal
- On payment success: submits form to `/razorpay/validation`

## Supported PrestaShop Versions
- Minimum: 1.7.0.0 (introduced modern `PaymentModule` and `PaymentOption`)
- Maximum: 9.0.3 (current latest, actively supported)
- PS 8.x support added in v2.5.2
- PS 9.0 support added in v2.5.7

## PHP SDK (Bundled)
- Version: 2.8.1
- Path: `razorpay/razorpay-sdk/`
- Loaded via: `require_once __DIR__.'/razorpay-sdk/Razorpay.php'`
- Key classes used: `Api`, `Utility` (for signature verification)
- HTTP transport: `Requests-1.8.0` library in `razorpay-sdk/libs/`
