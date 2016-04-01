<?php
/*
**********************************************

     *** GateCX Checkout for WHMCS ***

File:					stripecx/callback.php
File version:			0.0.3
Date:					27-10-2015

Copyright (C) NetDistrict 2014 - 2016
All Rights Reserved
**********************************************
*/

use WHMCS\Module\Gateway;
use WHMCS\Terminus;
use Illuminate\Database\Capsule\Manager as Capsule;

# Required File Includes
include "../../../init.php";
include ROOTDIR . DIRECTORY_SEPARATOR . 'includes/functions.php';
include ROOTDIR . DIRECTORY_SEPARATOR . 'includes/gatewayfunctions.php';
include ROOTDIR . DIRECTORY_SEPARATOR . 'includes/invoicefunctions.php';

$gatewaymodule = "stripecx";

function stripecx_capture($amount,$currency,$token,$invoice)
{	
	global $gateway;
	
	$GATEWAY = $gateway->getParams();
	
	// Post data variables
	$data = 'amount='.$amount.'&currency='.$currency.'&card='.$token.'&description='.$invoice;
	
	// Send Capture Request
    $ch = curl_init('https://api.stripe.com/v1/charges');

	curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Client: GateCX v0.0.3'));
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_USERPWD, $GATEWAY['private-key'] . ":" . NULL);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
	curl_setopt($ch, CURLOPT_CAINFO, realpath(dirname(__FILE__) . "/cacert.pem"));

	$return = curl_exec($ch);

	curl_close($ch);
    return $return;
}

# Ensure that the module is active before attempting to run any code
$gateway = new Gateway();

if (!$gateway->isActiveGateway($gatewaymodule) || !$gateway->load($gatewaymodule)) {
    Terminus::getInstance()->doDie('Module not Active');
}

$pdo = Capsule::connection()->getPdo();

