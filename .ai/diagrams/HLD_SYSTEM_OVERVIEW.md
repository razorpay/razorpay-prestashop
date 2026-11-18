# HLD: System Overview — Razorpay PrestaShop

```mermaid
graph TB
    subgraph Browser["Customer Browser"]
        PS_UI["PrestaShop Checkout UI"]
        JS["script.js"]
        RZP_MODAL["Razorpay Checkout Modal\n(checkout.js CDN)"]
    end

    subgraph PS_Server["PrestaShop Server"]
        MODULE["razorpay.php\n(PaymentModule)"]
        ORDER_CTRL["order.php\n(RazorpayOrderModuleFrontController)"]
        VALID_CTRL["validation.php\n(RazorpayValidationModuleFrontController)"]
        WEBHOOK_CTRL["webhook.php\n(RazorpayWebhookModuleFrontController)"]
        WEBHOOK_PROC["razorpay-webhook.php\n(RZP_Webhook)"]
        PS_ORDER["PrestaShop Order\nCreation Engine"]
        DB[(razorpay_sales_order\nps_configuration\nps_orders)]
    end

    subgraph Razorpay["Razorpay Platform"]
        RZP_API["Razorpay REST API\napi.razorpay.com"]
        RZP_CDN["Checkout.js CDN\ncheckout.razorpay.com"]
        RZP_WEBHOOKS["Webhook Delivery\nServer-to-Server"]
    end

    PS_UI --> JS
    JS -->|"POST /razorpay/order"| ORDER_CTRL
    ORDER_CTRL -->|"Orders API\ncreate order"| RZP_API
    RZP_API -->|"{ rzp_order_id }"| ORDER_CTRL
    ORDER_CTRL -->|"{ success, rzp_order_id }"| JS
    JS -->|"load & open modal"| RZP_CDN
    RZP_CDN --> RZP_MODAL
    RZP_MODAL -->|"payment.authorized"| RZP_API
    RZP_MODAL -->|"handler(payment_id, signature)"| JS
    JS -->|"POST /razorpay/validation"| VALID_CTRL
    VALID_CTRL -->|"verifyPaymentSignature"| RZP_API
    VALID_CTRL --> PS_ORDER
    PS_ORDER --> DB
    VALID_CTRL -->|"redirect to confirmation"| PS_UI

    RZP_WEBHOOKS -->|"POST /razorpay/webhook\norder.paid event"| WEBHOOK_CTRL
    WEBHOOK_CTRL --> WEBHOOK_PROC
    WEBHOOK_PROC -->|"verifyWebhookSignature"| WEBHOOK_PROC
    WEBHOOK_PROC --> DB
    WEBHOOK_PROC --> PS_ORDER

    MODULE -->|"hookHeader: inject JS vars"| JS
    MODULE -->|"admin save → autoEnableWebhook"| RZP_API
```

## Component Responsibilities

| Component | Layer | Responsibility |
|---|---|---|
| `razorpay.php` | Module | Config, hooks, webhook setup |
| `order.php` | Controller | Razorpay Order creation, JSON API |
| `validation.php` | Controller | Signature verify, PS order creation |
| `webhook.php` | Controller | Webhook entry point |
| `razorpay-webhook.php` | Service | Webhook business logic, race condition handling |
| `script.js` | Frontend | Modal orchestration, button management |
| `razorpay-sdk/` | Library | HTTP client for Razorpay API |
| `razorpay_sales_order` | Database | Payment state tracking, duplicate prevention |
