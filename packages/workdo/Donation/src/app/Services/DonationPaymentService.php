<?php

namespace Workdo\Donation\app\Services;

use Stripe;
use Session;
use Obydul\LaraSkrill\SkrillClient;
use Obydul\LaraSkrill\SkrillRequest;
use App\Models\Store;
use App\Models\Customer;
use App\Models\User;
use Paytabscom\Laravel_paytabs\Facades\paypage;
use PaytmWallet;
use GuzzleHttp\Client;
use YooKassa\Client as YooKassaClient;
use App\Coingate\Coingate;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Illuminate\Support\Facades\Http;
use App\Xendit\Xendit;
use App\Xendit\Invoice;
use Lahirulhr\PayHere\PayHere;
use App\Package\Payment;
use Dipesh79\LaravelPhonePe\LaravelPhonePe;
use Illuminate\Support\Facades\Cache;

class DonationPaymentService
{
    public function paymentConfig()
    {
        if (auth()->guard('customers')->check()) {
            $payment_setting =  getAdminAllSetting();
        }

        if ($payment_setting['paypal_mode'] == 'live') {
            config([
                'paypal.live.client_id' => isset($payment_setting['paypal_client_id']) ? $payment_setting['paypal_client_id'] : '',
                'paypal.live.client_secret' => isset($payment_setting['paypal_secret']) ? $payment_setting['paypal_secret'] : '',
                'paypal.mode' => isset($payment_setting['paypal_mode']) ? $payment_setting['paypal_mode'] : '',
            ]);
        } else {
            config([
                'paypal.sandbox.client_id' => isset($payment_setting['paypal_client_id']) ? $payment_setting['paypal_client_id'] : '',
                'paypal.sandbox.client_secret' => isset($payment_setting['paypal_secret']) ? $payment_setting['paypal_secret'] : '',
                'paypal.mode' => isset($payment_setting['paypal_mode']) ? $payment_setting['paypal_mode'] : '',
            ]);
        }
    }

    public function process($request, $paymentMethod, $slug, $donation_amount)
    {
        switch ($paymentMethod) {
            case 'Stripe':
                return $this->processStripe($request, $slug, $donation_amount);
            case 'Paypal':
                return $this->processPaypal($request, $slug, $donation_amount);
            case 'Paystack':
                return $this->processPaystack($request, $slug, $donation_amount);
            case 'Razorpay':
                return $this->processRazorpay($request, $slug, $donation_amount);
            case 'Mercado':
                return $this->processMercado($request, $slug, $donation_amount);
            case 'Skrill':
                return $this->processSkrill($request, $slug, $donation_amount);
            case 'Paymentwall':
                return $this->processPaymentWall($request, $slug, $donation_amount);
            case 'Flutterwave':
                return $this->processFlutterWave($request, $slug, $donation_amount);
            case 'Paytm':
                return $this->processPayTm($request, $slug, $donation_amount);
            case 'Mollie':
                return $this->processMollie($request, $slug, $donation_amount);
            case 'Coingate':
                return $this->processCoingate($request, $slug, $donation_amount);
            case 'Sspay':
                return $this->processSspay($request, $slug, $donation_amount);
            case 'Toyyibpay':
                return $this->processToyyibPay($request, $slug, $donation_amount);
            case 'Paytabs':
                return $this->processPayTabs($request, $slug, $donation_amount);
            case 'Iyzipay':
                return $this->processIyziPay($request, $slug, $donation_amount);
            case 'Payfast':
                return $this->processPayFast($request, $slug, $donation_amount);
            case 'Benefit':
                return $this->processBenefit($request, $slug, $donation_amount);
            case 'Cashfree':
                return $this->processCashFree($request, $slug, $donation_amount);
            case 'Aamarpay':
                return $this->processAamarPay($request, $slug, $donation_amount);
            case 'Telegram':
                return $this->processTelegram($request, $slug, $donation_amount);
            case 'Paytr':
                return $this->processPayTr($request, $slug, $donation_amount);
            case 'Yookassa':
                return $this->processYookassa($request, $slug, $donation_amount);
            case 'Xendit':
                return $this->processXendit($request, $slug, $donation_amount);
            case 'Midtrans':
                return $this->processMidtrans($request, $slug, $donation_amount);
            case 'Whatsapp':
                return $this->processWhatsapp($request, $slug, $donation_amount);
            case 'Nepalste':
                return $this->processNepalste($request, $slug, $donation_amount);
            case 'Khalti':
                return $this->processKhalti($request, $slug, $donation_amount);
            case 'Payhere':
                return $this->processPayHere($request, $slug, $donation_amount);
            case 'Authorizenet':
                return $this->processAuthorizeNet($request, $slug, $donation_amount);
            case 'Tap':
                return $this->processTap($request, $slug, $donation_amount);
            case 'Phonepe':
                return $this->processPhonePe($request, $slug, $donation_amount);
            case 'Paddle':
                return $this->processPaddle($request, $slug, $donation_amount);
            case 'Paiementpro':
                return $this->processPaiementpro($request, $slug, $donation_amount);
            case 'Fedpay':
                return $this->processFedPay($request, $slug, $donation_amount);
            case 'Cinetpay':
                return $this->processCinetPay($request, $slug, $donation_amount);
            default:
                return response()->json(['error' => __('Invalid payment method')], 422);
        }
    }

