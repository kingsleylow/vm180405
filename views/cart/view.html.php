<?php

/**
 *
 * View for the shopping cart
 *
 * @package	VirtueMart
 * @subpackage
 * @author Max Milbers
 * @author Oscar van Eijk
 * @author RolandD
 * @link ${PHING.VM.MAINTAINERURL}
 * @copyright Copyright (c) 2004 - 2010 VirtueMart Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * @version $Id: view.html.php 9493 2017-03-29 16:10:08Z Milbo $
 */
// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die('Restricted access');
use PayPal\Api\ChargeModel;
use PayPal\Api\Currency;
use PayPal\Api\MerchantPreferences;
use PayPal\Api\PaymentDefinition;
use PayPal\Api\Plan;

use PayPal\Api\Patch;
use PayPal\Api\PatchRequest;
use PayPal\Common\PayPalModel;

use PayPal\Api\Agreement;
use PayPal\Api\Payer;
use PayPal\Api\ShippingAddress;

use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;

use PayPal\Api\Payment;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
// Load the view framework
if(!class_exists('VmView'))
    require(JPATH_ROOT . '/components/com_virtuemart/helpers/vmview.php');
if(!class_exists('FxbotmarketConfig'))    
    require_once (JPATH_ROOT .'/components/com_fxbotmarket/helpers/config.php');
//require(VMPATH_SITE.DS.'helpers'.DS.'vmview.php');

/**
 * View for the shopping cart
 * @package VirtueMart
 * @author Max Milbers
 * @author Patrick Kohl
 */
class VirtueMartViewCart extends VmView {

