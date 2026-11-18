# Skill: Refund Investigation

Use this runbook when investigating a refund request or debugging a failed refund.

---

## Important: No Automated Refund in This Module

As of v2.5.8, this module does **not** implement automated refunds via Razorpay API. Refunds must be initiated from the **Razorpay Dashboard** directly.

The module only:
1. Creates payments via Razorpay
2. Stores the Razorpay Payment ID in the PS order (`transaction_id`)
3. Updates payment notes with `prestashop_order_id` and `prestashop_cart_id`

---

## Step 1: Locate the Razorpay Payment ID

### From PrestaShop Admin
```
Admin → Orders → Find Order → Payment section
Look for: "transaction_id" field
Format: pay_xxxxxxxxxx
```

### From Database
```sql
SELECT op.id_order, op.transaction_id, op.payment, op.amount, op.date_add
FROM ps_order_payment op
INNER JOIN ps_orders o ON o.id_cart = op.id_order
WHERE o.id_order = $ORDER_ID;
-- OR
SELECT * FROM ps_order_payment
WHERE transaction_id LIKE 'pay_%'
AND id_order = $ORDER_ID;
```

### From razorpay_sales_order
```sql
SELECT rzp_payment_id, amount_paid, by_frontend, by_webhook
FROM razorpay_sales_order
WHERE order_id = $PS_ORDER_ID;
```

---

## Step 2: Check Refund Status on Razorpay

1. Log in to [Razorpay Dashboard](https://dashboard.razorpay.com)
2. Navigate to **Transactions → Payments**
3. Search for the Payment ID (`pay_xxxxxxxxxx`)
4. Check the **Refunds** tab on the payment detail page

### Via Razorpay API (PHP)
```php
require_once 'razorpay/razorpay-sdk/Razorpay.php';
use Razorpay\Api\Api;

$api = new Api('rzp_live_xxx', 'secret_xxx');
$payment = $api->payment->fetch('pay_xxxxxxxxxx');

// List refunds for this payment
$refunds = $api->refund->all(['payment_id' => 'pay_xxxxxxxxxx']);
foreach ($refunds['items'] as $refund) {
    echo $refund->id . ' — ' . ($refund->amount / 100) . ' — ' . $refund->status;
}
```

---

## Step 3: Initiate a Refund (Manual)

### Via Razorpay Dashboard
1. Find the payment in Razorpay Dashboard
2. Click **Issue Refund**
3. Enter amount (partial or full)
4. Add reason/notes
5. Click **Confirm**

### Via Razorpay API (PHP)
```php
$payment = $api->payment->fetch('pay_xxxxxxxxxx');
$refund = $payment->refund([
    'amount' => 5000,   // partial refund in paise; omit for full refund
    'notes'  => [
        'reason'            => 'Customer request',
        'prestashop_order_id' => $psOrderId,
    ]
]);
echo $refund->id;   // rfnd_xxxxxxxxxx
echo $refund->status;  // 'processed' or 'pending'
```

---

## Step 4: Update PrestaShop Order Status

After initiating refund in Razorpay, update PS order manually:
1. **Admin → Orders → Find Order**
2. Add an order message noting the refund and Razorpay refund ID
3. Change order status to **Refunded** or **Partially Refunded** as appropriate

There is no automated sync between Razorpay refund status and PS order status in this module.

---

## Step 5: Refund Reconciliation

To verify all refunds match:

```sql
-- Orders with Razorpay payment method
SELECT o.id_order, o.reference, o.total_paid,
       op.transaction_id as razorpay_payment_id,
       os.name as order_status
FROM ps_orders o
INNER JOIN ps_order_payment op ON op.id_order = o.id_cart
INNER JOIN ps_order_history oh ON oh.id_order = o.id_order
INNER JOIN ps_order_state os ON os.id_order_state = oh.id_order_state
WHERE op.payment LIKE 'razorpay%'
AND os.id_order_state = (SELECT id_order_state FROM ps_order_state WHERE name LIKE '%refund%' LIMIT 1)
ORDER BY o.date_add DESC;
```

---

## Common Refund Issues

| Issue | Cause | Resolution |
|---|---|---|
| Refund not processed | Payment still in `authorized` state (not captured) | Capture the payment first, then refund |
| Partial refund failed | Amount exceeds original payment | Check `payment->amount` before refunding |
| Refund shows in Razorpay but not PS | No automated sync | Manually update PS order status |
| `KWD`/`OMR`/`BHD` payment refund fails | These currencies are blocked | Contact Razorpay support |
| Refund available period expired | >6 months from payment date | Contact Razorpay support for manual refund |
