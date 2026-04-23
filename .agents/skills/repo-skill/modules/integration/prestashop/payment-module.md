# PrestaShop PaymentModule Contract

Razorpay v2.5.8 integration with the PrestaShop `PaymentModule` base class. Documents how this
module uses the PS contract — the parts that are non-obvious or that break silently if misused.

---

## Class Hierarchy

```
Razorpay extends PaymentModule extends Module
```

- **Module** — registers hooks, manages install/uninstall, provides `$this->l()` translation,
  `$this->context`, `$this->smarty`, `Configuration::*`, `Logger::addLog()`.
- **PaymentModule** — adds `validateOrder()` (creates Order + OrderPayment + OrderHistory + sends
  confirmation email) and exposes `$this->currentOrder` after `validateOrder()` returns.
- **Razorpay** — implements the four required hooks and owns all Razorpay-specific logic.

---

## D1: Dual order-creation paths (frontend + webhook)

**Context:** A browser POST to `validation.php` and a Razorpay `order.paid` webhook can both arrive
for the same cart. Whichever arrives first must create the PS order; the second must not duplicate it.

**Decision:** The `razorpay_sales_order` table acts as a coordination lock. Both paths check
`order_placed = 1` before calling `validateOrder()`. The webhook additionally enforces a
configurable wait window (default 300 s, key `RAZORPAY_WEBHOOK_WAIT_TIME`) from its first
notification before proceeding, giving the browser callback priority.

**Alternatives considered:**
- **PS-only idempotency via `Order::getOrderByCartId()`** — rejected: PS creates the order row only
  after `validateOrder()` completes, so a race window exists without the custom table.
- **Webhook-only order creation** — rejected: adds latency for the customer redirect flow.

**Trade-offs:**
- Gained: customers are redirected immediately after browser callback; webhook is a safety net.
- Lost: custom table must be kept in sync; webhook wait window is a magic constant.

**Code:** `razorpay-webhook.php:orderPaid()`, `validation.php:postProcess()`

**Revisit if:** PS introduces native idempotency tokens for `validateOrder()`.

---

## D2: Payment method name prefixed with `razorpay.`

**Context:** PS stores the payment method string on `OrderPayment.payment_method`. Razorpay returns
a short method name like `netbanking`, `upi`, `card`.

**Decision:** Prefix with `razorpay.` before passing to `validateOrder()` — e.g.
`razorpay.netbanking`. Same prefix is applied in both validation.php and webhook handler.

**Trade-offs:**
- Gained: method is namespaced, queryable, and human-readable in the PS admin order list.
- Lost: if Razorpay adds a method name that itself contains a dot, the prefix convention could
  become ambiguous (low risk).

**Code:** `validation.php:postProcess()`, `razorpay-webhook.php:orderPaid()`

---

## `validateOrder()` Call Contract

Both callers use an identical signature. The parameters that carry non-obvious semantics:

| Parameter | Value passed | Why non-obvious |
|-----------|--------------|-----------------|
| `$id_cart` | `$cart->id` | Must match the live Cart object; reconstructed from `$_REQUEST['cart_id']` when context cart is absent |
| `$id_order_state` | `(int) Configuration::get('PS_OS_PAYMENT')` | The PS "Payment accepted" state constant; cast to int is required — PS rejects string |
| `$amount_paid` | `$cart->getOrderTotal(true, Cart::BOTH)` | Full cart total with tax and both products+shipping; **not** the Razorpay `amount` field (which is in paise) |
| `$payment_method` | `"razorpay.{$payment->method}"` | Stored verbatim on OrderPayment; see D2 |
| `$message` | `'Payment by Razorpay using ...'` | Becomes an OrderMessage; visible to shop admin |
| `$extra_vars` | `['transaction_id' => $payment->id]` | PS writes this as `OrderPayment.transaction_id`; this is what `hookOrderConfirmation()` reads back via `$order->getOrderPayments()[0]->transaction_id` |
| `$currency_special` | `NULL` | Use cart's own currency — no conversion needed |
| `$dont_touch_amount` | `false` | PS will normalise the amount; passing `true` would bypass rounding |
| `$secure_key` | `$customer->secure_key` | PS validates this on the order-confirmation redirect; mismatch causes a blank confirmation page |

