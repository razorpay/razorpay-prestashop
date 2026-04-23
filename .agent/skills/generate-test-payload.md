# Skill: Generate Test Webhook Payload

Use this to generate valid Razorpay webhook payloads for local testing.

---

## Generate Signed Payload (Shell)

```bash
#!/bin/bash

WEBHOOK_SECRET="YOUR_RAZORPAY_WEBHOOK_SECRET"  # from ps_configuration
WEBHOOK_URL="https://yourshop.com/razorpay/webhook"
CART_ID="42"
RZP_ORDER_ID="order_test123456789"
RZP_PAYMENT_ID="pay_test123456789"

# order.paid payload
PAYLOAD=$(cat <<EOF
{
  "entity": "event",
  "account_id": "acc_test123",
  "event": "order.paid",
  "contains": ["payment", "order"],
  "payload": {
    "payment": {
      "entity": {
        "id": "${RZP_PAYMENT_ID}",
        "entity": "payment",
        "amount": 10000,
        "currency": "INR",
        "status": "captured",
        "order_id": "${RZP_ORDER_ID}",
        "method": "card",
        "captured": true,
        "description": "PrestaShop Payment",
        "notes": {
          "prestashop_cart_id": "${CART_ID}",
          "prestashop_order_id": ""
        }
      }
    },
    "order": {
      "entity": {
        "id": "${RZP_ORDER_ID}",
        "entity": "order",
        "amount": 10000,
        "amount_paid": 10000,
        "amount_due": 0,
        "currency": "INR",
        "receipt": "${CART_ID}",
        "status": "paid"
      }
    }
  }
}
EOF
)

# Compute HMAC-SHA256 signature
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | awk '{print $2}')

echo "Payload:"
echo "$PAYLOAD"
echo ""
echo "Signature: $SIGNATURE"
echo ""
echo "curl command:"
echo "curl -X POST $WEBHOOK_URL \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -H 'X-Razorpay-Signature: $SIGNATURE' \\"
echo "  -d '$PAYLOAD'"
```

---

## Generate Payload (PHP)

```php
<?php
$webhookSecret = Configuration::get('RAZORPAY_WEBHOOK_SECRET');
$cartId        = 42;
$rzpOrderId    = 'order_test123456789';
$rzpPaymentId  = 'pay_test123456789';

$payload = json_encode([
    'entity'     => 'event',
    'account_id' => 'acc_test123',
    'event'      => 'order.paid',
    'contains'   => ['payment', 'order'],
    'payload'    => [
        'payment' => [
            'entity' => [
                'id'       => $rzpPaymentId,
                'entity'   => 'payment',
                'amount'   => 10000,
                'currency' => 'INR',
                'status'   => 'captured',
                'order_id' => $rzpOrderId,
                'method'   => 'card',
                'captured' => true,
                'notes'    => [
                    'prestashop_cart_id'  => (string)$cartId,
                    'prestashop_order_id' => '',
                ],
            ],
        ],
        'order' => [
            'entity' => [
                'id'          => $rzpOrderId,
                'entity'      => 'order',
                'amount'      => 10000,
                'amount_paid' => 10000,
                'amount_due'  => 0,
                'currency'    => 'INR',
                'receipt'     => (string)$cartId,
                'status'      => 'paid',
            ],
        ],
    ],
]);

$signature = hash_hmac('sha256', $payload, $webhookSecret);

$ch = curl_init(Configuration::get('webhookUrl'));
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Razorpay-Signature: ' . $signature,
    ],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP $httpCode: $response\n";
```

---

## Event Payloads Reference

### payment.authorized
```json
{
  "event": "payment.authorized",
  "payload": {
    "payment": {
      "entity": {
        "id": "pay_xxx",
        "amount": 10000,
        "currency": "INR",
        "status": "authorized",
        "order_id": "order_xxx",
        "method": "upi",
        "notes": {
          "prestashop_cart_id": "42"
        }
      }
    }
  }
}
```

### payment.failed
```json
{
  "event": "payment.failed",
  "payload": {
    "payment": {
      "entity": {
        "id": "pay_xxx",
        "amount": 10000,
        "status": "failed",
        "error_code": "BAD_REQUEST_ERROR",
        "error_description": "Card declined",
        "notes": {
          "prestashop_cart_id": "42"
        }
      }
    }
  }
}
```

---

## Expected HTTP Responses

| Scenario | Expected Response |
|---|---|
| Valid signature + order.paid (first webhook) | HTTP 409 (wait time not elapsed) |
| Valid signature + order.paid (after 300s) | HTTP 200 (order created) |
| Valid signature + order.paid (order exists) | HTTP 200 (exit immediately) |
| Invalid signature | HTTP 400 |
| ENABLE_RAZORPAY_WEBHOOK = 'off' | Silent return (no response body) |
