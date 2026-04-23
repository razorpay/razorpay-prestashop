# AGENTS.md — Razorpay PrestaShop Payment Extension

> Universal AI assistant context for Claude, Gemini, Kimi, GPT-4, Copilot, and others.
> Start here. This file gives you the full picture in one place.

---

## Project Identity

| Field | Value |
|---|---|
| **Plugin** | Razorpay Payment Extension for PrestaShop |
| **Version** | 2.5.8 |
| **Module name** | `razorpay` |
| **Primary class** | `Razorpay extends PaymentModule` |
| **Language** | PHP 5.6+ / JavaScript (ES5) |
| **Platform** | PrestaShop 1.7.0.0 – 9.0.3 |
| **SDK** | Razorpay PHP SDK v2.8.1 (bundled at `razorpay/razorpay-sdk/`) |
| **Purpose** | Accept payments via Razorpay Checkout modal — no redirect |

---

## Quick Navigation

| Task | Go to |
|---|---|
| Understand overall architecture | [`.ai/context/CODEBASE_OVERVIEW.md`](.ai/context/CODEBASE_OVERVIEW.md) |
| Trace a payment end-to-end | [`.ai/context/PAYMENT_FLOW.md`](.ai/context/PAYMENT_FLOW.md) |
| Understand webhook processing | [`.ai/context/WEBHOOK_FLOW.md`](.ai/context/WEBHOOK_FLOW.md) |
| Database tables and schema | [`.ai/context/DATABASE_SCHEMA.md`](.ai/context/DATABASE_SCHEMA.md) |
| Razorpay API calls made | [`.ai/context/API_INTEGRATION.md`](.ai/context/API_INTEGRATION.md) |
| Admin config panel | [`.ai/context/ADMIN_CONFIGURATION.md`](.ai/context/ADMIN_CONFIGURATION.md) |
| High-level diagrams | [`.ai/diagrams/`](.ai/diagrams/) |
| Sequence diagrams | [`.ai/diagrams/LLD_CHECKOUT_SEQUENCE.md`](.ai/diagrams/LLD_CHECKOUT_SEQUENCE.md) |
| Debug a failed payment | [`.agent/skills/debug-payment.md`](.agent/skills/debug-payment.md) |
| Add a new webhook event | [`.agent/skills/add-webhook-handler.md`](.agent/skills/add-webhook-handler.md) |
| Investigate a refund issue | [`.agent/skills/refund-investigation.md`](.agent/skills/refund-investigation.md) |
| Model selection guide | [`.agent/config/models.yaml`](.agent/config/models.yaml) |

---

## Repository Structure

```
razorpay-prestashop/
├── AGENTS.md                          ← You are here
├── CLAUDE.md                          ← Claude Code-specific instructions
├── README.md                          ← User-facing install guide
│
├── razorpay/                          ← Module root (deployed to PS modules/)
│   ├── razorpay.php                   ← Main module class (Razorpay extends PaymentModule)
│   ├── razorpay-webhook.php           ← Webhook processor class (RZP_Webhook)
│   ├── script.js                      ← Frontend checkout JS
│   ├── config.xml                     ← Module metadata
│   ├── logo.png                       ← Module logo
│   ├── CHANGELOG.md                   ← Version history
│   │
│   ├── razorpay-sdk/                  ← Bundled Razorpay PHP SDK v2.8.1
│   │   ├── Razorpay.php               ← SDK autoloader
│   │   └── src/
│   │       ├── Api.php                ← Main API client
│   │       ├── Utility.php            ← Signature verification
│   │       ├── Order.php, Payment.php, Refund.php, ...
│   │       └── libs/Requests-1.8.0/  ← HTTP transport
│   │
│   ├── controllers/front/
│   │   ├── order.php                  ← POST /razorpay/order → creates Razorpay Order
│   │   ├── validation.php             ← POST /razorpay/validation → verifies & creates PS order
│   │   └── webhook.php                ← POST /razorpay/webhook → receives Razorpay webhooks
│   │
│   └── views/templates/
│       ├── admin/admin.tpl            ← Admin settings page
│       └── hook/payment_return.tpl    ← Order confirmation page
│
├── .ai/
│   ├── context/                       ← Deep-dive documentation files
│   └── diagrams/                      ← Mermaid architecture and sequence diagrams
│
├── .agent/
│   ├── config/                        ← AI model configs (claude.yaml, gemini.yaml, etc.)
│   ├── skills/                        ← Task-specific runbooks for AI agents
│   └── prompts/                       ← Reusable prompt templates
│
├── .claude/CLAUDE.md                  ← Claude Code project memory
├── .gemini/GEMINI.md                  ← Gemini-specific context
├── .kimi/KIMI.md                      ← Kimi-specific context
├── .github/copilot-instructions.md    ← GitHub Copilot instructions
│
├── context/project-context.json       ← Machine-readable project metadata
└── docs/
    ├── architecture/HLD.md, LLD.md    ← Architecture docs
    ├── context/codebase-map.md        ← File-to-responsibility map
    └── flows/                         ← Detailed flow docs
```

