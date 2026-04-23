# Webhook Flow Documentation

See detailed documentation in:
- **Deep-dive**: [`.ai/context/WEBHOOK_FLOW.md`](../../.ai/context/WEBHOOK_FLOW.md)
- **Sequence diagram**: [`.ai/diagrams/LLD_WEBHOOK_SEQUENCE.md`](../../.ai/diagrams/LLD_WEBHOOK_SEQUENCE.md)
- **HLD diagram**: [`.ai/diagrams/HLD_WEBHOOK_FLOW.md`](../../.ai/diagrams/HLD_WEBHOOK_FLOW.md)

## Quick Summary

Webhooks are a **fallback** for when the frontend payment flow fails.

1. Razorpay sends `order.paid` POST to `/razorpay/webhook`
2. `webhook.php` instantiates `RZP_Webhook`, calls `process()`
3. `process()` verifies `X-Razorpay-Signature` header (HMAC-SHA256)
4. Routes to `orderPaid($data)`
5. `orderPaid()` checks if order already created (`order_placed=1`)
6. First webhook: records timestamp, returns HTTP 409 (wait time guard)
7. After wait time (default 300s): creates PrestaShop order if frontend missed it

**Key invariants:**
- Webhook signature verified before any DB/order operation
- Wait-time guard prevents race condition with frontend
- `order_placed` flag prevents duplicate orders
- `by_webhook=1` set to track which path created the order
