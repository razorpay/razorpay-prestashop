# Order Creation Flow Documentation

## Overview

A PrestaShop order is created in exactly one of two ways for each successful payment:

| Path | File | Trigger | Reliability |
|---|---|---|---|
| Frontend | `validation.php` | Customer browser returns from modal | Fast, but can fail |
| Webhook | `razorpay-webhook.php` | Razorpay server-to-server | Reliable, but delayed |

## Frontend Path

**Entry**: `POST /razorpay/validation?razorpay_payment_id=pay_xxx`

```
validation.php::postProcess()
1. Reconstruct cart from cart_id (URL param)
2. Validate cart has customer + addresses + module active
3. Fetch payment: $api->payment->fetch($paymentId)
4. Verify signature: $api->utility->verifyPaymentSignature([...])
5. $this->module->validateOrder(
       $cart->id,
       PS_OS_PAYMENT,
       $cart->getOrderTotal(true, Cart::BOTH),
       "razorpay.{$payment->method}",
       "Payment by Razorpay using $method",
       ['transaction_id' => $paymentId],
       NULL, false, $customer->secure_key
   )
6. UPDATE razorpay_sales_order SET by_frontend=1, order_placed=1, order_id=$psOrderId
7. Redirect to order-confirmation
```

## Webhook Path

**Entry**: `POST /razorpay/webhook` with `X-Razorpay-Signature` header

```
razorpay-webhook.php::orderPaid()
1. Extract cart_id from payload.payment.entity.notes.prestashop_cart_id
2. Verify order not already placed (order_placed flag)
3. Apply wait-time guard (return 409 if < 300s since first webhook)
4. Check for existing PS order: Order::getOrderByCartId($cartId)
5. If no order: $this->razorpay->validateOrder(
       $cart->id,
       PS_OS_PAYMENT,
       $cart->getOrderTotal(true, Cart::BOTH),
       "razorpay.$method",
       "Payment by Razorpay using $method",
       ['transaction_id' => $razorpayPaymentId],
       NULL, false, $customer->secure_key
   )
6. UPDATE razorpay_sales_order SET by_webhook=1, order_placed=1, order_id=$psOrderId
```

## PrestaShop validateOrder() Parameters

```php
$this->module->validateOrder(
    $id_cart,          // (int) PS cart ID
    $id_order_state,   // (int) PS_OS_PAYMENT — "Payment accepted" state
    $amount_paid,      // (float) cart total in shop currency
    $payment_method,   // (string) "razorpay.card", "razorpay.upi", etc.
    $message,          // (string) human-readable payment description
    $extra_vars,       // (array) ['transaction_id' => 'pay_xxx']
    $currency_special, // null (use shop default)
    $dont_touch_amount,// false
    $secure_key        // (string) $customer->secure_key
);
// Sets $this->module->currentOrder after execution
```

## Duplicate Prevention

```
razorpay_sales_order.order_placed = 1
→ Both paths check this before validateOrder()
→ DB UNIQUE KEY on order_id prevents two rows pointing to same PS order
→ DB UNIQUE KEY on (cart_id, rzp_payment_id) prevents same payment twice
```
