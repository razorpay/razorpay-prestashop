# razorpay_sales_order — Table Schema & Lifecycle

## Full DDL

```sql
CREATE TABLE IF NOT EXISTS `razorpay_sales_order` (
  `entity_id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id`                   int(11)          DEFAULT NULL,
  `order_id`                  int(11)          DEFAULT NULL,
  `rzp_order_id`              varchar(25)      DEFAULT NULL,
  `rzp_payment_id`            varchar(25)      DEFAULT NULL,
  `amount_paid`               decimal(20,2)    NOT NULL,
  `by_webhook`                tinyint(1)       NOT NULL DEFAULT '0',
  `by_frontend`               tinyint(1)       NOT NULL DEFAULT '0',
  `webhook_count`             smallint(6)      NOT NULL DEFAULT '0',
  `order_placed`              tinyint(1)       NOT NULL DEFAULT '0',
  `webhook_first_notified_at` bigint(20)       DEFAULT NULL,
  PRIMARY KEY (`entity_id`),
  UNIQUE KEY `cart_id` (`cart_id`, `rzp_payment_id`),
  UNIQUE KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

Source: `razorpay.php:install()`

---

## UNIQUE KEY Constraints

| Key name | Columns | What it prevents |
|----------|---------|-----------------|
| `cart_id` | `(cart_id, rzp_payment_id)` | Same payment being recorded twice for the same cart. Note: `rzp_payment_id` is NULL at INSERT time, so multiple partial rows per cart are technically possible until the payment_id is written. |
| `order_id` | `(order_id)` | Two rows claiming the same PS order — prevents double-order creation surfacing as a DB error. |

---

## Column Semantics

| Column | Type | Default | Meaning | Values |
|--------|------|---------|---------|--------|
| `entity_id` | int UNSIGNED AI | — | Surrogate PK | Auto |
| `cart_id` | int | NULL | PS cart this row tracks | PS `Cart::id`; set at INSERT |
| `order_id` | int | NULL | PS order created from this cart | NULL until order is placed; set to `$this->module->currentOrder` by frontend or `$this->razorpay->currentOrder` by webhook |
| `rzp_order_id` | varchar(25) | NULL | Razorpay order ID (`order_XXXX`) | Set at INSERT; re-set on retry via UPDATE in `order.php:postProcess()` |
| `rzp_payment_id` | varchar(25) | NULL | Razorpay payment ID (`pay_XXXX`) | NULL until payment captured; written by both validation.php and webhook |
| `amount_paid` | decimal(20,2) | NOT NULL | Actual rupee amount (not paise) | `0.00` at INSERT; set to `payment->amount / 100` on success |
| `by_webhook` | tinyint(1) | 0 | 1 when the webhook path created the PS order | 0 or 1 |
| `by_frontend` | tinyint(1) | 0 | 1 when the frontend validation path created the PS order | 0 or 1 |
| `webhook_count` | smallint(6) | 0 | Number of `order.paid` webhook deliveries received | Incremented on every webhook call; used to detect first delivery |
| `order_placed` | tinyint(1) | 0 | Gate flag: 1 = PS order exists, stop all further processing | 0 until an order is committed; set to 1 atomically with `order_id` |
| `webhook_first_notified_at` | bigint(20) | NULL | Unix timestamp of first webhook delivery | NULL until first webhook; then frozen for the wait-window calculation |

---

## Write Operations

### INSERT — Initial row creation

**Where:** `order.php:postProcess()`

**When:** Razorpay order ID successfully received from API and no existing row for this `cart_id`.

```sql
INSERT INTO `razorpay_sales_order` (`cart_id`, `rzp_order_id`, `amount_paid`)
VALUES ($cartId, '$rzpOrderId', 0.00)
```

Columns written: `cart_id`, `rzp_order_id`, `amount_paid=0.00`
Columns left at default: `order_id=NULL`, `rzp_payment_id=NULL`, `by_webhook=0`, `by_frontend=0`, `webhook_count=0`, `order_placed=0`, `webhook_first_notified_at=NULL`

### UPDATE — Rzp order ID refresh on retry

**Where:** `order.php:postProcess()`

**When:** Row already exists for `cart_id` (customer retried checkout — new Rzp order created).

```sql
UPDATE `razorpay_sales_order`
SET `cart_id` = $cartId, `rzp_order_id` = '$rzpOrderId'
WHERE `entity_id` = $order_sales_id
```

### UPDATE — Frontend order completion

**Where:** `validation.php:postProcess()`

**When:** Signature verified, PS order created via `validateOrder()`.

```sql
UPDATE `razorpay_sales_order`
SET `rzp_payment_id` = '$paymentId',
    `amount_paid`    = $amount_paid,
    `order_id`       = $this->module->currentOrder,
    `by_frontend`    = 1,
    `order_placed`   = 1
WHERE `cart_id` = $cart->id AND `entity_id` = $order_sales_id
```

### UPDATE — Webhook first-notification timestamp + count

**Where:** `razorpay-webhook.php:orderPaid()`

**When:** First webhook delivery for this row (`webhook_count < 1`). Sets the wait-window start time.

```sql
UPDATE `razorpay_sales_order`
SET `webhook_first_notified_at` = $firstNotifiedTime,
    `rzp_payment_id`            = '$razorpayPaymentId',
    `webhook_count`             = ($webhook_count + 1)
