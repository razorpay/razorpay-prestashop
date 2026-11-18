# Payment Flow: Cart to PrestaShop Order

## Entities and Their Roles

| Entity | What it is | When it exists |
|--------|-----------|----------------|
| **PrestaShop Cart** | Shopping basket with items, customer, address, and a computed total | Created by the customer during shopping; still active (no PS order yet) at payment time |
| **Razorpay Order** | An amount-locked payment intent on Razorpay's side (`order_id` prefixed `order_`) | Created by `order.php:postProcess()` just before the checkout modal opens |
| **Razorpay Payment** | The actual fund movement on Razorpay's side (`payment_id` prefixed `pay_`) | Created inside Razorpay's hosted checkout after the customer submits card/UPI/etc. |
| **PrestaShop Order** | A finalized PS order with state `PS_OS_PAYMENT` (paid) | Created by either `validation.php:postProcess()` (frontend) or `razorpay-webhook.php:orderPaid()` (webhook) |
| **razorpay_sales_order row** | Bridge table that tracks which cart maps to which Razorpay order and which PS order | Inserted in `order.php`, updated to `order_placed=1` by whichever actor (frontend or webhook) wins the race |

---

## Key Design Insight: Why the Razorpay Order is Created Before Payment

The Razorpay Order is created server-side (before the modal opens) for two reasons that are not visible in the code:

1. **Amount anchoring** — Razorpay validates that the payment amount matches the order amount. If the cart total is tampered in the browser, Razorpay rejects the payment. Without a pre-created order, only a client-supplied amount would be checked.
2. **Idempotency / retry safety** — `order.php:postProcess()` does an INSERT-or-UPDATE: if the same cart re-clicks Pay (e.g., after a modal close), it creates a new Razorpay order and updates the row rather than creating a duplicate PS order.

---

## Flow Stages

```
Customer clicks Pay
        |
        v
[1] script.js intercepts button click
    $.post(order_controller)  ──────────────────────────────────────────────────>
                                                                    [2] order.php:postProcess()
                                                                        - creates Razorpay Order via API
                                                                        - INSERT/UPDATE razorpay_sales_order
                                                                          (cart_id, rzp_order_id, amount_paid=0)
                                                                        - stores rzp_order_id in $_SESSION
                                                                    <── returns { success, rzp_order_id, amount }
        |
        v
[3] script.js:renderPaymentFrame()
    Opens Razorpay hosted checkout modal
    (order_id bound to pre-created Razorpay Order)
        |
        v (customer completes payment inside modal)
        |
[4] Razorpay calls handler(obj) with:
      razorpay_payment_id
      razorpay_signature
        |
    Two concurrent paths start here:
        |                                               |
        v                                               v
[5a] FRONTEND PATH                              [5b] WEBHOOK PATH
script.js injects payment_id + signature         Razorpay server POSTs order.paid event
into form, submits to validation.php             to webhook.php:postProcess()
        |                                               |
        v                                               v
[6a] validation.php:postProcess()               [6b] razorpay-webhook.php:orderPaid()
    - fetches payment from Razorpay API              - verifies webhook HMAC signature
    - verifies HMAC signature                        - checks order_placed flag (exits if 1)
      (order_id from $_SESSION or DB fallback)       - sets webhook_first_notified_at on 1st call
    - calls validateOrder() → creates PS Order       - waits RAZORPAY_WEBHOOK_WAIT_TIME (default 300s)
    - UPDATE razorpay_sales_order:                     before creating order (gives frontend priority)
        rzp_payment_id, amount_paid,                 - calls validateOrder() → creates PS Order
        order_id, by_frontend=1, order_placed=1      - UPDATE razorpay_sales_order:
    - redirects to order-confirmation                    rzp_payment_id, amount_paid,
                                                         order_id, by_webhook=1, order_placed=1
```

---

## Flow Map

### Frontend Path (most traffic — customer browser stays connected)

| Flow | Trigger | Key Functions | Decision Points | Outcome |
|------|---------|---------------|-----------------|---------|
| Normal checkout | Customer completes payment in modal | `script.js:handler()` -> `validation.php:postProcess()` | DP1: signature valid? DP2: cart still valid? | PS Order created, redirect to confirmation |
| Session lost | `$_SESSION['rzp_order_id']` missing | `validation.php:postProcess()` | DP3: fall back to DB lookup by cart_id | Order still created if DB row exists |
| Signature failure | Tampered callback params | `validation.php:postProcess()` | DP1 fails | Raw error page shown, no PS Order |
| Unsupported currency | `iso_code` in `CURRENCY_NOT_ALLOWED` | `order.php:postProcess()` | DP4 | Modal never opens, 400 JSON response |

### Webhook Path (fallback — browser closed, mobile app, network drop)

| Flow | Trigger | Key Functions | Decision Points | Outcome |
|------|---------|---------------|-----------------|---------|
| Frontend already won | `order.paid` event, `order_placed=1` | `razorpay-webhook.php:orderPaid()` | DP5: order_placed flag | Exit immediately, no duplicate order |
| Webhook arrives early | `order.paid` within wait window | `razorpay-webhook.php:orderPaid()` | DP6: time elapsed < wait_time | 409 response; Razorpay retries later |
| PS Order exists, unpaid | Cart has order but no payment record | `razorpay-webhook.php:orderPaid()` | DP7: `getOrderByCartId()` non-empty | `setCurrentState(PS_OS_PAYMENT)` — no new order |
| No PS Order yet | Frontend never completed | `razorpay-webhook.php:orderPaid()` | DP7: `getOrderByCartId()` empty | `validateOrder()` creates PS Order |