---

## Key Architectural Decisions

### 1. No-Redirect Checkout (Modal)
The module uses Razorpay Checkout.js to show a payment modal overlay. The customer **never leaves the merchant's site**. This is set up in `script.js` and `hookHeader()` in `razorpay.php`.

### 2. Dual Payment Processing (Frontend + Webhook)
Every payment goes through **two independent paths**:
- **Frontend path**: Customer completes payment → `validation.php` creates the PrestaShop order immediately
- **Webhook path**: Razorpay sends `order.paid` event → `razorpay-webhook.php` creates the order if frontend failed

The `razorpay_sales_order` DB table tracks which path succeeded (`by_frontend=1` or `by_webhook=1`) and prevents duplicates.

### 3. Amount Always in Paise (Integer)
Razorpay API requires amounts as integers in the **smallest currency unit** (paise for INR).

```php
// Correct — always use this pattern
$amount = number_format(($cart->getOrderTotal() * 100), 0, "", "");  // → "10000"
$total  = (string) intval($cart->getOrderTotal(true, Cart::BOTH) * 100);
```
**Never pass float amounts to Razorpay API.**

### 4. Webhook Wait-Time Guard (Race Condition Prevention)
When a webhook arrives before the frontend has finished creating the order:
- The webhook records `webhook_first_notified_at` and responds HTTP 409
- Subsequent webhook retries check: `(time() - webhook_first_notified_at) < RAZORPAY_WEBHOOK_WAIT_TIME`
- Default wait time: **300 seconds** (configurable in admin)
- After wait expires, webhook creates the order

### 5. Session + DB for Order ID Continuity
The Razorpay Order ID (`order_xxx`) is stored in:
- PHP `$_SESSION['rzp_order_id']` — for the current browser session
- `razorpay_sales_order.rzp_order_id` — persistent fallback if session is lost

Validation controller checks session first, falls back to DB query.

### 6. Auto Webhook Setup
On admin settings save, `autoEnableWebhook()` automatically creates/updates the webhook endpoint via Razorpay API. It re-verifies once per day (86400 seconds). Localhost domains are skipped (cannot receive webhooks).

### 7. Currency Blocking
Three currencies are blocked: `KWD`, `OMR`, `BHD` (3-decimal currencies incompatible with Razorpay).
Constant defined in `razorpay.php:30`: `CURRENCY_NOT_ALLOWED = ['KWD', 'OMR', 'BHD']`

---

## Complete Payment Flows

### Standard Frontend Flow
```
1. Customer selects "Pay by Razorpay" at checkout
2. script.js shows #razorpay-pay-button (replaces default submit)
3. Customer clicks Pay → script.js POSTs to /razorpay/order
4. order.php: validates currency, calls Razorpay Orders API, saves to DB + session
5. Returns JSON: { success: true, rzp_order_id: "order_xxx", amount: 10000 }
6. script.js opens Razorpay Checkout modal with order_id
7. Customer completes payment in modal
8. Modal calls handler(obj) with { razorpay_payment_id, razorpay_signature }
9. script.js injects signature into form, submits to /razorpay/validation
10. validation.php: verifies HMAC signature, calls validateOrder(), redirects to confirmation
```

### Webhook Fallback Flow
```
1. Payment succeeds but frontend fails (network drop, browser close)
2. Razorpay sends order.paid POST to /razorpay/webhook
3. webhook.php instantiates RZP_Webhook, calls process()
4. process(): verifies X-Razorpay-Signature header against RAZORPAY_WEBHOOK_SECRET
5. orderPaid(): extracts cart_id from payment notes, checks razorpay_sales_order table
6. If order already exists → exit (duplicate prevention)
7. If first webhook → record timestamp, return 409 (wait for frontend)
8. If wait time elapsed → call validateOrder(), update DB with by_webhook=1
```

---

## Code Conventions

### PHP Style
- Allman-style braces (opening brace on new line)
- 4-space indentation
- `camelCase` methods, `UPPER_SNAKE_CASE` configuration keys
- Access PrestaShop config via `Configuration::get('KEY')` / `Configuration::updateValue('KEY', $val)`
- Log via `Logger::addLog($message, $level)` — level 1=info, 3=warning, 4=error

### Amount Conversion
```php
// Cart total to paise (for Razorpay API)
$amount = number_format(($cart->getOrderTotal() * 100), 0, "", "");

// Payment amount from Razorpay (paise to currency unit)
$amount_paid = number_format($payment->amount / 100, "2", ".", "");
```

