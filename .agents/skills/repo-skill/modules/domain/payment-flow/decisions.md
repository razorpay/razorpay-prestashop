# Payment Flow: Architectural Decisions

---

### D1: CAPTURE vs AUTHORIZE Payment Action

**Context:** Merchants have two business models: instant settlement (e-commerce) and deferred fulfillment (hotel pre-auth, B2B). A single capture mode doesn't fit both.

**Decision:** Expose a `RAZORPAY_PAYMENT_ACTION` config key. When set to `'capture'`, send `payment_capture=1` to Razorpay's order create API, which auto-captures funds immediately on payment success. When set to `'authorize'`, send `payment_capture=0`, which holds funds in `authorized` state pending explicit capture.

**Code:** `order.php:postProcess()` line 50 — `'payment_capture' => ($payment_action === Razorpay::CAPTURE) ? 1 : 0`

**Alternatives considered:**
- **Always capture immediately:** Simpler, but blocks merchants who pre-authorize hotel bookings or need to confirm stock before charging.
- **Always authorize, capture via webhook:** Safer for inventory workflows, but breaks instant-settlement merchants who expect immediate confirmation.

**Trade-offs:**
- Gained: Merchant flexibility without code changes; matches Razorpay's native order API semantics.
- Lost: `validation.php` always calls `validateOrder()` with `PS_OS_PAYMENT` (paid status) even in authorize mode. The PrestaShop order shows as paid before capture actually happens — this is a known mismatch that requires the webhook to reconcile.

**Revisit if:** PrestaShop adds a distinct `PS_OS_AUTHORIZED` order state, or if merchants report confusion over order status in authorize mode.

---

### D2: Amount in Paise (multiply by 100)

**Context:** Razorpay's API requires all amounts as integers in the smallest currency unit (paise for INR). Sending decimal rupees causes API validation errors.

**Decision:** Multiply cart total by 100 and format as integer before all API calls and before passing the amount to the JS modal.

**Code:**
- `order.php:postProcess()` line 37 — `number_format(($this->context->cart->getOrderTotal() * 100), 0, "", "")` (string, no decimals, no separator)
- `validation.php:postProcess()` line 60 — `(string) intval($cart->getOrderTotal(true, Cart::BOTH) * 100)`
- `validation.php:postProcess()` line 150 — `number_format($payment->amount/100, "2", ".", "")` converts back from paise when storing `amount_paid`

**Alternatives considered:**
- **Store amount as float and convert only at API boundary:** Would require a single conversion point, but PrestaShop's `getOrderTotal()` returns a float and PHP float precision can introduce rounding at 2-decimal boundaries.

**Trade-offs:**
- Gained: Unambiguous integer amounts; matches Razorpay API contract exactly.
- Lost: Two separate conversion sites (`order.php` and `validation.php`) must stay in sync. `order.php` uses `number_format` while `validation.php` uses `intval` — functionally equivalent but inconsistent style.

**Revisit if:** A currency with a different subunit multiplier (e.g., JPY = no subunit, KWD = 1000 fils) needs to be supported — the hardcoded `* 100` is wrong for those cases (KWD/OMR/BHD are blocked for this reason).

---

### D3: Session + DB Dual Storage for rzp_order_id

**Context:** After `order.php` creates a Razorpay order, `validation.php` must retrieve that same `rzp_order_id` to verify the payment signature. There is no URL parameter carrying it (it would be tamperable). A single storage mechanism is fragile.

**Decision:** Store `rzp_order_id` in both PHP session (`$_SESSION['rzp_order_id']`) and the `razorpay_sales_order` DB table. `validation.php` reads the session first; if absent, falls back to DB lookup by `cart_id`.

**Code:**
- `order.php:postProcess()` lines 76-77 — `session_start(); $_SESSION['rzp_order_id'] = $order->id`
- `order.php:postProcess()` lines 81-103 — DB INSERT or UPDATE
- `validation.php:postProcess()` lines 73-86 — session read with DB fallback

