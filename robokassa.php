<?php

/*
* @author Robokassa.
* @version 1.0
* @package VirtueMart
* @subpackage payment
*
* https://www.robokassa.ru
*/

defined ('_JEXEC') or die('Restricted access');
if (!class_exists ('vmPSPlugin')) {
    require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}

class plgVmPaymentRobokassa extends vmPSPlugin {

    public function __construct (& $subject, $config)
    {
        parent::__construct ($subject, $config);
        $jlang = JFactory::getLanguage ();
        $jlang->load ('plg_vmpayment_robokassa', JPATH_ADMINISTRATOR, NULL, TRUE);
        $this->_loggable = TRUE;
        $this->_debug = TRUE;
        $this->tableFields = array_keys ($this->getTableSQLFields ());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';

        $varsToPush = array(
            'login' => array('', 'char'),
            'password1_test' => array('', 'char'),
            'password2_test' => array('', 'char'),
            'password1' => array('', 'char'),
            'password2' => array('', 'char'),
            'fiscalization_type' => array('', 'int'),
            'tax' => array('', 'char'),
            'sno' => array('', 'char'),
            'sandbox' => array('', 'int'),
            'debug' => array('', 'int'),
            'status_pending' => array('', 'char'),
            'status_success' => array('', 'char'),
            'status_canceled' => array('', 'char')
        );
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

        $this->setConvertable(array(
            'min_amount',
            'max_amount',
            'cost_per_transaction',
            'cost_min_transaction'
        ));

        $this->setConvertDecimal(array(
            'min_amount',
            'max_amount',
            'cost_per_transaction',
            'cost_min_transaction',
            'cost_percent_total'
        ));
    }

    public function getVmPluginCreateTableSQL()
    {

        return $this->createTableSQL('Payment Robokassa Table');
    }

    public function getTableSQLFields()
    {

        $SQLfields = array(
            'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(5000)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3)',
            'email_currency'              => 'char(3)',
            'cost_per_transaction'        => 'decimal(10,2)',
            'cost_min_transaction'        => 'decimal(10,2)',
            'cost_percent_total'          => 'decimal(10,2)',
            'tax_id'                      => 'smallint(1)',

            'robokassa_response_out_sum'  => 'decimal(10,2)',
            'robokassa_response_invid'    => 'int(11)',
            'robokassa_response_signature'=> 'varchar(32)',
            'robokassa_response_date'     => 'varchar(32)'
        );

        return $SQLfields;
    }

