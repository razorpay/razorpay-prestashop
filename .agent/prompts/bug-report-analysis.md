# Prompt: Bug Report Analysis — Razorpay PrestaShop

Use this prompt to analyze a bug report and identify the root cause.

---

## Prompt Template

```
You are debugging an issue in the Razorpay PrestaShop payment extension (v2.5.8).

Architecture summary:
- Payment flow: JS (script.js) → POST /razorpay/order → POST /razorpay/validation → PS order
- Webhook fallback: Razorpay → POST /razorpay/webhook → RZP_Webhook::orderPaid()
- Key table: razorpay_sales_order (tracks order_placed, by_frontend, by_webhook, webhook_count)
- Key session: $_SESSION['rzp_order_id'] (set in order.php, read in validation.php)
- Security: HMAC-SHA256 for both payment and webhook signature verification

Bug report:
[PASTE BUG DESCRIPTION HERE]

Available diagnostic information:
- PS Logs (Admin > Advanced Parameters > Logs, filter "razorpay"): [PASTE LOGS]
- razorpay_sales_order table row: [PASTE ROW DATA]
- Razorpay Dashboard payment status: [PASTE STATUS]

Please:
1. Identify the most likely root cause
2. State which file/line in the codebase is responsible
3. Describe the exact sequence of events that caused this bug
4. Suggest a targeted fix
5. List any related common pitfalls to check (from AGENTS.md)
```

---

## Common Bug Signatures

### "Payment charged but no order created"
Key data to provide:
- `razorpay_sales_order` row for the cart_id
- `order_placed`, `by_frontend`, `by_webhook`, `webhook_count` values
- PS logs around the payment time
- `ENABLE_RAZORPAY_WEBHOOK` value from `ps_configuration`

### "Signature verification error"
Key data to provide:
- `rzp_order_id` from `razorpay_sales_order` WHERE cart_id = X
- `$_SESSION` contents (if accessible from logs)
- The exact error message logged

### "Duplicate order created"
Key data to provide:
- Both `ps_orders` rows (same `id_cart`)
- `razorpay_sales_order` row for the cart
- Whether `order_id` unique constraint was violated

### "Webhook always returns 409"
Key data to provide:
- `webhook_first_notified_at` value
- `RAZORPAY_WEBHOOK_WAIT_TIME` config value
- Current Unix timestamp vs `webhook_first_notified_at`
