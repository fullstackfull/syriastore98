<?php

namespace Workdo\Campaigns\app\Http\Controllers;


use Exception;
use Http;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use \Paynow\Payments\Paynow;
use Illuminate\Support\Facades\Crypt;
use Session;
use Braintree\Gateway;



class CampaignPaymentController extends Controller
{

    private $currencyArray = [
        "USD" => "840",
        "EUR" => "978",
        "GBP" => "826",
        "CAD" => "124",
        "AUD" => "036",
        "JPY" => "392",
        "CHF" => "756",
        "SEK" => "752",
        "NOK" => "578",
        "DKK" => "208",
        "NZD" => "554",
        "SGD" => "702",
        "HKD" => "344",
        "ZAR" => "710",
        "MXN" => "484",
        "BRL" => "986",
        "INR" => "356",
        "CNY" => "156",
        "RUB" => "643",
    ];
    //paynow
    public static function paynowPayment($pay)
    {
        $paynow = new Paynow($pay['integration_id'], $pay['integration_key'], $pay['success_url'], $pay['faild_url']);
        $payment = $paynow->createPayment($pay['order_id'], $pay['email']);
        $payment->add($pay['item'], $pay['amount']);
        $response = $paynow->send($payment);
        if ($response->success()) {
            $link = $response->redirectUrl();
            $pollUrl = $response->pollUrl();
            return redirect()->to($link)->with('pollUrl', $pollUrl);
        } else {
            return redirect()->route('campaigns.index')->with('error', __('Payment initiation failed'));
        }
    }
    //end paynow

    //for payu
    public function pay($pay)
    {
        $pay = (object) Session::get('\'' . Crypt::decrypt($pay) . '\'');
        $pay->action =  ($pay->mode == 'production') ? "https://secure.payu.in/_payment" : "https://test.payu.in/_payment";
        $pay->hash   = $this->generateHash($pay->key, $pay->txnid, $pay->amount, $pay->productinfo, $pay->firstname, $pay->email, $pay->salt);
        return view('campaigns::payment.payu', compact('pay'));
    }
    function generateHash($key, $txnid, $amount, $productinfo, $firstname, $email, $salt, $udf1 = '', $udf2 = '', $udf3 = '', $udf4 = '', $udf5 = '')
    {
        $data = $key . '|' . $txnid . '|' . $amount . '|' . $productinfo . '|' . $firstname . '|' . $email . '|' . $udf1 . '|' . $udf2 . '|' . $udf3 . '|' . $udf4 . '|' . $udf5 . '||||||' . $salt;
        return hash('sha512', $data);
    }
    //end payu

    //braintree
    public function payBraintree(Request $request)
    {
        $clientToken = $request->token;
        $orderID = $request->orderID;
        $action = $request->action;
        $return_url = $request->return_url;
        $user = $request->user;
        $amount = $request->amount;
        return view('campaigns::payment.Braintree', compact('clientToken', 'orderID', 'action', 'return_url', 'user', 'amount'));
    }
    //end braintree