	var $pointAddress = false;
	/* @deprecated */
	var $display_title = true;
	/* @deprecated */
	var $display_loginform = true;
        var $ifsignal = false;
        var $signal_orderid = 0;
        var $restapilink = '';
        var $product_name = '';
        var $order_number = '';
        var $fxpaymentmethod = 0;
        var $product_type = 7;//Signal = 1,  Robot (MT4 EA) =2,Robot (MT5 EA) = 3, Indicator = 4,Script = 5,Software = 6,eBook (PDF) = 7, TAP = 1000
        //var $config;
        var $fxbot_product_id = 0;
        var $credit_card_mode = 1;
        var $product_price;
        var $stripe_public_key = '';
        var $fxbotmarket_id_quicksell_order = 0;
        var $fxbotmarket_id_downloadable_order = 0;
        var $fx_product_rent_post_var = 0;
        var $fx_product = false;
        var $selected_rent_msg = 'You are ordering: ';
	public function display($tpl = null) {


		$app = JFactory::getApplication();
                $session = JFactory::getSession();
                $this->fx_product_rent_post_var =  (int)$session->get('mtcart.fx_product_rent',0);
		$this->prepareContinueLink();
		if (VmConfig::get('use_as_catalog',0)) {
			vmInfo('This is a catalogue, you cannot access the cart');
			$app->redirect($this->continue_link);
		}

		$pathway = $app->getPathway();
		$document = JFactory::getDocument();
		$document->setMetaData('robots','NOINDEX, NOFOLLOW, NOARCHIVE, NOSNIPPET');

		$this->layoutName = $this->getLayout();
		if (!$this->layoutName) $this->layoutName = vRequest::getCmd('layout', 'default');

		$format = vRequest::getCmd('format');
                $config_p = new FxbotmarketConfig();
		if (!class_exists('VirtueMartCart'))
                    require(JPATH_ROOT . '/components/com_virtuemart/helpers/cart.php');
                    //require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
		$this->cart = VirtueMartCart::getCart();

		$this->cart->prepareVendor();

		if ($this->layoutName == 'select_shipment') {

			$this->cart->prepareCartData();
			$this->lSelectShipment();

			$pathway->addItem(vmText::_('COM_VIRTUEMART_CART_OVERVIEW'), JRoute::_('index.php?option=com_virtuemart&view=cart', FALSE));
			$pathway->addItem(vmText::_('COM_VIRTUEMART_CART_SELECTSHIPMENT'));
			$document->setTitle(vmText::_('COM_VIRTUEMART_CART_SELECTSHIPMENT'));
		} else if ($this->layoutName == 'select_payment') {

			$this->cart->prepareCartData();

			$this->lSelectPayment();

			$pathway->addItem(vmText::_('COM_VIRTUEMART_CART_OVERVIEW'), JRoute::_('index.php?option=com_virtuemart&view=cart', FALSE));
			$pathway->addItem(vmText::_('COM_VIRTUEMART_CART_SELECTPAYMENT'));
			$document->setTitle(vmText::_('COM_VIRTUEMART_CART_SELECTPAYMENT'));
		} else if ($this->layoutName == 'order_done') {
			vmLanguage::loadJLang( 'com_virtuemart_shoppers', true );
			$this->lOrderDone();

			$pathway->addItem( vmText::_( 'COM_VIRTUEMART_CART_THANKYOU' ) );
			$document->setTitle( vmText::_( 'COM_VIRTUEMART_CART_THANKYOU' ) );
		} else {
			vmLanguage::loadJLang('com_virtuemart_shoppers', true);

			$this->renderCompleteAddressList();

			if (!class_exists ('VirtueMartModelUserfields')) {
				require(VMPATH_ADMIN . DS . 'models' . DS . 'userfields.php');
			}

			$userFieldsModel = VmModel::getModel ('userfields');

			$userFieldsCart = $userFieldsModel->getUserFields(
				'cart'
				, array('captcha' => true, 'delimiters' => true) // Ignore these types
				, array('delimiter_userinfo','user_is_vendor' ,'username','password', 'password2', 'agreed', 'address_type') // Skips
			);

			$this->userFieldsCart = $userFieldsModel->getUserFieldsFilled(
				$userFieldsCart
				,$this->cart->cartfields
			);

			if (!class_exists ('CurrencyDisplay'))
				require(VMPATH_ADMIN . DS . 'helpers' . DS . 'currencydisplay.php');

			$this->currencyDisplay = CurrencyDisplay::getInstance($this->cart->pricesCurrency);

			$customfieldsModel = VmModel::getModel ('Customfields');
			$this->assignRef('customfieldsModel',$customfieldsModel);

			$this->lSelectCoupon();

			$totalInPaymentCurrency = $this->getTotalInPaymentCurrency();

			$this->checkoutAdvertise = $this->cart->getCheckoutAdvertise();

			if ($this->cart->getDataValidated()) {
				if($this->cart->_inConfirm){
					$pathway->addItem(vmText::_('COM_VIRTUEMART_CANCEL_CONFIRM_MNU'));
					$document->setTitle(vmText::_('COM_VIRTUEMART_CANCEL_CONFIRM_MNU'));
					$text = vmText::_('COM_VIRTUEMART_CANCEL_CONFIRM');
					$this->checkout_task = 'cancel';
				} else {
					$pathway->addItem(vmText::_('COM_VIRTUEMART_ORDER_CONFIRM_MNU'));
					$document->setTitle(vmText::_('COM_VIRTUEMART_ORDER_CONFIRM_MNU'));
					$text = vmText::_('COM_VIRTUEMART_ORDER_CONFIRM_MNU');
					$this->checkout_task = 'confirm';
				}
			} else {
				$pathway->addItem(vmText::_('COM_VIRTUEMART_CART_OVERVIEW'));
				$document->setTitle(vmText::_('COM_VIRTUEMART_CART_OVERVIEW'));
				$text = vmText::_('COM_VIRTUEMART_CHECKOUT_TITLE');
				$this->checkout_task = 'checkout';
			}
			$dynUpdate = '';
			if( VmConfig::get('oncheckout_ajax',false)) {
				$dynUpdate=' data-dynamic-update="1" ';
			}
			$this->checkout_link_html = '<button type="submit" id="checkoutFormSubmit" name="'.$this->checkout_task.'" value="1" class="vm-button-correct" '.$dynUpdate.' ><span>' . $text . '</span> </button>';

			$forceMethods=vRequest::getInt('forceMethods',false);
			if (VmConfig::get('oncheckout_opc', 1) or $forceMethods) {
				if (!class_exists('vmPSPlugin')) require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
				JPluginHelper::importPlugin('vmshipment');
				JPluginHelper::importPlugin('vmpayment');
				//vmdebug('cart view oncheckout_opc ');
				$lSelectShipment=$this->lSelectShipment() ;
				$lSelectPayment=$this->lSelectPayment();
				if(!$lSelectShipment or !$lSelectPayment){
					if (!VmConfig::get('oncheckout_opc', 1)) {
						vmInfo('COM_VIRTUEMART_CART_ENTER_ADDRESS_FIRST');
					}
					$this->pointAddress = true;
				}
			} else {
				$this->checkPaymentMethodsConfigured();
				$this->checkShipmentMethodsConfigured();
			}

			if ($this->cart->virtuemart_shipmentmethod_id) {
				$shippingText =  vmText::_('COM_VIRTUEMART_CART_CHANGE_SHIPPING');
			} else {
				$shippingText = vmText::_('COM_VIRTUEMART_CART_EDIT_SHIPPING');
			}
			$this->assignRef('select_shipment_text', $shippingText);

			if ($this->cart->virtuemart_paymentmethod_id) {
				$paymentText = vmText::_('COM_VIRTUEMART_CART_CHANGE_PAYMENT');
			} else {
				$paymentText = vmText::_('COM_VIRTUEMART_CART_EDIT_PAYMENT');
			}
			$this->assignRef('select_payment_text', $paymentText);

			$this->cart->prepareAddressFieldsInCart();

			$this->layoutName = $this->cart->layout;
			if(empty($this->layoutName)) $this->layoutName = 'default';

			if ($this->cart->layoutPath) {
				$this->addTemplatePath($this->cart->layoutPath);
			}

			if(!empty($this->layoutName) and $this->layoutName!='default'){
				$this->setLayout( strtolower( $this->layoutName ) );
			}
			//set order language
			$lang = JFactory::getLanguage();
			$order_language = $lang->getTag();
			$this->assignRef('order_language',$order_language);
		}

		

		$this->useSSL = vmURI::useSSL();
		$this->useXHTML = false;

		$this->assignRef('totalInPaymentCurrency', $totalInPaymentCurrency);


		//We set the valid content time to 2 seconds to prevent that the cart shows wrong entries
		$document->setMetaData('expires', '1',true);
		//We never want that the cart is indexed
		$document->setMetaData('robots','NOINDEX, NOFOLLOW, NOARCHIVE, NOSNIPPET');

		if ($this->cart->_inConfirm) vmInfo('COM_VIRTUEMART_IN_CONFIRM');

		$current = JFactory::getUser();
		$this->allowChangeShopper = false;
		$this->adminID = false;
		if(VmConfig::get ('oncheckout_change_shopper')){
			$this->allowChangeShopper = vmAccess::manager('user');
		}
		if($this->allowChangeShopper){
			$this->userList = $this->getUserList();
		}

		if(VmConfig::get('oncheckout_ajax',false)){
			vmJsApi::jDynUpdate();
		}
                //check if this user already bought signal product
                if(isset($this->cart) && ($this->cart->products) && count($this->cart->products) > 0 && isset($this->cart->products[min(array_keys($this->cart->products))]->virtuemart_product_id)){
                    $virt_prod_id = $this->cart->products[min(array_keys($this->cart->products))]->virtuemart_product_id;
                }else{
                    $virt_prod_id = -1;
                }
                if(isset($this->cart) && ($this->cart->products) && count($this->cart->products) > 0 && isset($this->cart->products[min(array_keys($this->cart->products))]->product_name)){
                    $this->product_name = $this->cart->products[min(array_keys($this->cart->products))]->product_name;
                }else{
                    $this->product_name = '';
                }
                if(isset($this->cart) && isset($this->cart->order_number)){
                    $order_number = $this->cart->order_number;
                }else{
                    $order_number = '';
                }
                if(!class_exists('FxbotmarketProduct')) {
                                include_once JPATH_ROOT.'/components/com_fxbotmarket/helpers/product.php';
                          }
                $fxbot_product =   FxbotmarketProduct::getFxbotProductByVid($virt_prod_id);// we try 
                // to get all information about product from fxbotmarketx_files_products table
                if(($fxbot_product !== false) && is_object($fxbot_product) && isset($fxbot_product->id) && ($fxbot_product->id > 0)){
                    $type_of_product = $fxbot_product->typeofproduct > 0? $fxbot_product->typeofproduct: 1;
                    //Signal = 1,  Robot (MT4 EA) =2,Robot (MT5 EA) = 3, Indicator = 4,Script = 5,Software = 6,eBook (PDF) = 7, TAP = 1000
                    $this->fx_product = $fxbot_product;
                }else{
                    $app->enqueueMessage('Can not find product.','error');
                    $app->redirect('index.php?option=com_fxbotmarket&view=customersignals');
                }
                $this->product_type = $type_of_product;
                $this->fxbot_product_id = $fxbot_product->id;
                
                $fxpaymentmethod = (int)$session->get('mtcart.fxpaymentmethod',0);
                    $this->fxpaymentmethod = $fxpaymentmethod;
                if($type_of_product == 1){//check if product is signal
                $db = JFactory::getDbo();
                $q = 'SELECT id, virtuemart_order_number,virtuemart_order_id FROM #__fxbotmarketx_signal_orders WHERE virtuemart_order_number LIKE '.$db->quote($order_number);
                $db->setQuery($q);
                $signal_order = $db->loadObject();
                
                if(is_object($signal_order) && isset($signal_order->id)){
                    $this->ifsignal = true;
                    $this->signal_orderid = (int)$signal_order->id;
                    $this->order_number = $signal_order->virtuemart_order_number;
                    $this->virtuemart_order_id = $signal_order->virtuemart_order_id;
                    
                    
                    $this->credit_card_mode = $config_p->get('fxproduct_paypal_stripe_card_mode');

                    switch($fxpaymentmethod){
                        case 1:$this->stripe_public_key = $config_p->get('stripe_public_key');
                                if(isset($this->cart) && isset($this->cart->cartPrices) && isset($this->cart->cartPrices['billTotal'])){
                                    $this->product_price = $this->cart->cartPrices['billTotal'] * 100;//$price = $this->getPrice($data->formdata['fxbot_price'])*100;
                                }else{
                                    $this->product_price = '0';
                                }
                                break;
                        case 2://when customer selects bitcoin then he needs to pay 
                                // for 12 monthes
                                $this->stripe_public_key = $config_p->get('stripe_public_key');
                                $this->bitcoin_annual_percent_amount = (int)$config_p->get('bitcoin_annual_percent_amount');
                                //$signal_order->id
                                $db = JFactory::getDbo();
                                $q = 'SELECT final_price FROM #__fxbotmarketx_signal_orders WHERE id = '.$signal_order->id;
                                $db->setQuery($q);
                                $this->product_price = $db->loadResult() * 100;
                                /*if(isset($this->cart) && isset($this->cart->cartPrices) && isset($this->cart->cartPrices['billTotal'])){
                                    $this->product_price = 12 * ($this->cart->cartPrices['billTotal'] * 100 + $this->cart->cartPrices['billTotal'] * $this->bitcoin_annual_percent_amount);//$price = $this->getPrice($data->formdata['fxbot_price'])*100;
                                }else{
                                    $this->product_price = '0';
                                }
                                */
                                break;
                        default:   
                            $this->restapilink = $this->createConfirmLink();
                                    if (is_numeric($this->restapilink) && $this->resapilink <= -1){
                                        /*$db = JFactory::getDbo();
                                        $q = 'DELETE FROM #__fxbotmarketx_signal_orders WHERE id = '.$this->signal_orderid;
                                        $db->setQuery($q);
                                        $db->execute();*/
                                        //$app = JFactory::getApplication();
                                        $app->enqueueMessage('Can not finish order. Error code:'.$this->restapilink,'error');
                                        $app->redirect('index.php?option=com_fxbotmarket&view=customersignals');
                                    } 
                    }
                }
        }elseif(FxbotmarketProduct::ifDownloadableTypeOfProduct($type_of_product) ){//if product is downloadable
            if($this->layoutName == 'order_done'){//if we need to display order_done page
                $this->setLayout('order_done_downloadable');
                //fxpaymentmethod
                if(array_key_exists('fxbotmarket_id_quicksell_order', $GLOBALS)){
                    //store quicksell order id from #__quicksell_orders table
                    $this->fxbotmarket_id_quicksell_order = (int)$GLOBALS['fxbotmarket_id_quicksell_order'];
                }
                if(array_key_exists('fxbotmarket_id_downloadable_order', $GLOBALS)){
                    //store  order id from #__fxbotmarketx_downloadable_orders table
                    $this->fxbotmarket_id_downloadable_order = (int)$GLOBALS['fxbotmarket_id_downloadable_order'];
                }
                //fxbotmarket_id_downloadable_order
                if(isset($this->cart->cartPrices['salesPricePayment'])){
                        $this->cart->cartPrices['billTotal'] -= $this->cart->cartPrices['salesPricePayment'];
                        $this->cart->cartPrices['salesPricePayment'] = 0;//$order['details']['BT']->order_total
                    }
                    //we are checking whic payment method customer selected
                    if($this->fxpaymentmethod == 1 || $this->fxpaymentmethod == 2){//stripe card and bitcoin
                        $this->product_price = $this->cart->cartPrices['billTotal']  * 100;
                        $this->stripe_public_key = $config_p->get('stripe_public_key');
                    
                        
                    }elseif($this->fxpaymentmethod == 0){//paypal
                        $amount = $this->product_price = $this->cart->cartPrices['billTotal'];
                        $order_info = new stdClass();
                        if(isset($this->cart) && isset($this->cart->virtuemart_order_id)){
                            $order_info->virtuemart_order_id = $this->cart->virtuemart_order_id;
                        }else{
                            $app->redirect($this->continue_link);
                            return;
                        }
                        
                        $order_info->product_name = $this->product_name;
                        $order_info->order_number = $order_number;
                        $order_info->downloadable_order_id = $this->fxbotmarket_id_downloadable_order;
                        $order_info->type_of_product = $type_of_product;
                        $this->restapilink = $this->createDownloadableConfirmLink($amount, $order_info);
                        
                    }
                //$this->restapilink    
            }else{
            $tpl = 'downloadable';
            //billTotal
            if(isset($this->cart) && isset($this->cart->cartPrices) && isset($this->cart->cartPrices['billTotal'])){
                //if product is downloadable then we do not need to add monthly fee 
                    if(isset($this->cart->cartPrices['salesPricePayment'])){
                        $this->cart->cartPrices['billTotal'] -= $this->cart->cartPrices['salesPricePayment'];
                        $this->cart->cartPrices['salesPricePayment'] = 0;
                    }
                }
            }                       
        }elseif(FxbotmarketProduct::ifEaTypeOfProduct($type_of_product)){
            //check if customer requests for rent
            //$this->fx_product_rent_post_var
            $fx_product = $this->fx_product;
            if(isset($this->cart->cartPrices['salesPricePayment'])){//we minus order payment/ order payment is used only for signals
                        $this->cart->cartPrices['billTotal'] -= $this->cart->cartPrices['salesPricePayment'];
                    }
            $this->cart->cartPrices['salesPricePayment'] = 0;
            $rent_var_name = '';
            $period_rent = '';
            switch($this->fx_product_rent_post_var){//check if customer requests rent
                    case 1:
                        $rent_var_name = 'rent1';
                        $period_rent = '1 month';
                        break;
                    case 3:
                        $rent_var_name = 'rent3';
                        $period_rent = '3 monthes';
                        break;
                    case 6:
                        $rent_var_name = 'rent6';
                        $period_rent = '6 monthes';
                        break;
                    case 12:
                        $rent_var_name = 'rent12';
                        $period_rent = '1 year';
                        break;
                }
            if($this->fx_product_rent_post_var > 0){
                if($fx_product->$rent_var_name > 0){
                            $this->cart->cartPrices['billTotal'] = $fx_product->$rent_var_name;
                            $this->cart->cartPrices['discountedPriceWithoutTax'] = $fx_product->$rent_var_name;
                            $this->cart->cartPrices['basePriceWithTax'] = $fx_product->$rent_var_name;
                            $this->cart->cartPrices['salesPrice'] = $fx_product->$rent_var_name;
                            $this->cart->cartPrices['discountedPriceWithoutTax'] = $fx_product->$rent_var_name;
                            $this->cart->cartPrices['priceWithoutTax'] = $fx_product->$rent_var_name;	
                            $this->cart->cartPrices['basePrice]'] = $fx_product->$rent_var_name;
                            $this->cart->cartPrices['toTax'] = $fx_product->$rent_var_name;
                            $this->cart->cartPrices['withTax']	 = $fx_product->$rent_var_name;
                            $this->cart->cartPrices['basePriceVariant']	 = $fx_product->$rent_var_name;
                            $this->selected_rent_msg = 'You have selected rent for '.$period_rent.':';

                        }else{
                            $app->enqueueMessage('Rent for 1 month was not set. Select different period.', 'error');
                            $app->redirect($this->continue_link);//$this->continue_link
                        }
            }

            
            if($this->layoutName == 'order_done'){//if we need to display order_done page
                $this->setLayout('order_done_ea');
                //fxpaymentmethod
                if(array_key_exists('fxbotmarket_id_quicksell_order', $GLOBALS)){
                    //store quicksell order id from #__quicksell_orders table
                    $this->fxbotmarket_id_quicksell_order = (int)$GLOBALS['fxbotmarket_id_quicksell_order'];
                }
                if(array_key_exists('fxbotmarket_id_downloadable_order', $GLOBALS)){
                    //store  order id from #__fxbotmarketx_downloadable_orders table
                    $this->fxbotmarket_id_downloadable_order = (int)$GLOBALS['fxbotmarket_id_downloadable_order'];
                }
                //fxbotmarket_id_downloadable_order
                if(isset($this->cart->cartPrices['salesPricePayment'])){//we minus order payment/ order payment is used only for signals
                        $this->cart->cartPrices['billTotal'] -= $this->cart->cartPrices['salesPricePayment'];
                        $this->cart->cartPrices['salesPricePayment'] = 0;//$order['details']['BT']->order_total
                    }
                    //we are checking whic payment method customer selected
                    if($this->fxpaymentmethod == 1 || $this->fxpaymentmethod == 2){//stripe card and bitcoin
                        $this->product_price = $this->cart->cartPrices['billTotal']  * 100;
                        $this->stripe_public_key = $config_p->get('stripe_public_key');
                    
                        
                    }elseif($this->fxpaymentmethod == 0){//paypal
                        $amount = $this->product_price = $this->cart->cartPrices['billTotal'];
                        $order_info = new stdClass();
                        if(isset($this->cart) && isset($this->cart->virtuemart_order_id)){
                            $order_info->virtuemart_order_id = $this->cart->virtuemart_order_id;
                        }else{
                            $app->redirect($this->continue_link);
                            return;
                        }
                        
                        $order_info->product_name = $this->product_name;
                        $order_info->order_number = $order_number;
                        $order_info->downloadable_order_id = $this->fxbotmarket_id_downloadable_order;
                        $order_info->type_of_product = $type_of_product;
                        $this->restapilink = $this->createDownloadableConfirmLink($amount, $order_info);
                        
                    }
                //$this->restapilink    
            }else{
            $tpl = 'ea';
            //billTotal
            if(isset($this->cart) && isset($this->cart->cartPrices) && isset($this->cart->cartPrices['billTotal'])){
                //if product is downloadable then we do not need to add monthly fee 
                    if(isset($this->cart->cartPrices['salesPricePayment'])){
                        $this->cart->cartPrices['billTotal'] -= $this->cart->cartPrices['salesPricePayment'];
                        $this->cart->cartPrices['salesPricePayment'] = 0;
                    }
                }
            }
            
        }elseif($type_of_product == 1001){
            if($this->layoutName == 'order_done'){//if we need to display order_done page
                $this->setLayout('order_done_blockpad');
                //fxpaymentmethod
                if(array_key_exists('fxbotmarket_id_blockpad_order', $GLOBALS)){
                    //store quicksell order id from #__quicksell_orders table
                    $this->fxbotmarket_id_blockpad_order = (int)$GLOBALS['fxbotmarket_id_blockpad_order'];
                }
                
                if(isset($this->cart->cartPrices['salesPricePayment'])){//we minus order payment/ order payment is used only for signals
                        $this->cart->cartPrices['billTotal'] -= $this->cart->cartPrices['salesPricePayment'];
                        $this->cart->cartPrices['salesPricePayment'] = 0;//$order['details']['BT']->order_total
                    }
                    //we are checking whic payment method customer selected
                    if($this->fxpaymentmethod == 1 || $this->fxpaymentmethod == 2){//stripe card and bitcoin
                        $this->product_price = $this->cart->cartPrices['billTotal']  * 100;
                        $this->stripe_public_key = $config_p->get('stripe_public_key');
                    
                        
                    }elseif($this->fxpaymentmethod == 0){//paypal
                        $amount = $this->product_price = $this->cart->cartPrices['billTotal'];
                        $order_info = new stdClass();
                        if(isset($this->cart) && isset($this->cart->virtuemart_order_id)){
                            $order_info->virtuemart_order_id = $this->cart->virtuemart_order_id;
                        }else{
                            $app->redirect($this->continue_link);
                            return;
                        }
                        
                        $order_info->product_name = $this->product_name;
                        $order_info->order_number = $order_number;
                        $order_info->fxbotmarket_id_blockpad_order = $this->fxbotmarket_id_blockpad_order;
                        $order_info->type_of_product = $type_of_product;
                        $this->restapilink = $this->createBlockConfirmLink($amount, $order_info);
                        
                    }
                //$this->restapilink    
            }else{
            
            if(isset($this->cart) && isset($this->cart->cartPrices) && isset($this->cart->cartPrices['billTotal'])){
                //if product is downloadable then we do not need to add monthly fee 
                    if(isset($this->cart->cartPrices['salesPricePayment'])){
                        $this->cart->cartPrices['billTotal'] -= $this->cart->cartPrices['salesPricePayment'];
                        $this->cart->cartPrices['salesPricePayment'] = 0;
                    }
                }
            $tpl = 'blockpad';
            }
        }else{
            $app->enqueueMessage('Can not find product.','error');
            $app->redirect('index.php?option=com_fxbotmarket&view=customersignals');
        }
                $this->_path['template'][1]=JPATH_ROOT."/plugins/system/vmfxbot/views/cart/tmpl/";//bpm
		parent::display($tpl);
	}
        public function createConfirmLink(){
            if(!class_exists('FxbotmarketLogger')) {
                    include_once JPATH_ROOT.'/components/com_fxbotmarket/helpers/logger.php';
                  }
                $logger = new FxbotmarketLogger();
                $GLOBALS['fxbotmarket_do_file_logs'] = 1;
            //require __DIR__ . '/../bootstrap.php';
            require_once (JPATH_ROOT .'/components/com_fxbotmarket/paypalsdk/paypal/rest-api-sdk-php/sample/bootstrap.php');
            $plan = new Plan();

            // # Basic Information
            // Fill up the basic information that is required for the plan
            $plan->setName("Order:".$this->signal_orderid.", product:".$this->product_name)
                ->setDescription("Order:".$this->signal_orderid.", product:".$this->product_name)
                ->setType('infinite');

            // # Payment definitions for this billing plan.
            $paymentDefinition = new PaymentDefinition();

            // The possible values for such setters are mentioned in the setter method documentation.
            // Just open the class file. e.g. lib/PayPal/Api/PaymentDefinition.php and look for setFrequency method.
            // You should be able to see the acceptable values in the comments.
            /*$paymentDefinition->setName('Regular Payments')
                ->setType('REGULAR')
                ->setFrequency('Month')
                ->setFrequencyInterval("1")
                ->setCycles("12")
                ->setAmount(new Currency(array('value' => 100, 'currency' => 'USD')));
            */
            if(isset($this->cart) && isset($this->cart->cartPrices) && isset($this->cart->cartPrices['billTotal'])){
                $totalSum = $this->cart->cartPrices['billTotal'];
            }else{
                $totalSum = '0';
            }
            $paymentDefinition->setName('Regular Payments')
                ->setType('REGULAR')
                ->setFrequency('MONTH')
                ->setFrequencyInterval("1")
                ->setCycles("0")
                ->setAmount(new Currency(array('value' => $totalSum, 'currency' => 'USD')));
            // Charge Models
            $chargeModel = new ChargeModel();
            $chargeModel->setType('SHIPPING')
                ->setAmount(new Currency(array('value' => 0, 'currency' => 'USD')));

            $paymentDefinition->setChargeModels(array($chargeModel));

            $merchantPreferences = new MerchantPreferences();
            //$baseUrl = getBaseUrl().'/components/com_fxbotmarket/paypalsdk/paypal/rest-api-sdk-php/sample/billing';
            $baseUrl = getBaseUrl().'/index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived&on='.$this->order_number.'&pm=1';
            // ReturnURL and CancelURL are not required and used when creating billing agreement with payment_method as "credit_card".
            // However, it is generally a good idea to set these values, in case you plan to create billing agreements which accepts "paypal" as payment_method.
            // This will keep your plan compatible with both the possible scenarios on how it is being used in agreement.
            $merchantPreferences->setReturnUrl($baseUrl."&success=true")
                ->setCancelUrl($baseUrl."&success=false")
                ->setAutoBillAmount("yes")
                ->setInitialFailAmountAction("CONTINUE")
                ->setMaxFailAttempts("0")
                ->setSetupFee(new Currency(array('value' => $totalSum, 'currency' => 'USD')));
//http://multivendor.my/index.php?option=com_virtuemart&amp;view=vmplg&amp;task=notify&amp;tmpl=component
            

            $plan->setPaymentDefinitions(array($paymentDefinition));
            $plan->setMerchantPreferences($merchantPreferences);

            // For Sample Purposes Only.
            //$request = clone $plan;

            // ### Create Plan
            try {
                $output = $plan->create($apiContext);
            } catch (Exception $ex) {
                // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
                //ResultPrinter::printError("Created Plan", "Plan", null, $request, $ex);
                //ResultPrinter::printError("Created Plan", "Plan", null, $plan, $ex);
                //exit(1);
                $logger->logToFile('paypalcreateConfirmLink.txt','error code -2',true);
                $logger->logToFile('paypalcreateConfirmLink.txt',$ex,true);
                return  -1;
            }

            // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
             //ResultPrinter::printResult("Created Plan", "Plan", $output->getId(), $request, $output);
            $createdPlan = $output;

            try {
                $patch = new Patch();

                $value = new PayPalModel('{
                           "state":"ACTIVE"
                         }');

                $patch->setOp('replace')
                    ->setPath('/')
                    ->setValue($value);
                $patchRequest = new PatchRequest();
                $patchRequest->addPatch($patch);

                $createdPlan->update($patchRequest, $apiContext);

                $plan = Plan::get($createdPlan->getId(), $apiContext);
            } catch (Exception $ex) {
                // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
                //ResultPrinter::printError("Updated the Plan to Active State", "Plan", null, $patchRequest, $ex);
                //exit(1);
                $logger->logToFile('paypalcreateConfirmLink.txt','error code -2',true);
                $logger->logToFile('paypalcreateConfirmLink.txt',$ex,true);
                return -2;
            }

            // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
            // ResultPrinter::printResult("Updated the Plan to Active State", "Plan", $plan->getId(), $patchRequest, $plan);

            $createdPlan =  $plan;

            /* Create a new instance of Agreement object
            {
                "name": "Base Agreement",
                "description": "Basic agreement",
                "start_date": "2015-06-17T9:45:04Z",
                "plan": {
                  "id": "P-1WJ68935LL406420PUTENA2I"
                },
                "payer": {
                  "payment_method": "paypal"
                },
                "shipping_address": {
                    "line1": "111 First Street",
                    "city": "Saratoga",
                    "state": "CA",
                    "postal_code": "95070",
                    "country_code": "US"
                }
            }*/
            $agreement = new Agreement();
            $curtime = time();
            //$curtime += 2592000;//in 30 Days
            $curtime += 86400;//in 1 Day
            $start_date = date('Y-m-d',$curtime).'T'.date('h:i:s',$curtime).'Z';
            $start_date = date('Y-m-d',strtotime('+1 month')).'T'.date('h:i:s',strtotime('+1 month')).'Z';
            //strtotime('+1 month')
/*            
            $agreement->setName('Base Agreement')
                ->setDescription('Basic Agreement')
                ->setStartDate('2019-06-17T9:45:04Z');
  */
      
            $agreement->setName("Order:".$this->signal_orderid.", product:".$this->product_name)
                ->setDescription("Order:".$this->signal_orderid.", product:".$this->product_name)
                ->setStartDate($start_date);
            
            // Add Plan ID
            // Please note that the plan Id should be only set in this case.
            $plan = new Plan();
            $plan->setId($createdPlan->getId());
            $agreement->setPlan($plan);

            // Add Payer
            $payer = new Payer();
            $payer->setPaymentMethod('paypal');
            $agreement->setPayer($payer);

            // Add Shipping Address
            /*
            $shippingAddress = new ShippingAddress();
            $shippingAddress->setLine1('111 First Street')
                ->setCity('Saratoga')
                ->setState('CA')
                ->setPostalCode('95070')
                ->setCountryCode('US');
            $agreement->setShippingAddress($shippingAddress);
            */
            // For Sample Purposes Only.
            //$request = clone $agreement;

            // ### Create Agreement
            try {
                // Please note that as the agreement has not yet activated, we wont be receiving the ID just yet.
                $agreement = $agreement->create($apiContext);

                // ### Get redirect url
                // The API response provides the url that you must redirect
                // the buyer to. Retrieve the url from the $agreement->getApprovalLink()
                // method
                $approvalUrl = $agreement->getApprovalLink();
            } catch (Exception $ex) {
                // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
                //ResultPrinter::printError("Created Billing Agreement.", "Agreement", null, $request, $ex);
                //ResultPrinter::printError("Created Billing Agreement.", "Agreement", null, $agreement, $ex);
                //exit(1);
                return -3;
            }

            // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
            // ResultPrinter::printResult("Created Billing Agreement. Please visit the URL to Approve.", "Agreement", "<a href='$approvalUrl' >$approvalUrl</a>", $request, $agreement);

            //return $agreement;
            // return "<a href='$approvalUrl' >$approvalUrl</a>";
            return "<a href='$approvalUrl' > <img style='width:100px;' src='".JURI::base()."/components/com_fxbotmarket/assets/img/paypal.jpg'/>"."</a>";

        }
        
