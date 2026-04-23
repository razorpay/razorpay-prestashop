# Skill: Add a New Webhook Event Handler

Use this runbook to add support for a new Razorpay webhook event.

---

## Available Razorpay Webhook Events

Common events you might add:
- `payment.captured` — Payment captured (after authorize-only)
- `payment.authorized` — Payment authorized (currently no-op in v2.5.8)
- `refund.created` — Refund initiated
- `refund.processed` — Refund completed
- `order.paid` — Already implemented

Full list: https://razorpay.com/docs/webhooks/

---

## Step-by-Step Implementation

### Step 1: Add Event Constant (`razorpay/razorpay.php`)

```php
// Add after existing event constants (around line 27)
const PAYMENT_CAPTURED   = 'payment.captured';
const REFUND_CREATED     = 'refund.created';
```

### Step 2: Add to Supported Events Array (`razorpay/razorpay.php`)

```php
protected $webhookSupportedEvents = [
    'payment.authorized',
    'payment.failed',
    'order.paid',
    'payment.captured',   // ADD THIS
    'refund.created',     // ADD THIS
];
```

### Step 3: Add to Default Events Array (`razorpay/razorpay.php`)

```php
protected $webhookEvents = [
    'payment.authorized' => true,
    'order.paid'         => true,
    'payment.captured'   => true,   // ADD THIS (or false to disable by default)
];
```

### Step 4: Add Switch Case (`razorpay/razorpay-webhook.php`)

In `RZP_Webhook::process()`, add to the switch statement (around line 87):

```php
switch ($data['event'])
{
    case Razorpay::PAYMENT_AUTHORIZED:
        return $this->paymentAuthorized($data);

    case Razorpay::PAYMENT_FAILED:
        return $this->paymentFailed($data);

    case Razorpay::ORDER_PAID:
        return $this->orderPaid($data);

    case Razorpay::PAYMENT_CAPTURED:        // ADD THIS
        return $this->paymentCaptured($data);

    case Razorpay::REFUND_CREATED:          // ADD THIS
        return $this->refundCreated($data);

    default:
        return;
}
```

### Step 5: Implement Handler Method (`razorpay/razorpay-webhook.php`)

Add after existing methods:

```php
/**
 * Handle payment.captured event
 * Called when an authorized payment is captured
 * @param array $data Webhook data
 */
protected function paymentCaptured(array $data)
{
    $paymentId = $data['payload']['payment']['entity']['id'];
    $cartId    = $data['payload']['payment']['entity']['notes']['prestashop_cart_id'] ?? null;

    Logger::addLog("Razorpay Webhook: Payment captured for payment_id: $paymentId, cart_id: $cartId", 1);

    // Example: Update order status to "Payment accepted"
    if ($cartId) {
        $orderId = Order::getOrderByCartId((int)$cartId);
        if ($orderId) {
            $order = new Order($orderId);
            // Optional: update order state
            // $order->setCurrentState((int) Configuration::get('PS_OS_PAYMENT'));
        }
    }
}

/**
 * Handle refund.created event
 * @param array $data Webhook data
 */
protected function refundCreated(array $data)
{
    $refundId  = $data['payload']['refund']['entity']['id'];
    $paymentId = $data['payload']['refund']['entity']['payment_id'];
    $amount    = $data['payload']['refund']['entity']['amount'] / 100;

    Logger::addLog("Razorpay Webhook: Refund $refundId created for payment $paymentId, amount: $amount", 1);

    // Add your business logic here
}
```

### Step 6: Update Admin UI (Optional)

If you want the event to appear as a checkbox in admin settings, update `admin.tpl` to include the new event in the events selector.

---

## Testing the New Handler

Use `.agent/skills/generate-test-payload.md` to generate a test webhook payload, then:

```bash
# Test with curl (replace with your webhook URL and secret)
PAYLOAD='{"event":"payment.captured","payload":{"payment":{"entity":{"id":"pay_test123","method":"card","amount":10000,"notes":{"prestashop_cart_id":"42"}}}}}'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "YOUR_WEBHOOK_SECRET" -hex | awk '{print $2}')

curl -X POST https://yourshop.com/razorpay/webhook \
  -H "Content-Type: application/json" \
  -H "X-Razorpay-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```

---

## Webhook Payload Structure Reference

```json
{
  "entity": "event",
  "account_id": "acc_xxx",
  "event": "order.paid",
  "contains": ["payment", "order"],
  "payload": {
    "payment": {
      "entity": {
        "id": "pay_xxx",
        "entity": "payment",
        "amount": 10000,
        "currency": "INR",
        "status": "captured",
        "order_id": "order_xxx",
        "method": "card",
        "notes": {
          "prestashop_cart_id": "42",
          "prestashop_order_id": ""
        }
      }
    },
    "order": {
      "entity": {
        "id": "order_xxx",
        "amount": 10000,
        "receipt": "42"
      }
    }
  }
}
```
