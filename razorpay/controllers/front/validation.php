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

        $paymentId = $_REQUEST['razorpay_payment_id'];

        $cart = $this->context->cart;

        if (($cart->id_customer === 0) or
            ($cart->id_address_delivery === 0) or
            ($cart->id_address_invoice === 0) or
            (!$this->module->active))
        {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $authorized = false;

        // Edge case when payment method is disabled while payment in progress
        foreach (Module::getPaymentModules() as $module)
        {
            if ($module['name'] == 'razorpay')
            {
                $authorized = true;
                break;
            }
        }
        if (!$authorized)
        {
            die($this->module->getTranslator()->trans('This payment method is not available.', array(), 'Modules.Razorpay.Shop'));
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer))
        {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;

        $total = (string) intval($cart->getOrderTotal(true, Cart::BOTH) * 100);

        $api = new Api($key_id, $key_secret);

        $api->setAppDetails('Prestashop', $this->module->version);

        try
        {
            $payment = $api->payment->fetch($paymentId);

            //validate Rzp signature
            try
            {
                session_start();
                $attributes = array(
                    'razorpay_order_id' => $_SESSION['rzp_order_id'],
                    'razorpay_payment_id' => $_REQUEST['razorpay_payment_id'],
                    'razorpay_signature' => $_POST['razorpay_signature']
                );

                $api->utility->verifyPaymentSignature($attributes);
            }
            catch(SignatureVerificationError $e)
            {

                Logger::addLog("Payment Failed for Order# ".$cart->id.". Razorpay payment id: ".$paymentId. "Error: ". $error, 4);

                echo 'Error! Please contact the seller directly for assistance.</br>';
                echo 'Order Id: '.$cart->id.'</br>';
                echo 'Razorpay Payment Id: '.$paymentId.'</br>';
                echo 'Error: '.$e->getMessage().'</br>';

                exit;
            }


            $payment->capture(['amount' => $total, 'currency' => $currency->iso_code]);

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
            $extraData = array(
                'transaction_id'    =>  $payment->id,
            );

            // So netbanking becomes razorpay.netbanking
            $method = "razorpay.{$payment->method}";

            $ret = $this->module->validateOrder(
                $cart->id,
                (int) Configuration::get('PS_OS_PAYMENT'),
                $cart->getOrderTotal(true, Cart::BOTH),
                $method,
                'Payment by Razorpay using ' . $payment->method,
                $extraData,
                NULL,
                false,
                $customer->secure_key
            );

            Logger::addLog("Payment Successful for Order#".$cart->id.". Razorpay payment id: ".$paymentId . "Ret=" . (int)$ret, 1);

            $query = http_build_query([
                'controller'    => 'order-confirmation',
                'id_cart'       => (int) $cart->id,
                'id_module'     => (int) $this->module->id,
                'id_order'      => $this->module->currentOrder,
                'key'           => $customer->secure_key,
            ], '', '&');

            $url = 'index.php?' . $query;

            Tools::redirect($url);
        }
        catch(\Razorpay\Api\Errors\BadRequestError $e)
        {
            $error = $e->getMessage();
            Logger::addLog("Payment Failed for Order# ".$cart->id.". Razorpay payment id: ".$paymentId. "Error: ". $error, 4);

            echo 'Error! Please contact the seller directly for assistance.</br>';
            echo 'Order Id: '.$cart->id.'</br>';
            echo 'Razorpay Payment Id: '.$paymentId.'</br>';
            echo 'Error: '.$error.'</br>';

            exit;
        }
    }
}
