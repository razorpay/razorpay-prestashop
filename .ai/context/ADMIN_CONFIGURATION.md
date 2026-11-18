# Admin Configuration — Razorpay PrestaShop

## Accessing Settings

**PrestaShop Admin → Modules → Razorpay → Configure**

URL pattern: `/index.php?controller=AdminModules&configure=razorpay`

---

## Settings Panel Fields

### API Credentials

| Field | Config Key | Required | Notes |
|---|---|---|---|
| Razorpay Key Id | `RAZORPAY_KEY_ID` | Yes | Live: `rzp_live_xxx`, Test: `rzp_test_xxx` |
| Razorpay Key Secret | `RAZORPAY_KEY_SECRET` | Yes | Keep secret, never expose in frontend |

Both fields are validated as non-empty in `_postValidation()`. Saving with empty values shows an error and does not update.

### Payment Action

| Value | Config Key | Constant | Behaviour |
|---|---|---|---|
| `capture` (default) | `RAZORPAY_PAYMENT_ACTION` | `Razorpay::CAPTURE` | Auto-capture on authorization |
| `authorize` | `RAZORPAY_PAYMENT_ACTION` | `Razorpay::AUTHORIZE` | Only authorize; manual capture via dashboard |

Default is `capture` if not configured (see `order.php:35`).

### Webhook Settings

| Field | Config Key | Description |
|---|---|---|
| Webhook URL | — | Displayed read-only; copy-to-clipboard button |
| Webhook Secret | `RAZORPAY_WEBHOOK_SECRET` | Auto-generated 20-char secret |
| Webhook Wait Time | `RAZORPAY_WEBHOOK_WAIT_TIME` | Seconds before webhook creates order (default: 300) |
| Events | `RAZORPAY_WEBHOOK_EVENTS` | Selected events (currently: `order.paid`) |

The webhook URL format: `https://yourshop.com/razorpay/webhook`

---

## Admin Template: `views/templates/admin/admin.tpl`

The Smarty template receives these variables from `_displayForm()`:

```php
$this->smarty->assign([
    'modrazorpay'                   => 'Razorpay Setup',
    'modrazorpayDesc'               => 'Please specify the Razorpay Key Id and Key Secret.',
    'modClientLabelKeyId'           => 'Razorpay Key Id',
    'modClientLabelKeySecret'       => 'Razorpay Key Secret',
    'modClientValueKeyId'           => $this->KEY_ID,
    'modClientValueKeySecret'       => $this->KEY_SECRET,
    'modUpdateSettings'             => 'Update settings',
    'modWebhookSecret'              => $this->WEBHOOK_SECRET,
    'modWebhookWaitTime'            => $this->WEBHOOK_WAIT_TIME ?? 300,
    'modPayActionCaptureLabel'      => 'Authorize and Capture',
    'modPayActionAuthorizeLabel'    => 'Authorize',
    'modPayActionCapture'           => 'capture',
    'modPayActionAuthorize'         => 'authorize',
    'modPayActionCaptureSelected'   => ($action === 'capture' || !$action) ? "selected='selected'" : "",
    'modPayActionAuthorizeSelected' => ($action === 'authorize') ? "selected='selected'" : "",
    'modOrderPaidEvent'             => 'order.paid',
    'webhookUrl'                    => $webhookUrl,
    'requestUri'                    => $_SERVER['REQUEST_URI'],
]);
```

In Smarty templates, access PHP vars with `{$varName}` syntax (not `$varName` directly — this was a bug fixed in v2.5.8).

---

## Settings Save Flow

On form submit with `btnSubmit`:

```
1. _postValidation()  → checks KEY_ID and KEY_SECRET are non-empty
2. _postProcess()     → if no errors:
   a. Configuration::updateValue('RAZORPAY_KEY_ID', Tools::getValue('KEY_ID'))
   b. Configuration::updateValue('RAZORPAY_KEY_SECRET', Tools::getValue('KEY_SECRET'))
   c. Configuration::updateValue('RAZORPAY_PAYMENT_ACTION', Tools::getValue('PAYMENT_ACTION'))
   d. Configuration::updateValue('ENABLE_RAZORPAY_WEBHOOK', true)
   e. Configuration::updateValue('RAZORPAY_WEBHOOK_EVENTS', Tools::getValue('EVENTS'))
   f. Configuration::updateValue('RAZORPAY_WEBHOOK_WAIT_TIME', $webhookWaitTime)
   g. autoEnableWebhook()  → creates/updates webhook via Razorpay API
   h. Display "Settings updated" confirmation
```

---

## Webhook Auto-Enable Logic

`autoEnableWebhook()` is called on every settings save. It:

1. Builds the webhook URL
2. Validates the domain has a public IP (skips localhost/private IPs)
3. Generates or reuses webhook secret
4. Paginates through Razorpay webhooks API to find existing webhook for this URL
5. Creates (POST) or updates (PUT) the webhook
6. Sets `RAZORPAY_WEBHOOK_LAST_VERIFY = time()`
7. Sets `ENABLE_RAZORPAY_WEBHOOK = 'on'` (or `'off'` if localhost)

It is also re-triggered (if >86400 seconds since last verify) each time a customer clicks "Pay" in `order.php`.

---

## Uninstall Behavior

`uninstall()` deletes all configuration keys:
- `RAZORPAY_KEY_ID`
- `RAZORPAY_KEY_SECRET`
- `RAZORPAY_PAYMENT_ACTION`
- `ENABLE_RAZORPAY_WEBHOOK`
- `RAZORPAY_WEBHOOK_EVENTS`
- `RAZORPAY_WEBHOOK_SECRET`
- `RAZORPAY_WEBHOOK_WAIT_TIME`

Note: `RAZORPAY_WEBHOOK_LAST_VERIFY` is **not** deleted on uninstall (minor oversight — does not affect functionality since other keys are gone).

Also drops `razorpay_sales_order` table.
