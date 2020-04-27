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
