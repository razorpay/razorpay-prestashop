# Kimi — Razorpay PrestaShop Context

## Project
Razorpay PrestaShop Payment Extension v2.5.8
PHP module for PrestaShop 1.7 to 9.0

## What It Does
- Adds Razorpay as a payment method in PrestaShop checkout
- Shows Razorpay Checkout modal (no page redirect)
- Accepts Credit/Debit Cards, NetBanking, UPI, Wallets
- Handles webhook events for reliable order creation

## Code Structure
```
razorpay/
├── razorpay.php                    ← Main module (hooks, config)
├── razorpay-webhook.php            ← Webhook processor
├── script.js                       ← Frontend (checkout modal)
├── razorpay-sdk/                   ← PHP SDK v2.8.1
└── controllers/front/
    ├── order.php                   ← POST: create Razorpay order
    ├── validation.php              ← POST: verify payment + create PS order
    └── webhook.php                 ← POST: receive webhook events
```

## Important Rules
1. Amounts in paise: `number_format(($total * 100), 0, "", "")`
2. Always verify HMAC signature before order creation
3. Check `order_placed=1` before calling `validateOrder()` (prevents duplicates)
4. Use `new Razorpay(false)` in non-module contexts

## More Documentation
Read `AGENTS.md` at repository root for complete details.
