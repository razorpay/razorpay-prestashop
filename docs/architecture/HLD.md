# High-Level Design — Razorpay PrestaShop Extension

## System Components

```
┌─────────────────────────────────────────────────────────────┐
│                    Customer Browser                          │
│                                                              │
│  ┌─────────────────┐    ┌────────────────────────────────┐  │
│  │ PrestaShop      │    │ Razorpay Checkout Modal         │  │
│  │ Checkout Page   │◄──►│ (checkout.js from CDN)          │  │
│  └────────┬────────┘    └────────────────────────────────┘  │
│           │ script.js                                        │
└───────────┼──────────────────────────────────────────────────┘
            │ HTTP
┌───────────▼──────────────────────────────────────────────────┐
│                  PrestaShop Server                            │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ razorpay.php (Razorpay extends PaymentModule)          │  │
│  │  - Hooks: header, paymentOptions, orderConfirmation    │  │
│  │  - Admin config + webhook auto-setup                   │  │
│  └────────────────────────────────────────────────────────┘  │
│                                                              │
│  ┌──────────────┐  ┌──────────────────┐  ┌───────────────┐  │
│  │ order.php    │  │ validation.php   │  │ webhook.php   │  │
│  │ POST         │  │ POST             │  │ POST          │  │
│  │ /rzp/order   │  │ /rzp/validation  │  │ /rzp/webhook  │  │
│  └──────┬───────┘  └────────┬─────────┘  └───────┬───────┘  │
│         │                   │                     │          │
│         │    ┌──────────────▼─────────────────────▼────────┐ │
│         │    │ razorpay-webhook.php (RZP_Webhook)          │ │
│         │    │  - Verify signature                         │ │
│         │    │  - orderPaid() with wait-time guard         │ │
│         │    └─────────────────────────────────────────────┘ │
│         │                   │                     │          │
│  ┌──────▼───────────────────▼─────────────────────▼────────┐ │
│  │             Database                                     │ │
│  │  razorpay_sales_order | ps_orders | ps_configuration    │ │
│  └──────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────┬───────────────────┘
                                           │ HTTPS
┌──────────────────────────────────────────▼───────────────────┐
│                    Razorpay Platform                          │
│                                                              │
│  ┌──────────────┐  ┌──────────────────┐  ┌───────────────┐  │
│  │ Orders API   │  │ Payments API      │  │ Webhook       │  │
│  │ create order │  │ fetch, edit notes │  │ Delivery      │  │
│  └──────────────┘  └──────────────────┘  └───────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

## Design Principles

### 1. No-Redirect Payment
The entire payment flow happens on the merchant's PrestaShop site. Razorpay's Checkout.js loads as a modal overlay. This improves conversion rates by keeping customers on the merchant's domain.

### 2. Reliability via Dual Paths
Every payment has two independent paths to create the PrestaShop order:
- **Frontend path**: Fast, immediate, triggered by customer browser
- **Webhook path**: Server-to-server, reliable, catches frontend failures

### 3. Safety via Wait-Time Guard
The webhook path intentionally delays to give the frontend path priority. This prevents duplicate orders when both paths would otherwise succeed simultaneously.

### 4. Security via HMAC Verification
Every order creation is gated behind cryptographic verification:
- Payment callback: HMAC-SHA256 over `order_id|payment_id`
- Webhook: HMAC-SHA256 over raw request body

### 5. Stateful Tracking
The `razorpay_sales_order` table provides a complete audit trail of every payment's journey — which path created the order, how many webhook attempts arrived, and when.

## Data Flow

```
Cart → Razorpay Order (via API) → Payment (via modal) → PrestaShop Order
  ↑                                                              ↑
  └────────── razorpay_sales_order tracks entire journey ───────┘
```

## Integration Points

| Integration | Protocol | Security |
|---|---|---|
| Razorpay Orders API | HTTPS REST | API Key auth |
| Razorpay Payments API | HTTPS REST | API Key auth |
| Razorpay Checkout.js | CDN script load | Subresource integrity |
| Razorpay Webhooks | HTTPS POST | HMAC-SHA256 |
| PrestaShop DB | MySQL | PS DB class with input sanitization |
| PrestaShop Config | MySQL | Configuration::get/updateValue |
