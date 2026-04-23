# Payment Flow — Razorpay PrestaShop

## Overview

The module supports a single checkout mode: **No-Redirect Modal Checkout**. The customer remains on the merchant's PrestaShop site throughout the entire payment process. Razorpay's checkout UI appears as an overlay modal powered by `checkout.js` loaded from Razorpay's CDN.

---

## Step-by-Step Frontend Payment Flow

### Phase 1: Page Load (hookHeader)

When the customer visits the PrestaShop order/checkout page (`controller=order`):

**`razorpay.php::hookHeader()`** runs and:
1. Registers `checkout.js` from `https://checkout.razorpay.com/v1/checkout.js` in `<head>`
2. Registers local `script.js` at bottom of page
3. Computes `$amount = number_format(($this->context->cart->getOrderTotal() * 100), 0, "", "")` (paise)
4. Injects JS variables via `Media::addJsDef`:
   ```js
   window.razorpay_checkout_vars = {
     key:                "rzp_live_xxx",      // API Key ID
     name:               "Shop Name",
     amount:             "10000",             // paise as string
     cart_id:            42,
     rzp_order_id:       "",                  // empty; filled after /order call
     ps_version:         "8.1.0",
     module_version:     "2.5.8",
     order_controller:   "https://shop.com/.../razorpay/order",
     action_controller:  "https://shop.com/.../razorpay/validation?cart_id=42",
   }
   ```

**`razorpay.php::hookPaymentOptions()`** registers the Razorpay payment option:
- Logo: `https://cdn.razorpay.com/static/assets/logo/payment_method.svg`
- CTA text: "Pay by Razorpay"
- Description: "Pay using Credit/Debit Card, NetBanking, Wallets, or UPI"

### Phase 2: Button Injection (script.js)

On `DOMContentLoaded`, `script.js`:
1. Creates a new `<button id="razorpay-pay-button">Pay</button>`
2. Inserts it before `#payment-confirmation` div
3. Listens for payment method radio button changes
4. Shows button (CSS class `shown`) only when Razorpay is selected
5. Disables button if Terms & Conditions checkbox is unchecked

### Phase 3: Order Creation (/razorpay/order)

Customer clicks "Pay":

**`script.js`** → `$.post(defaults.order_controller, '[]')`

**`RazorpayOrderModuleFrontController::postProcess()`** (`controllers/front/order.php`):
1. Validates HTTP method is POST
2. Checks `RAZORPAY_WEBHOOK_LAST_VERIFY` — re-runs webhook setup if stale (>24h)
3. Reads `RAZORPAY_PAYMENT_ACTION` from config (`capture` or `authorize`)
4. Checks currency is not in `['KWD', 'OMR', 'BHD']`
5. Calls `$api->order->create([...])`:
   ```php
   [
     'amount'          => "10000",   // paise, string
     'currency'        => "INR",
     'payment_capture' => 1,         // 1=auto-capture, 0=authorize-only
     'receipt'         => "42",      // cart ID as string
   ]
   ```
6. Stores in DB: `INSERT INTO razorpay_sales_order (cart_id, rzp_order_id, amount_paid) VALUES (42, 'order_xxx', 0.00)`
7. Stores in session: `$_SESSION['rzp_order_id'] = 'order_xxx'`
8. Returns JSON:
   ```json
   { "success": true, "rzp_order_id": "order_xxx", "amount": "10000" }
   ```

**Error response** (currency blocked or API error):
```json
{ "message": "Unable to create your order. Please contact support.", "parameters": [] }
```

### Phase 4: Checkout Modal (script.js)

On successful response, `script.js::renderPaymentFrame(data)`:
```js
options = {
  key:       "rzp_live_xxx",
  amount:    "10000",
  order_id:  "order_xxx",
  currency:  "INR",
  prefill: {
    name:    "John Doe",
    email:   "john@example.com",
    contact: "+919876543210"
  },
  notes: {
    prestashop_cart_id: 42,
    prestashop_order_id: ""
  },
  _: {
    integration:               "prestashop",
    integration_version:       "2.5.8",
    integration_parent_version: "8.1.0",
    integration_type:          "plugin"
  },
  callback_url: "https://shop.com/.../razorpay/validation?cart_id=42",
  handler: function(obj) { /* on success */ }
}
new Razorpay(options).open();
```

**`callback_url`** handles in-app browsers (e.g., Instagram, Facebook) that block JavaScript handlers.
**`handler`** handles standard browsers.

