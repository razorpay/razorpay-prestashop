<?php

require_once __DIR__.'/razorpay-sdk/Razorpay.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShop\PrestaShop\Adapter\Cart\CartPresenter;
use Razorpay\Api\Api;

class Razorpay extends PaymentModule
{
    // Manages the display in the admin panel
    private $_html         = '';
    private $KEY_ID        = null;
    private $KEY_SECRET    = null;
    public $ENABLE_WEBHOOK = null;
    public $WEBHOOK_SECRET = null;

    private $_postErrors   = [];

    const RAZORPAY_CHECKOUT_URL = 'https://checkout.razorpay.com/v1/checkout.js';
    const CAPTURE               = 'capture';
    const AUTHORIZE             = 'authorize';

    /**
     * Event constants
     */
    const PAYMENT_AUTHORIZED    = 'payment.authorized';
    const PAYMENT_FAILED        = 'payment.failed';
    const ORDER_PAID            = 'order.paid';


    protected $webhookSupportedEvents = [
        'payment.authorized',
        'payment.failed',
        'order.paid',
    ];

    protected $webhookEvents = [
        'payment.authorized' => true,
        'order.paid'         => true,
    ];

    public function __construct()
    {
        $this->controllers            = ['validation'];
        $this->name                   = 'razorpay';
        $this->displayName            = 'Razorpay';
        $this->tab                    = 'payments_gateways';
        $this->version                = '2.5.2';
        $this->need_instance          = 1;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->display                = true;

        $this->author                 = 'Team Razorpay';
        $this->module_key             = '084fe8aecafea8b2f84cca493377eb9b';

        $config = Configuration::getMultiple([
            'RAZORPAY_KEY_ID',
            'RAZORPAY_KEY_SECRET',
            'RAZORPAY_PAYMENT_ACTION',
            'ENABLE_RAZORPAY_WEBHOOK',
            'RAZORPAY_WEBHOOK_EVENTS',
            'RAZORPAY_WEBHOOK_SECRET',
            'RAZORPAY_WEBHOOK_WAIT_TIME',
            'RAZORPAY_WEBHOOK_LAST_VERIFY'
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

        if (array_key_exists('RAZORPAY_WEBHOOK_EVENTS', $config))
        {
            $this->RAZORPAY_WEBHOOK_EVENTS = $config['RAZORPAY_WEBHOOK_EVENTS'];
        }

        if (array_key_exists('RAZORPAY_WEBHOOK_SECRET', $config))
        {
            $this->WEBHOOK_SECRET = $config['RAZORPAY_WEBHOOK_SECRET'];
        }

        if (array_key_exists('RAZORPAY_WEBHOOK_WAIT_TIME', $config))
        {
            $this->WEBHOOK_WAIT_TIME = $config['RAZORPAY_WEBHOOK_WAIT_TIME'];
        }

        if (array_key_exists('RAZORPAY_WEBHOOK_LAST_VERIFY', $config))
        {
            $this->WEBHOOK_LAST_VERIFY = $config['RAZORPAY_WEBHOOK_LAST_VERIFY'];
        }

        parent::__construct();

        /* The parent construct is required for translations */
        $this->page = basename(__FILE__, '.php');
        $this->description = $this->l('Accept payments with Razorpay');

        // Both are set to NULL by default
        if ($this->KEY_ID === null OR $this->KEY_SECRET === null)
        {
            $this->warning = $this->l('Your Razorpay key must be configured in order to use this module correctly');
        }
    }

    public function getContent()
    {
        $this->_html = '';

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
                    $this->_html .= "<div class='alert error'>{$err}</div>";
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
        $this->smarty->assign([
            'modrazorpay'                   => $this->l('Razorpay Setup'),
            'modrazorpayDesc'               => $this->l('Please specify the Razorpay Key Id and Key Secret.'),
            'modClientLabelKeyId'           => $this->l('Razorpay Key Id'),
            'modClientLabelKeySecret'       => $this->l('Razorpay Key Secret'),
            'modClientValueKeyId'           => $this->KEY_ID,
            'modClientValueKeySecret'       => $this->KEY_SECRET,
            'modUpdateSettings'             => $this->l('Update settings'),
            'modWebhookSecret'              => $this->WEBHOOK_SECRET,
            'modWebhookWaitTime'            => $this->WEBHOOK_WAIT_TIME ? $this->WEBHOOK_WAIT_TIME : 300,
            'modPayActionCaptureLabel'      => $this->l('Authorize and Capture'),
            'modPayActionAuthorizeLabel'    => $this->l('Authorize'),
            'modPayActionCapture'           => self::CAPTURE,
            'modPayActionAuthorize'         => self::AUTHORIZE,

            'modPayActionCaptureSelected'   => ($this->PAYMENT_ACTION === self::CAPTURE || !$this->PAYMENT_ACTION) ? "selected = 'selected'" : "",

            'modPayActionAuthorizeSelected' => ($this->PAYMENT_ACTION === self::AUTHORIZE) ? "selected = 'selected'" : "",

            'modOrderPaidEvent'             => self::ORDER_PAID,

            'webhookUrl'                    => $this->context->link->getModuleLink('razorpay', 'webhook', [], true),
        ]);

        $this->_html .= $this->fetch('module:razorpay/views/templates/admin/admin.tpl');
    }

    public function install()
    {
        $db = \Db::getInstance();

        $result = $db->execute("
            CREATE TABLE  IF NOT EXISTS `razorpay_sales_order` (
          `entity_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity_id',
          `cart_id` int(11) DEFAULT NULL COMMENT 'cart_id',
          `order_id` int(11) DEFAULT NULL COMMENT 'Order_id',
          `rzp_order_id` varchar(25) DEFAULT NULL COMMENT 'Rzp_order_id',
          `rzp_payment_id` varchar(25) DEFAULT NULL COMMENT 'Rzp_payment_id',
          `amount_paid` decimal(20,2) NOT NULL,
          `by_webhook` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'By_webhook',
          `by_frontend` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'By_frontend',
          `webhook_count` smallint(6) NOT NULL DEFAULT '0' COMMENT 'Webhook_count',
          `order_placed` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Order_placed',
          `webhook_first_notified_at` bigint(20) DEFAULT NULL COMMENT 'Webhook_first_notified_at',
          PRIMARY KEY (`entity_id`),
          UNIQUE KEY `cart_id` (`cart_id`,`rzp_payment_id`),
          UNIQUE KEY `order_id` (`order_id`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8 COMMENT='razorpay_sales_order'
        ");


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
        Configuration::deleteByName('RAZORPAY_WEBHOOK_EVENTS');
        Configuration::deleteByName('RAZORPAY_WEBHOOK_SECRET');
        Configuration::deleteByName('RAZORPAY_WEBHOOK_WAIT_TIME');

        $db = \Db::getInstance();

        $result = $db->execute("DROP TABLE IF EXISTS `razorpay_sales_order`");

        return parent::uninstall();
    }

    public function hookPaymentOptions($params)
    {
        $option = new PaymentOption();

        $method_logo = "https://cdn.razorpay.com/static/assets/logo/payment_method.svg";

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
                $this->_postErrors[] = $this->displayError($this->trans('Your Key Id is required.', array(), 'Admin.Notifications.Error'));
            }
            if (empty($keySecret))
            {
                $this->_postErrors[] = $this->displayError($this->trans('Your Key Secret is required.', array(), 'Admin.Notifications.Error'));
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
            Configuration::updateValue('ENABLE_RAZORPAY_WEBHOOK', true);
            Configuration::updateValue('RAZORPAY_WEBHOOK_EVENTS', Tools::getValue('EVENTS'));

            //default is 300 seconds
            $webhookWaitTime = Tools::getValue('WEBHOOK_WAIT_TIME') ? Tools::getValue('WEBHOOK_WAIT_TIME') : 300;
            Configuration::updateValue('RAZORPAY_WEBHOOK_WAIT_TIME', $webhookWaitTime);

            $this->KEY_ID               = Tools::getValue('KEY_ID');
            $this->KEY_SECRET           = Tools::getValue('KEY_SECRET');
            $this->PAYMENT_ACTION       = Tools::getValue('PAYMENT_ACTION');

            $this->WEBHOOK_WAIT_TIME    = $webhookWaitTime;

            $this->autoEnableWebhook();
        }

        $this->_html .= $this->displayConfirmation($this->trans('Settings updated', array(), 'Admin.Notifications.Success'));
    }

    protected function createWebhookSecret()
    {
        $secretGenString = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-=~!@#$%^&*()_+,./<>?;:[]{}|abcdefghijklmnopqrstuvwxyz';

        return substr(str_shuffle($secretGenString), 0, 20);
    }

    public function autoEnableWebhook()
    {
        $webhookExist = false;
        $webhookUrl   = $this->context->link->getModuleLink('razorpay', 'webhook', [], true);

        $webhookSecret = Configuration::get('RAZORPAY_WEBHOOK_SECRET') ? Configuration::get('RAZORPAY_WEBHOOK_SECRET') : $this->createWebhookSecret();

        $this->WEBHOOK_SECRET = $webhookSecret;

        Configuration::updateValue('RAZORPAY_WEBHOOK_SECRET', $webhookSecret);

        $prepareEventsData = [];

        $domain = parse_url($webhookUrl, PHP_URL_HOST);

        $domain_ip = gethostbyname($domain);

        if (!filter_var($domain_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
        {
            Configuration::updateValue('ENABLE_RAZORPAY_WEBHOOK', 'off');

            Logger::addLog('Could not enable webhook for localhost', 3);

            return;
        }

        $this->ENABLE_WEBHOOK = true;

        $skipCount  = 0;
        $count      = 10;

        do
        {
            $webhook = $this->webhookAPI('GET', 'webhooks?count='. $count . '&skip=' . $skipCount);

            $skipCount += $count;

            if ((isset($webhook['count']) === true) and
                ($webhook['count'] > 0))
            {
                foreach ($webhook['items'] as $key => $value)
                {
                    if ($value['url'] === $webhookUrl)
                    {
                        $webhookExist  = true;
                        $webhookId     = $value['id'];

                        foreach($value['events'] as $event => $enabled)
                        {
                            if (($enabled === true) and
                                (in_array($event, $this->webhookSupportedEvents) === true))
                            {
                                $this->webhookEvents[$event] = true;
                            }
                        }

                        break;
                    }
                }
            }

        }
        while ( $webhook['count'] === $count);

        $data = [
            'url'    => $webhookUrl,
            'active' => true,
            'events' => $this->webhookEvents,
            'secret' => $this->WEBHOOK_SECRET,
        ];
        
        if($webhookExist)
        {
            $this->webhookAPI('PUT', "webhooks/".$webhookId, $data);
        }
        else
        {
            $this->webhookAPI('POST', "webhooks/", $data);
        }

        Configuration::updateValue('RAZORPAY_WEBHOOK_LAST_VERIFY', time());

        Configuration::updateValue('ENABLE_RAZORPAY_WEBHOOK', 'on');
        
    }

    protected function webhookAPI($method, $url, $data = array())
    {
        $webhook = [];

        try
        {
            $api = $this->getRazorpayApiInstance();

            $webhook = $api->request->request($method, $url, $data);
        }
        catch(Exception $e)
        {
            Logger::addLog($e->getMessage(), 4);
        }

        return $webhook;
    }

    private function _displayrazorpay()
    {
        $modDesc    = $this->l('This module allows you to accept payments using Razorpay.');
        $modStatus  = $this->l('Razorpay online payment service is the right solution for you if you are accepting payments in INR');
        $modconfirm = $this->l('');
        $this->_html .= "<img src='https://cdn.razorpay.com/logo.svg' style='float:left; margin-right:15px;' />
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