### Database Access
```php
$db = \Db::getInstance();
$db->getValue("SELECT col FROM table WHERE id = " . (int)$id);
$db->getRow("SELECT * FROM table WHERE id = " . (int)$id);
$db->execute("INSERT INTO ...");
// Always cast IDs to int: (int)$cartId
// Always sanitize strings: pSQL($string)
```

### Controller Response Pattern
```php
// JSON response from order.php
header('Content-Type: application/json', true, $code);
echo json_encode($responseContent);
exit;
```

### PrestaShop Order Creation
```php
$this->module->validateOrder(
    $cart->id,                                    // cart ID
    (int) Configuration::get('PS_OS_PAYMENT'),    // order state (paid)
    $cart->getOrderTotal(true, Cart::BOTH),        // amount
    $method,                                       // e.g. "razorpay.card"
    'Payment by Razorpay using ' . $method,        // message
    ['transaction_id' => $paymentId],              // extra data
    NULL,                                          // currency (default)
    false,                                         // dont_touch_amount
    $customer->secure_key                          // security key
);
```

---

## How-To Guides for Common Changes

### Add a New Webhook Event
1. Add event constant to `razorpay.php` (e.g., `const PAYMENT_CAPTURED = 'payment.captured'`)
2. Add to `$webhookSupportedEvents` array in `razorpay.php`
3. Add to `$webhookEvents` array with `true` default
4. Add case to `switch` in `RZP_Webhook::process()` in `razorpay-webhook.php`
5. Implement handler method `protectedpaymentCaptured(array $data)`
6. See runbook: `.agent/skills/add-webhook-handler.md`

### Add a New Admin Setting
1. Add `Configuration::get('NEW_KEY')` in `Razorpay::__construct()`
2. Add Smarty var assignment in `_displayForm()`
3. Add HTML field to `razorpay/views/templates/admin/admin.tpl`
4. Add validation in `_postValidation()` if required
5. Save in `_postProcess()` via `Configuration::updateValue('NEW_KEY', Tools::getValue('NEW_KEY'))`
6. Add `Configuration::deleteByName('NEW_KEY')` in `uninstall()`

### Modify Frontend Checkout Behaviour
- All JS logic is in `razorpay/script.js`
- PHP variables passed to JS via `Media::addJsDef(['razorpay_checkout_vars' => [...]])` in `hookHeader()`
- Add new JS vars by extending the array in `hookHeader()` and reading `window.razorpay_checkout_vars.new_key` in JS

### Change Payment Method Label
- Modify `setCallToActionText()` and `setAdditionalInformation()` in `hookPaymentOptions()` in `razorpay.php`

---

## Configuration Keys (PrestaShop `ps_configuration` table)

| Key | Type | Default | Description |
|---|---|---|---|
| `RAZORPAY_KEY_ID` | string | null | API Key ID (live or test) |
| `RAZORPAY_KEY_SECRET` | string | null | API Key Secret |
| `RAZORPAY_PAYMENT_ACTION` | string | `capture` | `capture` or `authorize` |
| `ENABLE_RAZORPAY_WEBHOOK` | string | `off` | `on` or `off` |
| `RAZORPAY_WEBHOOK_EVENTS` | string | — | Comma-separated selected events |
| `RAZORPAY_WEBHOOK_SECRET` | string | auto-generated | 20-char webhook HMAC secret |
| `RAZORPAY_WEBHOOK_WAIT_TIME` | int | `300` | Seconds to wait before webhook creates order |
| `RAZORPAY_WEBHOOK_LAST_VERIFY` | int | — | Unix timestamp of last webhook setup |

---

## Database Schema

### `razorpay_sales_order`
Created on module install. Dropped on uninstall.

| Column | Type | Description |
|---|---|---|
| `entity_id` | INT UNSIGNED AUTO_INCREMENT | Primary key |
| `cart_id` | INT(11) | PrestaShop cart ID |
| `order_id` | INT(11) | PrestaShop order ID (set after order creation) |
| `rzp_order_id` | VARCHAR(25) | Razorpay Order ID (`order_xxx`) |
| `rzp_payment_id` | VARCHAR(25) | Razorpay Payment ID (`pay_xxx`) |
| `amount_paid` | DECIMAL(20,2) | Amount in currency units (not paise) |
| `by_webhook` | TINYINT(1) | 1 if order created by webhook |
| `by_frontend` | TINYINT(1) | 1 if order created by frontend |
| `webhook_count` | SMALLINT(6) | Number of webhook attempts received |
| `order_placed` | TINYINT(1) | 1 if order has been created (either path) |
| `webhook_first_notified_at` | BIGINT(20) | Unix timestamp of first webhook attempt |

**Unique constraints**: `(cart_id, rzp_payment_id)` and `(order_id)`

---

## URL Endpoints

