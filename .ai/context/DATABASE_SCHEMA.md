# Database Schema — Razorpay PrestaShop

## Table: `razorpay_sales_order`

Created on module install, dropped on uninstall.

### DDL

```sql
CREATE TABLE IF NOT EXISTS `razorpay_sales_order` (
  `entity_id`                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity_id',
  `cart_id`                  INT(11) DEFAULT NULL COMMENT 'cart_id',
  `order_id`                 INT(11) DEFAULT NULL COMMENT 'Order_id',
  `rzp_order_id`             VARCHAR(25) DEFAULT NULL COMMENT 'Rzp_order_id',
  `rzp_payment_id`           VARCHAR(25) DEFAULT NULL COMMENT 'Rzp_payment_id',
  `amount_paid`              DECIMAL(20,2) NOT NULL,
  `by_webhook`               TINYINT(1) NOT NULL DEFAULT '0' COMMENT 'By_webhook',
  `by_frontend`              TINYINT(1) NOT NULL DEFAULT '0' COMMENT 'By_frontend',
  `webhook_count`            SMALLINT(6) NOT NULL DEFAULT '0' COMMENT 'Webhook_count',
  `order_placed`             TINYINT(1) NOT NULL DEFAULT '0' COMMENT 'Order_placed',
  `webhook_first_notified_at` BIGINT(20) DEFAULT NULL COMMENT 'Webhook_first_notified_at',
  PRIMARY KEY (`entity_id`),
  UNIQUE KEY `cart_id` (`cart_id`, `rzp_payment_id`),
  UNIQUE KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='razorpay_sales_order';
```

### Column Reference

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `entity_id` | INT UNSIGNED | NO | AUTO_INCREMENT | Internal primary key |
| `cart_id` | INT(11) | YES | NULL | PrestaShop `id_cart` from `ps_cart` |
| `order_id` | INT(11) | YES | NULL | PrestaShop `id_order` from `ps_orders` — NULL until order is created |
| `rzp_order_id` | VARCHAR(25) | YES | NULL | Razorpay Order ID (format: `order_xxxxxxxxxx`) |
| `rzp_payment_id` | VARCHAR(25) | YES | NULL | Razorpay Payment ID (format: `pay_xxxxxxxxxx`) — NULL until payment completes |
| `amount_paid` | DECIMAL(20,2) | NO | — | Amount in currency units (e.g., `100.50` for ₹100.50) |
| `by_webhook` | TINYINT(1) | NO | 0 | `1` if PrestaShop order was created by webhook path |
| `by_frontend` | TINYINT(1) | NO | 0 | `1` if PrestaShop order was created by frontend validation path |
| `webhook_count` | SMALLINT(6) | NO | 0 | Number of `order.paid` webhook attempts received for this cart |
| `order_placed` | TINYINT(1) | NO | 0 | `1` if PrestaShop order has been successfully created (by either path) |
| `webhook_first_notified_at` | BIGINT(20) | YES | NULL | Unix timestamp (seconds) when first webhook arrived |

### Unique Constraints

| Constraint Name | Columns | Purpose |
|---|---|---|
| `cart_id` | `(cart_id, rzp_payment_id)` | Prevents duplicate entries for the same cart+payment combination |
| `order_id` | `(order_id)` | Ensures each PrestaShop order is recorded only once |

---

## Row Lifecycle

### Row Created (order.php — when customer clicks Pay)
```sql
INSERT INTO razorpay_sales_order (cart_id, rzp_order_id, amount_paid)
VALUES ($cartId, '$rzpOrderId', 0.00)
```
State: `order_placed=0`, `by_frontend=0`, `by_webhook=0`, `order_id=NULL`, `rzp_payment_id=NULL`

### Row Updated (validation.php — frontend payment success)
```sql
UPDATE razorpay_sales_order
SET rzp_payment_id='$paymentId',
    amount_paid=$amountPaid,
    order_id=$psOrderId,
    by_frontend=1,
    order_placed=1
WHERE cart_id=$cartId AND entity_id=$entityId
```

### Row Updated (razorpay-webhook.php — first webhook attempt)
```sql
UPDATE razorpay_sales_order
SET webhook_first_notified_at=$timestamp,
    rzp_payment_id='$razorpayPaymentId',
    webhook_count=1
WHERE entity_id=$entityId
```

### Row Updated (razorpay-webhook.php — subsequent webhook attempts)
```sql
UPDATE razorpay_sales_order
SET webhook_count=$newCount
WHERE entity_id=$entityId
```

### Row Updated (razorpay-webhook.php — webhook creates order)
```sql
UPDATE razorpay_sales_order
SET rzp_payment_id='$razorpayPaymentId',
    amount_paid=$amountPaid,
    order_id=$psOrderId,
    by_webhook=1,
    order_placed=1
WHERE cart_id=$cartId AND entity_id=$salesOrderId
```

---

## State Machine

```
[Row created by order.php]
        |
        | Customer pays successfully
        |
   +----+----+
   |         |
Frontend   Webhook
 succeeds  arrives
   |         |
   v         v
by_frontend=1  webhook_count++
order_placed=1  webhook_first_notified_at set
               |
               | wait time elapsed (default 300s)
               | AND order_placed still 0
               v
          by_webhook=1
          order_placed=1
```

---

## Key Queries Used in Code

### Check if entry exists for cart
```sql
-- order.php: before insert
SELECT entity_id FROM razorpay_sales_order WHERE cart_id = $cartId

-- webhook.php: before insert
SELECT entity_id, order_placed, webhook_count, order_id, webhook_first_notified_at
FROM razorpay_sales_order
WHERE cart_id = $cartId AND rzp_order_id = '$rzpOrderId'
```

### Fallback order_id lookup (if session lost)
```sql
-- validation.php: when $_SESSION['rzp_order_id'] is not set
SELECT rzp_order_id FROM razorpay_sales_order WHERE cart_id = $cartId
```

### Get entity_id for update (validation.php)
```sql
SELECT entity_id FROM razorpay_sales_order
WHERE cart_id = $cartId AND rzp_order_id = '$rzpOrderId'
```

---

## PrestaShop Configuration Table

Module settings are stored in PrestaShop's `ps_configuration` table (not a custom table).

| Key | Example Value |
|---|---|
| `RAZORPAY_KEY_ID` | `rzp_live_xxxxxxxxxxxxxx` |
| `RAZORPAY_KEY_SECRET` | `xxxxxxxxxxxxxxxxxxxxxxxx` |
| `RAZORPAY_PAYMENT_ACTION` | `capture` |
| `ENABLE_RAZORPAY_WEBHOOK` | `on` |
| `RAZORPAY_WEBHOOK_EVENTS` | `order.paid` |
| `RAZORPAY_WEBHOOK_SECRET` | `A3b#xY9...` (20 chars) |
| `RAZORPAY_WEBHOOK_WAIT_TIME` | `300` |
| `RAZORPAY_WEBHOOK_LAST_VERIFY` | `1745000000` (unix timestamp) |

Read: `Configuration::get('KEY')`
Write: `Configuration::updateValue('KEY', $value)`
Delete: `Configuration::deleteByName('KEY')`