    //for dpo payment
    public function create(Request $request)
    {
        $action = $request->action;
        $data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        return view('campaigns::payment.dpo_pay', compact('data', 'payment_setting', 'action'));
    }
    public function storePayWithDPO(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();

        $responseData = json_decode($request->input('response_data'), true);
        $price = $responseData['total_price'];
        $user = (object) $responseData['user'];
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $date = new \DateTime();
        $formattedDate = $date->format('Y/m/d H:i');
        $carddata = $request->all();
            try {

                $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
                $company_token = isset($payment_setting['dpo_pay_Company_Token']) ? $payment_setting['dpo_pay_Company_Token'] : '';
                $service_type = isset($payment_setting['dpo_pay_Service_Type']) ? $payment_setting['dpo_pay_Service_Type'] : '';

                if ($currency != 'USD') {
                    return redirect()->route('campaigns.index')->with('error', __('Currency not supported'));
                }
                $postXml = '<?xml version="1.0" encoding="utf-8"?>
                        <API3G>
                            <CompanyToken>'.$company_token.'</CompanyToken>
                            <Request>createToken</Request>
                            <Transaction>
                                <PaymentAmount>'.$price.'</PaymentAmount>
                                <PaymentCurrency>'.$currency.'</PaymentCurrency>
                                <RedirectURL>http://www.domain.com/payurl.php</RedirectURL>
                                <BackURL>http://www.domain.com/payurl.php</BackURL>
                                <CompanyRefUnique>0</CompanyRefUnique>
                                <PTL>5</PTL>

                            </Transaction>
                            <Services>
                                <Service>

                                    <ServiceType>'.$service_type.'</ServiceType>
                                    <ServiceDescription>Purchase Iteams</ServiceDescription>
                                    <ServiceDate>'.$formattedDate.'</ServiceDate>
                                </Service>
                            </Services>
                        </API3G>';


                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => 'https://secure.3gdirectpay.com/API/v6/',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $postXml,
                    CURLOPT_HTTPHEADER => [
                        'cache-control: no-cache',
                    ],
                ]);
                $response = curl_exec($curl);
                $error = curl_error($curl);

                curl_close($curl);

