# Razorpay PHP SDK — API Client

SDK version **2.8.1** (declared as `Api::VERSION` in `Api.php`). Bundled at `razorpay/razorpay-sdk/`.
Module version is separately tracked as `2.5.8` in `razorpay.php:__construct()`.

---

## Entry Point

`razorpay.php:getRazorpayApiInstance()` — called throughout the module whenever an API call is needed.

```php
public function getRazorpayApiInstance()
{
    return new Api($this->KEY_ID, $this->KEY_SECRET);
}
```

**Non-obvious:** A new `Api` instance is constructed on every call. Credentials are stored as PHP static properties (`Api::$key`, `Api::$secret`), so each construction overwrites the statics. This is safe only because a single merchant key pair is ever in use. Do not call `getRazorpayApiInstance()` concurrently with different credentials.

**App details registration** happens after construction via `Api::setAppDetails()` / `Request::addHeader()`. The module does not call `setAppDetails()`; the User-Agent is built as `Razorpay/v1 PHPSDK/2.8.1 PHP/<version>` with no plugin annotation.

---

## Magic Dispatch — `Api::__get()`

`Api.php:__get()` converts a property access into an entity instantiation:

```
$api->order    → new \Razorpay\Api\Order()
$api->payment  → new \Razorpay\Api\Payment()
$api->request  → new \Razorpay\Api\Request()   ← used for raw HTTP (webhooks)
$api->utility  → new \Razorpay\Api\Utility()
```

Pattern: `__NAMESPACE__ . '\\' . ucwords($name)` — maps to a class file under `src/`.

**Gotcha:** Every property access creates a new object. `$api->payment->fetch($id)` and `$api->payment->edit(...)` chained on a fetched entity work because `fetch()` fills and returns `$this`. But `$api->payment->fetch($id)` followed by a separate `$api->payment->edit()` will fail — the second `$api->payment` is a new, empty entity with no `id` attribute.

---

## HTTP Layer — `Request::request()`

`Request.php:request()` is the single HTTP dispatch point. Key behaviours:

| Aspect | Detail |
|--------|--------|
| Auth | HTTP Basic Auth: `Api::getKey()` as username, `Api::getSecret()` as password |
| TLS | Forced to TLS 1.1 minimum via `setCurlSslOpts()` curl hook (constant `CURL_SSLVERSION_TLSv1_1 = 5`) |
| User-Agent | `Razorpay/v1 PHPSDK/2.8.1 PHP/<version>` plus any app details appended |
| Fixed header | `Razorpay-API: 1` sent on every request |
| Timeout | 60 seconds |
| Base URL | `https://api.razorpay.com/v1/` (static, overridable via `Api::setBaseUrl()`) |
| Library | `rmccue/requests` (Requests 1.8.0 bundled at `libs/Requests-1.8.0/`) |

