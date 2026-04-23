# Gemini — Razorpay PrestaShop Context

## Project Identity
- **Name**: Razorpay PrestaShop Payment Extension
- **Version**: 2.5.8
- **Language**: PHP 5.6+ / JavaScript (ES5)
- **Platform**: PrestaShop 1.7.0.0 – 9.0.3
- **Purpose**: Accept payments via Razorpay Checkout modal (no redirect)

## Critical Architecture Points

1. **No redirect** — Razorpay Checkout.js opens as a modal overlay on the PS checkout page
2. **Dual path** — Both frontend (validation.php) AND webhook (razorpay-webhook.php) can create the PrestaShop order
3. **Race condition guard** — Webhook waits 300 seconds (configurable) before creating order to let frontend finish first
4. **Duplicate prevention** — `order_placed` flag in `razorpay_sales_order` table is the source of truth

## Key Rules for Code Changes

- Amounts to Razorpay API: always paise as integer string
  ```php
  $amount = number_format(($total * 100), 0, "", "");
  ```
- Verify HMAC signature BEFORE creating any order
- Use `new Razorpay(false)` in controllers (not `new Razorpay()`)
- SQL: `(int)$id` for integers, `pSQL($str)` for strings

## File Map

| Goal | File |
|---|---|
| Change checkout behavior | `razorpay/script.js` |
| Change order creation | `razorpay/controllers/front/order.php` |
| Change payment validation | `razorpay/controllers/front/validation.php` |
| Change webhook handling | `razorpay/razorpay-webhook.php` |
| Change admin settings | `razorpay/razorpay.php` + `razorpay/views/templates/admin/admin.tpl` |

## Full Documentation
See `AGENTS.md` at repository root for complete architecture documentation.