    private function processStripe($request, $slug, $donation_amount)
    {
        $store = getStore($slug);
        
        $stripe_secret = \App\Models\Utility::GetValueByName('stripe_secret_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $donation_amount)));
        if ($totalprice > 0.0) {
            $l_name = $theme_id;
            $stripe_formatted_price = in_array(
                $CURRENCY_NAME,
                [
                    'MGA',
                    'BIF',
                    'CLP',
                    'PYG',
                    'DJF',
                    'RWF',
                    'GNF',
                    'UGX',
                    'JPY',
                    'VND',
                    'VUV',
                    'XAF',
                    'KMF',
                    'KRW',
                    'XOF',
                    'XPF',
                ]
            ) ? number_format($totalprice, 2, '.', '') : number_format($totalprice, 2, '.', '') * 100;

            $return_url_parameters = function ($return_type) {
                return '&return_type=' . $return_type . '&payment_processor=stripe';
            };

            Stripe\Stripe::setApiKey($stripe_secret);
            $data = \Stripe\Checkout\Session::create(
                [
                    'payment_method_types' => ['card'],
                    'line_items' => [
                        [
                            'price_data' => [
                                'currency' => $CURRENCY_NAME,
                                'unit_amount' => (int)$stripe_formatted_price,
                                'product_data' => [
                                    'name' => $store->name,
                                    'description' => 'Stipe payment',
                                ],
                            ],
                            'quantity' => 1,
                        ],
                    ],

                    'mode' => 'payment',
                    'success_url' => route(
                        'store.donation.payment',
                        [
                            $slug,
                            $return_url_parameters('success'),
                        ]
                    ),
                    'cancel_url' => route(
                        'store.donation.payment',
                        [
                            $slug,
                            $return_url_parameters('cancel'),
                        ]
                    ),
                ]

            );
            Session::put('donation_request_data', $request);
            $data = $data ?? false;
            try {
                $place_order_data = ($data);
                return $place_order_data->url;
            } catch (\Exception $e) {
                return redirect()->route('landing_page', $slug)->with('error', __('Transaction has been failed!'));
            }
        }
        return response()->json(['message' => __('Payment processed using Stripe')]);
    }

    private function processPaystack($request, $slug, $donation_amount)
    {
        $data = $request;
        Session::put('donation_request_data', $data);
        $store    = getStore($slug);
        $admin_payment_setting = getAdminAllSetting();
        return view('donation::payment.paystack', compact('data', 'admin_payment_setting', 'store', 'donation_amount'));
    }

    private function processRazorpay($request, $slug, $donation_amount)
    {
        $data   = $request;
        Session::put('donation_request_data', $data);
        $store    = getStore($slug);
        $admin_payment_setting = getAdminAllSetting();
        return view('donation::payment.razorpay', compact('data', 'admin_payment_setting', 'store', 'donation_amount'));
    }

    private function processMercado($request, $slug, $donation_amount)
    {
        $slug = !empty($request['slug']) ? $request['slug'] : $slug;
        $store = getStore($slug);

        
        $admin_payment_setting = getAdminAllSetting();

        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);
        $mercado_mode = \App\Models\Utility::GetValueByName('mercado_mode', $store->id);
        $mercado_access_token = \App\Models\Utility::GetValueByName('mercado_access_token', $store->id);

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $donation_amount)));

        $requests_data = $request;
        \MercadoPago\SDK::setAccessToken($mercado_access_token);

        try {
            // Create a preference object
            $preference = new \MercadoPago\Preference();
            // Create an item in the preference
            $item              = new \MercadoPago\Item();
            $item->title       = $store->name . "Order";
            $item->quantity    = 1;
            $item->unit_price  = (float)$donation_amount;
            $preference->items = array($item);
            $data = json_encode($requests_data);
            Session::put('donation_request_data', $request);
            $success_url = route(
                'store.donation.payment',
                [
                    'flag' => 'success',
                    $slug,
                ]
            );

            $failure_url = route(
                'store.donation.payment',
                [
                    $slug,
                    'flag' => 'failure',

                ]
            );
            $pending_url = route(
                'store.donation.payment',
                [
                    $slug,
                    'flag' => 'pending',

                ]
            );

            $preference->back_urls = array(
                "success" => $success_url,
                "failure" => $failure_url,
                "pending" => $pending_url,
            );

            $preference->auto_return = "approved";
            $preference->save();


            // Create a customer object
            $payer = new \MercadoPago\Payer();
            // Create payer information
            $payer->name    = $request['name'];
            $payer->email   = $request['email'];
            $payer->address = array(
                "street_name" => '',
            );
            if ($mercado_mode == 'live') {
                $redirectUrl = $preference->init_point;
            } else {
                $redirectUrl = $preference->sandbox_init_point;
            }

            return $redirectUrl;
        } catch (Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    private function processSkrill($request, $slug, $donation_amount)
    {
        $payment = getAdminAllSetting();
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        
        $skrill_email = \App\Models\Utility::GetValueByName('skrill_email', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        if (\Auth::check($slug)) {
            $pdata['phone']   = $request['number'];
            $pdata['email']   = $request['email'];
        } else {
            $pdata['phone']   = $request['number'];
            $pdata['email']   = $request['email'];
        }
        $cartlist_final_price = $donation_amount;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $donation_amount)));
        $transaction_id = strtoupper(str_replace('.', '', uniqid('', true)));

        $skill               = new SkrillRequest();
        $skill->pay_to_email = $skrill_email;
        $skill->return_url   = route('store.donation.payment', $store->slug);
        $skill->cancel_url   = route('store.donation.payment', $store->slug);

        // create object instance of SkrillRequest
        $skill->transaction_id  = MD5($transaction_id);
        $skill->amount          = $donation_amount;
        $skill->currency        = $CURRENCY_NAME;
        $skill->language        = 'EN';
        $skill->prepare_only    = '1';
        $skill->merchant_fields = 'site_name, customer_email';
        $skill->site_name       = $store->name;
        $skill->customer_email  = $pdata['email'];

        $client = new SkrillClient($skill);
        $sid    = $client->generateSID(); //return SESSION ID

        // handle error
        $jsonSID = json_decode($sid);
        if ($jsonSID != null && $jsonSID->code == "BAD_REQUEST") {
            return redirect()->back()->with('error', $jsonSID->message);
        }
        // do the payment
        Session::put('donation_request_data', $request);
        $redirectUrl = $client->paymentRedirectUrl($sid); //return redirect url
        if ($transaction_id) {
            $data = [
                'amount' => $donation_amount,
                'trans_id' => MD5($transaction_id),
                'currency' => 'USD',
                'slug' => $store->slug,
            ];
            session()->put('skrill_data', $data);
        }
        return redirect($redirectUrl);
    }

    private function processPaymentWall($request, $slug, $donation_amount)
    {
        $data   = $request;
        Session::put('donation_request_data', $data);
        $store    = getStore($slug);
        $admin_payment_setting = getAdminAllSetting();
        return view('donation::payment.paymentwall', compact('data', 'admin_payment_setting', 'store', 'donation_amount'));
    }

    private function processPayPal($request, $slug, $donation_amount)
    {
        $payment = getAdminAllSetting();

        $slug = !empty($slug) ? $slug : '';

        $store = getStore($slug);
        

        $paypal_secret = \App\Models\Utility::GetValueByName('paypal_secret_key', $store->id);
        $paypal_client_id = \App\Models\Utility::GetValueByName('paypal_client_id', $store->id);
        $paypal_mode = \App\Models\Utility::GetValueByName('paypal_mode', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        if ($paypal_mode == 'live') {
            config(
                [
                    'paypal.live.client_id' => isset($paypal_client_id) ? $paypal_client_id : '',
                    'paypal.live.client_secret' => isset($paypal_secret) ? $paypal_secret : '',
                    'paypal.mode' => isset($paypal_mode) ? $paypal_mode : '',

                ]
            );
        } else {
            config(
                [
                    'paypal.sandbox.client_id' => isset($paypal_client_id) ? $paypal_client_id : '',
                    'paypal.sandbox.client_secret' => isset($paypal_secret) ? $paypal_secret : '',
                    'paypal.mode' => isset($paypal_mode) ? $paypal_mode : '',

                ]
            );
        }
        $provider = new PayPalClient;

        $provider->setApiCredentials(config('paypal'));
        $paypalToken = $provider->getAccessToken();
        Session::put('paypal_payment_id', $paypalToken['access_token']);
        $objUser = \Auth::user();

        $return_url_parameters = function ($return_type) {
            return '&return_type=' . $return_type . '&payment_processor=stripe';
        };

        try {

            $response = $provider->createOrder([
                "intent" => "CAPTURE",
                "purchase_units" => [
                    0 => [
                        "amount" => [
                            "currency_code" => $CURRENCY_NAME,
                            "value" => $donation_amount
                        ]
                    ]
                ],
                "application_context" => [
                    "return_url" => route('store.donation.payment',  $slug),
                    "cancel_url" => route('store.donation.payment', $slug),
                ],

            ]);

            if (isset($response['id']) && $response['id'] != null) {
                // redirect to approve href
                Session::put('donation_request_data', $request);
                foreach ($response['links'] as $links) {
                    if ($links['rel'] == 'approve') {
                        return $links['href'];
                    }
                }
                return $response;

                return redirect()
                    ->route('landing_page', $slug)
                    ->with('error', 'Something went wrong.');
            } else {
                return redirect()
                    ->route('landing_page', $slug)
                    ->with('error', $response['message'] ?? 'Something went wrong.');
            }
        } catch (\Exception $e) {
            return redirect()->route('landing_page', $slug)->with('error', __('Unknown error occurred'));
        }
    }

    private function processFlutterWave($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);

        

        $flutterwave_secret = \App\Models\Utility::GetValueByName('flutterwave_secret_key', $store->id);
        $public_key = \App\Models\Utility::GetValueByName('flutterwave_public_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);


        $res_data['total_price'] = $donation_amount;
        $res_data['currency']    = $CURRENCY_NAME;
        $res_data['flag']        = 1;
        $res_data['public_key']  = $public_key;
        $res_data['store']  = $store->slug;
        $res_data['orderID']  = '1234564';
        $res_data['email']  = $request['email'];

        Session::put('donation_request_data', $request);
        return view('donation::payment.flutterwave', compact('res_data', 'donation_amount'));
    }

    private function processPayTm($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);

        

        $paytm_mode = \App\Models\Utility::GetValueByName('paytm_mode', $store->id);
        $paytm_merchant_id = \App\Models\Utility::GetValueByName('paytm_merchant_id', $store->id);
        $paytm_merchant_key = \App\Models\Utility::GetValueByName('paytm_merchant_key', $store->id);
        $paytm_industry_type = \App\Models\Utility::GetValueByName('paytm_industry_type', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $cutomer  = Customer::where('type', 'customer')->where('store_id', $store->id)->get()->count();
        $user = $cutomer + 1;

        config(
            [
                'services.paytm-wallet.env' => isset($paytm_mode) ? $paytm_mode : '',
                'services.paytm-wallet.merchant_id' => isset($paytm_merchant_id) ? $paytm_merchant_id : '',
                'services.paytm-wallet.merchant_key' => isset($paytm_merchant_key) ? $paytm_merchant_key : '',
                'services.paytm-wallet.merchant_website' => 'WEBSTAGING',
                'services.paytm-wallet.channel' => 'WEB',
                'services.paytm-wallet.industry_type' => isset($paytm_industry_type) ? $paytm_industry_type : '',
            ]
        );

        try {

            $payment = PaytmWallet::with('receive');

            $payment->prepare(
                [
                    'order' => date('Y-m-d') . '-' . strtotime(date('Y-m-d H:i:s')),
                    'user' => 1,
                    'mobile_number' => $request['number'],
                    'email' => $request['email'],
                    'amount' => $donation_amount,
                    'callback_url' => route('store.paytm.payment.paytm',  $slug),

                ]
            );
            Session::put('donation_request_data', $request);
            return $payment->receive();
        } catch (\Exception $e) {
            return redirect()->route('landing_page', $slug)->with('error', __($e->getMessage()));
        }
    }

    private function processMollie($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);

        

        $mollie_api_key = \App\Models\Utility::GetValueByName('mollie_api_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $request = $request;
        $mollie  = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($mollie_api_key);
        $payment = $mollie->payments->create(
            [
                "amount" => [
                    "currency" => "$CURRENCY_NAME",
                    "value" => str_replace(",", "", number_format($donation_amount, 2)),
                ],
                "description" => "payment for product",
                "redirectUrl" => route(
                    'store.donation.payment',
                    [
                        $store->slug,
                    ]
                ),

            ]
        );
        session()->put('mollie_payment_id', $payment->id);
        Session::put('donation_request_data', $request);

        return $payment->getCheckoutUrl();
    }

    private function processCoingate($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        

        $coingate_mode = \App\Models\Utility::GetValueByName('coingate_mode', $store->id);
        $coingate_auth_token = \App\Models\Utility::GetValueByName('coingate_auth_token', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        try {
            CoinGate::config(
                array(
                    'environment' => $coingate_mode,
                    // sandbox OR live
                    'auth_token' => $coingate_auth_token,
                    'curlopt_ssl_verifypeer' => FALSE
                    // default is false
                )
            );

            $post_params = array(
                // 'order_id' => $order->id,
                'order_id' => time(),
                'price_amount' => $donation_amount,
                'price_currency' => $CURRENCY_NAME,
                'receive_currency' => $CURRENCY_NAME,

                'callback_url' => route(
                    'store.donation.payment',
                    [
                        $store->slug,
                    ]
                ),
                'cancel_url' => route(
                    'store.donation.payment',
                    [
                        $store->slug,
                    ]
                ),

                'success_url' => route(
                    'store.donation.payment',
                    [
                        $store->slug,
                    ]
                ),
                'title' => 'Order #' . time(),
            );
            Session::put('donation_request_data', $request);
            $order       = Coingate::coingatePayment($post_params, 'POST');
            if ($order['status_code'] === 200) {
                $response = $order['response'];
                return (object)$response;
            } else {
                return redirect()->back()->with('error', __('opps something wren wrong.'));
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    private function processSspay($request, $slug, $donation_amount)
    {
        try {
            $slug = $slug;
            $store = getStore($slug);
            
            $sspay_secret_key = \App\Models\Utility::GetValueByName('sspay_secret_key', $store->id);
            $sspay_category_code = \App\Models\Utility::GetValueByName('sspay_category_code', $store->id);
            $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);
            $email = $request['name'] ?? 'Test';
            $firstname = $request['name'] ?? 'Test';
            $billing_user_telephone = $request['number'] ?? '1234567890';
            $billContentEmail = "Thank you for purchasing our product!";
            $Date = date('d-m-Y');
            $billExpiryDays = 3;
            $billExpiryDate = date('d-m-Y', strtotime($Date . ' + 3 days'));

            $this->callBackUrl = route('store.donation.payment', $store->slug);
            $this->returnUrl = route('store.donation.payment', $store->slug);

            $some_data = array(
                'userSecretKey' => $sspay_secret_key,
                'categoryCode' => $sspay_category_code,
                'billName' => $firstname,
                'billDescription' => $firstname,
                'billPriceSetting' => 1,
                'billPayorInfo' => 1,
                'billAmount' => 100 * $donation_amount,
                'billReturnUrl' => $this->returnUrl,
                'billCallbackUrl' => $this->callBackUrl,
                'billExternalReferenceNo' => 'AFR341DFI',
                'billTo' => $firstname,
                'billEmail' => $email,
                'billPhone' => $billing_user_telephone,
                'billSplitPayment' => 0,
                'billSplitPaymentArgs' => '',
                'billPaymentChannel' => '0',
                'billContentEmail' => $billContentEmail,
                'billChargeToCustomer' => 1,
                'billExpiryDate' => $billExpiryDate,
                'billExpiryDays' => $billExpiryDays
            );
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_URL, 'https://sspay.my/index.php/api/createBill');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $some_data);
            $result = curl_exec($curl);
            $info = curl_getinfo($curl);
            curl_close($curl);
            $obj = json_decode($result);
            Session::put('donation_request_data', $request);
            return redirect('https://sspay.my/' . $obj[0]->BillCode);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processToyyibPay($request, $slug, $donation_amount)
    {
        try {
            $slug = $slug;
            $store = getStore($slug);
            
            $toyyibpay_secret_key = \App\Models\Utility::GetValueByName('toyyibpay_secret_key', $store->id);
            $toyyibpay_category_code = \App\Models\Utility::GetValueByName('toyyibpay_category_code', $store->id);
            $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);
            $email = $request['email'] ?? '';
            $firstname = $request['name'] ?? 'Test';
            $billing_user_telephone = $request['number'] ?? '+7418523690';
            $billContentEmail = "Thank you for purchasing our product!";
            $Date = date('d-m-Y');
            $billExpiryDays = 3;
            $billExpiryDate = date('d-m-Y', strtotime($Date . ' + 3 days'));

            $this->callBackUrl = route('store.donation.payment',  $store->slug);
            $this->returnUrl = route('store.donation.payment',  $store->slug);

            $some_data = array(
                'userSecretKey' => $toyyibpay_secret_key,
                'categoryCode' => $toyyibpay_category_code,
                'billName' => $firstname,
                'billDescription' => $firstname,
                'billPriceSetting' => 1,
                'billPayorInfo' => 1,
                'billAmount' => 100 * $donation_amount,
                'billReturnUrl' => $this->returnUrl,
                'billCallbackUrl' => $this->callBackUrl,
                'billExternalReferenceNo' => 'AFR341DFI',
                'billTo' => $firstname,
                'billEmail' => $email,
                'billPhone' => $billing_user_telephone,
                'billSplitPayment' => 0,
                'billSplitPaymentArgs' => '',
                'billPaymentChannel' => '0',
                'billContentEmail' => $billContentEmail,
                'billChargeToCustomer' => 1,
                'billExpiryDate' => $billExpiryDate,
                'billExpiryDays' => $billExpiryDays
            );

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_URL, 'https://toyyibpay.com/index.php/api/createBill');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $some_data);
            $result = curl_exec($curl);
            $info = curl_getinfo($curl);
            curl_close($curl);
            $obj = json_decode($result);
            Session::put('donation_request_data', $request);
            return redirect('https://toyyibpay.com/' . $obj[0]->BillCode);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processPayTabs($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        
        $paytabs_profile_id = \App\Models\Utility::GetValueByName('paytabs_profile_id', $store->id);
        $paytabs_server_key = \App\Models\Utility::GetValueByName('paytabs_server_key', $store->id);
        $paytabs_region = \App\Models\Utility::GetValueByName('paytabs_region', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('defult_currancy', $store->id, $store->id);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        config(
            [
                'paytabs.profile_id' => $paytabs_profile_id,
                'paytabs.server_key' => $paytabs_server_key,
                'paytabs.region' => $paytabs_region,
                'paytabs.currency' => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD',
            ]
        );

        $pay = paypage::sendPaymentCode('all')
            ->sendTransaction('sale', 'ecom')
            ->sendCart(1, $donation_amount, 'Donation payment')
            ->sendCustomerDetails(isset($request['name']) ? $request['name'] : "", isset($request['email']) ? $request['email'] : '', '', '', '', '', '', '', '')
            ->sendURLs(
                route('store.donation.payment', $slug),
                route('store.donation.payment', $slug)
            )
            ->sendLanguage('en')
            ->sendFramed($on = false)
            ->create_pay_page();

        Session::put('donation_request_data', $request);
        if ($pay != null) {
            return $pay;
        } else {
            return redirect()->back()->with('error', __('Something went wrong, Please try again'));
        }
    }

    private function processIyziPay($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';

        $store = getStore($slug);
        

        $iyzipay_mode = \App\Models\Utility::GetValueByName('iyzipay_mode', $store->id);
        $iyzipay_secret_key = \App\Models\Utility::GetValueByName('iyzipay_secret_key', $store->id);
        $iyzipay_private_key = \App\Models\Utility::GetValueByName('iyzipay_private_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $adminPaymentSettings = getAdminAllSetting();
        $currency = $adminPaymentSettings['CURRENCY_NAME'] ?? ($CURRENCY_NAME ?? '$');

        $request_data = $request;

        $address = !empty($request['address']) ? $request['address'] : '';
        $setBaseUrl = ($iyzipay_mode == 'sandbox') ? 'https://sandbox-api.iyzipay.com' : 'https://api.iyzipay.com';
        $options = new \Iyzipay\Options();
        $options->setApiKey($iyzipay_private_key);
        $options->setSecretKey($iyzipay_secret_key);
        $options->setBaseUrl($setBaseUrl); // or "https://api.iyzipay.com" for production
        $ipAddress = Http::get('https://ipinfo.io/?callback=')->json();
        $address = ($address) ? $address : 'Nidakule Göztepe, Merdivenköy Mah. Bora Sok. No:1';
        // create a new payment request
        $request = new \Iyzipay\Request\CreateCheckoutFormInitializeRequest();
        $request->setLocale('en');
        $request->setPrice($donation_amount);
        $request->setPaidPrice($donation_amount);
        $request->setCurrency($currency);
        $request->setCallbackUrl(route('store.paytm.payment.iyzipay', $slug));
        $request->setEnabledInstallments(array(1));
        $request->setPaymentGroup(\Iyzipay\Model\PaymentGroup::PRODUCT);
        $buyer = new \Iyzipay\Model\Buyer();
        $buyer->setId(!empty($request_data['id']) ? $request_data['id'] : '0');
        $buyer->setName(!empty($request_data['name']) ? $request_data['name'] : 'Guest');
        $buyer->setSurname(!empty($request_data['name']) ? $request_data['name'] : 'Guest');
        $buyer->setGsmNumber(!empty($request_data['mobile']) ? $request_data['mobile'] : '9999999999');
        $buyer->setEmail(!empty($request_data['email']) ? $request_data['email'] : 'test@gmail.com');
        $buyer->setIdentityNumber(rand(0, 99999));
        $buyer->setLastLoginDate("2023-03-05 12:43:35");
        $buyer->setRegistrationDate("2023-04-21 15:12:09");
        $buyer->setRegistrationAddress($address);
        $buyer->setIp($ipAddress['ip']);
        $buyer->setCity($ipAddress['city']);
        $buyer->setCountry($ipAddress['country']);
        $buyer->setZipCode($ipAddress['postal']);
        $request->setBuyer($buyer);
        $shippingAddress = new \Iyzipay\Model\Address();
        $shippingAddress->setContactName(!empty($request_data['name']) ? $request_data['name'] : 'Guest');
        $shippingAddress->setCity($ipAddress['city']);
        $shippingAddress->setCountry($ipAddress['country']);
        $shippingAddress->setAddress($address);
        $shippingAddress->setZipCode($ipAddress['postal']);
        $request->setShippingAddress($shippingAddress);
        $billingAddress = new \Iyzipay\Model\Address();
        $billingAddress->setContactName(!empty($request_data['name']) ? $request_data['name'] : 'Guest');
        $billingAddress->setCity($ipAddress['city']);
        $billingAddress->setCountry($ipAddress['country']);
        $billingAddress->setAddress($address);
        $billingAddress->setZipCode($ipAddress['postal']);
        $request->setBillingAddress($billingAddress);
        $basketItems = array();
        $firstBasketItem = new \Iyzipay\Model\BasketItem();
        $firstBasketItem->setId("BI101");
        $firstBasketItem->setName("Binocular");
        $firstBasketItem->setCategory1("Collectibles");
        $firstBasketItem->setCategory2("Accessories");
        $firstBasketItem->setItemType(\Iyzipay\Model\BasketItemType::PHYSICAL);
        $firstBasketItem->setPrice($donation_amount);
        $basketItems[0] = $firstBasketItem;
        $request->setBasketItems($basketItems);
        try {
            $checkoutFormInitialize = \Iyzipay\Model\CheckoutFormInitialize::create($request, $options);
            Session::put('donation_request_data', $request_data);
            if ($checkoutFormInitialize->getpaymentPageUrl() != null) {
                return $checkoutFormInitialize->getpaymentPageUrl();
            } else {
                return redirect()->back()->with('error', __('Something went wrong, Please try again'));
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    private function processPayFast($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        


        $payfast_merchant_id = \App\Models\Utility::GetValueByName('payfast_merchant_id', $store->id);
        $payfast_salt_passphrase = \App\Models\Utility::GetValueByName('payfast_salt_passphrase', $store->id);
        $payfast_merchant_key = \App\Models\Utility::GetValueByName('payfast_merchant_key', $store->id);
        $payfast_mode = \App\Models\Utility::GetValueByName('payfast_mode', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);


        $name = 'test';
        $order_id = time();
        $data = array(
            // Merchant details
            'merchant_id' => !empty($payfast_merchant_id) ? $payfast_merchant_id : '',
            'merchant_key' => !empty($payfast_merchant_key) ? $payfast_merchant_key : '',
            'return_url' => route('store.donation.payment', $slug),
            'cancel_url' => route('store.donation.payment', $slug),
            'notify_url' => route('store.donation.payment', $slug),
            // Buyer details
            'name_first' => isset($request['name']) ? $request['name'] : '',
            'name_last' => isset($request['name']) ? $request['name'] : '',
            'email_address' => isset($request['email']) ? $request['email'] : '',
            // Transaction details
            'm_payment_id' => $order_id,
            'amount' => $donation_amount,
            'item_name' => 'test',
        );
        $passphrase = !empty($payfast_salt_passphrase) ? $payfast_salt_passphrase : '';
        $signature = $this->generateSignature($data, $passphrase);
        $data['signature'] = $signature;
        $checkouthtml = '';
        foreach ($data as $name => $value) {
            $checkouthtml .= '<input name="' . $name . '" type="hidden" value=\'' . $value . '\' />';
        }
        Session::put('donation_request_data', $request);
        $msg =  [
            'success' => true,
            'inputs' => $checkouthtml
        ];
        return $msg;
    }

    public function generateSignature($data, $passPhrase = null)
    {
        $pfOutput = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $pfOutput .= $key . '=' . urlencode(trim($val)) . '&';
            }
        }

        $getString = substr($pfOutput, 0, -1);
        if ($passPhrase !== null) {
            $getString .= '&passphrase=' . urlencode(trim($passPhrase));
        }
        return md5($getString);
    }

    private function processBenefit($request, $slug, $donation_amount)
    {

        $slug = !empty($slug) ? $slug : '';

        $store = getStore($slug);
        
        $benefit_secret_key = \App\Models\Utility::GetValueByName('benefit_secret_key', $store->id);
        $benefit_private_key = \App\Models\Utility::GetValueByName('benefit_private_key', $store->id);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        $customerData =
            [
                "amount" => $donation_amount,
                "currency" => $admin_payment_setting['CURRENCY_NAME'] ?? 'BHD',
                "customer_initiated" => true,
                "threeDSecure" => true,
                "save_card" => false,
                "description" => !empty($request['name']) ? $request['name'] : '',
                "metadata" => ["udf1" => "Metadata 1"],
                "reference" => ["transaction" => "txn_01", "order" => "ord_01"],
                "receipt" => ["email" => true, "sms" => true],
                "customer" => ["first_name" => $request['name'], "middle_name" => "", "last_name" => $request['name'], "email" => $request['email'], "phone" => ["country_code" => 965, "number" => 51234567]],
                "source" => ["id" => "src_bh.benefit"],
                "post" => ["url" => "https://webhook.site/fd8b0712-d70a-4280-8d6f-9f14407b3bbd"],
                "redirect" => ["url" => route('store.donation.payment', $slug)],

            ];
        session(['donation_request_data' => $request]);
        $responseData = json_encode($customerData);
        $client = new Client();
        try {
            $response = $client->request('POST', 'https://api.tap.company/v2/charges', [
                'body' => $responseData,
                'headers' => [
                    'Authorization' => 'Bearer ' . $benefit_secret_key,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
            ]);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Currency Not Supported.Contact To Your Site Admin'));
        }

        $data = $response->getBody();
        $res = json_decode($data);
        return $res->transaction->url;
    }

    private function processCashFree($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';

        $store = getStore($slug);
        
        $cashfree_secret_key = \App\Models\Utility::GetValueByName('cashfree_secret_key', $store->id);
        $cashfree_key = \App\Models\Utility::GetValueByName('cashfree_key', $store->id);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        $orderID =  date('YmdHis');
        Session::put('donation_request_data', $request);
        config(
            [
                'services.cashfree.key' => isset($cashfree_key) ? $cashfree_key : '',
                'services.cashfree.secret' => isset($cashfree_secret_key) ? $cashfree_secret_key : '',
            ]
        );
        $url = config('services.cashfree.url');
        $headers = array(
            "Content-Type: application/json",
            "x-api-version: 2022-01-01",
            "x-client-id: " . config('services.cashfree.key'),
            "x-client-secret: " . config('services.cashfree.secret')
        );
        $data = json_encode([
            'order_id' => $orderID,
            'order_amount' => $donation_amount,
            "order_currency" => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD',
            "order_name" => $request['name'],
            "customer_details" => [
                "customer_id" => 'customer_' . 1,
                "customer_name" => $request['name'],
                "customer_email" => $request['email'],
                "customer_phone" => '1234567890',
            ],
            "order_meta" => [
                "return_url" => route('store.donation.payment', $slug)
            ]
        ]);
        try {

            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

            $resp = curl_exec($curl);
            curl_close($curl);
            $redirect_url = json_decode($resp)->payment_link;
            return $redirect_url;
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Currency Not Supported.Contact To Your Site Admin'));
        }
    }

    private function processAamarPay($request, $slug, $donation_amount)
    {
        $url = 'https://sandbox.aamarpay.com/request.php';
        $slug = !empty($slug) ? $slug : '';

        $store = getStore($slug);
        
        $aamarpay_signature_key = \App\Models\Utility::GetValueByName('aamarpay_signature_key', $store->id);
        $aamarpay_description = \App\Models\Utility::GetValueByName('aamarpay_description', $store->id);
        $aamarpay_store_id = \App\Models\Utility::GetValueByName('aamarpay_store_id', $store->id);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);

        session(['donation_request_data' => $request]);
        try {

            $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
            $fields = array(
                'store_id' => $aamarpay_store_id,
                'amount' => $donation_amount,
                'payment_type' => '',
                'currency' => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD',
                'tran_id' => $orderID,
                'cus_name' => $request['name'],
                'cus_email' => $request['email'],
                'cus_add1' => '',
                'cus_add2' => '',
                'cus_city' => '',
                'cus_state' => '',
                'cus_postcode' => '',
                'cus_country' => '',
                'cus_phone' => '1234567890',
                'success_url' => route('store.paytm.payment.aamarpay', $slug),
                //your success route
                'fail_url' => route('landing_page', $slug),
                //your fail route
                'cancel_url' => route('landing_page', $slug),
                //your cancel url
                'signature_key' => $aamarpay_signature_key,
                'desc' => $aamarpay_description,
            ); //signature key will provided aamarpay, contact integration@aamarpay.com for test/live signature key

            $fields_string = http_build_query($fields);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_URL, $url);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $url_forward = str_replace('"', '', stripslashes(curl_exec($ch)));
            curl_close($ch);
            $this->redirect_to_merchant($url_forward);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e);
        }
    }

    function redirect_to_merchant($url)
    {

        $token = csrf_token();
?>
        <html xmlns="http://www.w3.org/1999/xhtml">

        <head>
            <script type="text/javascript">
                function closethisasap() {
                    document.forms["redirectpost"].submit();
                }
            </script>
        </head>

        <body onLoad="closethisasap();">

            <form name="redirectpost" method="post" action="<?php echo 'https://sandbox.aamarpay.com/' . $url; ?>">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
            </form>
        </body>

        </html>
<?php
        exit;
    }

    private function processTelegram($request, $slug, $donation_amount)
    {
        $data = $request;
        Session::put('donation_request_data', $request);
        return view('donation::payment.telegram', compact('data', 'slug', 'donation_amount'));
    }

    private function processPayTr($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);

        

        $paytr_merchant_id = \App\Models\Utility::GetValueByName('paytr_merchant_id', $store->id);
        $paytr_merchant_key = \App\Models\Utility::GetValueByName('paytr_merchant_key', $store->id);
        $paytr_salt_key = \App\Models\Utility::GetValueByName('paytr_salt_key', $store->id);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        try {

            $merchant_id    = $paytr_merchant_id;
            $merchant_key   = $paytr_merchant_key;
            $merchant_salt  = $paytr_salt_key;
            $orderID = date('YmdHis');


            $email = $request['email'];
            $payment_amount = $donation_amount;
            $merchant_oid = $orderID;
            $user_name = $request['name'];
            $user_address =  'no address';
            $user_phone = '0000000000';

            $user_basket = base64_encode(json_encode(array(
                array("Plan", $payment_amount, 1),
            )));

            if (isset($_SERVER["HTTP_CLIENT_IP"])) {
                $ip = $_SERVER["HTTP_CLIENT_IP"];
            } elseif (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
                $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
            } else {
                $ip = $_SERVER["REMOTE_ADDR"];
            }

            $user_ip = $ip;
            $timeout_limit = "30";
            $debug_on = 1;
            $test_mode = 0;
            $no_installment = 0;
            $max_installment = 0;
            $currency = $admin_payment_setting['CURRENCY_NAME'] ?? 'USD';
            $payment_amount = $payment_amount * 100;
            $hash_str = $merchant_id . $user_ip . $merchant_oid . $email . $payment_amount . $user_basket . $no_installment . $max_installment . $currency . $test_mode;
            $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $merchant_salt, $merchant_key, true));

            $request['orderID'] = $orderID;
            $request['amount'] = $payment_amount;
            $request['payment_status'] = 'failed';
            $payment_failed = $request;
            $request['payment_status'] = 'success';
            $payment_success = $request;
            Session::put('donation_request_data', $request);
            $post_vals = array(
                'merchant_id' => $merchant_id,
                'user_ip' => $user_ip,
                'merchant_oid' => $merchant_oid,
                'email' => $email,
                'payment_amount' => $payment_amount,
                'paytr_token' => $paytr_token,
                'user_basket' => $user_basket,
                'debug_on' => $debug_on,
                'no_installment' => $no_installment,
                'max_installment' => $max_installment,
                'user_name' => $user_name,
                'user_address' => $user_address,
                'user_phone' => $user_phone,
                'merchant_ok_url' => route('store.donation.payment', $slug,),
                'merchant_fail_url' => route('store.donation.payment', $slug),
                'timeout_limit' => $timeout_limit,
                'currency' => $currency,
                'test_mode' => $test_mode
            );
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://www.paytr.com/odeme/api/get-token");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);

            $result = @curl_exec($ch);

            if (curl_errno($ch)) {
                die("PAYTR IFRAME connection error. err:" . curl_error($ch));
            }

            curl_close($ch);

            $result = json_decode($result, 1);

            if ($result['status'] == 'success') {
                $token = $result['token'];
            } else {
                return redirect()->back()->with('error', $result['reason']);
            }
            return view('donation::payment.pay_tr', compact('token'));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __($e));
        }
    }

    private function processYookassa($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);

        

        $yookassa_shop_id_key = \App\Models\Utility::GetValueByName('yookassa_shop_id_key', $store->id);
        $yookassa_secret_key = \App\Models\Utility::GetValueByName('yookassa_secret_key', $store->id);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);

        try {
            if (is_int((int)$yookassa_shop_id_key)) {
                $client = new YooKassaClient();
                $client->setAuth((int)$yookassa_shop_id_key, $yookassa_secret_key);
                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
                $payment = $client->createPayment(
                    array(
                        'amount' => array(
                            'value' => $donation_amount,
                            'currency' => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD',
                        ),
                        'confirmation' => array(
                            'type' => 'redirect',
                            'return_url' => route('store.donation.payment', $slug),
                        ),

                        'capture' => true,
                        'description' => 'Заказ №1',
                    ),
                    uniqid('', true)
                );

                $type = 'Subscription';

                Session::put('payment_id', $payment['id']);
                Session::put('donation_request_data', $request);
                if ($payment['confirmation']['confirmation_url'] != null) {
                    return $payment['confirmation']['confirmation_url'];
                } else {
                    return redirect()->back()->with('error', __('Something went wrong, Please try again'));
                }
            } else {
                return redirect()->back()->with('error', __('Please Enter Valid Shop Id Key'));
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Incorrect currency of payment.'));
        }
    }

    private function processXendit($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);

        

        $xendit_api = \App\Models\Utility::GetValueByName('Xendit_api_key', $store->id);
        $Xendit_token_key = \App\Models\Utility::GetValueByName('Xendit_token_key', $store->id);

        $orderID = date('YmdHis');
        Xendit::setApiKey($xendit_api);

        $params = [
            'external_id' => $orderID,
            'description' => 'Payment for order ' . $orderID,
            'amount' => $donation_amount,
            'callback_url' =>  route('store.donation.payment', $slug),
            'success_redirect_url' => route('store.donation.payment', $slug),
            'failure_redirect_url' => route('store.donation.payment', $slug),
        ];
        $invoice = Invoice::create($params);
        Session::put('donation_request_data', $request);
        Session()->put('invoice', $invoice);

        return $invoice['invoice_url'];
    }

    private function processMidtrans($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        

        $midtrans_secret_key = \App\Models\Utility::GetValueByName('midtrans_secret_key', $store->id);

        $orderID = date('YmdHis');
        try {
            \Midtrans\Config::$serverKey = $midtrans_secret_key;
            \Midtrans\Config::$isProduction = false;
            \Midtrans\Config::$isSanitized = true;
            \Midtrans\Config::$is3ds = true;

            $params = array(
                'transaction_details' => array(
                    'order_id' => $orderID,
                    'gross_amount' => ceil($donation_amount),
                ),
                'customer_details' => array(
                    'first_name' =>  $request['name'],
                    'last_name' => $request['name'],
                    'email' => $request['email'],
                    'phone' => '8787878787',
                ),
            );
            $snapToken = \Midtrans\Snap::getSnapToken($params);
            Session::put('donation_request_data', $request);
            $data = [
                'snap_token' => $snapToken,
                'midtrans_secret' => $midtrans_secret_key,
                'order_id' => $orderID,
                'slug' => $slug,
                'amount' => $donation_amount,
                'fallback_url' => 'store.donation.payment.midtrans',
                $slug
            ];
            return view('donation::payment.midtrans', compact('data'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __($e));
        }
    }

    public function processWhatsapp($request, $slug, $donation_amount)
    {
        $data = $request;
        Session::put('donation_request_data', $request);
        return view('donation::payment.whatsapp', compact('data', 'slug', 'donation_amount'));
    }

    private function processNepalste($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        $api_key = isset($admin_payment_setting['nepalste_public_key']) ? $admin_payment_setting['nepalste_public_key'] : '';

        $requests_data = $request;


        $parameters = [
            'identifier' => 'DFU80XZIKS',
            'currency' => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD',
            'amount' => $donation_amount,
            'details' => 'test',
            'ipn_url' => route('store.donation.payment', $slug),
            'cancel_url' => route('nepalste.cancel'),
            'success_url' => route('store.donation.payment', $slug),
            'public_key' => $api_key,
            'site_logo' => 'https://nepalste.com.np/assets/images/logoIcon/logo.png',
            'checkout_theme' => 'dark',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@mail.com',
        ];

        //live end point
        // $url = "https://nepalste.com.np/payment/initiate";

        //test end point
        $url = "https://nepalste.com.np/sandbox/payment/initiate";
        Session::put('donation_request_data', $request);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS,  $parameters);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($result, true);
        if (isset($result['success'])) {
            return redirect($result['url']);
        } else {
            return redirect()->back()->with('error', __($result['message']));
        }
    }

    private function processKhalti($request, $slug, $donation_amount)
    {
        $data   = $request;
        Session::put('donation_request_data', $data);
        $store    = getStore($slug);
        Session::put('store_request_data', $store);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        return view('donation::payment.khalti', compact('data', 'admin_payment_setting', 'store', 'donation_amount'));
    }

    private function processPayHere($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        $orderID =  date('YmdHis');
        try {
            $config = [
                'payhere.api_endpoint' => $admin_payment_setting['payhere_mode'] === 'sandbox'
                    ? 'https://sandbox.payhere.lk/'
                    : 'https://www.payhere.lk/',
            ];

            $config['payhere.merchant_id'] = $admin_payment_setting['payhere_merchant_id'] ?? '';
            $config['payhere.merchant_secret'] = $admin_payment_setting['payhere_merchant_secret'] ?? '';
            $config['payhere.app_secret'] = $admin_payment_setting['payhere_app_secret'] ?? '';
            $config['payhere.app_id'] = $admin_payment_setting['payhere_app_id'] ?? '';
            config($config);

            $hash = strtoupper(
                md5(
                    $admin_payment_setting['payhere_merchant_id'] .
                        $orderID .
                        number_format($donation_amount, 2, '.', '') .
                        'LKR' .
                        strtoupper(md5($admin_payment_setting['payhere_merchant_secret']))
                )
            );

            $data = [
                'first_name' => $request['name'],
                'last_name' => $request['name'],
                'email' => $request['email'],
                'phone' => $request['number'],
                'address' => $request['address'],
                'city' => $request['city_id'],
                'country' => $request['country_id'],
                'order_id' => $orderID,
                'items' => 'test',
                'currency' => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD',
                'amount' => $donation_amount,
                'hash' => $hash,
            ];
            Session::put('donation_request_data', $request);
            return PayHere::checkOut()
                ->data($data)
                ->successUrl(route('store.donation.payment', $slug))
                ->failUrl(route('store.donation.payment', $slug))
                ->renderView();
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processAuthorizeNet($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        Session::put('store_request_data', $store);
        
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        $orderID = date('YmdHis');
        Session::put('donation_request_data', $request);

        $requests_data = $request;

        $data = [
            'get_amount'    =>  $donation_amount,
        ];

        $data  =    json_encode($data);
        $req_data   = $request;
        $get_amount = $donation_amount;

        try {
            return view('donation::payment.authorizenet', compact('req_data', 'admin_payment_setting', 'slug', 'data', 'get_amount'));
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }

    public function processTap($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        $tap_secret_key = \App\Models\Utility::GetValueByName('tap_secret_key', $store->id);

        $orderID = date('YmdHis');
        Session::put('donation_request_data', $request);

        $TapPay = new Payment(['tap_secret_key' => $tap_secret_key]);

        return $TapPay->charge([
            'amount' => $donation_amount,
            'currency' => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD',
            'threeDSecure' => 'true',
            'description' => 'test description',
            'statement_descriptor' => 'sample',
            'customer' => [
                'first_name' => $request['name'],
                'email' => $request['email'],
            ],
            'source' => [
                'id' => 'src_card'
            ],
            'post' => [
                'url' => null
            ],
            // 'merchant' => [
            //    'id' => 'YOUR-MERCHANT-ID'  //Include this when you are going to live
            // ],
            'redirect' => [
                'url' => route('store.donation.payment', $slug)
            ]
        ], true);
    }

    public function processPhonePe($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        Session::put('donation_request_data', $request);
        $orderID = $store->id .'_'. date('YmdHis');
        try {
            $phonepe = new \App\Libraries\PhonePe\PhonePe(
                isset($admin_payment_setting['phonepe_client_id']) ? $admin_payment_setting['phonepe_client_id'] : '',
                isset($admin_payment_setting['phonepe_client_secret']) ? $admin_payment_setting['phonepe_client_secret'] : '',
                isset($admin_payment_setting['phonepe_mode']) ? $admin_payment_setting['phonepe_mode'] : ''
            );

            $result = $phonepe->initiatePayment([
                'merchantOrderId' => $orderID,
                'amount' => (int) $donation_amount,
                'redirectUrl' => route('store.donation.payment', $slug)
            ]);

            if ($result['status']) {
                return redirect()->away($result['data']);
            }
            return redirect()->back()->with('error', $result['error']);

        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Payment processing failed. Please try again.'));
        }
    }

    public function processPaddle($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        

        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);

        $orderID = date('YmdHis');
        Session::put('donation_request_data', $request);
        $curerency = $admin_payment_setting['CURRENCY_NAME'] ?? 'USD';
        config(
            [
                'cashier.vendor_id' => !empty($admin_payment_setting['paddle_vendor_id']) ? $admin_payment_setting['paddle_vendor_id'] : '',
                'cashier.vendor_auth_code' => !empty($admin_payment_setting['paddle_vendor_auth_code']) ? $admin_payment_setting['paddle_vendor_auth_code'] : '',
                'cashier.public_key' => !empty($admin_payment_setting['paddle_public_key']) ? $admin_payment_setting['paddle_public_key'] : '',
                'cashier.sandbox' => !empty($admin_payment_setting['paddle_mode']) ? $admin_payment_setting['paddle_mode'] : true
            ]
        );
        $user = User::find($store->created_by);
        try {
            $price = $donation_amount;
            $paymentData = [
                "$curerency:$price"
            ];
            $priceInCents = (int) ($price);
            $printID = 'Donation payment';
            $paylink = $user->charge($priceInCents, $printID, [
                'quantity_variable' => 0,
                'discountable' => 0,
                'customer_email' => $user->email,
                'webhook_url' => route('store.donation.payment', $slug),
                'return_url' => route('landing_page', $slug),
            ]);
            return redirect($paylink);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', str_replace(array('\'', '"', '`', '{', "\n"), ' ', $th->getMessage()));
        }
    }

    public function processPaiementpro($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        

        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        $merchant_id = \App\Models\Utility::GetValueByName('merchant_id', $store->id);

        $orderID = date('YmdHis');

        Session::put('donation_request_data', $request);

        $call_back = route('store.donation.payment', $slug);
        $returnURL = route('landing_page', $store->slug);
        $data = array(
            'merchantId' => $merchant_id,
            'amount' =>  $donation_amount,
            'description' => "Api PHP",
            'channel' => 'OMCIV2',
            'countryCurrencyCode' => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD',
            'referenceNumber' => "REF-" . time(),
            'customerEmail' => $request['email'],
            'customerFirstName' => $request['name'],
            'customerLastname' =>  $request['name'],
            'customerPhoneNumber' => $request['number'],
            'notificationURL' => $call_back,
            'returnURL' => $returnURL,
            'returnContext' => json_encode(["requests_data" => $request]),
        );

        $data = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://www.paiementpro.net/webservice/onlinepayment/init/curl-init.php");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);

        curl_close($ch);
        $response = json_decode($response);

        if (isset($response->success) && $response->success == true) {
            // redirect to approve href
            return redirect($response->url);
        } else {
            return redirect()->back()->with('error', $response->message ?? __('Something went wrong.'));
        }
    }

    public function processFedPay($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        

        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        $fedpay_secret_key = \App\Models\Utility::GetValueByName('fedpay_secret_key', $store->id);
        $fedpay_mode = \App\Models\Utility::GetValueByName('fedpay_mode', $store->id);

        $orderID = date('YmdHis');
        Session::put('donation_request_data', $request);

        try {
            \FedaPay\FedaPay::setApiKey($fedpay_secret_key);
            \FedaPay\FedaPay::setEnvironment($fedpay_mode);

            $transaction = \FedaPay\Transaction::create([
                "description" => "Fedapay Payment",
                "amount" => $donation_amount,
                "currency" => ["iso" => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD'],
                "callback_url" => route('store.donation.payment', $slug),
                "cancel_url" =>  route('landing_page', $slug)
            ]);
            $token = $transaction->generateToken();

            return redirect($token->url);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', str_replace(array('\'', '"', '`', '{', "\n"), ' ', $th->getMessage()));
        }
    }

    public function processCinetPay($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        

        $cinet_pay_site_id = \App\Models\Utility::GetValueByName('cinet_pay_site_id', $store->id);
        $cinet_pay_api_key = \App\Models\Utility::GetValueByName('cinet_pay_api_key', $store->id);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        $orderID = date('YmdHis');
        Session::put('donation_request_data', $request);


        $call_back = route('store.donation.payment', $slug);

        $merchant_id = isset($cinet_pay_site_id) ? $cinet_pay_site_id : '';
        $cinet_pay_api_key = isset($cinet_pay_api_key) ? $cinet_pay_api_key : '';
        $call_back = route('store.donation.payment', [$store->slug]) . '?_token=' . csrf_token();
        $returnURL = route('landing_pages', $store->slug);

        $transaction_id = strtoupper(str_replace('.', '', uniqid('', true)));

        $data =  [
            "amount" => $donation_amount,
            "currency" => $admin_payment_setting['CURRENCY_NAME'] ?? 'USD',
            "apikey" => $cinet_pay_api_key,
            "site_id" => $merchant_id,
            "transaction_id" => $transaction_id,
            "description" => "Api PHP",
            "return_url" => $returnURL,
            "notify_url" => $call_back,
            "metadata" =>  "REF-" . time(),
            'customer_surname' => $request['name'],
            'customer_name' => $request['name'],
            'customer_email' => $request['email'],
            'customer_phone_number' => $request['number'],
            'customer_address' => null,
            'customer_city' => null,
            'customer_country' => null,
            'customer_state' => null,
            'customer_zip_code' => null,
        ];

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api-checkout.cinetpay.com/v2/payment',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTPHEADER => array(
                "content-type:application/json"
            ),
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        $response = json_decode($response);
        if (isset($response->code) && $response->code == '201') {
            $payment_link = $response->data->payment_url;
            return redirect($payment_link);
        } else {
            return redirect()->back()->with('error', $response->description ?? __('Something went wrong.'));
        }
    }
}
