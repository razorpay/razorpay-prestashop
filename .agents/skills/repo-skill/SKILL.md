---
name: razorpay-prestashop
description: Domain knowledge for the Razorpay PrestaShop payment gateway module (v2.5.8). Covers payment flow, webhook processing, module configuration, DB schema, SDK usage, and PrestaShop integration. Load when working on any file in this repository.
---

# Razorpay PrestaShop — Repo Skill

## Loading Rules

### always-load

These files are loaded at session start for any work in this repository:

- `core/boundaries.md` — module ownership and API surface
- `core/quick-ref.md` — common operations cheat sheet

### on-mention

Load when the named topic is mentioned or when working on the named file:

| Topic / File Mention | Load |
|---------------------|------|
| payment, checkout, order, cart, paise | `modules/domain/payment-flow/concept.md` |
| payment flow, order.php, validation.php, script.js | `modules/domain/payment-flow/flows.md` + `modules/domain/payment-flow/decisions.md` |
| currency, KWD, OMR, BHD, amount | `modules/domain/payment-flow/constraints.md` |
| webhook, razorpay-webhook.php | `modules/domain/webhook-processing/concept.md` + `modules/domain/webhook-processing/flows.md` |
| race condition, duplicate order, order_placed | `modules/domain/webhook-processing/constraints.md` |
| webhook wait time, 300s, dedup | `modules/domain/webhook-processing/decisions.md` |
| config, KEY_ID, KEY_SECRET, admin, autoEnableWebhook | `modules/domain/module-config/concept.md` + `modules/domain/module-config/flows.md` |
| razorpay_sales_order, DB, database | `modules/technical/database/schema.md` |
| script.js, checkout.js, modal, frontend | `modules/technical/frontend/checkout-js.md` |
| SDK, Api.php, entity, razorpay-sdk | `modules/technical/sdk/api-client.md` |
| hook, hookHeader, hookPaymentOptions, Smarty | `modules/technical/hooks/registered-hooks.md` |
| Orders API, order create, payment_capture | `modules/integration/razorpay-api/orders.md` |
| payment fetch, signature, HMAC, verifyPaymentSignature | `modules/integration/razorpay-api/payments.md` |
| webhook events, order.paid, payment.authorized | `modules/integration/razorpay-api/webhooks.md` |
| validateOrder, PaymentModule, PS integration | `modules/integration/prestashop/payment-module.md` |

### on-file-change

Load when the named source file is being modified:

| Source File | Load |
|-------------|------|
| `razorpay/controllers/front/order.php` | `modules/domain/payment-flow/flows.md`, `modules/technical/database/schema.md` |
| `razorpay/controllers/front/validation.php` | `modules/domain/payment-flow/flows.md`, `modules/integration/razorpay-api/payments.md` |
| `razorpay/controllers/front/webhook.php` | `modules/domain/webhook-processing/concept.md` |
| `razorpay/razorpay-webhook.php` | `modules/domain/webhook-processing/flows.md`, `modules/domain/webhook-processing/constraints.md`, `modules/technical/database/schema.md` |
| `razorpay/razorpay.php` | `modules/domain/module-config/flows.md`, `modules/technical/hooks/registered-hooks.md` |
| `razorpay/script.js` | `modules/technical/frontend/checkout-js.md`, `modules/domain/payment-flow/flows.md` |
| `razorpay/razorpay-sdk/**` | `modules/technical/sdk/api-client.md` |
