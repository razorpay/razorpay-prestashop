# CLAUDE.md — Claude Code Instructions for Razorpay PrestaShop

> Claude Code project memory. Auto-loaded at session start.

## Build & Test Commands

```bash
# No build step required — pure PHP + JS

# Install PHP SDK dependencies (if needed for dev tooling)
# The SDK is bundled at razorpay/razorpay-sdk/ — no composer required for the module itself

# Lint PHP (if PHP_CodeSniffer is installed)
phpcs --standard=PSR2 razorpay/razorpay.php

# Manual test: deploy to a PrestaShop instance
# Copy razorpay/ directory to <prestashop_root>/modules/razorpay/
```

## Architecture in 4 Files

| File | Responsibility |
|---|---|
| `razorpay/razorpay.php` | Module bootstrap, hooks, admin config, webhook auto-setup |
| `razorpay/controllers/front/order.php` | Creates Razorpay Order (API call), returns JSON |
| `razorpay/controllers/front/validation.php` | Verifies payment signature, creates PrestaShop order |
| `razorpay/razorpay-webhook.php` | Processes server-side Razorpay webhooks |

## Critical Rules — Do NOT Violate

1. **Never pass float amounts to Razorpay API.** Always `number_format(($total * 100), 0, "", "")`.
2. **Never skip signature verification.** `verifyPaymentSignature()` and `verifyWebhookSignature()` must always run before order creation.
3. **Never remove the webhook wait-time guard.** The 300-second delay in `razorpay-webhook.php::orderPaid()` prevents duplicate orders when frontend and webhook race.
4. **Never call `parent::__construct()` in contexts without PS.** Use `new Razorpay(false)` in webhook/order controllers.
5. **Always use `pSQL()` for string DB values and `(int)` for integer IDs** — no raw user input in queries.
6. **The `order_placed` flag is the source of truth** for whether an order was created. Always check it before calling `validateOrder()`.

## Model Selection Guide

| Task | Model |
|---|---|
| Fix typo, add log line, rename variable | `claude-3-5-haiku-20241022` |
| Understand a single file or function | `claude-3-5-haiku-20241022` |
| Debug a payment failure, trace webhook flow | `claude-3-5-sonnet-20241022` |
| Add new webhook event handler | `claude-3-5-sonnet-20241022` |
| Multi-file feature (new payment method, refund flow) | `claude-sonnet-4-5` |
| PR review, security audit | `claude-opus-4-5` |
| Architecture change (new PS version support) | `claude-opus-4-5` |

## Available Skills (Runbooks)

| Skill | File |
|---|---|
| Debug a failed payment | `.agent/skills/debug-payment.md` |
| Add a new webhook event | `.agent/skills/add-webhook-handler.md` |
| Investigate a refund | `.agent/skills/refund-investigation.md` |
| Sync order status | `.agent/skills/order-status-sync.md` |
| Generate test webhook payload | `.agent/skills/generate-test-payload.md` |
| Audit API endpoints | `.agent/skills/api-endpoint-audit.md` |

## Code Style

- Allman braces (opening `{` on new line for classes, methods, control flow)
- 4-space indentation
- `camelCase` for method names
- `UPPER_SNAKE_CASE` for PrestaShop configuration keys
- Single-line `if` conditions use parentheses-on-same-line style

## PrestaShop Patterns to Know

```php
// Read config
Configuration::get('RAZORPAY_KEY_ID')

// Write config
Configuration::updateValue('RAZORPAY_KEY_ID', $value)

// Log (level: 1=info, 2=warning, 3=error, 4=critical)
Logger::addLog("Message", 1);

// Redirect
Tools::redirect('index.php?controller=order');

// DB singleton
$db = \Db::getInstance();

// Generate module URL
$this->context->link->getModuleLink('razorpay', 'validation', ['cart_id' => $id], true)

// PrestaShop order creation (in module context)
$this->module->validateOrder($cartId, $stateId, $amount, $method, $msg, $extra, null, false, $secureKey);
```

## Agent Workflow Patterns

### Debugging a payment failure
```
1. Read .agent/skills/debug-payment.md
2. Check PS Admin > Advanced Parameters > Logs for "razorpay" entries
3. Query razorpay_sales_order table for the cart_id
4. Check order_placed, by_frontend, by_webhook columns
5. Verify signature mismatch vs. API error vs. DB error
```

### Adding a new webhook event
```
1. Read .agent/skills/add-webhook-handler.md
2. Add constant + array entry in razorpay.php
3. Add switch case in razorpay-webhook.php
4. Implement handler method
5. Test with .agent/skills/generate-test-payload.md
```
