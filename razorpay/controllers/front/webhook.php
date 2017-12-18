<?php

require_once __DIR__.'/../../razorpay-sdk/Razorpay.php'; 
require_once __DIR__.'/../../razorpay-webhook.php'; 

use Razorpay\Api\Api;

class RazorpayWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        try
        {
            $rzpWebhook = new RZP_Webhook();
            $rzpWebhook->process();
        }
        catch(\Razorpay\Api\Errors\BadRequestError $e)
        {
            $error = $e->getMessage();

            echo 'Error: '.$error.'</br>';

            exit;
        }
    }
}
