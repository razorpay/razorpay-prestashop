# Razorpay PrestaShop Module

## Module Overview

PrestaShop payment gateway module (v2.5.8) that integrates Razorpay's payment processing.

- **Compatibility:** PrestaShop 1.6.x, 1.7.x, 8.x, 9.x
- **Architecture:** `Razorpay extends PaymentModule` — 3 front controllers, 1 webhook handler, 1 custom DB table
- **Key domain knowledge:** `.agents/skills/repo-skill/`

## Key Files

| File | Responsibility |
|------|---------------|
| `razorpay.php` | Main module class: install, hooks, admin config, API instance factory |
| `razorpay-webhook.php` | `RZP_Webhook` class: signature verify, dedup, order creation via webhook |
| `script.js` | Frontend: injects Razorpay Checkout modal, AJAX to order.php, form submit to validation.php |
| `controllers/front/order.php` | `POST /modules/razorpay/order` — creates Razorpay Order before modal opens |
| `controllers/front/validation.php` | `POST /modules/razorpay/validation` — verifies signature, calls PS validateOrder() |
| `controllers/front/webhook.php` | `POST /modules/razorpay/webhook` — entry point for server-side webhook events |

## Critical Knowledge

Load these docs before making any changes:

- **Payment flow:** `.agents/skills/repo-skill/modules/domain/payment-flow/concept.md`
- **Webhook flow:** `.agents/skills/repo-skill/modules/domain/webhook-processing/concept.md`
- **Race condition:** `.agents/skills/repo-skill/modules/domain/webhook-processing/constraints.md`
- **DB schema:** `.agents/skills/repo-skill/modules/technical/database/schema.md`
- **Quick ref:** `.agents/skills/repo-skill/core/quick-ref.md`

## Gotchas

1. **Amount in paise** — multiply by 100 before sending to Razorpay API. KWD/OMR/BHD currencies are blocked.
2. **Webhook vs frontend race** — `razorpay_sales_order.order_placed` flag prevents duplicate orders. Webhook waits 300s to give frontend priority.
3. **Blank webhook secret** — if `RAZORPAY_WEBHOOK_SECRET` is empty, signature verification is silently skipped.
4. **Smarty scoping (PS 1.6 vs 1.7+)** — `hookHeader()` uses `Media::addJsDef()` (not Smarty assign) to inject JS vars. Fixed in 2.5.8.
5. **API instance statics** — `Api::$key`/`$secret` are PHP statics; overwritten on every `getRazorpayApiInstance()` call.
