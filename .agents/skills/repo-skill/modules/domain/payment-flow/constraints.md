# Payment Flow Constraints

Business rules and enforcement points for the Razorpay PrestaShop module (v2.5.8).
Two controllers own the payment flow: `order.php` (Razorpay order creation) and `validation.php` (payment verification and PS order placement).

---

## Constraint Summary Table

| # | Constraint | Enforced in | On Failure |
|---|-----------|------------|-----------|
| C1 | Currency must not be KWD, OMR, or BHD | `order.php:postProcess()` | Razorpay order is never created; response returns generic error message; JS checkout cannot open |
| C2 | Amount must be integer paise (× 100, no decimals) | `order.php:postProcess()`, `validation.php:postProcess()` | `order.php` uses `number_format(..., 0)` to truncate; `validation.php` uses `intval(... * 100)` — fractional paise are silently dropped |
| C3 | HTTP method must be POST | `order.php:postProcess()` | Returns HTTP 400 with empty JSON body and exits immediately |
| C4 | Cart must have a customer (`id_customer != 0`) | `validation.php:postProcess()` | Redirects to `index.php?controller=order&step=1` |
| C5 | Cart must have a delivery address (`id_address_delivery != 0`) | `validation.php:postProcess()` | Redirects to `index.php?controller=order&step=1` |
| C6 | Cart must have a billing address (`id_address_invoice != 0`) | `validation.php:postProcess()` | Redirects to `index.php?controller=order&step=1` |
| C7 | Razorpay module must be active in PS payment modules list | `validation.php:postProcess()` | `die()` with translated message "This payment method is not available." |
| C8 | Customer object must load successfully from DB | `validation.php:postProcess()` | Redirects to `index.php?controller=order&step=1` |
| C9 | HMAC-SHA256 signature must match | `validation.php:postProcess()` | Prints error HTML with cart ID and payment ID, then `exit` — no PS order is created |
| C10 | Each cart may place only one PS order (`order_placed` flag) | `razorpay-webhook.php` | Webhook exits immediately if `order_placed = 1`; frontend path does not re-check this flag |

---

## Constraint Details

### C1 — Currency Blocklist (KWD, OMR, BHD)

**Rule:** If `$this->context->currency->iso_code` is in `Razorpay::CURRENCY_NOT_ALLOWED`, the call to `order->create()` is skipped entirely.

**Why:** The Razorpay API uses sub-unit multipliers to convert amounts. KWD, OMR, and BHD have 3 decimal places (1 KWD = 1000 fils), so multiplying by 100 produces incorrect amounts. Razorpay's API itself also rejects these currencies. The blocklist is a pre-flight guard to avoid a confusing API error reaching the user.

**Enforced at:** `order.php:postProcess()` — `in_array($this->context->currency->iso_code, Razorpay::CURRENCY_NOT_ALLOWED)` check. The constant is defined in `razorpay.php` at `Razorpay::CURRENCY_NOT_ALLOWED`.

**On failure:** `$order` remains undefined; the response JSON returns `{"message":"Unable to create your order. Please contact support.","parameters":[]}` with HTTP 400. An error is written to the PS logger. The JS checkout modal never opens.

---

### C2 — Amount as Integer Paise

**Rule:** Amount sent to Razorpay must be a whole integer (no decimals). The two controllers use different conversion methods.

- `order.php:postProcess()`: `number_format(($this->context->cart->getOrderTotal() * 100), 0, "", "")` — rounds to 0 decimal places.
- `validation.php:postProcess()`: `(string) intval($cart->getOrderTotal(true, Cart::BOTH) * 100)` — truncates toward zero.

**Why:** Razorpay API rejects non-integer amounts. The two methods are subtly inconsistent (round vs. truncate) for amounts with fractional paise (e.g., a 0.005 INR difference). This is an edge case in practice for INR but could matter for other currencies.

**Gotcha:** The two conversions are not identical. If `getOrderTotal()` returns a value that produces a half-paise difference, the amount stored in the Razorpay order (from `order.php`) will differ from the amount used in `validateOrder()` (from `validation.php`). This does not block payment but may cause amount mismatch in reconciliation.

---

### C3 — HTTP POST Only (order.php)

**Rule:** `order.php:postProcess()` checks `$_SERVER['REQUEST_METHOD'] !== 'POST'` at entry.

**Why:** This endpoint creates a Razorpay order (a billable API call). Allowing GET requests would expose it to accidental browser prefetches or bots triggering order creation without user intent.

**On failure:** `header('Status:', true, 400)` + `exit`. The response body is empty (no JSON), not the standard `$responseContent` shape. Callers should not rely on a JSON body for non-POST responses.

