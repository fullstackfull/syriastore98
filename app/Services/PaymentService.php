<?php

namespace App\Services;

use App\Events\AddRewardClubPoint;
use Illuminate\Http\Request;
use Stripe;
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
use App\Models\State;
use App\Models\ShippingMethod;
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
use Paytabscom\Laravel_paytabs\paypage;
use PaytmWallet;
use GuzzleHttp\Client;
use YooKassa\Client as YooKassaClient;
use App\Coingate\Coingate;
use App\Events\GetProductStatus;
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
use App\Facades\ModuleFacade as Module;
use App\Models\Senangpay;
use Easebuzz\Easebuzz;
use Dipesh79\LaravelEsewa\LaravelEsewa as eSewa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Stripe\Checkout\Session as StripeSession;
use Illuminate\Support\Facades\Cache;
use App\Traits\ApiResponser;


use Illuminate\Support\Facades\Log;

class PaymentService
{
    use ApiResponser;

    /*
     * THE SHARED BASKET-OWNERSHIP SEAM, P01-COMMERCE-SEC-03 FOLLOW-UP.
     *
     * Used for basketHasForeignProduct() only -- the same predicate the seven checkout writers
     * in app/Http/Controllers/ run, rather than a ninth copy of it here. The trait's members do
     * not collide with anything in this class (verified member by member: this class's own
     * payment verifier is paymentVerifier(), the trait's is gatewayReturnVerifier()), and the
     * gateway-return helpers it also carries are simply unused on this path.
     */
    use \App\Http\Controllers\Concerns\GuardsGatewayReturn;

    /**
     * Server-authoritative tax resolution. See App\Services\OrderTaxResolver:
     * the tax component of a charged total is derived from the existing cart and
     * shipping-method computations and never read from client input.
     */
    private ?OrderTaxResolver $taxResolver = null;

    /** Exact-decimal reconciliation of a persisted total against its components. */
    private ?OrderTotalReconciler $totalReconciler = null;

    /** Derives an order's payment_status from a verification result. */
    private ?PaymentVerificationService $paymentVerifier = null;

    /** Atomic stock decrement, replacing the read-modify-write pattern. */
    private ?ProductStockService $stockService = null;

    /**
     * Lazily resolved rather than constructor-injected: this service is both
     * container-resolved and `new`-ed at several call sites, and neither
     * collaborator has dependencies of its own.
     */
    private function taxResolver(): OrderTaxResolver
    {
        return $this->taxResolver ??= new OrderTaxResolver();
    }

    private function totalReconciler(): OrderTotalReconciler
    {
        return $this->totalReconciler ??= new OrderTotalReconciler();
    }

    private function paymentVerifier(): PaymentVerificationService
    {
        return $this->paymentVerifier ??= new PaymentVerificationService();
    }

    private function stockService(): ProductStockService
    {
        return $this->stockService ??= new ProductStockService();
    }

    /**
     * Decrement stock atomically, remembering it so it can be given back.
     *
     * @param  array  $applied  Accumulator of applied decrements, by reference.
     * @param  string $kind     'variant' or 'product'.
     * @param  mixed  $model    The loaded ProductVariant or Product.
     * @param  mixed  $qty      The cart quantity.
     * @return array|null  null on success; a `['message' => ...]` payload when
     *                     the caller must abandon the order. Any decrements
     *                     already applied in this cart are given back first.
     */
    private function decrementStockOrFail(
        array &$applied,
        string $kind,
        $model,
        $qty,
        bool $rejectOnShortfall = true
    ): ?array {
        $result = $kind === 'variant'
            ? $this->stockService()->decrementVariantStock($model, $qty)
            : $this->stockService()->decrementProductStock($model, $qty);

        if ($result['applied']) {
            $applied[] = [
                'kind' => $kind,
                'id' => $model->id,
                'quantity' => $result['quantity'],
            ];

            return null;
        }

        if ($result['reason'] !== ProductStockService::INSUFFICIENT) {
            // Nothing to decrement (no quantity, or no row). Previously this
            // subtracted zero and saved, so carrying on matches the old
            // behaviour exactly.
            return null;
        }

        // PAST THE POINT OF NO RETURN.
        //
        // $rejectOnShortfall is false in every writer that has already done
        // irreversible work by the time this line runs. In those methods the cart
        // rows of the lines processed BEFORE this one have already been deleted,
        // `new Order()` has not been reached yet, and no transaction wraps any of
        // it -- and on the payment-return paths the gateway has already captured
        // the money. Returning an error there abandoned the checkout with no order
        // row, a partly destroyed cart and nothing refunded.
        //
        // The pre-existing code had no availability test at all on these paths: it
        // simply subtracted and saved, so stock could go negative. Overselling and
        // reporting it is therefore the SAME outcome the platform always had, and
        // strictly better than losing the order. The shortfall is a merchant
        // operations problem, not a reason to discard a paid basket.
        if (!$rejectOnShortfall) {
            $forced = $kind === 'variant'
                ? $this->stockService()->decrementVariantStock($model, $qty, true)
                : $this->stockService()->decrementProductStock($model, $qty, true);

            if ($forced['applied']) {
                $applied[] = [
                    'kind' => $kind,
                    'id' => $model->id,
                    'quantity' => $forced['quantity'],
                ];
            }

            Log::critical('Insufficient stock on an order path that cannot refuse; order kept, stock oversold', [
                'kind' => $kind,
                'model_id' => $model->id ?? null,
                'requested_quantity' => $qty,
                'outcome' => $forced['reason'] ?? null,
            ]);

            return null;
        }

        $this->restoreStockDecrements($applied);

        return ['message' => __('Insufficient stock. Please review your cart and try again.')];
    }

    /** Give back every decrement recorded by {@see decrementStockOrFail()}. */
    private function restoreStockDecrements(array $applied): void
    {
        foreach ($applied as $change) {
            if (($change['kind'] ?? null) === 'variant') {
                $this->stockService()->restoreVariantStock($change['id'], $change['quantity']);
            } else {
                $this->stockService()->restoreProductStock($change['id'], $change['quantity']);
            }
        }
    }

    /**
     * The server-side deposit amount for a PartialPayments order.
     *
     * The module's own ManageDeposit() derives the deposit from Product rows and
     * the store's deposit settings; it is called here with the SERVER-derived
     * components merged in, so neither the deposit nor its inputs come from the
     * request. Nothing inside packages/workdo is modified.
     *
     * @param  array $serverComponents Server-derived component amounts.
     * @param  mixed $fallbackTotal    Server total to fall back to. Never client input.
     * @return mixed
     */
    private function resolvePartialPaymentDeposit(
        $request,
        $slug,
        array $serverComponents,
        $fallbackTotal
    ) {
        $request->merge($serverComponents);

        try {
            $updated = \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::ManageDeposit(
                $request,
                $slug
            );
        } catch (\Throwable $e) {
            Log::error('PartialPayments deposit recomputation failed; using the server total', [
                'site' => __METHOD__,
                'error' => $e->getMessage(),
            ]);

            return $fallbackTotal;
        }

        return is_array($updated) && isset($updated['deposite_amount'])
            && is_numeric($updated['deposite_amount'])
                ? $updated['deposite_amount']
                : $fallbackTotal;
    }

