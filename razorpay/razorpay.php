<?php

class razorpay extends PaymentModule
{
    private $_html = '';
    private $_postErrors = array();

    public function __construct()
    {
        $this->name = 'razorpay';
        $this->displayName = 'Razorpay';
        $this->tab = 'payments_gateways';
        $this->version = 1.1;

        $config = Configuration::getMultiple(array('RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET'));

        if (isset($config['RAZORPAY_KEY_ID']))
            $this->KEY_ID = $config['RAZORPAY_KEY_ID'];
        if (isset($config['RAZORPAY_KEY_SECRET']))
            $this->KEY_SECRET = $config['RAZORPAY_KEY_SECRET'];

        parent::__construct();

        /* The parent construct is required for translations */
        $this->page = basename(__FILE__, '.php');
        $this->description = $this->l('Accept payments with Razorpay');

        if (!isset($this->KEY_ID) OR !isset($this->KEY_SECRET))
            $this->warning = $this->l('your Razorpay key must be configured in order to use this module correctly');
    }


    function install()
    {
//Call PaymentModule default install function
        parent::install();

//Create Payment Hooks
        $this->registerHook('payment');
        $this->registerHook('paymentReturn');

    }


    function uninstall()
    {
        Configuration::deleteByName('RAZORPAY_KEY_ID');
        Configuration::deleteByName('RAZORPAY_KEY_SECRET');
        parent::uninstall();
    }


    function getContent()
    {
        $this->_html = '<h2>'.$this->displayName.'</h2>';

        if (!empty($_POST))
        {
            $this->_postValidation();
            if (!sizeof($this->_postErrors))
                $this->_postProcess();
            else
                foreach ($this->_postErrors AS $err)
                    $this->_html .= "<div class='alert error'>{$err}</div>";
        }
        else
        {
            $this->_html .= "<br />";
        }

        $this->_displayrazorpay();
        $this->_displayForm();

        return $this->_html;
    }

    function execPayment($cart)
    {
        $delivery = new Address(intval($cart->id_address_delivery));
        $invoice = new Address(intval($cart->id_address_invoice));
        $customer = new Customer(intval($cart->id_customer));

        global $cookie, $smarty;

        //Verify currencies and display payment form
        $cart_details = $cart->getSummaryDetails(null, true);
        $currencies = Currency::getCurrencies();
        
        $order_currency = '';

        foreach ($currencies as $key => $currency) {
            if ($currency['id_currency'] == $cart->id_currency) {
                $order_currency = $currency['iso_code'];
            }
        }

        $CheckoutUrl        = 'https://checkout.razorpay.com/v1/checkout.js';
        $key_id             = Configuration::get('RAZORPAY_KEY_ID');
        $amount             = number_format($cart->getOrderTotal(true, 3), 2, '.', '')*100;
        $cart_order_id      = $cart->id;
        $email              = $customer->email;
        
        // Invoice Parameters
        $card_holder_name       = $invoice->firstname . ' ' . $invoice->lastname;
        $phone                  = $invoice->phone;
        
       $smarty->assign(array(
            'CheckoutUrl'           => $CheckoutUrl,
            'key_id'                   => $key_id,
            'name'                  => Configuration::get('PS_SHOP_NAME'),
            'amount'                 => $amount,
            'cart_order_id'         => $cart_order_id,
            'email'                 => $email,
            'card_holder_name'  => $card_holder_name,
            'phone'             => $phone,
            'currency_code'     => $order_currency,
            'total'       => $total,
            'return_url'     => __PS_BASE_URI__."?fc=module&module=razorpay&controller=validation"));


        return $this->display(__FILE__, 'payment_execution.tpl');
    }


