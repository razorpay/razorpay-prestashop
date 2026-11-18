# Module Configuration — Concept

The module's configuration is the single source of truth for how the PrestaShop store connects to
Razorpay. It controls API credentials, payment capture behaviour, and webhook registration.
All values are stored in PrestaShop's `Configuration` table and loaded into instance properties
in `razorpay.php:__construct()` on every page load.

---

## Configuration Keys

| PS Config Key | Form Field Name | Type | Default | Controls |
|---|---|---|---|---|
| `RAZORPAY_KEY_ID` | `KEY_ID` | string | `null` | API auth; also injected into checkout JS |
| `RAZORPAY_KEY_SECRET` | `KEY_SECRET` | string | `null` | API auth; used server-side only |
| `RAZORPAY_PAYMENT_ACTION` | `PAYMENT_ACTION` | enum: `capture`/`authorize` | `capture` | Whether payment is captured immediately or only authorised |
| `ENABLE_RAZORPAY_WEBHOOK` | n/a (auto-managed) | string: `on`/`off` | not set until first save | Tracks whether webhook auto-registration succeeded |
| `RAZORPAY_WEBHOOK_SECRET` | n/a (auto-generated) | string (20 chars) | auto-generated on first save | HMAC secret used to verify incoming webhook payloads |
| `RAZORPAY_WEBHOOK_EVENTS` | `EVENTS` | serialised | `payment.authorized`, `order.paid` | Which Razorpay events are subscribed |
| `RAZORPAY_WEBHOOK_WAIT_TIME` | `WEBHOOK_WAIT_TIME` | int (seconds) | 300 | How long webhook handler waits before assuming frontend missed the payment |
| `RAZORPAY_WEBHOOK_LAST_VERIFY` | n/a (auto-managed) | unix timestamp | set after each successful auto-register | Records last time webhook was confirmed in Razorpay dashboard |

**Note:** `RAZORPAY_WEBHOOK_LAST_VERIFY` is written but not deleted on `uninstall()` — this is a
minor data remnant. All other keys are purged by `razorpay.php:uninstall()`.

---

## Admin Panel Structure

The merchant sees a single fieldset rendered by `razorpay.php:_displayForm()` and
`admin.tpl`. Fields visible in the form:

1. **Razorpay Key Id** — plain text input, pre-filled with saved value
2. **Razorpay Key Secret** — plain text input (not masked — secret is visible in browser)
3. **Payment Action** — dropdown: "Authorize" or "Authorize and Capture" (default)

Fields that are NOT exposed in the form but are managed behind the scenes:
- Webhook secret (auto-generated, never shown to merchant)
- Webhook URL (computed from PS link helper, shown read-only in JS but not submitted)
- Webhook enable/disable toggle (present in template JS but not rendered in current HTML — dead UI code)

The form POSTs to `$_SERVER['REQUEST_URI']` (same page). On submit, `getContent()` calls
`_postValidation()` then `_postProcess()`. Key ID and Key Secret are both required; missing
either blocks the save.

---

## Decisions

### D1: ENABLE_RAZORPAY_WEBHOOK is force-set to `true` on every admin save

**Context:** Merchants must not need to remember to enable the webhook separately; missing it
causes silent order failures when the browser flow fails.

**Decision:** `razorpay.php:_postProcess()` hard-codes `Configuration::updateValue('ENABLE_RAZORPAY_WEBHOOK', true)` before calling `autoEnableWebhook()`. The value is then overwritten to `'on'` or `'off'` by `autoEnableWebhook()` depending on whether registration succeeds.

**Alternatives considered:**
- **Merchant checkbox:** Rejected — too easy to leave disabled, causing missed orders.
- **Separate webhook settings page:** Rejected — adds friction for a required feature.

**Trade-offs:**
- Gained: Zero merchant effort; webhook always attempted.
- Lost: No merchant override; localhost/private-IP installs always end up with `'off'` and log noise.

**Code:** `razorpay.php:_postProcess()`, `razorpay.php:autoEnableWebhook()`

**Revisit if:** Merchants operating on private networks need webhook disabled permanently.

---

### D2: Webhook secret is auto-generated and never shown to the merchant

**Context:** A webhook secret is needed to verify Razorpay's HMAC signature on incoming
events. Asking merchants to generate and copy a random secret is error-prone.

**Decision:** On first admin save (when no secret exists), `razorpay.php:createWebhookSecret()`
generates a 20-character random string from a fixed charset and stores it. The same secret is
re-used on subsequent saves. It is pushed to Razorpay's API alongside the webhook URL.

