<?php

require_once __DIR__.'/razorpay.php';
require_once __DIR__.'/razorpay-sdk/Razorpay.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShop\PrestaShop\Adapter\Cart\CartPresenter;
use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class Razorpay extends PaymentModule
{
    // Manages the display in the admin panel
    private $_html = '';
    private $KEY_ID = null;
    private $KEY_SECRET = null;
    public $ENABLE_WEBHOOK = null;
    public $WEBHOOK_SECRET = null;

    private $_postErrors = [];

    const RAZORPAY_CHECKOUT_URL = 'https://checkout.razorpay.com/v1/checkout.js';

    public function __construct()
    {
        $this->controllers = ['validation'];
        $this->name = 'razorpay';
        $this->displayName = 'Razorpay';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->display = true;

        $this->author = 'Team Razorpay';
        $this->module_key = '084fe8aecafea8b2f84cca493377eb9b';

        $config = Configuration::getMultiple([
            'RAZORPAY_KEY_ID',
            'RAZORPAY_KEY_SECRET',
            'ENABLE_RAZORPAY_WEBHOOK',
            'RAZORPAY_WEBHOOK_SECRET'
        ]);

        if (array_key_exists('RAZORPAY_KEY_ID', $config))
        {
            $this->KEY_ID = $config['RAZORPAY_KEY_ID'];
        }

        if (array_key_exists('RAZORPAY_KEY_SECRET', $config))
        {
            $this->KEY_SECRET = $config['RAZORPAY_KEY_SECRET'];
        }

        if (array_key_exists('ENABLE_RAZORPAY_WEBHOOK', $config))
        {
            $this->ENABLE_WEBHOOK = $config['ENABLE_RAZORPAY_WEBHOOK'];
        }

        if (array_key_exists('RAZORPAY_WEBHOOK_SECRET', $config))
        {
            $this->WEBHOOK_SECRET = $config['RAZORPAY_WEBHOOK_SECRET'];
        }

        parent::__construct();

        /* The parent construct is required for translations */
        $this->page = basename(__FILE__, '.php');
        $this->description = $this->l('Accept payments with Razorpay');

        // Both are set to NULL by default
        if ($this->KEY_ID === null OR $this->KEY_SECRET === null)
        {
            $this->warning = $this->l('your Razorpay key must be configured in order to use this module correctly');
        }
    }

    public function getContent()
    {
        $this->_html = '<h2>'.$this->displayName.'</h2>';
        if (Tools::isSubmit('btnSubmit'))
        {
            $this->_postValidation();
            if (empty($this->_postErrors))
            {
                $this->_postProcess();
            }
            else
            {
                foreach ($this->_postErrors AS $err)
                {
                    $this->_html .= "<div class='alert error'>ERROR: {$err}</div>";
                }
            }
        }
        else
        {
            $this->_html .= "<br />";
        }

        $this->_displayrazorpay();
        $this->_displayForm();

        return $this->_html;
    }

    private function _displayForm()
    {
        $modrazorpay                = $this->l('Razorpay Setup');
        $modrazorpayDesc        = $this->l('Please specify the Razorpay Key Id and Key Secret.');
        $modClientLabelKeyId      = $this->l('Razorpay Key Id');
        $modClientLabelKeySecret       = $this->l('Razorpay Key Secret');
        $modClientValueKeyId      = $this->KEY_ID;
        $modClientValueKeySecret       = $this->KEY_SECRET;
        $modUpdateSettings      = $this->l('Update settings');
        $modEnableWebhook = ($this->ENABLE_WEBHOOK  === 'on') ? 'checked' : '';
        $modWebhookSecret = $this->WEBHOOK_SECRET;
        $modEnableWebhookLabel = $this->l('Enable Webhook');
        $modWebhookSecretLabel = $this->l('Webhook Secret');

        $webhookUrl = __PS_BASE_URI__.'module/razorpay/webhook';

        $modWebhookDescription = $this->l('Enable Razorpay Webhook at https://dashboard.razorpay.com/#/app/webhooks with the URL '. $webhookUrl);
        $modWebhookSecretDescription = $this->l('Webhook secret is used for webhook signature verification. This has to match the one added at https://dashboard.razorpay.com/#/app/webhooks');
        
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
                                        <td width='130' title='{$modWebhookDescription}'>{$modEnableWebhookLabel}</td>
                                        <td>
                                                <input type='checkbox' name='ENABLE_WEBHOOK' style='width: 300px;' {$modEnableWebhook} />
                                        </td>
                                </tr>
                                <tr>
                                        <td width='130' title='{$modWebhookSecretDescription}'>{$modWebhookSecretLabel}</td>
                                        <td>
                                                <input type='text' name='WEBHOOK_SECRET' value='{$modWebhookSecret}' style='width: 300px;'/>
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

    public function install()
    {
        if (parent::install() and
            $this->registerHook('header') and
            $this->registerHook('orderConfirmation') and
            $this->registerHook('paymentOptions') and
            $this->registerHook('paymentReturn'))
        {
            return true;
        }

        return false;
    }

    public function hookHeader()
    {
        if (Tools::getValue('controller') == "order")
        {
            $this->context->controller->registerJavascript(
               'remote-razorpay-checkout',
               self::RAZORPAY_CHECKOUT_URL,
               ['server' => 'remote', 'position' => 'head', 'priority' => 20]
            );

            $this->context->controller->registerJavascript(
                'razorpay-checkout-local-script',
                'modules/' . $this->name . '/script.js',
                ['position' => 'bottom', 'priority' => 30]
            );

            $cart_presenter = new CartPresenter();

            $amount = ($this->context->cart->getOrderTotal() * 100);
            $rzp_order_id = "";

            try{
                $rzp_order  = $this->getRazorpayApiInstance()->order->create(array('amount' => $amount, 'currency' => 'INR','payment_capture'=>'1'));
                $rzp_order_id = $rzp_order->id;
            } catch (\Razorpay\Api\Errors\BadRequestError $e){
                $error = $e->getMessage();
                Logger::addLog("Order creation failed with the error ".$error, 4);
            }


            Media::addJsDef([
                'razorpay_checkout_vars'    =>  [
                    'key'           => $this->KEY_ID,
                    'name'          => Configuration::get('PS_SHOP_NAME'),
                    'cart'          => $cart_presenter->present($this->context->cart),
                    'rzp_order_id'     => $rzp_order_id
                ]
            ]);
        }
    }

    public function hookOrderConfirmation($params)
    {
        $order = $params['order'];

        if ($order)
        {
            if ($order->module === $this->name) {

                $payments = $order->getOrderPayments();

                if (count($payments) >= 1)
                {
                    $payment = $payments[0];
                    $paymentId = $payment->transaction_id;

                    return "Your Razorpay Payment Id is <code>$paymentId</code>";
                }

                return;
            }
        }
    }


    public function uninstall()
    {
        Configuration::deleteByName('RAZORPAY_KEY_ID');
        Configuration::deleteByName('RAZORPAY_KEY_SECRET');
        Configuration::deleteByName('ENABLE_RAZORPAY_WEBHOOK');
        Configuration::deleteByName('RAZORPAY_WEBHOOK_SECRET');

        return parent::uninstall();
    }

    public function hookPaymentOptions($params)
    {
        $option = new PaymentOption();

        $option->setModuleName('razorpay')
                ->setLogo('../modules/razorpay/methods.png')
                ->setAction($this->context->link->getModuleLink('razorpay', 'validation', [], true))
                ->setCallToActionText('Pay by Razorpay')
                ->setAdditionalInformation('<p>Pay using Credit/Debit Card, NetBanking, Wallets, or UPI</p>')
                ;

        return [
            $option,
        ];
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        if ((!isset($params['order'])) or
            ($params['order']->module != $this->name))
        {
            return false;
        }

        if ((isset($params['order'])) and
            (Validate::isLoadedObject($params['order'])) &&
            (isset($params['order']->valid)))
        {
            $this->smarty->assign([
                'id_order'  => $params['order']->id,
                'valid'     => $params['order']->valid,
            ]);
        }

        if ((isset($params['order']->reference)) and
            (!empty($params['order']->reference))) {
            $this->smarty->assign('reference', $params['order']->reference);
        }

        $this->smarty->assign([
            'shop_name'     => $this->context->shop->name,
            'reference'     => $params['order']->reference,
            'contact_url'   => $this->context->link->getPageLink('contact', true),
        ]);

        return $this->fetch('module:razorpay/views/templates/hook/payment_return.tpl');
    }

    private function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit'))
        {
            $keyId = Tools::getValue('KEY_ID');
            $keySecret = Tools::getValue('KEY_SECRET');

            if (empty($keyId))
            {
                $this->_postErrors[] = $this->l('Your Key Id is required.');
            }
            if (empty($keySecret))
            {
                $this->_postErrors[] = $this->l('Your Key Secret is required.');
            }
        }
    }

    private function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit'))
        {
            Configuration::updateValue('RAZORPAY_KEY_ID', Tools::getValue('KEY_ID'));
            Configuration::updateValue('RAZORPAY_KEY_SECRET', Tools::getValue('KEY_SECRET'));
            Configuration::updateValue('ENABLE_RAZORPAY_WEBHOOK', Tools::getValue('ENABLE_WEBHOOK'));
            Configuration::updateValue('RAZORPAY_WEBHOOK_SECRET', Tools::getValue('WEBHOOK_SECRET'));

            $this->KEY_ID= Tools::getValue('KEY_ID');
            $this->KEY_SECRET= Tools::getValue('KEY_SECRET');
            $this->ENABLE_WEBHOOK= Tools::getValue('ENABLE_WEBHOOK');
            $this->WEBHOOK_SECRET= Tools::getValue('WEBHOOK_SECRET');
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

    //Returns Razorpay API instance
    public function getRazorpayApiInstance()
    {
        return new Api($this->KEY_ID, $this->KEY_SECRET);
    }
}

?>