---

### C4–C6 — Cart Address and Customer Completeness

**Rule:** Before verifying the signature, `validation.php:postProcess()` requires:
- `$cart->id_customer !== 0`
- `$cart->id_address_delivery !== 0`
- `$cart->id_address_invoice !== 0`

These are checked as a group in a single `if` block (along with C7 below).

**Why:** PrestaShop's `validateOrder()` will fail silently or produce corrupt orders if these fields are missing. The redirect sends the user back to checkout step 1 rather than showing a payment error, because the issue is a checkout configuration problem, not a payment failure.

**Note:** The cart may be re-hydrated from `$_REQUEST['cart_id']` if `$this->context->cart->id` is not set — see `validation.php:postProcess()` cart reconstruction block. This handles the case where session cart is lost between order creation and return callback.

---

### C7 — Module Active Check

**Rule:** After the address/customer check, `validation.php:postProcess()` additionally checks that `razorpay` appears in `Module::getPaymentModules()`.

**Why:** A shop admin could disable the Razorpay module while a customer has the checkout page open. Without this check, payments completed in the Razorpay modal could land in `validation.php` and create PS orders even though the module was disabled.

**On failure:** `die()` with a translated string — no redirect, raw text output. This is a hard stop, not a graceful redirect.

---

### C8 — Customer Object Validity

**Rule:** After the group check (C4–C7), `validation.php:postProcess()` fetches the customer again via `new Customer($cart->id_customer)` and calls `Validate::isLoadedObject($customer)`.

**Why:** The cart's `id_customer` could reference a deleted customer. `validateOrder()` requires a valid customer object for the secure_key passed in the confirmation URL. If the customer cannot be loaded, continuing would create an order with a broken confirmation link.

---

### C9 — HMAC-SHA256 Signature Verification

**Rule:** `razorpay_payment_id` + `rzp_order_id` must produce an HMAC-SHA256 digest (using `RAZORPAY_KEY_SECRET`) that matches `razorpay_signature` posted by the checkout JS.

**Where `rzp_order_id` comes from:** Preferentially from `$_SESSION['rzp_order_id']` set by `order.php`. If the session key is missing (e.g., session expired between order creation and callback), the module falls back to querying `razorpay_sales_order` by `cart_id`. This fallback is in `validation.php:postProcess()`.

**On failure:** Raw HTML error output (`echo`) then `exit`. No PS order is created. No redirect. The user sees an inline error page with cart ID and Razorpay payment ID for manual reconciliation.

**Gotcha:** The session fallback uses `$_REQUEST['cart_id']` which is user-supplied. If an attacker supplies an arbitrary `cart_id`, they could attempt to map a different cart's `rzp_order_id` into the signature check. The signature itself still prevents forgery, but the `rzp_order_id` lookup is not authenticated prior to use.

---

### C10 — order_placed Uniqueness Flag

**Rule:** `razorpay_sales_order.order_placed` is a `tinyint(1)` defaulting to `0`. It is set to `1` by whichever path (frontend `validation.php` or webhook `razorpay-webhook.php`) successfully calls `validateOrder()` first.

**Why:** Both the frontend return URL and the webhook can arrive nearly simultaneously. Without this flag, both paths would call `validateOrder()`, creating duplicate PS orders for the same cart.

**Enforcement detail:**
- **Webhook path** (`razorpay-webhook.php`): Reads `order_placed` before calling `validateOrder()`. If `order_placed = 1`, it logs and exits immediately.
- **Frontend path** (`validation.php`): Does NOT check `order_placed` before calling `validateOrder()`. It only sets `order_placed = 1` after `validateOrder()` succeeds. This means if two frontend requests race (unlikely but possible with browser retries), they could both call `validateOrder()`. The webhook path is the more protected one.

**Schema:** `razorpay.php` install method defines the column as `order_placed tinyint(1) NOT NULL DEFAULT '0'`.

---

## Gaps Requiring Human Verification

- **Zero-amount carts:** No explicit guard against `$amount = 0` in either controller. Whether Razorpay API rejects a zero-amount order or silently accepts it is not visible from this code alone.
- **Frontend race condition:** Two near-simultaneous browser POSTs to `validation.php` for the same cart are not protected by a DB lock or re-check of `order_placed` in the frontend path. Needs verification whether PrestaShop's `validateOrder()` is itself idempotent for the same `cart_id`.
- **`number_format` vs `intval` discrepancy (C2):** Whether this has caused reconciliation issues in production is not determinable from code alone.
