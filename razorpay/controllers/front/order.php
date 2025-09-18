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

        try
        {
            if (in_array($this->context->currency->iso_code,  Razorpay::CURRENCY_NOT_ALLOWED) === false)
            {
                $orderData = array(
                    'amount'           => $amount,
                    'currency'         => $this->context->currency->iso_code,
                    'payment_capture'  => ($payment_action === Razorpay::CAPTURE) ? 1 : 0,
                    'receipt'          => (string) $this->context->cart->id,
                );

                $order  = (new Razorpay(false))->getRazorpayApiInstance()->order->create($orderData);
            }
            else
            {
                Logger::addLog("Order creation failed, because currency (". $this->context->currency->iso_code . ") not supported" . $error, 4);
            }

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

                $this->context->cookie->rzp_order_id = $order->id;

                //save the entry to razorpay_sales_order table

                $db = \Db::getInstance();

                $cartId = (int)$this->context->cart->id;
                $rzpOrderId = pSQL($order->id);

                $request = "SELECT `entity_id` FROM `razorpay_sales_order` WHERE `cart_id` = " . $cartId;

                $order_sales_id = $db->getValue($request);

                if(empty($order_sales_id) === true)
                {
                    $sql = "INSERT INTO `razorpay_sales_order` (`cart_id`, `rzp_order_id`, `amount_paid`) VALUES ($cartId, '$rzpOrderId', 0.00)";
                    $db->execute($sql);

                    Logger::addLog("Record inserted in razorpay_sales_order table cart_id : " . $cartId, 4);
                }
                else
                {
                    $request = "UPDATE `razorpay_sales_order` SET `cart_id` = $cartId, `rzp_order_id` = '$rzpOrderId' WHERE `entity_id` = $order_sales_id";

                    $db->execute($request);
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
