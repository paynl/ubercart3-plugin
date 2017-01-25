<?php

/*
 * Register the return and exchange (aka IPN) URLs
 */
function paynl_main_menu() {
	$items['cart/paynl/return'] = array(
    	'title' => 'Pay.nl betaling',
    	'page callback' => 'paynl_return',
    	'access arguments' => array('access content'),
    	'type' => MENU_CALLBACK,
  		);
  $items['cart/paynl/exchange'] = array(
    'title' => 'Pay.nl betaling',
    'page callback' => 'paynl_exchange',
    'access arguments' => array('access content'),
    'type' => MENU_CALLBACK,
    );
	return $items;
}


/*
 * Create a transaction and redirect to the payment form.
 */
function paynl_place_order( $order, $methodId )
{  
  global $base_url;
  require_once __DIR__ . '/includes/classes/Autoload.php';
  
  $objStartApi = new Pay_Api_Start();
  // token en serviceId setten
  $objStartApi->setApiToken( variable_get('paynl_settings_api_token', '') );
  $objStartApi->setServiceId( variable_get('paynl_settings_service_id', '') );

  // return to this url after payment
  $objStartApi->setFinishUrl( $base_url .'/cart/paynl/return?' );
  //IPN URL
  $objStartApi->setExchangeUrl( $base_url .'/cart/paynl/exchange?' );
  
  $arrBillingCountries = uc_get_country_data(array('country_id' => $order->billing_country));
  if( !$arrBillingCountries ) $arrBillingCountries = array( array( 'country_iso_code_2' => 'NL') );
  $arrBillingCountry = $arrBillingCountries[0];
  
  $arrDeliveryCountries = uc_get_country_data(array('country_id' => $order->delivery_country));
  if( !$arrDeliveryCountries ) $arrDeliveryCountries = array( array( 'country_iso_code_2' => 'NL') );
  $arrDeliveryCountry = $arrDeliveryCountries[0];
  
  $arrDeliveryStreet = _paynl_split_address($order->delivery_street1 .' '. $order->delivery_street2);
  $arrBillingStreet  = _paynl_split_address($order->billing_street1 .' '. $order->billing_street2);
  
  $enduser = array(
    'initals'               => _paynl_to_initials( $order->billing_first_name ), 
    'lastName'              => $order->billing_last_name,
    //'language'              => '',
    //'accessCode'            => '',
    //'gender (M or F)'       => '',
    //'dob (DD-MM-YYYY)'      => '',
    'phoneNumber'           => $order->billing_phone,
    'emailAddress'          => $order->primary_email,
    //'bankAccount'           => '',
    //'iban'                  => '',
    //'bic'                   => '',
    //'sendConfirmMail'       => '',
    //'confirmMailTemplate'   => '',
    'address' => array(
        'streetName'   => $arrDeliveryStreet[0],
        'streetNumber' => $arrDeliveryStreet[1],
        'zipCode'      => $order->delivery_postal_code,
        'city'         => $order->delivery_city,
        'countryCode'  => $arrDeliveryCountry['country_iso_code_2'],
    ),
    'invoiceAddress' => array(
        'initials'     => _paynl_to_initials( $order->billing_first_name ),
        'lastname'     => $order->billing_last_name,
        'streetName'   => $arrBillingStreet[0],
        'streetNumber' => $arrBillingStreet[1],
        'zipCode'      => $order->billing_postal_code,
        'city'         => $order->billing_city,
        'countryCode'  => $arrBillingCountry['country_iso_code_2'],
      ),
    );
  $objStartApi->setEnduser($enduser);
  
  /*
   *  Add products
   */

  /* not necessary to take tax in to account at this moment 
  $arrTaxCodes = array(
    '0' => 'H',
    '1' => 'H',
    '2' => 'L',
    '3' => 'N'
  );
  */
  foreach($order->products as $product)
  {
    print $objStartApi->addProduct(
      $product->order_product_id,
      $product->title,
      _paynl_to_cents( $product->price ),
      $product->qty,
      'H'
   );
  }
  
  // add all the other stuff (tax, shipping costs, payment method fee, probably even coupons if they have that module installed)
  foreach($order->line_items as $item)
  {
    print $objStartApi->addProduct(
      $item['line_item_id'],
      $item['title'] .' ('. $item['type'] .')',
      _paynl_to_cents( $item['amount'] ),
      1,
      'H'
   );
  }

  $objStartApi->setPaymentOptionId( $methodId );
  if( isset($_SESSION['paynl']['methodSubId']) && $_SESSION['paynl']['methodSubId'] )
  {
    $objStartApi->setPaymentOptionSubId( $_SESSION['paynl']['methodSubId'] );
  }
  
  $objStartApi->setAmount( _paynl_to_cents($order->order_total) );
  $objStartApi->setDescription( 'Order ' . $order->order_id );

  //save the cubecart orderid so we can refer to this when the pay.nl exchange pings back
  $objStartApi->setExtra1( $order->order_id );

  //create transaction
  $arrTransaction = $objStartApi->doRequest();
  
  header("Location: ". $arrTransaction['transaction']['paymentURL']);
  exit;
}



