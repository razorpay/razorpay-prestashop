<?php

require_once __DIR__.'/razorpay.php';
require_once __DIR__.'/razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class RZP_Webhook
{
    /**
     * Instance of the razorpay payments class
     * @var Razorpay
     */
    protected $razorpay;

    /**
     * API client instance to communicate with Razorpay API
     * @var Razorpay\Api\Api
     */
    protected $api;

    /**
     * Event constants
     */
    const PAYMENT_AUTHORIZED    = 'payment.authorized';
    const PAYMENT_FAILED        = 'payment.failed';
    const ORDER_PAID            = 'order.paid';

    function __construct()
    {
        $this->razorpay = new Razorpay(false);
        $this->api = $this->razorpay->getRazorpayApiInstance();
    }

    /**
     * Process a Razorpay Webhook. We exit in the following cases:
     * - Successful processed
     * - Exception while fetching the payment
     *
     * It passes on the webhook in the following cases:
     * - invoice_id set in payment.authorized
     * - Invalid JSON
     * - Signature mismatch
     * - Secret isn't setup
     * - Event not recognized
     */
    public function process()
    {
        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        if (json_last_error() !== 0)
        {
            return;
        }

        $enabled = Configuration::get('ENABLE_RAZORPAY_WEBHOOK');

        if (($enabled === 'on') and
            (empty($data['event']) === false))
        {
            
            if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true)
            {
                $razorpayWebhookSecret = Configuration::get('RAZORPAY_WEBHOOK_SECRET');

                if (empty($razorpayWebhookSecret) === false)
                {

                    try
                    {
                        $this->api->utility->verifyWebhookSignature($post,
                                                                $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'],
                                                                $razorpayWebhookSecret);
                    }
                    catch (Errors\SignatureVerificationError $e)
                    {
                        $log = array(
                            'message'   => $e->getMessage(),
                            'data'      => $data,
                            'event'     => 'razorpay.wc.signature.verify_failed'
                        );

                        Logger::addLog("Error: ". json_encode($log), 4);
                        //Set the validation error in response
                        header('Status: 400 Signature Verification failed', true, 400);    
                        exit;
                    }

                    switch ($data['event'])
                    {
                        case self::PAYMENT_AUTHORIZED:
                            return $this->paymentAuthorized($data);

                        case self::PAYMENT_FAILED:
                            return $this->paymentFailed($data);

                        case self::ORDER_PAID:
                            return $this->orderPaid($data);

                        default:
                            return;
                    }

                }
            }
            
        }
    }

    /**
     * Does nothing for the main payments flow currently
     * @param array $data Webook Data
     */
    protected function paymentFailed(array $data)
    {
        return;
    }

    /**
     * Does nothing for the main payments flow currently
     * @param array $data Webook Data
     */
    protected function paymentAuthorized(array $data)
    {
       return;
    }

    /**
     * Handling order.paid event    
     * @param array $data Webook Data
     */
    protected function orderPaid(array $data)
    {
        // reference_no (prestashop_cart_id) should be passed in payload
        $cartId = $data['payload']['payment']['entity']['notes']['prestashop_cart_id'];
        $rzpOrderId = $data['payload']['order']['entity']['id'];
        $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

        $db = \Db::getInstance();

        //verify to entry to razorpay_sales_order table
        $request = "SELECT `entity_id`, `order_placed`, `webhook_count`, `order_id`, `webhook_first_notified_at` FROM `razorpay_sales_order` WHERE `cart_id` =  $cartId  AND `rzp_order_id` = '" . $rzpOrderId ."'";

        $salesOrderData = $db->getRow($request);

        if(empty($salesOrderData['entity_id']) === false)
        {

            if ($salesOrderData['order_placed'])
            {
                 Logger::addLog("Razorpay Webhook: Quote order is inactive for cartID: $cartId and Razorpay payment_id(:$razorpayPaymentId) with PrestaShop OrderID (:" . $salesOrderData['increment_order_id'] . ") ", 4);

                //return;
            }

            $setWebhookFirstNotifiedQuery =  "UPDATE `razorpay_sales_order` SET ";

            //set the 1st webhook notification time
            if ($salesOrderData['webhook_count'] < 1)
            {
                $firstNotifiedTime = time();

                $setWebhookFirstNotifiedQuery .= "`webhook_first_notified_at` = " . $firstNotifiedTime . ",
                            `rzp_payment_id` = '$razorpayPaymentId',";
            }
            else
            {
               $firstNotifiedTime = $salesOrderData['webhook_first_notified_at'];
            }

            $setWebhookFirstNotifiedQuery .= " `webhook_count` = " . ($salesOrderData['webhook_count'] + 1);

            $setWebhookFirstNotifiedQuery .= " WHERE `entity_id` = " . $salesOrderData['entity_id'];

            $db->execute($setWebhookFirstNotifiedQuery);


            $webhookWaitTime = Configuration::get('RAZORPAY_WEBHOOK_WAIT_TIME') ? Configuration::get('RAZORPAY_WEBHOOK_WAIT_TIME') : 300;

            //ignore webhook call for some time as per config, from first webhook call
            if ((time() - $firstNotifiedTime) < $webhookWaitTime)
            {
                Logger::addLog("Razorpay Webhook: Order processing is active for cartID: $cartId and Razorpay payment_id(:$razorpayPaymentId) and webhook attempt: " . ($salesOrderData['webhook_count'] + 1), 4);

                header('Status: 409 Conflict, too early for processing', true, 409);

                exit;
            }

        }

        // check if a order already present for this cart
        $cart = new Cart($cartId);
    
        // Fetch the Order ID of this CartId
        $orderId = Order::getOrderByCartId($cart->id);

        // If order associated with the cart
        if(!empty($orderId))
        {
            $order = new Order($orderId);

            // If payment is already done, ignore the event
            $payments = $order->getOrderPayments();

            if (count($payments) >= 1)
            {
                exit;
            }

            $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

            try
            {
                $order->setCurrentState((int) Configuration::get('PS_OS_PAYMENT'));
            }
            catch (Exception $e)
            {
                $error = $e->getMessage();

                Logger::addLog("Payment Failed for Order# ".$cart->id.". Razorpay payment id: ".$razorpayPaymentId. "Error: ". $error, 1);

                echo 'Order Id: '.$order->id_cart.'</br>';
                echo 'Razorpay Payment Id: '.$razorpayPaymentId.'</br>';
                echo 'Error: '.$error.'</br>';

                exit;
            }
            exit;
        }


        //create a fresh order for the cart as payment already successfull
        try{

            $customer = new Customer($cart->id_customer);

            /**
             * Validate an order in database
             * Function called from a payment module
             *
             * @param int     $id_cart
             * @param int     $id_order_state
             * @param float   $amount_paid       Amount really paid by customer (in the default currency)
             * @param string  $payment_method    Payment method (eg. 'Credit card')
             * @param null    $message           Message to attach to order
             * @param array   $extra_vars
             * @param null    $currency_special
             * @param bool    $dont_touch_amount
             * @param bool    $secure_key
             * @param Shop    $shop
             *
             * @return bool
             * @throws PrestaShopException
             */

            $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

            $amount_paid = number_format($data['payload']['payment']['entity']['amount']/100, "2", ".", "");

            $extraData = array(
                'transaction_id'    =>  $razorpayPaymentId,
            );

            // So netbanking becomes razorpay.netbanking
            $method = "razorpay." . $data['payload']['payment']['entity']['method'];

            $ret = $this->razorpay->validateOrder(
                $cart->id,
                (int) Configuration::get('PS_OS_PAYMENT'),
                $cart->getOrderTotal(true, Cart::BOTH),
                $method,
                'Payment by Razorpay using ' . $data['payload']['payment']['entity']['method'],
                $extraData,
                NULL,
                false,
                $customer->secure_key
            );

            //Update the Razorpay payment with corresponding created order ID of this cart ID
            try{
                $this->api->payment->fetch($razorpayPaymentId)->edit(array('notes' => array('prestashop_order_id' => $this->razorpay->currentOrder,'prestashop_cart_id'=>$cart->id)));

            } catch (\Razorpay\Api\Errors\BadRequestError $e){
                $error = $e->getMessage();
                Logger::addLog("Razorpay payment notes update failed for the webhook of Razorpay payment id: ".$razorpayPaymentId. "with the Error ".$error, 4);
            }

            //add to entry to razorpay_sales_order table
            $request = "SELECT `entity_id` FROM `razorpay_sales_order` WHERE `cart_id` = " . $cart->id .
                        " AND `rzp_order_id` = '" . $rzpOrderId ."'";

            $salesOrderId = $db->getValue($request);

            if(empty($salesOrderId) === false)
            {
               $request =  "UPDATE `razorpay_sales_order`
                            SET `rzp_payment_id` = '$razorpayPaymentId',
                            `amount_paid` = $amount_paid,
                            `order_id` = " . $this->razorpay->currentOrder . ",
                            `by_webhook` = 1,
                            `order_placed` = 1
                            WHERE `cart_id` = " . $cart->id . " AND `entity_id` = $salesOrderId";

                $result = $db->execute($request);

                Logger::addLog("Record inserted in razorpay_sales_order table ", 4);
            }

        }
        catch (Exception $e)
        {
            $error = $e->getMessage();

            Logger::addLog("Order creation Failed for Cart# ".$cart->id.". Razorpay payment id: ".$razorpayPaymentId. "Error: ". $error, 1);

            echo 'Cart Id: '.$cart->id.'</br>';
            echo 'Razorpay Payment Id: '.$razorpayPaymentId.'</br>';
            echo 'Error: '.$error.'</br>';

            exit;
        }

        // Graceful exit since payment is now processed.
        exit;
    }
}
