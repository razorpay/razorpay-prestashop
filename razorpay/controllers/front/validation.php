<?php

require_once __DIR__.'/../../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;

class RazorpayValidationModuleFrontController extends ModuleFrontController
{
    private $api;
    private $db;

    public function postProcess()
    {
        try
        {
            $this->db = \Db::getInstance();
            $this->initRazorpayApi();

            $paymentId = $this->validateRequest();
            $cart = $this->loadCartAndCustomer();

            $payment = $this->fetchPaymentDetails($paymentId);

            $this->validatePayment($payment, $cart);

            $this->processOrder($payment, $cart);
        }
        catch (Exception $e)
        {
            $this->handleError($e->getMessage(), Tools::getValue('razorpay_payment_id'), Tools::getValue('cart_id'));
        }
    }

    /**
     * Initializes the Razorpay API client.
     */
    private function initRazorpayApi()
    {
        $key_id = Configuration::get('RAZORPAY_KEY_ID');
        $key_secret = Configuration::get('RAZORPAY_KEY_SECRET');

        if (empty($key_id) || empty($key_secret))
        {
            throw new Exception('Razorpay API keys are not configured.');
        }

        $this->api = new Api($key_id, $key_secret);
        $this->api->setAppDetails('Prestashop', $this->module->version);
    }

    /**
     * Validates incoming POST parameters.
     * @return string The Razorpay Payment ID.
     * @throws Exception
     */
    private function validateRequest()
    {
        $paymentId = Tools::getValue('razorpay_payment_id');
        $signature = Tools::getValue('razorpay_signature');

        if (empty($paymentId) || empty($signature))
        {
            throw new Exception('Missing payment parameters. The transaction could not be verified.');
        }

        return $paymentId;
    }

