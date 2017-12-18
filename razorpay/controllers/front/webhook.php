<?php

require_once __DIR__.'/../../razorpay-sdk/Razorpay.php'; 

use Razorpay\Api\Api;

class RazorpayWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        try
        {
            $post = file_get_contents('php://input');

            $data = json_decode($post, true);

            if (json_last_error() !== 0)
            {
                exit;
            }

            $paymentId = $data['payload']['payment']['entity']['id'];
            
            $rzpWebhook = new RZP_Webhook();

            $rzpWebhook->process($data);
        }
        catch(\Razorpay\Api\Errors\BadRequestError $e)
        {
            $error = $e->getMessage();
            Logger::addLog("Payment Failed for Razorpay payment id: ".$paymentId. "Error: ". $error, 4);

            echo 'Error: '.$error.'</br>';

            exit;
        }
    }
  
}
