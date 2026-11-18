# Prompt: Security Audit — Razorpay PrestaShop

Use this prompt to perform a thorough security audit of the module.

---

## Prompt Template

```
You are conducting a security audit of the Razorpay PrestaShop payment extension (v2.5.8).

The module handles real financial transactions. Security failures could lead to:
- Fraudulent orders (fake payment data accepted)
- Payment data exposure
- SQL injection via order/cart IDs
- Replay attacks on webhooks

Files to audit:
- razorpay/razorpay.php
- razorpay/controllers/front/order.php
- razorpay/controllers/front/validation.php
- razorpay/controllers/front/webhook.php
- razorpay/razorpay-webhook.php
- razorpay/script.js
- razorpay/views/templates/admin/admin.tpl

Audit checklist:

1. SIGNATURE VERIFICATION
   - [ ] verifyPaymentSignature() called before validateOrder() in validation.php
   - [ ] verifyWebhookSignature() called before any business logic in razorpay-webhook.php
   - [ ] HMAC computed over correct input (order_id|payment_id for payment, raw body for webhook)
   - [ ] SignatureVerificationError properly caught and handled (not silently swallowed)

2. INPUT VALIDATION
   - [ ] cart_id from URL validated as integer: is_numeric() + (int) cast
   - [ ] All direct DB interpolations use (int) for IDs
   - [ ] All string DB interpolations use pSQL()
   - [ ] Payment amount NOT accepted from client (computed server-side)

3. CREDENTIAL SECURITY
   - [ ] KEY_SECRET never appears in JS razorpay_checkout_vars
   - [ ] KEY_SECRET stored in ps_configuration (not in code)
   - [ ] WEBHOOK_SECRET stored in ps_configuration
   - [ ] No credentials in template files

4. API SECURITY
   - [ ] ENABLE_RAZORPAY_WEBHOOK checked before processing webhook
   - [ ] RAZORPAY_WEBHOOK_SECRET emptiness check (skips if not configured)
   - [ ] API instance created with credentials from Configuration::get() (not hardcoded)

5. DUPLICATE ORDER PREVENTION
   - [ ] order_placed flag prevents double order creation
   - [ ] Webhook wait-time guard prevents frontend/webhook race condition
   - [ ] UNIQUE KEY constraints on razorpay_sales_order prevent DB-level duplicates

6. OUTPUT SECURITY
   - [ ] Error pages don't leak stack traces or internal paths
   - [ ] Logger::addLog used for internal errors (not echo to customer)
   - [ ] Smarty template uses {$var|escape:'html'} for user-controlled values

7. ADMIN SECURITY
   - [ ] Admin form uses Tools::isSubmit('btnSubmit') check
   - [ ] Admin form uses Tools::getValue() (not $_POST directly)
   - [ ] Settings page only accessible to admins (PrestaShop handles this)

For each issue found, provide:
- Severity: Critical / High / Medium / Low
- File and line number
- Description of the vulnerability
- Recommended fix
```