        public function createBlockConfirmLink($amount = 0, $order_info){
            if(!class_exists('FxbotmarketLogger')) {
                    include_once JPATH_ROOT.'/components/com_fxbotmarket/helpers/logger.php';
                  }
                $logger = new FxbotmarketLogger();
                $GLOBALS['fxbotmarket_do_file_logs'] = 1;
            //require __DIR__ . '/../bootstrap.php';
            require_once (JPATH_ROOT .'/components/com_fxbotmarket/paypalsdk/paypal/rest-api-sdk-php/sample/bootstrap.php');
            $plan = new Plan();

            // # Basic Information
            // Fill up the basic information that is required for the plan
            $plan->setName("Order:".$this->fxbotmarket_id_blockpad_order.", product:".$this->product_name)
                ->setDescription("Order:".$this->fxbotmarket_id_blockpad_order.", product:".$this->product_name)
                ->setType('infinite');

            // # Payment definitions for this billing plan.
            $paymentDefinition = new PaymentDefinition();


            if(isset($this->cart) && isset($this->cart->cartPrices) && isset($this->cart->cartPrices['billTotal'])){
                $totalSum = $this->cart->cartPrices['billTotal'];
            }else{
                $totalSum = '0';
            }
            $paymentDefinition->setName('Regular Payments')
                ->setType('REGULAR')
                ->setFrequency('MONTH')
                ->setFrequencyInterval("1")
                ->setCycles("0")
                ->setAmount(new Currency(array('value' => $totalSum, 'currency' => 'USD')));
            // Charge Models
            $chargeModel = new ChargeModel();
            $chargeModel->setType('SHIPPING')
                ->setAmount(new Currency(array('value' => 0, 'currency' => 'USD')));

            $paymentDefinition->setChargeModels(array($chargeModel));

            $merchantPreferences = new MerchantPreferences();
            //$baseUrl = getBaseUrl().'/components/com_fxbotmarket/paypalsdk/paypal/rest-api-sdk-php/sample/billing';
            $baseUrl = getBaseUrl().'/index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived&on='.$this->order_number.'&pm=1&oi='.$order_info->fxbotmarket_id_blockpad_order.'&top='.$order_info->type_of_product;
            //'&pm=1&oi='.$order_info->downloadable_order_id.'&top='.$order_info->type_of_product
            // ReturnURL and CancelURL are not required and used when creating billing agreement with payment_method as "credit_card".
            // However, it is generally a good idea to set these values, in case you plan to create billing agreements which accepts "paypal" as payment_method.
            // This will keep your plan compatible with both the possible scenarios on how it is being used in agreement.
            $merchantPreferences->setReturnUrl($baseUrl."&success=true")
                ->setCancelUrl($baseUrl."&success=false")
                ->setAutoBillAmount("yes")
                ->setInitialFailAmountAction("CONTINUE")
                ->setMaxFailAttempts("0")
                ->setSetupFee(new Currency(array('value' => $totalSum, 'currency' => 'USD')));
//http://multivendor.my/index.php?option=com_virtuemart&amp;view=vmplg&amp;task=notify&amp;tmpl=component
            

            $plan->setPaymentDefinitions(array($paymentDefinition));
            $plan->setMerchantPreferences($merchantPreferences);

            // For Sample Purposes Only.
            //$request = clone $plan;

            // ### Create Plan
            try {
                $output = $plan->create($apiContext);
            } catch (Exception $ex) {

                $logger->logToFile('paypalcreateBlockConfirmLink.txt','error code -2',true);
                $logger->logToFile('paypalcreateBlockConfirmLink.txt',$ex,true);
                return  -1;
            }

            // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
             //ResultPrinter::printResult("Created Plan", "Plan", $output->getId(), $request, $output);
            $createdPlan = $output;

            try {
                $patch = new Patch();

                $value = new PayPalModel('{
                           "state":"ACTIVE"
                         }');

                $patch->setOp('replace')
                    ->setPath('/')
                    ->setValue($value);
                $patchRequest = new PatchRequest();
                $patchRequest->addPatch($patch);

                $createdPlan->update($patchRequest, $apiContext);

                $plan = Plan::get($createdPlan->getId(), $apiContext);
            } catch (Exception $ex) {
                // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
                //ResultPrinter::printError("Updated the Plan to Active State", "Plan", null, $patchRequest, $ex);
                //exit(1);
                $logger->logToFile('paypalcreateBlockConfirmLink.txt','error code -2',true);
                $logger->logToFile('paypalcreateBlockConfirmLink.txt',$ex,true);
                return -2;
            }

            // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
            // ResultPrinter::printResult("Updated the Plan to Active State", "Plan", $plan->getId(), $patchRequest, $plan);

            $createdPlan =  $plan;

            $agreement = new Agreement();
            $curtime = time();
            //$curtime += 2592000;//in 30 Days
            $curtime += 86400;//in 1 Day
            $start_date = date('Y-m-d',$curtime).'T'.date('h:i:s',$curtime).'Z';
            $start_date = date('Y-m-d',strtotime('+1 month')).'T'.date('h:i:s',strtotime('+1 month')).'Z';
            //strtotime('+1 month')

      
            $agreement->setName("Order:".$this->fxbotmarket_id_blockpad_order.", product:".$this->product_name)
                ->setDescription("Order:".$this->fxbotmarket_id_blockpad_order.", product:".$this->product_name)
                ->setStartDate($start_date);
            
            // Add Plan ID
            // Please note that the plan Id should be only set in this case.
            $plan = new Plan();
            $plan->setId($createdPlan->getId());
            $agreement->setPlan($plan);

            // Add Payer
            $payer = new Payer();
            $payer->setPaymentMethod('paypal');
            $agreement->setPayer($payer);

            try {
                // Please note that as the agreement has not yet activated, we wont be receiving the ID just yet.
                $agreement = $agreement->create($apiContext);

                $approvalUrl = $agreement->getApprovalLink();
            } catch (Exception $ex) {

                return -3;
            }

            return "<a href='$approvalUrl' > <img style='width:100px;' src='".JURI::base()."/components/com_fxbotmarket/assets/img/paypal.jpg'/>"."</a>";

        }
        