                if ($response != '') {
                    $xml = new \SimpleXMLElement($response);

                    $result = $xml->xpath('Result')[0]->__toString();
                    $resultExplanation = $xml->xpath('ResultExplanation')[0]->__toString();
                    $returnResult = [
                        'result' => $result,
                        'resultExplanation' => $resultExplanation,
                    ];

                    // Check if token was created successfully
                    if ($xml->xpath('Result')[0] != '000') {
                        $returnResult['success'] = 'false';
                    } else {

                        $transToken = $xml->xpath('TransToken')[0]->__toString();
                        $transRef = $xml->xpath('TransRef')[0]->__toString();
                        $returnResult['success'] = 'true';
                        $returnResult['transToken'] = $transToken;
                        $returnResult['transRef'] = $transRef;

                    }
                    $postXml = trim($postXml);

                    // Load the XML string into a SimpleXMLElement object
                    $xml = simplexml_load_string($postXml);

                    // Convert the SimpleXMLElement object to JSON
                    $json = json_encode($xml);


                    // Decode the JSON into an associative array
                    $array = json_decode($json, true);
                    $data = [
                        'carddata' => $carddata,
                        'array' => $array,
                        'returnResult' => $returnResult,
                    ];

                    $payment = $this->payment($data);
                    $result = $this->checkToken($data);
                    $response1 = $this->checkToken($data); // Assuming this function returns the response data
                    $response = simplexml_load_string($response1);

                    $Mainslug = [
                        'carddata' => $carddata,
                        'order_id' => $orderID,
                        'amount' => $price,
                        'response' => $response,
                    ];

                    $serializedData = json_encode($Mainslug);

                    return redirect()->route('campaigns.get.dpo.status', ['data' => $serializedData]);
                }

            } catch (\Exception $e) {
                return redirect()->route('campaigs.index')->with('error', __($e->getMessage()));
            }

    }
    public function payment(array $data)
    {
        $companyToken = $data['array']['CompanyToken'];
        $transToken = $data['returnResult']['transToken'];
        $CreditCardNumber = $data['carddata']['card_number'];
        $Expiration = $data['carddata']['expiry'];
        $cvv = $data['carddata']['cvv'];
        $name = $data['carddata']['name'];

        try {
            $curl = curl_init();
            curl_setopt_array(
                $curl,
                [
                    CURLOPT_URL => 'https://secure.3gdirectpay.com/API/v6/',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => "
                        <?xml version='1.0' encoding='utf-8'?>
                        <API3G>
                            <CompanyToken>".$companyToken.'</CompanyToken>
                            <Request>chargeTokenCreditCard</Request>
                            <TransactionToken>'.$transToken.'</TransactionToken>
                            <CreditCardNumber>'.$CreditCardNumber.'</CreditCardNumber>
                            <CreditCardExpiry>'.$Expiration.'</CreditCardExpiry>
                            <CreditCardCVV>'.$cvv.'</CreditCardCVV>
                            <CardHolderName>'.$name.'</CardHolderName>
                            <ChargeType></ChargeType>
                            <ThreeD>
                                <Enrolled>Y</Enrolled>
                                <Paresstatus>Y</Paresstatus>
                                <Eci>05</Eci>
                                <Xid>DYYVcrwnujRMnHDy1wlP1Ggz8w0=</Xid>
                                <Cavv>mHyn+7YFi1EUAREAAAAvNUe6Hv8=</Cavv>
                                <Signature>_</Signature>
                                <Veres>AUTHENTICATION_SUCCESSFUL</Veres>
                                <Pares>eAHNV1mzokgW/isVPY9GFSCL0EEZkeyg7</Pares>
                            </ThreeD>
                        </API3G>',
                    ],
                );

            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);

            if (strlen($err) > 0) {
                echo 'cURL Error #:'.$err;
            } else {

                return $response;
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __($e->getMessage()));

        }
    }
    public function checkToken(array $data)
    {
        $companyToken = $data['array']['CompanyToken'];
        $transToken = $data['returnResult']['transToken'];

        try {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://secure.3gdirectpay.com/API/v6/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => "
                <?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n<API3G>\r\n <CompanyToken>".$companyToken."</CompanyToken>\r\n
                    <Request>verifyToken</Request>\r\n <TransactionToken>".$transToken."</TransactionToken>\r\n
                </API3G>",

                CURLOPT_HTTPHEADER => [
                    'cache-control: no-cache',
                ],
            ]);

            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);

            if (strlen($err) > 0) {
                echo 'cURL Error #:'.$err;
            } else {

                return $response;
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __($e->getMessage()));

        }
    }
    public function storeGetDPOStatus(Request $request)
    {
        $data = json_decode($request->all()['data'], true);
        $result = $data['response']['Result'];

            try {
                if ($result == 000) {
                    return redirect()->route('promote.success');
                } else {
                    return redirect()->route('campaigns.index')->with('error', __('Transaction has been failed'));
                }

            } catch (\Exception $e) {
                return redirect()->route('campaigns.index')->with('error', __('Oops something went wrong'));
            }

    }
    // end dpo

    //start powertranz
    public function CampaignPayWithPowertranz(Request $request)
    {
        $request->validate([
            'PowerTranz_cardholder_name' => 'required|string',
            'PowerTranz_card_number' => 'required|string',
            'PowerTranz_expiration_date' => 'required|string',
            'PowerTranz_cvv_code' => 'required|string',
        ]);

        $requests_data = json_decode($request->input('requests_data'), true);
        $amount=$requests_data['total_cost'];
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $user = \Auth::user();

            try {

                if (array_key_exists($currency, $this->currencyArray)) {
                    $currency = $this->currencyArray[$currency];
                } else {
                    return redirect()->route('campagins.index')->with('error', __('Currency not supported'));
                }

                $PowerTranzmode = isset($payment_setting['powertranz_mode']) ? $payment_setting['powertranz_mode'] : '';
                $PowerTranzPasswordId = isset($payment_setting['powertranz_pay_merchant_id']) ? $payment_setting['powertranz_pay_merchant_id'] : '';
                $PowerTranzPassword = isset($payment_setting['powertranz_pay_processing_password']) ? $payment_setting['powertranz_pay_processing_password'] : '';
                $PowerTranzurl = isset($payment_setting['powertranz_pay_production_url']) ? $payment_setting['powertranz_pay_production_url'] : '';

                $PowerTranz_expiration_date = explode('/', $request->PowerTranz_expiration_date);
                $PowerTranz_expiration_date = $PowerTranz_expiration_date[1] . $PowerTranz_expiration_date[0];

                if($PowerTranzmode == 'sandbox'){
                    $url = 'https://staging.ptranz.com/api/spi/sale';
                }elseif($PowerTranzmode == 'production'){
                    $url = $PowerTranzurl.'/api/spi/sale';
                }

                $headers = [
                    "Accept: application/json",
                    "PowerTranz-PowerTranzId: $PowerTranzPasswordId",
                    "PowerTranz-PowerTranzPassword: $PowerTranzPassword",
                    "Content-Type: application/json; charset=utf-8",
                    "Host: staging.ptranz.com",
                    "Connection: Keep-Alive"
                ];
                $fields = [
                    "TotalAmount" => $amount,
                    "CurrencyCode" => $currency,
                    "ThreeDSecure" => true,
                    "Source" => [
                        "CardPan" => $request->PowerTranz_card_number,
                        "CardCvv" => $request->PowerTranz_cvv_code,
                        "CardExpiration" => $PowerTranz_expiration_date,
                        "CardholderName" => $request->PowerTranz_cardholder_name,
                    ],
                    "OrderIdentifier" => "$orderID",
                    "BillingAddress" => [
                        "FirstName" => $user->name,
                        "EmailAddress" => $user->email,
                        "PhoneNumber" => $user->mobile,
                    ],
                    "AddressMatch" => false,
                    "ExtendedData" => [
                        "ThreeDSecure" => [
                            "ChallengeWindowSize" => 4,
                            "ChallengeIndicator" => "01"
                        ],
                        "MerchantResponseUrl" => route('campaigns.get.Powertranz.status')
                    ]
                ];
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                $response = curl_exec($ch);

                curl_close($ch);
                $json_response = json_decode($response, true);
                if (isset($json_response['Approved']) && $json_response['Approved'] === false) {
                    if (isset($json_response['RedirectData'])) {
                        return response($json_response['RedirectData']);
                    } else {
                        return redirect()
                            ->route('campaigns.index')
                            ->with('error', $response['message'] ?? 'Something went wrong.');
                    }
                } else {
                    return redirect()
                        ->route('campaigns.index')
                        ->with('error', $response['message'] ?? 'Something went wrong.');
                }

            } catch (\Exception $e) {
                return redirect()->route('campaigns.index')->with('error', __($e->getMessage()));
            }


    }
    public function campaignsGetPowertranzStatus(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();

        $PowerTranzmode = isset($payment_setting['powertranz_mode']) ? $payment_setting['powertranz_mode'] : '';
        $PowerTranzPasswordId = isset($payment_setting['powertranz_pay_merchant_id']) ? $payment_setting['powertranz_pay_merchant_id'] : '';
        $PowerTranzPassword = isset($payment_setting['powertranz_pay_processing_password']) ? $payment_setting['powertranz_pay_processing_password'] : '';
        $PowerTranzurl = isset($payment_setting['powertranz_pay_production_url']) ? $payment_setting['powertranz_pay_production_url'] : '';
            if($PowerTranzmode == 'sandbox'){
                $url = 'https://staging.ptranz.com/api/spi/Payment';
            }elseif($PowerTranzmode == 'production'){
                $url = $PowerTranzurl.'/api/spi/Payment';
            }

            try {
                $response = Http::withHeaders([
                    "Accept: text/plain",
                    "Content-Type: application/json-patch+json",
                    "Host: staging.ptranz.com",
                    "Connection: Keep-Alive"
                ])->post($url, $request->SpiToken);
                $response_data = $response->json();
                if (isset($response_data['Approved']) && $response_data['Approved'] == true && isset($response_data['IsoResponseCode']) && $response_data['IsoResponseCode'] == "00") {
                    return redirect()->route('promote.success');
                } else {
                    return redirect()->route('campaigns.index')->with('error', __('Transaction has been failed'));
                }
            } catch (\Exception $e) {
                return redirect()->route('campaigns.index')->with('error', __('Oops something went wrong'));
            }

    }
    //end
}