    /**
     * Loads and validates the cart and customer from context.
     * @return Cart
     * @throws Exception
     */
    private function loadCartAndCustomer()
    {
        // If the context cart is not loaded, try to load it from the request.
        if (!$this->context->cart->id)
        {
            $reqCartId = (int)Tools::getValue('cart_id');
            if (!$reqCartId)
            {
                throw new Exception('Cart could not be loaded. Please try again.');
            }

            $candidateCart = new Cart($reqCartId);
            if (!Validate::isLoadedObject($candidateCart) || $candidateCart->secure_key !== $this->context->customer->secure_key)
            {
                throw new Exception('Invalid cart context. Please try again.');
            }

            $this->context->cart = $candidateCart;
            $this->context->customer = new Customer($candidateCart->id_customer);
        }

        $cart = $this->context->cart;

        // Ensure cart is valid for placing an order
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active)
        {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer))
        {
            Tools::redirect('index.php?controller=order&step=1');
        }

        return $cart;
    }

    /**
     * Fetches the full payment entity from Razorpay.
     * @param string $paymentId
     * @return object
     * @throws Exception
     */
    private function fetchPaymentDetails($paymentId)
    {
        try
        {
            return $this->api->payment->fetch($paymentId);
        }
        catch (\Razorpay\Api\Errors\Base $e)
        {
            throw new Exception('Could not fetch payment details from Razorpay: ' . $e->getMessage());
        }
    }

    /**
     * Runs all payment validation checks.
     * @param object $payment
     * @param Cart $cart
     * @throws Exception
     */
    private function validatePayment($payment, $cart)
    {
        $expectedRzpOrderId = $this->getExpectedRazorpayOrderId($cart->id);

        if ($payment->order_id !== $expectedRzpOrderId)
        {
            throw new Exception('Payment does not belong to this order.');
        }

        $this->preventPaymentReuse($payment->id, $cart->id);
        $this->verifySignature($expectedRzpOrderId, $payment->id);
        $this->verifyAmountAndCurrency($payment, $cart);
    }

    /**
     * Gets the expected Razorpay Order ID from cookie and validate mapping from db.
     * @param int $cartId
     * @return string
     * @throws Exception
     */
    private function getExpectedRazorpayOrderId($cartId)
    {
        $cookieOrderId = $this->context->cookie->rzp_order_id;

        if (!empty($cookieOrderId)) {
            $query = "SELECT 1 FROM `razorpay_sales_order` WHERE `cart_id` = " . (int)$cartId . " AND `rzp_order_id` = '" . pSQL($cookieOrderId) . "';";

            if ($this->db->getValue($query)) {
                return $cookieOrderId;
            }
        }

        throw new Exception('Unable to get expected order id.');
    }

    /**
     * Prevents the reuse of a payment ID for a different cart.
     * @param string $paymentId
     * @param int $cartId
     * @throws Exception
     */
    private function preventPaymentReuse($paymentId, $cartId)
    {
        $query = "SELECT `cart_id` FROM `razorpay_sales_order` WHERE `rzp_payment_id` = '".pSQL($paymentId)."';";
        $row = $this->db->getRow($query);

        if (!empty($row) && (int)$row['cart_id'] !== $cartId)
        {
            throw new Exception('This payment ID has already been used for another order.');
        }
    }

    /**
     * Verifies the payment signature returned by Razorpay.
     * @param string $rzpOrderId
     * @param string $paymentId
     * @throws Exception
     */
    private function verifySignature($rzpOrderId, $paymentId)
    {
        try
        {
            $attributes = [
                'razorpay_order_id'   => $rzpOrderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature'  => Tools::getValue('razorpay_signature'),
            ];
            $this->api->utility->verifyPaymentSignature($attributes);
        }
        catch (\Razorpay\Api\Errors\SignatureVerificationError $e)
        {
            throw new Exception('Payment signature verification failed.');
        }
    }

    /**
     * Verifies that the paid amount and currency match the cart totals.
     * @param object $payment
     * @param Cart $cart
     * @throws Exception
     */
    private function verifyAmountAndCurrency($payment, $cart)
    {
        $expectedAmount = (int)number_format(($this->context->cart->getOrderTotal() * 100), 0, "", "");;
        $paidAmount = (int)$payment->amount;

        $expectedCurrency = strtoupper($this->context->currency->iso_code);
        $paidCurrency = strtoupper($payment->currency);

        if ($expectedAmount !== $paidAmount || $expectedCurrency !== $paidCurrency)
        {
            throw new Exception('Payment amount or currency mismatch.');
        }
    }

    /**
     * Processes the order: validates it in PrestaShop and updates custom tables.
     * @param object $payment
     * @param Cart $cart
     */
    private function processOrder($payment, $cart)
    {
        $customer = new Customer($cart->id_customer);
        $existingOrderId = Order::getOrderByCartId((int)$cart->id);

        if ($existingOrderId)
        {
            $this->redirectToOrderConfirmation($cart->id, $existingOrderId, $customer->secure_key);
        }

        $paymentMethod = "razorpay.{$payment->method}";
        $this->module->validateOrder(
            $cart->id,
            (int)Configuration::get('PS_OS_PAYMENT'),
            $cart->getOrderTotal(true, Cart::BOTH),
            $paymentMethod,
            'Payment by Razorpay using ' . $payment->method,
            ['transaction_id' => $payment->id],
            null,
            false,
            $customer->secure_key
        );

        $this->updateSalesOrderTable($payment, $cart->id, $this->module->currentOrder);
        Logger::addLog("Payment Successful for Cart #".$cart->id.". Razorpay payment id: ".$payment->id, 1);
        $this->redirectToOrderConfirmation($cart->id, $this->module->currentOrder, $customer->secure_key);
    }

    /**
     * Updates the custom razorpay_sales_order table with payment details.
     * @param array $payment
     * @param int $cartId
     * @param int $orderId
     */
    private function updateSalesOrderTable($payment, $cartId, $orderId)
    {
        $amountPaidSafe   = (float)number_format($payment->amount / 100, 2, ".", "");
        $paymentIdSafe    = pSQL($payment->id);
        $rzpOrderIdSafe   = pSQL($payment->order_id);
        $orderIdSafe      = (int)$orderId;
        $cartIdSafe       = (int)$cartId;

        $sql = "UPDATE `razorpay_sales_order`
            SET 
                `rzp_payment_id` = '{$paymentIdSafe}',
                `amount_paid` = {$amountPaidSafe},
                `order_id` = {$orderIdSafe},
                `by_frontend` = 1,
                `order_placed` = 1
            WHERE 
                `cart_id` = {$cartIdSafe} AND `rzp_order_id` = '{$rzpOrderIdSafe}'";

        $this->db->execute($sql);
    }

    /**
     * Redirects the user to the order confirmation page.
     * @param int $cartId
     * @param int $orderId
     * @param string $secureKey
     */
    private function redirectToOrderConfirmation($cartId, $orderId, $secureKey)
    {
        $query = http_build_query([
            'controller' => 'order-confirmation',
            'id_cart'    => $cartId,
            'id_module'  => (int)$this->module->id,
            'id_order'   => $orderId,
            'key'        => $secureKey,
        ], '', '&');
        Tools::redirect('index.php?' . $query);
    }

    /**
     * Handles all errors, logs them, and displays a clean, self-contained error page.
     * @param string $errorMessage The user-facing error message.
     * @param string|null $paymentId
     * @param string|null $cartId
     */
    protected function handleError($errorMessage, $paymentId = null, $cartId = null)
    {
        $parts = ["Razorpay Payment Error: $errorMessage"];
        if ($cartId) {
            $parts[] = "Cart ID: $cartId";
        }
        if ($paymentId) {
            $parts[] = "Payment ID: $paymentId";
        }

        Logger::addLog(implode('. ', $parts) . '.', 3);

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        $translated = $this->trans($errorMessage, [], 'Modules.Razorpay.Shop');
        $safeMessage = htmlspecialchars($translated, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <title>Payment Error</title>
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background-color: #f4f4f4; }
                    .error-container { text-align: center; padding: 20px 40px; border: 1px solid #ddd; background-color: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-radius: 8px; }
                    h1 { color: #d9534f; font-size: 24px; }
                    p { color: #333; font-size: 16px; }
                </style>
            </head>
            <body>
                <div class="error-container">
                    <h1>An Error Occurred</h1>
                    <p>There was a problem processing your payment.</p>
                    <p><strong>{$safeMessage}</strong></p>
                    <p>Please contact customer support for assistance.</p>
                </div>
            </body>
            </html>
HTML;
        exit;
    }
}