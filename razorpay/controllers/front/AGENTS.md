# Front Controllers — AGENTS.md

## Overview

Three HTTP endpoints exposed by the Razorpay PrestaShop module. All extend `ModuleFrontController`.

## Endpoints

### `order.php` — POST /modules/razorpay/order

Creates a Razorpay Order before the checkout modal opens.

- **When:** Button click in script.js triggers AJAX POST
- **Key doc:** `.agents/skills/repo-skill/modules/domain/payment-flow/flows.md`
- **Returns:** `{rzp_order_id, amount}` on success, `{message}` + HTTP 400 on error
- **DB write:** INSERT or UPDATE `razorpay_sales_order` with cart_id → rzp_order_id
- **Gotcha:** POST-only — GET returns HTTP 400 immediately

### `validation.php` — POST /modules/razorpay/validation

Validates completed payment and creates the PrestaShop order.

- **When:** script.js submits form after Razorpay Checkout callback
- **Key doc:** `.agents/skills/repo-skill/modules/domain/payment-flow/flows.md`
- **Parameters:** `razorpay_payment_id`, `razorpay_signature`, `cart_id`
- **Signature:** HMAC-SHA256 of `rzp_order_id|razorpay_payment_id` vs KEY_SECRET
- **Fallback:** if `$_SESSION['rzp_order_id']` is absent, falls back to DB lookup by cart_id

### `webhook.php` — POST /modules/razorpay/webhook

Entry point for Razorpay server-to-server webhook events.

- **Key doc:** `.agents/skills/repo-skill/modules/domain/webhook-processing/concept.md`
- **Delegates to:** `RZP_Webhook::process()` in razorpay-webhook.php
- **Returns:** 409 (retry needed), 200 (done), 400 (stop retrying)

## Adding a New Front Controller

1. Create `controllers/front/newname.php` extending `ModuleFrontController`
2. No registration needed — PS auto-discovers front controllers by filename
3. URL: `/modules/razorpay/newname`
