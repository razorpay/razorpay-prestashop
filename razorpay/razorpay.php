<?php

require_once __DIR__.'/razorpay-sdk/Razorpay.php';
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShop\PrestaShop\Adapter\Cart\CartPresenter;
use Razorpay\Api\Api;

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
    const CAPTURE = 'capture';
    const AUTHORIZE = 'authorize';

    public function __construct()
    {
        $this->controllers = ['validation'];
        $this->name = 'razorpay';
        $this->displayName = 'Razorpay';
        $this->tab = 'payments_gateways';
        $this->version = '2.3.5';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->display = true;

        $this->author = 'Team Razorpay';
        $this->module_key = '084fe8aecafea8b2f84cca493377eb9b';

        $config = Configuration::getMultiple([
            'RAZORPAY_KEY_ID',
            'RAZORPAY_KEY_SECRET',
            'RAZORPAY_PAYMENT_ACTION',
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

        if (array_key_exists('RAZORPAY_PAYMENT_ACTION', $config))
        {
            $this->PAYMENT_ACTION = $config['RAZORPAY_PAYMENT_ACTION'];
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

        $modPayActionCaptureLabel = $this->l('Authorize and Capture');
        $modPayActionAuthorizeLabel = $this->l('Authorize');
        $modPayActionCapture = self::CAPTURE;
        $modPayActionAuthorize = self::AUTHORIZE;
        $modPayActionCaptureSelected = ($this->PAYMENT_ACTION === self::CAPTURE || !$this->PAYMENT_ACTION) ? "selected = 'selected'" : "";
        $modPayActionAuthorizeSelected = ($this->PAYMENT_ACTION === self::AUTHORIZE) ? "selected = 'selected'" : "";

        $webhookUrl = $this->context->link->getModuleLink('razorpay', 'webhook', [], true);

        $modWebhookDescription = $this->l('Enable Razorpay Webhook at https://dashboard.razorpay.com/#/app/webhooks with the URL '. $webhookUrl);
        $modWebhookSecretDescription = $this->l('Webhook secret is used for webhook signature verification. This has to match the one added at https://dashboard.razorpay.com/#/app/webhooks');

        $this->_html .=
        "
        <br />
        <br />
        <p><form action='{$_SERVER['REQUEST_URI']}' method='post'>
                <fieldset>
                <legend><img src='../img/admin/edit.gif' />{$modrazorpay}</legend>
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
                                        <td width='130'>Payment Action</td>
                                        <td>
                                                <select name='PAYMENT_ACTION' style='margin:3px 0px;'>
                                                    <option value='{$modPayActionAuthorize}' $modPayActionAuthorizeSelected >{$modPayActionAuthorizeLabel}</option>
                                                    <option value='{$modPayActionCapture}' $modPayActionCaptureSelected >{$modPayActionCaptureLabel}</option>
                                                </selet>
                                        </td>
                                </tr>
                                <tr>
                                        <td width='130' title='{$modWebhookDescription}'>{$modEnableWebhookLabel}</td>
                                        <td>
                                                <input type='checkbox' name='ENABLE_WEBHOOK' style='width: 300px;' {$modEnableWebhook} />
                                        </td>
                                </tr>
                                <tr>
                                        <td width='130'>Webhook Url</td>
                                        <td style='padding:5px 0;'>
                                            <span style='width:300px;font-weight: bold;' class='webhook-url' >{$webhookUrl}</span>
                                            <span class='copy-to-clipboard'
                                            style='background-color: #337ab7; color: white; border: none;cursor: pointer; padding: 2px 4px; text-decoration: none;'>Copy</span>
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
        <br />
        <script type='text/javascript'>
            $(function() {
                $('.copy-to-clipboard').click(function() {
                    var copyText = document.createElement('input');
                    copyText.type = 'text';
                    document.body.appendChild(copyText);
                    copyText.style = 'display: inline; width: 1px;';
                    copyText.value = $('.webhook-url').text();
                    copyText.focus();
                    copyText.select();
                    document.execCommand('Copy');
                    copyText.remove();
                    $('.copy-to-clipboard').text('Webhook url copied to clipboard.');
                });
            });
        </script>";
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

            $amount = number_format(($this->context->cart->getOrderTotal() * 100), 0, "", "");
            $rzp_order_id = "";

            $order_controller = $this->context->link->getModuleLink('razorpay', 'order', [], true);
            $action_controller = $this->context->link->getModuleLink('razorpay', 'validation', [], true);

            Media::addJsDef([
                'razorpay_checkout_vars'    =>  [
                    'key'               => $this->KEY_ID,
                    'name'              => Configuration::get('PS_SHOP_NAME'),
                    'cart'              => $cart_presenter->present($this->context->cart),
                    'amount'            => $amount,
                    'cart_id'           => $this->context->cart->id,
                    'rzp_order_id'      => $rzp_order_id,
                    'ps_version'        => _PS_VERSION_,
                    'module_version'    => $this->version,
                    'order_controller'  => $order_controller,
                    'action_controller' => $action_controller,
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

                    //update the Razorpay payment with corresponding created order ID of this cart ID
                    try{
                        $this->getRazorpayApiInstance()->payment->fetch($paymentId)->edit(array('notes' => array('prestashop_order_id' => $order->id, 'prestashop_cart_id'=>$order->id_cart)));

                    } catch (\Razorpay\Api\Errors\BadRequestError $e){
                        $error = $e->getMessage();
                        Logger::addLog("Razorpay payment notes update failed with the error ".$error, 4);
                    }

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
        Configuration::deleteByName('RAZORPAY_PAYMENT_ACTION');
        Configuration::deleteByName('ENABLE_RAZORPAY_WEBHOOK');
        Configuration::deleteByName('RAZORPAY_WEBHOOK_SECRET');

        return parent::uninstall();
    }

    public function hookPaymentOptions($params)
    {
        $option = new PaymentOption();

        $method_logo = $this->context->link->getBaseLink().'modules/razorpay/methods.png';

        $option->setModuleName('razorpay')
                ->setLogo($method_logo)
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
            Configuration::updateValue('RAZORPAY_PAYMENT_ACTION', Tools::getValue('PAYMENT_ACTION'));
            Configuration::updateValue('ENABLE_RAZORPAY_WEBHOOK', Tools::getValue('ENABLE_WEBHOOK'));
            Configuration::updateValue('RAZORPAY_WEBHOOK_SECRET', Tools::getValue('WEBHOOK_SECRET'));

            $this->KEY_ID= Tools::getValue('KEY_ID');
            $this->KEY_SECRET= Tools::getValue('KEY_SECRET');
            $this->PAYMENT_ACTION= Tools::getValue('PAYMENT_ACTION');
            $this->ENABLE_WEBHOOK= Tools::getValue('ENABLE_WEBHOOK');
            $this->WEBHOOK_SECRET= Tools::getValue('WEBHOOK_SECRET');
        }

        $this->_html .= $this->displayConfirmation($this->trans('Settings updated', array(), 'Admin.Notifications.Success'));
    }

    private function _displayrazorpay()
    {
        $modDesc    = $this->l('This module allows you to accept payments using Razorpay.');
        $modStatus  = $this->l('Razorpay online payment service is the right solution for you if you are accepting payments in INR');
        $modconfirm = $this->l('');
        $this->_html .= "<img src='https://cdn.razorpay.com/logo.svg' style='float:left; margin-right:15px;' />
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