        public function createDownloadableConfirmLink($amount = 0, $order_info = false){
            if(!is_object($order_info)){
                return '#';
            }
            $invoiceID = uniqid();

            //FxbotmarketUser
            if(!class_exists('FxbotmarketUser')) {
                include_once JPATH_ROOT.'/components/com_fxbotmarket/helpers/fxbotmarketuser.php';
            }
            $userInfo = FxbotmarketUser::getUserBaseData();
            $apiContext = require_once (JPATH_ROOT .'/components/com_fxbotmarket/paypalsdk/paypal/rest-api-sdk-php/sample/bootstrap.php');
                $payer = new Payer();
                $payer->setPaymentMethod("paypal");
                //$profilemodel = $this->getModel('Profile', 'UuModel');
                //$amount = $this->amount;
                // ### Itemized information
                // (Optional) Lets you specify item wise
                // information
                $item1 = new Item();//product_name
                /*$baseUrl = getBaseUrl().'/index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived&on='.$this->order_number.'&pm=1';
                // ReturnURL and CancelURL are not required and used when creating billing agreement with payment_method as "credit_card".
                // However, it is generally a good idea to set these values, in case you plan to create billing agreements which accepts "paypal" as payment_method.
                // This will keep your plan compatible with both the possible scenarios on how it is being used in agreement.
                $merchantPreferences->setReturnUrl($baseUrl."&success=true")
                    ->setCancelUrl($baseUrl."&success=false")*/
                $item1->setName(' '.$userInfo->firstname.' '.$userInfo->lastname.' bought downloadable  product '.$order_info->product_name.'. order #'.$order_info->downloadable_order_id)
                    ->setCurrency('USD')
                    ->setQuantity(1)
                    ->setSku($userInfo->email) // Similar to `item_number` in Classic API
                    ->setPrice($amount);


                $itemList = new ItemList();
                $itemList->setItems(array($item1));

                // ### Additional payment details
                // Use this optional field to set additional
                // payment information such as tax, shipping
                // charges etc.
                $details = new Details();
                $details->setShipping(0)
                    ->setTax(0)
                    ->setSubtotal($amount);

                // ### Amount
                // Lets you specify a payment amount.
                // You can also specify additional details
                // such as shipping, tax.
                $amountobj = new Amount();
                $amountobj->setCurrency("USD")
                    ->setTotal($amount)
                    ->setDetails($details);

                // ### Transaction
                // A transaction defines the contract of a
                // payment - what is the payment for and who
                // is fulfilling it. 
                $transaction = new Transaction();


                $transaction->setAmount($amountobj)
                    ->setItemList($itemList)
                    ->setDescription(' '.$userInfo->firstname.' '.$userInfo->lastname.' bought downloadable  product '.$order_info->product_name.'. order #'.$order_info->downloadable_order_id)
                    ->setInvoiceNumber($invoiceID);

                // ### Redirect urls
                // Set the urls that the buyer must be redirected to after 
                // payment approval/ cancellation.
                $baseUrl = getBaseUrl().'/index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived&on='.$order_info->order_number.'&pm=1&oi='.$order_info->downloadable_order_id.'&top='.$order_info->type_of_product;
                $redirectUrls = new RedirectUrls();
                //$redirectUrls->setReturnUrl("$baseUrl/ExecutePayment.php?success=true")
                /*$redirectUrls->setReturnUrl("$baseUrl/index.php?option=com_fxbotmarket&task=payseller.paysellerfeepaypalactive&success=true&orderid=".$product_info->id)
                    ->setCancelUrl("$baseUrl/index.php?option=com_fxbotmarket&task=payseller.paysellerfeepaypalactive&success=true&orderid=".$product_info->id);
                */
                $redirectUrls->setReturnUrl($baseUrl."&success=true")
                    ->setCancelUrl($baseUrl."&success=false");

    //$return_url
                // ### Payment
                // A Payment Resource; create one using
                // the above types and intent set to 'sale'
                $payment = new Payment();
                $payment->setIntent("sale")
                    ->setPayer($payer)
                    ->setRedirectUrls($redirectUrls)
                    ->setTransactions(array($transaction));


                // For Sample Purposes Only.
                //$request = clone $payment;

                // ### Create Payment
                // Create a payment by calling the 'create' method
                // passing it a valid apiContext.
                // (See bootstrap.php for more on `ApiContext`)
                // The return object contains the state and the
                // url to which the buyer must be redirected to
                // for payment approval
                try {
                    $payment->create($apiContext);
                } catch (Exception $ex) {
                    // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
                    //ResultPrinter::printError("Created Payment Using PayPal. Please visit the URL to Approve.", "Payment", null, $request, $ex);
                    //$model->log_pay_transaction(4, 0,$ex);
                    return 'There is some problems with paypal gateway. Try later or use other payment methods';
                    //exit(1);
                }
                $approval_link = $payment->getApprovalLink();
                return "<a href='$approval_link' > <img style='width:100px;' src='".JURI::base()."/components/com_fxbotmarket/assets/img/paypal.jpg'/>"."</a>";
                // ### Get redirect url
                // The API response provides the url that you must redirect
                // the buyer to. Retrieve the url from the $payment->getApprovalLink()
                // method
    }
    
