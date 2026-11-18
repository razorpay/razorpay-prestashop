# Prompt: Code Review — Razorpay PrestaShop

Use this prompt when reviewing a PR or code change for the Razorpay PrestaShop module.

---

## Prompt Template

```
You are reviewing a code change for the Razorpay PrestaShop payment extension (v2.5.8).

The module processes payments via Razorpay Checkout modal and uses a dual-path order creation
system (frontend validation + webhook fallback). The codebase is PHP (Allman style, 4-space
indent) for PrestaShop 1.7.0.0–9.0.3.

Review the following diff/code for:

1. SECURITY
   - Is HMAC signature verification present before any order creation?
   - Are all SQL values cast with (int) for integers and pSQL() for strings?
   - Is KEY_SECRET never exposed to the frontend/JavaScript?
   - Are amounts determined server-side (never from POST/GET parameters)?

2. CORRECTNESS
   - Are amounts in paise (integer)? Pattern: number_format(($total * 100), 0, "", "")
   - Is order_placed=1 checked before validateOrder() to prevent duplicates?
   - Is the webhook wait-time guard preserved?
   - Are sessions used with fallback to DB for rzp_order_id?
   - Is new Razorpay(false) used (not new Razorpay()) in non-module contexts?

3. PRESTASHOP COMPATIBILITY
   - Does it work with PS 1.7.0.0 through 9.0.3?
   - Is Configuration::get/updateValue used for settings (not hardcoded)?
   - Are translations done via $this->l() or $this->module->getTranslator()?
   - Are Smarty vars assigned via $this->smarty->assign() before fetch()?

4. WEBHOOK RELIABILITY
   - Is the duplicate prevention logic intact (order_placed flag)?
   - Is the webhook wait-time configurable and respected?
   - Are webhook events handled gracefully (unknown events return silently)?

5. CODE STYLE
   - Allman braces? 4-space indent? camelCase methods?
   - Appropriate Logger::addLog() level (1=info, 3=warn, 4=error)?

Code to review:
[PASTE CODE HERE]

Provide: specific issues found, severity (critical/major/minor), and suggested fixes.
```
