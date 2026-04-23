# Codebase Map — Razorpay PrestaShop

## File-to-Responsibility Map

### Core Module Files

| File | Class | Responsibility | Lines |
|---|---|---|---|
| `razorpay/razorpay.php` | `Razorpay` | Module bootstrap, hooks, admin config, webhook setup | 536 |
| `razorpay/razorpay-webhook.php` | `RZP_Webhook` | Server-to-server webhook processing | 324 |
| `razorpay/script.js` | — | Frontend checkout modal orchestration | 155 |
| `razorpay/config.xml` | — | PrestaShop module metadata | 13 |
| `razorpay/CHANGELOG.md` | — | Version history | 151 |

### Controller Files

| File | Class | HTTP Route | Purpose |
|---|---|---|---|
| `razorpay/controllers/front/order.php` | `RazorpayOrderModuleFrontController` | POST `/razorpay/order` | Create Razorpay Order |
| `razorpay/controllers/front/validation.php` | `RazorpayValidationModuleFrontController` | POST `/razorpay/validation` | Verify payment + create PS order |
| `razorpay/controllers/front/webhook.php` | `RazorpayWebhookModuleFrontController` | POST `/razorpay/webhook` | Webhook entry point |

### View Files

| File | Purpose |
|---|---|
| `razorpay/views/templates/admin/admin.tpl` | Admin settings panel (Smarty template) |
| `razorpay/views/templates/hook/payment_return.tpl` | Order confirmation message |

### SDK Files

| File | Purpose |
|---|---|
| `razorpay/razorpay-sdk/Razorpay.php` | SDK autoloader (require this) |
| `razorpay/razorpay-sdk/src/Api.php` | Main API client |
| `razorpay/razorpay-sdk/src/Utility.php` | Signature verification |
| `razorpay/razorpay-sdk/src/Order.php` | Order API operations |
| `razorpay/razorpay-sdk/src/Payment.php` | Payment API operations |
| `razorpay/razorpay-sdk/src/Refund.php` | Refund API operations |

---

## Method-to-Feature Map

### razorpay.php — Razorpay class

| Method | Feature | Called From |
|---|---|---|
| `__construct()` | Load config from PS | Module instantiation |
| `install()` | Create DB table, register hooks | PS module install |
| `uninstall()` | Delete config, drop table | PS module uninstall |
| `getContent()` | Admin settings page | PS admin |
| `_displayForm()` | Render admin Smarty template | getContent() |
| `_postValidation()` | Validate admin form | getContent() |
| `_postProcess()` | Save settings + auto-webhook | getContent() |
| `hookHeader()` | Inject JS on checkout page | PS hook |
| `hookPaymentOptions()` | Register payment method | PS hook |
| `hookOrderConfirmation()` | Update Razorpay notes | PS hook after order confirm |
| `hookPaymentReturn()` | Render confirmation | PS hook on return |
| `autoEnableWebhook()` | Create/update Razorpay webhook | _postProcess(), order.php |
| `webhookAPI()` | HTTP calls to Razorpay webhooks API | autoEnableWebhook() |
| `createWebhookSecret()` | Generate 20-char secret | autoEnableWebhook() |
| `getRazorpayApiInstance()` | Return new Api() instance | Multiple callers |

### razorpay-webhook.php — RZP_Webhook class

| Method | Feature |
|---|---|
| `__construct()` | Initialize module + API instance |
| `process()` | Verify signature, route to handler |
| `paymentAuthorized()` | No-op |
| `paymentFailed()` | No-op |
| `orderPaid()` | Wait-time guard + order creation |

---

## Configuration Key Usage Map

| Key | Read In | Written In |
|---|---|---|
| `RAZORPAY_KEY_ID` | `razorpay.php`, all controllers | `razorpay.php::_postProcess()` |
| `RAZORPAY_KEY_SECRET` | `razorpay.php`, all controllers | `razorpay.php::_postProcess()` |
| `RAZORPAY_PAYMENT_ACTION` | `order.php` | `razorpay.php::_postProcess()` |
| `ENABLE_RAZORPAY_WEBHOOK` | `razorpay-webhook.php` | `razorpay.php::autoEnableWebhook()` |
| `RAZORPAY_WEBHOOK_EVENTS` | `razorpay.php` admin | `razorpay.php::_postProcess()` |
| `RAZORPAY_WEBHOOK_SECRET` | `razorpay-webhook.php` | `razorpay.php::autoEnableWebhook()` |
| `RAZORPAY_WEBHOOK_WAIT_TIME` | `razorpay-webhook.php` | `razorpay.php::_postProcess()` |
| `RAZORPAY_WEBHOOK_LAST_VERIFY` | `order.php` | `razorpay.php::autoEnableWebhook()` |

---

## JS Variable Flow

```
hookHeader() → Media::addJsDef('razorpay_checkout_vars', [...])
                        ↓
              window.razorpay_checkout_vars (global)
                        ↓
script.js reads: key, amount, cart_id, order_controller, action_controller,
                 module_version, ps_version
```

## Session Variable Flow

```
order.php: $_SESSION['rzp_order_id'] = $order->id
                     ↓
validation.php: $attributes['razorpay_order_id'] = $_SESSION['rzp_order_id']
                         ↓ (fallback if session lost)
              SELECT rzp_order_id FROM razorpay_sales_order WHERE cart_id = X
```
