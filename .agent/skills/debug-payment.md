# Skill: Debug a Failed Payment

Use this runbook when a customer reports a payment failure, missing order, or payment was charged but no order was created.

---

## Step 1: Get the Cart ID or Order ID from the customer

Ask the customer for any of:
- PrestaShop Order ID
- PrestaShop Cart ID (from URL: `index.php?controller=order-confirmation&id_cart=X`)
- Razorpay Payment ID (`pay_xxxxxxxxxx`)
- Razorpay Order ID (`order_xxxxxxxxxx`)

---

## Step 2: Check PrestaShop Logs

**Admin → Advanced Parameters → Logs** → filter by "razorpay"

Or query the DB directly:
```sql
SELECT * FROM ps_log
WHERE object_type LIKE '%razorpay%'
   OR message LIKE '%razorpay%'
   OR message LIKE '%pay_%'
ORDER BY date_add DESC
LIMIT 50;
```

Key log patterns to look for:
- `"Payment Successful for Order#X"` — frontend succeeded
- `"Payment Failed for Order# X"` — signature or API error
- `"Razorpay Webhook: Quote order is inactive for cartID: X"` — duplicate (OK)
- `"Razorpay Webhook: Order processing is active for cartID: X, attempt N"` — wait time
- `"Order creation Failed for Cart# X"` — webhook failed to create order
- `"Signature Verification failed"` — HMAC mismatch

---

## Step 3: Query razorpay_sales_order Table

```sql
SELECT * FROM razorpay_sales_order
WHERE cart_id = $CART_ID;
-- OR
SELECT * FROM razorpay_sales_order
WHERE rzp_payment_id = 'pay_xxxxxxxxxx';
-- OR (if no record by payment_id yet)
SELECT * FROM razorpay_sales_order
ORDER BY entity_id DESC LIMIT 10;
```

**Interpret results**:

| `order_placed` | `by_frontend` | `by_webhook` | Meaning |
|---|---|---|---|
| 1 | 1 | 0 | Normal — frontend created order |
| 1 | 0 | 1 | Webhook fallback created order |
| 0 | 0 | 0 | Order creation failed (both paths) |
| 0 | 0 | 0 + `webhook_count > 0` | Webhook is waiting (check wait time) |
| NULL | — | — | No record at all — order.php may have failed |

---

## Step 4: Check If Signature Could Have Failed

Signature mismatch causes:
1. Session lost between order creation and validation → `rzp_order_id` from session is wrong
2. Customer used browser back button (different session)
3. In-app browser (Instagram/WhatsApp) blocked JavaScript `handler` — should use `callback_url` instead

Check:
```sql
-- Does DB have correct rzp_order_id for this cart?
SELECT cart_id, rzp_order_id FROM razorpay_sales_order WHERE cart_id = $CART_ID;
```

If `rzp_order_id` is NULL or wrong, the signature built in `validation.php:74` would use a stale session value.

---

## Step 5: Check Webhook Delivery

In Razorpay Dashboard → Developers → Webhooks → View attempts:
1. Is `order.paid` event being sent?
2. What HTTP response did PrestaShop return?
   - `200` = processed OK
   - `409` = wait time not elapsed (will retry)
   - `400` = signature failed (check RAZORPAY_WEBHOOK_SECRET matches dashboard)

Check wait time status:
```sql
SELECT cart_id, rzp_order_id, webhook_count, webhook_first_notified_at,
       order_placed, by_webhook, by_frontend,
       (UNIX_TIMESTAMP() - webhook_first_notified_at) AS seconds_since_first
FROM razorpay_sales_order
WHERE cart_id = $CART_ID;
```

If `seconds_since_first < 300` and `order_placed = 0`, webhook is still within wait time.

---

## Step 6: Verify API Credentials

Check that `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET` in `ps_configuration` match the Razorpay dashboard:

```sql
SELECT name, value FROM ps_configuration
WHERE name IN ('RAZORPAY_KEY_ID', 'RAZORPAY_PAYMENT_ACTION', 'ENABLE_RAZORPAY_WEBHOOK', 'RAZORPAY_WEBHOOK_WAIT_TIME');
```

---

## Step 7: Resolution Matrix

| Symptom | Likely Cause | Fix |
|---|---|---|
| No row in `razorpay_sales_order` | `order.php` failed (currency blocked, API error) | Check logs for "Order creation failed", verify credentials |
| Row exists, `order_placed=0`, no webhook | Webhook not configured or not received | Check `ENABLE_RAZORPAY_WEBHOOK`, test webhook URL manually |
| Row exists, `webhook_count>0`, `order_placed=0` | Wait time not elapsed yet | Wait up to 5 minutes; check `webhook_first_notified_at` |
| Row exists, `order_placed=0`, webhook_count>0 but stale | Webhook secret mismatch | Regenerate webhook secret in admin settings |
| Signature error in logs | Session loss / in-app browser | Check `rzp_order_id` fallback from DB works; test in real browser |
| `order_placed=1` but customer says no order | PS order creation failed silently | Check `ps_orders` for cart_id; check PS error logs |
| Double order created | Race condition bug | Check `order_id` unique constraint; should not happen in v2.5.8+ |

---

## Step 8: Emergency Manual Order Completion

If payment is confirmed in Razorpay dashboard but no PS order exists:

1. Get payment details from Razorpay dashboard
2. In PS Admin → Orders → Add new order for the customer
3. Or use PS Admin → Customer → View their cart → "Convert to order"
4. Set payment method to `razorpay.manual`
5. Note the Razorpay payment ID in the order notes
6. Manually update Razorpay payment notes via dashboard
