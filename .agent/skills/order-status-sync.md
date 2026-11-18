# Skill: Order Status Sync

Use this runbook to investigate and fix cases where a PrestaShop order status does not match the actual Razorpay payment status.

---

## Common Status Mismatch Scenarios

1. Order stuck in "Payment Pending" but Razorpay shows payment captured
2. Order in "Payment Accepted" but Razorpay shows payment failed/refunded
3. Order created by webhook but PS status shows wrong state

---

## Step 1: Get Current Order and Payment Status

### PrestaShop order status
```sql
SELECT o.id_order, o.reference, o.current_state,
       os.name as status_name,
       o.total_paid, o.date_add,
       op.transaction_id as rzp_payment_id
FROM ps_orders o
INNER JOIN ps_order_state_lang os ON os.id_order_state = o.current_state
                                  AND os.id_lang = 1
LEFT JOIN ps_order_payment op ON op.id_order = o.id_cart
WHERE o.id_order = $ORDER_ID;
```

### razorpay_sales_order state
```sql
SELECT * FROM razorpay_sales_order WHERE order_id = $ORDER_ID;
```

### PS Order state IDs
```sql
SELECT id_order_state, name FROM ps_order_state_lang WHERE id_lang = 1;
-- PS_OS_PAYMENT = id for "Payment accepted"
-- PS_OS_PENDING = id for "Awaiting payment"
```

---

## Step 2: Check Razorpay Payment Status

```php
require_once 'razorpay/razorpay-sdk/Razorpay.php';
use Razorpay\Api\Api;

$api = new Api(
    Configuration::get('RAZORPAY_KEY_ID'),
    Configuration::get('RAZORPAY_KEY_SECRET')
);

$payment = $api->payment->fetch('pay_xxxxxxxxxx');
echo "Status: " . $payment->status . "\n";
// Possible: created, authorized, captured, refunded, failed
echo "Amount: " . ($payment->amount / 100) . " " . $payment->currency . "\n";
echo "Captured: " . ($payment->captured ? 'yes' : 'no') . "\n";
echo "Order ID: " . $payment->order_id . "\n";
```

---

## Step 3: Fix Status Mismatch

### Case: Order "Pending" but payment captured in Razorpay

```php
$order = new Order($orderId);
$order->setCurrentState((int) Configuration::get('PS_OS_PAYMENT'));
// Adds an order history entry with the new state
```

Or via SQL (not recommended — bypasses PS hooks):
```sql
UPDATE ps_orders SET current_state = (SELECT id_order_state FROM ps_order_state WHERE paid = 1 LIMIT 1)
WHERE id_order = $ORDER_ID;

INSERT INTO ps_order_history (id_order, id_order_state, id_employee, date_add, id_lang)
VALUES ($ORDER_ID, $TARGET_STATE, 0, NOW(), 1);
```

### Case: `razorpay_sales_order` has no order_id but PS order exists

This can happen if the DB update in validation.php failed after `validateOrder()` succeeded.

```sql
-- Find the PS order for this cart
SELECT id_order FROM ps_orders WHERE id_cart = $CART_ID;

-- Update the tracking table
UPDATE razorpay_sales_order
SET order_id = $PS_ORDER_ID,
    order_placed = 1,
    by_frontend = 1
WHERE cart_id = $CART_ID;
```

---

## Step 4: Check for Duplicate Orders

```sql
-- Multiple orders for same cart?
SELECT id_order, date_add, current_state, total_paid
FROM ps_orders
WHERE id_cart = $CART_ID
ORDER BY date_add;
```

If duplicates exist:
1. Check which order has the payment (`ps_order_payment`)
2. Keep the correct order, cancel the duplicate via PS Admin
3. Issue refund if customer was charged twice

---

## Step 5: Verify Payment Notes on Razorpay

If `prestashop_order_id` is missing from Razorpay payment notes, update manually:

```php
$api->payment->fetch($rzpPaymentId)->edit([
    'notes' => [
        'prestashop_order_id' => $psOrderId,
        'prestashop_cart_id'  => $cartId,
    ]
]);
```

---

## Step 6: Audit All Orders for Status Issues

```sql
-- All Razorpay orders in last 30 days with their tracking status
SELECT
    o.id_order,
    o.id_cart,
    o.reference,
    o.current_state,
    o.total_paid,
    o.date_add,
    op.transaction_id as rzp_payment_id,
    rs.order_placed,
    rs.by_frontend,
    rs.by_webhook,
    rs.webhook_count
FROM ps_orders o
INNER JOIN ps_order_payment op ON op.id_order = o.id_cart
LEFT JOIN razorpay_sales_order rs ON rs.order_id = o.id_order
WHERE op.payment LIKE 'razorpay%'
AND o.date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY o.date_add DESC;
```

Red flags to look for:
- `order_placed = 0` — order tracked as not placed despite PS order existing
- `by_frontend = 0 AND by_webhook = 0` — neither path recorded success
- `webhook_count > 5` — excessive webhook retries (check wait time config)
