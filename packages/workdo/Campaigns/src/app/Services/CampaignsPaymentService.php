<?php

namespace Workdo\Campaigns\app\Services;

use App\Events\AddRewardClubPoint;
use Illuminate\Http\Request;
use Stripe;
use Session;
use Illuminate\Support\Facades\Log;
use App\Events\AddAdditionalFields;
use Obydul\LaraSkrill\SkrillClient;
use Obydul\LaraSkrill\SkrillRequest;
use App\Http\Controllers\CartController;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Utility;
use App\Models\Cart;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Order;
use App\Models\User;
use App\Models\City;
use App\Models\OrderBillingDetail;
use App\Models\ActivityLog;
use App\Models\OrderNote;
use App\Models\AppSetting;
use App\Models\TaxMethod;
use App\Models\Coupon;
use App\Models\ProductVariant;
use App\Models\{OrderTaxDetail, OrderCouponDetail, UserCoupon};
use Paytabscom\Laravel_paytabs\Facades\paypage;
use PaytmWallet;
use GuzzleHttp\Client;
use YooKassa\Client as YooKassaClient;
use App\Coingate\Coingate;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cookie;
use App\Xendit\Xendit;
use App\Xendit\Invoice;
use App\Http\Controllers\Api\ApiController;
use Lahirulhr\PayHere\PayHere;
use App\Package\Payment;
use Dipesh79\LaravelPhonePe\LaravelPhonePe;
use App\Models\Senangpay;
use Easebuzz\Easebuzz;
use Dipesh79\LaravelEsewa\LaravelEsewa as eSewa;
use Braintree\Gateway;

class CampaignsPaymentService
{
    public function paymentConfig()
    {
        if (\Auth::check()) {
            $payment_setting = getSuperAdminAllSetting();
        }

        if ($payment_setting['paypal_mode'] == 'live') {
            config([
                'paypal.live.client_id' => isset($payment_setting['paypal_client_id']) ? $payment_setting['paypal_client_id'] : '',
                'paypal.live.client_secret' => isset($payment_setting['paypal_secret_key']) ? $payment_setting['paypal_secret_key'] : '',
                'paypal.mode' => isset($payment_setting['paypal_mode']) ? $payment_setting['paypal_mode'] : '',
            ]);
        } else {
            config([
                'paypal.sandbox.client_id' => isset($payment_setting['paypal_client_id']) ? $payment_setting['paypal_client_id'] : '',
                'paypal.sandbox.client_secret' => isset($payment_setting['paypal_secret_key']) ? $payment_setting['paypal_secret_key'] : '',
                'paypal.mode' => isset($payment_setting['paypal_mode']) ? $payment_setting['paypal_mode'] : '',
            ]);
        }
    }
    public $secretKey, $callBackUrl, $returnUrl, $categoryCode, $is_enabled;
    public function process($request)
    {
        switch ($request->payment_type) {
            case 'Stripe':
                return $this->processStripe($request);
            case 'Paypal':
                return $this->processPaypal($request);
            case 'Paystack':
                return $this->processPaystack($request);
            case 'Razorpay':
                return $this->processRazorpay($request);
            case 'Mercado':
                return $this->processMercado($request);
            case 'Skrill':
                return $this->processSkrill($request);
            case 'Paymentwall':
                return $this->processPaymentWall($request);
            case 'Flutterwave':
                return $this->processFlutterWave($request);
            case 'Paytm':
                return $this->processPayTm($request);
            case 'Mollie':
                return $this->processMollie($request);
            case 'Coingate':
                return $this->processCoingate($request);
            case 'Sspay':
                return $this->processSspay($request);
            case 'Toyyibpay':
                return $this->processToyyibPay($request);
            case 'Paytabs':
                return $this->processPayTabs($request);
            case 'Iyzipay':
                return $this->processIyziPay($request);
            case 'Payfast':
                return $this->processPayFast($request);
            case 'Benefit':
                return $this->processBenefit($request);
            case 'Cashfree':
                return $this->processCashFree($request);
            case 'Aamarpay':
                return $this->processAamarPay($request);
            case 'Paytr':
                return $this->processPayTr($request);
            case 'Yookassa':
                return $this->processYookassa($request);
            case 'Xendit':
                return $this->processXendit($request);
            case 'Midtrans':
                return $this->processMidtrans($request);
            case 'Nepalste':
                return $this->processNepalste($request);
            case 'Khalti':
                return $this->processKhalti($request);
            case 'Payhere':
                return $this->processPayHere($request);
            case 'Authorizenet':
                return $this->processAuthorizeNet($request);
            case 'Tap':
                return $this->processTap($request);
            case 'Phonepe':
                return $this->processPhonePe($request);
            case 'Paddle':
                return $this->processPaddle($request);
            case 'Paiementpro':
                return $this->processPaiementpro($request);
            case 'Fedpay':
                return $this->processFedPay($request);
            case 'Cinetpay':
                return $this->processCinetPay($request);
            case 'Senangpay':
                return $this->processSenangpay($request);
            case 'Cybersource':
                return $this->processCybersource($request);
            case 'Ozow':
                return $this->processOzow($request);
            case 'Easebuzz':
                return $this->processEasebuzz($request);
            case 'Nmi':
                return $this->getNMIProductview($request);
            case 'Payu':
                return $this->processPayU($request);
            case 'Sofort':
                return $this->processSofort($request);
            case 'Esewa':
                return $this->processESewa($request);
            case 'Paynow':
                return $this->processPaynow($request);
            case 'Dpopay':
                return $this->processDPO($request);
            case 'Braintree':
                return $this->processBraintree($request);
            case 'Powertranz':
                return $this->processPowerTranz($request);
            case 'Myfatoorah':
                return $this->processMyfatoorah($request);
            default:
                return response()->json(['error' => 'Invalid payment method'], 422);
        }
    }

