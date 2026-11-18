# checkout-js — script.js Documentation

Source file: `razorpay/script.js`

---

## Variables Injected from PHP

All keys are set in `razorpay.php:hookHeader()` via `Media::addJsDef()` and exposed as `window.razorpay_checkout_vars`.

| Key | PHP source | Value |
|-----|-----------|-------|
| `key` | `$this->KEY_ID` (`Configuration::get('RAZORPAY_KEY_ID')`) | Razorpay publishable key (`rzp_live_...` or `rzp_test_...`) |
| `name` | `Configuration::get('PS_SHOP_NAME')` | Merchant store name shown in modal header |
| `amount` | `number_format($cart->getOrderTotal() * 100, 0, "", "")` | Cart total in paise (integer string, no decimal) |
| `cart_id` | `$this->context->cart->id` | PS cart ID; also written to Razorpay order notes |
| `rzp_order_id` | Hardcoded `""` | Always empty at page load — populated by the AJAX call to order.php at click time |
| `ps_version` | `_PS_VERSION_` | PrestaShop version string; passed to Razorpay `_` integration metadata |
| `module_version` | `$this->version` (`2.5.8`) | Module version; passed to Razorpay `_` integration metadata |
| `order_controller` | `getModuleLink('razorpay', 'order', [], true)` | AJAX endpoint: POST target for Rzp order creation |
| `action_controller` | `getModuleLink('razorpay', 'validation', ['cart_id' => $cart->id], true)` | Form POST target: validation.php with `cart_id` query param pre-set |

Note: `description` is referenced in `script.js:renderPaymentFrame()` (`defaults.description`) but is **not injected** by `hookHeader()`. It will be `undefined` at runtime — the Razorpay SDK ignores a missing description gracefully.

---

## DOM Manipulation

| Action | Element | Details |
|--------|---------|---------|
| Find | `#payment-confirmation` | The existing PS "Place order" `<div>` wrapper; used as insertion anchor. If absent, script exits early. |
| Create | `<button id="razorpay-pay-button">` | New button injected `beforebegin` the `#payment-confirmation` div |
| Inject | `<style>` block | Embedded in `newSubmitButton.innerHTML`; hides `#payment-confirmation` via CSS sibling selector when the Razorpay button has class `shown` |
| Show/hide Razorpay button | `class="btn btn-primary center-block shown\|not-shown"` | CSS class swap controls `display:block` vs `display:none` |
| Hide PS button | CSS rule `#razorpay-pay-button.shown + #payment-confirmation { display:none !important }` | PS default button hidden when Razorpay method is selected |
| Append at payment success | `<input type="hidden" name="razorpay_signature">` | Added to the validation form before submission |

---

## Button Click Flow

```
User clicks #razorpay-pay-button
  └─ script.js (click handler)
       └─ $.post(defaults.order_controller, '[]')      // POST to order.php
            └─ order.php:postProcess()
                 ├─ creates Razorpay order via API
                 ├─ stores rzp_order_id in $_SESSION and DB
                 └─ returns { success: true, rzp_order_id: "order_XXXX", amount: "NNNN" }
            └─ script.js:renderPaymentFrame(response)
                 └─ new Razorpay(options).open()        // opens Razorpay modal
```

Key non-obvious point: `rzp_order_id` in `razorpay_checkout_vars` is always `""` at page load. The actual Rzp order ID is fetched fresh on every button click. This means clicking Pay multiple times creates multiple Rzp orders (each updates the DB row).

---

## Razorpay Checkout Options

Passed to `new Razorpay(options)` inside `script.js:renderPaymentFrame()`:

