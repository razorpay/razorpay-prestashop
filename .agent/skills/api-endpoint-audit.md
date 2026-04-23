# Skill: API Endpoint Audit

Use this runbook to audit the security, correctness, and health of all Razorpay module endpoints.

---

## Endpoints to Audit

| Endpoint | File | Method | Auth |
|---|---|---|---|
| `/razorpay/order` | `controllers/front/order.php` | POST | None (public, PS session) |
| `/razorpay/validation` | `controllers/front/validation.php` | POST | PS secure_key |
| `/razorpay/webhook` | `controllers/front/webhook.php` | POST | HMAC-SHA256 signature |

---

## Endpoint 1: /razorpay/order

**Security checks**:
- [ ] Accepts only POST (`$_SERVER['REQUEST_METHOD'] !== 'POST'` → returns 400)
- [ ] Amount comes from server-side cart (`$this->context->cart->getOrderTotal()`) — not from user input
- [ ] Currency validated against `CURRENCY_NOT_ALLOWED`
- [ ] API credentials read from `Configuration::get()` — not from request

**Correctness checks**:
- [ ] `payment_capture` flag maps correctly: `CAPTURE` → 1, `AUTHORIZE` → 0
- [ ] `receipt` field is cart ID as string (for dashboard visibility)
- [ ] `rzp_order_id` stored in session AND database

**Potential issues**:
- No rate limiting on this endpoint (customer could spam order creation)
- Multiple clicks could create multiple Razorpay orders for same cart (handled by DB upsert)

---

## Endpoint 2: /razorpay/validation

**Security checks**:
- [ ] `verifyPaymentSignature()` called before `validateOrder()`
- [ ] `$customer->secure_key` passed to `validateOrder()`
- [ ] `razorpay_payment_id` comes from `$_REQUEST` but validated via API fetch + signature
- [ ] Module active check prevents payment with disabled module

**Correctness checks**:
- [ ] Cart validation: `id_customer != 0`, `id_address_delivery != 0`, `id_address_invoice != 0`
- [ ] Session fallback: if `$_SESSION['rzp_order_id']` missing, queries DB for `rzp_order_id`
- [ ] Payment method stored as `razorpay.{method}` (e.g., `razorpay.card`)
- [ ] `razorpay_sales_order` updated after `validateOrder()`

**Potential issues**:
- Error path echoes raw error text (`echo 'Error: '.$e->getMessage()`) — could leak info
- No CSRF token on the form (relies on PS module authorization check)

---

## Endpoint 3: /razorpay/webhook

**Security checks**:
- [ ] `X-Razorpay-Signature` header presence checked
- [ ] `verifyWebhookSignature()` called with raw POST body (not decoded JSON)
- [ ] `ENABLE_RAZORPAY_WEBHOOK` must be `'on'` to process
- [ ] Webhook secret must be non-empty
- [ ] Signature mismatch → HTTP 400 + exit

**Correctness checks**:
- [ ] `order_placed` flag checked before creating order (duplicate prevention)
- [ ] Wait time guard implemented (default 300 seconds)
- [ ] `prestashop_cart_id` extracted from `payload.payment.entity.notes` (must be set by frontend JS)
- [ ] `webhook_count` incremented on each attempt

**Potential issues**:
- `prestashop_cart_id` in notes depends on JS setting it at checkout — if JS fails to set it, webhook cannot correlate payment to cart
- No timeout handling for `validateOrder()` within webhook context

---

## Security Audit Checklist

```
[ ] API Key ID and Secret stored only in ps_configuration (never in code/JS)
[ ] Webhook secret auto-generated (not hardcoded)
[ ] All SQL uses (int) casting for integer IDs and pSQL() for strings
[ ] No raw user input passed to DB queries
[ ] Payment amount determined server-side (not from POST body)
[ ] Signature verified for both payment callback and webhook
[ ] ENABLE_RAZORPAY_WEBHOOK check prevents unauthorized webhook processing
[ ] KEY_SECRET never included in JS razorpay_checkout_vars (only KEY_ID)
```

---

## Quick Health Check Queries

```sql
-- Recent failed webhook attempts (HTTP 400)
SELECT * FROM ps_log
WHERE message LIKE '%signature.verify_failed%'
ORDER BY date_add DESC LIMIT 20;

-- Orders with Razorpay payment but no tracking record
SELECT o.id_order, o.id_cart, op.transaction_id
FROM ps_orders o
INNER JOIN ps_order_payment op ON op.id_order = o.id_cart
LEFT JOIN razorpay_sales_order rs ON rs.order_id = o.id_order
WHERE op.payment LIKE 'razorpay%'
AND rs.entity_id IS NULL;

-- Orphaned tracking records (cart has tracking but no PS order)
SELECT rs.*, o.id_order
FROM razorpay_sales_order rs
LEFT JOIN ps_orders o ON o.id_order = rs.order_id
WHERE rs.order_placed = 1
AND o.id_order IS NULL;
```