	private function lSelectCoupon() {

		$this->couponCode = (!empty($this->cart->couponCode) ? $this->cart->couponCode : '');
		$this->coupon_text = $this->cart->couponCode ? vmText::_('COM_VIRTUEMART_COUPON_CODE_CHANGE') : vmText::_('COM_VIRTUEMART_COUPON_CODE_ENTER');
	}

	/**
	* lSelectShipment
	* find al shipment rates available for this cart
	*
	* @author Valerie Isaksen
	*/

	private function lSelectShipment() {
		$found_shipment_method=false;
		$shipment_not_found_text = vmText::_('COM_VIRTUEMART_CART_NO_SHIPPING_METHOD_PUBLIC');
		$this->assignRef('shipment_not_found_text', $shipment_not_found_text);
		$this->assignRef('found_shipment_method', $found_shipment_method);

		$shipments_shipment_rates=array();
		if (!$this->checkShipmentMethodsConfigured()) {
			$this->assignRef('shipments_shipment_rates',$shipments_shipment_rates);
			return;
		}

		$selectedShipment = (empty($this->cart->virtuemart_shipmentmethod_id) ? 0 : $this->cart->virtuemart_shipmentmethod_id);

		$shipments_shipment_rates = array();
		if (!class_exists('vmPSPlugin')) require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
		JPluginHelper::importPlugin('vmshipment');
		$dispatcher = JDispatcher::getInstance();

		$d = VmConfig::$_debug;
		if(VmConfig::get('debug_enable_methods',false)){
			VmConfig::$_debug = 1;
		}
		$returnValues = $dispatcher->trigger('plgVmDisplayListFEShipment', array( $this->cart, $selectedShipment, &$shipments_shipment_rates));
		VmConfig::$_debug = $d;
		// if no shipment rate defined
		$found_shipment_method =count($shipments_shipment_rates);

		$ok = true;
		if ($found_shipment_method == 0)  {
			$validUserDataBT = $this->cart->validateUserData();

			if ($validUserDataBT===-1) {
				if (VmConfig::get('oncheckout_opc', 1)) {
					vmdebug('lSelectShipment $found_shipment_method === 0 show error');
					$ok = false;
				} else {
					$mainframe = JFactory::getApplication();
					$mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=user&task=editaddresscart&addrtype=BT'), vmText::_('COM_VIRTUEMART_CART_ENTER_ADDRESS_FIRST'));
				}
			}

		}
		if(empty($selectedShipment)){
			if($s_id = VmConfig::get('set_automatic_shipment',false)){
				$j = 'radiobtn = document.getElementById("shipment_id_'.$s_id.'");
				if(radiobtn!==null){ radiobtn.checked = true;}';
				vmJsApi::addJScript('autoShipment',$j);
			}
		}

		$shipment_not_found_text = vmText::_('COM_VIRTUEMART_CART_NO_SHIPPING_METHOD_PUBLIC');
		$this->assignRef('shipment_not_found_text', $shipment_not_found_text);
		$this->assignRef('shipments_shipment_rates', $shipments_shipment_rates);
		$this->assignRef('found_shipment_method', $found_shipment_method);

		return $ok;
	}