/* Handle the IPN callback.
 * 
 */
function paynl_exchange()
{
  if( ! isset( $_GET['order_id'] ) ||  $_GET['order_id'] === "") exit;
  
    $result = _paynl_order_update_status($_GET['order_id']);

  echo $result['echo'];
  exit;
}

function paynl_return() 
{
  if(! isset($_GET['orderId']) || ! $_GET['orderId'] ){
    watchdog('Pay.nl', 'No order ID specified.', array(), WATCHDOG_ERROR);
    drupal_set_message(t('Payment could not be checked. The order ID was not specified.'), 'error');
    drupal_goto( 'cart/checkout' );
    exit;
  }
  
  $result = _paynl_order_update_status($_GET['orderId']);
  
  drupal_goto( $result['goto'] );
  exit;
}


function _paynl_order_update_status($strPaynlOrderId)
{
  require_once __DIR__ . '/includes/classes/Autoload.php';
  
  try
  {
    // get information about the transaction
    $objApiInfo = new Pay_Api_Info();
    $objApiInfo->setApiToken( variable_get('paynl_settings_api_token', '') );
    $objApiInfo->setServiceId( variable_get('paynl_settings_service_id', '') );

    $objApiInfo->setTransactionId( $strPaynlOrderId );
    //grab info about the pay.nl transaction
    $arrApiInfoResult = $objApiInfo->doRequest();
    
    $strOrderId   = $arrApiInfoResult['statsDetails']['extra1'];
    $amountPaid   = (float) $arrApiInfoResult['paymentDetails']['paidAmount'] / 100;
    $strState     = $arrApiInfoResult['paymentDetails']['state'];
    $strStateText = Pay_Helper::getStateText($strState);
  }
  catch(Exception $e)
  {
    // for both exchange and return
    watchdog('Pay.nl',
    'Error retrieving order information from Pay.nl with order !order_id: !message',
    array('!order_id' => $strPaynlOrderId, 
          '!message'  => $e->getMessage()), 
    WATCHDOG_ERROR);
   
    // for return
    drupal_set_message(
        t('Could not process the payment. There was an error: !message', 
            array('!message' => $e->getMessage() )), 
        'error');
    
    return array(
      'echo' => "TRUE|De Pay.nl API gaf de volgende fout:<br />" . $e->getMessage(),
      'goto' => 'cart/checkout'
    );
  }
  
  // IPN only
  if( !uc_order_exists($strOrderId) )
  {
    watchdog('Pay.nl', 'IPN request not correct. Order with order id !order_id does not exist.', array('!order_id' => $strOrderId), WATCHDOG_ERROR);
    return array(
      'echo' => "TRUE|IPN verzoek niet correct; order met order_id $strOrderId bestaat niet.",
      'goto' => '/cart/checkout'
    );
  };
  
  $order = uc_order_load($strOrderId);

  // don't update the status if the order has been successfully handled (IPN only)
  if( $order->order_status == uc_order_state_default('payment_received') || 
      $order->order_status == uc_order_state_default('completed'))
  {
    @$_SESSION['uc_checkout'][$_SESSION['cart_order']]['do_complete'] = true;

    return array(
      'echo' => "TRUE|Statusupdate ontvangen, de order is al betaald dus statusupdate is niet doorgevoerd. Pay.nl status is: " . $strState . ' (' . $strStateText . ')',
      'goto' => 'cart/checkout/complete'
    );
  }

  $goto = 'cart/checkout';
  $echo = 'TRUE|Statusupdate ontvangen. Status is: ' . $strState . ' (' . $strStateText . ')';  
  
  switch( $arrApiInfoResult['paymentDetails']['stateName'] )
  {
    case 'PAID':
      $strMessage = t('Payment with !method succeeded for order !order_id', 
        array('!order_id' => $strOrderId,
              '!method'   => _uc_payment_method_data($order->payment_method, 'name') . ' via Pay.nl')
      );
      uc_payment_enter(
        $strOrderId, 
        $order->payment_method, 
        $amountPaid, 
        0, 
        array('Pay.nl order id' => $strPaynlOrderId), 
        $strMessage
      );
      uc_order_update_status( $strOrderId, uc_order_state_default('payment_received') );
      uc_order_comment_save($strOrderId, 0, $strMessage, 'admin', uc_order_state_default('payment_received') );
      uc_order_comment_save($strOrderId, 0, $strMessage, 'order', uc_order_state_default('payment_received') );
      
      watchdog(
        'Pay.nl', 
        'Payment with !method for order !order_id sucessful', 
        array('!order_id' => $strOrderId,
              '!method'   => _uc_payment_method_data($order->payment_method, 'name') . ' via Pay.nl'),
        WATCHDOG_INFO
      );
      
      $_SESSION['uc_checkout'][$_SESSION['cart_order']]['do_complete'] = true;
      
      $goto = 'cart/checkout/complete';
      break;
    
    case 'PENDING':
      $strMessage = t('Payment with !method in progress for order !order_id',
        array('!order_id' => $strOrderId, 
              '!method'   => _uc_payment_method_data($order->payment_method, 'name') . ' via Pay.nl')
      );
      
      uc_order_update_status( $strOrderId, uc_order_state_default('post_checkout') );
      uc_order_comment_save($strOrderId, 0, $strMessage, 'admin');
      uc_order_comment_save($strOrderId, 0, $strMessage, 'admin', uc_order_state_default('post_checkout') );
      uc_order_comment_save($strOrderId, 0, $strMessage, 'order', uc_order_state_default('post_checkout') );
      
      watchdog(
        'Pay.nl', 
        'Payment with !method in progress for order !order_id', 
        array('!order_id' => $strOrderId, 
              '!method'   => _uc_payment_method_data($order->payment_method, 'name') . ' via Pay.nl'),
        WATCHDOG_INFO
      );
      
      $goto = 'cart/checkout/complete';
      break;
    
    case 'CANCEL':
      $strMessage = t('Pament with !method cancelled for order !order_id',
        array('!order_id' => $strOrderId,
              '!method'   => _uc_payment_method_data($order->payment_method, 'name') . ' via Pay.nl')
      );

      uc_order_comment_save($strOrderId, 0, $strMessage, 'admin');
      uc_order_update_status( $strOrderId, uc_order_state_default('canceled') );
      uc_order_comment_save($strOrderId, 0, $strMessage, 'admin', uc_order_state_default('canceled') );
      uc_order_comment_save($strOrderId, 0, $strMessage, 'order', uc_order_state_default('canceled') );
      
      watchdog(
        'Pay.nl',
        'Pament with !method cancelled for order !order_id',
        array('!order_id' => $strOrderId,
              '!method'   => _uc_payment_method_data($order->payment_method, 'name') . ' via Pay.nl'),
        WATCHDOG_WARNING
      );
      
      drupal_set_message(t('The payment was cancelled. Please try again.'), 'error');
      
      $goto = 'cart/checkout';
      break;
    
    default:
      // API did not return a correct status. pretend it was cancelled to the user
      drupal_set_message(t('The payment was cancelled. Please try again.'), 'error');
      
      $goto = 'cart/checkout';
  }
  
  return array(
    'goto' => $goto,
    'echo' => $echo 
  );
  
}
function _paynl_to_initials( $name )
{
  $arrNames    = explode(' ', $name);
  $strInitials = '';
  foreach($arrNames as $strName)
  {
    $strInitials .= substr($strName, 0, 1);
  }
  return strtoupper($strInitials);
}