    private function processStripe($request)
    {
        $amount = $request->total_cost;
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $objUser = \Auth::user();
        $product = $request->name;
        $code = '';
        /* Final price */
        $stripe_formatted_price = in_array(
            $currency,
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
        ) ? number_format($amount, 2, '.', '') : number_format($amount, 2, '.', '') * 100;
        $return_url_parameters = function ($return_type) {
            return '&return_type=' . $return_type . '&payment_processor=stripe';
        };

        /* Initiate Stripe */
        \Stripe\Stripe::setApiKey($payment_setting['stripe_secret_key']);

        $stripe_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $product,
                            'description' => $product,
                        ],
                        'unit_amount' => $stripe_formatted_price,
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'user_id' => $objUser->id,
                'package_id' => $request->campaignId,
                'code' => $code,
            ],
            'mode' => 'payment',
            'success_url' => route('promote.success', [
                'campaign_id' => $request->campaignId,
                'currency' => $currency,
                'amount' => $amount,
                'coupon_id' => $request->coupon,
                'data' => $request->all(),
            ]),
            'cancel_url' => route('promote.success', [
                'campaign_id' => $request->campaignId,
                'currency' => $currency,
                'amount' => $amount,
                'coupon_id' => $request->coupon,
                $return_url_parameters('cancel'),
            ]),
        ]);

        $stripe_session = $stripe_session ?? false;
        $requests_data = $request->all();
        Session::put('campaigns_request_data', $requests_data);

        try {
            return $stripe_session->url;
        } catch (\Exception $e) {
            return redirect()->route('campaigns.index')->with('error', __('Transaction has been failed!'));
        }
    }

    private function processPaystack($request)
    {
        $requests_data = $request->all();
        Session::put('campaigns_request_data', $requests_data);
        $amount = $request->total_cost;
        return view('campaigns::payment.paystack', compact('amount'));
    }

    private function processRazorpay($request)
    {
        $requests_data = $request->all();
        Session::put('campaigns_request_data', $requests_data);
        $amount = $request->total_cost;
        return view('campaigns::payment.razorpay', compact('amount'));
    }

    private function processMercado($request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $mercado_access_token = isset($payment_setting['mercado_access_token']) ? $payment_setting['mercado_access_token'] : '';
        $requests_data = $request->all();
        $mercado_mode = isset($payment_setting['mercado_mode']) ? $payment_setting['mercado_mode'] : '';
        $amount = $request->total_cost;

        \MercadoPago\SDK::setAccessToken($mercado_access_token);
        try {
            // Create a preference object
            $preference = new \MercadoPago\Preference();
            // Create an item in the preference
            $item              = new \MercadoPago\Item();
            $item->title       =  "Order";
            $item->quantity    = 1;
            $item->unit_price  = $amount;
            $preference->items = array($item);

            $data = json_encode($requests_data);
            Session::put('campaigns_request_data', $requests_data);
            $success_url = route(
                'promote.success',
                [
                    'flag' => 'success',
                ]
            );

            $failure_url = route(
                'promote.success',
                [
                    'flag' => 'failure',
                ]
            );
            $pending_url = route(
                'promote.success',
                [
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

    private function processSkrill($request)
    {
        $store = getStoreById(getCurrentStore());
        $payment_setting = getSuperAdminAllSetting();
        $skrill_email = isset($payment_setting['skrill_email']) ? $payment_setting['skrill_email'] : '';
        $requests_data = $request->all();
        $amount = $request->total_cost;
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $amount = $amount;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $amount)));
        $transaction_id = strtoupper(str_replace('.', '', uniqid('', true)));
        $user = \Auth::user();
        $skill               = new SkrillRequest();
        $skill->pay_to_email = $skrill_email;
        $skill->return_url   = route('promote.success');
        $skill->cancel_url   = route('promote.success');

        // create object instance of SkrillRequest
        $skill->transaction_id  = MD5($transaction_id);
        $skill->amount          = $amount;
        $skill->currency        = $currency;
        $skill->language        = 'EN';
        $skill->prepare_only    = '1';
        $skill->merchant_fields = 'site_name, customer_email';
        $skill->site_name       = $store->name;
        $skill->customer_email  = $user->email;

        $client = new SkrillClient($skill);
        $sid    = $client->generateSID(); //return SESSION ID

        // handle error
        $jsonSID = json_decode($sid);

        if ($jsonSID != null && $jsonSID->code == "BAD_REQUEST") {
            return redirect()->back()->with('error', $jsonSID->message);
        }
        // do the payment
        Session::put('campaigns_request_data', $requests_data);
        $redirectUrl = $client->paymentRedirectUrl($sid); //return redirect url
        if ($transaction_id) {
            $data = [
                'amount' => $amount,
                'trans_id' => MD5($transaction_id),
                'currency' => 'USD',

            ];
            session()->put('skrill_data', $data);
        }
        return $redirectUrl;
    }

    private function processPaymentWall($request)
    {
        $requests_data = $request->all();
        Session::put('campaigns_request_data', $requests_data);
        $amount = $request->total_cost;
        $store = getStoreById(getCurrentStore());

        return view('campaigns::payment.paymentwall', compact('amount', 'store'));
    }

    private function processPayPal($request)
    {

        $this->paymentConfig();
        $provider = new PayPalClient;
        $provider->setApiCredentials(config('paypal'));

        $paypalToken = $provider->getAccessToken();
        $payment_setting = getSuperAdminAllSetting();
        $currency = !empty($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $amount = $request->total_cost;
        $response = $provider->createOrder([
            "intent" => "CAPTURE",
            "application_context" => [
                "return_url" => route('promote.success'),
                "cancel_url" => route('promote.success'),
            ],
            "purchase_units" => [
                0 => [
                    "amount" => [
                        "currency_code" =>  $currency,
                        "value" => $amount
                    ]
                ]
            ]
        ]);
        $requests_data = $request->all();
        Session::put('campaigns_request_data', $requests_data);
        if (isset($response['id']) && $response['id'] != null) {
            // redirect to approve href
            foreach ($response['links'] as $links) {
                if ($links['rel'] == 'approve') {
                    return $links['href'];
                }
            }

            return redirect()
                ->route('campaigns.index')
                ->with('error', 'Something went wrong.');
        } else {
            return redirect()
                ->route('campaigns.index')
                ->with('error', $response['message'] ?? 'Something went wrong.');
        }
    }

    private function processFlutterWave($request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $flutterwave_public_key = isset($payment_setting['flutterwave_public_key']) ? $payment_setting['flutterwave_public_key'] : '';
        $public_key = isset($payment_setting['public_key']) ? $payment_setting['public_key'] : '';
        $store = getStoreById(getCurrentStore());
        $amount = $request->total_cost;
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $requests_data = $request->all();
        $user = \Auth::user();

        $res_data['total_price'] = $request->total_cost;
        $res_data['currency']    = $currency;
        $res_data['flag']        = 1;
        $res_data['public_key']  = $public_key;
        $res_data['store']  = $store->slug;
        $res_data['orderID']  = '1234564';
        $res_data['email']  = $user->email;

        Session::put('campaigns_request_data', $requests_data);
        return view('campaigns::payment.flutterwave', compact('res_data', 'amount'));
    }

    private function processPayTm($request)
    {
        try {
            $payment_setting = getSuperAdminAllSetting();
            $paytm_mode = isset($payment_setting['paytm_mode']) ? $payment_setting['paytm_mode'] : '';
            $paytm_merchant_id = isset($payment_setting['paytm_merchant_id']) ? $payment_setting['paytm_merchant_id'] : '';
            $paytm_merchant_key = isset($payment_setting['paytm_merchant_key']) ? $payment_setting['paytm_merchant_key'] : '';
            $paytm_industry_type = isset($payment_setting['paytm_industry_type']) ? $payment_setting['paytm_industry_type'] : '';
            $requests_data = $request->all();
            $CURRENCY_NAME = !empty($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
            $amount = $request->total_cost;
            $user = \Auth::user();

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
                        'mobile_number' => $request->mobile,
                        'email' => $user->email,
                        'amount' => $amount,
                        'callback_url' => route('promote.success'),

                    ]
                );
                Session::put('campaigns_request_data', $requests_data);

                return $payment->receive();
            } catch (\Exception $e) {
                return redirect()->route('campaigns.index')->with('error', __($e->getMessage()));
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processMollie($request)
    {

        $payment_setting = getSuperAdminAllSetting();
        $mollie_api_key = isset($payment_setting['mollie_api_key']) ? $payment_setting['mollie_api_key'] : '';
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $requests_data = $request->all();

        $amount = $request->total_cost;
        $request = $request;
        $mollie  = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($mollie_api_key);
        $payment = $mollie->payments->create(
            [
                "amount" => [
                    "currency" => "$currency",
                    "value" => str_replace(",", "", number_format($amount, 2)),
                ],
                "description" => "payment",
                "redirectUrl" => route('promote.success')

            ]
        );

        session()->put('mollie_payment_id', $payment->id);
        Session::put('campaigns_request_data', $requests_data);

        return $payment->getCheckoutUrl();
    }

    private function processCoingate($request)
    {

        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $coingate_mode = isset($payment_setting['coingate_mode']) ? $payment_setting['coingate_mode'] : '';
        $coingate_auth_token = isset($payment_setting['coingate_auth_token']) ? $payment_setting['coingate_auth_token'] : '';

        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';


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
                'price_amount' => $amount,
                'price_currency' => $currency,
                'receive_currency' => $currency,
                'callback_url' => route('promote.success'),
                'cancel_url' => route('promote.success'),
                'success_url' => route('promote.success'),
                'title' => 'Order #' . time(),
            );
            Session::put('campaigns_request_data', $requests_data);

            $order       = Coingate::coingatePayment($post_params, 'POST');

            if($order['status_code'] === 200) { 
                $response = $order['response'];
                return (object)$response;
            }else{
                return redirect()->back()->with('error', __('opps something wren wrong.'));
            }
                
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    private function processSspay($request)
    {
        try {
            $amount = $request->total_cost;
            $requests_data = $request->all();
            $payment_setting = getSuperAdminAllSetting();
            $sspay_secret_key = isset($payment_setting['sspay_secret_key']) ? $payment_setting['sspay_secret_key'] : '';
            $sspay_category_code = isset($payment_setting['sspay_category_code']) ? $payment_setting['sspay_category_code'] : '';
            $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
            $user = \Auth::user();

            $email = $user->email ?? 'Test@mail.com';
            $firstname = $user->name ?? 'Test';
            $billing_user_telephone = $user->mobile ?? '1234567890';
            $billContentEmail = "Thank you for purchasing our product!";
            $Date = date('d-m-Y');
            $billExpiryDays = 3;
            $billExpiryDate = date('d-m-Y', strtotime($Date . ' + 3 days'));

            $this->callBackUrl = route('promote.success');
            $this->returnUrl = route('promote.success');

            $some_data = array(
                'userSecretKey' => $sspay_secret_key,
                'categoryCode' => $sspay_category_code,
                'billName' => $firstname,
                'billDescription' => $firstname,
                'billPriceSetting' => 1,
                'billPayorInfo' => 1,
                'billAmount' => 100 * $amount,
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

            Session::put('campaigns_request_data', $requests_data);
            return redirect('https://sspay.my/' . $obj[0]->BillCode);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processToyyibPay($request)
    {
        try {
            $amount = $request->total_cost;
            $requests_data = $request->all();
            $payment_setting = getSuperAdminAllSetting();
            $toyyibpay_secret_key = isset($payment_setting['toyyibpay_secret_key']) ? $payment_setting['toyyibpay_secret_key'] : '';
            $toyyibpay_category_code = isset($payment_setting['toyyibpay_category_code']) ? $payment_setting['toyyibpay_category_code'] : '';
            $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
            $user = \Auth::user();

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
            Session::put('campaigns_request_data', $request);
            return redirect('https://toyyibpay.com/' . $obj[0]->BillCode);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processPayTabs($request)
    {
        try {
            $amount = $request->total_cost;
            $requests_data = $request->all();
            $payment_setting = getSuperAdminAllSetting();
            $paytabs_profile_id = isset($payment_setting['paytabs_profile_id']) ? $payment_setting['paytabs_profile_id'] : '';
            $paytabs_server_key = isset($payment_setting['paytabs_server_key']) ? $payment_setting['paytabs_server_key'] : '';
            $paytabs_region = isset($payment_setting['paytabs_region']) ? $payment_setting['paytabs_region'] : '';
            $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
            $user = \Auth::user();

            config(
                [
                    'paytabs.profile_id' => $paytabs_profile_id,
                    'paytabs.server_key' => $paytabs_server_key,
                    'paytabs.region' => $paytabs_region,
                    'paytabs.currency' => $currency,
                ]
            );

            $pay = paypage::sendPaymentCode('all')
                ->sendTransaction('sale', 'ecom')
                ->sendCart(1, $amount, 'campaign payment')
                ->sendCustomerDetails(isset($user->name) ? $user->name : "", isset($user->email) ? $user->email : '', '', '', '', '', '', '', '')
                ->sendURLs(
                    route('promote.success'),
                    route('promote.success')
                )
                ->sendLanguage('en')
                ->sendFramed($on = false)
                ->create_pay_page();
            Session::put('campaigns_request_data', $requests_data);
            if ($pay != null) {
                return $pay;
            } else {
                return redirect()->back()->with('error', 'Something went wrong, Please try again');
            }
        } catch (\Throwable $th) {
        }
    }

    private function processIyziPay($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $iyzipay_mode = isset($payment_setting['iyzipay_mode']) ? $payment_setting['iyzipay_mode'] : '';
        $iyzipay_private_key = isset($payment_setting['iyzipay_private_key']) ? $payment_setting['iyzipay_private_key'] : '';
        $iyzipay_secret_key = isset($payment_setting['iyzipay_secret_key']) ? $payment_setting['iyzipay_secret_key'] : '';
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $user = \Auth::user();

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
        $request->setPrice($amount);
        $request->setPaidPrice($amount);
        $request->setCurrency($currency);
        $request->setCallbackUrl(route('promote.success'));
        $request->setEnabledInstallments(array(1));
        $request->setPaymentGroup(\Iyzipay\Model\PaymentGroup::PRODUCT);
        $buyer = new \Iyzipay\Model\Buyer();
        $buyer->setId(!empty($user->id) ? $user->id : '0');
        $buyer->setName(!empty($user->name) ? $user->name : 'Guest');
        $buyer->setSurname(!empty($user->name) ? $user->name : 'Guest');
        $buyer->setGsmNumber(!empty($user->mobile) ? $user->mobile : '9999999999');
        $buyer->setEmail(!empty($user->email) ? $user->email : 'test@gmail.com');
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
        $shippingAddress->setContactName(!empty($user->name) ?  $user->name : 'Guest');
        $shippingAddress->setCity($ipAddress['city']);
        $shippingAddress->setCountry($ipAddress['country']);
        $shippingAddress->setAddress($address);
        $shippingAddress->setZipCode($ipAddress['postal']);
        $request->setShippingAddress($shippingAddress);
        $billingAddress = new \Iyzipay\Model\Address();
        $billingAddress->setContactName(!empty($user->name) ?  $user->name : 'Guest');
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
        $firstBasketItem->setPrice($amount);
        $basketItems[0] = $firstBasketItem;
        $request->setBasketItems($basketItems);
        try {
            $checkoutFormInitialize = \Iyzipay\Model\CheckoutFormInitialize::create($request, $options);
            Session::put('campaigns_request_data', $requests_data);
            if ($checkoutFormInitialize->getpaymentPageUrl() != null) {
                return $checkoutFormInitialize->getpaymentPageUrl();
            } else {
                return redirect()->back()->with('error', 'Something went wrong, Please try again');
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    private function processPayFast($request)
    {
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $payfast_merchant_id = isset($payment_setting['payfast_merchant_id']) ? $payment_setting['payfast_merchant_id'] : '';
        $payfast_merchant_key = isset($payment_setting['payfast_merchant_key']) ? $payment_setting['payfast_merchant_key'] : '';
        $payfast_salt_passphrase = isset($payment_setting['payfast_salt_passphrase']) ? $payment_setting['payfast_salt_passphrase'] : '';
        $user = \Auth::user();
        $amount = $request->total_cost;
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $name = 'test';
        $order_id = time();
        $data = array(
            // Merchant details
            'merchant_id' => $payfast_merchant_id,
            'merchant_key' => $payfast_merchant_key,
            'return_url' => route('promote.success'),
            'cancel_url' => route('promote.success'),
            'notify_url' => route('promote.success'),
            // Buyer details
            'name_first' => $user->name ?? '',
            'name_last' => $user->name ?? '',
            'email_address' => $user->email ?? '',
            // Transaction details
            'm_payment_id' => $order_id,
            'amount' => $amount,
            'item_name' => 'test',
        );
        $passphrase = $payfast_salt_passphrase;
        $signature = $this->generateSignature($data, $passphrase);
        $data['signature'] = $signature;
        $checkouthtml = '';
        foreach ($data as $name => $value) {
            $checkouthtml .= '<input name="' . $name . '" type="hidden" value=\'' . $value . '\' />';
        }
        Session::put('campaigns_request_data', $requests_data);

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

    private function processBenefit($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();

        $benefit_secret_key = isset($payment_setting['benefit_secret_key']) ? $payment_setting['benefit_secret_key'] : '';
        $benefit_private_key = isset($payment_setting['benefit_private_key']) ? $payment_setting['benefit_private_key'] : '';
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $user = \Auth::user();

        $customerData =
            [
                "amount" => $amount,
                "currency" => $payment_setting['CURRENCY_NAME'] ?? 'BHD',
                "customer_initiated" => true,
                "threeDSecure" => true,
                "save_card" => false,
                "description" => !empty($user->name) ? $user->name : '',
                "metadata" => ["udf1" => "Metadata 1"],
                "reference" => ["transaction" => "txn_01", "order" => "ord_01"],
                "receipt" => ["email" => true, "sms" => true],
                "customer" => ["first_name" => $user->name, "middle_name" => "", "last_name" => $user->name, "email" => $user->email, "phone" => ["country_code" => 965, "number" => 51234567]],
                "source" => ["id" => "src_bh.benefit"],
                "post" => ["url" => "https://webhook.site/fd8b0712-d70a-4280-8d6f-9f14407b3bbd"],
                "redirect" => ["url" => route('promote.success')],

            ];
        session(['campaigns_request_data' => $requests_data]);
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
            return redirect()->back()->with('error', 'Currency Not Supported.Contact To Your Site Admin');
        }

        $data = $response->getBody();
        $res = json_decode($data);
        return $res->transaction->url;
    }

    private function processCashFree($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $cashfree_secret_key = isset($payment_setting['cashfree_secret_key']) ? $payment_setting['cashfree_secret_key'] : '';
        $cashfree_key = isset($payment_setting['cashfree_key']) ? $payment_setting['cashfree_key'] : '';
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $user = \Auth::user();

        $orderID =  date('YmdHis');
        Session::put('campaigns_request_data', $requests_data);
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
            'order_amount' => $amount,
            "order_currency" => $currency,
            "order_name" => $user->name,
            "customer_details" => [
                "customer_id" => 'customer_' . 1,
                "customer_name" => $user->name,
                "customer_email" => $user->email,
                "customer_phone" => '1234567890',
            ],
            "order_meta" => [
                "return_url" => route('promote.success')
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
            return redirect()->back()->with('error', 'Currency Not Supported');
        }
    }

    private function processAamarPay($request)
    {
        $url = 'https://sandbox.aamarpay.com/request.php';

        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $aamarpay_signature_key = isset($payment_setting['aamarpay_signature_key']) ? $payment_setting['aamarpay_signature_key'] : '';
        $aamarpay_description = isset($payment_setting['aamarpay_description']) ? $payment_setting['aamarpay_description'] : '';
        $aamarpay_store_id = isset($payment_setting['aamarpay_store_id']) ? $payment_setting['aamarpay_store_id'] : '';
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        //only work BDT
        $user = \Auth::user();
        Session::put('campaigns_request_data', $requests_data);


        // session(['campaigns_request_data' => $requests_data]);
        try {

            $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
            $fields = array(
                'store_id' => $aamarpay_store_id,
                'amount' => $amount,
                'payment_type' => '',
                'currency' => $currency,
                'tran_id' => $orderID,
                'cus_name' => $user->name,
                'cus_email' => $user->email,
                'cus_add1' => '',
                'cus_add2' => '',
                'cus_city' => '',
                'cus_state' => '',
                'cus_postcode' => '',
                'cus_country' => '',
                'cus_phone' => '1234567890',
                'success_url' => route('promote.success'),
                //your success route
                'fail_url' => route('campaigns.index'),
                //your fail route
                'cancel_url' => route('campaigns.index'),
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

    private function processPayTr($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $paytr_merchant_id = isset($payment_setting['paytr_merchant_id']) ? $payment_setting['paytr_merchant_id'] : '';
        $paytr_merchant_key = isset($payment_setting['paytr_merchant_key']) ? $payment_setting['paytr_merchant_key'] : '';
        $paytr_salt_key = isset($payment_setting['paytr_salt_key']) ? $payment_setting['paytr_salt_key'] : '';
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $user = \Auth::user();
        try {

            $merchant_id    = $paytr_merchant_id;
            $merchant_key   = $paytr_merchant_key;
            $merchant_salt  = $paytr_salt_key;
            $orderID = date('YmdHis');


            $email = $user->email;
            $payment_amount = $amount;
            $merchant_oid = $orderID;
            $user_name = $user->name;
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
            $payment_amount = $payment_amount * 100;
            $hash_str = $merchant_id . $user_ip . $merchant_oid . $email . $payment_amount . $user_basket . $no_installment . $max_installment . $currency . $test_mode;
            $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $merchant_salt, $merchant_key, true));

            $request['orderID'] = $orderID;
            $request['amount'] = $payment_amount;
            $request['payment_status'] = 'failed';
            $payment_failed = $request;
            $request['payment_status'] = 'success';
            $payment_success = $request;
            Session::put('campaigns_request_data', $requests_data);
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
                'merchant_ok_url' => route('promote.success'),
                'merchant_fail_url' => route('promote.success'),
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
            return view('campaigns::payment.pay_tr', compact('token'));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __($e));
        }
    }

    private function processYookassa($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();

        $yookassa_shop_id_key = isset($payment_setting['yookassa_shop_id_key']) ? $payment_setting['yookassa_shop_id_key'] : '';
        $yookassa_secret_key = isset($payment_setting['yookassa_secret_key']) ? $payment_setting['yookassa_secret_key'] : '';

        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        //ONLY WORK 'RUB'
        $user = \Auth::user();
        try {
            if (is_int((int)$yookassa_shop_id_key)) {
                $client = new YooKassaClient();
                $client->setAuth((int)$yookassa_shop_id_key, $yookassa_secret_key);
                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
                $payment = $client->createPayment(
                    array(
                        'amount' => array(
                            'value' => $amount,
                            'currency' => $currency,
                        ),
                        'confirmation' => array(
                            'type' => 'redirect',
                            'return_url' => route('promote.success'),
                        ),

                        'capture' => true,
                        'description' => 'Заказ №1',
                    ),
                    uniqid('', true)
                );

                $type = 'Subscription';

                Session::put('payment_id', $payment['id']);
                Session::put('campaigns_request_data', $requests_data);
                if ($payment['confirmation']['confirmation_url'] != null) {
                    return $payment['confirmation']['confirmation_url'];
                } else {
                    return redirect()->back()->with('error', 'Something went wrong, Please try again');
                }
            } else {
                return redirect()->back()->with('error', 'Please Enter  Valid Shop Id Key');
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Incorrect currency of payment.'));
        }
    }

    private function processXendit($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();

        $Xendit_api_key = isset($payment_setting['Xendit_api_key']) ? $payment_setting['Xendit_api_key'] : '';
        $Xendit_token_key = isset($payment_setting['Xendit_token_key']) ? $payment_setting['Xendit_token_key'] : '';
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $user = \Auth::user();


        $orderID = date('YmdHis');
        Xendit::setApiKey($Xendit_api_key);

        $params = [
            'external_id' => $orderID,
            'description' => 'Payment for order ' . $orderID,
            'amount' => $amount,
            'callback_url' =>  route('promote.success'),
            'success_redirect_url' => route('promote.success'),
            'failure_redirect_url' => route('promote.success'),
        ];
        $invoice = Invoice::create($params);
        Session::put('campaigns_request_data', $requests_data);
        Session()->put('invoice', $invoice);

        return $invoice['invoice_url'];
    }

    private function processMidtrans($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();

        $midtrans_secret_key = isset($payment_setting['midtrans_secret_key']) ? $payment_setting['midtrans_secret_key'] : '';

        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $user = \Auth::user();
        $orderID = date('YmdHis');
        try {
            \Midtrans\Config::$serverKey = $midtrans_secret_key;
            \Midtrans\Config::$isProduction = false;
            \Midtrans\Config::$isSanitized = true;
            \Midtrans\Config::$is3ds = true;

            $params = array(
                'transaction_details' => array(
                    'order_id' => $orderID,
                    'gross_amount' => ceil($amount),
                ),
                'customer_details' => array(
                    'first_name' =>  $user->name,
                    'last_name' => $user->name,
                    'email' => $user->email,
                    'phone' => '8787878787',
                ),
            );
            $snapToken = \Midtrans\Snap::getSnapToken($params);
            Session::put('campaigns_request_data', $requests_data);
            $data = [
                'snap_token' => $snapToken,
                'midtrans_secret' => $midtrans_secret_key,
                'order_id' => $orderID,
                'slug' => '',
                'amount' => $amount,
                'fallback_url' => 'promote.success'
            ];
            return view('campaigns::payment.midtrans', compact('data'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __($e));
        }
    }
    private function processNepalste($request)
    {
        try {
            // Retrieve admin payment settings
            $payment_setting = getSuperAdminAllSetting();
            $api_key = isset($payment_setting['nepalste_public_key']) ? $payment_setting['nepalste_public_key'] : '';
            $requests_data = $request->all();
            $CURRENCY_NAME = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

            // Prepare payment parameters
            $parameters = [
                'identifier' => 'DFU80XZIKS',
                'currency' =>  $CURRENCY_NAME,
                'amount' => $request->total_cost,
                'details' => 'test',
                'ipn_url' => route('promote.success', [
                    'campaign_id' => $request->campaignId,
                    'currency' => $CURRENCY_NAME,
                    'amount' =>  $request->total_cost,
                    'coupon_id' => $request->coupon,
                    'data' => $request->all(),
                ]),
                'cancel_url' => route('nepalste.cancel'),
                'success_url' => route('promote.success', [
                    'campaign_id' => $request->campaignId,
                    'currency' => 'NPR',
                    'amount' =>  $request->total_cost,
                    'coupon_id' => $request->coupon,
                    'data' => $request->all(),
                ]),
                'public_key' => $api_key,
                'site_logo' => 'https://nepalste.com.np/assets/images/logoIcon/logo.png',
                'checkout_theme' => 'dark',
                'customer_name' => 'John Doe',
                'customer_email' => 'john@mail.com',
            ];

            // Set the URL for payment initiation
            $url = "https://nepalste.com.np/sandbox/payment/initiate";
            Session::put('campaigns_request_data', $requests_data);
            // Initialize CURL session
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POSTFIELDS,  $parameters);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Execute CURL request
            $result = curl_exec($ch);

            // Check for CURL errors
            if (curl_errno($ch)) {
                throw new \Exception(curl_error($ch));
            }
            curl_close($ch);

            $result = json_decode($result, true);
            if (isset($result['success'])) {
                return redirect($result['url']);
            } else {
                return redirect()->back()->with('error', __($result['message']));
            }
        } catch (\Exception $e) {

            Log::error('Error during Nepalste payment initiation: ' . $e->getMessage());
            return redirect()->back()->with('error', 'An error occurred during payment initiation. Please try again later.');
        }
    }

    private function processKhalti($request)
    {
        try {
            $data = $request;
            $requests_data = $request->all();
            Session::put('campaigns_request_data', $requests_data);
            $payment_setting = getSuperAdminAllSetting();
            $amount = $request->total_cost;
            return view('campaigns::payment.khalti', compact('data', 'requests_data', 'payment_setting', 'amount'));
        } catch (\Throwable $th) {
        }
    }

    private function processPayHere($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $user = \Auth::user();
        $orderID =  date('YmdHis');
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        try {
            $config = [
                'payhere.api_endpoint' => $payment_setting['payhere_mode'] === 'sandbox'
                    ? 'https://sandbox.payhere.lk/'
                    : 'https://www.payhere.lk/',
            ];

            $config['payhere.merchant_id'] = $payment_setting['payhere_merchant_id'] ?? '';
            $config['payhere.merchant_secret'] = $payment_setting['payhere_merchant_secret'] ?? '';
            $config['payhere.app_secret'] = $payment_setting['payhere_app_secret'] ?? '';
            $config['payhere.app_id'] = $payment_setting['payhere_app_id'] ?? '';
            config($config);

            $hash = strtoupper(
                md5(
                    $payment_setting['payhere_merchant_id'] .
                        $orderID .
                        number_format($amount, 2, '.', '') .
                        'LKR' .
                        strtoupper(md5($payment_setting['payhere_merchant_secret']))
                )
            );

            $data = [
                'first_name' => $user->name,
                'last_name' => $user->name,
                'email' => $user->email,
                'phone' => $user->mobile,
                'address' => ' ',
                'city' => '',
                'country' => '',
                'order_id' => $orderID,
                'items' => 'test',
                'currency' => $currency,
                'amount' => $amount,
                'hash' => $hash,
            ];

            Session::put('campaigns_request_data', $requests_data);
            return PayHere::checkOut()
                ->data($data)
                ->successUrl(route('promote.success'))
                ->failUrl(route('promote.success'))
                ->renderView();
        } catch (\Exception $e) {
            \Log::debug($e->getMessage());
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processAuthorizeNet($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $orderID = date('YmdHis');
        Session::put('campaigns_request_data', $requests_data);

        $data = [
            'get_amount'    =>  $amount,
        ];

        $data  =    json_encode($data);
        $req_data   = \Auth::user();
        $get_amount = $amount;

        try {
            return view('campaigns::payment.authorizenet', compact('req_data', 'payment_setting', 'data', 'get_amount'));
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }

    public function processTap($request)
    {

        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();

        $tap_secret_key = isset($payment_setting['tap_secret_key']) ? $payment_setting['tap_secret_key'] : '';

        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $user = \Auth::user();
        $orderID = date('YmdHis');
        Session::put('campaigns_request_data', $requests_data);

        $TapPay = new Payment(['tap_secret_key' => $tap_secret_key]);

        return $TapPay->charge([
            'amount' => $amount,
            'currency' => $currency,
            'threeDSecure' => 'true',
            'description' => 'test description',
            'statement_descriptor' => 'sample',
            'customer' => [
                'first_name' => $user->name,
                'email' => $user->email,
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
                'url' => route('promote.success')
            ]
        ], true);
    }

    public function processPhonePe($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        Session::put('campaigns_request_data', $requests_data);
        $user = \Auth::user();
        $orderID = $user->id .'_'. date('YmdHis');

        try {
            $phonepe = new \App\Libraries\PhonePe\PhonePe(
                isset($payment_setting['phonepe_client_id']) ? $payment_setting['phonepe_client_id'] : '',
                isset($payment_setting['phonepe_client_secret']) ? $payment_setting['phonepe_client_secret'] : '',
                isset($payment_setting['phonepe_mode']) ? $payment_setting['phonepe_mode'] : ''
            );

            $result = $phonepe->initiatePayment([
                'merchantOrderId' => $orderID,
                'amount' => (int) $amount,
                'redirectUrl' => route('promote.success')
            ]);

            if ($result['status']) {
                return redirect()->away($result['data']);
            }
            return redirect()->back()->with('error', $result['error']);

        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Payment processing failed. Please try again.'));
        }
    }

    public function processPaddle($request)
    {

        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $user = \Auth::user();

        $orderID = date('YmdHis');
        Session::put('campaigns_request_data', $requests_data);

        config(
            [
                'cashier.vendor_id' => !empty($payment_setting['paddle_vendor_id']) ? $payment_setting['paddle_vendor_id'] : '',
                'cashier.vendor_auth_code' => !empty($payment_setting['paddle_vendor_auth_code']) ? $payment_setting['paddle_vendor_auth_code'] : '',
                'cashier.public_key' => !empty($payment_setting['paddle_public_key']) ? $payment_setting['paddle_public_key'] : '',
                'cashier.sandbox' => !empty($payment_setting['paddle_mode']) ? $payment_setting['paddle_mode'] : true
            ]
        );
        try {
            $price = $amount;

            $paymentData = [
                "$currency:$price"
            ];
            $priceInCents = (int) ($price);
            $printID = ' payment';
            $paylink = $user->charge($priceInCents, $printID, [
                'quantity_variable' => 0,
                'discountable' => 0,
                'customer_email' => $user->email,
                'webhook_url' => route('promote.success'),
                'return_url' => route('campaigns.index'),
            ]);
            return redirect($paylink);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', str_replace(array('\'', '"', '`', '{', "\n"), ' ', $th->getMessage()));
        }
    }

    public function processPaiementpro($request)
    {

        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $merchant_id = isset($payment_setting['merchant_id']) ? $payment_setting['merchant_id'] : '';
        $user = \Auth::user();
        $orderID = date('YmdHis');

        Session::put('campaigns_request_data', $requests_data);

        $call_back = route('promote.success');
        $data = array(
            'merchantId' => $merchant_id,
            'amount' =>  $amount,
            'description' => "Api PHP",
            'channel' => $request->channel,
            'countryCurrencyCode' => $currency,
            'referenceNumber' => "REF-" . time(),
            'customerEmail' => $user->email,
            'customerFirstName' => $user->name,
            'customerLastname' =>  $user->name,
            'customerPhoneNumber' => $request->mobile_number,
            'notificationURL' => $call_back,
            'returnURL' => $call_back,
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
            return redirect()->back()->with('error', $response->message ?? 'Something went wrong.');
        }
    }

    public function processFedPay($request)
    {

        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $fedpay_secret_key = isset($payment_setting['fedpay_secret_key']) ? $payment_setting['fedpay_secret_key'] : '';
        $fedpay_mode = isset($payment_setting['fedpay_mode']) ? $payment_setting['fedpay_mode'] : '';
        $orderID = date('YmdHis');
        Session::put('campaigns_request_data', $requests_data);
        try {

            \FedaPay\FedaPay::setApiKey($fedpay_secret_key);
            \FedaPay\FedaPay::setEnvironment($fedpay_mode);
            $transaction = \FedaPay\Transaction::create([
                "description" => "Fedapay Payment",
                "amount" => $amount,
                "currency" => ["iso" => $currency],
                "callback_url" => route('promote.success'),
                "cancel_url" =>  route('campaigns.index')
            ]);
            $token = $transaction->generateToken();
            return redirect($token->url);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', str_replace(array('\'', '"', '`', '{', "\n"), ' ', $th->getMessage()));
        }
    }

    public function processCinetPay($request)
    {


        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();

        $cinet_pay_site_id = isset($payment_setting['cinet_pay_site_id']) ? $payment_setting['cinet_pay_site_id'] : '';
        $cinet_pay_api_key = isset($payment_setting['cinet_pay_api_key']) ? $payment_setting['cinet_pay_api_key'] : '';

        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $user = \Auth::user();
        $orderID = date('YmdHis');
        Session::put('campaigns_request_data', $requests_data);


        $call_back = route('promote.success');

        $merchant_id = isset($cinet_pay_site_id) ? $cinet_pay_site_id : '';
        $cinet_pay_api_key = isset($cinet_pay_api_key) ? $cinet_pay_api_key : '';
        $call_back = route('promote.success') . '?_token=' . csrf_token();
        $returnURL = route('campaigns.index');

        $transaction_id = strtoupper(str_replace('.', '', uniqid('', true)));

        $data =  [
            "amount" => $amount,
            "currency" => $currency,
            "apikey" => $cinet_pay_api_key,
            "site_id" => $merchant_id,
            "transaction_id" => $transaction_id,
            "description" => "Api PHP",
            "return_url" => $returnURL,
            "notify_url" => $call_back,
            "metadata" =>  "REF-" . time(),
            'customer_surname' => $user->name,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'customer_phone_number' => $user->mobile,
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
            return redirect()->back()->with('error', $response->description ?? 'Something went wrong.');
        }
    }
    public function processSenangpay($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $user = \Auth::user();


        $orderID =  date('YmdHis');


        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $amount)));
        Session::put('campaigns_request_data', $requests_data);
        $price = $requests_data['total_cost'];

        try {
            $transactionId = uniqid();
            $request = [
                'full_name' => $user->name,
                'email' => $user->email,
                'contact_number' => $user->mobile
            ];

            $senangPay = new Senangpay();
            $senangPay->setSendPaymentDetails($request, 'null', $orderID, $amount, $user->id);

            return redirect($senangPay->processPayment());
        } catch (\Exception $e) {
            \Log::debug($e->getMessage());
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
    public function processCybersource($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();

        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $user = \Auth::user();
        $callback_url = 'promote.success';

        $price = $requests_data['total_cost'];
        $data = [
            'name' => $user->name,
            'email' => $user->email,
            'contact_number' => $user->mobile,
            'amount' =>  $price,
        ];
        $data  =    json_encode($data);
        $name = $user->name;
        Session::put('campaigns_request_data', $requests_data);
        try {
            return view('campaigns::payment.cybersource', compact('callback_url', 'price', 'data', 'name', 'currency'));
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }
    public function processOzow($request)
    {
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        // $currency = 'ZAR';
        $orderID =  date('YmdHis');
        Session::put('campaigns_request_data', $requests_data);

        try {
            $siteCode = isset($payment_setting['ozow_pay_Site_key']) ? $payment_setting['ozow_pay_Site_key'] : '';
            $privateKey = isset($payment_setting['ozow_pay_private_key']) ? $payment_setting['ozow_pay_private_key'] : '';
            $apiKey = isset($payment_setting['ozow_pay_api_key']) ? $payment_setting['ozow_pay_api_key'] : '';
            $isTest         = isset($payment_setting['ozow_mode']) && $payment_setting['ozow_mode'] == 'sandbox'  ? 'true' : 'false';
            $countryCode    = "ZA";
            $currencyCode   = $currency;
            $amount = $request->total_cost;
            $bankReference  = time() . 'FKU';
            $transactionReference = time();


            if ($currencyCode != 'ZAR') {
                return redirect()->back()->with('error', __('currency not supported'));
            }

            $cancelUrl  =  route('campaigns.index');

            $errorUrl   = route('campaigns.index');

            $successUrl = route('promote.success');

            $notifyUrl  = route('promote.success');

            // Calculate the hash with the exact same data being sent
            $inputString    = $siteCode . $countryCode . $currencyCode . $amount . $transactionReference . $bankReference . $cancelUrl . $errorUrl . $successUrl . $notifyUrl . $isTest . $privateKey;

            $hashCheck      =  \App\Http\Controllers\OzowController::generate_request_hash_check($inputString);
            $data = [
                "countryCode"           => $countryCode,
                "amount"                => $amount,
                "transactionReference"  => $transactionReference,
                "bankReference"         => $bankReference,
                "cancelUrl"             => $cancelUrl,
                "currencyCode"          => $currencyCode,
                "errorUrl"              => $errorUrl,
                "isTest"                => $isTest, // boolean value here is okay
                "notifyUrl"             => $notifyUrl,
                "siteCode"              => $siteCode,
                "successUrl"            => $successUrl,
                "hashCheck"             => $hashCheck,
            ];

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL             => 'https://api.ozow.com/postpaymentrequest',
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_ENCODING        => '',
                CURLOPT_MAXREDIRS       => 10,
                CURLOPT_TIMEOUT         => 0,
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST   => 'POST',
                CURLOPT_POSTFIELDS      => json_encode($data),
                CURLOPT_HTTPHEADER      => array(
                    'Accept: application/json',
                    'ApiKey: ' . $apiKey,
                    'Content-Type: application/json'
                ),
            ));
            $response = curl_exec($curl);
            curl_close($curl);
            $json_attendance = json_decode($response, true);
            if (isset($json_attendance['url']) && $json_attendance['url'] != null) {

                return redirect()->away($json_attendance['url']);
            } else {
                return redirect()->back()->with('error', $response['message'] ?? 'Something went wrong.');
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }
    public function processEasebuzz($request)
    {
        try {
            $amount = $request->total_cost;
            $requests_data = $request->all();
            $payment_setting = getSuperAdminAllSetting();

            $easebuzz_merchant_key = isset($payment_setting['easebuzz_merchant_key']) ? $payment_setting['easebuzz_merchant_key'] : '';
            $easebuzz_salt_key = isset($payment_setting['easebuzz_salt_key']) ? $payment_setting['easebuzz_salt_key'] : '';
            $easebuzz_enviroment_name = isset($payment_setting['easebuzz_enviroment_name']) ? $payment_setting['easebuzz_enviroment_name'] : '';
            $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
            $user = \Auth::user();
            $orderID =  date('YmdHis');
            $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $amount)));

            Session::put('campaigns_request_data', $requests_data);
            $serialized_request_data = urlencode(json_encode($request->all()));

            $call_back = route('promote.success') . '?_token=' . csrf_token() . '&request_data=' . $serialized_request_data;
            $returnURL = route('promote.success');

            $transaction_id = strtoupper(str_replace('.', '', uniqid('', true)));
            $easebuzzObj = new Easebuzz($easebuzz_merchant_key, $easebuzz_salt_key, $easebuzz_enviroment_name);
            $price = number_format((float)$amount, 2, '.', '');
            $postData = array(
                "txnid" => $transaction_id,
                "amount" => $price,
                "firstname" => $user->name ?? 'Test',
                "email" => $user->email ?? 'test@example.com',
                "phone" => '7878787878',
                "productinfo" => 'Test',
                "surl" => $call_back,
                "furl" => $returnURL,
                "udf1" => "aaaa",
                "udf2" => "aaaa",
                "udf3" => "aaaa",
                "udf4" => "aaaa",
                "udf5" => "aaaa",
                "address1" => "aaaa",
                "address2" => "aaaa",
                "city" => "aaaa",
                "state" => "aaaa",
                "country" => "aaaa",
                "zipcode" => "123123"
            );
           $easebuzzObj->initiatePaymentAPI($postData);
            if ($easebuzzObj) {
                return redirect()->back()->with('error', __('Something went wrong'));
            }
        } catch (\Throwable $th) {
        }
    }
    public function getNMIProductview($request)
    {
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();
        Session::put('campaigns_request_data', $requests_data);
        return view('campaigns::payment.nmi', compact('request'));
    }
    public function processPayU($request)
    {
        try {
            $amount = $request->total_cost;
            $requests_data = $request->all();
            $payment_setting = getSuperAdminAllSetting();
            $merchant_key = isset($payment_setting['payu_merchant_key']) ? $payment_setting['payu_merchant_key'] : '';
            $salt_key = isset($payment_setting['payu_salt_key']) ? $payment_setting['payu_salt_key'] : '';

            $payu_mode = isset($payment_setting['payu_mode']) ? $payment_setting['payu_mode'] : '';
            $user = \Auth::user();
            $orderID =  date('YmdHis');
            $pay_id = $orderID . '777';
            $txnid = $orderID;
            Session::put('campaigns_request_data', $requests_data);

            $furl = route('promote.success', ['data' => Crypt::encrypt(['status' => 'false', 'order_id' => $orderID])]);
            $surl = route('promote.success', ['data' => Crypt::encrypt(['status' => 'true', 'order_id' => $orderID])]);

            $pay = [
                'key' => $merchant_key,
                'mode' => $payu_mode,
                'salt' => $salt_key,
                'txnid' => $txnid,
                'amount' => $amount,
                'firstname' =>  $user->name,
                'email' =>  $user->email,
                'surl' => $surl,
                'furl' => $furl,
                'productinfo' => 'WorkDo',
                'service_provider' => 'payu_paisa',
            ];
            $request->session()->put('\'' . $pay_id . '\'', $pay);

            return redirect()->to(route('campaigns.payu', ['pay' => Crypt::encrypt($pay_id)]));
        } catch (\Throwable $th) {
        }
    }
    public function processPaynow($request)
    {
        try {
            $requests_data = $request->all();
            $payment_setting = getSuperAdminAllSetting();
            $amount = $request->total_cost;
            $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
            Session::put('campaigns_request_data', $requests_data);
            $orderID =  date('YmdHis');
            $user = \Auth::user();

            $integration_id = isset($payment_setting['paynow_pay_integration_id']) ? $payment_setting['paynow_pay_integration_id'] : '';
            $integration_key = isset($payment_setting['paynow_pay_integration_key']) ? $payment_setting['paynow_pay_integration_key'] : '';
            $merchant_email = isset($payment_setting['paynow_pay_merchant_email']) ? $payment_setting['paynow_pay_merchant_email'] : '';
            $mode = isset($payment_setting['paynow_mode']) && $payment_setting['paynow_mode'] == 'sandbox'  ? 'sandbox' : 'production';

            $data = [
                'amount' => $amount,
                'order_id' => $orderID,
                'email' => $user->email,
            ];

            $success_url = route('promote.success', ['data' => $data]);
            $faild_url = route('promote.success', ['data' => $data]);

            if ($mode == 'sandbox') {
                $email = $merchant_email;
            } elseif ($mode == 'production') {
                $email = $user->email;
            }
            $pay = [
                'integration_id' => $integration_id,
                'integration_key' => $integration_key,
                'success_url' => $success_url,
                'faild_url' => $faild_url,
                'email' => $email,
                'order_id' => $orderID,
                'item' => 'Item Order',
                'amount' => $amount,
            ];

            $response = \Modules\Campaigns\app\Http\Controllers\CampaignPaymentController::paynowPayment($pay);

            return $response;
        } catch (\Exception $ex) {
            $exMessage = __('Paynow.' . $ex->getMessage());
            return redirect()->route('promote.success')->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }

    private function processSofort($request)
    {
        try {
            $amount = $request->total_cost;
            $payment_setting = getSuperAdminAllSetting();
            $sofort_secret_key = isset($payment_setting['sofort_secret_key']) ? $payment_setting['sofort_secret_key'] : '';
            $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
            $requests_data = $request->all();
            $stripe_formatted_price = in_array(
                $currency,
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
            ) ? number_format($amount, 2, '.', '') : number_format($amount, 2, '.', '') * 100;

            $return_url_parameters = function ($return_type) {
                return '&return_type=' . $return_type . '&payment_processor=stripe';
            };
            Stripe\Stripe::setApiKey($sofort_secret_key);

            $data = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['sofort'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $request->name,
                        ],
                        'unit_amount' => $stripe_formatted_price,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('promote.success'),
                'cancel_url' => route('promote.success'),
            ]);

            if (is_array($request)) {
                $request = Request::create('/', 'POST', $request);
            }
            Session::put('campaigns_request_data', $requests_data);
            $data = $data ?? false;
            try {
                return $data->url;
            } catch (\Exception $e) {
                return redirect()->route('campaigns.index')->with('error', __('Transaction has been failed!'));
            }
        } catch (\Throwable $th) {
        }
    }
    private function processESewa($request)
    {
        try {
            $amount = $request->total_cost;
            $payment_setting = getSuperAdminAllSetting();
            $esewa_merchant_key = isset($payment_setting['esewa_merchant_key']) ? $payment_setting['esewa_merchant_key'] : '';
            $esewa_mode = isset($payment_setting['esewa_mode']) ? $payment_setting['esewa_mode'] : '';
            $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
            $requests_data = $request->all();
            $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
            $user = \Auth::user();
            $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $amount)));
            if ($totalprice > 0.0) {
                config([
                    'esewa.scd' => isset($esewa_merchant_key) ? $esewa_merchant_key : '',
                    'esewa.env' => ucfirst(isset($esewa_mode) ? $esewa_mode : 'Sandbox'),
                ]);
                $payment    = new eSewa();
                $successURL = route('promote.success');
                $faildURL   = route('campaigns.index');
                $pay        =  $payment->esewaCheckout($amount, 0, 0, 0, $orderID, $successURL, $faildURL);
                Session::put('campaigns_request_data', $requests_data);
                try {
                    return redirect()->away($pay);
                } catch (\Exception $e) {
                    return redirect()->route('campaigns.index')->with('error', __('Transaction has been failed!'));
                }
            }
            return response()->json(['message' => 'Payment processed using Stripe']);
        } catch (\Throwable $th) {
        }
    }

    public function processMyfatoorah($request)
    {

        $amount = $request->total_cost;
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $user = \Auth::user();
        $orderId =  date('YmdHis');
        $requests_data = $request->all();
        Session::put('campaigns_request_data', $requests_data);
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $amount)));

        try {
            $allowedCurrencies = ['SAR', 'QAR', 'OMR', 'KWD', 'BHD', 'JOD', 'AED', 'USD'];

            if (!in_array($currency, $allowedCurrencies)) {
                return redirect()->back()->with('error', __('Selected currency is not supported for MyFatoorah payment.'));
            }

            $mfConfig = [
                'apiKey' => isset($payment_setting['myfatoorah_pay_api_key']) ? $payment_setting['myfatoorah_pay_api_key'] : '',
                'isTest' => isset($payment_setting['myfatoorah_mode']) && $payment_setting['myfatoorah_mode']  == 'sandbox'  ? true : false,
                'countryCode' => isset($payment_setting['myfatoorah_pay_country_iso']) ? $payment_setting['myfatoorah_pay_country_iso'] : '',
            ];

            //For example: pmid=0 for MyFatoorah invoice or pmid=1 for Knet in test mode
            $paymentId = request('pmid') ?: 0;
            $sessionId = request('sid') ?: null;
            $requests_data = $request->all();
            $callbackURL = route('promote.success');

            $curlData = [
                'CustomerName' => $user->name,
                'InvoiceValue' => $amount,
                'DisplayCurrencyIso' => $currency,
                'CustomerEmail' => $user->email,
                'CallBackUrl' => $callbackURL,
                'ErrorUrl' => $callbackURL,
                'MobileCountryCode' => '+965',
                'CustomerMobile' =>  $user->mobile,
                'Language' => 'en',
                'Reference' => $orderId,
                'SourceInfo' => 'Laravel ' . app()::VERSION . ' - MyFatoorah Package ' . MYFATOORAH_LARAVEL_PACKAGE_VERSION
            ];

            $mfObj = new \MyFatoorah\Library\API\Payment\MyFatoorahPayment($mfConfig);
            $payment = $mfObj->getInvoiceURL($curlData, $paymentId, $orderId, $sessionId);

            $request->session()->put('MyFatoorahSession');

            return redirect($payment['invoiceURL']);
        } catch (\Exception $ex) {
            $exMessage = __('myfatoorah.' . $ex->getMessage());
            return redirect()->back()->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }

    public function processDPO($request)
    {
        $amount = $request->total_cost;
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $requests_data = $request->all();
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $amount)));
        Session::put('campaigns_request_data', $requests_data);
        $user = \Auth::user();

        try {

            $user = [
                'name' => $user->name,
                'email' => $user->email,
                'contact_number' => $user->mobile,
            ];

            return redirect()->route('campaigns.dpo.view', ['total_price' => $totalprice, 'requests_data' => $requests_data, 'user' => $user, 'action' => route('campaigns.pay.with.dpo')]);
        } catch (\Exception $ex) {
            $exMessage = __('DPO.' . $ex->getMessage());

            return redirect()->route('campaigns.index')->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }

    public function processBraintree($request)
    {

        $amount = $request->total_cost;
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $user = \Auth::user();
        $orderId =  date('YmdHis');
        $requests_data = $request->all();
        Session::put('campaigns_request_data', $requests_data);
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $amount)));

        try {
            $BraintreeSession = [
                'amount' => $amount,
            ];
            $request->session()->put('\'' . $orderId . '\'', $BraintreeSession);

            $config = new \Braintree\Configuration([

                'environment' => isset($payment_setting['braintree_mode']) ? $payment_setting['braintree_mode'] : 'sandbox',
                'merchantId' => isset($payment_setting['braintree_pay_merchant_id']) ? $payment_setting['braintree_pay_merchant_id'] : '',
                'publicKey' => isset($payment_setting['braintree_pay_public_key']) ? $payment_setting['braintree_pay_public_key'] : '',
                'privateKey' => isset($payment_setting['braintree_pay_private_key']) ? $payment_setting['braintree_pay_private_key'] : '',
            ]);
            $gateway = new \Braintree\Gateway($config);
            $clientToken = $gateway->clientToken()->generate();

            if (!empty($clientToken)) {
                $url = route('campaigns.braintree', [
                    'token' => $clientToken,
                    'orderID' => $orderId,
                    'action' => route('promote.success'),
                    'return_url' => route('campaigns.index'),
                    'user' => ' ',
                    'amount' => $currency . ' ' . $amount,
                ]);

                return redirect()->away($url);
            } else {
                return redirect()->route('campaigns.index')->with('error', __('Transaction failed'));
            }
        } catch (\Exception $ex) {
            $exMessage = __('braintree.' . $ex->getMessage());
            return redirect()->route('campaigns.index')->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }

    public function processPowerTranz($request)
    {
        try {
            $requests_data = $request->all();
            Session::put('campaigns_request_data', $requests_data);
            return view('campaigns::payment.Powertranz', compact('requests_data'));
        } catch (\Exception $ex) {
            $exMessage = __('Powertranz.' . $ex->getMessage());
            return redirect()->route('campaigns.index')->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }

    public function processSSLCommerz($request)
    {
        try {
            $requests_data = $request->all();
            Session::put('campaigns_request_data', $requests_data);
            return view('campaigns::payment.SSLCommerz', compact('requests_data'));
        } catch (\Exception $ex) {
            $exMessage = __('SSLCommerz.' . $ex->getMessage());
            return redirect()->route('campaigns.index')->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }
}