**Error detection** in `Request.php:checkErrors()`: HTTP status outside `[200, 300)` triggers `processError()`. The error code string from `body['error']['code']` is title-cased and mapped to a class under `Razorpay\Api\Errors\` (e.g. `BAD_REQUEST_ERROR` → `BadRequestError`). If the body is not well-formed JSON or the error code is unknown, `ServerError` is thrown instead.

**TLS 1.1 enforcement decision:** The constant is defined as a fallback for PHP < 5.5.19/5.6.3 where it was not yet in the PHP standard library. Actual negotiation with Razorpay servers will use the highest mutually supported TLS version; this sets a floor.

---

## Entity Base Class — `Entity.php`

URL pattern: `Entity.php:getEntityUrl()` derives URL segment from class name using `snakeCase()`:
- `Order` → `orders/`
- `Payment` → `payments/`

| Method | HTTP | URL |
|--------|------|-----|
| `create($attrs)` | POST | `{entity}s/` |
| `fetch($id)` | GET | `{entity}s/{id}` |
| `all($options)` | GET | `{entity}s/` (query params) |

`Entity.php:request()` wraps the response: if `response['entity']` matches the calling class name, it fills `$this` and returns `$this` (enabling method chaining). Otherwise it calls `buildEntity()` which inspects the entity type and constructs the matching class.

**Non-obvious:** `buildEntity()` only knows about `['collection', 'payment', 'refund', 'order', 'customer', 'token', 'settlement']`. Any entity type not in that list gets wrapped as the calling entity class.

---

## Order Entity Methods — `Order.php`

| Method | HTTP | URL | Notes |
|--------|------|-----|-------|
| `create($attrs)` | POST | `orders/` | Standard creation |
| `fetch($id)` | GET | `orders/{id}` | |
| `all($options)` | GET | `orders/` | |
| `edit($attrs)` | PATCH | `orders/{id}` | Requires `$this->id` to be set (call after `fetch`) |
| `payments()` | GET | `orders/{id}/payments` | Lists payments for this order |
| `transfers($options)` | GET | `orders/{id}` | Misleading name — fetches order, not transfers |

**Gotcha on `transfers()`:** The method is named `transfers()` but issues a GET to `orders/{id}` with options passed as query params — it does not call a `/transfers` sub-resource. This appears to be an SDK bug or intentional filter mechanism.

---

## Payment Entity Methods — `Payment.php`

| Method | HTTP | URL | Notes |
|--------|------|-----|-------|
| `fetch($id)` | GET | `payments/{id}` | |
| `edit($attrs)` | PATCH | `payments/{id}` | Used by module to write order notes |
| `refund($attrs)` | POST | `refunds/` | Delegates to `Refund::create()` with `payment_id` injected |
| `capture($attrs)` | POST | `payments/{id}/capture` | Required when payment action is `authorize` |
| `transfer($attrs)` | POST | `payments/{id}/transfers` | |

The module uses the chain `$api->payment->fetch($paymentId)->edit(array(...))` in `hookOrderConfirmation`. The `fetch()` fills and returns `$this`, so `edit()` has `$this->id` available.

---

## Utility — `Utility.php`

### `verifyPaymentSignature($attributes)`

Inputs (array keys):
- `razorpay_signature` — signature to verify
- `razorpay_payment_id` — always required
- One of: `razorpay_order_id`, `razorpay_subscription_id`, or `razorpay_payment_link_id` (+ `razorpay_payment_link_reference_id`, `razorpay_payment_link_status`)

Payload construction:
- Order flow: `"{order_id}|{payment_id}"`
- Subscription flow: `"{payment_id}|{subscription_id}"`
- Payment link flow: `"{link_id}|{ref_id}|{status}|{payment_id}"`

Algorithm: `HMAC-SHA256(payload, Api::$secret)` compared via constant-time equality.

### `verifyWebhookSignature($payload, $actualSignature, $secret)`

Inputs: raw request body string, `X-Razorpay-Signature` header value, webhook secret.
Algorithm: same `HMAC-SHA256`. The webhook secret is **separate** from the API key secret.

**Timing-attack mitigation:** Uses PHP built-in `hash_equals()` when available (PHP >= 5.6). Falls back to `Utility.php:hashEquals()` — a manual XOR loop that is constant-time regardless of string content.

---

## Direct HTTP Calls (Webhook Management)

`razorpay.php:webhookAPI()` bypasses the entity pattern entirely:

```php
$api->request->request($method, $url, $data)
```

`$api->request` invokes `Api::__get('request')` → `new Request()`. This calls `Request::request()` directly with a relative URL (e.g. `webhooks/`, `webhooks/{id}`), which is prefixed with the base URL. Used for GET/POST/PUT on the webhooks resource during `autoEnableWebhook()`.

**Why direct:** The SDK does not have a `Webhook` entity class. Rather than add one, the module accesses the raw HTTP layer. This pattern is fragile — any future change to `Request::request()`'s signature breaks it.

---

## Autoloader — `Razorpay.php`

`Razorpay.php` (entry point, not the `Razorpay` module class) does two things:
1. Conditionally includes the bundled Requests library only if `class_exists('Requests') === false` — avoids double-include if PrestaShop already loaded a compatible version.
2. Registers an `spl_autoload_register` for the `Razorpay\Api` namespace → `src/` directory.

**Gotcha:** The Requests version check (`version_compare(Requests::VERSION, '1.6.0') === -1`) throws if the found version is older than 1.6.0. If PrestaShop loads a newer incompatible Requests library first, the module will silently use it.

---

## Gaps Requiring Human Verification

- Whether `$api->payment->all()` with `X-Razorpay-Account` header is used anywhere in the module (it is implemented in `Payment.php` but not called in the visible module code).
- TLS 1.1 floor — verify Razorpay API still accepts TLS 1.1; industry standard has moved to 1.2+.
- The `Order::transfers()` method behaviour (appears to be a bug — fetches order instead of transfers sub-resource).
