<?php

namespace App\Console\Commands\Netsuite\Castle;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Console\Commands\Netsuite\NetsuiteMasterCommand;


class CastleDirectRestlet extends NetsuiteMasterCommand
{

    /**       * The name and signature of the console command.     * @var string      */
    protected $signature = 'castle:directreslet';


    /**     * The console command description.     * @var string     */
    protected $description = 'Update all Castle tables from Netsuite';


    /**     * Create a new command instance.      * @return void     */
    public function __construct(){parent::__construct();}
    

    public $ConnectionData;


    /**     * Execute the console command.    * @return int     */

    public function handle()
    {

					
					//Set company ID etc.
					$this->ConnectionData = (object)[];
					$this->ConnectionData->Company_ID=1;
					$this->ConnectionData->Started=Carbon::now('UTC')->format('Y-m-d H:i:s');
					$this->ConnectionData->ERP= 'Netsuite';
					$this->ConnectionData->SoftwareID=2;
					$this->ConnectionData->Update_Type = 1;
					$this->ConnectionData->DeepRefresh = true;
					$this->ConnectionData->DocumentType =  'parameter';
					$this->ConnectionData->TableName = 'parameters';
					$this->ConnectionData->ResultForLog = '';


					//Get the Parameters from the database
					
					$this->GetCompanyVariables();
					$this->SetRefreshStartDate();


					//Get all the company keys, tokens and variables
					$this->ConnectionData->oauthconsumerkey = env('OAUTH_RESTLET_CONSUMER_KEY'); //Consumer Key
					$this->ConnectionData->clientsecret = env('OAUTH_RESTLET_CONSUMER_SECRET'); //Consumer Secret
					$this->ConnectionData->oauthtoken = env('OAUTH_RESTLET_TOKEN'); //Token ID
					$this->ConnectionData->tokensecret = env('OAUTH_RESTLET_TOKEN_SECRET'); //Token Secret
					$this->ConnectionData->oauthversion=env('OAUTH_VERSION');
					$this->ConnectionData->realm=env('OAUTH_REALM');   
					$this->ConnectionData->method = 'POST';		
					$this->ConnectionData->baseurl="https://" .$this->ConnectionData->realm. ".restlets.api.netsuite.com/app/site/hosting/restlet.nl";
					
					$searchResults = [
						$this->GetSearchDataFromNetsuite('customsearch1448'), // InvoicesThisMonth
						$this->GetSearchDataFromNetsuite('customsearch1449'), // InvoicesThisYear
						$this->GetSearchDataFromNetsuite('customsearch1439'), // OrdersThisMonth
						$this->GetSearchDataFromNetsuite('customsearch1441'), // OrdersThisYear
						$this->GetSearchDataFromNetsuite('customsearch1443')  // OrdersWaitingInvoice
					];

					$parameterNames = [
						'Invoices_This_Month_Total',
						'Invoices_This_Month_Cost',
						'Invoices_This_Month_GP',
						'Invoices_This_Year_Total',
						'Invoices_This_Year_Cost',
						'Invoices_This_Year_GP',
						'Orders_This_Month_Total',
						'Orders_This_Month_GP',
						'Orders_This_Year_Total',
						'Orders_This_Year_GP',
						'Orders_Waiting_Invoice_Total'
					];

					// Save each parameter
					$i = 0;
					foreach ($searchResults as $result) {
						$data = $result->results[0]->values;
						foreach ($data as $key => $value) {
							$formattedValue = number_format((float)$value, 2, '.', ''); // Format the number
							//dump('SaveParameter('.$this->ConnectionData->Company_ID.', '. $parameterNames[$i].', '.(string)$formattedValue);
							$this->ConnectionData->ResultForLog = $this->ConnectionData->ResultForLog.(string)$formattedValue.',  ';
							$this->SaveParameter($this->ConnectionData->Company_ID, $parameterNames[$i], (string)$formattedValue); // Convert it back to string
        					$i++;
						}
					}


					$this->logcompletion();

	}




