<?php

require_once __DIR__.'/../../razorpay-sdk/Razorpay.php'; 
require_once __DIR__.'/../../razorpay-webhook.php'; 

use Razorpay\Api\Api;

class RazorpayWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $rzpWebhook = new RZP_Webhook();

        try
        {
            $rzpWebhook->process();
        }
        catch(\Razorpay\Api\Errors\BadRequestError $e)
        {
            $error = $e->getMessage();

            Logger::addLog("Error: ". $error, 4);

            exit;
        }
    }
}