Return value: `validateOrder()` returns `true`/`false` (bool). Neither caller acts on the return
value — success is assumed if no exception is thrown. `$this->module->currentOrder` (set by PS as
a side-effect) is read immediately after to build the redirect URL or update `razorpay_sales_order`.

---

## Configuration Keys

| Key | set by | read by | Notes |
|-----|--------|---------|-------|
| `RAZORPAY_KEY_ID` | `_postProcess()` | `__construct()`, `validation.php:postProcess()` | Loaded at construct time; change requires page reload |
| `RAZORPAY_KEY_SECRET` | `_postProcess()` | `__construct()`, `validation.php:postProcess()` | Same |
| `RAZORPAY_PAYMENT_ACTION` | `_postProcess()` | `__construct()` (display only) | `capture` or `authorize`; not yet enforced in payment flow as of v2.5.8 |
| `ENABLE_RAZORPAY_WEBHOOK` | `_postProcess()`, `autoEnableWebhook()` | `razorpay-webhook.php:process()` | String `'on'`/`'off'`, not boolean; webhook handler does strict `=== 'on'` check |
| `RAZORPAY_WEBHOOK_EVENTS` | `_postProcess()` | (display only) | Stored but not validated against `$webhookSupportedEvents` at read time |
| `RAZORPAY_WEBHOOK_SECRET` | `autoEnableWebhook()` | `razorpay-webhook.php:process()`, `autoEnableWebhook()` | Auto-generated 20-char random string if not already set; only regenerated on settings save |
| `RAZORPAY_WEBHOOK_WAIT_TIME` | `_postProcess()` | `razorpay-webhook.php:orderPaid()` | Seconds to wait before webhook creates order; default 300; `0` disables the wait |
| `RAZORPAY_WEBHOOK_LAST_VERIFY` | `autoEnableWebhook()` | (not read back) | Unix timestamp of last webhook registration; informational only |

---

## Non-Obvious Constraints

**C1: Webhook is auto-registered on every admin settings save.**
`autoEnableWebhook()` is called from `_postProcess()`. It calls the Razorpay Webhooks API (GET to
check existence, then PUT or POST) every time an admin saves settings. If the shop domain resolves
to a private IP, auto-registration is silently skipped and `ENABLE_RAZORPAY_WEBHOOK` is set to
`'off'`. Code: `razorpay.php:autoEnableWebhook()`.

**C2: `ENABLE_RAZORPAY_WEBHOOK` stores a string, not a boolean.**
The webhook processor checks `=== 'on'`; any other truthy value (including PHP `true` from
`Configuration::updateValue('ENABLE_RAZORPAY_WEBHOOK', true)` called in `_postProcess()`) will be
stored as `'1'` by PS and the webhook will not fire. The correct final write in
`autoEnableWebhook()` uses the string `'on'`. Code: `razorpay.php:_postProcess()`,
`razorpay.php:autoEnableWebhook()`, `razorpay-webhook.php:process()`.

**C3: Signature fallback reads rzp_order_id from DB when session is missing.**
`$_SESSION['rzp_order_id']` may be absent if the customer's session expired or was on a different
server. The fallback queries `razorpay_sales_order` by `cart_id`. This means an entry must already
exist in that table before `validation.php` is called; it is written by the order-creation
controller (`order.php`, not included here). Code: `validation.php:postProcess()`.

**C4: Cart validity is checked against three zero-value properties, not `Validate::isLoadedObject()`.**
`$cart->id_customer === 0`, `id_address_delivery === 0`, `id_address_invoice === 0` are used as the
guard. A cart that passes `isLoadedObject()` but has no customer/address will redirect to step 1
silently. Code: `validation.php:postProcess()`.

**C5: `payment.authorized` and `payment.failed` webhook handlers are stubs.**
Both methods return immediately (`return;`). The actual order-creation path is `order.paid` only.
This is intentional: Razorpay recommends `order.paid` because it fires after capture confirmation,
not just authorization. Code: `razorpay-webhook.php:paymentAuthorized()`,
`razorpay-webhook.php:paymentFailed()`.

---

## Flow Map

### Frontend Payment (most traffic)

