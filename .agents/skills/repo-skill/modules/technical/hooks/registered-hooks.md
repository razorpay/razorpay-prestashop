# PrestaShop Hooks — Registered Hooks

Module registers four hooks in `razorpay.php:install()`:
`header`, `orderConfirmation`, `paymentOptions`, `paymentReturn`.

All hooks are PS 1.7+ only (`ps_versions_compliancy min: 1.7.0.0`).

---

## 1. `hookHeader($params)` — `razorpay.php:hookHeader()`

**Fires:** On every page load where the header hook runs. The hook itself has no `$params` input.

**Page guard:** The hook only acts when `Tools::getValue('controller') == "order"`. This is the PS 1.7+ checkout controller name. No separate PS 1.6 branch exists — the module dropped PS 1.6 support at `min: 1.7.0.0`.

**What it injects (two JS assets):**

| Asset | Position | Priority | Source |
|-------|----------|----------|--------|
| `checkout.js` CDN | `head` | 20 | `https://checkout.razorpay.com/v1/checkout.js` (remote) |
| `script.js` local | `bottom` | 30 | `modules/razorpay/script.js` |

Both registered via `registerJavascript()` — PS 1.7+ asset management, not raw `<script>` injection.

**JS data object — `razorpay_checkout_vars`:**

Injected via `Media::addJsDef()` (makes the array available globally as a JS variable):

| Key | Value |
|-----|-------|
| `key` | Razorpay Key ID (public) |
| `name` | `PS_SHOP_NAME` config value |
| `amount` | Cart total × 100, formatted as integer string (paise) |
| `cart_id` | Current cart ID |
| `rzp_order_id` | Always `""` at header time — order is created later via `order` controller |
| `ps_version` | `_PS_VERSION_` constant |
| `module_version` | `2.5.8` |
| `order_controller` | URL to `razorpay/order` front controller |
| `action_controller` | URL to `razorpay/validation` front controller with `cart_id` query param |

**Smarty scoping fix in v2.5.8:** Prior to 2.5.8, the module tried to assign Smarty variables inside `hookHeader` for a template that does not exist in this hook. The hook has no associated `.tpl` file — `hookHeader` returns nothing. Smarty `assign()` calls were removed; all JS data now goes through `Media::addJsDef()`. This was the "smarty php var access issue" fixed in commit `b72b6eb`.

**Why `rzp_order_id` is empty here:** The Razorpay order must be created after the user clicks pay (to have a confirmed cart total). The `order_controller` URL points to `razorpay/order` which creates the Razorpay order on-demand and returns the `rzp_order_id` to the JS.

---

## 2. `hookPaymentOptions($params)` — `razorpay.php:hookPaymentOptions()`

**Fires:** PS 1.7+ payment step — PS collects `PaymentOption` objects from all active payment modules.

**Returns:** Array containing one `PaymentOption` object.

| Property | Value |
|----------|-------|
| Module name | `razorpay` |
| Logo | `https://cdn.razorpay.com/static/assets/logo/payment_method.svg` |
| Action | `razorpay/validation` front controller URL (no `cart_id` here — unlike `hookHeader`) |
| CTA text | `Pay by Razorpay` |
| Additional info | `<p>Pay using Credit/Debit Card, NetBanking, Wallets, or UPI</p>` |

**No custom template.** The `setAdditionalInformation()` string is raw HTML, not a `.tpl` file. PS renders it inline in the payment options list.

**Non-obvious — action URL difference:** `hookPaymentOptions` sets the action to `validation` with no `cart_id`. `hookHeader` sets `action_controller` with `cart_id`. The JS in `script.js` uses `action_controller` (from `razorpay_checkout_vars`) not the form action from `PaymentOption`. The `PaymentOption` action is a fallback for non-JS browsers or if Razorpay checkout.js fails to intercept the form submit.

---

## 3. `hookOrderConfirmation($params)` — `razorpay.php:hookOrderConfirmation()`

**Fires:** After PS has created the order — typically after `validation` front controller runs and PS `validateOrder()` completes. PS passes the newly created `Order` object.

**Guard:** Checks `$order->module === $this->name` (`'razorpay'`). Silently returns for orders placed through other payment modules.

**What it does:**

1. Reads the first `OrderPayment` record via `$order->getOrderPayments()[0]`.
2. Extracts `transaction_id` as the Razorpay payment ID.
3. Calls `$api->payment->fetch($paymentId)->edit(['notes' => ['prestashop_order_id' => $order->id, 'prestashop_cart_id' => $order->id_cart]])` — writes back-reference into Razorpay payment notes for support tracing.
4. Returns the HTML string `"Your Razorpay Payment Id is <code>{$paymentId}</code>"` — PS displays this on the order confirmation page.