| URL Pattern | Controller | Method | Purpose |
|---|---|---|---|
| `/razorpay/order` | `RazorpayOrderModuleFrontController` | POST | Create Razorpay Order |
| `/razorpay/validation?cart_id=X` | `RazorpayValidationModuleFrontController` | POST | Verify payment, create PS order |
| `/razorpay/webhook` | `RazorpayWebhookModuleFrontController` | POST | Receive Razorpay webhooks |

URLs are generated with `$this->context->link->getModuleLink('razorpay', 'order', [], true)`.

---

## Security Model

| Mechanism | Implementation |
|---|---|
| Payment signature | `$api->utility->verifyPaymentSignature()` — HMAC-SHA256 of `order_id + "|" + payment_id` |
| Webhook signature | `$api->utility->verifyWebhookSignature()` — HMAC-SHA256 of raw POST body |
| Customer key | `$customer->secure_key` passed to `validateOrder()` |
| SQL injection | `(int)$id` for integers, `pSQL($string)` for strings |
| CSRF | PrestaShop token system (`Tools::getValue`, `Tools::isSubmit`) |

**Never skip signature verification.** Both `verifyPaymentSignature` and `verifyWebhookSignature` must pass before any order creation.

---

## Razorpay API Calls Made by This Module

| Call | When | File |
|---|---|---|
| `$api->order->create($data)` | Customer clicks Pay | `controllers/front/order.php` |
| `$api->payment->fetch($paymentId)` | After payment, before creating order | `controllers/front/validation.php` |
| `$api->utility->verifyPaymentSignature($attrs)` | Payment callback | `controllers/front/validation.php` |
| `$api->utility->verifyWebhookSignature(...)` | Every webhook | `razorpay-webhook.php` |
| `$api->payment->fetch($id)->edit($notes)` | After order creation | `razorpay.php`, `razorpay-webhook.php` |
| `$api->request->request('GET', 'webhooks?...')` | Admin settings save | `razorpay.php::autoEnableWebhook()` |
| `$api->request->request('POST/PUT', 'webhooks/')` | Admin settings save | `razorpay.php::autoEnableWebhook()` |

---

## Common Pitfalls

1. **Double order creation**: The `order_placed` flag in `razorpay_sales_order` prevents this. Always check it before calling `validateOrder()`.

2. **Session lost between order creation and validation**: `validation.php` falls back to DB query for `rzp_order_id` if `$_SESSION['rzp_order_id']` is not set.

3. **Webhook arrives before frontend**: The 409 + wait-time mechanism handles this. Do not remove the `webhookWaitTime` check.

4. **Blocked currencies**: `KWD`, `OMR`, `BHD` will cause order creation to fail silently in `order.php`. Check `Razorpay::CURRENCY_NOT_ALLOWED` before adding multi-currency features.

5. **Webhook not firing for localhost**: `autoEnableWebhook()` explicitly skips private/reserved IP ranges. This is by design; use ngrok or a live domain for webhook testing.

6. **Amount rounding errors**: Always use `number_format(($total * 100), 0, "", "")` — never `round()` or direct multiplication.

7. **`payment_capture` flag**: In `order.php`, `payment_capture: 1` means auto-capture on authorization. `payment_capture: 0` means authorize-only. Maps to `RAZORPAY_PAYMENT_ACTION` config.

8. **`$this->module->currentOrder`**: Set by `validateOrder()`. Only available immediately after the call; do not cache it.

9. **Smarty variable access**: In `.tpl` files, PHP vars must be assigned via `$this->smarty->assign()` in the PHP controller. Direct PHP is not available in Smarty templates.

10. **`new Razorpay(false)`**: The `false` argument prevents calling `parent::__construct()` which requires a PS context. Used in `order.php` and `razorpay-webhook.php` where a lightweight instance is needed.

---

## Testing

- No automated test suite exists in this repository
- Manual testing: Install module on a PrestaShop test instance, use Razorpay test credentials (`rzp_test_xxx`)
- Use Razorpay test cards: `4111 1111 1111 1111` (success), `4000 0000 0000 0002` (decline)
- Webhook testing: Use `ngrok` to expose localhost, configure webhook URL in Razorpay dashboard
- Check PrestaShop logs at **Admin > Advanced Parameters > Logs** (filtered by `razorpay`)

---

## External Dependencies

| Dependency | Purpose | Location |
|---|---|---|
| Razorpay PHP SDK v2.8.1 | API client, signature verification | `razorpay/razorpay-sdk/` |
| Razorpay Checkout.js | Payment modal UI | CDN: `https://checkout.razorpay.com/v1/checkout.js` |
| Razorpay CDN logo | Payment method logo | `https://cdn.razorpay.com/static/assets/logo/payment_method.svg` |
| jQuery (`$`) | AJAX call in script.js | Provided by PrestaShop core |
| PrestaShop `prestashop` JS object | Customer/cart/shop data in frontend | Provided by PrestaShop core |