	/*
	 * lSelectPayment
	* find al payment available for this cart
	*
	* @author Valerie Isaksen
	*/

	private function lSelectPayment() {

		$this->payment_not_found_text='';
		$this->payments_payment_rates=array();

		$this->found_payment_method = 0;
		$selectedPayment = empty($this->cart->virtuemart_paymentmethod_id) ? 0 : $this->cart->virtuemart_paymentmethod_id;

		$this->paymentplugins_payments = array();
		if (!$this->checkPaymentMethodsConfigured()) {
			return;
		}

		if(!class_exists('vmPSPlugin')) require(JPATH_VM_PLUGINS.DS.'vmpsplugin.php');
		JPluginHelper::importPlugin('vmpayment');
		$dispatcher = JDispatcher::getInstance();
		$d = VmConfig::$_debug;
		if(VmConfig::get('debug_enable_methods',false)){
			VmConfig::$_debug = 1;
		}
		$returnValues = $dispatcher->trigger('plgVmDisplayListFEPayment', array($this->cart, $selectedPayment, &$this->paymentplugins_payments));
		VmConfig::$_debug = $d;

		$this->found_payment_method =count($this->paymentplugins_payments);
		if (!$this->found_payment_method) {
			$link=''; // todo
			$this->payment_not_found_text = vmText::sprintf('COM_VIRTUEMART_CART_NO_PAYMENT_METHOD_PUBLIC', '<a href="'.$link.'" rel="nofollow">'.$link.'</a>');
		}

		$ok = true;
		if ($this->found_payment_method == 0 )  {
			$validUserDataBT = $this->cart->validateUserData();
			if ($validUserDataBT===-1) {
				if (VmConfig::get('oncheckout_opc', 1)) {
					$ok = false;
				} else {
					$mainframe = JFactory::getApplication();
					$mainframe->redirect( JRoute::_( 'index.php?option=com_virtuemart&view=user&task=editaddresscart&addrtype=BT' ), vmText::_('COM_VIRTUEMART_CART_ENTER_ADDRESS_FIRST') );
				}
			}
		}

		if(empty($selectedPayment)){
			if($p_id = VmConfig::get('set_automatic_payment',false)){
				$j = 'radiobtn = document.getElementById("payment_id_'.$p_id.'");
				if(radiobtn!==null){ radiobtn.checked = true;}';
				vmJsApi::addJScript('autoPayment',$j);
			}
		}

		return $ok;
	}

