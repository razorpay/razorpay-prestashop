# Webhook Processing — Architectural Decisions

---

### D1: razorpay_sales_order Bridge Table

**Context:** Both the browser callback (frontend) and Razorpay's webhook can attempt to create a PrestaShop order for the same cart. PrestaShop's `validateOrder()` is not idempotent — calling it twice creates two orders. There is no built-in PS mechanism to coordinate two independent callers racing on the same cart.

**Decision:** Introduce a `razorpay_sales_order` bridge table that is written by `order.php` at checkout initiation and updated by whichever actor wins the race. The `order_placed` flag is the authoritative single bit of truth for "has an order been created for this cart?"

**Alternatives considered:**
- **PS order lookup only:** Check `Order::getOrderByCartId()` directly without a bridge table — rejected because the PS order may not exist yet when the webhook first arrives, and there is no way to record the "in-flight" state or the wait window metadata (`webhook_first_notified_at`, `webhook_count`) without a side table.
- **Lock the cart:** Use a DB-level advisory lock per cart — rejected as PrestaShop does not expose a locking primitive suitable for cross-process coordination.

**Trade-offs:**
- Gained: A single place to read order state, audit who created it (`by_webhook`/`by_frontend`), and store timing metadata needed for the wait-time gate.
- Lost: An extra table and SELECT/UPDATE on every webhook delivery; the bridge row must be created by `order.php` before any webhook can be processed (if the row is absent, the wait-time gate is bypassed entirely — see constraints.md C4).

**Code:** `razorpay.php:install()` (CREATE TABLE), `razorpay-webhook.php:orderPaid()` (reads and updates), `controllers/front/validation.php` (sets `by_frontend=1, order_placed=1`), `controllers/front/order.php` (INSERTs the row)

**Revisit if:** PrestaShop adds a native idempotency layer for payment modules, or if the module migrates to a queue-based order creation model.

---

### D2: 300-Second Wait Time Before Webhook Creates Order

**Context:** The browser callback and the Razorpay webhook are two independent HTTP paths racing to call `validateOrder()`. The frontend callback arrives within seconds of payment completion in normal conditions. The webhook may arrive simultaneously or slightly earlier depending on network conditions.

**Decision:** When the first webhook delivery arrives, record the timestamp in `webhook_first_notified_at` and return HTTP 409 for all deliveries until `wait_time` seconds (default 300, configurable via `RAZORPAY_WEBHOOK_WAIT_TIME`) have elapsed since that first delivery. This gives the frontend up to 5 minutes to complete.

**Alternatives considered:**
- **Let webhook always win:** Webhook creates the order, frontend redirect lands on an already-created order — rejected because the frontend redirect flow requires `validateOrder()` to have been called by the frontend to construct the confirmation URL correctly; if the webhook already created it, the redirect may fail or show the wrong state.
- **Let frontend always win:** Ignore webhooks for order creation entirely — rejected because the frontend callback can fail (user closes browser, network drop). Webhook is the reliability backstop.
- **Shorter wait (e.g., 30s):** Tighter window risks the webhook winning when the frontend is slow (slow networks, payment method redirects like netbanking that take longer). 300s is conservative for worst-case netbanking redirects.

**Trade-offs:**
- Gained: Frontend almost always creates the order (better UX, correct redirect); webhook is a true fallback.
- Lost: If the frontend fails AND the webhook is the only actor, order creation is delayed by up to 300 seconds. Razorpay must retry (it does, with exponential backoff).

**Code:** `razorpay-webhook.php:orderPaid()` lines 174-183, `razorpay.php` line 393-394 (admin config save)

**Revisit if:** Razorpay's webhook retry window becomes shorter than 300s, or if netbanking redirect times reliably fall below 30s.

---

### D3: by_webhook and by_frontend Flags

**Context:** After an order is created, there is no way to tell — from the PS order alone — whether the webhook or the frontend browser callback was the actor that called `validateOrder()`. This distinction matters for debugging payment disputes and replaying failed deliveries.

**Decision:** Set `by_webhook=1` when the webhook calls `validateOrder()` (`razorpay-webhook.php:orderPaid()` line 297). Set `by_frontend=1` when the frontend validation controller calls it (`controllers/front/validation.php` line 158). Both are stored in `razorpay_sales_order` alongside `order_placed=1`.

**Alternatives considered:**
- **Single `order_source` enum:** One column with values 'webhook'/'frontend' — rejected as a more invasive schema change with no clear benefit over two boolean columns for a two-actor system.

**Trade-offs:**
- Gained: Clear audit trail; support staff can query `razorpay_sales_order` to identify webhook-created orders and correlate with Razorpay dashboard.
- Lost: Slight schema redundancy (both flags could theoretically be set, though in practice only one path runs `validateOrder()`).

**Code:** `razorpay-webhook.php:orderPaid()` line 297, `controllers/front/validation.php` line 158

**Revisit if:** A third order-creation path (e.g., admin-initiated) is introduced.

---

### D4: webhook_count Column

**Context:** Razorpay retries webhook delivery with exponential backoff. During the 300-second wait window, multiple deliveries arrive and all receive 409. Without tracking delivery attempts, there is no way to audit how many times a webhook was attempted, or to distinguish "first delivery" (needs `webhook_first_notified_at` stamped) from "subsequent delivery" (uses existing timestamp).

**Decision:** Increment `webhook_count` on every delivery that passes the `order_placed` check. Use `webhook_count < 1` as the condition for stamping `webhook_first_notified_at` — ensuring the timestamp is only set once regardless of concurrency.

**Alternatives considered:**
- **Rely on Razorpay dashboard:** Don't track locally — rejected because the dashboard shows delivery attempts at the Razorpay side, not the local processing state (e.g., a delivery that got 409 may still show as "delivered" in Razorpay's view).

**Trade-offs:**
- Gained: Local audit of delivery attempts; enables support to answer "was the webhook delivered multiple times?" without querying Razorpay's API.
- Lost: An extra UPDATE on every delivery; `smallint(6)` caps at 32,767 deliveries (not a real constraint in practice).

**Code:** `razorpay-webhook.php:orderPaid()` lines 155-167

**Revisit if:** A dedicated webhook delivery log table is introduced.

---

### D5: payment.authorized and payment.failed Are No-Op Stubs

**Context:** Razorpay emits `payment.authorized` when a payment is captured but before the order status transitions to paid. It emits `payment.failed` when a payment attempt fails. Both events are dispatched to the switch statement.

**Decision:** Both handlers (`paymentAuthorized()`, `paymentFailed()`) immediately `return` with no logic. The comment on each method says "Does nothing for the main payments flow currently."

**Alternatives considered:**
- **Handle payment.authorized to capture payment:** In some Razorpay integrations, `payment.authorized` is used to trigger capture for manual-capture accounts — not needed here because the PrestaShop module uses auto-capture; by the time `order.paid` fires, the payment is already captured.
- **Handle payment.failed to cancel the cart:** Mark cart as abandoned on failure — not implemented, likely because PS carts naturally expire and the user can retry from the cart page without any explicit cancellation step.

**Trade-offs:**
- Gained: Simplicity; no risk of double-processing or premature state changes.
- Lost: No local record of failed payment attempts; debugging requires querying the Razorpay dashboard.

**Code:** `razorpay-webhook.php:paymentAuthorized()` line 119, `razorpay-webhook.php:paymentFailed()` line 110

**Revisit if:** The module needs to support manual-capture accounts, or if failed payment telemetry is required locally.
