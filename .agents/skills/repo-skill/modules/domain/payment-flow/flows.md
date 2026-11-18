# Payment Flow: Code Paths

## Happy Path (CAPTURE mode)

### Create Order + Open Modal + Confirm Payment

| Flow | Trigger | Key Functions | Decision Points | Outcome |
|------|---------|---------------|-----------------|---------|
| Happy path (most traffic) | Customer clicks "Pay" button | `script.js:newSubmitButton.click` -> `order.php:postProcess()` -> `script.js:renderPaymentFrame()` -> `validation.php:postProcess()` | DP1: currency allowed, DP2: order exists in DB, DP3: session has rzp_order_id, DP4: signature valid | Order confirmed, redirect to order-confirmation |
| Currency blocked (rare) | Same button click | `order.php:postProcess()` -> currency check | DP1: currency in blocklist | HTTP 400, error JSON, modal never opens |
| Duplicate cart submission (uncommon) | User clicks Pay twice for same cart | `order.php:postProcess()` -> DB SELECT | DP2: row already exists | UPDATE replaces rzp_order_id, new Razorpay order used |
| Session missing (shared hosting) | Browser redirect, session lost | `validation.php:postProcess()` -> DB SELECT | DP3: `$_SESSION['rzp_order_id']` not set | rzp_order_id fetched from `razorpay_sales_order` by cart_id |
| Signature invalid | Tampered payment_id or forged POST | `validation.php:postProcess()` -> `verifyPaymentSignature()` | DP4: HMAC mismatch | Raw HTML error, `exit`, no order created |
| Payment fetch fails | Invalid payment_id or API error | `validation.php:postProcess()` | BadRequestError catch | Raw HTML error, `exit` |

**Decision Points:**

- **DP1: Currency Blocklist Check** (`order.php:postProcess()`, line 45) — `Razorpay::CURRENCY_NOT_ALLOWED` = `['KWD', 'OMR', 'BHD']`. These currencies use sub-unit denominators that conflict with Razorpay's integer-paise model. The check happens before any API call; if the currency is blocked, `$order` is never assigned, the `if (null !== $order)` guard at line 66 keeps the error response, and the response exits with HTTP 400.

- **DP2: INSERT vs UPDATE in razorpay_sales_order** (`order.php:postProcess()`, lines 86-103) — SELECT by `cart_id` first. Empty result → INSERT. Non-empty → UPDATE. This means each new "Pay" click for the same cart replaces the stored rzp_order_id with a freshly created Razorpay order, preventing stale order IDs from reaching validation.

- **DP3: Session vs DB rzp_order_id lookup** (`validation.php:postProcess()`, lines 73-86) — Session is tried first (`$_SESSION['rzp_order_id']`). If not set, falls back to DB SELECT on `razorpay_sales_order` by `cart_id` from `$_REQUEST`. Session can be absent on shared hosting where PHP sessions don't persist across processes.

- **DP4: Signature Verification** (`validation.php:postProcess()`, lines 88-101) — `$api->utility->verifyPaymentSignature()` computes HMAC-SHA256 over `rzp_order_id + "|" + razorpay_payment_id` using the key secret. Mismatch throws `SignatureVerificationError`. The catch block prints raw HTML (not a redirect) and calls `exit`, so no PrestaShop order is ever created.

---

## Authorize-Only Mode Differences

When `RAZORPAY_PAYMENT_ACTION` = `'authorize'` (configured in admin):

- `order.php:postProcess()` line 50: `payment_capture` is set to `0` instead of `1` in the `$orderData` array sent to `order->create()`.
- With `payment_capture=0`, Razorpay holds the payment in `authorized` state — funds are reserved but not captured.
- `validation.php` is unchanged: it calls `validateOrder()` with `PS_OS_PAYMENT` regardless of capture mode.
- Actual capture must happen via the webhook (`payment.authorized` event) or manual action. Without a capture step, the hold expires per Razorpay's authorization window.

---

## Currency Blocklist Path (KWD / OMR / BHD)

Branch in `order.php:postProcess()` at line 45:

```
in_array($this->context->currency->iso_code, Razorpay::CURRENCY_NOT_ALLOWED)
```

- `true` (blocked): enters the `else` branch at line 57, logs the unsupported currency, `$order` is never assigned.
- The `if (null !== $order && !empty($order->id))` guard at line 66 is never entered.
- `$responseContent` stays as `['message' => 'Unable to create your order...', 'parameters' => []]`.
- Response exits with HTTP 400 (`$code` stays at `400`).
- `script.js:renderPaymentFrame()` is never called because `response.success` is absent from the JSON.

---

## Signature Verification Failure Path

`validation.php:postProcess()` lines 88-101:

1. `$api->utility->verifyPaymentSignature($attributes)` throws `SignatureVerificationError`.
2. Catch block at line 90: logs to PrestaShop logger at level 4.
3. Outputs raw HTML lines directly (`echo`), not a redirect or template.
4. Calls `exit` — PHP terminates without calling `validateOrder()`, without writing to `razorpay_sales_order`, and without redirecting to order-confirmation.
5. Customer sees a plain error page with Order Id and Razorpay Payment Id for manual reconciliation with the merchant.

---

## JS-Side Flow Detail

`script.js` orchestrates the browser side:

- On `DOMContentLoaded`: a custom `#razorpay-pay-button` is injected before the native PrestaShop `#payment-confirmation` div. CSS hides one or the other depending on whether Razorpay is the selected payment method (`script.js` lines 136-149).
- Button click (`script.js` line 110) fires a `$.post` to `order_controller` (order.php). On success it calls `renderPaymentFrame(response)` passing `rzp_order_id` and `amount` from the server response.
- After successful modal payment, the `handler` callback (`script.js` lines 73-103): appends a hidden `razorpay_signature` input to the existing validation form, sets `razorpay_payment_id` as a URL param on the form action, then programmatically clicks the native PrestaShop submit button to POST to `validation.php`.
- `callback_url` is also set as a fallback for redirect-based payment methods (netbanking, UPI redirect) where the Razorpay modal cannot fire the JS handler.