**Trade-offs:**
- Gained: Fully automatic HMAC verification setup.
- Lost: Merchant cannot rotate the secret without a code-level intervention; secret is never
  surfaced for manual dashboard configuration.

**Code:** `razorpay.php:createWebhookSecret()`, `razorpay.php:autoEnableWebhook()`

**Revisit if:** Merchant needs to use their own secret or rotate after a compromise.

---

### D3: Payment Action defaults to `capture` when not explicitly set

**Context:** The `RAZORPAY_PAYMENT_ACTION` key may not exist on a fresh install until the
first save.

**Decision:** `razorpay.php:_displayForm()` renders `capture` as selected when
`$this->PAYMENT_ACTION` is either `'capture'` or falsy. The downstream payment handling code
reads this key to decide whether to capture immediately.

**Trade-offs:**
- Gained: Safe default — most merchants want immediate capture.
- Lost: Merchants who want authorize-only must explicitly change this; the empty state silently
  behaves as capture.

**Code:** `razorpay.php:_displayForm()`

---

## Config Lifecycle

### Install → First Admin Save → Auto-Webhook → Uninstall

| Stage | What Happens | Key Functions |
|---|---|---|
| `install()` | Creates `razorpay_sales_order` table; registers PS hooks; no config keys written | `razorpay.php:install()` |
| First page load (no keys) | `__construct()` detects null KEY_ID/KEY_SECRET; sets `$this->warning` banner | `razorpay.php:__construct()` |
| Admin form submit | Validates KEY_ID and KEY_SECRET are non-empty; writes all config keys; triggers webhook registration | `razorpay.php:_postProcess()` |
| Auto-webhook | Resolves shop domain to IP; rejects localhost/private ranges; paginates existing webhooks (10 at a time) to find URL match; creates or updates webhook in Razorpay dashboard; writes `RAZORPAY_WEBHOOK_LAST_VERIFY` timestamp | `razorpay.php:autoEnableWebhook()`, `razorpay.php:webhookAPI()` |
| Subsequent admin saves | Same as above; webhook secret preserved from previous save | same |
| `uninstall()` | Deletes all 7 config keys (not `RAZORPAY_WEBHOOK_LAST_VERIFY`); drops `razorpay_sales_order` table; calls parent uninstall | `razorpay.php:uninstall()` |

---

## Credentials Flow

```
Admin form (KEY_ID, KEY_SECRET)
  └─> _postProcess() — Configuration::updateValue()
        └─> stored in PS configuration table

__construct() — Configuration::getMultiple()
  └─> $this->KEY_ID, $this->KEY_SECRET

getRazorpayApiInstance()
  └─> new Api($this->KEY_ID, $this->KEY_SECRET)
        └─> used by: autoEnableWebhook(), webhookAPI(), hookOrderConfirmation()

hookHeader()
  └─> KEY_ID injected into JS as razorpay_checkout_vars.key (public key only — safe)
```

KEY_SECRET never reaches the browser. KEY_ID is intentionally public (Razorpay's checkout
requires it client-side to initialise the payment modal).

---

## Non-Obvious Constraints

**Localhost blocks webhook registration.** `razorpay.php:autoEnableWebhook()` resolves the
shop domain with `gethostbyname()` and rejects private/reserved IPv4 ranges via
`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`. Dev environments always get
`ENABLE_RAZORPAY_WEBHOOK = 'off'` silently. No error is shown to the merchant — only a
severity-3 log entry is written.

**Webhook pagination assumes exactly 10 items per page.** The do-while loop in
`autoEnableWebhook()` continues while `$webhook['count'] === $count` (hardcoded 10). If
Razorpay ever returns fewer than 10 items on a non-final page (e.g. due to filtering), the
loop exits early and may miss an existing webhook registration for this URL, resulting in a
duplicate webhook being created.

**KEY_SECRET is rendered in a plain text input.** The admin template renders the secret in
`<input type='text'>`, not `type='password'`. Any admin user with screen access or a
screenshot can read the live secret.

**Three currencies are blocked at the module level.** `CURRENCY_NOT_ALLOWED` contains KWD,
OMR, BHD. This is defined in `razorpay.php` as a constant but the enforcement point is in
the checkout/order controllers, not in config validation.

---

## Gaps Requiring Human Verification

- Whether `RAZORPAY_WEBHOOK_EVENTS` (the `EVENTS` form field) is actually rendered in the
  admin template — the current `admin.tpl` does not show an events checkbox, but the JS
  references `#events` show/hide logic. The field may have been removed from the form without
  removing the JS or the config key write.
- The `RAZORPAY_WEBHOOK_LAST_VERIFY` key is written but not used anywhere in the PHP code
  visible here. Whether something reads it (cron, webhook controller) needs verification.