    public function plgVmConfirmedOrder ($cart, $order)
    {

        if (!($method = $this->getVmPluginMethod(
            $order['details']['BT']->virtuemart_paymentmethod_id))
        ) {
            return NULL;
        } // Another method was selected, do nothing

        if (!$this->selectedThisElement($method->payment_element)) {
            return FALSE;
        }

        $session = JFactory::getSession ();
        $return_context = $session->getId ();

        if ($method->debug) {
            $this->logInfo ('plgVmConfirmedOrder order number: '.
                $order['details']['BT']->order_number, 'message'
            );
        }

        if (!class_exists ('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }

        if (!class_exists ('VirtueMartModelCurrency')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'currency.php');
        }

        //user info
        $usrBT = $order['details']['BT'];
        $address = isset($order['details']['ST']) ? $order['details']['ST'] :
            $order['details']['BT'];

        //get currency
        $this->getPaymentCurrency($method);

        //get currency code
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'.
            $method->payment_currency.'" ';
        $db = JFactory::getDBO();
        $db->setQuery($q);
        $currencyCode = $db->loadResult();


        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency(
            $order['details']['BT']->order_total,$method->payment_currency
        );
        $cartCurrency = CurrencyDisplay::getInstance($cart->pricesCurrency);

        if ($totalInPaymentCurrency['value'] <= 0) {
            vmInfo (vmText::_ ('VMPAYMENT_ROBOKASSA_PAYMENT_AMOUNT_INCORRECT'));
            return FALSE;
        }

        $login = $method->login;
        if (empty($login)) {
            vmInfo (vmText::_ ('VMPAYMENT_ROBOKASSA_LOGIN_NOT_SET'));
            return FALSE;
        }

        $password1 = $method->password1;
        if (empty($password1)) {
            vmInfo (vmText::_ ('VMPAYMENT_ROBOKASSA_PASSWORD1_NOT_SET'));
            return FALSE;
        }

        $password1_test = $method->password1_test;
        if (empty($password1_test)) {
            vmInfo (vmText::_ ('VMPAYMENT_ROBOKASSA_PASSWORD1_TEST_NOT_SET'));
            return FALSE;
        }

        $send = array(
            'MrchLogin' => $login,
            'OutSum' => $totalInPaymentCurrency['value'],
            'InvId' => $order['details']['BT']->virtuemart_order_id,
            'Desc' => vmText::_ ('VMPAYMENT_ROBOKASSA_PAY_DESCRIPTION').
                $order['details']['BT']->order_number,
            'IncCurrLabel' => '',
            'Culture' => $currencyCode,
            'Encoding' => 'UTF-8',
            'Email' => $address->email,
        );

        $stringToHash = $send['MrchLogin'].':'.$send['OutSum'].':'.$send['InvId'];

        if ($method->fiscalization_type) {
            $billing = $order['details']['BT'];
            $send['Receipt'] = array(
                'sno' => $method->sno,
                'items' => array()
            );
            foreach ($order['items'] as $item) {
                $send['Receipt']['items'][] = array(
                    'name' => mb_strcut($item->order_item_name, 0, 63),
                    'quantity' => round($item->product_quantity, 2),
                    'sum' => round($item->product_subtotal_with_tax, 2),
                    'payment_method' => 'full_payment',
                    'payment_object' => 'commodity',
                    'tax' => $method->tax
                );
            }

            if(!empty($billing)) {
                $shipment_cost = 0;

                if(!empty($billing->order_shipment))
                    $shipment_cost += $billing->order_shipment;

                if(!empty($billing->order_shipment_tax))
                    $shipment_cost += $billing->order_shipment_tax;

                if($shipment_cost > 0) {
                    $send['Receipt']['items'][] = array(
                        'name' => 'Shipment',
                        'quantity' => 1,
                        'sum' => round($shipment_cost, 2),
                        'payment_method' => 'full_payment',
                        'payment_object' => 'service',
                        'tax' => $method->tax
                    );
                }
            }

            $send['Receipt'] = json_encode($send['Receipt']);
            $stringToHash .= ':'.$send['Receipt'];
        }

        $stringToHash .= ':'.($method->sandbox ? $password1_test : $password1);

        $send['SignatureValue'] = md5($stringToHash);

        if ($method->sandbox) {
            $send['IsTest'] = 1;
        }

        $html = '<form id="robokassa" style="display:none;"'.
            'action="https://merchant.roboxchange.com/Index.aspx" '.
            'method="POST">';
        foreach ($send as $key => $value) {
            $html .= '<input type="hidden" name="'.$key.'" value=\''.$value.'\'>';
        }
        $html .= '<input type="submit" value="Pay"></form>';
        $html .= '<script>var form = document.getElementById("robokassa");'.
            'form.submit();</script>';
        vRequest::setVar ('html', $html);
        if ($method->debug) {
            $this->logInfo (
                'Send data to Robokassa: '.print_r($send, true), 'message'
            );
        }

        $modelOrder = VmModel::getModel ('orders');
        $vmorder = $modelOrder->getOrder ($order['details']['BT']->virtuemart_order_id);
        $order['customer_notified'] = 0;
        $order['order_status'] = $method->status_pending;
        
        $modelOrder->updateStatusForOneOrder(
            $order['details']['BT']->virtuemart_order_id,
            $order, 
            TRUE
        );

        return true;
    }

    public function plgVmOnPaymentResponseReceived (&$html) {

        if (!class_exists ('VirtueMartCart')) {
            require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
        }
        if (!class_exists ('shopFunctionsF')) {
            require(VMPATH_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
        }
        if (!class_exists ('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }

        vmLanguage::loadJLang('com_virtuemart_orders', TRUE);
        $post = vRequest::getPost();


        // the payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = vRequest::getInt ('pm', 0);
        $order_number = vRequest::getString ('on', 0);
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL;
        } // Another method was selected, do nothing

        if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber(
            $order_number)
        )) {
            return NULL;
        }

        if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }
        vmLanguage::loadJLang('com_virtuemart');
        $orderModel = VmModel::getModel('orders');
        $order = $orderModel->getOrder($virtuemart_order_id);

        vmdebug ('Robokassa plgVmOnPaymentResponseReceived', $post);
        $paymentName = $this->renderPluginName($method);
        $html = $this->getPaymentResponseHtml($paymentTable, $paymentName);
        $link =  JRoute::
            _("index.php?option=com_virtuemart&view=orders&layout=details&order_number=".
            $order['details']['BT']->order_number."&order_pass=".
            $order['details']['BT']->order_pass, false
        );

        $html .='<br /><a class="vm-button-correct" href="'.$link.'">'.
            vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER').'</a>';

        $cart = VirtueMartCart::getCart ();
        $cart->emptyCart ();
        return TRUE;
    }

