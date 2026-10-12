<?php

namespace App\Http\Controllers;

use App\Events\AddRewardClubPoint;
use App\Events\CustomerOrder;
use App\Events\GetProductStatus;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use App\Models\Store;
use App\Models\Coupon;
use App\Models\User;
use App\Models\Plan;
use App\Models\Utility;
use App\Models\Setting;
use App\Models\Product;
use App\Models\Cart;
use App\Models\City;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderTaxDetail;
use App\Models\OrderBillingDetail;
use App\Models\AppSetting;
use Stripe;
use App\Http\Controllers\CartController;
use Illuminate\Http\RedirectResponse;
use Session;
use App\Models\OrderNote;
use App\Models\OrderCouponDetail;
use App\Models\UserCoupon;
use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cookie;
use App\Events\AddAdditionalFields;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use App\Traits\ApiResponser;
use App\Http\Controllers\Api\ApiController;
use App\Models\ProductVariant;
use App\Models\TaxMethod;
use App\Facades\ModuleFacade as Module;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class PaymentController extends Controller
{
    use ApiResponser;
    protected $paymentService;

    /**
     * Server-authoritative tax resolution. See App\Services\OrderTaxResolver:
     * the tax component of a charged total is derived from the existing cart and
     * shipping-method computations and never read from client input.
     */
    private ?\App\Services\OrderTaxResolver $taxResolver = null;

    /** Exact-decimal reconciliation of a persisted total against its components. */
    private ?\App\Services\OrderTotalReconciler $totalReconciler = null;

    /** Derives an order's payment_status from a verification result. */
    private ?\App\Services\PaymentVerificationService $paymentVerifier = null;

    /** Atomic stock decrement, replacing the read-modify-write pattern. */
    private ?\App\Services\ProductStockService $stockService = null;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Lazily resolved so that `new PaymentController(...)` call sites and the
     * container both keep working without a constructor signature change.
     */
    private function taxResolver(): \App\Services\OrderTaxResolver
    {
        return $this->taxResolver ??= new \App\Services\OrderTaxResolver();
    }

    private function totalReconciler(): \App\Services\OrderTotalReconciler
    {
        return $this->totalReconciler ??= new \App\Services\OrderTotalReconciler();
    }

    private function paymentVerifier(): \App\Services\PaymentVerificationService
    {
        return $this->paymentVerifier ??= new \App\Services\PaymentVerificationService();
    }

    private function stockService(): \App\Services\ProductStockService
    {
        return $this->stockService ??= new \App\Services\ProductStockService();
    }

    /**
     * Decrement stock atomically, remembering it so it can be given back.
     *
     * One copy for all three order paths in this controller
     * (getProductStatus, getWhatsappUrl, whatsapp), which each carried an
     * identical read-modify-write decrement.
     *
     * @param  array  $applied  Accumulator of applied decrements, by reference.
     * @param  string $kind     'variant' or 'product'.
     * @param  mixed  $model    The loaded ProductVariant or Product.
     * @param  mixed  $qty      The cart quantity.
     * @return array|null  null on success; a `['message' => ...]` payload when
     *                     the caller must abandon the order. Any decrements
     *                     already applied in this cart are given back first.
     */
    private function decrementStockOrFail(array &$applied, string $kind, $model, $qty): ?array
    {
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

        if ($result['reason'] !== \App\Services\ProductStockService::INSUFFICIENT) {
            // Nothing to decrement (no quantity, or no row). Previously this
            // subtracted zero and saved, so carrying on matches the old
            // behaviour exactly.
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








public function pollPaymentStatus($paymentId)
{
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . config('services.paymera.username'), // أو حسب الستور
        'Accept'        => 'application/json',
    ])->get('https://egate-t.paymera.cc/api/payment-status/' . $paymentId);

    return $response->json();
}



public function handlePaymeraCallback(Request $request, string $orderId)
{
    $pending = Cache::get('paymera_pending_' . $orderId);

    if (!$pending) {
        return redirect()
            ->route('checkout', '')
            ->with('error', 'انتهت صلاحية جلسة الدفع');
    }

    /*
     * إذا الـ trigger عالج الدفع مسبقًا، لا ننشئ الطلب مرة ثانية.
     */
    if (($pending['processed'] ?? false) === true) {
        return redirect()
            ->route('order.complete', $pending['slug'])
            ->with('data', $pending['product_order_id'] ?? '');
    }

    /*
     * استخدم نفس شرط النجاح القديم الذي كان يعمل عندك.
     * لا تغيّر آلية التحقق الآن.
     */
    $statusData = $this->pollPaymentStatus(
        $pending['payment_id']
    );

    if (($statusData['status'] ?? null) != 'A') {
        return redirect()
            ->route('store.payment.status', [
                $pending['slug'],
                '&return_type=cancel&payment_processor=paymera',
            ]);
    }

    /*
     * VERIFY WHAT WE ARE BEING TOLD, BEFORE creating the order.
     *
     * The status test above is a bare `!= 'A'` and is the only check this
     * callback used to perform: it never compared a captured amount against the
     * order total and never verified any inbound signature, and then marked the
     * order Paid and created it. Verification now happens here, ahead of both.
     *
     * Fails closed: an unverifiable amount or signature returns the customer to
     * the payment-status page and creates nothing.
     */
    $verification = $this->verifyPaymeraPayment($pending, $statusData, $request->all(), $orderId);

    if (!$verification->allowsPaid()) {
        return redirect()
            ->route('store.payment.status', [
                $pending['slug'],
                '&return_type=cancel&payment_processor=paymera',
            ]);
    }

    $lock = Cache::lock(
        'paymera_lock_' . $orderId,
        10
    );

    if (!$lock->get()) {
        return redirect()
            ->route('checkout', $pending['slug'])
            ->with('error', 'الطلب قيد المعالجة');
    }

    try {
        $requestData = $pending['request_data'] ?? [];

        /*
         * هذا يحل مشكلة حفظها COD.
         */
        $requestData['payment_type'] = 'paymera';
        $requestData['slug'] = $pending['slug'];

        /*
         * The `payment_status = 'Paid'` that used to be set here is gone. The
         * order writer derives the status from the verification result instead,
         * which is recorded server-side (in the session, not in the request, so
         * it cannot be forged) and is single-use.
         */
        $this->paymentVerifier()->recordVerification(
            'paymera',
            $verification,
            $pending['expected_total'] ?? ($verification->expectedAmount ?? '')
        );

        Session::put('request_data', $requestData);

        /*
         * نستخدم Request الحالي بدل Request::create.
         */
        $request->merge($requestData);

        /*
         * مهم جدًا:
         * نعيد Response الخاصة بإنشاء الطلب مباشرة.
         */
        $response = $this->getProductStatus(
            $request,
            $pending['slug']
        );

        $latestOrder = Order::where(
            'store_id',
            $requestData['store_id'] ?? null
        )
            ->where('payment_type', 'paymera')
            ->latest('id')
            ->first();

        $pending['status'] = 'A';
        $pending['processed'] = true;
        $pending['order_id'] = $latestOrder?->id;
        $pending['product_order_id'] =
            $latestOrder?->product_order_id;

        Cache::put(
            'paymera_pending_' . $orderId,
            $pending,
            now()->addHours(2)
        );

        return $response;
    } finally {
        $lock->release();
    }
}


/**
 * Verify a Paymera payment: the captured amount, and the inbound signature.
 *
 * ONE copy, used by BOTH routed Paymera entry points — handlePaymeraCallback
 * (the customer's browser returning) and handlePaymeraTrigger (the gateway's
 * server-to-server poke). Both created orders and marked them paid on the same
 * unverified `status == 'A'` test, so verifying in only one of them would leave
 * the other open.
 *
 * The two unrouted copies, handlePaymeraCallback_backup and
 * handlePaymeraCallback00, are deliberately NOT wired in: they are dead code
 * reachable from no route, and "fixing" them in parallel would create a second
 * verification path to keep in step with this one.
 *
 * @param  array  $pending        The server-side pending-payment record.
 * @param  array  $statusData     The gateway's status response.
 * @param  array  $callbackPayload The inbound request parameters.
 * @param  string $reference      The Paymera payment reference, for the log.
 */
private function verifyPaymeraPayment(
    array $pending,
    array $statusData,
    array $callbackPayload,
    string $reference
): \App\Services\PaymentVerificationResult {
    $verifier = $this->paymentVerifier();

    // 1. The signature. BLOCKED on GATE G(4) — the field names, algorithm and
    //    secret delivery exist only in Paymera's integration document — so this
    //    returns "unverifiable" until it is configured. Unverifiable is treated
    //    as failure, not as success.
    $signature = $verifier->verifyPaymeraSignature($callbackPayload);

    if (!$signature->isVerified()) {
        if ($verifier->paymeraSignatureIsRequired()) {
            \Log::critical('Paymera callback REJECTED: inbound signature not verified', [
                'paymera_reference' => $reference,
                'store_id' => $pending['request_data']['store_id'] ?? null,
                'signature' => $signature->context(),
                'owner_action' => 'GATE G(4): supply the Paymera callback signature field '
                    . 'name, algorithm and secret delivery, or set '
                    . 'services.paymera.require_callback_signature to false to '
                    . 'restore the previous unverified behaviour deliberately.',
            ]);

            return $signature;
        }

        // Enforcement explicitly switched off by the owner. The callback
        // proceeds on the amount check alone, and says so every time.
        \Log::warning('Paymera callback signature NOT verified and not enforced', [
            'paymera_reference' => $reference,
            'signature' => $signature->context(),
        ]);
    }

    // 2. The captured amount against what this payment was initiated for.
    $amount = $verifier->verifyPaymeraCapture(
        $statusData,
        $pending['expected_total'] ?? null,
        $pending['amount_sent'] ?? null
    );

    if (!$amount->allowsPaid()) {
        \Log::critical('Paymera callback REJECTED: captured amount not verified', [
            'paymera_reference' => $reference,
            'store_id' => $pending['request_data']['store_id'] ?? null,
            'amount' => $amount->context(),
            'owner_action' => 'GATE G(4): supply the Paymera response field name that '
                . 'carries the captured amount (services.paymera.callback_amount_field).',
        ]);
    }

    return $amount;
}


public function handlePaymeraCallback_backup($orderId)
{
    $pending = Cache::get('paymera_pending_' . $orderId);

    if (!$pending) {
        return redirect()->route('checkout', $pending['slug'] ?? '')->with('error', 'انتهت صلاحية جلسة الدفع');
    }

    if ($pending['status'] === 'A') {
        return redirect()->route('store.payment.status', [$pending['slug'], '&return_type=success&payment_processor=paymera']);
    }

    $statusData = $this->pollPaymentStatus($pending['payment_id']);

    if (($statusData['status'] ?? null) == 'A') {
        $lock = Cache::lock('paymera_lock_' . $orderId, 10);

        if ($lock->get()) {
            try {
                $pending['status'] = 'A';
                Cache::put('paymera_pending_' . $orderId, $pending, now()->addHours(2));

                // ⭐ نرجّع بيانات الطلب للـ Session، مشان getProductStatus يشتغل متل ما هو بالضبط
                Session::put('request_data', $pending['request_data']);
                $freshRequest = Request::create('/', 'POST', $pending['request_data']);
                $this->getProductStatus($freshRequest, $pending['slug']);
            } finally {
                $lock->release();
            }
        }

        return redirect()->route('store.payment.status', [$pending['slug'], '&return_type=success&payment_processor=paymera']);
    }

    return redirect()->route('store.payment.status', [$pending['slug'] ?? '', '&return_type=cancel&payment_processor=paymera']);
}






public function handlePaymeraTrigger($orderId)
{
    $pending = Cache::get('paymera_pending_' . $orderId);
    if (!$pending) {
        return response()->json(['error' => 'Payment context not found'], 404);
    }

    if ($pending['status'] === 'A') {
        return response()->json(['status' => 'A', 'ok' => true]);
    }

    /*
     * Already processed — by this trigger or by the browser callback. Do not
     * create a second order. The `processed` flag the callback sets was never
     * set here, so a trigger followed by a callback created the order TWICE.
     */
    if (($pending['processed'] ?? false) === true) {
        return response()->json([
            'status' => 'A',
            'ok' => true,
            'product_order_id' => $pending['product_order_id'] ?? null,
        ]);
    }

    $statusData = $this->pollPaymentStatus($pending['payment_id']);

    if (($statusData['status'] ?? null) == 'A') {
        // Same verification as the browser callback, same single copy, before
        // anything is created. Fails closed.
        $verification = $this->verifyPaymeraPayment($pending, $statusData, request()->all(), $orderId);

        if (!$verification->allowsPaid()) {
            return response()->json([
                'status' => $statusData['status'] ?? 'unknown',
                'ok' => false,
                'error' => 'payment_not_verified',
            ], 422);
        }

        $lock = Cache::lock('paymera_lock_' . $orderId, 10);

        if ($lock->get()) {
            try {
                $pending['status'] = 'A';
                Cache::put('paymera_pending_' . $orderId, $pending, now()->addHours(2));

                $this->paymentVerifier()->recordVerification(
                    'paymera',
                    $verification,
                    $pending['expected_total'] ?? ($verification->expectedAmount ?? '')
                );

                Session::put('request_data', $pending['request_data']);
                $freshRequest = Request::create('/', 'POST', $pending['request_data']);
                $this->getProductStatus($freshRequest, $pending['slug']);

                // Close the double-processing window the callback relies on.
                $latestOrder = Order::where('store_id', $pending['request_data']['store_id'] ?? null)
                    ->where('payment_type', 'paymera')
                    ->latest('id')
                    ->first();

                $pending['processed'] = true;
                $pending['order_id'] = $latestOrder?->id;
                $pending['product_order_id'] = $latestOrder?->product_order_id;
                Cache::put('paymera_pending_' . $orderId, $pending, now()->addHours(2));
            } finally {
                $lock->release();
            }
        }
    }

    return response()->json(['status' => $statusData['status'] ?? 'unknown', 'ok' => true]);
}







public function handlePaymeraCallback00($orderId)
{
    $pending = Cache::get('paymera_pending_' . $orderId);

    if (!$pending) {
        return redirect()->route('checkout', $pending['slug'] ?? '')->with('error', 'انتهت صلاحية جلسة الدفع');
    }

    // Idempotency: إذا سبق وتفعّل عبر الـ trigger، ما نكرر
    if ($pending['status'] === 'A') {
        return redirect()->route('store.payment.status', [$pending['slug'], '&return_type=success&payment_processor=paymera']);
    }

    $statusData = $this->pollPaymentStatus($pending['payment_id']);

    if (($statusData['status'] ?? null) == 'A') {
        $pending['status'] = 'A';
        Cache::put('paymera_pending_' . $orderId, $pending, now()->addHours(2));

        // ⭐ هون لازم تستدعي نفس المنطق يلي بـ getProductStatus لإنشاء الـ Order فعلياً
        // مثال: $this->getProductStatus($pending['request_data'], $pending['slug'], $cartList);

        return redirect()->route('store.payment.status', [$pending['slug'], '&return_type=success&payment_processor=paymera']);
    }

    return redirect()->route('store.payment.status', [$pending['slug'] ?? '', '&return_type=cancel&payment_processor=paymera']);
}

public function handlePaymeraTrigger00($orderId)
{
    $pending = Cache::get('paymera_pending_' . $orderId);
    if (!$pending) {
        return response()->json(['error' => 'Payment context not found'], 404);
    }

    if ($pending['status'] === 'A') {
        return response()->json(['status' => 'A', 'ok' => true]); // مسبقاً متفعّل
    }

    $statusData = $this->pollPaymentStatus($pending['payment_id']);

    if (($statusData['status'] ?? null) == 'A') {
        $pending['status'] = 'A';
        Cache::put('paymera_pending_' . $orderId, $pending, now()->addHours(2));

        // نفس نقطة استدعاء إنشاء الطلب هون كمان (server-to-server، أهم من الـ callback)
        // $this->getProductStatus($pending['request_data'], $pending['slug'], $cartList);
    }

    return response()->json(['status' => $statusData['status'] ?? 'unknown', 'ok' => true]);
}











    public function processOrder(Request $request, $slug)
    {
 
        // Get store and admin info
        $store = getStore($slug);
        $user = User::where('id', $store->created_by)->first();
        $plan = ($user->type == 'admin') ? Plan::find($user->plan_id) : null;

        // Handle customer registration if needed
        $isGuest = !auth('customers')->check();
        $customer = $isGuest ? $this->handleCustomerRegistration($request, $store) : auth('customers')->user();
        
        if (!$customer) {
            return redirect()->back()->with('error', __('User not found.'));
        }

        // Process billing info
        $this->processBillingInfo($request, $customer->id, $store);
        
        // Validate payment type
        $validPaymentTypes = $this->getValidPaymentTypes();
        if (!in_array($request->payment_type, $validPaymentTypes)) {
            return redirect()->back()->with('error', __('Invalid payment method.'));
        }

        // Validate billing information
        $validationResult = $this->validateBillingInfo($request);
        if ($validationResult !== true) {
            return $validationResult;
        }

        // Get cart data
        $cartData = $this->getCartData($request, $store, $customer, $isGuest);
        if (isset($cartData['error'])) {
            return redirect()->back()->with('error', $cartData['error']);
        }
        
        $cartlist = $cartData['cartlist'];
        $cartlist_final_price = $cartData['cartlist_final_price'];

        // Process coupon
        $couponData = $this->processCoupon($request, $cartlist_final_price, $slug);
        $cartlist_final_price = $couponData['cartlist_final_price'];
        $coupon_price = $couponData['coupon_price'];
        $request->merge((array)$couponData);
        // Process shipping. The cart's server-computed tax is handed in so the
        // posted `tax_price` never reaches the total.
        $shippingData = $this->processShipping($request, $plan, $slug, $cartData['tax_price'] ?? 0);
        $delivery_price = $shippingData['delivery_price'];
        $tax_price = $shippingData['tax_price'];

        // Prepare final request data
        $this->prepareFinalRequestData($request, $cartlist, $cartlist_final_price, $delivery_price, $tax_price);

        // Process payment
        return $this->processPayment($request, $slug, $cartlist);
    }

    /**
     * Handle customer registration or login
     */
    private function handleCustomerRegistration(Request $request, $store)
    {
        $billing = is_string($request->billing_info) ? (array)json_decode($request->billing_info) : $request->billing_info;
        
        // Handle registration with account creation
        if (isset($request->register) && $request->register == 'on') {
            $validator = \Validator::make(
                $billing,
                [
                    'firstname' => 'required',
                    'lastname' => 'required',
                    'billing_address' => 'required',
                    'billing_country' => 'required',
                    // 'billing_state' => 'required',
                    // 'billing_city' => 'required',
                    'email' => 'required'
                ]
            );

            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }

            $customer = $this->createCustomer($billing, $store, true);
            $this->sendPasswordResetLink($billing['email'], $store);
            auth('customers')->login($customer);
            
            return $customer;
        }
        
        // Handle guest checkout
        $validator = \Validator::make(
            $billing,
            [
                'firstname' => 'required',
                'lastname' => 'required',
                'billing_address' => 'required',
                'billing_country' => 'required',
                // 'billing_state' => 'required',
                // 'billing_city' => 'required',
                'email' => 'required|email',
            ]
        );

        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        // Check if customer already exists
        $customer = Customer::where('email', $billing['email'])->first();
        
        if (!$customer) {
            $customer = $this->createCustomer($billing, $store, false);
        } else {
            $customer->last_active = date('Y-m-d');
            $customer->save();
        }
        
        return $customer;
    }

    /**
     * Create a new customer
     */
    private function createCustomer($billing, $store, $isRegistered = false)
    {
        $customerData = [
            'first_name' => $billing['firstname'],
            'last_name' => $billing['lastname'],
            'email' => $billing['email'],
            'register_type' => 'email',
            'type' => 'customer',
            'mobile' => !empty($billing['billing_user_telephone']) ? $billing['billing_user_telephone'] : '',
            'last_active' => date('Y-m-d'),
            'store_id' => $store->id,
            'created_by' => $store->created_by
        ];
        
        if ($isRegistered) {
            $customerData['regiester_date'] = date('Y-m-d');
            $customerData['password'] = Hash::make('1234');
        }
        
        $customer = Customer::create($customerData);
        
        if ($isRegistered) {
            ActivityLog::create([
                'customer_id' => $customer->id,
                'log_type' => 'register',
                'store_id' => $store->id,
            ]);
        }
        
        $this->addCustomerAddress($customer, $billing, $store);
        
        return $customer;
    }

    /**
     * Add customer address
     */
    private function addCustomerAddress($customer, $billing, $store)
    {
        $addressData = [
            'store_id' => $store->id,
            'slug' => $store->slug,
            'customer_id' => $customer->id,
            'default_address' => 1,
            'first_name' => $billing['firstname'],
            'address' => $billing['billing_address'],
            'country' => $billing['billing_country'],
            'state' => $billing['billing_state'],
            'city' => $billing['billing_city'],
            'postcode' => $billing['billing_postecode'] ?? '',
            'title' => strtolower($billing['firstname']),
        ];
        
        $request = new Request($addressData);
        $api = new ApiController();
        $api->add_address($request, $store->slug);
    }

    /**
     * Process billing information
     */
    private function processBillingInfo(Request &$request, $customerId, $store)
    {
        $param = [
            'customer_id' => $customerId,
            'slug' => $store->slug,
            'store_id' => $store->id
        ];
        
        // Convert billing info from string to array if needed
        if (isset($request->billing_info) && is_string($request->billing_info)) {
            $param['billing_info'] = (array)json_decode($request->billing_info);
            unset($request->billing_info);
        }
        if (!isset($request->delivery_not_same)) {
            $billing = is_string($request->billing_info) ? (array)json_decode($request->billing_info) : $request->billing_info;
            
            $param['billing_info'] = [
                'firstname' => $billing['firstname'],
                'lastname' => $billing['lastname'],
                'email' => $billing['email'],
                'billing_user_telephone' => $billing['billing_user_telephone'],
                'billing_address' => $billing['billing_address'],
                'billing_country' => $billing['billing_country'],
                'billing_state' => $billing['billing_state'],
                'billing_city' => $billing['billing_city'],
                'billing_postecode' => $billing['billing_postecode'] ?? '',
                'delivery_address' => $billing['billing_address'],
                'delivery_country' => $billing['billing_country'],
                'delivery_state' => $billing['billing_state'],
                'delivery_city' => $billing['billing_city'],
                'delivery_postcode' => $billing['billing_postecode'] ?? '',
            ];
        }
        // Handle delivery and billing being the same
        // if ($request->delivery_and_billing == 'on' || $request->delivery_and_billing == 'true' || $request->delivery_and_billing == true) {
        //     $billing = is_string($request->billing_info) ? (array)json_decode($request->billing_info) : $request->billing_info;
            
        //     $param['billing_info'] = [
        //         'firstname' => $billing['firstname'],
        //         'lastname' => $billing['lastname'],
        //         'email' => $billing['email'],
        //         'billing_user_telephone' => $billing['billing_user_telephone'],
        //         'billing_address' => $billing['billing_address'],
        //         'billing_country' => $billing['billing_country'],
        //         'billing_state' => $billing['billing_state'],
        //         'billing_city' => $billing['billing_city'],
        //         'billing_postecode' => $billing['billing_postecode'] ?? '',
        //         'delivery_address' => $billing['billing_address'],
        //         'delivery_country' => $billing['billing_country'],
        //         'delivery_state' => $billing['billing_state'],
        //         'delivery_city' => $billing['billing_city'],
        //         'delivery_postcode' => $billing['billing_postecode'] ?? '',
        //     ];
        // }

        $request->merge($param);
    }

    /**
     * Get valid payment types
     */
    private function getValidPaymentTypes()
    {
        return [
            'stripe', 'paystack', 'mercado', 'skrill', 'paymentwall', 'Razorpay', 'paypal',  'paymera', 
            'flutterwave', 'paytm', 'mollie', 'coingate', 'toyyibpay', 'Sspay', 'Paytabs', 
            'iyzipay', 'payfast', 'benefit', 'cashfree', 'aamarpay', 'telegram', 'whatsapp', 
            'paytr', 'yookassa', 'Xendit', 'midtrans', 'cod', 'bank_transfer', 'Nepalste', 
            'PayHere', 'khalti', 'AuthorizeNet', 'Tap', 'PhonePe', 'Paddle', 'Paiementpro', 
            'FedPay', 'CinetPay', 'SenagePay', 'CyberSource', 'Ozow', 'MyFatoorah', 'easebuzz', 
            'NMI', 'payu', 'Paynow', 'esewa', 'sofort', 'DPO', 'Braintree', 'PowerTranz', 'SSLCommerz'
        ];
    }

    /**
     * Validate billing information
     */
    private function validateBillingInfo(Request $request)
    {
        $billing = is_string($request->billing_info) ? (array)json_decode($request->billing_info) : $request->billing_info;
        
        $requiredFields = [
            'firstname' => 'Billing first name',
            'lastname' => 'Billing last name',
            'email' => 'Billing email',
            'billing_user_telephone' => 'Billing telephone',
            'billing_address' => 'Billing address',
            'billing_country' => 'Billing country',
            // 'billing_state' => 'Billing state',
            // 'billing_city' => 'Billing city',
            'delivery_address' => 'Delivery address',
            'delivery_country' => 'Delivery country',
            // 'delivery_state' => 'Delivery state',
            // 'delivery_city' => 'Delivery city',
        ];
        
        foreach ($requiredFields as $field => $message) {
            if (empty($billing[$field])) {
                return redirect()->back()->with('error', __($message . ' not found.'));
            }
        }
        
        return true;
    }

    /**
     * Get cart data
     */
    private function getCartData(Request $request, $store, $customer, $isGuest)
    {
        if ($isGuest) {
            // For guest users
            $response = Cart::cart_list_cookie($request->all(), $store->id);
            $response = json_decode(json_encode($response));
            $cartlist = (array)$response->data;
            
            if (empty($cartlist['product_list'])) {
                return ['error' => 'Cart is empty.'];
            }
            
            // Check quantity control if module is active
            if (module_is_active('CartQuantityControl')) {
                $qtyControlResult = $this->checkCartQuantityControl($cartlist);
                if ($qtyControlResult !== true) {
                    return ['error' => $qtyControlResult];
                }
            }
            
            return [
                'cartlist' => $cartlist,
                'cartlist_final_price' => !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0,
                'final_price' => $response->data->total_final_price,
                'tax_price' => !empty($cartlist['total_tax_price']) ? $cartlist['total_tax_price'] : 0,
                'products' => $cartlist['product_list']
            ];
        } else {
            // For authenticated customers
            $request->merge(['customer_id' => $customer->id]);
            
            $cartApi = new ApiController();
            $cartResponse = $cartApi->cart_list($request, $store->slug);
            $cartlist = (array)$cartResponse->getData()->data;
            
            if (empty($cartlist['product_list'])) {
                return ['error' => 'Cart is empty.'];
            }
            
            // Check quantity control if module is active
            if (module_is_active('CartQuantityControl')) {
                $qtyControlResult = $this->checkCartQuantityControl($cartlist);
                if ($qtyControlResult !== true) {
                    return ['error' => $qtyControlResult];
                }
            }
            
            // Process reward points if module is active
            $cartlist_final_price = $this->processRewardPoints($request, $store, $cartlist, $customer->id);
            
            return [
                'cartlist' => $cartlist,
                'cartlist_final_price' => $cartlist_final_price,
                'final_price' => $cartlist['total_final_price'],
                'tax_price' => $cartlist['total_tax_price'] ?? 0,
                'products' => $cartlist['product_list']
            ];
        }
    }

    /**
     * Check cart quantity control
     */
    private function checkCartQuantityControl($cartlist)
    {
        if (!module_is_active('CartQuantityControl')) {
            return true;
        }
        
        $responseQuantityControl = \Workdo\CartQuantityControl\app\Models\CartQuantityControl::minQtyCheckoutValidation($cartlist);
        if (!isset($responseQuantityControl) || empty($responseQuantityControl)) {
            return true;
        }
        
        $out_of_stock_Message = isset($responseQuantityControl['out_of_stock']) ? implode('<br>', $responseQuantityControl['out_of_stock']) : '';
        $min_quantity_Message = isset($responseQuantityControl['min_quantity']) ? implode(' , ', $responseQuantityControl['min_quantity']) : '';
        $max_quantity_Message = isset($responseQuantityControl['max_quantity']) ? implode(' , ', $responseQuantityControl['max_quantity']) : '';
        
        if (!empty($out_of_stock_Message) && !empty($min_quantity_Message)) {
            return 'The minimum allowed order quantity for <b>' . $min_quantity_Message . '</b> Please add more quantity. <br>' . $out_of_stock_Message;
        } elseif (!empty($out_of_stock_Message)) {
            return $out_of_stock_Message;
        } elseif (!empty($min_quantity_Message)) {
            return 'The minimum allowed order quantity for <b>' . $min_quantity_Message . '</b> Please add more quantity.';
        } elseif (!empty($max_quantity_Message)) {
            return 'The maximum allowed order quantity for <b>' . $max_quantity_Message . '</b> Please remove some quantity.';
        }
        
        return true;
    }

    /**
     * Process reward points
     */
    private function processRewardPoints(Request $request, $store, $cartlist, $customerId)
    {
        if (module_is_active('RewardClubPoint') && isset($request->club_point_is_active) && $request->club_point_is_active == 'on') {
            $customerDetail = Customer::find($customerId);
            $settings = getAdminAllSetting($store->created_by, $store->id) ?? null;
            $saveRewardPrice = ($customerDetail->total_club_point * $settings['reward_point_price'] ?? 0) / ($settings['reward_points'] ?? 0);
            return (!empty($cartlist['final_price']) ? $cartlist['final_price'] : 0) - $saveRewardPrice;
        }
        
        return !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
    }

    /**
     * Process coupon
     */
    private function processCoupon(Request $request, $cartlist_final_price, $slug)
    {
        $coupon_price = 0;
        if (empty($request['coupon_code'])) {
            return [
                'cartlist_final_price' => $cartlist_final_price,
                'coupon_price' => 0
            ];
        }
        
        if (isset($request['coupon_info']) && $request['coupon_info']) {
            $coupon_price = $request['coupon_info']['coupon_discount_amount'] ?? 0;
            return [
                'cartlist_final_price' => $cartlist_final_price,
                'coupon_price' => $coupon_price
            ];
        }
        
        $apply_coupon = [
            'coupon_code' => $request['coupon_code'] ?? null,
            'sub_total' => $cartlist_final_price ?? 0
        ];
        
        $request->request->add($apply_coupon);
        $coupon_apply = new ApiController();
        $apply_coupon_response = $coupon_apply->apply_coupon($request, $slug);
        $apply_coupon = (array)$apply_coupon_response->getData()->data;
        
        if (!empty($apply_coupon['final_price'])) {
            $cartlist_final_price = $apply_coupon['final_price'];
            $coupon_price = $apply_coupon['amount'];
        }
        
        return [
            'cartlist_final_price' => $cartlist_final_price,
            'coupon_price' => $coupon_price,
            'coupon_info' => (array)$apply_coupon['coupon_info']
        ];
    }

    /**
     * Process shipping
     */
    private function processShipping(Request $request, $plan, $slug, $serverTaxPrice = 0)
    {
        $delivery_price = 0;

        // The tax component of the charged total is SERVER-DERIVED. $serverTaxPrice
        // is the cart's own `total_tax_price`, computed by Cart::cart_list_cookie()
        // or ApiController::cart_list() in getCartData(); the posted `tax_price` is
        // discarded (it used to be read straight into the total here).
        $this->taxResolver()->recordClientSuppliedTax(
            $request->input('tax_price'),
            (float) $serverTaxPrice,
            'PaymentController::processShipping'
        );
        $tax_price = $this->taxResolver()->resolve(['total_tax_price' => $serverTaxPrice]);

        if (!$plan || $plan->shipping_method != 'on' || empty($request->method_id)) {
            return [
                'delivery_price' => $delivery_price,
                'tax_price' => $tax_price
            ];
        }

        $del_charge = new CartController();
        $delivery_charge = $del_charge->get_shipping_method($request, $slug);
        $content = $delivery_charge->getContent();
        $data = json_decode($content, true);

        return [
            'delivery_price' => $data['total_final_price'] ?? 0,
            // get_shipping_method's `final_tax_price` already includes the tax on
            // the shipping charge, so it supersedes the cart-only figure.
            'tax_price' => $this->taxResolver()->resolve(
                ['total_tax_price' => $serverTaxPrice],
                is_array($data) ? $data : null
            )
        ];
    }

    /**
     * Prepare final request data
     */
    private function prepareFinalRequestData(Request &$request, $cartlist, $cartlist_final_price, $delivery_price, $tax_price)
    {
        $new_array = [
            'shipping_final_price' => $delivery_price,
            'tax_price' => $tax_price,
            'cartlist_final_price' => $cartlist_final_price + $delivery_price + $tax_price,
            'cartlist' => $cartlist,
            'billing_info' => is_string($request->billing_info) ? (array)json_decode($request->billing_info) : $request->billing_info
        ];
        
        // Handle partial payments if module is active
        if (module_is_active('PartialPayments') && \Auth::guard('customers')->user()) {
            $store = Store::where('slug', $request->slug ?? '')->first();
            if ($store) {
                $settings = Setting::where('store_id', $store->id)
                    ->pluck('value', 'name')
                    ->toArray();
                
                if (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on') {
                    $param = [
                        'tax_price' => $new_array['tax_price'] ?? 0,
                        'shipping_price' => $new_array['shipping_final_price'] ?? 0,
                        'coupon_price' => $cartlist['total_coupon_price'] ?? 0,
                    ];
                    
                    $request->merge($param);
                    $updated_cart = \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::ManageDeposit($request, $store->slug);
                    
                    if ((!isset($request->partial_payment_type) || $request->partial_payment_type != 'pending_payment')) {
                        $new_array['cartlist_final_price'] = $updated_cart['deposite_amount'] ?? 0;
                        $new_array['shipping_final_price'] = $updated_cart['shipping_price_value'] ?? $new_array['shipping_final_price'];
                        $new_array['tax_price'] = $updated_cart['tax_price_value'] ?? $new_array['tax_price'];
                        $new_array['shipping_price'] = $updated_cart['shipping_price_value'] ?? $new_array['shipping_final_price'];
                        $new_array['coupon_price'] = $updated_cart['coupon_price_value'] ?? $cartlist['total_coupon_price'] ?? 0;
                        $new_array['order_pending_amount'] = $updated_cart['pending_amount'] ?? 0;
                    } else {
                        $new_array['cartlist_final_price'] = $request->payment_amount ?? 0;
                    }
                }
            }
        }
        
        $request->merge($new_array);
    }

    /**
     * Server-side deposit amounts for a PartialPayments checkout.
     *
     * The module's own `ManageDeposit()` is the one source of truth for what a
     * deposit is: it reads the Product rows and the store's deposit settings
     * and derives the figure. It is called here with the SERVER-derived tax,
     * shipping, coupon and subtotal merged in, so neither the deposit nor the
     * components it is computed from can come from the request.
     *
     * Nothing inside packages/workdo is modified; the existing module method is
     * called, exactly as prepareFinalRequestData() already calls it.
     *
     * @param  array $serverComponents The server-derived component amounts.
     * @param  mixed $fallbackTotal    The server total to use if the module
     *                                 cannot produce a figure. Never client input.
     * @return array{final_price: mixed, delivery_price: mixed}
     */
    private function resolvePartialPaymentAmounts(
        Request $request,
        $slug,
        array $serverComponents,
        $fallbackTotal
    ): array {
        $request->merge($serverComponents);

        try {
            $updated = \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::ManageDeposit(
                $request,
                $slug
            );
        } catch (\Throwable $e) {
            \Log::error('PartialPayments deposit recomputation failed; using the server total', [
                'site' => __METHOD__,
                'error' => $e->getMessage(),
            ]);
            $updated = null;
        }

        $depositAmount = is_array($updated) && isset($updated['deposite_amount'])
            && is_numeric($updated['deposite_amount'])
                ? $updated['deposite_amount']
                : $fallbackTotal;

        $shippingAmount = is_array($updated) && isset($updated['shipping_price_value'])
            && is_numeric($updated['shipping_price_value'])
                ? $updated['shipping_price_value']
                : ($serverComponents['shipping_price'] ?? 0);

        return [
            'final_price' => $depositAmount,
            'delivery_price' => $shippingAmount,
        ];
    }

    /**
     * Process payment
     */
    private function processPayment(Request $request, $slug, $cartlist)
    {
        $paymentMethod = $request->payment_type;
        $response = $this->paymentService->process($request, $paymentMethod, $slug, $cartlist);
        
        // Handle different response types based on payment method
        if (in_array($paymentMethod, ['paystack', 'Razorpay', 'paymentwall', 'flutterwave', 'paytm', 'telegram', 'paytr', 'midtrans', 'whatsapp', 'PayHere', 'khalti', 'AuthorizeNet'])) {
            if ($response instanceof \Illuminate\Http\RedirectResponse) {
                return new RedirectResponse($response->getTargetUrl());
            }
            return $response->render();
        } elseif (in_array($paymentMethod, ['skrill', 'Sspay', 'toyyibpay', 'Paytabs', 'cod', 'bank_transfer', 'Nepalste', 'Tap', 'PhonePe', 'Paddle', 'Paiementpro', 'FedPay', 'CinetPay', 'SenagePay', 'Ozow', 'easebuzz', 'esewa', 'Paynow', 'DPO', 'MyFatoorah', 'paymera'])) {
    if ($paymentMethod == 'paymera') {
    // تحقق هل $response هو كائن RedirectResponse (يعني هناك خطأ ويحاول العودة للتشيك أوت)
    if ($response instanceof \Illuminate\Http\RedirectResponse) {
        return $response; // أعد الكائن كما هو، سيتولى لارافيل التحويل بشكل صحيح
    }
    
    // إذا لم يكن كائناً، فمن المفترض أنه رابط نصي (string)
    return redirect(trim($response));
}

   if ($paymentMethod == 'Paytabs' ) {
                return redirect($response->getTargetUrl());
            }
            return new RedirectResponse($response->getTargetUrl());
        } elseif ($paymentMethod == 'coingate') {
            return new RedirectResponse($response->payment_url);
        } elseif (in_array($paymentMethod, ['payfast', 'CyberSource', 'NMI', 'payu', 'Braintree', 'PowerTranz', 'SSLCommerz'])) {
            return $response;
        } else {
            return new RedirectResponse($response);
        }
    }

    /**
     * Send password reset link
     */
    private function sendPasswordResetLink($email, $store)
    {
        try {
            $settings = Setting::where('store_id', $store->id)
                ->pluck('value', 'name')
                ->toArray();

            config([
                'mail.driver' => $settings['MAIL_DRIVER'] ?? env('MAIL_DRIVER'),
                'mail.host' => $settings['MAIL_HOST'] ?? env('MAIL_HOST'),
                'mail.port' => $settings['MAIL_PORT'] ?? env('MAIL_PORT'),
                'mail.encryption' => $settings['MAIL_ENCRYPTION'] ?? env('MAIL_ENCRYPTION'),
                'mail.username' => $settings['MAIL_USERNAME'] ?? env('MAIL_USERNAME'),
                'mail.password' => $settings['MAIL_PASSWORD'] ?? env('MAIL_PASSWORD'),
                'mail.from.address' => $settings['MAIL_FROM_ADDRESS'] ?? env('MAIL_FROM_ADDRESS'),
                'mail.from.name' => $settings['MAIL_FROM_NAME'] ?? env('MAIL_FROM_NAME'),
            ]);

            Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $th) {
            // Silently handle mail errors
        }
    }

    public function getProductStatus(Request $request, $slug)
    {
        $requests_data = Session::get('request_data');
        $store = getStore($slug);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);
        
        if ($requests_data['payment_type'] == 'PhonePe') {
            $phonepe = new \App\Libraries\PhonePe\PhonePe(
                $admin_payment_setting['phonepe_client_id'] ?? '',
                $admin_payment_setting['phonepe_client_secret'] ?? '',
                $admin_payment_setting['phonepe_mode'] ?? 'sandbox'
            );
            
            // Check payment status using order_id from session
            if (isset($requests_data['order_id'])) {
                $paymentStatus = $phonepe->checkStatus($requests_data['order_id']);
                if (isset($paymentStatus['success']) && $paymentStatus['success'] === false) {
                    return redirect()->route('checkout', $slug)->with('error', __('Payment failed. Please try again.'));
                }
                
                if (isset($paymentStatus['data']['state']) && $paymentStatus['data']['state'] !== 'COMPLETED') {
                    return redirect()->route('checkout', $slug)->with('error', __('Payment was not completed. Please try again.'));
                }

                if (isset($paymentStatus['state']) && $paymentStatus['state'] !== 'COMPLETED') {
                    return redirect()->route('checkout', $slug)->with('error', __('Payment was not completed. Please try again.'));
                }
            } else {
                return redirect()->route('checkout', $slug)->with('error', __('Order ID not found. Please try again.'));
            }
        }
        if($requests_data['payment_type'] == 'toyyibpay')
        {
            if ($request->status_id == 3)
            {
                return redirect()->route('checkout', $slug)->with('error', __('Your Transaction is fail please try again.'));
            }else if ($request->status_id == 2)
            {  
                return redirect()->route('checkout', $slug)->with('success', __('Your transaction on pending'));
            }
        }

        if ($requests_data['payment_type'] == 'Tap') {
            try {
                if (isset($request->tap_id)) {
                    $tap_secret_key = \App\Models\Utility::GetValueByName('tap_secret_key', $requests_data['store_id']);
                    $TapPay         = new \App\Package\Payment(['tap_secret_key' => $tap_secret_key]);
                    $tapPayStatus         = $TapPay->getCharge($request->tap_id);
                    if ($tapPayStatus->status != "CAPTURED") {
                        return redirect()->route('checkout', $slug)->with('error', __('Your Transaction is fail please try again.'));
                    }
                }
            } catch (\Exception $th) {
                return redirect()->route('checkout', $slug)->with('error', __('Your Transaction is fail please try again.'));
            }
        }

        if (is_array($requests_data)) {
            // Merge $requests_data into the request
            $request->merge($requests_data);
        }

        if (!isset($requests_data['coupon_info']) && isset($request->cartlist['coupon_info'])) {
            $requests_data['coupon_info'] = is_object($request->cartlist['coupon_info']) ? (array) $request->cartlist['coupon_info'] : $request->cartlist['coupon_info'];
        }

        if ($requests_data == null) {
            $requests_data = $request->all();
        }
        $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
        $store = getStore($slug);
        Session::forget('request_data');
        $customer_id = $requests_data['customer_id'] ?? '';

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
                        return $this->error(['message' => 'This coupon has expired.']);
                    }
                }
            }
        }
        if (!auth('customers')->user()) {
            $rules = [
                'billing_info' => 'required',
                'payment_type' => 'required',
                //'delivery_id' => 'required',
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
        // cart list api
        if (!auth('customers')->user()) {
            $response = Cart::cart_list_cookie($requests_data, $store->id ?? '');

            $response = json_decode(json_encode($response));
            $cartlist = (array) $response->data;
            if (empty($cartlist['product_list'])) {
                return $this->error(['message' => 'Cart is empty.']);
            }
            $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
            $final_sub_total_price = !empty($cartlist['total_sub_price']) ? $cartlist['total_sub_price'] : 0;
            $final_price = $response->data->total_final_price;
            // SERVER-DERIVED tax. Previously `$requests_data['tax_price']`, i.e.
            // whatever the customer posted at checkout, which went straight into
            // $order->tax_price and into the charged final_price below.
            $tax_price = $this->taxResolver()->resolve($cartlist);
            $this->taxResolver()->recordClientSuppliedTax(
                $requests_data['tax_price'] ?? null,
                $tax_price,
                __METHOD__ . '(guest)'
            );
            $billing = $requests_data['billing_info'];

            $products = $cartlist['product_list'];
        } elseif (!empty($customer_id)) {
            $cart_list['customer_id'] = $customer_id;
            if (is_array($requests_data)) {
                // Merge $requests_data into the request
                $request->merge($requests_data);
            }
            $cartt = new ApiController();
            $cartlist_response = $cartt->cart_list($request, $slug);
            $cartlist = (array) $cartlist_response->getData()->data;
            if (empty($cartlist['product_list'])) {
                return $this->error(['message' => 'Cart is empty.']);
            }

            $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
            $final_sub_total_price = !empty($cartlist['total_sub_price']) ? $cartlist['total_sub_price'] : 0;
            $final_price = $cartlist['total_final_price'];
            // SERVER-DERIVED tax — see the guest branch above.
            $tax_price = $this->taxResolver()->resolve($cartlist);
            $this->taxResolver()->recordClientSuppliedTax(
                $requests_data['tax_price'] ?? null,
                $tax_price,
                __METHOD__ . '(customer)'
            );
            $billing = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
            $products = $cartlist['product_list'];
        } else {
            return Utility::error(['message' => 'User not found.']);
        }

        $coupon_price = 0;
        // coupon api call
        if (!empty($requests_data['coupon_info'])) {
            $coupon_data = $requests_data['coupon_info'];
            $apply_coupon = [
                'coupon_code' => $coupon_data['coupon_code'],
                'sub_total' => $cartlist_final_price,
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
        } elseif (!empty($requests_data['cartlist']['coupon_info'])) {
            $coupon_data = is_object($requests_data['cartlist']['coupon_info']) ? (array) $requests_data['cartlist']['coupon_info'] : $requests_data['cartlist']['coupon_info'];

            $apply_coupon = [
                'coupon_code' => $coupon_data['coupon_code'],
                'sub_total' => $cartlist_final_price,
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

        $delivery_price = 0;
        if ($plan->shipping_method == 'on') {
            if (!empty($request->method_id)) {
                $del_charge = new CartController();
                $delivery_charge = $del_charge->get_shipping_method($request, $slug);
                $content = $delivery_charge->getContent();
                $data = json_decode($content, true);
                $delivery_price = $data['total_final_price'];
                // SERVER-DERIVED tax, from get_shipping_method's own
                // `final_tax_price` (shipping is taxable), falling back to the
                // cart tax. Previously `$requests_data['tax_price']`.
                $tax_price = $this->taxResolver()->resolve(
                    $cartlist,
                    is_array($data) ? $data : null
                );
            }
            // else {
            //     return $this->error(['message' => 'Shipping Method not found']);
            // }
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
        if (!empty($products)) {
            foreach ($products as $key => $product) {
                $prodduct_id_array[] = $product->product_id;

                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;

                $Product = Product::where('id', $product_id)->first();
                $datas = Product::find($product_id);
                if ($settings['stock_management'] ?? '' == 'on') {
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
                                if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'variant', $ProductStock, $qtyy)) {
                                    return $this->error($stockFailure);
                                }

                                if ($ProductStock->stock <= $ProductStock->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $ProductStock, $settings);
                                        }

                                    }
                                }
                                if ($ProductStock->stock <= $settings['out_of_stock_threshold']) {
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
                                if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'product', $datas, $qtyy)) {
                                    return $this->error($stockFailure);
                                }
                                if ($datas->product_stock <= $datas->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $datas, $settings);
                                        }

                                    }
                                }
                                if ($datas->product_stock <= $settings['out_of_stock_threshold']) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_out_of_stock($product, $datas, $settings);
                                        }
                                    }
                                }
                                if ($datas->product_stock <= $settings['out_of_stock_threshold'] && $datas->stock_order_status == 'notify_customer') {
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
                            if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'product', $Product, $qtyy)) {
                                return $this->error($stockFailure);
                            }
                            if ($Product->product_stock <= $Product->low_stock_threshold) {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::low_stock_threshold($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= $settings['out_of_stock_threshold']) {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::out_of_stock($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= $settings['out_of_stock_threshold'] && $Product->stock_order_status == 'notify_customer') {
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
                // remove from cart
                Cart::where('customer_id', $request->customer_id)->where('product_id', $product_id)->where('variant_id', $variant_id)->where('store_id', $store->id)->delete();
            }
        }
        if (isset($request->customer_id) && !empty($request->customer_id)) {
            // Cart::where('customer_id', $request->customer_id)->delete();
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
        $order->product_price = $final_price;
        $order->coupon_price = $coupon_price;
        $order->delivery_price = $delivery_price;
        $order->tax_price = $tax_price;
        if (!auth('customers')->user()) {
            if ($plan->shipping_method == "on") {
                $order->final_price = $final_sub_total_price;
            } else {
                $order->final_price = $final_price + $tax_price - $coupon_price;
            }
        } elseif (module_is_active('PartialPayments') && \Auth::guard('customers')->user() && (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on')) {
            // With this module active the TOTAL ITSELF used to come from the
            // client: final_price was `$requests_data['cartlist_final_price']`
            // and delivery_price was `$requests_data['shipping_price']`, both
            // request-derived. The deposit is now recomputed through the
            // module's OWN server-side computation — ManageDeposit() derives it
            // from Product rows and the store's deposit settings, which is the
            // same call prepareFinalRequestData() already makes — with the
            // server-derived components merged in first.
            $partialAmounts = $this->resolvePartialPaymentAmounts(
                $request,
                $slug,
                [
                    'tax_price' => $tax_price,
                    'shipping_price' => $delivery_price,
                    'coupon_price' => $coupon_price,
                    'total_sub_price' => $final_sub_total_price,
                ],
                $final_price + $tax_price - $coupon_price
            );

            $order->final_price = $partialAmounts['final_price'];
            $order->delivery_price = $partialAmounts['delivery_price'];
            \Workdo\PartialPayments\app\Models\OrderPartialPayments::OrderPartialPayments($order, $slug, $request);
        } else {
            if (module_is_active('RewardClubPoint') && isset($requests_data['club_point_is_active']) && $requests_data['club_point_is_active'] == 'on') {
                $customerDetail = Customer::find($customer_id);
                $rewardSetting = getAdminAllSetting($store->created_by, $store->id) ?? null;
                $saveRewardPrice = ($customerDetail->total_club_point * $rewardSetting['reward_point_price'] ?? 0) / ($rewardSetting['reward_points'] ?? 0);
                if ($plan->shipping_method == "on") {
                    $order->final_price = $final_sub_total_price - $saveRewardPrice;
                } else {
                    $order->final_price = $final_price + $tax_price - $saveRewardPrice;
                }
            } else {
                if ($plan->shipping_method == "on") {
                    $order->final_price = $final_sub_total_price;
                } else {
                    $order->final_price = $final_price + $tax_price - $coupon_price;
                }
            }
        }
        event(new AddAdditionalFields($order, $request->all(), $store));
        $order->payment_comment = !empty($requests_data['payment_comment']) ? $requests_data['payment_comment'] : '';
        $order->payment_type = $requests_data['payment_type'];
        // DERIVED, not asserted. This was the literal `'Paid'`, with nothing
        // between the customer's browser and this line having asked a gateway
        // whether any money moved. See App\Services\PaymentVerificationService
        // for what is enforced today and what is still preserved behaviour.
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
        $order->save();


/*
|--------------------------------------------------------------------------
| Store notification
|--------------------------------------------------------------------------
*/
\Log::info('Before StoreNotificationService', [
    'order_id' => $order->id,
    'store_id' => $order->store_id,
]);

$result = app(\App\Services\StoreNotificationService::class)
    ->orderCreated($order);

\Log::info('After StoreNotificationService', [
    'notification_id' => $result?->id,
]);






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
            if (isset($Coupon)) {
                $UserCoupon = new UserCoupon();
                $UserCoupon->user_id = !empty($requests_data['customer_id']) ? $requests_data['customer_id'] : null;
                $UserCoupon->coupon_id = $Coupon->id;
                $UserCoupon->amount = $coupon_data['coupon_final_amount'];
                $UserCoupon->order_id = $order->id;
                $UserCoupon->date_used = now();
                
                $UserCoupon->save();
            }

            $discount_string = '-' . $coupon_data['coupon_discount_amount'];
            $CURRENCY = Utility::GetValueByName('CURRENCY', $store->id);
            $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME', $store->id);
            if ($coupon_data['coupon_discount_type'] == 'flat') {
                $discount_string .= $CURRENCY;
            } else {
                $discount_string .= '%';
            }

            $discount_string .= ' ' . __('for all products');
            $order_array['coupon']['code'] = $coupon_data['coupon_code'];
            $order_array['coupon']['discount_string'] = $discount_string;
            $order_array['coupon']['price'] = SetNumber($coupon_data['coupon_final_amount']);
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
        if (isset($requests_data['tax_id_value'])) {
            $taxes = TaxMethod::where('tax_id', $request['tax_id_value'])->where('store_id', $store->id)->orderBy('priority', 'asc')->get();
            $other_info = !is_array($requests_data['billing_info']) ? json_decode($requests_data['billing_info']) : $requests_data['billing_info'];
            $country = !empty($other_info->delivery_country) ? $other_info->delivery_country : '';
            $state_id = !empty($other_info->delivery_state) ? $other_info->delivery_state : '';
            $city_id = !empty($other_info->delivery_city) ? $other_info->delivery_city : '';
            foreach ($taxes as $tax) {
                $countryMatch = (!$tax->country_id || $country == $tax->country_id);
                $stateMatch = (!$tax->state_id || $state_id == $tax->state_id);
                $cityMatch = (!$tax->city_id || $city_id == $tax->city_id);

                if ($countryMatch && $stateMatch && $cityMatch) {
                    $OrderTaxDetail = new OrderTaxDetail();
                    $OrderTaxDetail->order_id = $order->id;
                    $OrderTaxDetail->product_order_id = $order->product_order_id;
                    $OrderTaxDetail->tax_id = $tax->id;
                    $OrderTaxDetail->tax_name = $tax->name;
                    $OrderTaxDetail->tax_discount_amount = $tax->tax_rate;
                    // The tax ledger row records the SERVER-DERIVED tax, the same
                    // figure persisted on the order, not the posted one.
                    $OrderTaxDetail->tax_final_amount = $tax_price;

                    $OrderTaxDetail->save();
                }
            }
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
        $other_info = $request->billing_info;

        //Order Mail
        $order_email = $OrderBillingDetail->email ?? (!empty($other_info->email) ? $other_info->email : '');
        $owner = User::find($store->created_by);
        $owner_email = $owner->email;
        $order_id = Crypt::encrypt($order->id);

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
        // add in Order Tax Detail table end
        event(new GetProductStatus($OrderBillingDetail, $order, $owner));
        if (auth('customers')->user() && module_is_active('ProductAffiliate')) {
            Utility::affiliateTransaction($order);
        }
        if (!empty($order) && !empty($OrderBillingDetail)) {

            // $order_array['order_id'] = $order->id;
            // $cart_array = [];
            // $cart_json = json_encode($cart_array);
            // Cookie::queue('cart', $cart_json, 1440);
            $cart = Cookie::get('cart');
            $cart = Cart::where('cookie_session_id', $cart)->delete();
            if (auth('customers')->user()) {
                event(new AddRewardClubPoint($requests_data, $order, $slug));
            }

            return redirect()->route('order.complete', $slug)->with('data', $order->product_order_id);
        } else {
            return $this->error(['message' => 'Somthing went wrong.']);
        }
    }

    public function getWhatsappUrl(Request $request, $slug)
    {
        $store = getStore($slug);
        $requests_data = Session::get('request_data');
        $telegram_access_token = \App\Models\Utility::GetValueByName('telegram_access_token', $store->id);
        $telegram_chat_id = \App\Models\Utility::GetValueByName('telegram_chat_id', $store->id);
        $whatsapp_number = \App\Models\Utility::GetValueByName('whatsapp_number', $store->id);
        $cartlist_final_price = 0;
        $final_price = 0;
        $customer_id = $requests_data['customer_id'] ?? 0;

        // cart list api call
        if (!auth('customers')->user()) {
            $response = Cart::cart_list_cookie($request->all(), $store->id);
            $response = json_decode(json_encode($response));
            $cartlist = (array) $response->data;

            if (empty($cartlist['product_list'])) {
                return $this->error(['message' => 'Cart is empty.']);
            }

            $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
            $final_price = $cartlist['final_price'] - $cartlist['tax_price'];
            $billing = (isset($requests_data['billing_info']) && is_string($requests_data['billing_info'])) ? json_decode($requests_data['billing_info'], true) : ($requests_data['billing_info'] ?? []);

            $taxes = !empty($cartlist['tax_info']) ? $cartlist['tax_info'] : '';
            $products = $cartlist['product_list'];
        } elseif (!empty($customer_id)) {
            $cart_list['customer_id'] = $customer_id;
            if (is_array($requests_data)) {
                // Merge $requests_data into the request
                $request->merge($requests_data);
            }
            $cartt = new ApiController();
            $cartlist_response = $cartt->cart_list($request, $slug);
            $cartlist = (array) $cartlist_response->getData()->data;

            if (empty($cartlist['product_list'])) {
                return Utility::error(['message' => 'Cart is empty.']);
            }

            $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
            $final_price = $cartlist['final_price'] - $cartlist['tax_price'];
            $billing = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
            // $billing = $request->billing_info;
            $taxes = !empty($cartlist['tax_info']) ? $cartlist['tax_info'] : '';
            $products = $cartlist['product_list'];
        } else {
            return Utility::error(['message' => 'User not found.']);
        }

        $prodduct_id_array = [];
        // Stock decrements applied in this loop, so they can be given back if
        // a later product in the same cart cannot be fulfilled and the order is
        // therefore never written. Without this, a rejected checkout would
        // silently consume the inventory of the products it got through first.
        $appliedStockChanges = [];
        if (!empty($products)) {
            foreach ($products as $key => $product) {
                $prodduct_id_array[] = $product->product_id;

                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;

                $Product = Product::where('id', $product_id)->first();
                if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {
                    $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();
                    if (!empty($ProductStock)) {
                        // ATOMIC. This was a read-modify-write (`$remain_stock = $ProductStock->stock - $qtyy; ... ->save()`),
                        // i.e. a lost update: two concurrent checkouts both wrote
                        // `stock - qty` and the second overwrote the first, so the shop
                        // oversold by the quantity it processed concurrently, and the
                        // subtraction could also drive a SIGNED stock column negative.
                        if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'variant', $ProductStock, $qtyy)) {
                            return $this->error($stockFailure);
                        }
                        $pro_qty[] = $product->qty . ' x ' . $product->name . '-' . $product->variant_name;

                        $lists[] = array(
                            'quantity' => $product->qty,
                            'product_name' => $product->name,
                            'variant_name' => $product->variant_name,
                            'item_total' => $product->final_price * $product->qty,
                        );
                    } else {
                        return Utility::error(['message' => 'Product not found .']);
                    }
                } elseif (!empty($product_id) && $product_id != 0) {
                    if (!empty($Product)) {
                        // ATOMIC. This was a read-modify-write (`$remain_stock = $Product->product_stock - $qtyy; ... ->save()`),
                        // i.e. a lost update: two concurrent checkouts both wrote
                        // `stock - qty` and the second overwrote the first, so the shop
                        // oversold by the quantity it processed concurrently, and the
                        // subtraction could also drive a SIGNED stock column negative.
                        if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'product', $Product, $qtyy)) {
                            return $this->error($stockFailure);
                        }
                        $pro_qty[] = $product->qty . ' x ' . $product->name;

                        $lists[] = array(
                            'quantity' => $product->qty,
                            'product_name' => $product->name,
                            'item_total' => $product->final_price * $product->qty,
                        );

                    } else {
                        return Utility::error(['message' => 'Product not found .']);
                    }
                } else {
                    return Utility::error(['message' => 'Please fill proper product json field .']);
                }
                Cart::where('customer_id', $request->customer_id)->where('product_id', $product_id)->where('variant_id', $variant_id)->where('store_id', $store->id)->delete();
            }
        }
        $item_variable = '';
        $qty_total = 0;
        $sub_total = 0;
        $total_tax = 0;
        $tax_price = 0;
        if (!empty($taxes)) {
            foreach ($taxes as $key => $tax) {
                $tax_price += $tax->tax_price;
            }
        }
        $delivery_price = 0;
        // dilivery api call
        $whatsapp_item_variable = \App\Models\Utility::GetValueByName('whatsapp_item_variable', $store->id);
        foreach ($lists as $l) {
            $arrList = [
                'quantity' => $l['quantity'],
                'product_name' => $l['product_name'],
                'item_total' => ($l['item_total']),
            ];

            if (isset($l['variant_name']) && !empty($l['variant_name'])) {
                $arrList['variant_name'] = $l['variant_name'];
            }

            $resp = Utility::replaceVariable($whatsapp_item_variable, $arrList);
            $resp = str_replace('-  ', '', $resp);
            $item_variable .= $resp . PHP_EOL;

            $qty_total = $qty_total + $l['quantity'];
            $sub_total += $l['item_total'] * $l['quantity'];
        }
        $total_price = (floatval($requests_data['cartlist_final_price']));
        $other_info = !is_array($requests_data['billing_info']) ? json_decode($requests_data['billing_info']) : $requests_data['billing_info'];
        $arr = [
            'store_name' => $store->name,
            'order_no' => !empty($request['data']['order_id']) ? $request['data']['order_id'] : '1',
            'customer_name' => !empty($other_info->firstname) ? $other_info->firstname : '-',
            'billing_address' => !empty($other_info->billing_address) ? $other_info->billing_address : '-',
            'billing_country' => !empty($other_info->billing_country) ? $other_info->billing_country : '-',
            'billing_city' => !empty($other_info->billing_city) ? $other_info->billing_city : '-',
            'billing_postalcode' => !empty($other_info->billing_postecode) ? $other_info->billing_postecode : '-',
            'shipping_address' => !empty($other_info->delivery_address) ? $other_info->delivery_address : '-',
            'shipping_country' => !empty($other_info->delivery_country) ? $other_info->delivery_country : '-',
            'shipping_city' => !empty($other_info->delivery_city) ? $other_info->delivery_city : '-',
            'shipping_postalcode' => !empty($other_info->delivery_postcode) ? $other_info->delivery_postcode : '-',
            'item_variable' => $item_variable,
            'qty_total' => $qty_total,
            'sub_total' => ($sub_total),
            'shipping_amount' => (!empty($delivery_price) ? $delivery_price : '0'),
            'total_tax' => ($tax_price),
            'final_total' => $total_price,
        ];
        $settings = getAdminAllSetting();
        $resp = Utility::replaceVariable(($settings['whatsapp_content'] ?? null), $arr) ?? null;

        if ($request['data']['type'] == 'telegram') {
            $msg = $resp;
            // Set your Bot ID and Chat ID.
            $telegrambot = $telegram_access_token;
            $telegramchatid = $telegram_chat_id;

            // Function call with your own text or variable
            $url = 'https://api.telegram.org/bot' . $telegrambot . '/sendMessage';
            $data = array(
                'chat_id' => $telegramchatid,
                'text' => $msg,
            );
            $options = array(
                'http' => array(
                    'method' => 'POST',
                    'header' => "Content-Type:application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($data),
                ),
            );
            $context = stream_context_create($options);
            $result = file_get_contents($url, false, $context);
            $url = $url;
        } else {
            $url = 'https://api.whatsapp.com/send?phone=' . $whatsapp_number . '&text=' . urlencode(string: $resp);
        }
        $new_order_id = str_replace('#', '', $request->order_id);
        return $url;
    }

    

    public function whatsapp(Request $request, $slug)
    {
        $requests_data = (Session::get('request_data'));

        if ($requests_data == null) {
            $requests_data = $request->all();
        }
        $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
        $store = getStore($slug);

        Session::forget('request_data');
        $customer_id = $requests_data['customer_id'] ?? '';

        if (!empty($requests_data['method_id'])) {

            $request['method_id'] = $requests_data['method_id'];
        }
        $user = Cache::remember('admin_details', 3600, function () {
            return User::where('type','admin')->first();
        });
        if ($user->type == 'admin') {
            $plan = Plan::find($user->plan_id);
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
                        return $this->error(['message' => 'This coupon has expired.']);
                    }
                }
            }
        }
        if (!auth('customers')->user()) {
            $rules = [
                'billing_info' => 'required',
                'payment_type' => 'required',
                //'delivery_id' => 'required',
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
        // cart list api
        if (!auth('customers')->user()) {

            $response = Cart::cart_list_cookie($requests_data, $store->id);

            $response = json_decode(json_encode($response));
            $cartlist = (array) $response->data;
            if (empty($cartlist['product_list'])) {
                return $this->error(['message' => 'Cart is empty.']);
            }
            $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
            $final_sub_total_price = !empty($cartlist['total_sub_price']) ? $cartlist['total_sub_price'] : 0;
            $final_price = $response->data->total_final_price;
            // SERVER-DERIVED tax. Previously `$requests_data['tax_price']`, i.e.
            // whatever the customer posted at checkout, which went straight into
            // $order->tax_price and into the charged final_price below.
            $tax_price = $this->taxResolver()->resolve($cartlist);
            $this->taxResolver()->recordClientSuppliedTax(
                $requests_data['tax_price'] ?? null,
                $tax_price,
                __METHOD__ . '(guest)'
            );
            $billing = $requests_data['billing_info'];

            $products = $cartlist['product_list'];
        } elseif (!empty($customer_id)) {
            $cart_list['customer_id'] = $customer_id;
            if (is_array($requests_data)) {
                // Merge $requests_data into the request
                $request->merge($requests_data);
            }
            $cartt = new ApiController();
            $cartlist_response = $cartt->cart_list($request, $slug);
            $cartlist = (array) $cartlist_response->getData()->data;
            if (empty($cartlist['product_list'])) {
                return $this->error(['message' => 'Cart is empty.']);
            }

            $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
            $final_sub_total_price = !empty($cartlist['total_sub_price']) ? $cartlist['total_sub_price'] : 0;
            $final_price = $cartlist['total_final_price'];
            // SERVER-DERIVED tax — see the guest branch above.
            $tax_price = $this->taxResolver()->resolve($cartlist);
            $this->taxResolver()->recordClientSuppliedTax(
                $requests_data['tax_price'] ?? null,
                $tax_price,
                __METHOD__ . '(customer)'
            );
            $billing = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
            $products = $cartlist['product_list'];
        } else {
            return Utility::error(['message' => 'User not found.']);
        }

        $coupon_price = 0;
        // coupon api call
        if (!empty($requests_data['coupon_info'])) {
            $coupon_data = $requests_data['coupon_info'];
            $apply_coupon = [
                'coupon_code' => $coupon_data['coupon_code'],
                'sub_total' => $cartlist_final_price,
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
        } elseif (!empty($requests_data['cartlist']['coupon_info'])) {
            $coupon_data = is_object($requests_data['cartlist']['coupon_info']) ? (array) $requests_data['cartlist']['coupon_info'] : $requests_data['cartlist']['coupon_info'];

            $apply_coupon = [
                'coupon_code' => $coupon_data['coupon_code'],
                'sub_total' => $cartlist_final_price,
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

        $delivery_price = 0;
        if ($plan->shipping_method == 'on') {
            if (!empty($request->method_id)) {
                $del_charge = new CartController();
                $delivery_charge = $del_charge->get_shipping_method($request, $slug);
                $content = $delivery_charge->getContent();
                $data = json_decode($content, true);
                $delivery_price = $data['total_final_price'];
                // SERVER-DERIVED tax, from get_shipping_method's own
                // `final_tax_price` (shipping is taxable), falling back to the
                // cart tax. Previously `$requests_data['tax_price']`.
                $tax_price = $this->taxResolver()->resolve(
                    $cartlist,
                    is_array($data) ? $data : null
                );
            }
            // else {
            //     return $this->error(['message' => 'Shipping Method not found']);
            // }
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
        if (!empty($products)) {
            foreach ($products as $key => $product) {
                $prodduct_id_array[] = $product->product_id;

                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;

                $Product = Product::where('id', $product_id)->first();
                $datas = Product::find($product_id);
                if ($settings['stock_management'] ?? '' == 'on') {
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
                                if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'variant', $ProductStock, $qtyy)) {
                                    return $this->error($stockFailure);
                                }

                                if ($ProductStock->stock <= $ProductStock->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $ProductStock, $settings);
                                        }

                                    }
                                }
                                if ($ProductStock->stock <= $settings['out_of_stock_threshold']) {
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
                                if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'product', $datas, $qtyy)) {
                                    return $this->error($stockFailure);
                                }
                                if ($datas->product_stock <= $datas->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $datas, $settings);
                                        }

                                    }
                                }
                                if ($datas->product_stock <= $settings['out_of_stock_threshold']) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_out_of_stock($product, $datas, $settings);
                                        }
                                    }
                                }
                                if ($datas->product_stock <= $settings['out_of_stock_threshold'] && $datas->stock_order_status == 'notify_customer') {
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
                            if ($stockFailure = $this->decrementStockOrFail($appliedStockChanges, 'product', $Product, $qtyy)) {
                                return $this->error($stockFailure);
                            }
                            if ($Product->product_stock <= $Product->low_stock_threshold) {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::low_stock_threshold($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= $settings['out_of_stock_threshold']) {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::out_of_stock($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= $settings['out_of_stock_threshold'] && $Product->stock_order_status == 'notify_customer') {
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
                // remove from cart
                Cart::where('customer_id', $request->customer_id)->where('product_id', $product_id)->where('variant_id', $variant_id)->where('store_id', $store->id)->delete();
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
        $order->product_price = $final_sub_total_price;
        $order->coupon_price = $coupon_price;
        $order->delivery_price = $delivery_price;
        $order->tax_price = $tax_price;
        if (!auth('customers')->user()) {
            if ($plan->shipping_method == "on") {
                $order->final_price = $final_price + $delivery_price;
            } else {
                $order->final_price = $final_price;
            }

        } else {
            if (module_is_active('RewardClubPoint') && isset($requests_data['club_point_is_active']) && $requests_data['club_point_is_active'] == 'on') {
                $customerDetail = Customer::find($customer_id);
                $rewardSetting = getAdminAllSetting($store->created_by, $store->id) ?? null;
                $saveRewardPrice = ($customerDetail->total_club_point * $rewardSetting['reward_point_price'] ?? 0) / ($rewardSetting['reward_points'] ?? 0);
                if ($plan->shipping_method == "on") {
                    $order->final_price = $final_price + $delivery_price - $saveRewardPrice;
                } else {
                    $order->final_price = $final_price - $saveRewardPrice;
                }
            } else {
                if ($plan->shipping_method == "on") {
                    $order->final_price = $final_price + $delivery_price;
                } else {
                    $order->final_price = $final_price;
                }
            }
        }
        $order->payment_comment = !empty($requests_data['payment_comment']) ? $requests_data['payment_comment'] : '';
        $order->payment_type = $requests_data['payment_type'];
        // DERIVED, not asserted. This was the literal `'Paid'`, with nothing
        // between the customer's browser and this line having asked a gateway
        // whether any money moved. See App\Services\PaymentVerificationService
        // for what is enforced today and what is still preserved behaviour.
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
        $order->save();
        // add in  Order table end
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentOrderStore($order, $slug);
        }
        // Utility::paymentWebhook($order);

        $billing_city_id = 0;
        if (!empty($billing['billing_city'])) {
            $cityy = City::where('name', $billing['billing_city'])->first();
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

        $delivery_city_id = 0;
        if (!empty($billing['delivery_city'])) {
            $d_cityy = City::where('name', $billing['delivery_city'])->first();
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
        event(new AddAdditionalFields($order, $request->all(), $store));
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
            $UserCoupon->user_id = !empty($requests_data['customer_id']) ? $requests_data['customer_id'] : null;
            $UserCoupon->coupon_id = $Coupon->id;
            $UserCoupon->amount = $coupon_data['coupon_discount_amount'];
            $UserCoupon->order_id = $order->id;
            $UserCoupon->date_used = now();
            
            $UserCoupon->save();

            $discount_string = '-' . $coupon_data['coupon_discount_amount'];
            $CURRENCY = Utility::GetValueByName('CURRENCY', $store->id);
            $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME', $store->id);
            if ($coupon_data['coupon_discount_type'] == 'flat') {
                $discount_string .= $CURRENCY;
            } else {
                $discount_string .= '%';
            }

            $discount_string .= ' ' . __('for all products');
            $order_array['coupon']['code'] = $coupon_data['coupon_code'];
            $order_array['coupon']['discount_string'] = $discount_string;
            $order_array['coupon']['price'] = SetNumber($coupon_data['coupon_final_amount']);
        } elseif (!empty($requests_data['cartlist']['coupon_code'])) {
            $coupon_data = is_object($requests_data['cartlist']['coupon_info']) ? (array) $requests_data['cartlist']['coupon_info'] : $requests_data['cartlist']['coupon_info'];
            $Coupon = Coupon::find($coupon_data['coupon_id']);
            // coupon stock decrease end
            if ($Coupon) {
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
        if (isset($requests_data['tax_id_value'])) {
            $taxes = TaxMethod::where('tax_id', $request['tax_id_value'])->where('store_id', $store->id)->orderBy('priority', 'asc')->get();
            $other_info = !is_array($requests_data['billing_info']) ? json_decode($requests_data['billing_info']) : $requests_data['billing_info'];
            $country = !empty($other_info->delivery_country) ? $other_info->delivery_country : '';
            $state_id = !empty($other_info->delivery_state) ? $other_info->delivery_state : '';
            $city_id = !empty($other_info->delivery_city) ? $other_info->delivery_city : '';
            foreach ($taxes as $tax) {
                $countryMatch = (!$tax->country_id || $country == $tax->country_id);
                $stateMatch = (!$tax->state_id || $state_id == $tax->state_id);
                $cityMatch = (!$tax->city_id || $city_id == $tax->city_id);

                if ($countryMatch && $stateMatch && $cityMatch) {
                    $OrderTaxDetail = new OrderTaxDetail();
                    $OrderTaxDetail->order_id = $order->id;
                    $OrderTaxDetail->product_order_id = $order->product_order_id;
                    $OrderTaxDetail->tax_id = $tax->id;
                    $OrderTaxDetail->tax_name = $tax->name;
                    $OrderTaxDetail->tax_discount_amount = $tax->tax_rate;
                    // The tax ledger row records the SERVER-DERIVED tax, the same
                    // figure persisted on the order, not the posted one.
                    $OrderTaxDetail->tax_final_amount = $tax_price;

                    $OrderTaxDetail->save();
                }
            }
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
        $other_info = $request->billing_info;

        //Order Mail
        $order_email = $OrderBillingDetail->email ?? (!empty($other_info->email) ? $other_info->email : '');
        $owner = User::find($store->created_by);
        $owner_email = $owner->email;
        $order_id = Crypt::encrypt($order->id);

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
        // add in Order Tax Detail table end
        event(new GetProductStatus($OrderBillingDetail, $order, $owner));
        if (auth('customers')->user() && module_is_active('ProductAffiliate')) {
            Utility::affiliateTransaction($order);
        }
        if (!empty($order) && !empty($OrderBillingDetail)) {

            $order_array['order_id'] = $order->id;
            $cart_array = [];
            $cart_json = json_encode($cart_array);
            Cookie::queue('cart', $cart_json, 1440);

            if (auth('customers')->user()) {
                event(new AddRewardClubPoint($requests_data, $order, $slug));
            }
            $msg = [
                'status' => 'success',
                'success' => __('Your Order Successfully Added'),
                'order_id' => $order->product_order_id,
                'data' => [
                    'order_id' => $order->id,
                ],
            ];
            return $msg;
        } else {
            return response()->json(
                [
                    'status' => 'error',
                    'success' => __('Failed'),
                ]
            );
        }
    }
}