					function GetSearchDataFromNetsuite($SearchID)
			{
					$this->ConnectionData->nonce = $this->generateNonce() ; 
					$this->ConnectionData->timenow = idate('U'); 	

					// Start building the parameters we need
					$this->ConnectionData->key = rawurlencode($this->ConnectionData->clientsecret) . "&" . rawurlencode($this->ConnectionData->tokensecret);


									/*   to really do the following steps in a tidy way some code similar to the following should be inplemented
											the URL variables need to be stripped off the back of the url and added to the paramstring array 
											they should then be sorted alphabetically before they are encoded for the signature base 


									// The URL you want to parse
									$url = "https://4294049.restlets.api.netsuite.com/app/site/hosting/restlet.nl?script=921&deploy=1";

									// Parse the URL to get its components
									$url_components = parse_url($url);

									// Check if the URL contains a query string
									if (isset($url_components['query'])) {
										// Parse the query string into an associative array
										parse_str($url_components['query'], $params);
										
										// Print the base URL without parameters
										$base_url = $url_components['scheme'] . "://" . $url_components['host'] . $url_components['path'];
										echo "Base URL: " . $base_url . PHP_EOL;
										
										// Print the parameters
										echo "Parameters: " . PHP_EOL;
										foreach ($params as $key => $value) {
											echo $key . " = " . $value . PHP_EOL;
										}
									} else {
										echo "No query parameters found in the URL." . PHP_EOL;
									}
									*/


					$this->ConnectionData->paramstring = "oauth_consumer_key=" . $this->ConnectionData->oauthconsumerkey. 
																							"&oauth_nonce=" . $this->ConnectionData->nonce . 
																							"&oauth_signature_method=HMAC-SHA256" . 
																							"&oauth_timestamp=" . $this->ConnectionData->timenow . 
																							"&oauth_token=" .$this->ConnectionData->oauthtoken. 
																							"&oauth_version=". $this->ConnectionData->oauthversion.
																							"&script=921";

					$this->ConnectionData->encodeurl = "POST"."&" . rawurlencode($this->ConnectionData->baseurl) . "&" .rawurlencode("deploy=1&" ). rawurlencode($this->ConnectionData->paramstring);
					$this->ConnectionData->signature = hash_hmac( 'sha256', $this->ConnectionData->encodeurl, $this->ConnectionData->key, TRUE );
					$this->ConnectionData->signature = base64_encode( $this->ConnectionData->signature );
					$this->ConnectionData->signature = rawurlencode($this->ConnectionData->signature);

					// Set the search variables
					$this->ConnectionData->bodyContent  = json_encode(['searchID' => $SearchID]);

					// Calculate the content length
					$this->ConnectionData->contentLength = strlen($this->ConnectionData->bodyContent );
	
					$this->ConnectionData->headers = [
						'Prefer: transient',
						'Content-Type: application/json',
						'Authorization: OAuth realm="'.$this->ConnectionData->realm.'",
						oauth_consumer_key="'.$this->ConnectionData->oauthconsumerkey.'",
						oauth_token="'.$this->ConnectionData->oauthtoken.'",
						oauth_signature_method="HMAC-SHA256",
						oauth_timestamp="'.$this->ConnectionData->timenow.'",
						oauth_nonce="'.$this->ConnectionData->nonce.'",
						oauth_version="1.0",
						oauth_signature="'.$this->ConnectionData->signature.'"', 
						'Host: '.parse_url($this->ConnectionData->baseurl, PHP_URL_HOST).'',
						'Content-Length: '.$this->ConnectionData->contentLength.''
						];

			// Dump headers before making the request
			$HeaderString = "";
			foreach ($this->ConnectionData->headers as $header) {$header = str_replace("\t", "", $header);$HeaderString .= $header . "\n";}
			//dump($HeaderString);
			//dump($this->ConnectionData->bodyContent);


		$curl = curl_init();
			$verbose = fopen('php://temp', 'w+');
	
		curl_setopt_array($curl, [
			CURLOPT_URL => 'https://4294049.restlets.api.netsuite.com/app/site/hosting/restlet.nl?deploy=1&script=921',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_VERBOSE => true,
			CURLOPT_STDERR => $verbose,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $this->ConnectionData->method,
			CURLOPT_POSTFIELDS => $this->ConnectionData->bodyContent ,
			CURLOPT_HTTPHEADER => $this->ConnectionData->headers,
		]);

		
	$this->ConnectionData->response = curl_exec($curl);
	$this->ConnectionData->response =  json_decode($this->ConnectionData->response); 
	
		if (curl_errno($curl)) {dump('Request Error:' . curl_error($curl));}
	
		rewind($verbose);
		$verboseLog = stream_get_contents($verbose);
		$status = curl_getinfo($curl);
	
		curl_close($curl);
	
		//dump($verboseLog);
		//dump($status);
		return $this->ConnectionData->response;

	
	}
	
		
	function generateNonce($length = 11) {
		return bin2hex(random_bytes($length / 2));
	}
	

function logcompletion(){


	
						//this looks after the entering of the logs into the log table
						//if the last log is the same as the new one then it does not create a new one it just modifies the time and date
	
							$Last_Record_ID = DB::table('table_update_log')->where('Company_ID',$this->ConnectionData->Company_ID)->where('table_name',$this->ConnectionData->TableName)->where('record_type',$this->ConnectionData->DocumentType)->max('Company_Record_ID');
							DB::table('table_update_log')->insert([	
											'table_name'=>$this->ConnectionData->TableName,
											'record_type'=>$this->ConnectionData->DocumentType,
											'status'=> $this->ConnectionData->ResultForLog, 
											'started'=> Null,
											'completed'=> Now(), 
											'company_record_id'=> $Last_Record_ID+1, 
											'company_id'=>$this->ConnectionData->Company_ID,
											'erp_id'=>$this->ConnectionData->SoftwareID, 
											'update_type_id'=>$this->ConnectionData->Update_Type,
											'created_at'=>Carbon::now('UTC')->format('Y-m-d H:i:s'),
											'updated_at'=>Carbon::now('UTC')->format('Y-m-d H:i:s')]);
						//DD('LSS7N',$this->ConnectionData);
}







}