**Decision Points:**

- **DP1: HMAC Signature** — Validates `(order_id + payment_id)` signed with key_secret. Why: prevents replaying a valid payment_id against a different order.
- **DP2: Cart validity** — Checks customer, delivery address, invoice address all set. Why: PS `validateOrder()` will fail silently if cart is incomplete.
- **DP3: Session fallback** — `$_SESSION['rzp_order_id']` can be missing on shared hosting with session isolation or after a redirect. DB fallback by `cart_id` recovers this. Why: both point to the same pre-created Razorpay Order.
- **DP4: Currency guard** — `CURRENCY_NOT_ALLOWED` list checked server-side in `order.php:postProcess()`. Why: Razorpay rejects certain currencies; better to fail before opening the modal.
- **DP5: order_placed flag** — Single boolean that prevents double order creation regardless of which actor checks it. Why: the flag is written by whoever calls `validateOrder()` first; the other actor reads it and exits.
- **DP6: Webhook wait window** — Default 300 seconds from `RAZORPAY_WEBHOOK_WAIT_TIME`. Why: gives the frontend path time to complete. The webhook should be a safety net, not the primary creator.
- **DP7: Existing PS Order check** — `Order::getOrderByCartId()` before calling `validateOrder()`. Why: if a PS Order exists but has no payments (rare edge case), the webhook promotes it to paid state instead of creating a duplicate.

---

## razorpay_sales_order State Transitions

```
[INSERT]                         [UPDATE by frontend]          [UPDATE by webhook]
cart_id set                      rzp_payment_id set            rzp_payment_id set
rzp_order_id set      ──────>    amount_paid set     OR  ──>   amount_paid set
amount_paid = 0                  order_id set                  order_id set
order_placed = 0                 by_frontend = 1               by_webhook = 1
                                 order_placed = 1              order_placed = 1
(written by order.php)           (written by validation.php)   (written by razorpay-webhook.php)
```

The `order_placed` column is the mutex. Once either actor sets it to `1`, the other actor exits without creating a second PS Order.

---

## Service Contracts

**What the frontend expects from `order.php`:**
- POST returns `{ success: true, rzp_order_id: "order_xxx", amount: <paise> }` on success
- On failure returns `{ message: "...", parameters: [] }` — `script.js` silently swallows failure (no user error shown — gap)

**What `validation.php` expects:**
- `$_SESSION['rzp_order_id']` set by `order.php`, OR `razorpay_sales_order` row exists for `cart_id`
- `razorpay_payment_id` and `razorpay_signature` in `$_REQUEST` / `$_POST`

**What the webhook expects from Razorpay:**
- `notes.prestashop_cart_id` present in payment entity — set by `script.js:renderPaymentFrame()` when building checkout options. If this note is missing, `orderPaid()` will throw on `$data['payload']['payment']['entity']['notes']['prestashop_cart_id']` with no guard.

**What breaks if Razorpay API is down:**
- `order.php`: cannot create Razorpay Order — modal never opens, customer cannot pay
- `validation.php`: `$api->payment->fetch()` fails — PS Order is not created even though payment succeeded
- `razorpay-webhook.php`: webhook still creates PS Order (does not call `fetch()`, uses payload data directly)

---

## Non-Obvious Constraints

| Constraint | Why | Enforced at |
|-----------|-----|-------------|
| Razorpay Order must be created before modal opens | Amount anchoring — Razorpay ties the payment to the order amount server-side | `order.php:postProcess()` pre-modal |
| Payment action (capture vs authorize) is set at order creation, not payment time | `payment_capture` flag is part of the Razorpay Order; it cannot be changed after the order is created | `order.php:postProcess()` |
| `callback_url` and `handler` both set in checkout options | `callback_url` handles mobile/webview where JS handler doesn't fire; `handler` handles desktop browser. Both route to `validation.php` | `script.js:renderPaymentFrame()` |
| Webhook auto-enabled daily | `RAZORPAY_WEBHOOK_LAST_VERIFY` checked each time `order.php` runs; re-enables webhook if >24h old | `order.php:postProcess()` |
| `prestashop_cart_id` note is load-bearing | Webhook cannot identify which cart to process without it — no fallback | `script.js:renderPaymentFrame()` (client-side) |

---

## Gaps Needing Human Verification

1. **Silent frontend failure**: if `order.php` returns a non-success response, `script.js` does not show the customer an error. Confirm whether this is intentional (e.g., the original PS submit button is still visible as a fallback).
2. **`CURRENCY_NOT_ALLOWED` list**: the list is defined in `razorpay.php` but not shown here. Verify it is kept current with Razorpay's actual supported currencies.
3. **Race condition between frontend and webhook**: `order_placed` is set after `validateOrder()` returns, not before it is called. Two concurrent callers could both pass the `order_placed=0` check and both call `validateOrder()`. PrestaShop's `validateOrder()` may or may not be idempotent for the same cart — needs verification.