**Alternatives considered:**
- **Session only:** Breaks on shared hosting where PHP session files are not shared across web processes, and on some CDN/load-balancer setups where the callback lands on a different process.
- **DB only:** Requires a DB read on every validation request; session is faster and is usually available.
- **Pass rzp_order_id in POST body from JS:** The JS handler controls the POST to `validation.php` and could include it, but it would be user-controlled and require additional server-side trust logic.

**Trade-offs:**
- Gained: Works across hosting environments; session is the fast path, DB is the reliable fallback.
- Lost: `validation.php` line 146 references `$_SESSION['rzp_order_id']` in the DB query even after the DB-fallback path sets `$attributes['razorpay_order_id']` — if the session was absent and the DB path ran, line 146's query will use an unset/null session value. This is a latent bug where `order_sales_id` lookup in the post-`validateOrder()` block could fail silently on the DB-fallback path.

**Revisit if:** Session reliability becomes a widespread support issue, in which case the session write in `order.php` can be dropped and the DB path made primary.

---

### D4: Razorpay Order Created Before Modal Opens

**Context:** The Razorpay modal accepts `amount` and `order_id` as configuration. Without a server-created order, the amount displayed in the modal is set by the browser, making it tamperable.

**Decision:** `order.php` (server-side) creates the Razorpay order with the authoritative cart amount before `script.js` opens the modal. The modal receives `rzp_order_id` and `amount` from the server JSON response, not from any client-controlled value.

**Code:**
- `order.php:postProcess()` line 54 — `order->create($orderData)` where `$amount` is from `$this->context->cart->getOrderTotal() * 100`
- `script.js:renderPaymentFrame()` line 62 — `order_id: data.rzp_order_id` bound from server response
- `script.js` line 114 — `$.post(defaults.order_controller, '[]')` fires first; `renderPaymentFrame` only called if `response.success` is true

**Alternatives considered:**
- **Create Razorpay order inside the modal (client-side):** Razorpay's checkout.js does not support client-side order creation; an `order_id` must come from the server.
- **Use `key` + `amount` only (no order_id):** Razorpay supports this for simple integrations, but without `order_id` there is no amount anchoring — the customer could manipulate the amount in browser dev tools before the modal opens.

**Trade-offs:**
- Gained: Amount is locked server-side; Razorpay's signature verification at `validation.php` line 88 binds `payment_id` to `order_id`, so any mismatch is caught. Idempotent: if the modal is closed and reopened, a new `order.php` call creates a new Razorpay order (the DB row is updated).
- Lost: One extra server round-trip before the modal opens, visible as a brief delay on "Pay" click.

**Revisit if:** Razorpay introduces client-side order creation with server-side amount anchoring.

---

### D5: Server-Side Signature Verification

**Context:** After payment, Razorpay returns `razorpay_payment_id`, `razorpay_order_id`, and `razorpay_signature` to the browser. If `validation.php` trusted the payment_id alone (e.g., just fetched the payment and checked its status), an attacker could reuse a payment_id from a different, smaller transaction.

**Decision:** `validation.php` verifies the HMAC-SHA256 signature over `order_id + "|" + payment_id` using the key secret before calling `validateOrder()`. The key secret is never exposed to the browser.

**Code:** `validation.php:postProcess()` line 88 — `$api->utility->verifyPaymentSignature($attributes)`

**Alternatives considered:**
- **Fetch payment and check status/amount server-side only:** Would catch amount mismatches but not payment_id reuse (attacker pays Rs 1 on a different order, replays that payment_id here).
- **Rely on Razorpay webhook only:** Webhook delivery is not guaranteed in real time; customer would be stuck on a loading screen. Frontend confirmation flow must complete synchronously.

**Trade-offs:**
- Gained: Cryptographically binds this specific payment_id to this specific order_id; prevents replaying any other payment against this cart.
- Lost: If the key secret is rotated without updating `RAZORPAY_KEY_SECRET` in PrestaShop config, all in-flight payments fail signature verification until config is updated.

**Revisit if:** Razorpay deprecates HMAC-SHA256 signatures in favor of a different verification scheme.
