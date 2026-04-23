# Webhook Processing — Flow Map

Entry point: `webhook.php:postProcess()` instantiates `RZP_Webhook` and calls `process()`.
All branching below is inside `razorpay-webhook.php`.

---

## Flow 1: order.paid — Happy Path (most traffic)

| Step | Code ref | What happens |
|------|----------|--------------|
| 1. Read raw body | `razorpay-webhook.php:process()` line 43 | `file_get_contents('php://input')` — raw bytes preserved for HMAC |
| 2. JSON decode | `razorpay-webhook.php:process()` line 45 | `json_decode($post, true)` |
| 3. Guard: webhook enabled + event present | `razorpay-webhook.php:process()` line 54 | `ENABLE_RAZORPAY_WEBHOOK == 'on'` AND `$data['event']` not empty |
| 4. Signature verify | `razorpay-webhook.php:process()` line 67 | `$this->api->utility->verifyWebhookSignature(...)` using `RAZORPAY_WEBHOOK_SECRET` |
| 5. Route to handler | `razorpay-webhook.php:process()` line 93 | `case Razorpay::ORDER_PAID` dispatches to `orderPaid()` |
| 6. Lookup bridge table | `razorpay-webhook.php:orderPaid()` line 138 | SELECT by `cart_id` + `rzp_order_id` |
| 7. **DP1** — order_placed check | `razorpay-webhook.php:orderPaid()` line 145 | If `order_placed=1`, log and exit (see Flow 3) |
| 8. Record first notification | `razorpay-webhook.php:orderPaid()` line 155 | If `webhook_count < 1`, stamp `webhook_first_notified_at = time()` |
| 9. Increment webhook_count | `razorpay-webhook.php:orderPaid()` line 167 | Always incremented on every delivery |
| 10. **DP2** — wait time gate | `razorpay-webhook.php:orderPaid()` line 177 | If `time() - first_notified < wait_time`, return 409 (see Flow 4) |
| 11. Check PS order exists | `razorpay-webhook.php:orderPaid()` line 192 | `Order::getOrderByCartId($cart->id)` |
| 12a. PS order exists (frontend won) | `razorpay-webhook.php:orderPaid()` line 202 | If `count(payments) >= 1`, exit silently. Else call `setCurrentState(PS_OS_PAYMENT)` |
| 12b. No PS order (webhook wins) | `razorpay-webhook.php:orderPaid()` line 264 | Call `validateOrder(...)` to create the PS order |
| 13. Update bridge table | `razorpay-webhook.php:orderPaid()` line 293 | SET `by_webhook=1, order_placed=1, order_id, rzp_payment_id, amount_paid` |
| 14. Update Razorpay notes | `razorpay-webhook.php:orderPaid()` line 278 | `$this->api->payment->fetch()->edit(notes)` — patches `prestashop_order_id` back to Razorpay |

**Decision Points:**
- **DP1: order_placed check** — Was order already created by any actor? If yes, log "Quote order is inactive" and exit cleanly. Why: prevents double-order creation; the flag is set by whichever actor (frontend or webhook) runs `validateOrder()` first.
- **DP2: wait time gate** — Has enough time passed since the first webhook arrived? If not, return 409. Why: gives the frontend browser callback time to complete `validateOrder()` before the webhook does (see decisions.md D2).

---

## Flow 2: Duplicate Webhook (same cart_id + rzp_payment_id)

| Flow | Trigger | Key ref | Outcome |
|------|---------|---------|---------|
| Duplicate delivery, order already placed | Razorpay retries after 409 and `order_placed=1` is now set | `razorpay-webhook.php:orderPaid()` line 145 | Logs "Quote order is inactive", calls `exit` — HTTP 200 returned to Razorpay, no error |
| Duplicate delivery, order not placed | Razorpay retries within wait window | `razorpay-webhook.php:orderPaid()` line 177 | 409 returned, `webhook_count` incremented again |

**Note:** There is no UNIQUE KEY on `(cart_id, rzp_payment_id)` at INSERT time from the webhook path — the bridge row is created by `order.php` (INSERT) before the webhook arrives. The webhook only UPDATEs. The UNIQUE KEY `cart_id` (`cart_id`, `rzp_payment_id`) prevents two rows for the same payment, but the duplicate-webhook guard is purely the `order_placed` flag check, not a DB constraint violation.

---

## Flow 3: Frontend Already Placed Order (order_placed=1 in DB)

| Flow | Trigger | Key ref | Outcome |
|------|---------|---------|---------|
| Webhook arrives after frontend completed | `order_placed=1` set by `validation.php` | `razorpay-webhook.php:orderPaid()` line 145-149 | Log at level 1, `exit` — no order created, no notes updated |

This is the normal race resolution: frontend won, webhook detects it via `order_placed` flag and exits gracefully. No HTTP error is returned; Razorpay receives 200.

---

## Flow 4: Webhook Wait Time Throttle (too early)

| Flow | Trigger | Key ref | Outcome |
|------|---------|---------|---------|
| First webhook arrives before frontend finishes | `time() - webhook_first_notified_at < wait_time` (default 300s) | `razorpay-webhook.php:orderPaid()` line 177-183 | `webhook_count` incremented, `HTTP 409 Conflict` returned, `exit` |

Razorpay's retry policy will re-deliver. On each retry, `webhook_count` keeps incrementing. Once the wait time elapses AND the frontend has not yet placed the order, the next delivery proceeds to `validateOrder()`.

---

## Flow 5: payment.authorized Event

| Flow | Trigger | Key ref | Outcome |
|------|---------|---------|---------|
| payment.authorized received | `case Razorpay::PAYMENT_AUTHORIZED` | `razorpay-webhook.php:paymentAuthorized()` line 119 | `return` immediately — no-op stub |

---

## Flow 6: payment.failed Event

| Flow | Trigger | Key ref | Outcome |
|------|---------|---------|---------|
| payment.failed received | `case Razorpay::PAYMENT_FAILED` | `razorpay-webhook.php:paymentFailed()` line 110 | `return` immediately — no-op stub |

---

## Flow 7: Invalid Signature

| Flow | Trigger | Key ref | Outcome |
|------|---------|---------|---------|
| Signature mismatch | `verifyWebhookSignature` throws `SignatureVerificationError` | `razorpay-webhook.php:process()` line 71-83 | Caught in the try/catch: log at level 4 with event `razorpay.wc.signature.verify_failed`, set `HTTP 400`, `exit` |
| Outer `BadRequestError` | Any `BadRequestError` escaping `process()` | `webhook.php:postProcess()` line 18-24 | Caught in controller: log at level 4, `exit` |

**Note on blank secret:** If `RAZORPAY_WEBHOOK_SECRET` is empty, the entire `try/verifyWebhookSignature` block is skipped (line 62 guard: `empty($razorpayWebhookSecret) === false`). Verification never runs. The event is dispatched unverified to the handler. See constraints.md for the misconfiguration risk.

---

## Flow 8: Malformed / Non-JSON Payload

| Flow | Trigger | Key ref | Outcome |
|------|---------|---------|---------|
| `json_last_error() !== 0` | Non-JSON body sent to endpoint | `razorpay-webhook.php:process()` line 47-50 | `return` from `process()` with no response body — controller returns HTTP 200 silently |
