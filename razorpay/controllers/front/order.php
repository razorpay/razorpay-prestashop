<?php

require_once __DIR__.'/../../razorpay-sdk/Razorpay.php';
require_once __DIR__.'/../../razorpay.php';

use Razorpay\Api\Api;

class RazorpayOrderModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        if($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Content-Type: application/json');
            header('Status:', true, 400);
            exit;
        }


        //verify the webhook enabled or not
        $webhookLastVerified = Configuration::get('RAZORPAY_WEBHOOK_LAST_VERIFY');

        if (empty($webhookLastVerified) === true)
        {
            (new Razorpay(false))->autoEnableWebhook();
        }
        else
        {
            if ($webhookLastVerified + 86400 < time())
            {
                (new Razorpay(false))->autoEnableWebhook();
            }
        }

        $payment_action = Configuration::get('RAZORPAY_PAYMENT_ACTION') ? Configuration::get('RAZORPAY_PAYMENT_ACTION') : Razorpay::CAPTURE;

        $amount = number_format(($this->context->cart->getOrderTotal() * 100), 0, "", "");

        $code = 400;

        $rzp_order_id = "";

        try{
            $order  = (new Razorpay(false))->getRazorpayApiInstance()->order->create(array('amount' => $amount, 'currency' => $this->context->currency->iso_code, 'payment_capture' => ($payment_action === Razorpay::CAPTURE) ? 1 : 0));

            $responseContent = [
                'message'    => 'Unable to create your order. Please contact support.',
                'parameters' => []
            ];

            if (null !== $order && !empty($order->id))
            {
                $responseContent = [
                    'success'       => true,
                    'rzp_order_id'  => $order->id,
                    'amount'        => $amount
                ];

                $code = 200;

                session_start();
                $_SESSION['rzp_order_id'] = $order->id;

                //save the entry to razorpay_sales_order table

                $db = \Db::getInstance();

                $request = "SELECT `entity_id` FROM `razorpay_sales_order` WHERE `cart_id` = ".$this->context->cart->id;

                $order_sales_id = $db->getValue($request);

                if(empty($order_sales_id) === true)
                {
                    $request = "INSERT INTO `razorpay_sales_order` (`cart_id`, `rzp_order_id`) VALUES (".$this->context->cart->id . ",'" . $order->id . "')";

                    $result = $db->execute($request);
                    Logger::addLog("Record inserted in razorpay_sales_order table cart_id : " . $this->context->cart->id, 4);
                }
                else
                {
                    $request = "UPDATE `razorpay_sales_order` SET `cart_id` = ".$this->context->cart->id .", `rzp_order_id` = '" . $order->id . "' WHERE `entity_id` = $order_sales_id";

                    $result = $db->execute($request);
                    Logger::addLog("Record updated in razorpay_sales_order table for $order_sales_id", 4);
                }
            }

        }
        catch(\Razorpay\Api\Errors\BadRequestError $e)
        {
            $error = $e->getMessage();

            $responseContent = [
                'message'   => $error,
                'parameters' => []
            ];

            Logger::addLog("Order creation failed with the error " . $error, 4);
        }
        catch(\Exception $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
        }

        header('Content-Type: application/json', true, $code);
        echo json_encode($responseContent);
        exit;
    }
}