**Error handling:** `BadRequestError` from the `edit()` call is caught and logged at PS log level 4 (critical). The hook does not fail the page — note writing is best-effort.

**Non-obvious:** The hook checks `count($payments) >= 1` but only reads `$payments[0]`. If multiple payments exist (e.g. partial payments or re-attempts), only the first is annotated and displayed.

---

## 4. `hookPaymentReturn($params)` — `razorpay.php:hookPaymentReturn()`

**Fires:** PS renders the order confirmation / payment return page. Receives `$params['order']`.

**Guards (two):**
1. `!$this->active` — returns nothing if module is disabled.
2. `$params['order']->module != $this->name` — returns `false` for non-Razorpay orders.

**Smarty variables assigned:**

| Variable | Source |
|----------|--------|
| `id_order` | `$params['order']->id` |
| `valid` | `$params['order']->valid` (PS order validity flag: 1 = valid) |
| `reference` | `$params['order']->reference` |
| `shop_name` | `$this->context->shop->name` |
| `contact_url` | Link to PS contact page |

**Template:** `views/templates/hook/payment_return.tpl`

**Success vs failure determination:** The template branches on `{if $valid == 1}`. `$order->valid` is set by PS when the order state is a "paid" state. The module does not independently re-check payment status — it trusts whatever PS recorded during `validateOrder()`.

**Non-obvious:** `reference` is assigned twice — once conditionally inside `isset($params['order']->reference)`, then unconditionally in the second `assign()` block. The second assignment always overwrites the first. The conditional block is dead code but harmless.

---

## Flow Map — Hook Execution Sequence

### Checkout Page Load

| Flow | Trigger | Key Functions | Decision Points | Outcome |
|------|---------|---------------|-----------------|---------|
| Happy path (most traffic) | Customer reaches checkout | `razorpay.php:hookHeader()` | DP1: controller == "order"? | CDN + local JS injected; `razorpay_checkout_vars` published |
| Non-checkout page (most traffic) | Any other page | `razorpay.php:hookHeader()` | DP1: controller != "order" | Hook exits immediately, nothing injected |

**DP1: Page guard** — Only the `order` controller page should load Razorpay JS. Injecting on every page would load a 200 KB+ external script unnecessarily and could expose the Key ID in DOM on unrelated pages.

### Payment Submission

| Flow | Trigger | Key Functions | Decision Points | Outcome |
|------|---------|---------------|-----------------|---------|
| JS path (most traffic) | Customer clicks "Pay by Razorpay" | `script.js` intercepts → `order` controller → Razorpay checkout.js → `validation` controller | DP2: payment success in modal | PS order created, redirect to confirmation |
| No-JS fallback (rare) | Form submitted directly | `hookPaymentOptions()` action → `validation` controller | DP2 skipped | Razorpay checkout.js not invoked; behaviour undefined without JS |

### Order Confirmation

| Flow | Trigger | Key Functions | Decision Points | Outcome |
|------|---------|---------------|-----------------|---------|
| Razorpay order (most traffic) | PS order confirmation page | `hookOrderConfirmation()` → `getRazorpayApiInstance()` → `payment->fetch()->edit()` | DP3: module matches? | Payment notes updated; payment ID shown |
| Other module order (common) | PS order confirmation page | `hookOrderConfirmation()` | DP3: module != razorpay | Early return, no action |
| Note write fails (rare) | API error | `hookOrderConfirmation()` | `BadRequestError` caught | Logged at level 4; page still renders with payment ID |

**DP3: Module ownership check** — PS fires `hookOrderConfirmation` for every order regardless of payment module. Guard prevents the hook from trying to fetch payment IDs for orders paid by other methods (which would not have a Razorpay `transaction_id`).

---

## Gaps Requiring Human Verification

- The `hookPaymentReturn` double-assignment of `reference` is harmless but confirms the conditional block above it has no effect — verify this is not masking a missing reference bug.
- `hookPaymentOptions` action URL omits `cart_id` — confirm `validation` controller can recover cart from session when `cart_id` is absent in the query string.
- `hookHeader` `rzp_order_id` is always `""` — the JS must call `order_controller` to get the real ID before opening the Razorpay modal. Confirm `script.js` does this before checkout.js is invoked.