| Option | Value | Source |
|--------|-------|--------|
| `name` | `defaults.name` | Shop name from `razorpay_checkout_vars` |
| `key` | `defaults.key` | Razorpay Key ID |
| `amount` | `data.amount` | Paise amount from order.php response (NOT from `razorpay_checkout_vars.amount`) |
| `currency` | `prestashop.currency.iso_code` | Active PS currency; not filtered here — currency exclusion happens in order.php |
| `order_id` | `data.rzp_order_id` | From order.php response |
| `description` | `defaults.description` | Undefined at runtime (not injected); harmless |
| `prefill.name` | `customer.firstname + ' ' + customer.lastname` | `prestashop.customer` JS object |
| `prefill.email` | `customer.email` | `prestashop.customer` JS object |
| `prefill.contact` | `contactNumber` | Extracted from `prestashop.customer.addresses` (see Phone Extraction below) |
| `notes.prestashop_order_id` | `''` | Always empty string at modal open; updated to the real PS order ID later via `hookOrderConfirmation()` calling the Razorpay payment edit API |
| `notes.prestashop_cart_id` | `defaults.cart_id` | PS cart ID |
| `_.integration` | `'prestashop'` | Razorpay integration tracking |
| `_.integration_version` | `defaults.module_version` | `2.5.8` |
| `_.integration_parent_version` | `defaults.ps_version` | PS version string |
| `_.integration_type` | `'plugin'` | Razorpay integration tracking |
| `callback_url` | `defaults.action_controller` | Fallback redirect URL if JS handler fails (validation.php) |
| `handler` | Function (see Callback Flow) | JS success callback |

---

## Callback Flow (Payment Success)

```
Razorpay modal calls handler(obj)
  obj = { razorpay_payment_id, razorpay_signature, razorpay_order_id }

  └─ clearInterval(intervalId)            // cancel any pending interval (intervalId unused in current code)

  └─ Build URL from defaults.action_controller
       └─ url.searchParams.set("razorpay_payment_id", obj.razorpay_payment_id)

  └─ Find the validation form by querying three selectors in priority order:
       1. form[action$="<full action_controller URL>"]
       2. form[action$="<origin + pathname>"]          // handles query-string differences
       3. form[action*="module=razorpay&controller=validation"]

  └─ form.setAttribute('action', url.href)             // inject payment_id into form action URL

  └─ Create <input type="hidden" name="razorpay_signature" value="obj.razorpay_signature">
       └─ form.appendChild(razorpay_signature)

  └─ submitButton.getElementsByTagName('button')[0].click()
       // Clicks the inner <button> inside #payment-confirmation, submitting the form to validation.php
```

Result: validation.php receives `razorpay_payment_id` (via query string on form action) and `razorpay_signature` (via POST body hidden field), plus `cart_id` (already in action_controller URL from PHP).

---

## Visibility Logic

Visibility is controlled by listening to `change` events on `#checkout-payment-step` (capture phase, `true` as third arg).

**Show Razorpay button when:**
- A radio button with `data-module-name` attribute changes (payment method selected), OR
- A checkbox changes (T&C approval)
- AND `input[data-module-name="razorpay"]` is checked

**Hide Razorpay button when:**
- Any of the above conditions change and Razorpay radio is not checked

**Disable Razorpay button when:**
- Any `input[name^=conditions_to_approve]` is unchecked (`!!querySelector(...)` returns true)
- Re-evaluated on every radio/checkbox change, not just on T&C checkbox changes

**CSS mechanism:**
- `shown` class → `display:block` on Razorpay button + `display:none !important` on PS default button (via adjacent sibling selector)
- `not-shown` class → `display:none` on Razorpay button + PS default button visible

---

## Phone Extraction Logic

Executed once at `DOMContentLoaded` via an IIFE:

```javascript
for (var i in prestashop.customer.addresses) {
  var address = prestashop.customer.addresses[i];
  if (address.phone !== '') return address.phone;
  if (address.phone_mobile !== '') return address.phone_mobile;
}
return '';
```

**Priority:** Iterates `prestashop.customer.addresses` in object key order. For each address checks `phone` first, then `phone_mobile`. Returns the first non-empty value found across all addresses. Falls back to `''` if all addresses lack phone numbers.

**Non-obvious:** Iteration order of `for...in` on a JS object is insertion order for string keys in modern engines but is not guaranteed by the ES spec for integer-like keys. If a customer has addresses with IDs like `1`, `2`, `3`, the order is typically numeric ascending in V8/SpiderMonkey, but this is engine-dependent behavior.
