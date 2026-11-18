# Webhook Processing — Hard Constraints

---

### C1: Blank Webhook Secret Silently Skips Signature Verification

**Rule:** If `RAZORPAY_WEBHOOK_SECRET` is empty or not configured, HMAC signature verification is never performed and the webhook payload is processed as trusted.

**Why this is a risk:** An attacker who can POST to the webhook endpoint with a valid JSON payload and a plausible `order.paid` event structure can trigger `validateOrder()` for an arbitrary cart, creating a real PrestaShop order with no payment. There is no secondary server-side payment confirmation before order creation in the webhook path (unlike the frontend path which fetches `$this->api->payment->fetch()` to verify amount).

**Enforced at:** `razorpay-webhook.php:process()` line 62 — `if (empty($razorpayWebhookSecret) === false)`. The entire verification block is skipped when secret is blank; no error is logged, no 400 is returned.

**Misconfiguration signal:** Webhook is enabled (`ENABLE_RAZORPAY_WEBHOOK = 'on'`) but `RAZORPAY_WEBHOOK_SECRET` is empty. The admin UI toggles the secret field with JavaScript (`admin.tpl` line 86-91) — it is possible to enable webhooks without entering a secret.

**Correct state:** Both `ENABLE_RAZORPAY_WEBHOOK = 'on'` AND a non-empty `RAZORPAY_WEBHOOK_SECRET` must be set together.

---

### C2: UNIQUE KEY on (cart_id, rzp_payment_id)

**Rule:** The `razorpay_sales_order` table has `UNIQUE KEY cart_id (cart_id, rzp_payment_id)`. A given cart + payment ID pair can only appear once.

**What the constraint actually guards:** Prevents the same Razorpay payment from being linked to the same cart twice. The key is named `cart_id` (confusingly), but it is a composite key on two columns.

**How duplicates are handled in practice:** The webhook path never INSERTs — it only UPDATEs an existing row (inserted by `order.php` at checkout time). The UNIQUE KEY would fire if two concurrent webhook processes both tried to INSERT for the same cart+payment, but since the row is pre-created by `order.php`, this path is not triggered under normal operation. If the `order.php` INSERT is somehow called twice (e.g., user double-clicks), the second INSERT hits this constraint and fails at the DB level — the error is not caught; `order.php` logs it at level 4.

**Enforced at:** `razorpay.php:install()` line 204 (DDL), `controllers/front/order.php` line 92 (INSERT path)

---

### C3: UNIQUE KEY on order_id

**Rule:** `razorpay_sales_order` has `UNIQUE KEY order_id (order_id)`. A PrestaShop order can only appear once in the bridge table.

**Why:** Prevents two bridge records from pointing at the same PS order, which would make it impossible to determine which Razorpay payment corresponds to an order. If `setCurrentState()` or `validateOrder()` somehow ran twice for the same PS order, the second UPDATE in the webhook/frontend path that sets `order_id` would fail with a duplicate-key error.

**Enforced at:** `razorpay.php:install()` line 205 (DDL)

**Note:** `order_id` is NULL until `validateOrder()` completes (either via webhook or frontend). The UNIQUE constraint allows multiple NULL values in MySQL/MariaDB, so pre-creation rows (where order is not yet placed) do not conflict.

---

### C4: Wait Time Gate Requires Bridge Row to Exist

**Rule:** The wait-time throttle (300s gate) only activates if a row exists in `razorpay_sales_order` for the incoming `cart_id` + `rzp_order_id`. If no row exists, the SELECT returns empty, the `if(empty($salesOrderData['entity_id']) === false)` block is skipped entirely, and the webhook proceeds directly to `Order::getOrderByCartId()` and potentially `validateOrder()` — with no wait.

**Why this matters:** The bridge row is created in `order.php` when the checkout page loads and the Razorpay order is created. If the user abandons the checkout before `order.php` runs, no bridge row exists. A webhook delivery for that cart_id will bypass the wait time entirely and attempt order creation immediately. In practice this is benign (the cart will still not have a PS order), but it is a non-obvious bypass of the throttle.

**Enforced at:** `razorpay-webhook.php:orderPaid()` line 142 (`empty($salesOrderData['entity_id'])` check), `controllers/front/order.php` line 92 (INSERT that creates the row)

---

### C5: Raw php://input Required; Malformed JSON Silently Returns

**Rule:** `process()` reads `php://input` directly. If the body is not valid JSON, `json_last_error() !== 0` evaluates to true and `process()` returns immediately with no error response.

**What the caller receives:** The outer `postProcess()` in `webhook.php` returns normally after `process()` returns, resulting in an HTTP 200 response with an empty body. Razorpay's delivery system treats 2xx as success and will not retry.

**Risk:** A mis-configured proxy or CDN that re-encodes the body (e.g., gzip without the module decompressing) will cause silent discard. The only indication is absence of any log entry for that delivery.

**Enforced at:** `razorpay-webhook.php:process()` lines 43-50

---

### C6: prestashop_cart_id Must Be Present in Payment Notes

**Rule:** `orderPaid()` reads `$data['payload']['payment']['entity']['notes']['prestashop_cart_id']` without any null-check. If this key is absent (e.g., payment created outside the module, or notes were modified in the Razorpay dashboard), PHP emits a notice and `$cartId` is null/empty.

**Effect:** The subsequent `SELECT ... WHERE cart_id = ` query runs with a null cart_id, returns no row, `new Cart(null)` is constructed, and `validateOrder()` may be called with an invalid cart — resulting in a PS exception that is caught and logged, but the webhook receives 200 (the exception is caught inside the try/catch in `orderPaid()` and exits after echoing the error).

**Enforced at:** `razorpay-webhook.php:orderPaid()` line 131 (no guard), `controllers/front/order.php` (sets the note when creating the Razorpay order — this is the only guaranteed path that populates the note)
