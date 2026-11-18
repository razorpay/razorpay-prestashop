# Prompt: Feature Planning — Razorpay PrestaShop

Use this prompt to plan a new feature before implementation.

---

## Prompt Template

```
You are planning a new feature for the Razorpay PrestaShop payment extension (v2.5.8).

Codebase structure:
- razorpay/razorpay.php        — Main module (hooks, config, Razorpay class)
- razorpay/razorpay-webhook.php — Webhook processor (RZP_Webhook)
- razorpay/controllers/front/
  - order.php       — POST endpoint: creates Razorpay order
  - validation.php  — POST endpoint: verifies payment, creates PS order
  - webhook.php     — POST endpoint: receives Razorpay webhooks
- razorpay/script.js            — Frontend JS (modal orchestration)
- razorpay/views/templates/     — Smarty templates

PrestaShop compatibility: 1.7.0.0 – 9.0.3
Razorpay SDK: v2.8.1 (bundled at razorpay/razorpay-sdk/)

Feature request:
[DESCRIBE THE FEATURE]

Please provide:
1. IMPACT ANALYSIS
   - Which existing files need to change?
   - Does this affect the payment flow, webhook flow, or both?
   - Any database changes required?
   - Any new configuration keys needed?

2. IMPLEMENTATION PLAN
   - Step-by-step changes in order of dependency
   - New files to create (if any)
   - Existing methods to modify

3. COMPATIBILITY CONCERNS
   - Does this work across PS 1.7.x through 9.0.x?
   - Any PHP version constraints (module supports PHP 5.6+)?
   - Impact on existing webhook or payment behavior?

4. SECURITY REVIEW
   - New input vectors? (validate with (int) or pSQL())
   - New API credentials needed?
   - New signature verification requirements?

5. TESTING APPROACH
   - Test cases for the happy path
   - Edge cases to verify (session loss, webhook race condition, etc.)
   - How to test webhook changes locally (ngrok, test payloads)
```
