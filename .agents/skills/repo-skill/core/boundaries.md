# Module Boundaries: Razorpay PrestaShop (v2.5.8)

## What This Module Owns

- Creating a Razorpay Order against each PrestaShop cart before checkout.js launches (`order.php:postProcess()`)
- Verifying the HMAC-SHA256 payment signature returned by checkout.js (`validation.php:postProcess()` via `api->utility->verifyPaymentSignature()`)
- Verifying the HMAC-SHA256 webhook signature on every inbound event (`razorpay-webhook.php:process()` via `api->utility->verifyWebhookSignature()`)
- Tracking the dual-path order-creation race (frontend vs. webhook) in the `razorpay_sales_order` table it owns exclusively (`razorpay.php:install()`)
- Auto-registering and updating the webhook endpoint at Razorpay via the management API on every admin save (`razorpay.php:autoEnableWebhook()`)
- Writing Razorpay payment notes back with `prestashop_order_id` and `prestashop_cart_id` after an order is placed (`razorpay-webhook.php:orderPaid()`, `razorpay.php:hookOrderConfirmation()`)

## What It Delegates to PrestaShop Core

| PrestaShop API | Used for |
|----------------|----------|
| `PaymentModule::validateOrder()` | Creating the PS order record, setting payment state to `PS_OS_PAYMENT` |
| `Configuration::get/updateValue/deleteByName()` | Storing all module config (keys, webhook secret, wait time) |
| `Cart` | Reading totals, customer id, delivery/invoice addresses |
| `Order::getOrderByCartId()` | Checking whether an order already exists for a cart before webhook creates one |
| `Order::setCurrentState()` | Updating order status when webhook fires after frontend already created the order |
| `Customer` | Loading customer from cart for `secure_key` during `validateOrder()` |
| `Module::getPaymentModules()` | Authorisation guard — confirms this module is still active at validation time |
| `Logger::addLog()` | All operational logging (no custom log sink) |
| `Tools::redirect()` | Redirecting on success or cart-validation failure |
| `Media::addJsDef()` | Injecting checkout JS variables into the page (`razorpay_checkout_vars`) |

## What It Delegates to the Razorpay PHP SDK

SDK entry point: `razorpay.php:getRazorpayApiInstance()` returns `new Api($KEY_ID, $KEY_SECRET)`.

| SDK Resource | Method | Called from |
|--------------|--------|-------------|
| `api->order->create()` | Creates Razorpay order | `order.php:postProcess()` |
| `api->payment->fetch()` | Fetches payment details (method, amount) | `validation.php:postProcess()` |
| `api->payment->fetch()->edit()` | Writes PS order/cart IDs to payment notes | `razorpay-webhook.php:orderPaid()`, `razorpay.php:hookOrderConfirmation()` |
| `api->utility->verifyPaymentSignature()` | HMAC verification after checkout.js | `validation.php:postProcess()` |
| `api->utility->verifyWebhookSignature()` | HMAC verification on webhook POST body | `razorpay-webhook.php:process()` |
| `api->request->request()` | Raw HTTP calls to webhook management API | `razorpay.php:webhookAPI()` |

## HTTP Endpoints Exposed

| Endpoint (module link) | Controller | Method | Purpose |
|------------------------|------------|--------|---------|
| `/module/razorpay/order` | `order.php:postProcess()` | POST only (enforced; exits 400 on GET) | Creates Razorpay order; returns JSON `{rzp_order_id, amount}` |
| `/module/razorpay/validation` | `validation.php:postProcess()` | POST (form submit from checkout.js) | Verifies signature, calls `validateOrder()`, redirects to order-confirmation |
| `/module/razorpay/webhook` | `webhook.php:postProcess()` | POST (Razorpay server push) | Verifies webhook signature, routes `order.paid` event to create order if frontend missed |

## PrestaShop Hooks Registered

| Hook | Handler | What it does |
|------|---------|--------------|
| `header` | `razorpay.php:hookHeader()` | On the checkout page only: loads checkout.js from CDN and `script.js`, injects `razorpay_checkout_vars` JSON (key_id, amount, cart_id, controller URLs) |
| `paymentOptions` | `razorpay.php:hookPaymentOptions()` | Returns a single `PaymentOption` object with logo, CTA text, and action URL pointing to `/validation` |
| `orderConfirmation` | `razorpay.php:hookOrderConfirmation()` | Writes PS order/cart IDs back to Razorpay payment notes; displays the payment ID string on confirmation page |
| `paymentReturn` | `razorpay.php:hookPaymentReturn()` | Renders `payment_return.tpl` (order reference, shop name, contact URL) after redirect |

## What This Module Does NOT Do

- **Refunds** — no refund initiation UI, no call to `api->payment->refund()` anywhere in the module.
- **Subscription or recurring payments** — no subscription API calls; the `payment.authorized` and `payment.failed` webhook handlers are stubs that return immediately (`razorpay-webhook.php:paymentAuthorized()`, `razorpay-webhook.php:paymentFailed()`).
- **Partial captures** — payment capture mode is binary: full auto-capture (`payment_capture=1`) or manual authorize-only (`payment_capture=0`), set at order creation time.
- **Multi-currency beyond INR intent** — three currencies are hard-blocked (`KWD`, `OMR`, `BHD`) via `Razorpay::CURRENCY_NOT_ALLOWED`; no currency conversion logic exists.
- **Admin order management actions** — no hooks into `actionOrderStatusUpdate` or similar; post-order state is managed entirely by `Order::setCurrentState()` driven from the webhook.
- **Webhook signature rotation** — the webhook secret is generated once on first save (`razorpay.php:createWebhookSecret()`) and reused; there is no rotation flow.
- **Localhost webhook registration** — `autoEnableWebhook()` explicitly skips registration and logs a warning when the resolved IP is a private/reserved range.

## Non-Obvious Constraint: Webhook Wait Time Gate

Before the webhook creates an order, it checks `(time() - webhook_first_notified_at) < RAZORPAY_WEBHOOK_WAIT_TIME` (default 300 s). If the gap is under the threshold, it returns HTTP 409 and exits without creating the order. This gives the frontend path priority. If the frontend completes first (sets `order_placed = 1`), the webhook detects this via `$salesOrderData['order_placed']` and exits without duplication. Both paths set `by_webhook` or `by_frontend` flags in `razorpay_sales_order` to record which path won. (`razorpay-webhook.php:orderPaid()`)