### Phase 5: Payment Validation (/razorpay/validation)

After customer completes payment in modal:

**`script.js::handler(obj)`**:
1. Injects hidden `<input name="razorpay_signature">` into form
2. Appends `razorpay_payment_id` as URL parameter to form action
3. Submits form via hidden submit button click

**`RazorpayValidationModuleFrontController::postProcess()`** (`controllers/front/validation.php`):

1. Reads `razorpay_payment_id` from `$_REQUEST`
2. Reconstructs cart/customer context from `cart_id` in URL if session lost
3. Validates cart has customer, delivery address, invoice address
4. Checks Razorpay module is still active (disabled mid-payment edge case)
5. Fetches payment: `$api->payment->fetch($paymentId)`
6. Verifies HMAC signature:
   ```php
   $api->utility->verifyPaymentSignature([
     'razorpay_order_id' => $_SESSION['rzp_order_id'],  // or from DB fallback
     'razorpay_payment_id' => $paymentId,
     'razorpay_signature' => $_POST['razorpay_signature'],
   ]);
   ```
   - On failure: logs error, echoes error HTML, `exit`
7. Calls `$this->module->validateOrder(...)` with:
   - Order state: `PS_OS_PAYMENT` (paid)
   - Payment method: `"razorpay.{$payment->method}"` (e.g., `razorpay.card`)
   - Transaction ID stored in order extra data
8. Updates `razorpay_sales_order`:
   ```sql
   UPDATE razorpay_sales_order
   SET rzp_payment_id='pay_xxx', amount_paid=100.00,
       order_id=123, by_frontend=1, order_placed=1
   WHERE cart_id=42 AND entity_id=5
   ```
9. Redirects to: `index.php?controller=order-confirmation&id_cart=42&id_module=7&id_order=123&key=abc`

### Phase 6: Order Confirmation

PrestaShop renders confirmation page, which calls:

**`razorpay.php::hookPaymentReturn()`** → renders `payment_return.tpl` with:
- `id_order`, `valid`, `reference`, `shop_name`, `contact_url`

**`razorpay.php::hookOrderConfirmation()`** → updates Razorpay payment notes:
```php
$api->payment->fetch($paymentId)->edit([
  'notes' => [
    'prestashop_order_id' => $order->id,
    'prestashop_cart_id'  => $order->id_cart,
  ]
]);
```

---

## Payment Capture Modes

Controlled by `RAZORPAY_PAYMENT_ACTION` configuration:

| Mode | `payment_capture` | Behaviour |
|---|---|---|
| `capture` (default) | `1` | Payment captured immediately on authorization |
| `authorize` | `0` | Payment only authorized; must be captured manually via Razorpay dashboard |

---

## Error Paths

| Error | Where caught | Action |
|---|---|---|
| Invalid currency | `order.php` | Return JSON error, JS shows no modal |
| Razorpay API error (order create) | `order.php` | Return JSON error message |
| Signature mismatch | `validation.php` | Log level 4, echo error HTML, `exit` |
| Payment fetch fails | `validation.php` | Catch `BadRequestError`, log, echo error HTML, `exit` |
| Module disabled mid-payment | `validation.php` | Translate + `die()` |
| Cart invalid | `validation.php` | Redirect to `order&step=1` |
| Notes update fails | `hookOrderConfirmation` | Log level 4, non-fatal |

---

## Amount Handling Reference

```php
// Cart total → paise (for order creation and display)
$amount = number_format(($cart->getOrderTotal() * 100), 0, "", "");
// e.g., 100.50 INR → "10050"

// Validation controller (total as string integer)
$total = (string) intval($cart->getOrderTotal(true, Cart::BOTH) * 100);

// Storing amount paid (paise → currency units)
$amount_paid = number_format($payment->amount / 100, "2", ".", "");
// e.g., 10050 paise → "100.50"
```

---

## Supported Payment Methods

Razorpay Checkout modal supports all methods enabled on the merchant's Razorpay account:
- Credit/Debit Cards (Visa, Mastercard, RuPay, Amex)
- NetBanking (50+ banks)
- UPI (BHIM, Google Pay, PhonePe, etc.)
- Wallets (Paytm, Mobikwik, etc.)
- EMI
- Pay Later (Cardless EMI)

The payment method used is reflected in `$payment->method` and stored in the PS order as `razorpay.{method}`.
