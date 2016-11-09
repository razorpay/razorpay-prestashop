<?php

require_once __DIR__.'/../../razorpay-sdk/Razorpay.php';
use Razorpay\Api\Api;

class RazorpayValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        global $cookie;
        
        $key_id            = Configuration::get('RAZORPAY_KEY_ID');
        $key_secret        = Configuration::get('RAZORPAY_KEY_SECRET');
        $razorpay_payment_id = $_REQUEST['razorpay_payment_id'];
        $razorpay_order_id = $cookie->razorpay_order_id;
        $razorpay_signature = $_REQUEST['razorpay_signature'];
        $cart_id        = $_REQUEST['merchant_order_id'];

        $cart = new Cart($cart_id);

        $razorpay = new Razorpay();

        $amount = number_format($cart->getOrderTotal(true, 3), 2, '.', '')*100;

        $api = new Api($key_id, $key_secret);

        $success = false;
        $error = "";
        $captured = false;


        // Orders API to be implemented here
        try 
        {
            $signature = hash_hmac('sha256', $razorpay_order_id . '|' . $razorpay_payment_id, $key_secret);

            if (hash_equals($signature , $razorpay_signature))
            {
                $captured = true;;
            }
        
            //Check success response
            if ($captured)
            {
                $success = true;
            }

            else
            {
                $success = false;

                $error = "PAYMENT_ERROR = Payment failed";
            }
        }
        catch (Exception $e) {
            $success = false;
            $error ="PRESTASHOP_ERROR: Request to Razorpay Failed";
        }

        if ($success == true) {
            $customer = new Customer($cart->id_customer);
            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
            $razorpay->validateOrder($cart_id, _PS_OS_PAYMENT_, $total, $razorpay->displayName,  '', array(), NULL, false, $customer->secure_key);

            Logger::addLog("Payment Successful for Order#".$cart_id.". Razorpay payment id:".$razorpay_payment_id, 1);

            $query = http_build_query(array(
                'controller'    =>  'order-confirmation',
                'id_cart'       =>  (int) $cart->id,
                'id_module'     =>  (int) $this->module->id,
                'id_order'      =>  $razorpay->currentOrder
            ), '', '&');

            $url = 'index.php?' . $query;

            Tools::redirect($url);
        } else {
            Logger::addLog("Payment Failed for Order# ".$cart_id.". Razorpay payment id:".$razorpay_payment_id. "Error: ".$error, 4);
            echo 'Error! Please contact the seller directly for assistance.</br>';
            echo 'Order Id: '.$cart_id.'</br>';
            echo 'Razorpay Payment Id: '.$razorpay_payment_id.'</br>';
            echo 'Error: '.$error.'</br>';
        }
    }
}
