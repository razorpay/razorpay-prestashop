# Payment Flow Documentation

See detailed documentation in:
- **Deep-dive**: [`.ai/context/PAYMENT_FLOW.md`](../../.ai/context/PAYMENT_FLOW.md)
- **Sequence diagram**: [`.ai/diagrams/LLD_CHECKOUT_SEQUENCE.md`](../../.ai/diagrams/LLD_CHECKOUT_SEQUENCE.md)
- **HLD diagram**: [`.ai/diagrams/HLD_PAYMENT_FLOW.md`](../../.ai/diagrams/HLD_PAYMENT_FLOW.md)

## Quick Summary

1. Customer visits PrestaShop checkout (`controller=order`)
2. `hookHeader()` injects `checkout.js` + `script.js` + JS variables
3. Customer selects Razorpay → `script.js` shows Pay button
4. Customer clicks Pay → `script.js` POSTs to `/razorpay/order`
5. `order.php` creates Razorpay Order via API, stores in DB + session, returns JSON
6. `script.js` opens Razorpay Checkout modal
7. Customer completes payment in modal
8. `script.js` submits form to `/razorpay/validation`
9. `validation.php` verifies HMAC signature, calls `validateOrder()`, redirects to confirmation

**Key invariants:**
- Amount always in paise (integer): `number_format(($total * 100), 0, "", "")`
- Signature verified before `validateOrder()` (no exceptions)
- `secure_key` passed to `validateOrder()` for security
