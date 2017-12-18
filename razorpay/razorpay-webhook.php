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
    const PAYMENT_AUTHORIZED = 'payment.authorized';
    const PAYMENT_FAILED     = 'payment.failed';
    const ORDER_PAID = 'order.paid';

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

                        error_log(json_encode($log));
                        return;
                    }

                }
                else
                {
                    return;
                }
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
        exit;
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
         // We don't process subscription/invoice payments here
        if (isset($data['payload']['payment']['entity']['invoice_id']) === true)
        {
            exit;
        }

        //reference_no (ps order id) should be passed in payload
        $orderId = $data['payload']['payment']['entity']['notes']['reference_no'];
    
        $order = new Order($orderId);

        if(empty($order->id_cart))
        {
            exit;
        }

        // If payment is already done, ignore the event
        if ($order->getCurrentOrderState()->paid === '1')
        {
            exit;
        }

        $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

        $payment = $this->getPaymentEntity($razorpayPaymentId, $data);

        try
        {
            $order->setCurrentState((int) Configuration::get('PS_OS_PAYMENT'));
        }
        catch (Exception $e)
        {
            $error = $e->getMessage();
            Logger::addLog("Payment Failed for Order# ".$order->id_cart.". Razorpay payment id: ".$payment->id. "Error: ". $error, 4);

            echo 'Order Id: '.$order->id_cart.'</br>';
            echo 'Razorpay Payment Id: '.$payment->id.'</br>';
            echo 'Error: '.$error.'</br>';

            exit;
        }

        // Graceful exit since payment is now processed.
        exit;
    }

    protected function getPaymentEntity($razorpayPaymentId, $data)
    {
        try
        {
            $payment = $this->api->payment->fetch('pay_9DatWmAvREZZyI');
        }
        catch (Exception $e)
        {
            $log = array(
                'message'         => $e->getMessage(),
                'payment_id'      => $razorpayPaymentId,
                'event'           => $data['event']
            );

            error_log(json_encode($log));

            exit;
        }

        return $payment;
    }

}
