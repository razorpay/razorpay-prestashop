# BUILD_CHECKLIST.md — razorpay-prestashop

## Service Context

- **Module:** Razorpay PrestaShop Payment Gateway Plugin
- **Version:** 2.5.8
- **Type:** PHP PrestaShop Module (extends PaymentModule)
- **PS Compatibility:** 1.6.x, 1.7.x, 8.x, 9.x
- **Architecture:** Single-module, 3 front controllers, 1 webhook handler, 1 custom DB table

---

## Core Files

```
razorpay/
├── razorpay.php               # Main module class (Razorpay extends PaymentModule)
├── razorpay-webhook.php       # Webhook handler (RZP_Webhook)
├── script.js                  # Frontend Checkout.js integration
├── config.xml                 # Module metadata
├── CHANGELOG.md               # Version history
├── controllers/
│   └── front/
│       ├── order.php          # POST /modules/razorpay/order — creates Razorpay order
│       ├── validation.php     # POST /modules/razorpay/validation — validates payment
│       └── webhook.php        # POST /modules/razorpay/webhook — receives webhooks
├── razorpay-sdk/
│   ├── Razorpay.php           # SDK autoloader + PSR-4 namespace setup
│   └── src/
│       ├── Api.php            # Main API client (magic property dispatch)
│       ├── Request.php        # HTTP client (Requests library, Basic Auth)
│       ├── Entity.php         # Base class for API resources
│       ├── Order.php          # /orders API resource
│       ├── Payment.php        # /payments API resource
│       ├── Refund.php         # /refunds API resource
│       └── Utility.php        # Signature verification (HMAC-SHA256)
└── views/
    └── templates/
        ├── admin/admin.tpl    # Admin configuration form
        └── hook/payment_return.tpl
```

---

## Extraction Tasks

### Wave 1 — Core (no dependencies)

- [x] `core/boundaries.md` — Module boundaries, what it owns vs PrestaShop core, which PS APIs it calls
- [x] `core/quick-ref.md` — Quick reference for common dev operations (add config key, register hook, make API call, test webhook)

### Wave 2 — Domain Concepts (depends on Wave 1)

- [x] `modules/domain/payment-flow/concept.md` — Mental model of the checkout-to-order flow: cart → Razorpay order → payment → PrestaShop order
- [x] `modules/domain/webhook-processing/concept.md` — Mental model of server-side webhook flow: receive → verify signature → deduplicate → create/update order
- [x] `modules/domain/module-config/concept.md` — Mental model of module configuration: admin panel, configuration keys, webhook auto-registration

### Wave 3 — Domain Flows, Decisions, Constraints (depends on Wave 2 concepts)

- [x] `modules/domain/payment-flow/flows.md` — Full code paths: happy path, authorize-only mode, duplicate payment prevention, currency blocklist
- [x] `modules/domain/payment-flow/decisions.md` — Why CAPTURE vs AUTHORIZE, why paise conversion, why session + DB for order ID storage
- [x] `modules/domain/payment-flow/constraints.md` — Business rules: currency blocklist (KWD, OMR, BHD), amount must be in paise, cart must have customer + addresses
- [x] `modules/domain/webhook-processing/flows.md` — Webhook flows: signature verify → check razorpay_sales_order → webhook wait time throttle → create or skip order
- [x] `modules/domain/webhook-processing/decisions.md` — Why deduplication table, why webhook wait time, why by_webhook/by_frontend flags
- [x] `modules/domain/webhook-processing/constraints.md` — Race condition rules: webhook wait time (300s default), UNIQUE KEY on cart_id+rzp_payment_id, UNIQUE KEY on order_id
- [x] `modules/domain/module-config/flows.md` — Config flow: install → register hooks → admin saves config → autoEnableWebhook → webhook verified
- [x] `modules/domain/module-config/constraints.md` — Config constraints: KEY_ID/SECRET required, webhook events JSON array, webhook last-verify 24h TTL

### Wave 4 — Technical (parallel with Wave 2)

- [x] `modules/technical/database/schema.md` — razorpay_sales_order table: schema, lifecycle, all read/write operations, UNIQUE KEY constraints
- [x] `modules/technical/frontend/checkout-js.md` — script.js walkthrough: button injection, order creation AJAX, Razorpay Checkout modal, form submission to validation controller
- [x] `modules/technical/sdk/api-client.md` — Razorpay SDK architecture: Api class magic dispatch, Request class HTTP auth, entity classes, signature utility
- [x] `modules/technical/hooks/registered-hooks.md` — All 4 PS hooks: what each does, which PS version differences exist, Smarty variable scoping gotcha

### Wave 5 — Integration (depends on domain flows)

- [x] `modules/integration/razorpay-api/orders.md` — Razorpay Orders API: create with amount/currency/payment_capture, fetch, notes update
- [x] `modules/integration/razorpay-api/payments.md` — Razorpay Payments API: fetch, signature verify, notes update on order confirmation
- [x] `modules/integration/razorpay-api/webhooks.md` — Webhook events handled: order.paid, payment.authorized, payment.failed; signature verification; auto-registration flow
- [x] `modules/integration/prestashop/payment-module.md` — PrestaShop PaymentModule contract: validateOrder(), hookPaymentOptions(), hookPaymentReturn(), Configuration::updateValue()

---

## Known Gotchas (document in relevant files)

1. **Webhook race condition** — Frontend and webhook can both try to create the same order. Resolution: `razorpay_sales_order` table with `by_webhook`/`by_frontend` flags and `webhook_wait_time` throttle (default 300s). Document in `webhook-processing/constraints.md`.

2. **Smarty variable scoping** — `$this->context->smarty` PHP variable access differs between PS versions. Fixed in 2.5.8. Document in `hooks/registered-hooks.md`.

3. **Amount in paise** — All amounts must be multiplied by 100 before sending to Razorpay API. Currency blocklist: KWD, OMR, BHD not supported. Document in `payment-flow/constraints.md`.

4. **Session + DB dual storage** — `rzp_order_id` stored in both PHP session AND `razorpay_sales_order` table. Session may expire; DB is fallback. Document in `payment-flow/flows.md`.

5. **Auto-webhook registration** — `autoEnableWebhook()` is called from `order.php` only if not verified in last 24h (`RAZORPAY_WEBHOOK_LAST_VERIFY` config key). Uses raw `$api->request->request()` not entity class. Document in `module-config/flows.md`.