    public function plgVmOnUserPaymentCancel () {

        if (!class_exists ('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }

        $virtuemart_order_id = vRequest::getString ('InvId', '');
        $db = & JFactory::getDBO();
        $query = "SELECT * FROM #__virtuemart_orders WHERE virtuemart_order_id =".
            $virtuemart_order_id;
        $db->setQuery($query);
        $paymentTable = $db->loadObject();

        if (!$paymentTable || !$this->selectedThisByMethodId ($paymentTable->virtuemart_paymentmethod_id)) {
            return NULL;
        }

        $method = $this->getVmPluginMethod ($paymentTable->virtuemart_paymentmethod_id);

        VmInfo (vmText::_ ('VMPAYMENT_ROBOKASSA_PAYMENT_CANCELLED'));
        $session = JFactory::getSession ();
        $return_context = $session->getId ();
        if (strcmp ($paymentTable->user_session, $return_context) === 0) {
            $this->handlePaymentUserCancel ($virtuemart_order_id);
        }

        $modelOrder = VmModel::getModel ('orders');
        $vmorder = $modelOrder->getOrder ($virtuemart_order_id);
        $order = array();
        $order['customer_notified'] = 0;
        $order['order_status'] = $method->status_canceled;
        $order['comments'] = vmText::sprintf (
            'VMPAYMENT_ROBOKASSA_PAYMENT_CANCELED',
            $order_number
        );
        if ($method->debug) {
            $this->logInfo (
                'Transaction canceled', 'message'
            );
        }
        $modelOrder->updateStatusForOneOrder(
            $virtuemart_order_id,
            $order, 
            TRUE
        );

        return TRUE;
    }

    public function plgVmOnPaymentNotification () {

        if (!class_exists ('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }

        $post = vRequest::getPost();

        if (!isset(
            $post['OutSum'], 
            $post['InvId'], 
            $post['SignatureValue']
        )) {
            return;
        }

        $virtuemart_order_id = $post['InvId'];
        $db = & JFactory::getDBO();
        $query = "SELECT * FROM #__virtuemart_orders WHERE virtuemart_order_id =".
            $virtuemart_order_id;
        $db->setQuery($query);
        $payment = $db->loadObject();

        if (!$payment) {
            return;
        }


        $method = $this->getVmPluginMethod ($payment->virtuemart_paymentmethod_id);
        if (!$this->selectedThisElement ($method->payment_element)) {
            return FALSE;
        }

        if (!$payment) {
            if ($method->debug) {
                $this->logInfo (
                    'Robokassa getDataByOrderId payment not found: exit ',
                    'ERROR'
                );
            }
            return NULL;
        }

        $this->storePSPluginInternalData(
            array(
                'virtuemart_order_id' => $virtuemart_order_id,
                'robokassa_response_out_sum'  => $post['OutSum'],
                'robokassa_response_invid'    => $post['InvId'],
                'robokassa_response_signature'=> $post['SignatureValue'],
                'robokassa_response_date'     => date('d.m.Y H:i:s')
            ),
            'virtuemart_order_id', 
            TRUE
        );

        if ($method->debug) {
            $this->logInfo (
                'Robokassa notification data '.print_r($_REQUEST, true), 'message'
            );
        }

        $modelOrder = VmModel::getModel ('orders');
        $vmorder = $modelOrder->getOrder ($virtuemart_order_id);
        $order = array();

        $signature = strtoupper($post['SignatureValue']);
        $calcSignature = strtoupper(md5(
            $post['OutSum'].':'.
            $post['InvId'].':'.
            ($method->sandbox ? $method->password2_test : $method->password2)
        ));

        if ($signature != $calcSignature) {
            $error = "bad sign\n";
            $order['customer_notified'] = 0;
            $order['order_status'] = $method->status_canceled;
            $order['comments'] = $error;
            if ($method->debug) {
                $this->logInfo (
                    'Robokassa error: '.$error, 'ERROR'
                );
            }
            echo $error;
        } else {
            $order['customer_notified'] = 1;
            $order['order_status'] = $method->status_success;
            $order['comments'] = vmText::sprintf (
                'VMPAYMENT_ROBOKASSA_PAYMENT_STATUS_CONFIRMED',
                $order_number
            );
            if ($method->debug) {
                $this->logInfo (
                    'Robokassa info: Order '.$order_number.' was successful paid',
                    'message'
                );
            }
            echo "OK".$post['InvId']."\n";
        }
        $modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
        $this->emptyCart ($payment->user_session, $post['InvId']);
        exit;
    }

    private function getPaymentResponseHtml ($paymentTable, $payment_name) {
        vmLanguage::loadJLang('com_virtuemart');

        $html = '<table>' . "\n";
        $html .= $this->getHtmlRow ('COM_VIRTUEMART_PAYMENT_NAME', $payment_name);
        if (!empty($paymentTable)) {
            $html .= $this->getHtmlRow ('ROBOKASSA_ORDER_NUMBER',
                $paymentTable->order_number
            );
        }
        $html .= '</table>' . "\n";

        return $html;
    }

    /**
     * Display stored payment data for an order
     *
     */
    public function plgVmOnShowOrderBEPayment(
        $virtuemart_order_id, $virtuemart_payment_id
    ) {

        if (!$this->selectedThisByMethodId ($virtuemart_payment_id)) {
            return NULL; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
            return NULL;
        }
        vmLanguage::loadJLang('com_virtuemart');

        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE ();
        $html .= $this->getHtmlRowBE ('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE ('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        if ($paymentTable->email_currency) {
            $html .= $this->getHtmlRowBE ('STANDARD_EMAIL_CURRENCY', $paymentTable->email_currency );
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @author: Valerie Isaksen
     *
     * @param $cart_prices: cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions ($cart, $method, $cart_prices) {

        $this->convert_condition_amount($method);
        $amount = $this->getCartAmount($cart_prices);
        $address = $cart -> getST();

        if($this->_toConvert){
            $this->convertToVendorCurrency($method);
        }
        //vmdebug('standard checkConditions',  $amount, $cart_prices['salesPrice'],  $cart_prices['salesPriceCoupon']);
        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
            OR
            ($method->min_amount <= $amount AND ($method->max_amount == 0)));
        if (!$amount_cond) {
            return FALSE;
        }
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array ($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        // probably did not gave his BT:ST address
        if (!is_array ($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count ($countries) == 0 || in_array ($address['virtuemart_country_id'], $countries) ) {
            return TRUE;
        }

        return FALSE;
    }


    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

        return $this->onStoreInstallPluginTable ($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */
    public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {

        return $this->OnSelectCheck ($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object  $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

        return $this->displayListFE ($cart, $selected, $htmlIn);
    }

    /*
    * plgVmonSelectedCalculatePricePayment
    * Calculate the price (value, tax_id) of the selected method
    * It is called by the calculator
    * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
    * @author Valerie Isaksen
    * @cart: VirtueMartCart the current cart
    * @cart_prices: array the new cart prices
    * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
    *
    *
    */
    public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

        return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

        if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement ($method->payment_element)) {
            return FALSE;
        }
        $this->getPaymentCurrency ($method);

        $paymentCurrencyId = $method->payment_currency;
        return;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

        return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {

        $this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    /**
     * @param $orderDetails
     * @param $data
     * @return null
     */

    function plgVmOnUserInvoice ($orderDetails, &$data) {

        if (!($method = $this->getVmPluginMethod ($orderDetails['virtuemart_paymentmethod_id']))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement ($method->payment_element)) {
            return NULL;
        }
        //vmdebug('plgVmOnUserInvoice',$orderDetails, $method);

        if (!isset($method->send_invoice_on_order_null) or $method->send_invoice_on_order_null==1 or $orderDetails['order_total'] > 0.00){
            return NULL;
        }

        if ($orderDetails['order_salesPrice']==0.00) {
            $data['invoice_number'] = 'reservedByPayment_' . $orderDetails['order_number']; // Nerver send the invoice via email
        }

    }
    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     * @return bool|null
     */
    function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId) {

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return FALSE;
        }

        if(empty($method->email_currency)){

        } else if($method->email_currency == 'vendor'){
            $vendor_model = VmModel::getModel('vendor');
            $vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $emailCurrencyId = $vendor->vendor_currency;
        } else if($method->email_currency == 'payment'){
            $emailCurrencyId = $this->getPaymentCurrency($method);
        }


    }
    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     * @author Max Milbers

    public function plgVmOnCheckoutCheckDataPayment(  VirtueMartCart $cart) {
    return null;
    }
     */

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment ($order_number, $method_id) {

        return $this->onShowOrderPrint ($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
        return $this->declarePluginParams('payment', $data);
    }
    function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

        return $this->setOnTablePluginParams ($name, $id, $table);
    }

    //Notice: We only need to add the events, which should work for the specific plugin, when an event is doing nothing, it should not be added

    /**
     * Save updated order data to the method specific table
     *
     * @param array   $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     *
    public function plgVmOnUpdateOrderPayment(  $_formData) {
    return null;
    }

    /**
     * Save updated orderline data to the method specific table
     *
     * @param array   $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     *
    public function plgVmOnUpdateOrderLine(  $_formData) {
    return null;
    }

    /**
     * plgVmOnEditOrderLineBE
     * This method is fired when editing the order line details in the backend.
     * It can be used to add line specific package codes
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     *
    public function plgVmOnEditOrderLineBEPayment(  $_orderId, $_lineId) {
    return null;
    }

    /**
     * This method is fired when showing the order details in the frontend, for every orderline.
     * It can be used to display line specific package codes, e.g. with a link to external tracking and
     * tracing systems
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     *
    public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
    return null;
    }

    /**
     * This event is fired when the  method notifies you when an event occurs that affects the order.
     * Typically,  the events  represents for payment authorizations, Fraud Management Filter actions and other actions,
     * such as refunds, disputes, and chargebacks.
     *
     * NOTE for Plugin developers:
     *  If the plugin is NOT actually executed (not the selected payment method), this method must return NULL
     *
     * @param         $return_context: it was given and sent in the payment form. The notification should return it back.
     * Used to know which cart should be emptied, in case it is still in the session.
     * @param int     $virtuemart_order_id : payment  order id
     * @param char    $new_status : new_status for this order id.
     * @return mixed Null when this method was not selected, otherwise the true or false
     *
     * @author Valerie Isaksen
     *
     *
    public function plgVmOnPaymentNotification() {
    return null;
    }

    /**
     * plgVmOnPaymentResponseReceived
     * This event is fired when the  method returns to the shop after the transaction
     *
     *  the method itself should send in the URL the parameters needed
     * NOTE for Plugin developers:
     *  If the plugin is NOT actually executed (not the selected payment method), this method must return NULL
     *
     * @param int     $virtuemart_order_id : should return the virtuemart_order_id
     * @param text    $html: the html to display
     * @return mixed Null when this method was not selected, otherwise the true or false
     *
     * @author Valerie Isaksen
     *
     *
    function plgVmOnPaymentResponseReceived(, &$virtuemart_order_id, &$html) {
    return null;
    }
     */
}