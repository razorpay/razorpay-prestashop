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

        if (isset($this->context->getContext()->cart->id) === false and
            is_numeric($_REQUEST['cart_id']) === true)
        {
            $this->context->getContext()->cart = new Cart($_REQUEST['cart_id']);
            $this->context->getContext()->customer = new Customer($this->context->getContext()->cart->id_customer);
        }

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

                if (isset($_SESSION['rzp_order_id']) === false)
                {
                    $db = \Db::getInstance();
                    $request = "SELECT `rzp_order_id` FROM `razorpay_sales_order` WHERE `cart_id` = " . $_REQUEST['cart_id'];
                    $rzp_order_id = $db->getValue($request);
                    $attributes['razorpay_order_id'] = $rzp_order_id;
                }

                $api->utility->verifyPaymentSignature($attributes);
            }
            catch(\Razorpay\Api\Errors\SignatureVerificationError $e)
            {
                $error = $e->getMessage();
                Logger::addLog("Payment Failed for Order# ".$cart->id.". Razorpay payment id: ".$paymentId. "Error: ". $error, 4);

                echo 'Error! Please contact the seller directly for assistance.</br>';
                echo 'Order Id: '.$cart->id.'</br>';
                echo 'Razorpay Payment Id: '.$paymentId.'</br>';
                echo 'Error: '.$e->getMessage().'</br>';

                exit;
            }

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

            //add to entry to razorpay_sales_order table
            $db = \Db::getInstance();

            $request = "SELECT `entity_id` FROM `razorpay_sales_order` WHERE `cart_id` = " . $cart->id .
                " AND `rzp_order_id` = '" . $_SESSION['rzp_order_id'] ."'";

            $order_sales_id = $db->getValue($request);

            $amount_paid = number_format($payment->amount/100, "2", ".", "");

            if(empty($order_sales_id) === false)
            {
                $request =  "UPDATE `razorpay_sales_order`
                            SET `rzp_payment_id` = '$paymentId',
                            `amount_paid` = $amount_paid,
                            `order_id` = " . $this->module->currentOrder . ",
                            `by_frontend` = 1,
                            `order_placed` = 1
                            WHERE `cart_id` = " . $cart->id . " AND `entity_id` = $order_sales_id";

                $result = $db->execute($request);

                Logger::addLog("Record inserted in razorpay_sales_order table ", 4);
            }

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