	private function getTotalInPaymentCurrency() {

		if (empty($this->cart->virtuemart_paymentmethod_id)) {
			return null;
		}

		if (!$this->cart->paymentCurrency or ($this->cart->paymentCurrency==$this->cart->pricesCurrency)) {
			return null;
		}

		$paymentCurrency = CurrencyDisplay::getInstance($this->cart->paymentCurrency);
		$totalInPaymentCurrency = $paymentCurrency->priceDisplay( $this->cart->cartPrices['billTotal'],$this->cart->paymentCurrency) ;
		$this->currencyDisplay = CurrencyDisplay::getInstance($this->cart->pricesCurrency);

		return $totalInPaymentCurrency;
	}


	private function lOrderDone() {

		$this->display_title = !isset($this->display_title) ? vRequest::getBool('display_title', true) : $this->display_title;
		$this->display_loginform = !isset($this->display_loginform) ? vRequest::getBool('display_loginform', true) : $this->display_loginform;

		//Do not change this. It contains the payment form
		$this->html = !isset($this->html) ? vRequest::get('html', vmText::_('COM_VIRTUEMART_ORDER_PROCESSED')) : $this->html;
		//Show Thank you page or error due payment plugins like paypal express
	}

	private function checkPaymentMethodsConfigured() {

		//For the selection of the payment method we need the total amount to pay.
		$paymentModel = VmModel::getModel('Paymentmethod');
		$payments = $paymentModel->getPayments(true, false);
		if (empty($payments)) {

			$text = '';
			if(vmAccess::manager() or vmAccess::isSuperVendor()) {
				$link = JURI::root() . 'administrator/index.php?option=com_virtuemart&view=paymentmethod';
				$text = vmText::sprintf('COM_VIRTUEMART_NO_PAYMENT_METHODS_CONFIGURED_LINK', '<a href="' . $link . '" rel="nofollow">' . $link . '</a>');
			}

			vmInfo('COM_VIRTUEMART_NO_PAYMENT_METHODS_CONFIGURED', $text);
			$this->cart->virtuemart_paymentmethod_id = 0;
			return false;
		}
		return true;
	}