    function hookPayment($params)
    {
        global $smarty;
        $smarty->assign(array(
        'this_path'         => $this->_path,
        'this_path_ssl'     => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/"));

        return $this->display(__FILE__, 'payment.tpl');
    }


    function hookPaymentReturn($params)
    {
        global $smarty;
        $state = $params['objOrder']->getCurrentState();
        if ($state == _PS_OS_OUTOFSTOCK_ or $state == _PS_OS_PAYMENT_)
            $smarty->assign(array(
                'total_to_pay'  => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false, false),
                'status'        => 'ok',
                'id_order'      => $params['objOrder']->id
            ));
        else
            $smarty->assign('status', 'failed');

        return $this->display(__FILE__, 'payment_return.tpl');
    }


    private function _postValidation()
    {
        if (isset($_POST['btnSubmit']))
        {
            if (empty($_POST['KEY_ID']))
                $this->_postErrors[] = $this->l('Your Key Id is required.');
            if (empty($_POST['KEY_SECRET']))
                $this->_postErrors[] = $this->l('Your Key Secret is required.');
        }
    }




    private function _postProcess()
    {
        if (isset($_POST['btnSubmit']))
        {
            Configuration::updateValue('RAZORPAY_KEY_ID', $_POST['KEY_ID']);
            Configuration::updateValue('RAZORPAY_KEY_SECRET', $_POST['KEY_SECRET']);
            $this->KEY_ID= $_POST['KEY_ID'];
            $this->KEY_SECRET= $_POST['KEY_SECRET'];
        }
        
        $ok = $this->l('Ok');
        $updated = $this->l('Settings Updated');
        $this->_html .= "<div class='conf confirm'><img src='../img/admin/ok.gif' alt='{$ok}' />{$updated}</div>";
    }




    private function _displayrazorpay()
    {
        $modDesc    = $this->l('This module allows you to accept payments using Razorpay.');
        $modStatus  = $this->l('Razorpay online payment service is the right solution for you if you are accepting payments in INR');
        $modconfirm = $this->l('');
        $this->_html .= "<img src='../modules/razorpay/logo.png' style='float:left; margin-right:15px;' />
                                        <b>{$modDesc}</b>
                                        <br />
                                        <br />
                                        {$modStatus}
                                        <br />
                                        {$modconfirm}
                                        <br />
                                        <br />
                                        <br />";
    }




    private function _displayForm()
    {
        $modrazorpay                = $this->l('Razorpay Setup');
        $modrazorpayDesc        = $this->l('Please specify the Razorpay key id and key secret.');
        $modClientLabelKeyId      = $this->l('Razorpay Key Id');
        $modClientValueKeyId      = $this->KEY_ID;
        $modClientLabelKeySecret       = $this->l('Razorpay Key Secret');
        $modClientValueKeySecret       = $this->KEY_SECRET;
        $modUpdateSettings      = $this->l('Update settings');
        $this->_html .=
        "
        <br />
        <br />
        <p><form action='{$_SERVER['REQUEST_URI']}' method='post'>
                <fieldset>
                <legend><img src='../img/admin/access.png' />{$modrazorpay}</legend>
                        <table border='0' width='500' cellpadding='0' cellspacing='0' id='form'>
                                <tr>
                                        <td colspan='2'>
                                                {$modrazorpayDesc}<br /><br />
                                        </td>
                                </tr>
                                <tr>
                                        <td width='130'>{$modClientLabelKeyId}</td>
                                        <td>
                                                <input type='text' name='KEY_ID' value='{$modClientValueKeyId}' style='width: 300px;' />
                                        </td>
                                </tr>
                                <tr>
                                        <td width='130'>{$modClientLabelKeySecret}</td>
                                        <td>
                                                <input type='text' name='KEY_SECRET' value='{$modClientValueKeySecret}' style='width: 300px;' />
                                        </td>
                                </tr>
                                <tr>
                                        <td colspan='2' align='center'>
                                                <input class='button' name='btnSubmit' value='{$modUpdateSettings}' type='submit' />
                                        </td>
                                </tr>
                        </table>
                </fieldset>
        </form>
        </p>
        <br />";
    }
}

?>