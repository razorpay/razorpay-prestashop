# AGENTS.md — razorpay-prestashop

Razorpay payment gateway module for PrestaShop. PHP module (v2.5.8) supporting PS 1.6.x, 1.7.x, 8.x, 9.x.

## Repository Structure

```
razorpay/                          # Module root (deploy this directory to PS modules/)
├── razorpay.php                   # Main module class, hooks, admin config
├── razorpay-webhook.php           # Webhook handler (RZP_Webhook)
├── script.js                      # Frontend Checkout.js integration
├── controllers/front/             # HTTP endpoints (order, validation, webhook)
├── razorpay-sdk/                  # Bundled Razorpay PHP SDK v2.8.1
└── views/templates/               # Smarty templates (admin config, payment return)
```

## Domain Knowledge

All extracted knowledge is in `.agents/skills/repo-skill/`. Load before making changes:

| Topic | File |
|-------|------|
| Module boundaries & ownership | `core/boundaries.md` |
| Common operations quick-ref | `core/quick-ref.md` |
| Payment flow mental model | `modules/domain/payment-flow/concept.md` |
| Payment flow code paths | `modules/domain/payment-flow/flows.md` |
| Payment constraints & rules | `modules/domain/payment-flow/constraints.md` |
| Webhook flow mental model | `modules/domain/webhook-processing/concept.md` |
| Webhook code paths | `modules/domain/webhook-processing/flows.md` |
| Webhook race condition rules | `modules/domain/webhook-processing/constraints.md` |
| Module config flow | `modules/domain/module-config/flows.md` |
| DB schema & lifecycle | `modules/technical/database/schema.md` |
| Frontend JS (script.js) | `modules/technical/frontend/checkout-js.md` |
| SDK architecture | `modules/technical/sdk/api-client.md` |
| PrestaShop hooks | `modules/technical/hooks/registered-hooks.md` |
| Razorpay Orders API | `modules/integration/razorpay-api/orders.md` |
| Razorpay Payments API | `modules/integration/razorpay-api/payments.md` |
| Webhook events & auto-reg | `modules/integration/razorpay-api/webhooks.md` |
| PrestaShop PaymentModule contract | `modules/integration/prestashop/payment-module.md` |

## Critical Gotchas

1. **Amounts in paise** — Multiply by 100. KWD/OMR/BHD are blocked currencies.
2. **Webhook/frontend race** — `razorpay_sales_order.order_placed` is the dedup guard. Webhook waits 300s to yield to frontend.
3. **Blank webhook secret** — Empty `RAZORPAY_WEBHOOK_SECRET` silently bypasses signature verification.
4. **Smarty scoping** — `hookHeader()` uses `Media::addJsDef()`, not Smarty assign. PS 1.6 vs 1.7+ difference; fixed in v2.5.8.
5. **Session + DB dual storage** — `rzp_order_id` in both PHP session and DB. DB is the fallback when session expires.
6. **`payment.authorized` and `payment.failed` are stubs** — Only `order.paid` does meaningful work.
7. **SDK statics** — `Api::$key`/`$secret` are PHP statics; safe only because single-merchant credentials.

---

## Skills Index

The following skills are installed in `.agents/skills/`. Trigger them when the scenario matches.

### repo-skill

**Trigger:** When working on ANY file in this repository.
**Load:** Always load `core/boundaries.md` and `core/quick-ref.md` first.
**Progressive loading:**
- When touching payment flow code → load `modules/domain/payment-flow/`
- When touching webhook code → load `modules/domain/webhook-processing/`
- When touching razorpay.php config → load `modules/domain/module-config/`
- When touching script.js → load `modules/technical/frontend/checkout-js.md`
- When touching razorpay-sdk/ → load `modules/technical/sdk/api-client.md`
- When touching razorpay_sales_order queries → load `modules/technical/database/schema.md`

---

### application-security (`code-security`)

**Trigger:** When reviewing PRs for security issues, writing code that handles user input, implementing authentication/signature verification, or when asked to "check for security issues", "review for vulnerabilities", "is this secure".

**Invoke:** `/code-security`

Covers: injection prevention, signature verification correctness, API key handling, input validation (PHP context included).

---

### go-code-review (`code-review`)

**Trigger:** When asked to "review this code", "review this PR", "check for issues", "code review", or before merging a PR.

**Invoke:** `/code-review`

Note: Primarily Go-focused but applies general code review principles useful for PHP too (error handling, concurrency, resource management).

---

### devstack

**Trigger:** When setting up a local development environment, deploying to devstack, debugging pod failures, creating helm charts, or when asked "how do I run this locally", "deploy to devstack", "debug the pod crash".

**Invoke:** `/devstack`

---

### log-volume-optimizer

**Trigger:** When asked to reduce logging costs, audit log volume, optimize Coralogix spend, or when reviewing changes that add new log statements.

**Invoke:** `/log-volume-optimizer`

---

### logs-investigator

**Trigger:** When debugging a production issue, looking up a specific payment ID or order ID in logs, investigating an error spike, tracing a request across services, or when asked "why did payment X fail", "check logs for order Y".

**Invoke:** `/logs-investigator`

---

### pr-observability

**Trigger:** When preparing a PR for production, asked "what should I monitor for this change", "give me observability for this PR", "what metrics/logs should I watch after deploying".

**Invoke:** `/pr-observability`

---

### tech-spec-reviewer

**Trigger:** When reviewing a tech spec, design document, architecture proposal, or when asked "review this design", "am I missing anything", "check this spec".

**Invoke:** `/tech-spec-reviewer`