	private function checkShipmentMethodsConfigured() {

		//For the selection of the shipment method we need the total amount to pay.
		$shipmentModel = VmModel::getModel('Shipmentmethod');
		$shipments = $shipmentModel->getShipments();
		if (empty($shipments)) {

			$text = '';
			$user = JFactory::getUser();
			if(vmAccess::manager() or vmAccess::isSuperVendor()) {
				$uri = JFactory::getURI();
				$link = $uri->root() . 'administrator/index.php?option=com_virtuemart&view=shipmentmethod';
				$text = vmText::sprintf('COM_VIRTUEMART_NO_SHIPPING_METHODS_CONFIGURED_LINK', '<a href="' . $link . '" rel="nofollow">' . $link . '</a>');
			}

			vmInfo('COM_VIRTUEMART_NO_SHIPPING_METHODS_CONFIGURED', $text);

			$tmp = 0;
			$this->assignRef('found_shipment_method', $tmp);
			$this->cart->virtuemart_shipmentmethod_id = 0;
			return false;
		}
		return true;
	}

	/**
	 * Todo, works only for small stores, we need a new solution there with a bit filtering
	 * For example by time, if already shopper, and a simple search
	 * @return object list of users
	 */
	function getUserList() {

		$result = false;

		if($this->allowChangeShopper){
			$this->adminID = vmAccess::getBgManagerId();
			$superVendor = vmAccess::isSuperVendor($this->adminID);
			if($superVendor){
				$uModel = VmModel::getModel('user');
				$result = $uModel->getSwitchUserList($superVendor,$this->adminID);
			}
		}
		if(!$result) $this->allowChangeShopper = false;
		return $result;
	}

	function renderCompleteAddressList(){

		$addressList = false;

		if($this->cart->user->virtuemart_user_id){
			$addressList = array();
			$newBT = vmText::_('COM_VIRTUEMART_ACC_BILL_DEF') . '<br/>';
			foreach($this->cart->user->userInfo as $userInfo){
				$address = $userInfo->loadFieldValues(false);
				if($address->address_type=='BT'){
					$address->virtuemart_userinfo_id = 0;
					$address->address_type_name = $newBT;
					array_unshift($addressList,$address);
				} else {
					$address->address_type_name = !empty($address->zip) ? $address->address_type_name . ' - ' . $address->zip : $address->address_type_name . '<br/>';
					$addressList[] = $address;
				}
			}
			if(count($addressList)==0){
				$addressList[0] = new stdClass();
				$addressList[0]->virtuemart_userinfo_id = 0;
				$addressList[0]->address_type_name = $newBT;
			}

			$_selectedAddress = (
			empty($this->cart->selected_shipto)
				? $addressList[0]->virtuemart_userinfo_id // Defaults to 1st BillTo
				: $this->cart->selected_shipto
			);

			$this->cart->lists['shipTo'] = JHtml::_('select.radiolist', $addressList, 'shipto', null, 'virtuemart_userinfo_id', 'address_type_name', $_selectedAddress);
			$this->cart->lists['billTo'] = empty($addressList[0]->virtuemart_userinfo_id)? 0 : $addressList[0]->virtuemart_userinfo_id;
		} else {
			$this->cart->lists['shipTo'] = false;
			$this->cart->lists['billTo'] = false;
		}
	}

	static public function addCheckRequiredJs(){

		$updF = '';
		if( VmConfig::get('oncheckout_ajax',false)) {
			$updF = 'Virtuemart.updForm();';
		}

		$j='jQuery(document).ready(function(){
    form = jQuery("#checkoutFormSubmit");
    jQuery(".output-shipto").find(":radio").change(function(){
		form.attr("task","checkout");
		'.$updF.'
		form.submit();
    });

    jQuery(".required").change(function(){
    	var count = 0;
    	var hit = 0;
    	jQuery.each(jQuery(".required"), function (key, value){
    		count++;
    		if(jQuery(this).attr("checked")){
        		hit++;
       		}
    	});
        if(count==hit){
        	form.attr("task","checkout");

			'.$updF.'
			form.submit();
        } else {
        	form.attr("task","checkout");
        }
    });
    
    jQuery("#checkoutForm").change(function(){
    	var task = form.attr("task");
    	if(task=="checkout"){
    		form.html("<span>'.vmText::_('COM_VIRTUEMART_CHECKOUT_TITLE').'</span>");
    	} else {
    		form.html("<span>'.vmText::_('COM_VIRTUEMART_ORDER_CONFIRM_MNU').'</span>");
    	}
		form.attr("name",task);
		
    });

});';
		vmJsApi::addJScript('autocheck',$j);
	}
        
        function getServersOptions($value = 0){
            $db = JFactory::getDbo();
            $q = 'SELECT id,server FROM #__fxbotmarketx_signal_servers ';
            $db->setQuery($q);
            $list = $db->loadObjectList();
            $options = '';
            $selected = '';
            foreach($list as $server){
                if($value == $server->id){
                  $selected = 'selected';  
                }
                $options .= '<option value="'.$server->id.'" '.$selected.'>'.$server->server.'</option>';
            }
            return $options;
        }
        
}

//no closing tag