WHERE `entity_id` = $salesOrderData['entity_id']
```

### UPDATE — Subsequent webhook count increment

**Where:** `razorpay-webhook.php:orderPaid()`

**When:** Webhook fires again after the first delivery (`webhook_count >= 1`). Does NOT touch `webhook_first_notified_at`.

```sql
UPDATE `razorpay_sales_order`
SET `webhook_count` = ($webhook_count + 1)
WHERE `entity_id` = $salesOrderData['entity_id']
```

### UPDATE — Webhook order completion

**Where:** `razorpay-webhook.php:orderPaid()`

**When:** `webhook_count >= 1` AND `(time() - webhook_first_notified_at) >= RAZORPAY_WEBHOOK_WAIT_TIME` AND no PS order exists yet.

```sql
UPDATE `razorpay_sales_order`
SET `rzp_payment_id` = '$razorpayPaymentId',
    `amount_paid`    = $amount_paid,
    `order_id`       = $this->razorpay->currentOrder,
    `by_webhook`     = 1,
    `order_placed`   = 1
WHERE `cart_id` = $cart->id AND `entity_id` = $salesOrderId
```

---

## Read Operations

### SELECT — Check for existing row before INSERT/UPDATE

**Where:** `order.php:postProcess()`

```sql
SELECT `entity_id` FROM `razorpay_sales_order` WHERE `cart_id` = $cartId
```

Purpose: Decides INSERT vs UPDATE when customer opens checkout.

### SELECT — Fallback rzp_order_id lookup (session lost)

**Where:** `validation.php:postProcess()`

**When:** `$_SESSION['rzp_order_id']` is not set (e.g., server restart between order creation and payment).

```sql
SELECT `rzp_order_id` FROM `razorpay_sales_order` WHERE `cart_id` = $_REQUEST['cart_id']
```

### SELECT — Locate row for frontend update

**Where:** `validation.php:postProcess()`

```sql
SELECT `entity_id` FROM `razorpay_sales_order`
WHERE `cart_id` = $cart->id AND `rzp_order_id` = '$_SESSION[rzp_order_id]'
```

### SELECT — Full row read for webhook processing

**Where:** `razorpay-webhook.php:orderPaid()`

```sql
SELECT `entity_id`, `order_placed`, `webhook_count`, `order_id`, `webhook_first_notified_at`
FROM `razorpay_sales_order`
WHERE `cart_id` = $cartId AND `rzp_order_id` = '$rzpOrderId'
```

Purpose: Gate check — if `order_placed=1`, exit immediately. If row missing, proceed to create order without the wait-window logic.

### SELECT — Locate row for webhook update after order creation

**Where:** `razorpay-webhook.php:orderPaid()`

```sql
SELECT `entity_id` FROM `razorpay_sales_order`
WHERE `cart_id` = $cart->id AND `rzp_order_id` = '$rzpOrderId'
```

---

## Row Lifecycle

```
[1] Customer clicks Pay
    order.php:postProcess()
      → INSERT: cart_id, rzp_order_id, amount_paid=0.00
        (order_id=NULL, by_frontend=0, by_webhook=0, order_placed=0, webhook_count=0)

[2a] FRONTEND PATH — Customer completes payment in Razorpay modal
     validation.php:postProcess()
       → UPDATE: rzp_payment_id, amount_paid, order_id, by_frontend=1, order_placed=1

[2b] WEBHOOK PATH — order.paid event arrives (parallel or fallback)
     razorpay-webhook.php:orderPaid()
       First call:
         → reads order_placed — if 1, EXIT (frontend already won)
         → UPDATE: webhook_first_notified_at=now, rzp_payment_id, webhook_count=1
         → returns 409 (too early)
       Subsequent calls within wait window:
         → UPDATE: webhook_count += 1
         → returns 409
       After wait window expires AND order_placed still 0:
         → validates PS order
         → UPDATE: rzp_payment_id, amount_paid, order_id, by_webhook=1, order_placed=1

[3] FINAL STATE (either path)
    order_placed=1, order_id=<PS order id>, rzp_payment_id=<pay_XXXX>, amount_paid>0
    by_frontend=1 OR by_webhook=1 (exactly one)
```

---

## Race Condition: Frontend vs Webhook

**Scenario:** Customer completes payment in the browser AND Razorpay's webhook fires before the browser redirect returns.

**Resolution mechanism:**

1. `order_placed` is the single gate bit. Both paths set it to `1` in the same UPDATE that writes `order_id`.
2. The webhook is intentionally delayed: on the first delivery it records `webhook_first_notified_at` and returns HTTP 409, giving the frontend `RAZORPAY_WEBHOOK_WAIT_TIME` seconds (default 300) to complete.
3. If the frontend completes within the wait window, `order_placed` becomes `1`. All subsequent webhook calls hit the `if ($salesOrderData['order_placed'])` exit at the top of `razorpay-webhook.php:orderPaid()`.
4. If the frontend never completes (browser crash, network drop), the webhook proceeds after the wait window.

**Non-obvious gap:** There is no database-level locking (no `SELECT FOR UPDATE` or transaction wrapping the read-then-write). If the frontend and a webhook call arrive simultaneously after the wait window, both could read `order_placed=0` and both call `validateOrder()`. The `UNIQUE KEY order_id (order_id)` on the table would then fail on the second UPDATE, but the PS order would already have been created twice. The `by_frontend` / `by_webhook` flags exist for post-hoc auditing of which path "won", not as a lock.

**Human verification needed:** Confirm whether `validateOrder()` itself has idempotency protection at the PS layer that prevents double order creation in this window.
