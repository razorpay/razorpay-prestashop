<?php
require_once __DIR__.'/../../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;

class RazorpayValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {

        $key_id            = Configuration::get('RAZORPAY_KEY_ID');
        $key_secret        = Configuration::get('RAZORPAY_KEY_SECRET');
        $razorpay_payment_id = $_REQUEST['razorpay_payment_id'];
        $razorpay_signature = $_REQUEST['razorpay_signature'];
        $cart_id        = $_REQUEST['merchant_order_id'];

        $cart = new Cart($cart_id);

        $razorpay = new Razorpay();

        $amount = number_format($cart->getOrderTotal(true, 3), 2, '.', '')*100;

        $success = false;
        $error = "";

        $api = new Api($key_id, $key_secret);

        $api->setAppDetails('Prestashop', $this->module->version);

        //validate Rzp signature
        try
        {
            session_start();
            $attributes = array(
                'razorpay_order_id' => $_SESSION['rzp_order_id'],
                'razorpay_payment_id' => $razorpay_payment_id,
                'razorpay_signature' => $razorpay_signature
            );

            $api->utility->verifyPaymentSignature($attributes);
            $success = true;
        }
        catch(\Razorpay\Api\Errors\SignatureVerificationError $e)
        {   
            $success = false;
            Logger::addLog("Payment Failed for Order# ".$cart->id.". Razorpay payment id: ".$razorpay_payment_id. "Error: ". $error, 4);

            echo 'Error! Please contact the seller directly for assistance.</br>';
            echo 'Order Id: '.$cart->id.'</br>';
            echo 'Razorpay Payment Id: '.$razorpay_payment_id.'</br>';
            echo 'Error: '.$e->getMessage().'</br>';
        }

        if ($success === true) {
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
        }
    }
}
