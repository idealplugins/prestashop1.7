<?php
/**
 * @file    Provides support for TargetPay iDEAL, Bancontact, Sofort Banking, Paysafecard and Creditcard
 * @author  idealplugins
 * @url     http://www.idealplugins.nl
 */
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShop\PrestaShop\Adapter\Order\OrderPresenter;
use Monolog\Logger;

if (! defined('_PS_VERSION_')) {
    exit();
}

require_once ('core/targetpay.class.php');

class Ps_Targetpay extends PaymentModule
{
    
    const DEFAULT_RTLO = 93929;
    
    const DEFAULT_ENABLE_METHOD = 1;
    
    public $listMethods;
    
    public $appId = 'e16cc084dc5a1341f373e016d40ae1b2';
    
    public function __construct()
    {
        $this->setListMethods();
        $this->name = 'ps_targetpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        );
        $this->author = 'DigiWallet';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->limited_currencies = array(
            'EUR'
        );
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('DigiWallet Bank Payments');
        $this->description = $this->l('Let the customer pay with popular payment services such as iDEAL (The Netherlands), Bancontact (Belgium), SOFORT Banking (Germany)');
        if (! count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
    }
    
    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }
        
        Configuration::updateValue('TARGETPAY_RTLO', self::DEFAULT_RTLO); // Default TargetPay
        foreach ($this->listMethods as $id => $method) {
            Configuration::updateValue('ENABLE_METHOD_' . $id, $method['enabled']);
        }
        
        if (! parent::install()
            || ! $this->createTargetpayIdealTable()
            || ! $this->updateTargetpayIdealTable()
            || ! $this->createOrderPartialStatus()
            || ! $this->registerHook('displayHeader')
            || ! $this->registerHook('paymentOptions')
            || ! $this->registerHook('paymentReturn')
            || ! $this->registerHook('actionOrderSlipAdd')  // for refund
            || !$this->registerHook('cancelProduct')
            || Currency::refreshCurrencies()) {
                return false;
            }
            