| Flow | Trigger | Key Functions | Decision Points | Outcome |
|------|---------|---------------|-----------------|---------|
| Happy path | Browser POST after Razorpay checkout | `validation.php:postProcess()` -> `validateOrder()` | DP1: cart valid, DP2: module active, DP3: signature OK | Order created, redirect to order-confirmation |
| Session lost | Same POST, no `$_SESSION['rzp_order_id']` | `postProcess()` -> DB fallback -> `verifyPaymentSignature()` | DP3: DB lookup for rzp_order_id | Same outcome if found |
| Signature fail | Tampered POST | `verifyPaymentSignature()` throws | DP3 fails | Plain-text error page, `exit` (no redirect) |
| Cart invalid | Missing customer/address | Cart guard in `postProcess()` | DP1 fails | Redirect to `order&step=1` |

### Webhook Order Recovery (safety net, triggered when browser path missed)

| Flow | Trigger | Key Functions | Decision Points | Outcome |
|------|---------|---------------|-----------------|---------|
| Order already placed | `order.paid` fires after browser path succeeded | `orderPaid()` checks `order_placed` | DP4: `order_placed=1` | `exit` immediately (idempotent) |
| Webhook too early | `order.paid` arrives within wait window | `orderPaid()` checks time delta | DP5: `(time() - first_notified) < wait_time` | HTTP 409, `exit` |
| Order exists, no payment | `order.paid` after partial PS order | `Order::getOrderByCartId()`, `setCurrentState()` | DP6: `getOrderPayments()` count | State transitioned to `PS_OS_PAYMENT` |
| No order yet | `order.paid` after wait window, no PS order | `orderPaid()` -> `validateOrder()` | All DPs clear | Order created via webhook |

**Decision Points:**
- **DP1: Cart validity** — `id_customer`, `id_address_delivery`, `id_address_invoice` must be non-zero. Why: `validateOrder()` will throw if cart is incomplete.
- **DP2: Module active** — checks `Module::getPaymentModules()` list at call time. Why: prevents order creation if merchant disabled Razorpay while a payment was in flight.
- **DP3: Signature verification** — `verifyPaymentSignature()` against `rzp_order_id` + `razorpay_payment_id` + `razorpay_signature`. Why: prevents replay or forged payment IDs.
- **DP4: `order_placed` flag** — custom table field, not a PS concept. Why: PS has no atomic "already processed" guard for `validateOrder()`.
- **DP5: Webhook wait window** — configurable seconds from first webhook notification. Why: gives the browser callback priority so the customer redirect works correctly.
- **DP6: Existing payments count** — `$order->getOrderPayments()`. Why: PS may create an order in pending state; if `count >= 1` payment already recorded, skip to avoid double-charging records.

---

## Hooks Registered

| Hook | Handler | What it does |
|------|---------|--------------|
| `paymentOptions` | `hookPaymentOptions()` | Returns a `PaymentOption` object pointing to the `validation` front controller |
| `paymentReturn` | `hookPaymentReturn()` | Renders confirmation template; validates `$params['order']->module === 'razorpay'` before rendering |
| `orderConfirmation` | `hookOrderConfirmation()` | Reads `getOrderPayments()[0]->transaction_id` and pushes PS order/cart IDs to Razorpay payment notes via API |
| `header` | `hookHeader()` | Injects `checkout.js` and `script.js` only on `controller=order`; passes key, amount, cart_id, and controller URLs to JS via `Media::addJsDef()` |

**Non-obvious:** `hookHeader()` multiplies `getOrderTotal()` by 100 to convert to paise and formats
with `number_format(..., 0, "", "")` to strip decimals. The same conversion happens independently
in `validation.php` (`intval(...*100)`). The two must agree or the amount shown in checkout will
differ from what is validated. Code: `razorpay.php:hookHeader()`, `validation.php:postProcess()`.

---

## Gaps Requiring Human Verification

- **`RAZORPAY_PAYMENT_ACTION` (`capture`/`authorize`)** — stored and displayed in admin but no
  code in these three files acts on it during payment. Verify whether the `order.php` front
  controller (not in scope here) uses it to decide between capture and authorize API calls.
- **`order.php` front controller** — creates the Razorpay Order and writes the initial
  `razorpay_sales_order` row. Signature fallback and webhook flow depend on this row existing.
  Its exact fields and timing are not documented here.
- **`RAZORPAY_WEBHOOK_LAST_VERIFY`** — written but never read back in any of the three files.
  Confirm whether any admin UI or cron reads this timestamp.