function _paynl_to_cents( $amount )
{
  return round( 100 * (float) $amount );
}


/**
 * Extract the streetnumber from adress, so we can feed it to the api.
 * 
 * @param string $strAddress
 * @return array
 */
function _paynl_split_address($strAddress)
{
  $strAddress = trim($strAddress);

  $a = preg_split('/([0-9]+)/', $strAddress, 2, PREG_SPLIT_DELIM_CAPTURE);
  $strStreetName = trim(array_shift($a));
  $strStreetNumber = trim(implode('', $a));

  if(empty($strStreetName))
  { // American address notation
    $a = preg_split('/([a-zA-Z]{2,})/', $strAddress, 2, PREG_SPLIT_DELIM_CAPTURE);

    $strStreetNumber = trim(array_shift($a));
    $strStreetName = implode(' ', $a);
  }

  return array($strStreetName, $strStreetNumber);
}


function _paynl_show_payment_options($form_state, $order, $method_id)
{
  $payment_options = variable_get('paynl_payment_options', array());
  
  $form = array();
  if( isset($payment_options[$method_id]) )
  {
  
    $form['methodSubId'] = array(
      '#type' => 'radios',
      '#title' => t('Choose your bank:'),
      '#options' => $payment_options[$method_id],
      '#description' => '',
      );
  }
	return $form;
}

/* Fetch all sub options per payment method associated with 
 * the supplied api token and service id.
 * 
 * @param  string  $strApiToken
 * @param  string  $strServiceId
 * @return  array
 */
function _paynl_fetch_payment_sub_options( $strApiToken, $strServiceId )
{
  require_once __DIR__ . '/includes/classes/Autoload.php';
  try
  {
    $objApiInfo = new Pay_Api_Getservice();
    $objApiInfo->setApiToken( $strApiToken );
    $objApiInfo->setServiceId( $strServiceId );
    $arrServiceResult = $objApiInfo->doRequest();

    //Resultaat opschonen
    $paymentSubOptions = array();

    foreach($arrServiceResult['paymentOptions'] as $paymentOption)
    {
      if(!empty($paymentOption['paymentOptionSubList']))
      {
        $paymentSubOptions[$paymentOption['id']] = array();

        foreach($paymentOption['paymentOptionSubList'] as $subItem)
        {
          $paymentSubOptions[$paymentOption['id']][$subItem['id']] = $subItem['visibleName'];
        }
      }
    }
  }
  catch(Exception $ex)
  {
    return array();
  }

  return $paymentSubOptions;
}