    public function paymentConfig()
    {
        if (auth()->guard('customers')->check()) {
            $payment_setting = getAdminAllSetting();
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

    public function process($request, $paymentMethod, $slug, $cartList)
    {
        switch ($paymentMethod) {
            case 'stripe':
                return $this->processStripe($request, $slug, $cartList);
            case 'paymera':
                return $this->processPaymera($request, $slug, $cartList); 
            case 'paypal':
                return $this->processPaypal($request, $slug, $cartList);
            case 'paystack':
                return $this->processPaystack($request, $slug, $cartList);
            case 'Razorpay':
                return $this->processRazorpay($request, $slug, $cartList);
            case 'mercado':
                return $this->processMercado($request, $slug, $cartList);
            case 'skrill':
                return $this->processSkrill($request, $slug, $cartList);
            case 'paymentwall':
                return $this->processPaymentWall($request, $slug, $cartList);
            case 'flutterwave':
                return $this->processFlutterWave($request, $slug, $cartList);
            case 'paytm':
                return $this->processPayTm($request, $slug, $cartList);
            case 'mollie':
                return $this->processMollie($request, $slug, $cartList);
            case 'coingate':
                return $this->processCoingate($request, $slug, $cartList);
            case 'Sspay':
                return $this->processSspay($request, $slug, $cartList);
            case 'toyyibpay':
                return $this->processToyyibPay($request, $slug, $cartList);
            case 'Paytabs':
                return $this->processPayTabs($request, $slug, $cartList);
            case 'iyzipay':
                return $this->processIyziPay($request, $slug, $cartList);
            case 'payfast':
                return $this->processPayFast($request, $slug, $cartList);
            case 'benefit':
                return $this->processBenefit($request, $slug, $cartList);
            case 'cashfree':
                return $this->processCashFree($request, $slug, $cartList);
            case 'aamarpay':
                return $this->processAamarPay($request, $slug, $cartList);
            case 'telegram':
                return $this->processTelegram($request, $slug, $cartList);
            case 'paytr':
                return $this->processPayTr($request, $slug, $cartList);
            case 'yookassa':
                return $this->processYookassa($request, $slug, $cartList);
            case 'Xendit':
                return $this->processXendit($request, $slug, $cartList);
            case 'midtrans':
                return $this->processMidtrans($request, $slug, $cartList);
            case 'cod':
                return $this->getProductStatus($request, $slug, $cartList);
            case 'bank_transfer':
                return $this->getProductStatus($request, $slug, $cartList);
            case 'whatsapp':
                return $this->processWhatsapp($request, $slug, $cartList);
            case 'Nepalste':
                return $this->processNepalste($request, $slug, $cartList);
            case 'khalti':
                return $this->processKhalti($request, $slug, $cartList);
            case 'PayHere':
                return $this->processPayHere($request, $slug, $cartList);
            case 'AuthorizeNet':
                return $this->processAuthorizeNet($request, $slug, $cartList);
            case 'Tap':
                return $this->processTap($request, $slug, $cartList);
            case 'PhonePe':
                return $this->processPhonePe($request, $slug, $cartList);
            case 'Paddle':
                return $this->processPaddle($request, $slug, $cartList);
            case 'Paiementpro':
                return $this->processPaiementpro($request, $slug, $cartList);
            case 'FedPay':
                return $this->processFedPay($request, $slug, $cartList);
            case 'CinetPay':
                return $this->processCinetPay($request, $slug, $cartList);
            case 'SenagePay':
                return $this->processSenagePay($request, $slug, $cartList);
            case 'CyberSource':
                return $this->processCyberSource($request, $slug, $cartList);
            case 'Ozow':
                return $this->processOzow($request, $slug, $cartList);
            case 'MyFatoorah':
                return $this->processMyfatoorah($request, $slug, $cartList);
            case 'easebuzz':
                return $this->processEasebuzz($request, $slug, $cartList);
            case 'NMI':
                return $this->getNMIProductview($request, $slug, $cartList);
            case 'payu':
                return $this->processPayU($request, $slug, $cartList);
            case 'sofort':
                return $this->processSofort($request, $slug, $cartList);
            case 'esewa':
                return $this->processESewa($request, $slug, $cartList);
            case 'Paynow':
                return $this->processPaynow($request, $slug, $cartList);
            case 'DPO':
                return $this->processDPO($request, $slug, $cartList);
            case 'Braintree':
                return $this->processBraintree($request, $slug, $cartList);
            case 'PowerTranz':
                return $this->processPowerTranz($request, $slug, $cartList);
            case 'SSLCommerz':
                return $this->processSSLCommerz($request, $slug, $cartList);
            default:
                return response()->json(['error' => 'Invalid payment method'], 422);
        }
    }






private function processPaymera($request, $slug, $cartList)
{
    $store = Store::where('slug', $slug)->first();
    $terminal_id = \App\Models\Utility::GetValueByName('PAYMERA_TERMINAL_ID', $store->id);
    $username    = \App\Models\Utility::GetValueByName('PAYMERA_USERNAME', $store->id);
    $CURRENCY    = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

    $cartlist_final_price = $request['cartlist_final_price'];
    $raw_price = str_replace([' ', ',', '.'], '', str_replace($CURRENCY, '', $cartlist_final_price));
    $totalprice = (int) $raw_price;

    if ($totalprice > 0) {
        try {
            $api_url = 'https://egate-t.paymera.cc/api/create-payment';
            $base = 'https://storesyria.com';
            $orderId = uniqid('paymera_'); // معرّف مؤقت للدفعة، مش Order id حقيقي بعد

            $payload = [
                'lang'        => $request->input('lang', 'ar'),
                'terminalId'  => $terminal_id,
                'amount'      => $totalprice,
                'callbackURL' => $base . '/store/paymera/callback/' . $orderId,
                'triggerURL'  => $base . '/store/paymera/trigger/' . $orderId,
                'notes'       => 'Store Purchase - ' . $store->name,
            ];

            $paymeraResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $username,
                'Accept'        => 'application/json',
            ])->asJson()->post($api_url, $payload);

            $responseData = $paymeraResponse->json();

            if (isset($responseData['ErrorCode']) && $responseData['ErrorCode'] == 0) {

                // ⭐ خزّن بيانات الطلب + الدفعة المعلّقة، مشان الـ callback/trigger يقدروا يكملوا
                if (is_array($request)) {
                    $request = Request::create('/', 'POST', $request);
                }
                $requestData = $request->except('attachment', 'payment_receipt');
$requestData['payment_type'] = 'paymera';
/*
 * WAS the literal 'Paid', and this one is set at payment INITIATION -- before
 * the shopper has even been redirected to Paymera, let alone paid. The pending
 * record stored immediately below says `'status' => 'P'` (Pending) about the
 * very same payment, so the two disagreed inside one array literal.
 *
 * SAFE TO CORRECT, verified rather than assumed: nothing reads this key.
 * `grep -rn "requests_data\['payment_status'\]\|request_data\['payment_status'\]"`
 * over app/, packages/, resources/ and themes/ returns no reader, and the order
 * writers derive payment_status through
 * PaymentVerificationService::resolveOrderPaymentStatus() instead
 * (PaymentController:1948, PaymentService:2539). So this value reached no
 * decision -- it was a false statement sitting in the session waiting for a
 * future reader to trust it.
 *
 * It is set to the honest value rather than deleted, so a future reader finds
 * the truth instead of nothing.
 */
$requestData['payment_status'] = PaymentVerificationService::STATUS_PENDING;
$requestData['slug'] = $slug;
$requestData['store_id'] = $store->id;
                Session::put('request_data', $requestData);

                Cache::put('paymera_pending_' . $orderId, [
                    'payment_id' => $responseData['Data']['paymentId'] ?? null,
                    'slug'       => $slug,
                    'request_data' => $requestData,
                    'status'     => 'P', // Pending
                    // The amount this payment is FOR, recorded server-side at
                    // initiation so the callback can compare what Paymera says
                    // it captured against what was asked for. Without this the
                    // callback had nothing to compare against at all.
                    //
                    // Two forms are stored because the unit Paymera expects is
                    // unresolved (GATE G(3)): $raw_price above strips '.', so
                    // 1250.50 is sent as 125050 while 1250 is sent as 1250.
                    // 'expected_total' is the decimal the order is for;
                    // 'amount_sent' is the exact integer handed to the gateway.
                    'expected_total' => str_replace(
                        [' ', ','],
                        '',
                        str_replace($CURRENCY, '', (string) $cartlist_final_price)
                    ),
                    'amount_sent' => $totalprice,
                ], now()->addHours(2));

                return $responseData['Data']['url'];
            }

            throw new \Exception('Paymera API Error: ' . ($responseData['ErrorMessage'] ?? 'Unknown Error'));

        } catch (\Throwable $e) {
            \Log::error('PAYMERA DEBUG - Final Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Paymera Error: ' . $e->getMessage());
        }
    }

    return redirect()->route('checkout', $slug)->with('error', __('Invalid amount.'));
}






private function processPaymera00000($request, $slug)
{
    $store = Store::where('slug', $slug)->first();
    $terminal_id = \App\Models\Utility::GetValueByName('PAYMERA_TERMINAL_ID', $store->id);
    $username    = \App\Models\Utility::GetValueByName('PAYMERA_USERNAME', $store->id);
    $CURRENCY    = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

    // تنظيف السعر
    $cartlist_final_price = $request['cartlist_final_price'];
    $raw_price = str_replace([' ', ',', '.'], '', str_replace($CURRENCY, '', $cartlist_final_price));
    $totalprice = (int) $raw_price;

    if ($totalprice > 0) {
        try {
            $api_url = 'https://egate-t.paymera.cc/api/create-payment'; 
            $base = 'https://lynomia.com'; 
            $orderId = $request->order_id ?? uniqid('paymera_');

            $payload = [
                'lang'        => $request->input('lang', 'ar'),
                'terminalId'  => $terminal_id,
                'amount'      => $totalprice,
                'callbackURL' => $base . '/store/paymera/callback/' . $orderId,
                'triggerURL'  => $base . '/store/paymera/trigger/' . $orderId,
                'notes'       => 'Store Purchase - ' . $store->name,
            ];

            \Log::info('PAYMERA DEBUG - Final Payload:', $payload);

            // إضافة الهيدر هنا
            $paymeraResponse = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $username,
                'Accept'        => 'application/json',
            ])->asJson()->post($api_url, $payload);

            $responseData = $paymeraResponse->json();

            // التحقق من النجاح
            if (isset($responseData['ErrorCode']) && $responseData['ErrorCode'] == 0) {
                return $responseData['Data']['url'];
            }
            
            throw new \Exception('Paymera API Error: ' . ($responseData['ErrorMessage'] ?? 'Unknown Error'));

        } catch (\Throwable $e) {
            \Log::error('PAYMERA DEBUG - Final Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Paymera Error: ' . $e->getMessage());
        }
    }
    
    return redirect()->route('checkout', $slug)->with('error', __('Invalid amount.'));
}


    private function processStripe($request, $slug, $response)
    {
        $slug = $request['slug'];
        $store = Store::where('slug', $request['slug'])->first();
        $stripe_secret = \App\Models\Utility::GetValueByName('stripe_secret_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request['customer_id'] . date('YmdHis');
        $cartlist_final_price = $request['cartlist_final_price'];
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));

        if ($totalprice > 0.0) {
            $l_name = $store->slug;
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
                                'unit_amount' => (int) $stripe_formatted_price,
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
                        'store.payment.status',
                        [
                            $slug,
                            $return_url_parameters('success'),
                        ]
                    ),
                    'cancel_url' => route(
                        'store.payment.status',
                        [
                            $slug,
                            $return_url_parameters('cancel'),
                        ]
                    ),
                ]

            );
            if (is_array($request)) {
                // Convert the array to an Illuminate\Http\Request object
                $request = Request::create('/', 'POST', $request);
            }
            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
            $data = $data ?? false;
            try {
                $place_order_data = ($data);
                return $place_order_data->url;
            } catch (\Exception $e) {
                return redirect()->route('checkout', $slug)->with('error', __('Transaction has been failed!'));
            }
        }
        return response()->json(['message' => 'Payment processed using Stripe']);
    }

    private function processPaystack($request, $slug, $response)
    {
        $data = $request->all();
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        $store = Store::where('slug', $request->slug)->first();
        $admin_payment_setting = getAdminAllSetting();
        return view('payment.paystack', compact('data', 'admin_payment_setting', 'store'));
    }

    private function processRazorpay($request, $slug, $response)
    {
        $data = $request->all();
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        $store = Store::where('slug', $request->slug)->first();
        $admin_payment_setting = getAdminAllSetting();
        return view('payment.razorpay', compact('data', 'admin_payment_setting', 'store'));
    }

    private function processMercado($request, $slug, $response)
    {
        // Get the store details
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);

        

        // Retrieve the billing info from request
        $billing_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        // Retrieve Mercado Pago settings and other configurations
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);
        $mercado_mode = \App\Models\Utility::GetValueByName('mercado_mode', $store->id);
        $mercado_access_token = \App\Models\Utility::GetValueByName('mercado_access_token', $store->id);

        // Generate unique order ID based on customer_id and current date-time
        $orderID = $request->customer_id . date('YmdHis');

        // Clean the cart total price (remove any currency symbols and commas)
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $request->cartlist_final_price)));

        // Prepare the payment data for Mercado Pago API request
        $success_url = route('store.payment.status', ['flag' => 'success', $slug,]);
        $failure_url = route('checkout', [$slug, 'flag' => 'failure',]);
        $pending_url = route('checkout', [$slug, 'flag' => 'pending',]);
        try {


            $paymentData = [
                'items' => [
                    [
                        'title' => "E-com_Product",
                        'quantity' => 1,
                        'unit_price' => (float) $totalprice,
                        'currency_id' => $CURRENCY,
                    ],
                ],
                'back_urls' => [
                    'success' => $success_url,
                    'failure' => $failure_url,
                    'pending' => $pending_url,
                ],
                'auto_return' => 'approved',
                'payer' => [
                    'name' => "name",
                    'surname' => '',
                    'email' => "email@gmail.com",
                    'address' => [
                        'street_name' => '',
                        'street_number' => '',
                        'zip_code' => '',
                    ],
                    'identification' => [
                        'type' => 'CPF',
                        'number' => '19119119100',
                    ],
                ],
            ];
            $response = \Http::withToken($mercado_access_token)->post('https://api.mercadopago.com/checkout/preferences', $paymentData);

            // \Log::info('Mercado Pago Response: ' . $response->body());
            // Check if the API response is successful
            if ($response->successful() || $response->statusCode() === 201) {
                // Use the correct URL based on mode (live or sandbox)
                $initPoint = $mercado_mode === 'live' ? $response->json()['init_point'] : $response->json()['sandbox_init_point'];
                return $initPoint;


            } else {
                // Handle errors based on response code
                if (!empty($response->json()['code']) && $response->json()['code'] === 'unauthorized') {
                    return redirect()->route('checkout', $slug)->with('error', __('Invalid access token'));
                } else if (!empty($response->json()['status']) && $response->json()['status'] == 400) {
                    return redirect()->route('checkout', $slug)->with('error', __('Currency not supported'));

                } else {

                    return redirect()->route('checkout', $slug)->with('error', __('Something went wrong'));

                }
            }

        } catch (\Exception $e) {
            // Catch any exceptions and return the error message
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    private function processSkrill($request, $slug, $response)
    {
        $payment = getAdminAllSetting();
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $skrill_email = \App\Models\Utility::GetValueByName('skrill_email', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request->customer_id . date('YmdHis');
        if (\Auth::check($slug)) {
            $customer_data = auth(guard: 'customers')->user();
            $pdata['phone'] = $request->billing_info["mobile"] ?? "";
            $pdata['email'] = $request->billing_info["email"] ?? "";
            $pdata['customer_id'] = $request->billing_info["id"] ?? "";
        } else {
            $pdata['phone'] = '';
            $pdata['email'] = '';
            $pdata['customer_id'] = '';
        }

        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));

        $skill = new SkrillRequest();
        $skill->pay_to_email = $skrill_email;
        $skill->return_url = route('store.payment.status', $store->slug);
        $skill->cancel_url = route('store.payment.status', $store->slug);

        // Create a unique transaction ID for Skrill
        $skill->transaction_id = md5(uniqid($request['transaction_id'], true));

        $skill->amount = $totalprice;
        $skill->currency = $CURRENCY_NAME;
        $skill->language = 'EN';
        $skill->prepare_only = '0';
        $skill->merchant_fields = 'site_name, customer_email';
        $skill->site_name = $store->name;
        $skill->customer_email = $pdata['email'];

        $client = new SkrillClient($skill);
        $sidResponse = $client->generateSID(); // HTML response

        // Extract the session ID from the HTML response
        preg_match('/url=([^"]+)/', $sidResponse, $matches);

        if (isset($matches[1])) {
            $redirectUrl = $matches[1];

            // Store session data for later use
            if ($request['transaction_id']) {
                $data = [
                    'amount' => $cartlist_final_price,
                    'trans_id' => md5($request['transaction_id']),
                    'currency' => 'USD',
                    'slug' => $store->slug,
                ];
                session()->put('skrill_data', $data);
            }

            // Redirect user to Skrill payment page
            return redirect()->away($redirectUrl);

        } else {
            // Handle the error if SID is not found in the response
            \Log::error('Skrill response did not contain a valid redirect URL. Response: ' . $sidResponse);
            return redirect()->back()->with('error', 'Failed to initiate Skrill payment. Please try again.');
        }

        // If it's needed to store additional request data
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);

        // If the CheckoutAttachment module is active, store the checkout attachment
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
    }


    private function processPaymentWall($request, $slug, $response)
    {
        $data = $request->all();
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        ;
        $store = Store::where('slug', $request->slug)->first();
        $admin_payment_setting = getAdminAllSetting();
        return view('payment.paymentwall', compact('data', 'admin_payment_setting', 'store'));
    }

    private function processPayPal($request, $slug, $response)
    {
        $payment = getAdminAllSetting();

        $slug = !empty($request->slug) ? $request->slug : '';

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
        $orderID = $request->user_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));

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
                            "value" => $request->cartlist_final_price
                        ]
                    ]
                ],
                "application_context" => [
                    "return_url" => route('store.payment.status', $slug),
                    "cancel_url" => route('store.payment.status', $slug),
                ],

            ]);

            if (isset($response['id']) && $response['id'] != null) {
                // redirect to approve href
                if (is_array($request)) {
                    // Convert the array to an Illuminate\Http\Request object
                    $request = Request::create('/', 'POST', $request);
                }
                $requestData = $request->except('attachment','payment_receipt');
                Session::put('request_data', $requestData);
                if (module_is_active('CheckoutAttachment')) {
                    \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
                }
                foreach ($response['links'] as $links) {
                    if ($links['rel'] == 'approve') {
                        return $links['href'];
                    }
                }
                return $response;

                return redirect()
                    ->route('checkout', $slug)
                    ->with('error', 'Something went wrong.');
            } else {
                return redirect()
                    ->route('checkout', $slug)
                    ->with('error', $response['message'] ?? 'Something went wrong.');
            }
        } catch (\Exception $e) {
            return redirect()->route('checkout', $slug)->with('error', __('Unknown error occurred'));
        }
    }

    private function processFlutterWave($request, $slug, $response)
    {
        if (is_array($request->get('billing_info'))) {
            $email = $request->get('billing_info')['email'];
        } else {
            $email = json_decode($request->get('billing_info'), true)['email'];
        }

        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);

        $flutterwave_secret = \App\Models\Utility::GetValueByName('flutterwave_secret_key', $store->id);
        $public_key = \App\Models\Utility::GetValueByName('flutterwave_public_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));

        $res_data['total_price'] = $cartlist_final_price;
        $res_data['currency'] = $CURRENCY_NAME;
        $res_data['flag'] = 1;
        $res_data['public_key'] = $public_key;
        $res_data['store'] = $store->slug;
        $res_data['orderID'] = $orderID;
        $res_data['email'] = $email;

        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        return view('payment.flutterwave', compact('res_data'));

    }

    private function processPayTm($request, $slug, $response)
    {
        if (is_array($request->get('billing_info'))) {
            $email = $request->get('billing_info')['email'];
            $firstname = $request->get('billing_info')['firstname'];
            $billing_user_telephone = $request->get('billing_info')['billing_user_telephone'];
        } else {
            $email = json_decode($request->get('billing_info'), true)['email'];
            $firstname = json_decode($request->get('billing_info'), true)['firstname'];
            $billing_user_telephone = json_decode($request->get('billing_info'), true)['billing_user_telephone'];
        }

        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);

        $paytm_mode = \App\Models\Utility::GetValueByName('paytm_mode', $store->id);
        $paytm_merchant_id = \App\Models\Utility::GetValueByName('paytm_merchant_id', $store->id);
        $paytm_merchant_key = \App\Models\Utility::GetValueByName('paytm_merchant_key', $store->id);
        $paytm_industry_type = \App\Models\Utility::GetValueByName('paytm_industry_type', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $cutomer = Customer::where('type', 'customer')->where('store_id', $store->id)->get()->count();
        $user = $cutomer + 1;

        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $cartlist_final_price = $request->cartlist_final_price;
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
                    'user' => $firstname,
                    'mobile_number' => $billing_user_telephone,
                    'email' => $email,
                    'amount' => $cartlist_final_price,
                    'callback_url' => route('store.payment.status', $slug),

                ]
            );
            if (is_array($request)) {
                // Convert the array to an Illuminate\Http\Request object
                $request = Request::create('/', 'POST', $request);
            }
            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
            return $payment->receive();
        } catch (\Exception $e) {
            return redirect()->route('checkout', $slug)->with('error', __($e->getMessage()));
        }
    }

    private function processMollie(Request $request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);

        $mollie_api_key = \App\Models\Utility::GetValueByName('mollie_api_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        $request = $request->all();
        $mollie = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($mollie_api_key);
        $payment = $mollie->payments->create(
            [
                "amount" => [
                    "currency" => "$CURRENCY_NAME",
                    "value" => str_replace(",", "", number_format($cartlist_final_price, 2)),
                ],
                "description" => "payment for product",
                "redirectUrl" => route(
                    'store.payment.status',
                    [
                        $store->slug,
                    ]
                ),

            ]
        );
        session()->put('mollie_payment_id', $payment->id);

        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }

        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);

        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        return $payment->getCheckoutUrl();

    }

    private function processCoingate($request, $slug, $response)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
       
        $coingate_mode = \App\Models\Utility::GetValueByName('coingate_mode', $store->id);
        $coingate_auth_token = \App\Models\Utility::GetValueByName('coingate_auth_token', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request->user_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
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
                'price_amount' => $cartlist_final_price,
                'price_currency' => $CURRENCY_NAME,
                'receive_currency' => $CURRENCY_NAME,

                'callback_url' => route(
                    'store.payment.status',
                    [
                        $store->slug,
                    ]
                ),
                'cancel_url' => route(
                    'store.payment.status',
                    [
                        $store->slug,
                    ]
                ),

                'success_url' => route(
                    'store.payment.status',
                    [
                        $store->slug,
                    ]
                ),
                'title' => 'Order #' . time(),
            );

            if (is_array($request)) {
                // Convert the array to an Illuminate\Http\Request object
                $request = Request::create('/', 'POST', $request);
            }

            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
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

    private function processSspay($request, $slug, $response)
    {
        try {
            if (auth('customers')->user()) {
                $products = Product::find($request->cartlist['product_list'][0]->product_id);
            } else {
                $products = Product::find($request->product[0]['product_id']);
            }

            $slug = $request->slug;
            $store = getStore($slug);
            $sspay_secret_key = \App\Models\Utility::GetValueByName('sspay_secret_key', $store->id);
            $sspay_category_code = \App\Models\Utility::GetValueByName('sspay_category_code', $store->id);
            $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);
            if (is_array($request->get('billing_info'))) {
                $email = $request->get('billing_info')['email'];
                $firstname = $request->get('billing_info')['firstname'];
                $billing_user_telephone = $request->get('billing_info')['billing_user_telephone'];
            } else {
                $email = json_decode($request->get('billing_info'), true)['email'];
                $firstname = json_decode($request->get('billing_info'), true)['firstname'];
                $billing_user_telephone = json_decode($request->get('billing_info'), true)['billing_user_telephone'];
            }
            $cartlist_final_price = $request->cartlist_final_price;
            $billContentEmail = "Thank you for purchasing our product!";
            $Date = date('d-m-Y');
            $billExpiryDays = 3;
            $billExpiryDate = date('d-m-Y', strtotime($Date . ' + 3 days'));

            $this->callBackUrl = route('store.payment.status', $store->slug);
            $this->returnUrl = route('store.payment.status', $store->slug);
            $some_data = array(
                'userSecretKey' => $sspay_secret_key,
                'categoryCode' => $sspay_category_code,
                'billName' => $products->name,
                'billDescription' => $products->description,
                'billPriceSetting' => 1,
                'billPayorInfo' => 1,
                'billAmount' => 100 * $cartlist_final_price,
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
            return redirect('https://sspay.my/' . $obj[0]->BillCode);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processToyyibPay($request, $slug, $response)
    {
        try {

            if (auth('customers')->user()) {
                $products = Product::find($request->cartlist['product_list'][0]->product_id);
            } else {
                $products = Product::find($request->product[0]['product_id']);
            }

            $slug = $request->slug;
            $store = getStore($slug);
            
            $toyyibpay_secret_key = \App\Models\Utility::GetValueByName('toyyibpay_secret_key', $store->id);
            $toyyibpay_category_code = \App\Models\Utility::GetValueByName('toyyibpay_category_code', $store->id);
            $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);
            if (is_array($request->get('billing_info'))) {
                $email = $request->get('billing_info')['email'];
                $firstname = $request->get('billing_info')['firstname'];
                $billing_user_telephone = $request->get('billing_info')['billing_user_telephone'];
            } else {
                $email = json_decode($request->get('billing_info'), true)['email'];
                $firstname = json_decode($request->get('billing_info'), true)['firstname'];
                $billing_user_telephone = json_decode($request->get('billing_info'), true)['billing_user_telephone'];
            }
            $cartlist_final_price = $request->cartlist_final_price;
            $billContentEmail = "Thank you for purchasing our product!";
            $Date = date('d-m-Y');
            $billExpiryDays = 3;
            $billExpiryDate = date('d-m-Y', strtotime($Date . ' + 3 days'));

            $this->callBackUrl = route('store.payment.status', $store->slug);
            $this->returnUrl = route('store.payment.status', $store->slug);
            $some_data = array(
                'userSecretKey' => $toyyibpay_secret_key,
                'categoryCode' => $toyyibpay_category_code,
                'billName' => $products->name,
                'billDescription' => 'Description',
                'billPriceSetting' => 1,
                'billPayorInfo' => 1,
                'billAmount' => 100 * $cartlist_final_price,
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
            if (is_array($request)) {
                // Convert the array to an Illuminate\Http\Request object
                $request = Request::create('/', 'POST', $request);
            }
            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
            return redirect('https://toyyibpay.com/' . $obj[0]->BillCode);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processPayTabs($request, $slug, $response)
    {
        if (empty($slug)) {
            $slug = $request->slug;
        }
        $store = getStore($slug);
        $paytabs_profile_id = \App\Models\Utility::GetValueByName('paytabs_profile_id', $store->id);
        $paytabs_server_key = \App\Models\Utility::GetValueByName('paytabs_server_key', $store->id);
        $paytabs_region = \App\Models\Utility::GetValueByName('paytabs_region', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        if ($CURRENCY_NAME != 'INR') {
            return redirect()->back()->with('error', 'Currency Not Supported.Contact To Your Site Admin');
        } else {

            config(
                [
                    'paytabs.profile_id' => $paytabs_profile_id,
                    'paytabs.server_key' => $paytabs_server_key,
                    'paytabs.region' => $paytabs_region,
                    'paytabs.currency' => $CURRENCY_NAME,
                ]
            );



            $cartlist_final_price = $request->cartlist_final_price;
            $user = \Auth::user();
            $cart_list = Cart::cart_list_cookie($request->all(), $store->id);
            $paytabs = new paypage();
            $pay = $paytabs->sendPaymentCode('all')
                ->sendTransaction('sale', 'ecom')
                ->sendCart(1, $cartlist_final_price, 'plan payment')
                ->sendCustomerDetails(isset($user->name) ? $user->name : "", isset($user->email) ? $user->email : '', '', '', '', '', '', '', '')
                ->sendURLs(
                    route('store.payment.status', $slug),
                    route('store.payment.status', $slug)
                )
                ->sendLanguage('en')
                ->sendFramed($on = false)
                ->create_pay_page();

            if (is_array($request)) {
                // Convert the array to an Illuminate\Http\Request object
                $request = Request::create('/', 'POST', $request);
            }

            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
            return $pay;
        }
    }

    private function processIyziPay($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';

        $store = getStore($slug);
        

        $iyzipay_mode = \App\Models\Utility::GetValueByName('iyzipay_mode', $store->id);
        $iyzipay_secret_key = \App\Models\Utility::GetValueByName('iyzipay_secret_key', $store->id);
        $iyzipay_private_key = \App\Models\Utility::GetValueByName('iyzipay_private_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $adminPaymentSettings = getAdminAllSetting();
        $currency = $adminPaymentSettings['CURRENCY_NAME'] ?? ($CURRENCY_NAME ?? '$');
        $cart_list = Cart::cart_list_cookie($request->all(), $store->id);
        $request_data = $request->except('attachment','payment_receipt');
        session(['cart_list' => $cart_list, 'request_data' => $request_data]);
        $objUser = \Auth::user();
        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));

        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        $address = !empty($other_info->billing_address) ? $other_info->billing_address : '';
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
        $request->setPrice($totalprice);
        $request->setPaidPrice($totalprice);
        $request->setCurrency($currency);
        $request->setCallbackUrl(route('store.payment.status', $slug));
        $request->setEnabledInstallments(array(1));
        $request->setPaymentGroup(\Iyzipay\Model\PaymentGroup::PRODUCT);
        $buyer = new \Iyzipay\Model\Buyer();
        $buyer->setId(!empty($objUser['id']) ? $objUser['id'] : '0');
        $buyer->setName(!empty($objUser['name']) ? $objUser['name'] : 'Guest');
        $buyer->setSurname(!empty($objUser['name']) ? $objUser['name'] : 'Guest');
        $buyer->setGsmNumber(!empty($objUser['mobile']) ? $objUser['mobile'] : '9999999999');
        $buyer->setEmail(!empty($objUser['email']) ? $objUser['email'] : 'test@gmail.com');
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
        $shippingAddress->setContactName(!empty($objUser['name']) ? $objUser['name'] : 'Guest');
        $shippingAddress->setCity($ipAddress['city']);
        $shippingAddress->setCountry($ipAddress['country']);
        $shippingAddress->setAddress($address);
        $shippingAddress->setZipCode($ipAddress['postal']);
        $request->setShippingAddress($shippingAddress);
        $billingAddress = new \Iyzipay\Model\Address();
        $billingAddress->setContactName(!empty($objUser['name']) ? $objUser['name'] : 'Guest');
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
        $firstBasketItem->setPrice($totalprice);
        $basketItems[0] = $firstBasketItem;
        $request->setBasketItems($basketItems);

        $checkoutFormInitialize = \Iyzipay\Model\CheckoutFormInitialize::create($request, $options);

        if ($checkoutFormInitialize->getpaymentPageUrl() != null) {
            return $checkoutFormInitialize->getpaymentPageUrl();
        } else {
            return redirect()->route('checkout', $slug)->with('error', 'Something went wrong, Please try again');
        }
        return $checkoutFormInitialize->getpaymentPageUrl();

    }

    private function processPayFast($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;


        $payfast_merchant_id = \App\Models\Utility::GetValueByName('payfast_merchant_id', $store->id);
        $payfast_salt_passphrase = \App\Models\Utility::GetValueByName('payfast_salt_passphrase', $store->id);
        $payfast_merchant_key = \App\Models\Utility::GetValueByName('payfast_merchant_key', $store->id);
        $payfast_mode = \App\Models\Utility::GetValueByName('payfast_mode', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);


        $order_id = $request['customer_id'] . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY_NAME, '', $cartlist_final_price)));
        $name = 'test';
        $order_id = time();
        $success = Crypt::encrypt([
            'order_id' => $order_id
        ]);
        $data = array(
            // Merchant details
            'merchant_id' => !empty($payfast_merchant_id) ? $payfast_merchant_id : '',
            'merchant_key' => !empty($payfast_merchant_key) ? $payfast_merchant_key : '',
            'return_url' => route('store.payment.status', $slug),
            'cancel_url' => route('store.payment.status', $slug),
            'notify_url' => route('store.payment.status', $slug),
            // Buyer details
            'name_first' => isset($other_info->firstname) ? $other_info->firstname : '',
            'name_last' => isset($other_info->lastname) ? $other_info->lastname : '',
            'email_address' => isset($other_info->email) ? $other_info->email : '',
            // Transaction details
            'm_payment_id' => $order_id,
            'amount' => $cartlist_final_price,
            'item_name' => 'test',
        );
        $passphrase = !empty($payfast_salt_passphrase) ? $payfast_salt_passphrase : '';
        $signature = $this->generateSignature($data, $passphrase);
        $data['signature'] = $signature;
        $checkouthtml = '';
        foreach ($data as $name => $value) {
            $checkouthtml .= '<input name="' . $name . '" type="hidden" value=\'' . $value . '\' />';
        }

        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        $msg = [
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

    private function processBenefit($request, $slug, $response)
    {

        $products = Product::find($request->product[0]['product_id']);

        $name = $products->name;
        $slug = !empty($request->slug) ? $request->slug : '';

        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        $benefit_secret_key = \App\Models\Utility::GetValueByName('benefit_secret_key', $store->id);
        $benefit_private_key = \App\Models\Utility::GetValueByName('benefit_private_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));
        $customerData =
            [
                "amount" => $cartlist_final_price,
                "currency" => !empty($CURRENCY_NAME) ? $CURRENCY_NAME : 'BHD',
                "customer_initiated" => true,
                "threeDSecure" => true,
                "save_card" => false,
                "description" => !empty($name) ? $name : '',
                "metadata" => ["udf1" => "Metadata 1"],
                "reference" => ["transaction" => "txn_01", "order" => "ord_01"],
                "receipt" => ["email" => true, "sms" => true],
                "customer" => ["first_name" => $other_info['firstname'], "middle_name" => "", "last_name" => $other_info['firstname'], "email" => $other_info['email'], "phone" => ["country_code" => 965, "number" => 51234567]],
                "source" => ["id" => "src_bh.benefit"],
                "post" => ["url" => "https://webhook.site/fd8b0712-d70a-4280-8d6f-9f14407b3bbd"],
                "redirect" => ["url" => route('store.payment.status', $slug)],

            ];
        $requestData = $request->except('attachment','payment_receipt');
        session(['request_data' => $requestData]);
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

    private function processCashFree($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';

        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        $cashfree_secret_key = \App\Models\Utility::GetValueByName('cashfree_secret_key', $store->id);
        $cashfree_key = \App\Models\Utility::GetValueByName('cashfree_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);
        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
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
            'order_amount' => round($cartlist_final_price),
            "order_currency" => !empty($CURRENCY_NAME) ? $CURRENCY_NAME : 'USD',
            "order_name" => $other_info->firstname ?? 'Test',
            "customer_details" => [
                    "customer_id" => 'customer_' . $request['customer_id'],
                    "customer_name" => $other_info->firstname ?? $other_info['firstname'],
                    "customer_email" => $other_info->email ?? $other_info['email'],
                    "customer_phone" => '1234567890',
                ],
            "order_meta" => [
                "return_url" => route('store.payment.status', $slug)
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
            return redirect()->route('checkout', $slug)->with('error', 'Currency Not Supported.Contact To Your Site Admin');
        }

    }

    private function processAamarPay($request, $slug, $response)
    {
        $url = 'https://sandbox.aamarpay.com/request.php';
        $slug = !empty($request->slug) ? $request->slug : '';

        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        $aamarpay_signature_key = \App\Models\Utility::GetValueByName('aamarpay_signature_key', $store->id);
        $aamarpay_description = \App\Models\Utility::GetValueByName('aamarpay_description', $store->id);
        $aamarpay_store_id = \App\Models\Utility::GetValueByName('aamarpay_store_id', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);
        $orderID = $request->user_id . date('YmdHis');
        $response = Cart::cart_list_cookie($request->all(), $store->id);
        $response = json_decode(json_encode($response));
        $cartlist = (array) $response->data;
        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));
        $requestData = $request->except('attachment','payment_receipt');
        session(['request_data' => $requestData]);
        try {

            $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
            $fields = array(
                'store_id' => $aamarpay_store_id,
                'amount' => $cartlist_final_price,
                'payment_type' => '',
                'currency' => $CURRENCY_NAME,
                'tran_id' => $orderID,
                'cus_name' => $other_info['firstname'],
                'cus_email' => $other_info['email'],
                'cus_add1' => '',
                'cus_add2' => '',
                'cus_city' => '',
                'cus_state' => '',
                'cus_postcode' => '',
                'cus_country' => '',
                'cus_phone' => '1234567890',
                'success_url' => route('store.payment.status', [
                            $slug,
                            'request_data' => $request->all(),
                            'cart_data' => $cartlist
                        ]),
                //your success route
                'fail_url' => route('store.payment.status', Crypt::encrypt(['response' => 'failure', 'slug' => $slug, 'price' => $cartlist_final_price, 'order_id' => $orderID])),
                //your fail route
                'cancel_url' => route('store.payment.status', Crypt::encrypt(['response' => 'cancel'])),
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
            return \App\Services\PaymentFailure::redirectBack($e, 'processAamarPay');
        }

    }

    function redirect_to_merchant($url)
    {

        $token = csrf_token();
        ?>
        <html xmlns="http://www.w3.org/1999/xhtml">

        <head>
            <script type="text/javascript">
                function closethisasap() { document.forms["redirectpost"].submit(); }
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

    private function processTelegram($request, $slug, $response)
    {
        $data = $request->all();
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        return view('payment.telegram', compact('data'));
    }

    private function processPayTr($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);

        

        $paytr_merchant_id = \App\Models\Utility::GetValueByName('paytr_merchant_id', $store->id);
        $paytr_merchant_key = \App\Models\Utility::GetValueByName('paytr_merchant_key', $store->id);
        $paytr_salt_key = \App\Models\Utility::GetValueByName('paytr_salt_key', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;


        try {

            $merchant_id = $paytr_merchant_id;
            $merchant_key = $paytr_merchant_key;
            $merchant_salt = $paytr_salt_key;
            $orderID = $request->user_id . date('YmdHis');


            $email = $other_info['email'];
            $payment_amount = $cartlist_final_price;
            $merchant_oid = $orderID;
            $user_name = $other_info['firstname'];
            $user_address = 'no address';
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
            $currency = isset($CURRENCY) ? $CURRENCY : 'USD';
            $payment_amount = $payment_amount * 100;
            $hash_str = $merchant_id . $user_ip . $merchant_oid . $email . $payment_amount . $user_basket . $no_installment . $max_installment . $currency . $test_mode;
            $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $merchant_salt, $merchant_key, true));

            $request['orderID'] = $orderID;
            $request['amount'] = $payment_amount;
            $request['payment_status'] = 'failed';
            $payment_failed = $request->all();
            $request['payment_status'] = 'success';
            $payment_success = $request->all();
            if (is_array($request)) {
                // Convert the array to an Illuminate\Http\Request object
                $request = Request::create('/', 'POST', $request);
            }
            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
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
                'merchant_ok_url' => route('store.payment.status', $slug, ),
                'merchant_fail_url' => route('store.payment.status', $slug),
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
            return view('payment.pay_tr', compact('token'));
        } catch (\Throwable $e) {
            return \App\Services\PaymentFailure::redirectBack($e, 'processPayTr');
        }

    }

    private function processYookassa($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);

        $yookassa_shop_id_key = \App\Models\Utility::GetValueByName('yookassa_shop_id_key', $store->id);
        $yookassa_secret_key = \App\Models\Utility::GetValueByName('yookassa_secret_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        try {
            if (is_int((int) $yookassa_shop_id_key)) {
                $client = new YooKassaClient();
                $client->setAuth((int) $yookassa_shop_id_key, $yookassa_secret_key);
                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
                $payment = $client->createPayment(
                    array(
                        'amount' => array(
                            'value' => $cartlist_final_price,
                            'currency' => !empty($CURRENCY_NAME) ? $CURRENCY_NAME : 'USD',
                        ),
                        'confirmation' => array(
                            'type' => 'redirect',
                            'return_url' => route('store.payment.status', $slug),
                        ),

                        'capture' => true,
                        'description' => 'Заказ №1',
                    ),
                    uniqid('', true)
                );


                $type = 'Subscription';

                Session::put('payment_id', $payment['id']);
                if (is_array($request)) {
                    // Convert the array to an Illuminate\Http\Request object
                    $request = Request::create('/', 'POST', $request);
                }
                $requestData = $request->except('attachment','payment_receipt');
                Session::put('request_data', $requestData);
                if (module_is_active('CheckoutAttachment')) {
                    \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
                }
                if ($payment['confirmation']['confirmation_url'] != null) {
                    return $payment['confirmation']['confirmation_url'];
                } else {
                    return redirect()->route('checkout', $slug)->with('error', 'Something went wrong, Please try again');
                }
            } else {
                return redirect()->back()->with('error', 'Please Enter  Valid Shop Id Key');
            }

        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Incorrect currency of payment.'));
        }

    }

    private function processXendit($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);

        

        $xendit_api = \App\Models\Utility::GetValueByName('Xendit_api_key', $store->id);
        $Xendit_token_key = \App\Models\Utility::GetValueByName('Xendit_token_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        Xendit::setApiKey($xendit_api);

        $params = [
            'external_id' => $orderID,
            'description' => 'Payment for order ' . $orderID,
            'amount' => $cartlist_final_price,
            'callback_url' => route('store.payment.status', $slug),
            'success_redirect_url' => route('store.payment.status', [$slug, 'request_data' => $request->all()]),
            'failure_redirect_url' => route('store.payment.status', $slug),
        ];
        $invoice = Invoice::create($params);
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        Session()->put('invoice', $invoice);

        return $invoice['invoice_url'];
    }

    private function processMidtrans($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);

        

        $midtrans_secret_key = \App\Models\Utility::GetValueByName('midtrans_secret_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        try {
            \Midtrans\Config::$serverKey = $midtrans_secret_key;
            \Midtrans\Config::$isProduction = false;
            \Midtrans\Config::$isSanitized = true;
            \Midtrans\Config::$is3ds = true;

            $params = array(
                'transaction_details' => array(
                    'order_id' => $orderID,
                    'gross_amount' => ceil($cartlist_final_price),
                ),
                'customer_details' => array(
                    'first_name' => $other_info['firstname'],
                    'last_name' => $other_info['lastname'],
                    'email' => $other_info['email'],
                    'phone' => '8787878787',
                ),
            );
            $snapToken = \Midtrans\Snap::getSnapToken($params);
            if (is_array($request)) {
                // Convert the array to an Illuminate\Http\Request object
                $request = Request::create('/', 'POST', $request);
            }
            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
            $data = [
                'snap_token' => $snapToken,
                // P1-SEC-17 F2: the SERVER key is no longer handed to the view. It was printed as
                // `data-client-key` and, through route($fallback_url, $data), into the form action URL.
                'order_id' => $orderID,
                'slug' => $slug,
                'amount' => $cartlist_final_price,
                'fallback_url' => 'store.payment.status',
                $slug
            ];
            return view('midtras.order_payment', compact('data'));
        } catch (\Exception $e) {
            return \App\Services\PaymentFailure::redirectBack($e, 'processMidtrans');
        }
    }

    public function getProductStatus($request, $slug, $response)
    {
        $requests_data = $request->all();

        if($requests_data['payment_type'] && $requests_data['payment_type'] == "bank_transfer"){
            $validator = \Illuminate\Support\Facades\Validator::make($requests_data, [
                'payment_receipt' => 'required|image',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error',__('The payment receipt field is required.'));
            }

            if ($requests_data['payment_receipt']) {
                $dir = 'uploads/receipt';
                if($requests_data['payment_receipt']) {
                    $fileName = $requests_data['payment_receipt']->getClientOriginalName() . '_' . time();
                    $path = Utility::upload_file($requests_data, 'payment_receipt', $fileName, $dir,[],$requests_data['payment_receipt']);
                }
                if ($path['flag'] == 1) {
                    $payment_receipt = $path['url'];
                } else {
                    return redirect()->back()->with('error', __($path['msg']));
                }
            }
        }
        
        $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
        $store = getStore($slug);
        // Session::forget('request_data');
        $customer_id = $requests_data['customer_id'];

        if (!empty($requests_data['method_id'])) {

            $request['method_id'] = $requests_data['method_id'];
        }
        $user = Cache::remember('admin_details', 3600, function () {
            return User::where('type','admin')->first();
        });
        if ($user->type == 'admin') {
            $plan = Plan::find($user->plan_id);
        }
        if (module_is_active('PartialPayments')) {
            if ($plan && strpos($plan->modules, 'PartialPayments') !== false && \Auth::guard('customers')->user()) {
                if ((isset($request->partial_payment_type) || $request->partial_payment_type == 'pending_payment')) {
                    $return = \Workdo\PartialPayments\app\Models\PartialPayments::ManagePartialPayment($requests_data, $slug);
                    if ($return['status'] == 'success') {
                        return redirect()->back()->with('success', __($return['message']));
                    }
                }
            }
        }
        if (!auth('customers')->user()) {
            if ($request->coupon_code != null) {
                $coupon = Coupon::where('id', $request->coupon_info['coupon_id'])->where('store_id', $store->id)->first();
                $coupon_email = $coupon->PerUsesCouponCount();
                $i = 0;
                foreach ($coupon_email as $email) {
                    if ($email == $request->billing_info['email']) {
                        $i++;
                    }
                }

                if (!empty($coupon->coupon_limit_user)) {
                    if ($i >= $coupon->coupon_limit_user) {
                        return $this->error(['message' => __('This coupon has expired.')]);
                    }
                }
            }
        }
        if (!auth('customers')->user()) {
            $rules = [
                'billing_info' => 'required',
                'payment_type' => 'required',
                // 'delivery_id' => 'required',
            ];
        } else {
            $rules = [
                'customer_id' => 'required',
                'billing_info' => 'required',
                'payment_type' => 'required',
                //'delivery_id' => 'required',
            ];
        }


        $validator = \Validator::make($requests_data, $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            Utility::error([
                'message' => $messages->first()
            ]);
        }

        $cartlist_final_price = 0;
        $final_price = 0;
        $tax_price = 0;
        // cart list api call


        $cartlist_final_price = !empty($response['final_price']) ? $response['final_price'] : 0;
        $product_price = !empty($response['total_final_price']) ? $response['total_final_price'] : 0;
        $final_price = $response['total_final_price'];
        $total_sub_price = $response['total_sub_price'];
        // SERVER-DERIVED tax. This used to prefer `$requests_data['tax_price']`,
        // i.e. the value the customer posted, and only fall back to the cart's
        // own figure — so the tax component of the charged total was client
        // controlled. $response is the server-built cart payload.
        $tax_price = $this->taxResolver()->resolve(is_array($response) ? $response : []);
        $this->taxResolver()->recordClientSuppliedTax(
            $requests_data['tax_price'] ?? null,
            $tax_price,
            __METHOD__
        );
        $billing = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        $products = $response['product_list'];

        /*
         * THIS BASKET MUST BELONG TO THE STORE THIS ORDER IS BEING WRITTEN INTO.
         * P01-COMMERCE-SEC-03 FOLLOW-UP. This is the `cod` and `bank_transfer` order writer and
         * it had no ownership check of any kind: its product lookup is
         * `Product::where('id', $product_id)->first()` (no store predicate), its variant lookup
         * is built from the caller's own two values, and its plain-product arm decrements
         * `Product::find($product_id)`. A cart line naming another merchant's product moved that
         * merchant's `products.product_stock` and wrote the order here.
         *
         * `$store` IS THE ONE THIS METHOD WILL ACTUALLY WRITE WITH -- resolved at the top of this
         * method from the request's own `slug`, not from the route. PaymentController::processOrder
         * now pins that `slug` to the route's store before calling, and this check is what makes
         * the pin self-enforcing: if the pin were ever removed, a basket from store A arriving
         * with `slug` for store B is refused HERE, because the products would not belong to the
         * store named. That is why this is not redundant with the caller's copy.
         *
         * The refusal is written out rather than delegated to
         * GuardsGatewayReturn::gatewayReturnForeignProductRefusal() because that message tells
         * the shopper their payment has not been applied to an order -- on a cash-on-delivery or
         * bank-transfer checkout nothing has been charged, so it would be false.
         */
        if ($this->basketHasForeignProduct($products, $store)) {
            Log::warning('cod/bank_transfer order writer refused: basket names a product from another store', [
                'store_id' => $store->id ?? null,
                'customer_id' => $customer_id ?: null,
                'payment_type' => $requests_data['payment_type'] ?? null,
            ]);

            return redirect()->back()->with('error', __('Your cart contains an item this store does not sell.'));
        }

        /*
         * AND NO LINE QUANTITY IS ABSURD -- the same correction, measured on this writer too.
         * `PaymentController::processOrder` refuses `qty <= 0` before dispatching here, which is a
         * FLOOR and not a CEILING: a line of 2147483647 drove a 50-unit product to
         * -2147483597 and wrote an order for 21474836470.0 marked 'Paid', HTTP 302, no error.
         * GuardsGatewayReturn::MAX_SANE_LINE_QUANTITY existed and was wired into none of the three
         * cash-on-delivery writers. The message matches the one processOrder already uses for the
         * floor, so nothing new reaches the shopper.
         */
        if ($this->basketHasUnusableQuantity($products)) {
            Log::warning('cod/bank_transfer order writer refused: basket holds an unusable quantity', [
                'store_id' => $store->id ?? null,
                'customer_id' => $customer_id ?: null,
            ]);

            return redirect()->back()->with('error', __('Your cart contains an invalid quantity.'));
        }

        $coupon_price = 0;
        // coupon api call
        if (!empty($requests_data['coupon_code'])) {
            if (isset($requests_data['coupon_info']) && $requests_data['coupon_info']) {
                $coupon_price = $requests_data['coupon_info']['coupon_discount_amount'] ?? 0;
            } else {
                $coupon_data = $requests_data['coupon_code'];
                $apply_coupon = [
                    'coupon_code' => $coupon_data,
                    'sub_total' => $product_price,
                    'slug' => $requests_data['slug']

                ];
                $request->merge($apply_coupon);

                $couponss = new ApiController();
                $apply_coupon_response = $couponss->apply_coupon($request, $slug);
                $apply_coupon = (array) $apply_coupon_response->getData()->data;
                $order_array['coupon']['message'] = $apply_coupon['message'];
                $order_array['coupon']['status'] = false;
                if (!empty($apply_coupon['final_price'])) {
                    $cartlist_final_price = $apply_coupon['final_price'];
                    $coupon_price = $apply_coupon['amount'];
                    $order_array['coupon']['status'] = true;
                }
            }
        } elseif (!empty($requests_data['cartlist']['coupon_code'])) {
            $coupon_info = is_object($requests_data['cartlist']['coupon_info']) ? (array) $requests_data['cartlist']['coupon_info'] : $requests_data['cartlist']['coupon_info'];
            if (isset($coupon_info) && $coupon_info) {
                $coupon_price = ($coupon_info['coupon_final_amount'] ?? 0);
            } else {
                $coupon_data = $requests_data['cartlist']['coupon_code'];
                $apply_coupon = [
                    'coupon_code' => $coupon_data,
                    'sub_total' => $product_price,
                    'slug' => $requests_data['slug']

                ];
                $request->merge($apply_coupon);

                $couponss = new ApiController();
                $apply_coupon_response = $couponss->apply_coupon($request, $slug);
                $apply_coupon = (array) $apply_coupon_response->getData()->data;
                $order_array['coupon']['message'] = $apply_coupon['message'];
                $order_array['coupon']['status'] = false;
                if (!empty($apply_coupon['final_price'])) {
                    $cartlist_final_price = $apply_coupon['final_price'];
                    $coupon_price = $apply_coupon['amount'];
                    $order_array['coupon']['status'] = true;
                }
            }
        }
        $delivery_price = 0;
        if ($plan->shipping_method == 'on') {
            // The client short-circuit that used to sit here
            // (`if (isset($requests_data['shipping_final_price'])) $delivery_price = ...`)
            // is DELETED: it let the posted shipping price become the charged
            // shipping price. Control now always falls through to the server
            // computation below.
            if (!empty($request->method_id)) {
                $del_charge = new CartController();
                $delivery_charge = $del_charge->get_shipping_method($request, $slug);
                $content = $delivery_charge->getContent();

                $data = json_decode($content, true);
                $delivery_price = $data['total_final_price'];
                // SERVER-DERIVED tax; previously preferred the posted value.
                // Note the old fallback read `$content['final_tax_price']` from
                // the raw JSON STRING rather than from the decoded $data, so it
                // could never fire.
                $tax_price = $this->taxResolver()->resolve(
                    is_array($response) ? $response : [],
                    is_array($data) ? $data : null
                );
            }
            // else {
            //     return $this->error(['message' => 'Shipping Method not found']);
            // }

            if (isset($requests_data['shipping_final_price'])
                && !OrderTotalReconciler::amountsMatch(
                    (string) $requests_data['shipping_final_price'],
                    (string) $delivery_price
                )
            ) {
                Log::warning('Checkout: client-supplied shipping_final_price discarded (server value used)', [
                    'site' => __METHOD__,
                    'client_shipping_final_price' => (string) $requests_data['shipping_final_price'],
                    'server_shipping_final_price' => (string) $delivery_price,
                ]);
            }
        } else {
            if (!empty($tax_price)) {
                $tax_price = $tax_price;
            } else {
                $tax_price = 0;
            }
        }

        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();

        // Order stock decrease start
        $prodduct_id_array = [];
        // Stock decrements applied in this loop, so they can be given back if
        // a later product in the same cart cannot be fulfilled and the order is
        // therefore never written. Without this, a rejected checkout would
        // silently consume the inventory of the products it got through first.
        $appliedStockChanges = [];

        // Cart lines to empty once the order has COMMITTED -- see the
        // accumulation site inside the loop below.
        $cartLinesToClear = [];
        if (!empty($products)) {

$products_weight_array = [];
            foreach ($products as $key => $product) {
                $prodduct_id_array[] = $product->product_id;

                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;

                $Product = Product::where('id', $product_id)->first();


$products_weight_array[] = [
    'product_id'   => $product_id,
    'variant_id'   => $variant_id,
    'qty'          => $qtyy,
    'weight'       => !empty($Product->product_weight) ? $Product->product_weight : 0,
];


                $datas = Product::find($product_id);
                if (isset($settings['stock_management']) && $settings['stock_management'] == 'on') {
                    if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {
                        $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();
                        $variationOptions = explode(',', $ProductStock->variation_option);
                        $option = in_array('manage_stock', $variationOptions);
                        if (!empty($ProductStock)) {
                            if ($option == true) {
                                // ATOMIC. This was a read-modify-write (`$remain_stock = $ProductStock->stock - $qtyy; ... ->save()`),
                                // i.e. a lost update: two concurrent checkouts both wrote
                                // `stock - qty` and the second overwrote the first, so the shop
                                // oversold by the quantity it processed concurrently, and the
                                // subtraction could also drive a SIGNED stock column negative.
                                if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'variant', $ProductStock, $qtyy, false)) {
                                    return $this->error($stockFailure);
                                }

                                if ($ProductStock->stock <= $ProductStock->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $ProductStock, $settings);
                                        }

                                    }
                                }
                                if (isset($settings['out_of_stock_threshold']) && $ProductStock->stock <= $settings['out_of_stock_threshold']) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_out_of_stock($product, $ProductStock, $settings);
                                        }
                                    }
                                }
                            } else {
                                // ATOMIC. This was a read-modify-write (`$remain_stock = $datas->product_stock - $qtyy; ... ->save()`),
                                // i.e. a lost update: two concurrent checkouts both wrote
                                // `stock - qty` and the second overwrote the first, so the shop
                                // oversold by the quantity it processed concurrently, and the
                                // subtraction could also drive a SIGNED stock column negative.
                                if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'product', $datas, $qtyy, false)) {
                                    return $this->error($stockFailure);
                                }
                                if ($datas->product_stock <= $datas->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $datas, $settings);
                                        }

                                    }
                                }
                                if (isset($settings['out_of_stock_threshold']) && $datas->product_stock <= $settings['out_of_stock_threshold']) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_out_of_stock($product, $datas, $settings);
                                        }
                                    }
                                }
                                if (isset($settings['out_of_stock_threshold']) && $datas->product_stock <= $settings['out_of_stock_threshold'] && $datas->stock_order_status == 'notify_customer') {
                                    //Stock Mail
                                    $order_email = $billing['email'];
                                    $owner = User::find($store->created_by);
                                    $ProductId = '';

                                    try {
                                        $dArr = [
                                            'item_variable' => $Product->id,
                                            'product_name' => $Product->name,
                                            'customer_name' => $billing['firstname'],
                                        ];

                                        // Send Email
                                        $resp = Utility::sendEmailTemplate('Stock Status', $order_email, $dArr, $owner, $store, $ProductId);
                                    } catch (\Exception $e) {
                                        $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                                    }
                                    try {
                                        $mobile_no = $request['billing_info']['billing_user_telephone'];
                                        $customer_name = $request['billing_info']['firstname'];
                                        $msg = __("Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name. ");
                                        $resp = Utility::SendMsgs('Stock Status', $mobile_no, $msg);
                                    } catch (\Exception $e) {
                                        $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
                                    }
                                }
                            }
                        } else {
                            return $this->error(['message' => 'Product not found .']);
                        }
                    } elseif (!empty($product_id) && $product_id != 0) {

                        if (!empty($Product)) {
                            // ATOMIC. This was a read-modify-write (`$remain_stock = $Product->product_stock - $qtyy; ... ->save()`),
                            // i.e. a lost update: two concurrent checkouts both wrote
                            // `stock - qty` and the second overwrote the first, so the shop
                            // oversold by the quantity it processed concurrently, and the
                            // subtraction could also drive a SIGNED stock column negative.
                            if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'product', $Product, $qtyy, false)) {
                                return $this->error($stockFailure);
                            }
                            if ($Product->product_stock <= $Product->low_stock_threshold) {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::low_stock_threshold($Product, $settings);
                                    }
                                }
                            }

                            if (isset($settings['out_of_stock_threshold']) && $Product->product_stock <= $settings['out_of_stock_threshold']) {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::out_of_stock($Product, $settings);
                                    }
                                }
                            }

                            if (isset($settings['out_of_stock_threshold']) && $Product->product_stock <= $settings['out_of_stock_threshold'] && $Product->stock_order_status == 'notify_customer') {
                                //Stock Mail
                                $order_email = $billing['email'];
                                $owner = User::find($store->created_by);
                                $ProductId = '';

                                try {
                                    $dArr = [
                                        'item_variable' => $Product->id,
                                        'product_name' => $Product->name,
                                        'customer_name' => $billing['firstname'],
                                    ];

                                    // Send Email
                                    $resp = Utility::sendEmailTemplate('Stock Status', $order_email, $dArr, $owner, $store, $ProductId);
                                } catch (\Exception $e) {
                                    $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                                }
                                try {
                                    $mobile_no = $request['billing_info']['billing_user_telephone'];
                                    $customer_name = $request['billing_info']['firstname'];
                                    $msg = __("Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name. ");
                                    $resp = Utility::SendMsgs('Stock Status', $mobile_no, $msg);
                                } catch (\Exception $e) {
                                    $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
                                }
                            }

                        } else {
                            return $this->error(['message' => 'Product not found .']);
                        }
                    } else {
                        return $this->error(['message' => 'Please fill proper product json field .']);
                    }
                }
                /*
                 * CART ROWS ARE NO LONGER DESTROYED BY A FAILED CHECKOUT.
                 *
                 * THE DEFECT. This was a `Cart::...->delete()` executed HERE,
                 * per line, inside the stock loop -- while `new Order()` does
                 * not happen until after the loop. So a failure part-way through
                 * (a product that cannot be found, a stock shortfall, a thrown
                 * exception) had already deleted the lines processed so far, and
                 * left the rest. The shopper got no order AND a half-empty cart,
                 * with no way to recover what was in it: nothing restored those
                 * rows, and the loop's own `return $this->error(...)` paths skip
                 * any cleanup.
                 *
                 * THE FIX is to defer rather than to compensate. The line's
                 * identity is remembered and the delete happens AFTER the order
                 * has committed, so the cart is emptied only once there is an
                 * order to show for it. Deferring is strictly better than
                 * restoring: nothing has to reconstruct cart state that this
                 * method no longer holds.
                 *
                 * FAILURE DIRECTION, stated because it is a deliberate choice:
                 * if the deferred delete itself fails, the order exists and the
                 * cart still holds its rows -- the shopper may re-see items they
                 * have bought. That is a visible, recoverable annoyance. The
                 * previous direction -- cart gone, no order -- destroyed data.
                 *
                 * The predicate is unchanged, including `store_id`, so no row
                 * outside the shopper's own cart in this store is touched.
                 */
                // P1-SEC-23/24. The AUTHENTICATED customer's id, or the guest's own cart cookie -- not
                // `$request->customer_id`, which for a guest is the id of the Customer found or created from the
                // billing e-mail they typed (see PaymentController::clearCartLines()).
                $cartLinesToClear[] = [
                    'customer_id' => auth('customers')->id(),
                    'cookie_session_id' => Cookie::get('cart'),
                    'product_id' => $product_id,
                    'variant_id' => $variant_id,
                    'store_id' => $store->id,
                ];
            }
        }
        // Order stock decrease end
        if (!empty($prodduct_id_array)) {
            $prodduct_id_array = $prodduct_id_array = array_unique($prodduct_id_array);
            $prodduct_id_array = implode(',', $prodduct_id_array);
        } else {
            $prodduct_id_array = '';
        }

        $product_reward_point = 1;

        $product_order_id = '0' . date('YmdHis');
        $is_guest = 1;
        if (auth('customers')->check()) {
            $product_order_id = $request->customer_id . date('YmdHis');
            $is_guest = 0;
        }
        // add in  Order table  start
        $order = new Order();
        $order->product_order_id = $product_order_id;
        $order->order_date = date('Y-m-d H:i:s');
        $order->customer_id = !empty($request->customer_id) ? $request->customer_id : 0;
        $order->is_guest = $is_guest;
        $order->product_id = $prodduct_id_array;
        $order->product_json = json_encode($products);
        $order->product_price = $product_price;
        $order->coupon_price = $coupon_price;
        $order->delivery_price = $delivery_price;
        $order->tax_price = $tax_price;
        if (!auth('customers')->user()) {
            if ($plan->shipping_method == "on") {
                $order->final_price = $total_sub_price + $delivery_price;
            } else {
                $order->final_price = $total_sub_price;
            }

        } elseif (module_is_active('PartialPayments') && \Auth::guard('customers')->user() && (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on')) {
            // Was `$requests_data['cartlist_final_price']` — with this module
            // active the order TOTAL came from the client. Recomputed through
            // the module's own server-side ManageDeposit(); see
            // PaymentController::resolvePartialPaymentAmounts() for the same
            // repair on the storefront path.
            $order->final_price = $this->resolvePartialPaymentDeposit(
                $request,
                $slug,
                [
                    'tax_price' => $tax_price,
                    'shipping_price' => $delivery_price,
                    'coupon_price' => $coupon_price,
                    'total_sub_price' => $total_sub_price,
                ],
                $total_sub_price
            );
            \Workdo\PartialPayments\app\Models\OrderPartialPayments::OrderPartialPayments($order, $slug, $request);
        } else {

            if (module_is_active('RewardClubPoint') && isset($requests_data['club_point_is_active']) && $requests_data['club_point_is_active'] == 'on') {
                $customerDetail = Customer::find($customer_id);
                $rewardSetting = getAdminAllSetting($store->created_by, $store->id) ?? null;
                $saveRewardPrice = ($customerDetail->total_club_point * $rewardSetting['reward_point_price'] ?? 0) / ($rewardSetting['reward_points'] ?? 0);
                if ($plan->shipping_method == "on") {
                    $order->final_price = $total_sub_price + $delivery_price - $saveRewardPrice;
                } else {
                    $order->final_price = $total_sub_price - $saveRewardPrice;
                }
            } else {
                if ($plan->shipping_method == "on") {
                    $order->final_price = $total_sub_price + $delivery_price;
                } else {
                    $order->final_price = $total_sub_price;
                }
            }
        }
        event(new AddAdditionalFields($order, $request->all(), $store));
        $order->payment_comment = !empty($requests_data['payment_comment']) ? $requests_data['payment_comment'] : '';
        $order->payment_type = $requests_data['payment_type'];
        // DERIVED, not asserted — see App\Services\PaymentVerificationService.
        // This was the literal `'Paid'`.
        $order->payment_status = $this->paymentVerifier()->resolveOrderPaymentStatus(
            $requests_data['payment_type'] ?? null,
            $this->paymentVerifier()->consumeVerification(),
            $order->final_price,
            __METHOD__
        );
        $order->delivery_id = $requests_data['method_id'] ?? 0;
        $order->delivery_comment = !empty($requests_data['delivery_comment']) ? $requests_data['delivery_comment'] : '';
        if (module_is_active('PreOrder') && \Auth::guard('customers')->user() && isset($requests_data['order_type']) && $requests_data['order_type'] == 'pre_order') {
            $order->delivered_status = 8;
        }else{
            $order->delivered_status = 0;
        }
        $order->reward_points = SetNumber($product_reward_point);
        $order->additional_note = $request->additional_note;
        $order->payment_receipt = $payment_receipt ?? null;
        $order->store_id = $store->id;
        // Reconcile the total against its own components before it is
        // persisted. WARN-ONLY on purpose: the two pricing branches above build
        // final_price from different bases (one adds tax and subtracts the
        // coupon, the other takes the cart's own pre-summed total), so a
        // rejecting assertion here would fail legitimate orders in the second
        // branch. Logging the mismatch is what produces the evidence for the
        // cutover; App\Services\OrderTotalReconciler::assertConsistent() is the
        // rejecting variant and is ready for the write paths whose component
        // maps are unambiguous.
        $this->totalReconciler()->warnIfInconsistent(
            [
                'product_price' => $order->product_price,
                'tax_price' => $order->tax_price,
                'delivery_price' => $order->delivery_price,
                'coupon_price' => $order->coupon_price,
            ],
            $order->final_price,
            __METHOD__
        );
        // TRANSACTION BOUNDARY -- OPENS HERE. See the twin boundary in
        // PaymentController::getProductStatus() for the full rationale; this is
        // the same defect in the service-side writer, which is the one that
        // actually runs for 'cod' and 'bank_transfer'
        // (PaymentService::process() cases at :315 and :317).
        //
        // THE DEFECT. No DB::transaction existed anywhere in this method. The
        // Order row, OrderBillingDetail, OrderCouponDetail, the UserCoupon
        // consumption row and the OrderTaxDetail rows were each saved alone, so
        // a failure part-way left an order without its billing address, or a
        // coupon consumed with nothing recording what for, or recorded against
        // the order but never marked used -- redeemable a second time.
        //
        // COVERS: every local write from the order row to the last tax row.
        // DOES NOT COVER: the stock loop and the per-line cart deletion above,
        // which stay outside because that loop makes SMTP and SMS calls on its
        // low-stock branches; inventory is compensated in the catch instead.
        // Cart rows deleted by that loop are still not restored -- pre-existing
        // behaviour, reported rather than hidden.
        //
        // The exception is RE-THROWN, so the failure response is unchanged.
        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $order->save();
       
            // add in  Order table end
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachment($order, $slug, $request);
            }
            // Utility::paymentWebhook($order);

            $billing_city_id = $billing['billing_city'] ?? 0;
            if (!empty($billing['billing_city'])) {
                $cityy = City::where('id', $billing['billing_city'])->first();
                if (!empty($cityy)) {
                    $billing_city_id = $cityy->id;
                } else {
                    $new_billing_city = new City();
                    $new_billing_city->name = $billing['billing_city'];
                    $new_billing_city->state_id = $billing['billing_state'];
                    $new_billing_city->country_id = $billing['billing_country'];
                    $new_billing_city->save();
                    $billing_city_id = $new_billing_city->id;
                }
            }

            $delivery_city_id = $billing['delivery_city'] ?? 0;
            if (!empty($billing['delivery_city'])) {
                $d_cityy = City::where('id', $billing['delivery_city'])->first();
                if (!empty($d_cityy)) {
                    $delivery_city_id = $d_cityy->id;
                } else {
                    $new_delivery_city = new City();
                    $new_delivery_city->name = $billing['delivery_city'];
                    $new_delivery_city->state_id = $billing['delivery_state'];
                    $new_delivery_city->country_id = $billing['delivery_country'];
                    $new_delivery_city->save();
                    $delivery_city_id = $new_delivery_city->id;
                }
            }

            $OrderBillingDetail = new OrderBillingDetail();
            $OrderBillingDetail->order_id = $order->id;
            $OrderBillingDetail->product_order_id = $order->product_order_id;
            $OrderBillingDetail->first_name = !empty($billing['firstname']) ? $billing['firstname'] : '';
            $OrderBillingDetail->last_name = !empty($billing['lastname']) ? $billing['lastname'] : '';
            $OrderBillingDetail->email = !empty($billing['email']) ? $billing['email'] : '';
            $OrderBillingDetail->telephone = !empty($billing['billing_user_telephone']) ? $billing['billing_user_telephone'] : '';
            $OrderBillingDetail->address = !empty($billing['billing_address']) ? $billing['billing_address'] : '';
            $OrderBillingDetail->postcode = !empty($billing['billing_postecode']) ? $billing['billing_postecode'] : '';
            $OrderBillingDetail->country = !empty($billing['billing_country']) ? $billing['billing_country'] : '';
            $OrderBillingDetail->state = !empty($billing['billing_state']) ? $billing['billing_state'] : '';
            $OrderBillingDetail->city = $billing_city_id;
        
            $OrderBillingDetail->delivery_address = !empty($billing['delivery_address']) ? $billing['delivery_address'] : '';
            $OrderBillingDetail->delivery_city = $delivery_city_id;
            $OrderBillingDetail->delivery_postcode = !empty($billing['delivery_postcode']) ? $billing['delivery_postcode'] : '';
            $OrderBillingDetail->delivery_country = !empty($billing['delivery_country']) ? $billing['delivery_country'] : '';
            $OrderBillingDetail->delivery_state = !empty($billing['delivery_state']) ? $billing['delivery_state'] : '';
            $OrderBillingDetail->save();
       

            // add in Order Coupon Detail table start
            if (!empty($requests_data['coupon_info'])) {
                $coupon_data = $requests_data['coupon_info'];
                $Coupon = Coupon::find($coupon_data['coupon_id']);
                if ($Coupon) {
                    // coupon stock decrease end

                    // Order Coupon history
                    $OrderCouponDetail = new OrderCouponDetail();
                    $OrderCouponDetail->order_id = $order->id;
                    $OrderCouponDetail->product_order_id = $order->product_order_id;
                    $OrderCouponDetail->coupon_id = $coupon_data['coupon_id'];
                    $OrderCouponDetail->coupon_name = $coupon_data['coupon_name'];
                    $OrderCouponDetail->coupon_code = $coupon_data['coupon_code'];
                    $OrderCouponDetail->coupon_discount_type = $coupon_data['coupon_discount_type'];
                    $OrderCouponDetail->coupon_discount_number = $coupon_data['coupon_discount_number'];
                    $OrderCouponDetail->coupon_discount_amount = $coupon_data['coupon_discount_amount'];
                    $OrderCouponDetail->coupon_final_amount = $coupon_data['coupon_final_amount'];
                
                    $OrderCouponDetail->save();

                    // Coupon history
                    $UserCoupon = new UserCoupon();
                    $UserCoupon->user_id = !empty($request->customer_id) ? $request->customer_id : null;
                    $UserCoupon->coupon_id = $Coupon->id;
                    $UserCoupon->amount = $coupon_data['coupon_discount_amount'];
                    $UserCoupon->order_id = $order->id;
                    $UserCoupon->date_used = now();
                
                    $UserCoupon->save();
                }


                $discount_string = '-' . $coupon_data['coupon_discount_amount'];
                $CURRENCY = Utility::GetValueByName('CURRENCY');
                $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME');
                if ($coupon_data['coupon_discount_type'] == 'flat') {
                    $discount_string .= $CURRENCY;
                } else {
                    $discount_string .= '%';
                }

                $discount_string .= ' ' . __('for all products');
                $order_array['coupon']['code'] = $coupon_data['coupon_code'] ?? null;
                $order_array['coupon']['discount_string'] = $discount_string ?? null;
                $order_array['coupon']['price'] = SetNumber($coupon_data['coupon_final_amount'] ?? 0.00);
            } elseif (!empty($requests_data['cartlist']['coupon_code'])) {
                $coupon_data = is_object($requests_data['cartlist']['coupon_info']) ? (array) $requests_data['cartlist']['coupon_info'] : $requests_data['cartlist']['coupon_info'];
                $Coupon = Coupon::find($coupon_data['coupon_id']);
                if ($Coupon) {
                    // coupon stock decrease end

                    // Order Coupon history
                    $OrderCouponDetail = new OrderCouponDetail();
                    $OrderCouponDetail->order_id = $order->id;
                    $OrderCouponDetail->product_order_id = $order->product_order_id;
                    $OrderCouponDetail->coupon_id = $coupon_data['coupon_id'];
                    $OrderCouponDetail->coupon_name = $coupon_data['coupon_name'];
                    $OrderCouponDetail->coupon_code = $coupon_data['coupon_code'];
                    $OrderCouponDetail->coupon_discount_type = $coupon_data['coupon_discount_type'];
                    $OrderCouponDetail->coupon_discount_number = $coupon_data['coupon_discount_number'];
                    $OrderCouponDetail->coupon_discount_amount = $coupon_data['coupon_discount_amount'];
                    $OrderCouponDetail->coupon_final_amount = $coupon_data['coupon_final_amount'];
                
                    $OrderCouponDetail->save();

                    // Coupon history
                    $UserCoupon = new UserCoupon();
                    $UserCoupon->user_id = !empty($request->customer_id) ? $request->customer_id : null;
                    $UserCoupon->coupon_id = $Coupon->id;
                    $UserCoupon->amount = $coupon_data['coupon_discount_amount'];
                    $UserCoupon->order_id = $order->id;
                    $UserCoupon->date_used = now();
                
                    $UserCoupon->save();
                }


                $discount_string = '-' . $coupon_data['coupon_discount_amount'];
                $CURRENCY = Utility::GetValueByName('CURRENCY');
                $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME');
                if ($coupon_data['coupon_discount_type'] == 'flat') {
                    $discount_string .= $CURRENCY;
                } else {
                    $discount_string .= '%';
                }

                $discount_string .= ' ' . __('for all products');
                $order_array['coupon']['code'] = $coupon_data['coupon_code'] ?? null;
                $order_array['coupon']['discount_string'] = $discount_string ?? null;
                $order_array['coupon']['price'] = SetNumber($coupon_data['coupon_final_amount'] ?? 0.00);
            }

            // add in Order Coupon Detail table end
            if ($response['tax_id']) {
                $taxes = TaxMethod::where('tax_id', $response['tax_id'])->where('store_id', $store->id)->orderBy('priority', 'asc')->get();
                $other_info = is_string($requests_data['billing_info']) ? (array) json_decode($requests_data['billing_info']) : $requests_data['billing_info'];
                $country = !empty($other_info->delivery_country) ? $other_info->delivery_country : $other_info['delivery_country'];
                $state_id = !empty($other_info->delivery_state) ? $other_info->delivery_state : $other_info['delivery_state'];
                $city_id = !empty($other_info->delivery_city) ? $other_info->delivery_city : $other_info['delivery_city'];
                foreach ($taxes as $tax) {
                    $countryMatch = (!$tax->country_id || $country == $tax->country_id);
                    $stateMatch = (!$tax->state_id || $state_id == $tax->state_id);
                    $cityMatch = (!$tax->city_id || $city_id == $tax->city_id);

                    if ($countryMatch && $stateMatch && $cityMatch) {
                        $OrderTaxDetail = new OrderTaxDetail();
                        $OrderTaxDetail->order_id = $order->id;
                        $OrderTaxDetail->product_order_id = $order->product_order_id;
                        $OrderTaxDetail->tax_id = $response['tax_id'];
                        $OrderTaxDetail->tax_name = $tax->name;
                        $OrderTaxDetail->tax_discount_amount = $tax->tax_rate;
                        // The tax ledger row records the SERVER-DERIVED tax, the same
                        // figure persisted on the order, not the posted one.
                        $OrderTaxDetail->tax_final_amount = $tax_price;
                    
                        $OrderTaxDetail->save();
                    }
                }
            }
            \Illuminate\Support\Facades\DB::commit();

            // The order exists now, so the cart may be emptied. Inside the
            // try but after the commit: a cart row that will not delete must
            // not roll back an order whose money has already moved, which is
            // why clearCartLines() swallows and logs per line.
            $this->clearCartLines($cartLinesToClear);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();

            // Give back the inventory this request consumed before the boundary
            // opened; otherwise a failed order write silently ate stock.
            $this->restoreStockDecrements($appliedStockChanges);

            Log::critical('Order write failed and was rolled back; stock decrements were given back', [
                'store_id' => $store->id ?? null,
                'payment_type' => $requests_data['payment_type'] ?? null,
                'restored_lines' => count($appliedStockChanges),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        //activity log
        ActivityLog::order_entry([
            'customer_id' => $order->customer_id,
            'order_id' => $order->product_order_id,
            'order_date' => $order->order_date,
            'products' => $order->product_id,
            'final_price' => $order->final_price,
            'payment_type' => $order->payment_type,
            'store_id' => $order->store_id
        ]);
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        //Order Mail
        //$order_email = !empty($other_info->email) ? $other_info->email : '';
        $order_email = $OrderBillingDetail->email ?? (!empty($other_info->email) ? $other_info->email : '');
        $owner = User::find($store->created_by);
        $owner_email = $owner->email;
        $order_id = Crypt::encrypt($order->id);
        
        event(new GetProductStatus($OrderBillingDetail,$order,$owner));

        if (module_is_active('PreOrder') && \Auth::guard('customers')->user() && isset($requests_data['order_type']) && $requests_data['order_type'] == 'pre_order') {
            \Workdo\PreOrder\app\Models\PreOrderHistory::PreOrderHistory($order, $store, $request, $order_email);
        } else {
            try {
                $dArr = [
                    'order_id' => $order->product_order_id,
                ];

                // Send Email
                $resp = Utility::sendEmailTemplate('Order Created', $order_email, $dArr, $owner, $store, $order_id);
                $resp1 = Utility::sendEmailTemplate('Order Created For Owner', $owner_email, $dArr, $owner, $store, $order_id);
            } catch (\Exception $e) {
                $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
            }
        }

        foreach ($products as $product) {
            $product_data = Product::find($product->product_id);

            if ($product_data) {
                if ($product_data->variant_product == 0) {
                    if ($product_data->track_stock == 1) {
                        OrderNote::order_note_data([
                            'customer_id' => !empty($request->customer_id) ? $request->customer_id : '0',
                            'order_id' => $order->id,
                            'product_name' => !empty($product_data->name) ? $product_data->name : '',
                            'variant_product' => $product_data->variant_product,
                            'product_stock' => !empty($product_data->product_stock) ? $product_data->product_stock : '',
                            'status' => 'Stock Manage',
                            'store_id' => $order->store_id,
                        ]);
                    }
                } else {
                    $variant_data = ProductVariant::find($product->variant_id);
                    $variationOptions = explode(',', $variant_data->variation_option);
                    $option = in_array('manage_stock', $variationOptions);
                    if ($option == true) {
                        OrderNote::order_note_data([
                            'customer_id' => !empty($request->customer_id) ? $request->customer_id : '0',
                            'order_id' => !empty($order->id) ? $order->id : '',
                            'product_name' => !empty($product_data->name) ? $product_data->name : '',
                            'variant_product' => $product_data->variant_product,
                            'product_variant_name' => !empty($variant_data->variant) ? $variant_data->variant : '',
                            'product_stock' => !empty($variant_data->stock) ? $variant_data->stock : '',
                            'status' => 'Stock Manage',
                            'store_id' => $order->store_id,
                        ]);
                    }
                }
            }
        }

        OrderNote::order_note_data([
            'customer_id' => !empty($request->customer_id) ? $request->customer_id : '0',
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'delivery_status' => 'Pending',
            'status' => 'Order Created',
            'store_id' => $order->store_id
        ]);

        try {
            $msg = __("Hello, Welcome to $store->name .Hi,your order id is $order->product_order_id, Thank you for Shopping We received your purchase request, we'll be in touch shortly!. ");
            $mess = Utility::SendMsgs('Order Created', $OrderBillingDetail->telephone, $msg);
        } catch (\Exception $e) {
            $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
        }
        if (auth('customers')->user() && module_is_active('ProductAffiliate')) {
            Utility::affiliateTransaction($order);
        }
        // add in Order Tax Detail table end
        if (!empty($order) && !empty($OrderBillingDetail)) {
            $cart = Cookie::get('cart');
            $cart = Cart::forGuestCookie($cart)->delete();
            // $order_array['order_id'] = $order->id;
            // $cart_array = [];
            // $cart_json = json_encode($cart_array);
            // Cookie::queue('cart', $cart_json, 1440);

            if (auth('customers')->user()) {
                event(new AddRewardClubPoint($requests_data, $order, $slug));
            }




try {
    app(\App\Services\StoreNotificationService::class)
        ->orderPaid($order);
} catch (\Throwable $e) {
    \Log::error('Failed to send store notification', [
        'order_id' => $order->id,
        'message' => $e->getMessage(),
    ]);
}




$r=$request->all();







$selectedMethodId = $requests_data['method_id'] ?? null;

if ($selectedMethodId) {
    $selectedShippingMethod = ShippingMethod::query()
        ->where('id', $selectedMethodId)
        ->where('store_id', $store->id)
        ->first();

    if (
        $selectedShippingMethod &&
        trim((string) $selectedShippingMethod->method_name) === 'Delivery Syria'
    ) {
        $deliverySyriaResult = $this->webhook(
            $order,
            $OrderBillingDetail,
            $request->all(),
            $products_weight_array,
            $owner
        );

        if (!($deliverySyriaResult['success'] ?? false)) {
            Log::error('Delivery Syria parcel was not created', [
                'order_id' => $order->id ?? null,
                'method_id' => $selectedMethodId,
                'result' => $deliverySyriaResult,
            ]);
        }
    }
}














            return redirect()->route('order.complete', $slug)->with('data', $order->product_order_id);
        } else {
            return $this->error(['message' => 'Somthing went wrong.']);
        }
    }










/**
 * How much cash the courier must collect on delivery.
 *
 * The amount owed is the order total for a cash-on-delivery order and nothing
 * for an order that was settled online. The Delivery Syria payload used to send
 * the literal '0' in every case, so a COD parcel was dispatched with an
 * instruction to collect nothing.
 *
 * The format matches `selling_price` in the same payload, which is the order
 * total cast to string, so the two figures are expressed identically.
 *
 * @param  \App\Models\Order $order
 */
private function codCashCollectionAmount($order): string
{
    $paymentType = strtolower(trim((string) ($order->payment_type ?? '')));

    if (!in_array($paymentType, ['cod', 'cash_on_delivery', 'cash'], true)) {
        return '0';
    }

    $amount = $order->final_price ?? 0;

    if (!is_numeric($amount) || (float) $amount <= 0) {
        // A COD order with no collectable total is a data problem, not a
        // zero-value delivery: say so rather than silently shipping '0'.
        Log::error('COD order has no collectable total; courier told to collect 0', [
            'order_id' => $order->id ?? null,
            'payment_type' => $paymentType,
            'final_price' => (string) $amount,
        ]);

        return '0';
    }

    return (string) $amount;
}

private function webhook(
    $order,
    $billing,
    array $requestData,
    array $productsWeight,
    $owner
): array {
    $secret = trim((string) ($owner->deliverysyria_secret ?? ''));

    if ($secret === '') {
        throw new \RuntimeException(
            'Delivery Syria token is not configured for the store owner.'
        );
    }

    // الوزن الإجمالي = وزن المنتج × الكمية.
    $weight = collect($productsWeight)->sum(function ($item) {
        if (!is_array($item)) {
            return 0;
        }

        $itemWeight = (float) ($item['weight'] ?? 0);
        $quantity = (float) ($item['qty'] ?? 1);

        return $itemWeight * $quantity;
    });

    if ($weight <= 0) {
        $weight = (float) ($requestData['weight'] ?? 1);
    }

    $customerName = trim(
        (string) ($billing->first_name ?? '') . ' ' .
        (string) ($billing->last_name ?? '')
    );

    if ($customerName === '') {
        $customerName = (string) (
            $requestData['customer_name']
            ?? 'Customer'
        );
    }

    $customerAddress = !empty($billing->delivery_address)
        ? (string) $billing->delivery_address
        : (string) ($billing->address ?? '');

    $customerPhone = (string) (
        $billing->telephone
        ?? $requestData['customer_phone']
        ?? ''
    );

    $note = (string) (
        $order->additional_note
        ?: $order->delivery_comment
        ?: ($requestData['note'] ?? '')
    );

    $latitude = $requestData['lat'] ?? null;
    $longitude = $requestData['long'] ?? null;

    /*
     * المحافظة والمدينة موجودتان فعليًا
     * داخل OrderBillingDetail.
     */
    $governorate = State::where('id', $billing->delivery_state)
    ->value('name') ?? '';
    $hubId = (string) ($billing->delivery_city ?? '');

    $chargeDetails = [
        'vatTex'                    => 15,
        'VatAmount'                 => '10.00',
        'deliveryChargeAmount'      => '50.00',
        'codChargeAmount'           => '20.00',
        'totalDeliveryChargeAmount' => '80.00',
        'currentPayable'            => '60.00',
        'packagingAmount'           => '5.00',
        'liquidFragileAmount'       => '10.00',
    ];

    $encodedChargeDetails = json_encode(
        $chargeDetails,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($encodedChargeDetails === false) {
        throw new \RuntimeException(
            'Could not encode chargeDetails: ' . json_last_error_msg()
        );
    }

    $payload = [
        'shop_id' => (string) $order->store_id,

        // البيانات الجديدة.
        'Governorate' => $governorate,
        'hub_id' => $hubId,

        'weight' => (string) $weight,
        'invoice_no' => (string) $order->product_order_id,
        // The courier is now told how much to collect. This was the literal
        // '0', so for a cash-on-delivery order Delivery Syria was dispatched
        // with an instruction to collect NOTHING: the parcel was handed over and
        // no cash was ever asked for. For an order already settled online it
        // stays 0, which is correct.
        'cash_collection' => $this->codCashCollectionAmount($order),
        'selling_price' => (string) $order->final_price,
        'customer_name' => $customerName,
        'customer_address' => $customerAddress,
        'lat' => $latitude !== null ? (string) $latitude : null,
        'long' => $longitude !== null ? (string) $longitude : null,
        'customer_phone' => $customerPhone,
        'note' => $note,
        'parcel_bank' => 'on',
        'fragileLiquid' => 'on',
        'cod_amount2' => '1',
        'chargeDetails' => $encodedChargeDetails,
    ];

    /*
     * نحذف null فقط ونبقي "0" والقيم الفارغة
     * إن كان الـAPI يحتاج وجود الحقل.
     */
    $payload = array_filter(
        $payload,
        static fn ($value) => $value !== null
    );

    Log::info('Delivery Syria outgoing request', [
        'order_id' => $order->id ?? null,
        'authorization' => 'Bearer token exists',
        'token_length' => strlen($secret),
        'x_platform' => 'storesyria',
        'Governorate' => $governorate,
        'hub_id' => $hubId,
        'payload' => $payload,
    ]);

    try {
        $response = Http::acceptJson()
            ->asJson()
            ->withToken($secret)
            ->withHeaders([
                'X-Platform' => 'storesyria',
            ])
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(
                times: 2,
                sleepMilliseconds: 500,
                when: null,
                throw: false
            )
            ->post(
                'https://jingle-name-buddhism.ngrok-free.dev/api/parcel/create-with-security',
                $payload
            );
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        Log::error('Delivery Syria connection failed', [
            'order_id' => $order->id ?? null,
            'message' => $e->getMessage(),
        ]);

        return [
            'success' => false,
            'status' => 0,
            'message' => 'Could not connect to Delivery Syria API.',
        ];
    } catch (\Throwable $e) {
        Log::error('Delivery Syria request failed unexpectedly', [
            'order_id' => $order->id ?? null,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return [
            'success' => false,
            'status' => 0,
            'message' => $e->getMessage(),
        ];
    }

    $responseData = $response->json();

    if ($response->failed()) {
        Log::error('Delivery Syria API returned an error', [
            'order_id' => $order->id ?? null,
            'status' => $response->status(),
            'response' => $response->body(),
            'response_json' => $responseData,
            'payload' => $payload,
        ]);

        return [
            'success' => false,
            'status' => $response->status(),
            'message' => is_array($responseData)
                ? ($responseData['message'] ?? $response->body())
                : $response->body(),
            'data' => $responseData,
        ];
    }

    Log::info('Delivery Syria parcel created successfully', [
        'order_id' => $order->id ?? null,
        'response' => $responseData,
    ]);

    return [
        'success' => true,
        'status' => $response->status(),
        'data' => $responseData,
    ];
}




private function webhook_backup($order, $r, $products_weight = []) {
    $url = 'https://webhook.site/c331de9b-b220-4db3-9619-82d9c64367bd'; 
    $data = [
        'order' => $order,
        'request' => $r,
        'products_weight' => $products_weight
    ];
    $response = Http::post($url, $data);
    return [
        'status' => $response->status(),
        'body' => $response->body()
    ];
}












    public function processWhatsapp($request, $slug, $response)
    {
        $data = $request->all();
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        //Session::put('request_data', $request->all());
        return view('payment.whatsapp', compact('data'));
    }

    private function processNepalste($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        /*
         * P1-SEC-26: READ THE SETTINGS OF THE STORE BEING PAID, NOT THE CALLER'S.
         *
         * {storeSlug}/process-order carries no `auth` and processOrder() treats a web principal as a guest, so a merchant, staff
         * user or super admin with a `web` session is admitted here. A no-argument getAdminAllSetting() then takes its FIRST
         * branch -- Setting::where('created_by', $user->id)->where('store_id', $user->current_store) -- and answers with THAT
         * user's own store. For an anonymous or `customers`-guard shopper it is already correct, via P1-SEC-18 F4's
         * getRouteStoreId() fallback.
         *
         * WHY NOT getAdminAllSetting AT ALL, which took two attempts to get right and is the whole point of this comment:
         *   - getAdminAllSetting() with no arguments is the defect above.
         *   - getAdminAllSetting(null, $storeId) returns an EMPTY array, measured: the first branch still filters
         *     `created_by = $user->id`, so a foreign store id matches no rows.
         *   - getAdminAllSetting($store->created_by, $storeId) looked right and is ALSO wrong, found by the read-only review of this
         *     fix. `settings.created_by` is not the store's owner -- it is WHOEVER SAVED LAST: PaymentSettingController::saveSettings
         *     keys updateOrCreate on ['name','store_id'] only and rewrites `created_by` to auth()->user()->id (:162-173), and
         *     StoreSettingsAccess admits a STAFF member holding the permission. So for a store whose payment tab was last saved by
         *     staff, that pair matches no rows either.
         * The `created_by` limb buys nothing here in any case: every settings writer already scopes `store_id` to its own tenant, so
         * a row carrying this store's id can only have been written by an insider of this store. Dropping it is exactly the predicate
         * the canonical per-store reader uses (Utility::GetValueByName, app/Models/Utility.php:181-196) and that Setting::paymentList
         * and the admin API already use for these same keys -- so the storefront's "SSLCommerz is enabled" and this read now agree.
         * StorelessCredentialReadTest pins every form side by side.
         *
         * Resolved from getRouteStoreId() and not from $store: the line below overwrites this method's own $slug parameter with the
         * request body's `slug`, which the checkout form does not post, so $store is null on the legitimate flow. With no resolvable
         * store (a job, a console run) the ternary falls back to the previous behaviour, which is the right answer there.
         *
         * SCOPE, stated so the next reader is not misled: because of that same $slug overwrite the method dies at the CURRENCY_NAME
         * read below for EVERY caller unless the body carries `slug`, so this correction is live only for that request shape -- which
         * is also the only shape that reaches the gateway, and the shape the original defect needed. Fixing the overwrite is a
         * separate change (it moves currency resolution) and is recorded on #99, not done here. Note also that credentials now come
         * from the PATH store while the currency and success_url still come from the BODY store; previously the pair was (caller's
         * store, body store).
         */
        $sec26Store = \App\Models\Store::find(getRouteStoreId());
        $admin_payment_setting = $sec26Store
            ? \App\Models\Setting::where('store_id', $sec26Store->id)->pluck('value', 'name')->toArray()
            : getAdminAllSetting();

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $api_key = isset($admin_payment_setting['nepalste_public_key']) ? $admin_payment_setting['nepalste_public_key'] : '';
        $nepalste_mode = isset($admin_payment_setting['nepalste_mode']) ? $admin_payment_setting['nepalste_mode'] : '';

        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));

        $requests_data = $request->all();


        $parameters = [
            'identifier' => 'DFU80XZIKS',
            'currency' => $currency,
            'amount' => $cartlist_final_price,
            'details' => 'test',
            'ipn_url' => route('store.payment.status', $slug),
            'cancel_url' => route('nepalste.cancel'),
            'success_url' => route('store.payment.status', $slug),
            'public_key' => $api_key,
            'site_logo' => 'https://nepalste.com.np/assets/images/logoIcon/logo.png',
            'checkout_theme' => 'dark',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@mail.com',
        ];

        if ($nepalste_mode == 'live') {
            //live end point
            $url = "https://nepalste.com.np/payment/initiate";
        } else {
            //test end point
            $url = "https://nepalste.com.np/sandbox/payment/initiate";
        }
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        \Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($result, true);
        if (isset($result['success'])) {
            return redirect($result['url']);
        }else{
            return redirect()->back()->with('error',isset($result['message']) ? $result['message'] : 'Something went wrong');
        }
    }

    private function processKhalti($request, $slug, $response)
    {
        $data = $request->all();
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        $store = Store::where('slug', $request->slug)->first();
        $admin_payment_setting = getAdminAllSetting();
        return view('payment.khalti', compact('data', 'admin_payment_setting', 'store'));
    }

    private function processPayHere($request, $slug, $response)
    {
        // PAYHERE IS RETIRED BY OWNER DECISION. REFUSE DELIBERATELY, NOT INCIDENTALLY.
        //
        // The owner's closure decision is that PayHere is not needed, so the integration is
        // withdrawn rather than merely broken: lahirulhr/laravel-payhere has been removed from
        // composer.json and composer.lock, and this path is closed on purpose and permanently.
        //
        // This refusal is FIRST and UNCONDITIONAL. It does not ask whether the SDK happens to
        // be loadable, because "the class is missing" is a symptom of the removal, not the
        // reason for it -- and a future tree that somehow vendored the SDK back in must still
        // refuse, instead of quietly resuming charges through a gateway the owner retired.
        // The class_exists() check below is kept as a SECOND line of defence only.
        //
        // It returns the SAME redirect-with-error this method already produces for other
        // failures, and it runs BEFORE $store/$admin_payment_setting are read and before the
        // try block's Session::put('request_data') and CheckoutAttachmentStore() writes, so a
        // refused checkout leaves no row, no session state and no attachment behind.
        //
        // NOT the plan-activation gate: that is assertPlanActivationEnabled('payhere') driven
        // by config/payments.php, and it stays where it is. See docs/BUILD-REPRODUCIBILITY.md.
        \Log::warning('PayHere storefront checkout refused: the integration is retired (owner decision).', [
            'store_slug' => is_object($request) ? ($request->slug ?? null) : null,
        ]);

        return redirect()->back()->with('error', __('PayHere is currently unavailable. Please choose another payment method.'));

        // Second line of defence. Unreachable while the refusal above stands; it is retained so
        // that deleting the refusal does not silently re-expose a call to a class that the
        // committed lock does not install. Calling a missing class raises \Error, which
        // `catch (\Exception)` would NOT catch, which is how this path used to 500.
        if (!class_exists(PayHere::class)) {
            \Log::error('PayHere checkout refused: package lahirulhr/laravel-payhere is not installed (' . PayHere::class . ' missing). See docs/BUILD-REPRODUCIBILITY.md.');

            return redirect()->back()->with('error', __('PayHere is currently unavailable. Please choose another payment method.'));
        }

        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        $admin_payment_setting = getAdminAllSetting(null, $store->id);

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $orderID = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->sub_total;
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));

        $requests_data = $request->all();
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
                    number_format($cartlist_final_price, 2, '.', '') .
                    'LKR' .
                    strtoupper(md5($admin_payment_setting['payhere_merchant_secret']))
                )
            );

            $data = [
                'first_name' => $other_info['firstname'],
                'last_name' => $other_info['lastname'],
                'email' => $other_info['email'],
                'phone' => $other_info['billing_user_telephone'],
                'address' => $other_info['billing_address'],
                'city' => $other_info['billing_city'],
                'country' => $other_info['billing_country'],
                'order_id' => $orderID,
                'items' => 'test',
                'currency' => $currency,
                'amount' => $cartlist_final_price,
                'hash' => $hash,
            ];
            if (is_array($request)) {
                // Convert the array to an Illuminate\Http\Request object
                $request = Request::create('/', 'POST', $request);
            }
            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
            return PayHere::checkOut()
                ->data($data)
                ->successUrl(route('store.payment.status', $slug))
                ->failUrl(route('store.payment.status', $slug))
                ->renderView();
        } catch (\Throwable $e) {
            \Log::debug($e->getMessage());
            return redirect()->back()->with('error', __($e->getMessage()));
        }
    }

    private function processAuthorizeNet($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        /*
         * P1-SEC-26: READ THE SETTINGS OF THE STORE BEING PAID, NOT THE CALLER'S.
         *
         * {storeSlug}/process-order carries no `auth` and processOrder() treats a web principal as a guest, so a merchant, staff
         * user or super admin with a `web` session is admitted here. A no-argument getAdminAllSetting() then takes its FIRST
         * branch -- Setting::where('created_by', $user->id)->where('store_id', $user->current_store) -- and answers with THAT
         * user's own store. For an anonymous or `customers`-guard shopper it is already correct, via P1-SEC-18 F4's
         * getRouteStoreId() fallback.
         *
         * WHY NOT getAdminAllSetting AT ALL, which took two attempts to get right and is the whole point of this comment:
         *   - getAdminAllSetting() with no arguments is the defect above.
         *   - getAdminAllSetting(null, $storeId) returns an EMPTY array, measured: the first branch still filters
         *     `created_by = $user->id`, so a foreign store id matches no rows.
         *   - getAdminAllSetting($store->created_by, $storeId) looked right and is ALSO wrong, found by the read-only review of this
         *     fix. `settings.created_by` is not the store's owner -- it is WHOEVER SAVED LAST: PaymentSettingController::saveSettings
         *     keys updateOrCreate on ['name','store_id'] only and rewrites `created_by` to auth()->user()->id (:162-173), and
         *     StoreSettingsAccess admits a STAFF member holding the permission. So for a store whose payment tab was last saved by
         *     staff, that pair matches no rows either.
         * The `created_by` limb buys nothing here in any case: every settings writer already scopes `store_id` to its own tenant, so
         * a row carrying this store's id can only have been written by an insider of this store. Dropping it is exactly the predicate
         * the canonical per-store reader uses (Utility::GetValueByName, app/Models/Utility.php:181-196) and that Setting::paymentList
         * and the admin API already use for these same keys -- so the storefront's "SSLCommerz is enabled" and this read now agree.
         * StorelessCredentialReadTest pins every form side by side.
         *
         * Resolved from getRouteStoreId() and not from $store: the line below overwrites this method's own $slug parameter with the
         * request body's `slug`, which the checkout form does not post, so $store is null on the legitimate flow. With no resolvable
         * store (a job, a console run) the ternary falls back to the previous behaviour, which is the right answer there.
         *
         * SCOPE, stated so the next reader is not misled: because of that same $slug overwrite the method dies at the CURRENCY_NAME
         * read below for EVERY caller unless the body carries `slug`, so this correction is live only for that request shape -- which
         * is also the only shape that reaches the gateway, and the shape the original defect needed. Fixing the overwrite is a
         * separate change (it moves currency resolution) and is recorded on #99, not done here. Note also that credentials now come
         * from the PATH store while the currency and success_url still come from the BODY store; previously the pair was (caller's
         * store, body store).
         */
        $sec26Store = \App\Models\Store::find(getRouteStoreId());
        $admin_payment_setting = $sec26Store
            ? \App\Models\Setting::where('store_id', $sec26Store->id)->pluck('value', 'name')->toArray()
            : getAdminAllSetting();

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        $requests_data = $request->all();

        $data = [
            'orderID' => $orderID,
            'customer_id' => $request->customer_id,
            'slug' => $slug,
            'get_amount' => $cartlist_final_price,
        ];

        $data = json_encode($data);
        $req_data = $request->all();
        $get_amount = $cartlist_final_price;

        try {
            return view('payment.authorizenet', compact('req_data', 'admin_payment_setting', 'slug', 'data', 'get_amount'));
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }

    public function processTap($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        $admin_payment_setting = getAdminAllSetting();
        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $tap_secret_key = \App\Models\Utility::GetValueByName('tap_secret_key', $store->id);

        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        $requests_data = $request->all();

        $TapPay = new Payment(['tap_secret_key' => $tap_secret_key]);

        return $TapPay->charge([
            'amount' => $cartlist_final_price,
            'currency' => $currency,
            'threeDSecure' => 'true',
            'description' => 'test description',
            'statement_descriptor' => 'sample',
            'customer' => [
                    'first_name' => $other_info['firstname'],
                    'email' => $other_info['email'],
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
                'url' => route('store.payment.status', $slug)
            ]
        ], true);

    }

    public function processPhonePe($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        $admin_payment_setting = getAdminAllSetting(null, $store->id);
        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

        $orderID = $request->customer_id .'_'. date('YmdHis');
        $cartlist_final_price = (float) $request->cartlist_final_price;

        if (is_array($request)) {
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        $requestData['order_id'] = $orderID;
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        
        try {
            $phonepe = new \App\Libraries\PhonePe\PhonePe(
                $admin_payment_setting['phonepe_client_id'] ?? '',
                $admin_payment_setting['phonepe_client_secret'] ?? '',
                $admin_payment_setting['phonepe_mode'] ?? 'sandbox'
            );

            $result = $phonepe->initiatePayment([
                'merchantOrderId' => $orderID,
                'amount' => $cartlist_final_price,
                'message' => 'Order Payment',
                'redirectUrl' => route('store.payment.status', $slug)
            ]);

            if ($result['status']) {
                return redirect()->away($result['data']);
            }
            return redirect()->back()->with('error', $result['error']);

        } catch (\Exception $e) {
            \Log::error('PhonePe Payment Error: ' . $e->getMessage());
            return redirect()->back()->with('error', __('Payment processing failed. Please try again.'));
        }
    }

    public function processPaddle($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $admin_payment_setting = getAdminAllSetting(null, $store->id);
        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        $requestData['order_id'] = $orderID;
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        if (!auth('customers')->user()) {
            $user = auth()->user();
        } else {
            $user = Customer::find($request['customer_id']);
        }
        $requests_data = $request->all();
        config(
            [
                'cashier.vendor_id' => !empty($admin_payment_setting['paddle_vendor_id']) ? $admin_payment_setting['paddle_vendor_id'] : '',
                'cashier.vendor_auth_code' => !empty($admin_payment_setting['paddle_vendor_auth_code']) ? $admin_payment_setting['paddle_vendor_auth_code'] : '',
                'cashier.public_key' => !empty($admin_payment_setting['paddle_public_key']) ? $admin_payment_setting['paddle_public_key'] : '',
                'cashier.sandbox' => !empty($admin_payment_setting['paddle_mode']) ? $admin_payment_setting['paddle_mode'] : true
            ]
        );

        try {
            $price = $cartlist_final_price;
            $paymentData = [
                "$currency:$price"
            ];
            $priceInCents = (int) ($price);
            $printID = 'Order payment';
            $paylink = $user->charge($priceInCents, $printID, [
                'quantity_variable' => 0,
                'discountable' => 0,
                'customer_email' => $other_info['email'],
                'webhook_url' => route('store.payment.status', $slug),
                'return_url' => route('store.payment.status', $slug),
            ]);
            return redirect($paylink);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', str_replace(array('\'', '"', '`', '{', "\n"), ' ', $th->getMessage()));
        }

    }

    public function processPaiementpro($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $admin_payment_setting = getAdminAllSetting();
        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $merchant_id = \App\Models\Utility::GetValueByName('paiementpro_merchant_id', $store->id);

        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        $requests_data = $request->all();

        $call_back = route('store.payment.paiementpro', $slug);

        $data = array(
            'merchantId' => $merchant_id,
            'amount' => $cartlist_final_price,
            'description' => "Api PHP",
            'channel' => $request->channel,
            'countryCurrencyCode' => !empty($currency) ? $currency : '',
            'referenceNumber' => "REF-" . time(),
            'customerEmail' => $other_info['email'],
            'customerFirstName' => $other_info['firstname'],
            'customerLastname' => $other_info['lastname'],
            'customerPhoneNumber' => $request->mobile_number,
            'notificationURL' => $call_back,
            'returnURL' => $call_back,
            'returnContext' => json_encode(["requests_data" => $requests_data])
            ,
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

    public function processFedPay($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $admin_payment_setting = getAdminAllSetting();
        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $fedpay_secret_key = \App\Models\Utility::GetValueByName('fedpay_secret_key', $store->id);
        $fedpay_mode = \App\Models\Utility::GetValueByName('fedpay_mode', $store->id);

        if($currency != "XOF"){
            return redirect()->back()->with('error', __('Currency is not Supported , Fedapay Support only XOF .'));
        }


        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = intval($request->cartlist_final_price);

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }


        $requests_data = $request->all();

        try {
            \FedaPay\FedaPay::setApiKey($fedpay_secret_key);
            \FedaPay\FedaPay::setEnvironment($fedpay_mode);

            $transaction = \FedaPay\Transaction::create([
                "description" => "Fedapay Payment",
                "amount" => $cartlist_final_price,
                "currency" => ["iso" => $currency],
                "callback_url" => route('store.payment.status', $slug),
                "cancel_url" => route('store.payment.status', $slug)
            ]);
            $token = $transaction->generateToken();

            return redirect($token->url);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', str_replace(array('\'', '"', '`', '{', "\n"), ' ', $th->getMessage()));
        }

    }

    public function processCinetPay($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $cinet_pay_site_id = \App\Models\Utility::GetValueByName('cinet_pay_site_id', $store->id);
        $cinet_pay_api_key = \App\Models\Utility::GetValueByName('cinet_pay_api_key', $store->id);

        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        $requests_data = $request->all();

        $call_back = route('store.payment.cinetpay', $slug);

        $merchant_id = isset($cinet_pay_site_id) ? $cinet_pay_site_id : '';
        $cinet_pay_api_key = isset($cinet_pay_api_key) ? $cinet_pay_api_key : '';
        $call_back = route('store.payment.cinetpay', [$store->slug]) . '?_token=' . csrf_token();
        $returnURL = route('store.payment.cinetpay.return', [$store->slug]) . '?_token=' . csrf_token();

        $transaction_id = strtoupper(str_replace('.', '', uniqid('', true)));

        $data = [
            "amount" => (int) $cartlist_final_price,
            "currency" => 'XOF',
            "apikey" => $cinet_pay_api_key,
            "site_id" => $merchant_id,
            "transaction_id" => $transaction_id,
            "description" => "Api PHP",
            "return_url" => $returnURL,
            "notify_url" => $call_back,
            "metadata" => "REF-" . time(),
            'customer_surname' => $other_info['lastname'],
            'customer_name' => $other_info['firstname'],
            'customer_email' => $other_info['email'],
            'customer_phone_number' => $request->mobile_number,
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
            $payment_link = $response->data->payment_url; // Retrieving the payment URL
            //Recording information in the database
            //Then redirect to the payment page
            return redirect($payment_link);
        } else {
            return redirect()->back()->with('error', $response->description ?? 'Something went wrong.');
        }
    }

    public function processSenagePay($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME');

        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        $requests_data = $request->all();
        $price = $requests_data['cartlist_final_price'];
        $user = [
            'name' => $requests_data['billing_info']['firstname'],
            'email' => $requests_data['billing_info']['email'],
            'contact_number' => $requests_data['billing_info']['billing_user_telephone'],
        ];

        try {
            $transactionId = uniqid();

            $senangPaySession = [
                'slug' => $slug
            ];
            $request->session()->put('senangPaySession', $senangPaySession);

            $request = [
                'full_name' => $user['name'],
                'email' => $user['email'],
                'contact_number' => $user['contact_number']
            ];
            $senangPay = new Senangpay();
            $senangPay->setSendPaymentDetails($request, $user['name'], $orderID, $price);

            return redirect($senangPay->processPayment());

        } catch (\Exception $e) {
            \Log::debug($e->getMessage());
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function processCyberSource($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME');

        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        $requests_data = $request->all();

        $callback_url = 'store.get.cybersource.status';

        $price = $requests_data['cartlist_final_price'];

        $user = [
            'name' => $requests_data['billing_info']['firstname'],
            'email' => $requests_data['billing_info']['email'],
            'contact_number' => $requests_data['billing_info']['billing_user_telephone'],
        ];
        $data = [
            'slug' => $slug,
            'amount' => $price,
            'user' => $user
        ];
        $data = json_encode($data);

        $name = $requests_data['billing_info']['firstname'];
        try {
            return view('plans.cybersourcerequest', compact('callback_url', 'price', 'data', 'name'));
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }

    public function processOzow($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        

        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        // $currency = 'ZAR';
        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        try {
            // The store's own credentials, by id -- like the currency above. A read that names no store has no store_id
            // predicate and returns the last tenant's row (P1-SEC-18 F4); the route fallback covers it, this does not depend on it.
            $siteCode = \App\Models\Utility::GetValueByName('ozow_pay_Site_key', $store->id) ?: '';
            $privateKey = \App\Models\Utility::GetValueByName('ozow_pay_private_key', $store->id) ?: '';
            $apiKey = \App\Models\Utility::GetValueByName('ozow_pay_api_key', $store->id) ?: '';
            $isTest = \App\Models\Utility::GetValueByName('ozow_mode', $store->id) == 'sandbox' ? 'true' : 'false';

            $countryCode = "ZA";
            $currencyCode = $currency;
            $amount = $cartlist_final_price;
            $bankReference = time() . 'FKU';
            $transactionReference = time();


            if ($currencyCode != 'ZAR') {
                return redirect()->back()->with('error', __('currency not supported'));
            }

            $cancelUrl = route('store.get.ozow.status');

            $errorUrl = route('store.get.ozow.status');

            $successUrl = route('store.get.ozow.status');

            $notifyUrl = route('store.get.ozow.status');

            // Calculate the hash with the exact same data being sent
            $inputString = $siteCode . $countryCode . $currencyCode . $amount . $transactionReference . $bankReference . $cancelUrl . $errorUrl . $successUrl . $notifyUrl . $isTest . $privateKey;
            $hashCheck = \App\Http\Controllers\OzowController::generate_request_hash_check($inputString);

            $data = [
                "countryCode" => $countryCode,
                "amount" => $amount,
                "transactionReference" => $transactionReference,
                "bankReference" => $bankReference,
                "cancelUrl" => $cancelUrl,
                "currencyCode" => $currencyCode,
                "errorUrl" => $errorUrl,
                "isTest" => $isTest, // boolean value here is okay
                "notifyUrl" => $notifyUrl,
                "siteCode" => $siteCode,
                "successUrl" => $successUrl,
                "hashCheck" => $hashCheck,
            ];

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.ozow.com/postpaymentrequest',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => array(
                        'Accept: application/json',
                        'ApiKey: ' . $apiKey,
                        'Content-Type: application/json'
                    ),
            ));

            $response = curl_exec($curl);
            curl_close($curl);
            $json_attendance = json_decode($response, true);
            if (isset($json_attendance['url']) && $json_attendance['url'] != null) {
                $OzowPaySession = [
                    'slug' => $slug
                ];
                $request->session()->put('OzowPaySession', $OzowPaySession);
                return redirect()->away($json_attendance['url']);

            } else {
                return redirect()->back()->with('error', $response['message'] ?? 'Something went wrong.');
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }

    public function processEasebuzz($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        // By the store's id (P1-SEC-18 F4): a read naming no store returns the last tenant's row.
        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $easebuzz_merchant_key = \App\Models\Utility::GetValueByName('easebuzz_merchant_key', $store->id);
        $easebuzz_salt_key = \App\Models\Utility::GetValueByName('easebuzz_salt_key', $store->id);
        $easebuzz_enviroment_name = \App\Models\Utility::GetValueByName('easebuzz_enviroment_name', $store->id);

        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));

        if (is_array($request)) {
            // Convert the array to an Illuminate\Http\Request object
            $request = Request::create('/', 'POST', $request);
        }
        $requests_data = $request->except('attachment','payment_receipt');
        // $requests_data = $request->all();
        // $request->session()->put('easebuzz_request_data', $requests_data);
        // session(['easebuzz_request_data' => $requests_data]);
        Session::put('easebuzz_request_data', $requests_data);
        Session::save();
        $serialized_request_data = urlencode(json_encode($request->all()));
        $call_back = route('store.payment.easebuzz', ['storeSlug' => $slug]) . '?_token=' . csrf_token() . '&request_data=' . $serialized_request_data;

        // $call_back = route('store.payment.easebuzz', ['storeSlug' => $slug]) . '?_token=' . csrf_token();
        $returnURL = route('checkout', $store->slug);

        $transaction_id = strtoupper(str_replace('.', '', uniqid('', true)));
        $easebuzzObj = new Easebuzz($easebuzz_merchant_key, $easebuzz_salt_key, $easebuzz_enviroment_name);
        $price = number_format((float) $cartlist_final_price, 2, '.', '');
        $postData = array(
            "txnid" => $transaction_id,
            "amount" => $price,
            "firstname" => $other_info['firstname'] ?? 'Test',
            "email" => $other_info['email'] ?? 'test@example.com',
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
            return redirect()->back()->with('error', __('Something went wrong.'));
        }
    }

    public function getNMIProductview($request, $slug, $response)
    {
        $requestData = $request->except('attachment','payment_receipt');
        $store = getStore($slug);
        $languages = Utility::languages();
        $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
        Session::put('request_data', $requestData);
        $currentTheme = $store->theme_id;
        return view('front_end.payment.nmi-payment', compact('request', 'slug', 'response', 'currentTheme', 'currantLang'));
    }

    public function processPayU($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $merchant_key = \App\Models\Utility::GetValueByName('payu_merchant_key', $store->id);
        $salt_key = \App\Models\Utility::GetValueByName('payu_salt_key', $store->id);
        $payu_mode = \App\Models\Utility::GetValueByName('payu_mode', $store->id);

        $orderID = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;
        $pay_id = $orderID . '777';

        $txnid = $orderID;

        if (is_array($request)) {
            $request = Request::create('/', 'POST', $request);
        }

        $requestData = $request->except('attachment','payment_receipt');

        \Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        $furl = route('store.payu.status', ['data' => Crypt::encrypt(['status' => 'false', 'order_id' => $orderID])]);
        $surl = route('store.payu.status', ['data' => Crypt::encrypt(['status' => 'true', 'order_id' => $orderID])]);
        $session = ['slug' => $slug];
        $request->session()->put('\'' . $orderID . '\'', $session);
        $pay = [
            'key' => $merchant_key,
            'mode' => $payu_mode,
            'salt' => $salt_key,
            'txnid' => $txnid,
            'amount' => $cartlist_final_price,
            'firstname' => $other_info['firstname'],
            'email' => $other_info['email'],
            'surl' => $surl,
            'furl' => $furl,
            'productinfo' => 'WorkDo',
            'service_provider' => 'payu_paisa',
        ];

        // Session::put('\'' . $orderID . '\'', $pay);
        $request->session()->put('\'' . $pay_id . '\'', $pay);
        return redirect()->to(route('payu.pay', ['pay' => Crypt::encrypt($pay_id)]));
    }

    private function processSofort($request, $slug, $response)
    {
        $slug = $request['slug'];
        $store = Store::where('slug', $request['slug'])->first();
        $sofort_secret_key = \App\Models\Utility::GetValueByName('sofort_secret_key', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request['customer_id'] . date('YmdHis');
        $cartlist_final_price = $request['cartlist_final_price'];
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));
        if ($totalprice > 0.0) {
            $l_name = $store->slug;
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

            Stripe\Stripe::setApiKey($sofort_secret_key);

            $data = StripeSession::create([
                'payment_method_types' => ['sofort'],
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => $CURRENCY_NAME,
                            'product_data' => [
                                'name' => $store->name,
                            ],
                            'unit_amount' => (int) $stripe_formatted_price,
                        ],
                        'quantity' => 1,
                    ]
                ],
                'mode' => 'payment',
                'success_url' => route(
                    'store.payment.status',
                    [
                        $slug,
                        $return_url_parameters('success'),
                    ]
                ),
                'cancel_url' => route(
                    'store.payment.status',
                    [
                        $slug,
                    ]
                ),
            ]);
            if (is_array($request)) {
                // Convert the array to an Illuminate\Http\Request object
                $request = Request::create('/', 'POST', $request);
            }
            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
            $data = $data ?? false;
            try {
                $place_order_data = ($data);
                return $place_order_data->url;
            } catch (\Exception $e) {
                return redirect()->route('checkout', $slug)->with('error', __('Transaction has been failed!'));
            }
        }
        return response()->json(['message' => 'Payment processed using Stripe']);
    }

    private function processESewa($request, $slug, $response)
    {
        $slug = $request['slug'];
        $store = Store::where('slug', $request['slug'])->first();
        $esewa_merchant_key = \App\Models\Utility::GetValueByName('esewa_merchant_key', $store->id);
        $esewa_mode = \App\Models\Utility::GetValueByName('esewa_mode', $store->id);
        $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', $store->id);

        $orderID = $request['customer_id'] . date('YmdHis');
        $cartlist_final_price = $request['cartlist_final_price'];
        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($CURRENCY, '', $cartlist_final_price)));

        if ($totalprice > 0.0) {
            config([
                'esewa.scd' => isset($esewa_merchant_key) ? $esewa_merchant_key : '',
                'esewa.env' => ucfirst(isset($esewa_mode) ? $esewa_mode : 'Sandbox'),
            ]);
            $payment = new eSewa();
            $successURL = route('store.payment.status', $slug);
            $faildURL = route('esewa.transaction.failure', $slug);
            $pay = $payment->esewaCheckout($cartlist_final_price, 0, 0, 0, $orderID, $successURL, $faildURL);
            $requestData = $request->except('attachment','payment_receipt');
            Session::put('request_data', $requestData);
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
            }
            try {
                return redirect()->away($pay);
            } catch (\Exception $e) {
                return redirect()->route('checkout', $slug)->with('error', __('Transaction has been failed!'));
            }
        }
        return response()->json(['message' => 'Payment processed using Stripe']);
    }

    public function processPaynow($request, $slug, $response)
    {
		$slug = !empty($request->slug) ? $request->slug : '';
		$store = getStore($slug);
		
		$other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

		$currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

		$orderID = $request->customer_id . date('YmdHis');

		$cartlist_final_price = $request->cartlist_final_price;

		$totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
		$requestData = $request->except('attachment','payment_receipt');
		Session::put('request_data', $requestData);

		if (module_is_active('CheckoutAttachment')) {
		    \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
		}

		try {
		    $requests_data = $request->all();
		    $user =[
		        'name' => $requests_data['billing_info']['firstname'],
		        'email' => $requests_data['billing_info']['email'],
		        'contact_number' => $requests_data['billing_info']['billing_user_telephone'],
		    ];

		        $integration_id =  \App\Models\Utility::GetValueByName('paynow_pay_integration_id', $store->id) ? \App\Models\Utility::GetValueByName('paynow_pay_integration_id', $store->id) : '';
		        $integration_key = \App\Models\Utility::GetValueByName('paynow_pay_integration_key', $store->id) ? \App\Models\Utility::GetValueByName('paynow_pay_integration_key', $store->id) : '';
		        $merchant_email = \App\Models\Utility::GetValueByName('paynow_pay_merchant_email', $store->id) ? \App\Models\Utility::GetValueByName('paynow_pay_merchant_email', $store->id) : '';
		        $mode = \App\Models\Utility::GetValueByName('paynow_mode', $store->id) && \App\Models\Utility::GetValueByName('paynow_mode', $store->id) == 'sandbox'  ? 'sandbox' : 'production';

		        $data =[
		            'slug'=>$slug,
		            'amount' => $cartlist_final_price,
		            'store_id' =>  $store->id,
		            'order_id' => $orderID,
		            'email' => $user['email'],
		        ];

		        $success_url = route('store.get.Paynow.status',['data'=> $data]);

		        $faild_url = route('store.get.Paynow.status',['data'=> $data]);

		        if($mode == 'sandbox'){
		            $email = $merchant_email;
		        }elseif($mode == 'production'){
		            $email = $user['email'];
		        }

		        $pay=[
		            'integration_id'=>$integration_id,
		            'integration_key'=>$integration_key,
		            'success_url'=>$success_url,
		            'faild_url'=>$faild_url,
		            'email'=>$email,
		            'order_id' => $orderID,
		            'item' => 'Item Order',
		            'amount' => $cartlist_final_price,
		        ];

		        $response= \App\Http\Controllers\PaynowController::paynowPayment($pay);
		        return $response;

		} catch (\Exception $ex) {
		    $exMessage = __('Paynow.' . $ex->getMessage());
		    return redirect()->route('checkout', $slug)->with('error', $ex->getMessage() ?? 'Something went wrong.');
		}
    }

    public function processMyfatoorah($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

        $orderId = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        $requestData = $request->except('attachment','payment_receipt');
        \Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        try {
            $allowedCurrencies = ['SAR', 'QAR', 'OMR', 'KWD', 'BHD', 'JOD', 'AED', 'USD'];

            if (!in_array($currency, $allowedCurrencies)) {
                return redirect()->back()->with('error', __('Selected currency is not supported for MyFatoorah payment.'));
            }

            $mfConfig = [
                'apiKey' => \App\Models\Utility::GetValueByName('myfatoorah_pay_api_key', $store->id) ? \App\Models\Utility::GetValueByName('myfatoorah_pay_api_key', $store->id) : '',
                'isTest' => \App\Models\Utility::GetValueByName('myfatoorah_mode', $store->id) == 'sandbox' ? true : false,
                'countryCode' => \App\Models\Utility::GetValueByName('myfatoorah_pay_country_iso', $store->id) ? \App\Models\Utility::GetValueByName('myfatoorah_pay_country_iso', $store->id) : '',
            ];

            //For example: pmid=0 for MyFatoorah invoice or pmid=1 for Knet in test mode
            $paymentId = request('pmid') ?: 0;
            $sessionId = request('sid') ?: null;
            $requests_data = $request->all();
            $callbackURL = route('store.myfatoorah.call_back');

            $curlData = [
                'CustomerName' => $requests_data['billing_info']['firstname'],
                'InvoiceValue' => $request->cartlist_final_price,
                'DisplayCurrencyIso' => $currency,
                'CustomerEmail' => $requests_data['billing_info']['email'],
                'CallBackUrl' => $callbackURL,
                'ErrorUrl' => $callbackURL,
                'MobileCountryCode' => '+965',
                'CustomerMobile' => $requests_data['billing_info']['billing_user_telephone'],
                'Language' => 'en',
                'CustomerReference' => $orderId,
                'SourceInfo' => 'Laravel ' . app()::VERSION . ' - MyFatoorah Package ' . MYFATOORAH_LARAVEL_PACKAGE_VERSION
            ];

            $mfObj = new \MyFatoorah\Library\API\Payment\MyFatoorahPayment($mfConfig);
            $payment = $mfObj->getInvoiceURL($curlData, $paymentId, $orderId, $sessionId);

            $MyFatoorahSession = [
                'slug' => $slug,
                'store_id' => $store->id,
            ];
            $request->session()->put('MyFatoorahSession', $MyFatoorahSession);

            return redirect($payment['invoiceURL']);
        } catch (\Exception $ex) {
            $exMessage = __('myfatoorah.' . $ex->getMessage());
            return redirect()->back()->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }

    public function processDPO($request, $slug, $response)
    {
        $slug = ! empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

        $orderId = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);

        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        try {
            $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
            $requests_data = $request->all();
            $user = [
                'name' => $requests_data['billing_info']['firstname'],
                'email' => $requests_data['billing_info']['email'],
                'contact_number' => $requests_data['billing_info']['billing_user_telephone'],
            ];

            return redirect()->route('store.dpo.view', [
                'slug' => $slug,
                'store_id' => $store->id,
                'total_price' => $totalprice,
                'user' => $user,
                'action' => route('store.pay.with.dpo')
            ]);
        } catch (\Exception $ex) {
            $exMessage = __('DPO.' . $ex->getMessage());

            return redirect()->route('checkout', $slug)->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }

    public function processBraintree($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

        $orderId = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);

        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        try {
            $BraintreeSession = [
                'amount' => $cartlist_final_price,
                'slug' => $slug,
                'store_id' => $store->id,
            ];
            $request->session()->put('\'' . $orderId . '\'', $BraintreeSession);

            $config = new \Braintree\Configuration([
                'environment' => \App\Models\Utility::GetValueByName('braintree_mode', $store->id) ?? 'sandbox',
                'merchantId' => \App\Models\Utility::GetValueByName('braintree_pay_merchant_id', $store->id) ?? '',
                'publicKey' => \App\Models\Utility::GetValueByName('braintree_pay_public_key', $store->id) ?? '',
                'privateKey' => \App\Models\Utility::GetValueByName('braintree_pay_private_key', $store->id) ?? '',
            ]);
            $gateway = new \Braintree\Gateway($config);
            $clientToken = $gateway->clientToken()->generate();

            if (!empty($clientToken)) {
                $url = route('store.braintree.pay', [
                    'token' => $clientToken,
                    'orderID' => $orderId,
                    'action' => route('store.braintree.status'),
                    'return_url' => route('checkout', $slug),
                    'user' => ' ',
                    'amount' => $currency . ' ' . $cartlist_final_price,
                ]);
                return redirect()->away($url);
            } else {
                return redirect()->route('checkout', $slug)->with('error', __('Transaction failed'));
            }
        } catch (\Exception $ex) {
            $exMessage = __('braintree.' . $ex->getMessage());
            return redirect()->route('checkout', $slug)->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }

    public function processPowerTranz($request, $slug, $response)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        $currentTheme = $store->theme_id;
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

        $orderId = $request->customer_id . date('YmdHis');

        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);

        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }

        try {
            $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
            $requests_data = $request->all();
            $user = [
                'name' => $requests_data['billing_info']['firstname'],
                'email' => $requests_data['billing_info']['email'],
                'contact_number' => $requests_data['billing_info']['billing_user_telephone'],
            ];

            return redirect()->route('store.Powertranz.view', [
                'slug' => $slug,
                'amount' => $cartlist_final_price,
                'store_id' => $store->id,
                'currantLang' => $currantLang,
                'currentTheme' => $currentTheme,
                'user' => $user
            ]);
        } catch (\Exception $ex) {
            $exMessage = __('Powertranz.' . $ex->getMessage());
            return redirect()->route('checkout', $slug)->with('error', $ex->getMessage() ?? 'Something went wrong.');
        }
    }
    public function processSSLCommerz($request, $slug, $response)
    {
        /*
         * P1-SEC-26: READ THE SETTINGS OF THE STORE BEING PAID, NOT THE CALLER'S.
         *
         * {storeSlug}/process-order carries no `auth` and processOrder() treats a web principal as a guest, so a merchant, staff
         * user or super admin with a `web` session is admitted here. A no-argument getAdminAllSetting() then takes its FIRST
         * branch -- Setting::where('created_by', $user->id)->where('store_id', $user->current_store) -- and answers with THAT
         * user's own store. For an anonymous or `customers`-guard shopper it is already correct, via P1-SEC-18 F4's
         * getRouteStoreId() fallback.
         *
         * WHY NOT getAdminAllSetting AT ALL, which took two attempts to get right and is the whole point of this comment:
         *   - getAdminAllSetting() with no arguments is the defect above.
         *   - getAdminAllSetting(null, $storeId) returns an EMPTY array, measured: the first branch still filters
         *     `created_by = $user->id`, so a foreign store id matches no rows.
         *   - getAdminAllSetting($store->created_by, $storeId) looked right and is ALSO wrong, found by the read-only review of this
         *     fix. `settings.created_by` is not the store's owner -- it is WHOEVER SAVED LAST: PaymentSettingController::saveSettings
         *     keys updateOrCreate on ['name','store_id'] only and rewrites `created_by` to auth()->user()->id (:162-173), and
         *     StoreSettingsAccess admits a STAFF member holding the permission. So for a store whose payment tab was last saved by
         *     staff, that pair matches no rows either.
         * The `created_by` limb buys nothing here in any case: every settings writer already scopes `store_id` to its own tenant, so
         * a row carrying this store's id can only have been written by an insider of this store. Dropping it is exactly the predicate
         * the canonical per-store reader uses (Utility::GetValueByName, app/Models/Utility.php:181-196) and that Setting::paymentList
         * and the admin API already use for these same keys -- so the storefront's "SSLCommerz is enabled" and this read now agree.
         * StorelessCredentialReadTest pins every form side by side.
         *
         * Resolved from getRouteStoreId() and not from $store: the line below overwrites this method's own $slug parameter with the
         * request body's `slug`, which the checkout form does not post, so $store is null on the legitimate flow. With no resolvable
         * store (a job, a console run) the ternary falls back to the previous behaviour, which is the right answer there.
         *
         * SCOPE, stated so the next reader is not misled: because of that same $slug overwrite the method dies at the CURRENCY_NAME
         * read below for EVERY caller unless the body carries `slug`, so this correction is live only for that request shape -- which
         * is also the only shape that reaches the gateway, and the shape the original defect needed. Fixing the overwrite is a
         * separate change (it moves currency resolution) and is recorded on #99, not done here. Note also that credentials now come
         * from the PATH store while the currency and success_url still come from the BODY store; previously the pair was (caller's
         * store, body store).
         */
        $sec26Store = \App\Models\Store::find(getRouteStoreId());
        // This is the money-bearing one of the three: sslcommerz_pay_store_id and sslcommerz_pay_secret_key below are posted to the
        // gateway as store_id/store_passwd, so the wrong row here creates the payment against the signed-in merchant's OWN account.
        $payment_setting        = $sec26Store
            ? \App\Models\Setting::where('store_id', $sec26Store->id)->pluck('value', 'name')->toArray()
            : getAdminAllSetting();
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        
        $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
        $currency = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);

        $orderId = $request->customer_id . date('YmdHis');
        $cartlist_final_price = $request->cartlist_final_price;

        $totalprice = str_replace(' ', '', str_replace(',', '', str_replace($currency, '', $cartlist_final_price)));
        $requestData = $request->except('attachment','payment_receipt');
        Session::put('request_data', $requestData);
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentStore($slug, $request);
        }
        $user = \Auth::user();
        
        // `?? ''` because these two were the only unguarded dereferences of the array (their siblings all use isset), so an empty
        // read 500'd here instead of failing the gateway call. Defensive either way; the store-scoped read above is the actual fix.
        $sslcommerz_pay_store_id = $payment_setting['sslcommerz_pay_store_id'] ?? '';
        $sslcommerz_pay_secret_key = $payment_setting['sslcommerz_pay_secret_key'] ?? '';

        $sslcommerz_data = [
            "store_id"      => $sslcommerz_pay_store_id,
            "store_passwd"  => $sslcommerz_pay_secret_key,
            "total_amount"  => $totalprice,
            "currency"      => $currency,
            "tran_id"       => $orderId,
            "success_url"   => route('store.payment.status', [
                'storeSlug' => $slug,
                'return_type' => 'success',
                'payment_processor' => 'SSLCommerz'
            ]),
            "fail_url"      => route('checkout', [
                'storeSlug' => $slug,
            ]),
            "cancel_url"    => route('checkout', [
                'storeSlug' => $slug,
            ]),
            "cus_name"          => $other_info['firstname'],
            "cus_email"         => $other_info['email'],
            "cus_add1"          => $other_info['billing_address'],
            "cus_city"          => '',
            "cus_country"       => '',
            "cus_phone"         => $other_info['billing_user_telephone'],
            "product_profile"   => 'product_purchase',
            "product_name"      => $request->product_name ?? 'Product',
            "product_category"  => 'General',
            "ship_name"         => $user->last_name ?? 'User',
            'ship_add1'         => 'Dhaka',
            'ship_city'         => 'Dhaka',
            'ship_postcode'     => '1000',
            'ship_country'      => 'Bangladesh',
            'shipping_method'   => 'yes'
        ];

        try {
            $url = $payment_setting['sslcommerz_mode'] == 'sandbox' ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php' : 
            'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php';


            $client = new Client();
            $response = $client->post($url, [
                'form_params' => $sslcommerz_data,
                'timeout' => 30,
                'connect_timeout' => 30,
                'verify' => false,
            ]);

            $body = $response->getBody();
            $sslcz = json_decode($body, true);

            if (isset($sslcz['status']) && $sslcz['status'] === "SUCCESS") {
                return redirect()->to($sslcz['GatewayPageURL']);
            } else {
                return redirect()->route('checkout', $slug)->with('error', __('Transaction has been failed.'));
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return redirect()->route('checkout', $slug)->with('error', __('Something went wrong, Please try again.'));
        }
    } 

    /**
     * Empty the cart lines an order was built from, AFTER it has committed.
     *
     * See the block at the accumulation site for why this is deferred rather
     * than compensated. Advisory by design: a cart row that will not delete must
     * never fail a checkout whose order is already committed and whose money has
     * already moved, so each line is attempted independently and a failure is
     * logged rather than thrown.
     */
    private function clearCartLines(array $lines): void
    {
        foreach ($lines as $line) {
            try {
                $query = Cart::where('product_id', $line['product_id'])
                    ->where('variant_id', $line['variant_id'])
                    ->where('store_id', $line['store_id']);

                // A customer's lines by id, a guest's by their cart cookie, neither -> nothing (never against 0).
                if (is_numeric($line['customer_id'] ?? null) && (int) $line['customer_id'] > 0) {
                    $query->forCustomer((int) $line['customer_id']);
                } elseif (is_string($line['cookie_session_id'] ?? null) && $line['cookie_session_id'] !== '') {
                    $query->forGuestCookie($line['cookie_session_id']);
                } else {
                    continue;
                }

                $query->delete();
            } catch (\Throwable $e) {
                \Log::error('Could not clear a cart line after a committed order', [
                    'product_id' => $line['product_id'] ?? null,
                    'variant_id' => $line['variant_id'] ?? null,
                    'store_id' => $line['store_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