# Process and store transaction	
if (isset($_REQUEST['id']) && !empty($_REQUEST['id'])) {
 
	$ca = new WHMCS_ClientArea();
		 
	# Check login status
	if ($ca->isLoggedIn()) {
		
		$invoice_id = (int)$_REQUEST['id'];
		$store_token = $_REQUEST['token'];
		$currency = $_REQUEST['currency'];
		$amount = (int)$_REQUEST['amount'];
								
		if (preg_match("#^[A-Z]{3}$#", $currency) && preg_match("#^[a-zA-Z 0-9\_\-]*$#", $store_token)) {

			//Get invoice details
			$sql = "SELECT * FROM tblinvoices WHERE id = '$invoice_id'";			
			$result = $pdo->query($sql);
			$row = $result->fetch(PDO::FETCH_ASSOC);
			
			$invoice_num = $row['invoicenum'];
			
			if ($invoice_num == NULL) $invoice_num = $invoice_id;
						
			//Create Capture request
			$capture = json_decode(stripecx_capture($amount,$currency,$token,$invoice_num));

			if ($capture->id == NULL) {
				if ($capture->error->charge) {
					$store_transaction = $capture->error->charge;
				} elseif ($capture->error) {
					logActivity('GateCX capture failed: '.$capture->error->message);		
				}
			} else {
				$store_transaction = $capture->id;
			}

			if (!empty($store_transaction)) {						
				$sql = "INSERT INTO stripecx_transactions (invoice_id, transaction_id) VALUES ('$invoice_id', '$store_transaction')";
				$result = $pdo->query($sql);
			}
		}
	} else {
		logActivity('GateCX error: Unable to store transaction details for invoice ID: '.(int)$_REQUEST['id']);	
	}
		
} else {

	//Process Payment	
	$GATEWAY = getGatewayVariables($gatewaymodule);
	if (!$GATEWAY["type"]) die("Module Not Activated"); # Checks gateway module is active before accepting callback

	// Retrieve the request's body and parse it as JSON
	$input = @file_get_contents("php://input");
	$event_json = json_decode($input);
	
	if (!$event_json) { 
		header("HTTP/1.1 400 BAD REQUEST"); 
		exit();
	}
	
	// Process returned data
	$transid = $event_json->data->object->id;
	$type = $event_json->type;
	$amount = ($event_json->data->object->amount) / 100;
	$currency = $event_json->data->object->currency;
	$fail_code = $event_json->data->object->failure_code;
	$fail_desc = $event_json->data->object->failure_message;
	$fee = (($amount * 0.029) + 0.30);
	
	/* Has the currency been converted for processing? Let's find out. */
	// Get User ID
	$sql = "SELECT userid FROM tblaccounts WHERE transid = '$transid'";
	$result = $pdo->query($sql);
	$row = $result->fetch(PDO::FETCH_ASSOC);
	
	$uid = $row['userid'];
	
	// What's user's default currency?
	$sql = "SELECT currency FROM tblclients WHERE id = '$uid'";
	$result = $pdo->query($sql);
	$row = $result->fetch(PDO::FETCH_ASSOC);
	
	$ccurid = $row['currency'];
	
	// Translate user's currency (ccurid) to ISO
	$sql = "SELECT code FROM tblcurrencies WHERE id = '$ccurid'";
	$result = $pdo->query($sql);
	$row = $result->fetch(PDO::FETCH_ASSOC);
	
	$ccuriso = $row['code'];

	// If the payment has been processed in a differente currency,
	// convert it back to client's
	if (strcasecmp($currency, $ccuriso) != 0) {
		$sql = "SELECT rate FROM tblcurrencies WHERE code = '$currency'";
		$result = $pdo->query($sql);
		$row = $result->fetch(PDO::FETCH_ASSOC);
		
		$exrate = $row['rate'];
		
		// Get the inverse rate -- that's what we need.
		$defrate = 1 / $exrate;
		
		$amount *= $defrate;
		$fee = (($amount * 0.029) + (0.30 * $defrate));
	}

	
	//Get invoice id from database
	$sql = "SELECT * FROM stripecx_transactions WHERE transaction_id = '$transid'";
	$result = $pdo->query($sql);
	$row = $result->fetch(PDO::FETCH_ASSOC);

	$invoice = $row['invoice_id'];
	
	if (empty($invoice)) {
		$transid = $event_json->data->object->charge;
		
		$sql = "SELECT * FROM stripecx_transactions WHERE transaction_id = '$transid'";
		$result = $pdo->query($sql);
		$row = $result->fetch(PDO::FETCH_ASSOC);
	
		$invoice = $row['invoice_id'];
	}
	
	if ($type == 'charge.succeeded' && $invoice == '') {
		header("HTTP/1.1 400 BAD REQUEST");
		//http_response_code(400); // PHP 5.4 or greater
		exit();
	} elseif ($invoice == NULL) {
		$sql = "SELECT * FROM tblaccounts WHERE transid = '$transid'";
		$result = $pdo->query($sql);
		$row = $result->fetch(PDO::FETCH_ASSOC);

		$invoice = $row['invoiceid'];
	}

	$invoiceid = checkCbInvoiceID($invoice,$GATEWAY['name']); # Checks invoice ID is a valid invoice number or ends processing

	if ($type == 'charge.succeeded') {
		checkCbTransID($transid); # Checks transaction number isn't already in the database and ends processing if it does

		# Successful
		addInvoicePayment($invoiceid,$transid,$amount,$fee,$gatewaymodule); # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
		
		$output = "Transaction ID: " .$transid.
				"\r\nInvoice ID: " .$invoiceid.
				"\r\nStatus: " .$type;
								
		logTransaction($GATEWAY["name"],$output,"Successful"); # Save to Gateway Log: name, data array, status
		
	} elseif ($type == 'charge.refunded') {
		# Refunded	
		$output = "Transaction ID: " .$transid.
				  "\r\nInvoice ID: " .$invoiceid.
				  "\r\nStatus: " .$type;
				  
		logTransaction($GATEWAY["name"],$output,"Refunded"); # Save to Gateway Log: name, data array, status
						
		// Remove transaction from processing table if it is there
		$sql = "DELETE FROM stripecx_transactions WHERE invoice_id='$invoiceid'";
		$pdo->query($sql);
	
	} elseif ($type == 'charge.updated') {
		# Updated
		$output = "Transaction ID: " .$transid.
				  "\r\nInvoice ID: " .$invoiceid.
				  "\r\nStatus: " .$type;
				  
		logTransaction($GATEWAY["name"],$output,"Updated"); # Save to Gateway Log: name, data array, status
			
	} elseif ($type == 'charge.failed') {
		# Unsuccessful
		$output = "Transaction ID: " .$transid.
				  "\r\nInvoice ID: " .$invoiceid.
				  "\r\nStatus: " .$type.
				  "\r\nFailure Code: " .$fail_code.
				  "\r\nFailure Description: " .$fail_desc;
				
		logTransaction($GATEWAY["name"],$output,"Unsuccessful"); # Save to Gateway Log: name, data array, status
		
		//Send fail mail to client
		if ($GATEWAY["sendfailedmail"] == 'on') {
			$command = "sendemail";
			$adminuser = "admin";
			$values["messagename"] = "Credit Card Payment Failed";
			$values["id"] = $invoiceid;
	 
			$results = localAPI($command,$values,$adminuser);
		}
		
		// Remove transaction from processing table
		$sql = "DELETE FROM stripecx_transactions WHERE invoice_id='$invoiceid'";
		$pdo->query($sql);
	
	} elseif ($type == 'charge.dispute.created' || $type == 'charge.dispute.updated' || $type == 'charge.dispute.closed') {
		# Dispute
		$output = "Transaction ID: " .$transid.
				  "\r\nInvoice ID: " .$invoiceid.
				  "\r\nStatus: " .$type;
				  				  
		logTransaction($GATEWAY["name"],$output,"Disputed"); # Save to Gateway Log: name, data array, status
	}
	
	header("HTTP/1.1 200 OK");
	//http_response_code(200); // PHP 5.4 or greater

}
?>