            return true;
    }
    
    /**
     * Delete config when uninstall
     *
     * @return unknown
     */
    public function uninstall()
    {
        Configuration::deleteByName('TARGETPAY_RTLO');
        Configuration::deleteByName('TARGETPAY_TESTMODE');
        foreach ($this->listMethods as $id => $method) {
            Configuration::deleteByName('ENABLE_METHOD_' . $id);
        }
        
        return parent::uninstall();
    }
    
    /**
     * Function called by install
     * Column Descriptions:
     * id_payment the primary key.
     * order_id : Stores the order number associated with iDEAL
     * paymethod: Stores the paymethod
     * transaction_id: The transaction_id which is retrieved from the API
     * bank_id: The bank identifier
     * description: Description of the payment
     * amount: Decimal of the amount. 1 euro and 10 cents is "1.10"
     * status: init:0, success:1, fail:2
     * via
     */
    public function createTargetpayIdealTable()
    {
        $db = Db::getInstance();
        $query = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "targetpay_ideal` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
            `order_id` int(11) NULL DEFAULT '0',
            `cart_id` int(11) NOT NULL DEFAULT '0',
            `rtlo` int(11) NOT NULL,
            `paymethod` varchar(8) NOT NULL DEFAULT 'IDE',
            `transaction_id` varchar(255) NOT NULL,
            `description` varchar(64) NOT NULL,
            `amount` decimal(11,2) NOT NULL,
            INDEX `IX_tp_transaction_id` (`transaction_id`)
            ) ENGINE = InnoDB ";
        
        $db->Execute($query);
        
        return true;
    }
    
    /**
     * add field
     * @return boolean
     */
    public function updateTargetpayIdealTable()
    {
        $db = Db::getInstance();
        $sql = "SHOW COLUMNS FROM `"._DB_PREFIX_."targetpay_ideal` LIKE 'paid_amount'";
        $results = $db->ExecuteS($sql);
        if (empty($results))
        {
            $db->Execute( "ALTER TABLE `" . _DB_PREFIX_ . "targetpay_ideal` ADD `paid_amount` decimal(11,2) NOT NULL DEFAULT '0' AFTER `paymethod`;");
        }
        return true;
    }

    /**
     * @return bool
     */
    public function createOrderPartialStatus()
    {
        $db = Db::getInstance();
        $query = '
            INSERT INTO `' . _DB_PREFIX_ . 'order_state`
            SET 
                `invoice` = 1,
                `send_email` = 0,
                `module_name` = "digiwallet_bankwire_partial",
                `color` = "blue",
                `unremovable` = 1,
                `logable` = 1,
                `paid` = 1
        ';
        $db->Execute($query);
        $statusID = $db->Insert_ID();
        foreach (Language::getLanguages() as $language) {
            $query = sprintf('
                INSERT INTO `' . _DB_PREFIX_ . 'order_state_lang`
                SET 
                    `id_order_state` = %d,
                    `id_lang` = %d,
                    `name` = "Partial Payment Received",
                    `template` = "bankwire"
                ',
                $statusID,
                $language['id_lang']
            );
            $db->Execute($query);
        }

        return true;
    }
    
    /* admin configuration settings */
    /**
     * Admin configuration settings
     *
     * @return string
     */
    public function getContent()
    {
        $output = null;
        
        if (Tools::isSubmit('submit' . $this->name)) {
            $RTLO = strval(Tools::getValue('TARGETPAY_RTLO'));
            $Token = strval(Tools::getValue('TARGETPAY_TOKEN'));
            if (! $RTLO || empty($RTLO) || ! Validate::isGenericName($RTLO) || ! Validate::isUnsignedInt($RTLO)) {
                $output .= $this->displayError($this->l('Invalid RTLO. Only numbers allowed.'));
            } else {
                Configuration::updateValue('TARGETPAY_RTLO', $RTLO);
                Configuration::updateValue('TARGETPAY_TOKEN', $Token);
                $TEST = strval(Tools::getValue('TARGETPAY_TESTMODE'));
                Configuration::updateValue('TARGETPAY_TESTMODE', ($TEST == 1) ? '1' : '0');
                foreach ($this->listMethods as $id => $method) {
                    $value = strval(Tools::getValue('ENABLE_METHOD_' . $id));
                    Configuration::updateValue('ENABLE_METHOD_' . $id, ($value == 1) ? '1' : '0');
                }
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        
        return $output . $this->displayForm();
    }
    
    /**
     * Build config form
     *
     * @return string
     */
    private function displayForm()
    {
        // Get default Language
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        
        $helper = new HelperForm();
        
        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        
        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true; // false -> remove toolbar
        $helper->toolbar_scroll = true; // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules')
            ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );
        
        // Load current value
        $helper->fields_value['TARGETPAY_RTLO'] = Configuration::get('TARGETPAY_RTLO');
        $helper->fields_value['TARGETPAY_TOKEN'] = Configuration::get('TARGETPAY_TOKEN');
        $helper->fields_value['TARGETPAY_TESTMODE'] = Configuration::get('TARGETPAY_TESTMODE');
        foreach ($this->listMethods as $id => $name) {
            $helper->fields_value['ENABLE_METHOD_' . $id] = Configuration::get('ENABLE_METHOD_' . $id);
        }
        return $helper->generateForm(array(
            $this->getConfigForm()
        ));
    }
    
    /**
     * Set config element to array
     *
     * @return array
     */
    private function getConfigForm()
    {
        $arrInputs = array(
            array(
                'col' => 3,
                'type' => 'text',
                'desc' => $this->l('Enter a valid RTLO'),
                'name' => 'TARGETPAY_RTLO',
                'required' => true,
                'label' => $this->l('RTLO')
            ),
            array(
                'col' => 3,
                'type' => 'text',
                'desc' => $this->l('Enter Digiwallet token, register one at digiwallet.nl'),
                'name' => 'TARGETPAY_TOKEN',
                'required' => false,
                'label' => $this->l('Digiwallet Token')
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Test mode'),
                'name' => 'TARGETPAY_TESTMODE',
                'is_bool' => true,
                'desc' => $this->l('Use this module in test mode'),
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => true,
                        'label' => $this->l('Enabled')
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => false,
                        'label' => $this->l('Disabled')
                    )
                )
            )
        );
        foreach ($this->listMethods as $id => $method) {
            $arrInputs[] = array(
                'type' => 'switch',
                'label' => $method['name'],
                'name' => 'ENABLE_METHOD_' . $id,
                'is_bool' => true,
                'desc' => $this->l($method['extra_text']),
                'values' => array(
                    array(
                        'id' => 'active_on_' . $id,
                        'value' => true,
                        'label' => $this->l('Enabled')
                    ),
                    array(
                        'id' => 'active_off_' . $id,
                        'value' => false,
                        'label' => $this->l('Disabled')
                    )
                )
            );
        }
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs'
                ),
                'input' => $arrInputs,
                'submit' => array(
                    'title' => $this->l('Save')
                )
            )
        );
    }
    
    /**
     * Check currency of cart.
     * TargetPay is only accept EUR right now
     *
     * @param unknown $cart
     * @return boolean
     */
    private function checkCurrency($cart)
    {
        $currencyOrder = new Currency($cart->id_currency);
        if (in_array($currencyOrder->iso_code, $this->limited_currencies) == true) {
            return true;
        }
        return false;
    }
    
    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookDisplayHeader()
    {
        $this->context->controller->addJS($this->_path . '/views/js/front.js');
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }
    
    /**
     * hookPaymentOptions
     * Called in Front Office at Payment Screen - displays user this module as payment option
     *
     * @param unknown $params
     * @return string
     */
    public function hookPaymentOptions($params)
    {
        $payment_options = [];
        
        if (!$this->active) {
            return;
        }
        
        if (!$this->checkCurrency($params['cart'])) {
            return;
        }
        
        $rtlo = Configuration::get('TARGETPAY_RTLO');
        
        foreach ($this->listMethods as $id => $method) {
            $setInputs = [
                'bankID' => [
                    'name' => 'method',
                    'type' => 'hidden',
                    'value' => $id,
                ]];
            $addInfo = '';
            if($id == 'IDE' || $id == 'DEB') {
                $templateVars = $this->getTemplateVars($id, $rtlo);
                $this->smarty->assign(
                    $templateVars
                    );
                $addInfo = $this->fetch('module:ps_targetpay/views/templates/front/payment_infos.tpl');
                $setInputs['option'] = [
                    'name' => 'option',
                    'type' => 'hidden',
                    'value' => $templateVars['selected']
                ];
            }
            
            
            if (Configuration::get('ENABLE_METHOD_' . $id) == 1) {
                $newOption = new PaymentOption();
                $newOption->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))
                ->setAdditionalInformation($addInfo)
                ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/views/img/'. $id .'_50.png'))
                ->setInputs($setInputs)
                ;
                $payment_options[] = $newOption;
            }
        }
        return $payment_options;
    }
    
    /**
     *
     * @param unknown $method
     * @param unknown $rtlo
     * @return [][]
     */
    public function getTemplateVars($method, $rtlo)
    {
        $targetpayObj = new TargetPayCore($method, $rtlo);
        if($method == 'IDE')
            $list = $targetpayObj->getBankList();
        else
            $list = $targetpayObj->getCountryList();
    
        return [
            'optionListArr' => $list,
            'selected' => key($list),
            'method' => $method
        ];
    }
    
    /**
     * This hook is used to display the order confirmation page.
     *
     * @param unknown $params
     * @return void|unknown
     */
    public function hookPaymentReturn($params)
    {
        if ($this->active == false) {
            return;
        }
        
        $order = $params['order'];
        if ($order->getCurrentOrderState()->id == Configuration::get('PS_OS_PAYMENT')) {
            $this->smarty->assign('status', 'ok');
        } else
            if ($order->getCurrentOrderState()->id == Configuration::get('PS_OS_CHEQUE')) {
                $this->smarty->assign('status', 'processing');
            } else {
                $this->smarty->assign('status', 'error');
            }
            $order_presenter = new OrderPresenter();
            $this->smarty->assign(array(
                'shop_name' => $this->context->shop->name,
                'order' => $order,
                'reorderUrl' => $order_presenter->present($order)['details']['reorder_url'],
                'total' => Tools::displayPrice($params['order']->getOrdersTotalPaid(), new Currency($params['order']->id_currency), false)
            ));
            return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }
    
    /**
     * Get transaction info in targetpay_ideal table
     *
     * @param string $trxid
     * @return boolean|object|NULL
     */
    public function selectTransaction($trxid)
    {
        $sql = sprintf("SELECT `id`, `cart_id`, `rtlo`,`order_id`, `paymethod`, `transaction_id`, `description`, `amount`
            FROM `" . _DB_PREFIX_ . "targetpay_ideal`
            WHERE `transaction_id`= '%s'
            ORDER BY `id` DESC", $trxid); // Choose most recent to minimize collision risk because we lack a paymethod field here!
        $result = Db::getInstance()->getRow($sql);
        return $result;
    }

    /**
     * @param $trxid
     * @return mixed
     */
    public function getBankWirePartialStatusID()
    {
        $query = '
            SELECT `id_order_state`
            FROM `' . _DB_PREFIX_ . 'order_state`
            WHERE `module_name` = "digiwallet_bankwire_partial"
        ';
        $result = Db::getInstance()->getRow($query);

        return $result['id_order_state'];
    }
    
    /**
     * Update order, order history, transaction info after payment
     *
     * @param array $transactionInfoArr
     */
    public function updateOrderAfterCheck($transactionInfoArr)
    {
        $orderId = (int) $transactionInfoArr['order_id'];
        $order = new Order($orderId);
        if (! $order)
            return ("Order is not found");
            
            if ($order->current_state == Configuration::get('PS_OS_PAYMENT'))
                return ("order $orderId had been done");
                
                $targetpayObj = new TargetPayCore($transactionInfoArr["paymethod"], $transactionInfoArr["rtlo"], "nl", Configuration::get('TARGETPAY_TESTMODE'));
                $targetpayObj->checkPayment($transactionInfoArr['transaction_id']);
                $updateArr = [];
                $paymentIsPartial = false;
                $amountPaid = null;
                if ($targetpayObj->getPaidStatus()) {
                    $amountPaid = $transactionInfoArr['amount'];
                    if($transactionInfoArr["paymethod"] == 'BW') {
                        $consumber_info = $targetpayObj->getConsumerInfo();
                        if (!empty($consumber_info) && $consumber_info['bw_paid_amount'] > 0) {
                            $amountPaid = number_format($consumber_info['bw_paid_amount'] / 100, 5);
                            if ($consumber_info['bw_paid_amount'] < $consumber_info['bw_due_amount']) {
                                $paymentIsPartial = true;
                            }
                        }
                        if ($paymentIsPartial) {
                            $state = $this->getBankWirePartialStatusID(); // Configuration::get('PS_OS_BANKWIRE');
                            $retMsg = $updateArr["description"] = 'Paid partial';
                            $updateArr['paid_amount'] = $amountPaid;
                        } else {
                            $state = Configuration::get('PS_OS_PAYMENT');
                            $retMsg = $updateArr["description"] = 'Paid';
                            $updateArr['paid_amount'] = $amountPaid;
                        }
                    } else {
                        $state = Configuration::get('PS_OS_PAYMENT');
                        $retMsg = $updateArr["description"] = 'Paid';
                        $updateArr['paid_amount'] = $amountPaid;
                    }
                } else {
                    $state = Configuration::get('PS_OS_ERROR');
                    $updateArr["description"] = 'Error:' . $targetpayObj->getErrorMessage();
                    $retMsg = $updateArr["description"] = 'Error:' . $targetpayObj->getErrorMessage();
                }
                
                $history = new OrderHistory();
                $history->id_order = $orderId;
                $history->changeIdOrderState($state, $orderId);
                $history->save();
                $this->updateTransaction($updateArr, $transactionInfoArr['transaction_id']);
                if ($paymentIsPartial) {
                    list($payment) = $order->getOrderPaymentCollection(); // Should be one single payment
                    $payment->amount = $amountPaid;
                    $payment->save();
                }
                
                return $retMsg;
    }
    
    /**
     * Update transaction info in targetpay_ideal table
     *
     * @param array $updateArr
     * @param string $trxid
     */
    public function updateTransaction($updateArr, $trxid)
    {
        $fields = '';
        foreach ($updateArr as $key => $value) {
            $fields .= "`" . $key . "` = '" . $value . "',";
        }
        $fields = rtrim($fields, ", ");
        
        $sql = sprintf("UPDATE `" . _DB_PREFIX_ . "targetpay_ideal` SET
            " . $fields . "
            WHERE `transaction_id`= '%s'", $trxid);
        Db::getInstance()->execute($sql);
        return;
    }
    
    public function setListMethods()
    {
        $this->listMethods = array(
            "IDE" => array(
                'name' => 'iDEAL',
                'enabled' => 1,
                'extra_text' => $this->l('Enable iDEAL method')
            ),
            "MRC" => array(
                'name' => 'Bancontact',
                'enabled' => 1,
                'extra_text' => $this->l('Enable Bancontact method')
            ),
            "DEB" => array(
                'name' => 'Sofort Banking',
                'enabled' => 1,
                'extra_text' => $this->l('Enable Sofort Banking method')
            ),
            'WAL' => array(
                'name' => 'Paysafecard',
                'enabled' => 1,
                'extra_text' => $this->l('Enable Paysafecard method')
            ),
            'CC' => array(
                'name' => 'Creditcard',
                'enabled' => 0,
                'extra_text' => $this->l('Enable Creditcard method (only possible when creditcard is activated on your targetpay account)')
            ),
            'AFP' => array(
                'name' => 'Afterpay',
                'enabled' => 0,
                'extra_text' => $this->l('Enable Afterpay method')
            ),
            'PYP' => array(
                'name' => 'Paypal',
                'enabled' => 0,
                'extra_text' => $this->l('Enable Paypal method')
            ),
            'BW' => array(
                'name' => 'Bankwire',
                'enabled' => 0,
                'extra_text' => $this->l('Enable Bankwire method')
            )
        );
    }
    
    /**
     *
     * @param unknown $params
     */
    public function hookActionOrderSlipAdd($params)
    {
        $this->refund($params);
    }
    
    /**
     *
     * @param unknown $params
     * @return boolean
     */
    public function refund($params)
    {
        if (empty($params['productList']))
            return false;
            
            $order = $params['order'];
            $orderId = $order->id;
            $customer = new Customer($order->id_customer);
            
            $sql = sprintf("SELECT `rtlo`,`paymethod`, `transaction_id`
            FROM `" . _DB_PREFIX_ . "targetpay_ideal`
            WHERE `order_id`= '%s'", $orderId);
            $result = Db::getInstance()->getRow($sql);
            
            $refundAmount = 0;
            foreach ($params['productList'] as $product) {
                $refundAmount += $product['quantity'] * $product['amount'];
            }
            
            if ($refundAmount == 0)
                return false;
                
                $dataRefund = array(
                    'paymethodID' => $result['paymethod'],
                    'transactionID' => $result['transaction_id'],
                    'amount' => intval(floatval($refundAmount) * 100),
                    'description' => 'OrderId: ' . $orderId . ', Amount: ' . $refundAmount,
                    'internalNote' => 'Internal note - OrderId: ' . $orderId . ', Amount: ' . $refundAmount . ', Customer Email: ' . $customer->email,
                    'consumerName' => $customer->firstname . ' ' . $customer->lastname
                );
                
                $targetPay = new TargetPayCore($result['paymethod'], $result['rtlo']);
                
                if (! $targetPay->refund(Configuration::get('TARGETPAY_TOKEN'), $dataRefund)) {
                    PrestaShopLogger::addLog($targetPay->getErrorMessage(), 3);
                    $this->context->controller->errors[] = ($targetPay->getErrorMessage());
                }
    }
    
    public function hookCancelProduct($params)
    {
        echo __FUNCTION__; die;
    }
}
