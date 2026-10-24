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
use App\Models\DeliveryAddress;
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
use App\Http\Controllers\Concerns\GuardsGatewayReturn;

class PaymentController extends Controller
{
    use ApiResponser;

    /*
     * P01-COMMERCE-SEC-02. The stock decrement/give-back pair moved verbatim to a Concern so a
     * fourth order writer (NMIPayController) could use it instead of a second copy. No body
     * changed; see App\Http\Controllers\Concerns\AppliesCartStockChanges.
     */
    use \App\Http\Controllers\Concerns\AppliesCartStockChanges;
    use GuardsGatewayReturn;
    /* P1-ORD-02: the payable of a `pending_payment` checkout comes from the recorded balance. */
    use \App\Http\Controllers\Concerns\ResolvesPendingPartialPayment;
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

    /**
     * Check that a line is available WITHOUT changing any stock.
     *
     * NEW-whatsapp-url-anonymous-stock-decrement.
     *
     * `getWhatsappUrl()` (POST /{slug}/get-massage, name `get.whatsappurl`)
     * used `decrementStockOrFail()`, i.e. it permanently decremented stock. Two
     * facts make that indefensible:
     *
     *   1. The route sits in the routes/customer.php group whose only
     *      middleware is [ActiveTheme, themelanguage] — no authentication and
     *      no throttle — so ANY anonymous caller could POST a cart to it,
     *      repeatedly, and drive a store's inventory to zero. Nothing is
     *      created in exchange: `getWhatsappUrl()` builds a wa.me/Telegram URL
     *      and returns a string. It writes no Order; `new Order()` does not
     *      appear anywhere in it.
     *
     *   2. Even for a legitimate shopper it DOUBLE-COUNTED. The WhatsApp
     *      checkout page calls both endpoints —
     *      resources/views/payment/whatsapp.blade.php:42 posts to
     *      `user.whatsapp` and :84 posts to `get.whatsappurl` — and
     *      `whatsapp()` decrements the same lines again when it writes the
     *      order. So one WhatsApp order consumed each line's stock twice.
     *
     * Because this path creates no order, there is nothing for a decrement here
     * to correspond to, and no reservation record or release anywhere that
     * would make it a hold. It is therefore removed rather than guarded, which
     * is a correctness fix and not a product decision: inventory is still
     * consumed, once, by `whatsapp()`, which is the path that actually writes
     * the order.
     *
     * The availability CHECK is kept so the shopper-visible behaviour is
     * unchanged — an out-of-stock cart still gets the same error, at the same
     * point, with the same message. Only the write is gone.
     *
     * @return array|null null when the line may be ordered; an error payload
     *                    otherwise.
     */
    private function assertStockAvailable(string $kind, $model, $qty): ?array
    {
        if ($model === null || !is_numeric($qty)) {
            return null;
        }

        $quantity = (int) $qty;

        if ($quantity <= 0) {
            return null;
        }

        // Only a product that forbids ordering below stock can fail here; a
        // backorder product is orderable at any quantity, exactly as in
        // ProductStockService.
        if (($model->stock_order_status ?? null) !== 'not_allow') {
            return null;
        }

        $available = (int) ($kind === 'variant'
            ? ($model->stock ?? 0)
            : ($model->product_stock ?? 0));

        if ($available >= $quantity) {
            return null;
        }

        return ['message' => __('Insufficient stock. Please review your cart and try again.')];
    }

    /**
     * PRE-FLIGHT availability check for a WHOLE cart, before anything is
     * written and before the shopper is handed to a gateway.
     *
     * WHY THIS EXISTS, AND WHY IT IS THE ONLY PLACE A REFUSAL BELONGS
     * ---------------------------------------------------------------
     * `decrementStockOrFail()` above takes `bool $rejectOnShortfall = true`, and
     * ALL NINE of its production call sites pass FALSE
     * (PaymentController:1721, :1746, :1803, :2739, :2764, :2821 and
     * PaymentService:2322, :2347, :2404). That is correct and must stay correct:
     * each of those sites sits inside a product loop that has already deleted
     * the cart rows of the lines before it, has not yet reached `new Order()`,
     * and on the payment-return paths runs AFTER the gateway captured the money.
     * Refusing there abandoned a paid checkout with no order row, a partly
     * destroyed cart and nothing refunded — strictly worse than overselling.
     *
     * So the rejecting behaviour has to live somewhere a refusal is free. That
     * is here: before the destructive loop, before any cart row is deleted, and
     * before `PaymentService::process()` sends the shopper to a gateway. Nothing
     * is written and nothing is destroyed by this method — it only reads.
     *
     * WHAT IT DOES NOT DO, deliberately
     * ---------------------------------
     *  - It does NOT refuse a malformed line or a missing product row. The
     *    destructive loop has its own shopper-visible answers for those
     *    ('Product not found .', 'Please fill proper product json field .') and
     *    those answers stay the loop's to give, so this check cannot change an
     *    existing response.
     *  - It does NOT run when the store has stock management off, so it can
     *    never refuse a merchant who does not track stock.
     *  - It only ever refuses a product whose `stock_order_status` is
     *    'not_allow', which is the platform's existing marker for "do not sell
     *    when out of stock" (App\Models\Cart, ApiController, and
     *    ProductStockService::mustNotOversell()). A backorder product is still
     *    orderable at any quantity.
     *
     * It is therefore a STRICT SUBSET of what the destructive loop would check,
     * which is what makes it safe to add to a live checkout.
     *
     * @param  mixed      $products The cart's product_list (objects or arrays).
     * @param  mixed      $store    The store the cart belongs to.
     * @param  array|null $settings Already-loaded store settings, when the
     *                              caller has them, so this costs no extra query.
     * @return array|null null when every line may be ordered; an error payload
     *                    naming the first unavailable line otherwise.
     */
    private function assertCartAvailable($products, $store, ?array $settings = null): ?array
    {
        if (empty($products)) {
            return null;
        }

        $stockManaged = $settings === null
            ? $this->stockManagementIsOn($store)
            : (($settings['stock_management'] ?? null) === 'on');

        if (!$stockManaged) {
            return null;
        }

        foreach ($products as $line) {
            $productId = $this->cartLineValue($line, 'product_id');
            $variantId = $this->cartLineValue($line, 'variant_id');
            $qty = $this->cartLineValue($line, 'qty');

            if (empty($productId) || !is_numeric($qty) || (int) $qty <= 0) {
                continue;
            }

            $product = Product::where('id', $productId)->first();

            if ($product === null) {
                continue;
            }

            $kind = 'product';
            $row = $product;

            if (!empty($variantId) && (int) $variantId !== 0) {
                $variant = ProductVariant::where('id', $variantId)
                    ->where('product_id', $productId)
                    ->first();

                if ($variant === null) {
                    continue;
                }

                // The SAME test the destructive loop uses to decide which row
                // holds a variant line's stock: with 'manage_stock' among the
                // variation options the variant's own `stock` is decremented,
                // otherwise the parent product's `product_stock` is. Checking the
                // other row would make this guard disagree with the loop it
                // guards.
                if (in_array('manage_stock', explode(',', (string) $variant->variation_option), true)) {
                    $kind = 'variant';
                    $row = $variant;
                }
            }

            if ($this->assertStockAvailable($kind, $row, $qty) !== null) {
                return [
                    'message' => __('Insufficient stock. Please review your cart and try again.'),
                    'product_name' => $product->name ?? null,
                    'product_id' => $product->id,
                ];
            }
        }

        return null;
    }

    /**
     * Whether this store tracks stock at all.
     *
     * THE PRECEDENCE BUG THIS DOCBLOCK USED TO REPORT IS NOW FIXED, BY OWNER DECISION.
     * The note is kept rather than deleted, because the reasoning for deferring it is the
     * reasoning the owner had to overrule, and the next reader needs both halves.
     *
     * WHAT WAS WRONG. Twelve sites tested the setting as
     * `$settings['stock_management'] ?? '' == 'on'`. `??` binds LOOSER than `==`, so PHP parses
     * that as `$settings['stock_management'] ?? ('' == 'on')` — i.e. `... ?? false`. Whenever the
     * key EXISTS its own VALUE becomes the condition, and `'off'` is a non-empty string, so it is
     * truthy. A store with stock management OFF therefore had its stock decremented on every one
     * of those paths.
     *
     * AND THE COMMON CASE IS 'off', WHICH IS WHY THIS MATTERED MORE THAN THE ORIGINAL NOTE
     * ALLOWED. `Utility.php:111` ships `'stock_management' => 'off'` as the platform default, and
     * `SettingController:1094` writes `'off'` whenever the merchant's checkbox is unticked. So the
     * key is PRESENT with value `'off'` for essentially every store that has ever saved its
     * settings — which is the exact case the bug mistook for "on".
     *
     * That this was a precedence slip and not a decision was always visible from the two writers
     * that get it right: `PaymentService:2332` and `NMIPayController:580` both use
     * `isset(...) && ... == 'on'`, and this method uses `(... ?? null) === 'on'`.
     *
     * WHY IT WAS DEFERRED, AND WHAT THE OWNER DECIDED. Correcting it STOPS inventory being
     * consumed for every merchant who has the setting off — a merchant-facing behaviour change
     * rather than a reliability fix, so it was reported and left. The owner has now approved the
     * correction with the behaviour stated explicitly: OFF must not automatically deduct stock.
     * All twelve sites now use the same explicit, correctly grouped test as this method.
     *
     * EXACTLY ONE TRANSITION CHANGES. Absent key: no deduction before (the `?? false` limb) and
     * none now. Value `'on'`: deducted before and now. Value present but not `'on'`: deducted
     * before, NOT deducted now. That single transition is the fix.
     *
     * This pre-flight has always used the correct test, so it could only ever apply to FEWER
     * carts than the loop; the two now agree, which removes a second, quieter inconsistency —
     * a store with the setting off was pre-flighted as unmanaged and then decremented anyway.
     */
    private function stockManagementIsOn($store): bool
    {
        if (empty($store) || empty($store->id)) {
            return false;
        }

        return Setting::where('store_id', $store->id)
            ->where('name', 'stock_management')
            ->value('value') === 'on';
    }

    /** Read a field from a cart line, which may be an object or an array. */
    private function cartLineValue($line, string $key)
    {
        if (is_object($line)) {
            return $line->{$key} ?? null;
        }

        if (is_array($line)) {
            return $line[$key] ?? null;
        }

        return null;
    }

    /**
     * The shopper-visible text for a pre-flight refusal.
     *
     * The base sentence is the one `decrementStockOrFail()` and
     * `assertStockAvailable()` already use, so a shopper who hits the shortfall
     * at either point reads the same thing; the product name is appended only
     * when there is one, because naming the line is the difference between an
     * actionable message and a dead end.
     */
    private function stockErrorMessage(array $unavailable): string
    {
        $message = $unavailable['message'] ?? __('Insufficient stock. Please review your cart and try again.');
        $name = $unavailable['product_name'] ?? null;

        return empty($name) ? $message : $message . ' (' . $name . ')';
    }










/**
 * Sentinel returned by {@see pollPaymentStatus()} when the status call could
 * not be made authentically. It is deliberately NOT a status value: the caller
 * must branch on it explicitly rather than have "no answer" fall through the
 * `status != 'A'` test and look like an ordinary decline.
 */
public const PAYMERA_STATUS_UNAUTHENTICATED = '__paymera_status_unauthenticated';

/**
 * The bearer credential for Paymera's status API, or null when there isn't one.
 *
 * THE CONFIGURATION-SOURCE DEFECT, now corrected.
 *
 * This read `config('services.paymera.username')` and nothing else. That key
 * has never existed in any deployment -- it dates to the initial commit
 * (3c39c160) -- so the credential was always null, the poll always abandoned,
 * and PAYMERA CHECKOUT COULD NEVER COMPLETE. Not "was broken recently": could
 * not complete, ever.
 *
 * Meanwhile the credential the merchant actually fills in is a PER-STORE
 * SETTING, not an env var:
 *
 *     Utility::GetValueByName('PAYMERA_USERNAME', $store->id)
 *
 * which is what PaymentService::processPaymera() already uses to authorise the
 * CREATE-payment call (PaymentService.php:340, :428), what the settings screen
 * writes (PaymentSettingController.php:177-178 lists PAYMERA_TERMINAL_ID and
 * PAYMERA_USERNAME as Paymera's required_fields) and what Setting.php:1059-1074
 * reads back per store. A single global env var cannot express a per-store
 * credential on a shared-database multi-tenant platform, so the env key was
 * never going to be right.
 *
 * So the SOURCE is corrected: the per-store setting first, the global config key
 * only as a fallback for a single-tenant deployment that had set it. No
 * credential value is invented, written or printed here, and no live credential
 * is changed -- this reads the value the merchant already saved.
 *
 * WHY THIS CANNOT BY ITSELF CAUSE A PAID ORDER, which is the question a reviewer
 * should ask of any change to a payment path. The callback verifies in this
 * order: poll (here), then verifyPaymeraPayment() -- amount AND signature.
 * `services.paymera.require_callback_signature` defaults TRUE and the signature
 * scheme is unconfigured (GATE G(4)), so verifyPaymeraSignature() returns
 * UNVERIFIABLE and the callback is still REJECTED after this. The net effect of
 * this change is therefore that the status poll now authenticates instead of
 * sending an empty bearer token. It does not open checkout.
 *
 * NOT VERIFIED, and not claimed to be: nothing here establishes that Paymera
 * accepts this credential as a bearer token, or that PAYMERA_USERNAME is the
 * right field for the status API rather than only for the create call. No
 * Paymera host is reachable from this container. The flag
 * `services.paymera.status_credential_from_store_setting` exists so the owner
 * can switch this back to the old global-only lookup without a code change.
 *
 * Never logs or returns the credential's value -- only whether one is present.
 *
 * @param mixed $storeId The store the payment belongs to. Null falls back to the
 *                       global key only, which is the pre-existing behaviour.
 */
private function paymeraStatusCredential($storeId = null): ?string
{
    $credential = null;

    if (!empty($storeId) && (bool) config('services.paymera.status_credential_from_store_setting', true)) {
        $credential = \App\Models\Utility::GetValueByName('PAYMERA_USERNAME', $storeId);
    }

    if (!is_string($credential) || trim($credential) === '') {
        // Fallback for a single-tenant deployment that set the env var, and the
        // only source when no store is in scope.
        $credential = config('services.paymera.username');
    }

    if (!is_string($credential) || trim($credential) === '') {
        return null;
    }

    return trim($credential);
}

/**
 * Ask Paymera what happened to a payment.
 *
 * FAILS CLOSED. With no credential configured this makes no call at all and
 * returns the unauthenticated sentinel, so a missing credential can never be
 * mistaken for a successful capture. The previous behaviour — send an empty
 * bearer token and trust the reply — is gone.
 *
 * THE GATEWAY HOST. `egate-t.paymera.cc` is Paymera's TEST gateway and it was
 * HARDCODED here, so there was no way to point production at the live gateway
 * without editing this line. It now comes from
 * `services.paymera.status_base_url`, whose default is that exact host, so
 * behaviour is unchanged until the owner sets the key. Which host is correct for
 * production is Paymera's to say and is NOT guessed.
 *
 * @param mixed $storeId The store whose per-store credential to use. See
 *                       paymeraStatusCredential().
 */
public function pollPaymentStatus($paymentId, $storeId = null)
{
    $credential = $this->paymeraStatusCredential($storeId);

    if ($credential === null) {
        \Log::critical('Paymera status poll ABANDONED: no API credential is configured', [
            'payment_id' => $paymentId,
            'store_id' => $storeId,
            'sources_tried' => [
                'per-store setting PAYMERA_USERNAME',
                'config services.paymera.username',
            ],
            'note' => 'Neither source holds a credential, so the header would have been '
                . 'sent as an empty bearer token and the reply to an unauthenticated '
                . 'request treated as authoritative. No value is logged.',
        ]);

        return ['status' => self::PAYMERA_STATUS_UNAUTHENTICATED];
    }

    try {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $credential,
            'Accept'        => 'application/json',
        ])->timeout(30)->get(
            rtrim(
                (string) config('services.paymera.status_base_url', 'https://egate-t.paymera.cc'),
                '/'
            ) . '/api/payment-status/' . $paymentId
        );
    } catch (\Throwable $e) {
        // An unreachable gateway is not a successful payment.
        \Log::error('Paymera status poll failed', [
            'payment_id' => $paymentId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);

        return ['status' => self::PAYMERA_STATUS_UNAUTHENTICATED];
    }

    if (!$response->successful()) {
        \Log::error('Paymera status poll returned an unsuccessful response', [
            'payment_id' => $paymentId,
            'http_status' => $response->status(),
        ]);

        return ['status' => self::PAYMERA_STATUS_UNAUTHENTICATED];
    }

    $body = $response->json();

    return is_array($body) ? $body : ['status' => self::PAYMERA_STATUS_UNAUTHENTICATED];
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
        /*
         * P1-ORD-13 criterion 3. THE REPLAY BRANCH USED TO HAND THE ORDER TO WHOEVER PRESENTED THE
         * REFERENCE. `GET /store/paymera/callback/{orderId}` is anonymous and the reference is
         * `uniqid('paymera_')` -- about a million candidates per known second -- and the URL sits in
         * the buyer's history, the server log and any Referer. So a stranger replaying it was shown
         * the buyer's order number and, through the thank-you page, a minted link to the order.
         *
         * The number is now flashed only to the session that PLACED that order. A stranger gets the
         * same completion redirect with nothing flashed, which the thank-you page answers by
         * sending them to the landing page -- it does not tell them whether the reference was real.
         */
        $mine = \App\Services\OrderCompleteBinding::holds($pending['order_id'] ?? null);

        if (! $mine) {
            \Log::warning('Paymera replay refused: the session presenting the reference did not place the order', [
                'paymera_reference' => $orderId,
            ]);
        }

        return redirect()
            ->route('order.complete', $pending['slug'])
            ->with('data', $mine ? ($pending['product_order_id'] ?? '') : '');
    }

    /*
     * استخدم نفس شرط النجاح القديم الذي كان يعمل عندك.
     * لا تغيّر آلية التحقق الآن.
     */
    // The store is passed so the PER-STORE credential is used. See
    // paymeraStatusCredential(): the global config key it used to read alone has
    // never existed in any deployment.
    $statusData = $this->pollPaymentStatus(
        $pending['payment_id'],
        $pending['request_data']['store_id'] ?? null
    );

    /*
     * FAIL CLOSED on "we could not ask". An unauthenticated, unreachable or
     * unsuccessful status call is NOT a decline and must not be allowed to
     * look like one: it is the absence of an answer, and no order is created.
     * Branched on explicitly so this can never silently become the `!= 'A'`
     * path again. See pollPaymentStatus() and NEW-paymera-poll-unauthenticated.
     */
    if (($statusData['status'] ?? null) === self::PAYMERA_STATUS_UNAUTHENTICATED) {
        \Log::critical('Paymera callback REJECTED: the payment status could not be established', [
            'paymera_reference' => $orderId,
        ]);

        return redirect()
            ->route('store.payment.status', [
                $pending['slug'],
                '&return_type=cancel&payment_processor=paymera',
            ]);
    }

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
        /*
         * RE-CHECK UNDER THE LOCK. See freshPaymeraPendingOrNull(): the
         * pre-lock test above and this use of the record straddled the lock,
         * so a concurrent trigger that finished first did not stop this one
         * from creating a second order.
         */
        $fresh = $this->freshPaymeraPendingOrNull($orderId);

        if ($fresh === null) {
            // Already processed, or the record is gone. Either way this request
            // must not create an order. Answering with the same completion
            // redirect the pre-lock branch above uses keeps the customer's
            // experience identical to a replay that lost the race by a wider
            // margin. (`$pending` here is only read for display values; it is
            // never used to WRITE anything, which is the whole point.)
            // P1-ORD-13: same rule as the pre-lock replay branch above -- the number is flashed
            // only to the session that placed the order.
            return redirect()
                ->route('order.complete', $pending['slug'])
                ->with('data', \App\Services\OrderCompleteBinding::holds($pending['order_id'] ?? null)
                    ? ($pending['product_order_id'] ?? '')
                    : '');
        }

        $pending = $fresh;

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
        /*
         * P1-ORD-13 criterion 3. WHICH ORDER DID THIS CALLBACK JUST CREATE?
         *
         * It used to ask for "the latest paymera order in this store"
         * (`->where('payment_type','paymera')->latest('id')->first()`), which is a GUESS: two
         * concurrent Paymera payments in one store each record the OTHER buyer's number, and the
         * record is what the replay branch above hands back.
         *
         * `getProductStatus()` ends at the order writer, which records the order's primary key
         * against THIS session (OrderCompleteBinding::remember), so the answer comes from this very
         * request rather than from a global ordering. The buyer's browser IS the caller here, so
         * the binding stays -- that is what makes their own thank-you page resolvable. The store
         * and payment type are still checked as defence in depth.
         *
         * `orderWrittenBy()` rather than `end(bound())`: the review of the first cut showed that
         * `end()` names an EARLIER order of the same session when the writer creates nothing, which
         * writes the wrong order into the pending record. The before/after difference cannot.
         */
        $response = null;
        $createdId = $this->orderWrittenBy(function () use (&$response, $request, $pending) {
            $response = $this->getProductStatus($request, $pending['slug']);
        }, true);

        $latestOrder = $createdId === null ? null : Order::where('id', $createdId)
            ->where('store_id', $requestData['store_id'] ?? null)
            ->where('payment_type', 'paymera')
            ->first();

        if ($latestOrder === null) {
            \Log::warning('Paymera callback could not identify the order it created; the pending record names none', [
                'paymera_reference' => $orderId,
                'store_id' => $requestData['store_id'] ?? null,
            ]);
        }

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
 * Re-read the pending-payment record now that the lock is held.
 *
 * THE DEFECT THIS CLOSES: a replayed callback created a SECOND ORDER.
 *
 * Both routed Paymera entry points tested `$pending['processed']` BEFORE taking
 * the lock, and then, inside the lock, went on using the `$pending` array they
 * had read before it. So the check and the use straddled the lock instead of
 * both being inside it:
 *
 *   gateway trigger            browser callback
 *   ---------------            ----------------
 *   read pending (processed=false)
 *                              read pending (processed=false)
 *   check passes
 *                              check passes
 *   acquire lock
 *   create order
 *   processed = true
 *   release lock
 *                              acquire lock   <-- succeeds, nothing stops it
 *                              create order   <-- SECOND ORDER, second stock
 *                                                 decrement, second wallet
 *                                                 credit
 *
 * A gateway retrying its callback is normal, not exceptional, and the two entry
 * points are a browser return and a server-to-server poke for the SAME payment,
 * so they race by design rather than by accident. The pre-lock check is a
 * cheap-path optimisation and is kept; this is the authoritative one, and it
 * returns the record to actually use so a caller cannot keep the stale copy by
 * mistake.
 *
 * Returns null when the payment has already been processed, which the caller
 * must treat as "done, do not create anything".
 */
private function freshPaymeraPendingOrNull(string $orderId): ?array
{
    $pending = Cache::get('paymera_pending_' . $orderId);

    if (!is_array($pending)) {
        // The record expired while we waited for the lock. Creating an order
        // from a stale copy of it is exactly what must not happen.
        \Log::warning('Paymera pending record vanished before the order was created', [
            'paymera_reference' => $orderId,
        ]);

        return null;
    }

    if (($pending['processed'] ?? false) === true) {
        \Log::info('Paymera callback replay ignored: this payment already produced an order', [
            'paymera_reference' => $orderId,
            'order_id' => $pending['order_id'] ?? null,
        ]);

        return null;
    }

    return $pending;
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






/**
 * Run the order writer and name the order it created -- for a caller who may not be the buyer.
 *
 * P1-ORD-13 FOLLOW-UP (tenant-authz review, B1/N2/N4). Two separate defects, one cause.
 *
 * (a) WHO HOLDS THE BINDING. `OrderCompleteBinding::remember()` lives inside the shared order
 *     writer (`getProductStatus()`), and `remember()` writes through the `session()` helper, which
 *     resolves THE CURRENT HTTP REQUEST'S session -- not the synthetic `Request::create(...)` the
 *     trigger hands the writer. The Paymera trigger
 *     (`POST /store/paymera/trigger/{orderId}`, routes/web.php:514) is ANONYMOUS and CSRF-exempt
 *     (bootstrap/app.php:373); it is a server-to-server URL given to the gateway at initiation. So
 *     an anonymous caller who POSTs it with a live `paymera_<uniqid>` reference -- a value that is
 *     in the buyer's history, the access log and any Referer, which is the threat model the replay
 *     refusal above is written against -- had the VICTIM'S order bound into THEIR OWN session, and
 *     could then read the number and the minted `/order/<encrypted>` link off the thank-you page
 *     with no query string at all. A machine caller is not a buyer and must keep no binding.
 *
 * (b) WHICH ORDER. `end(bound())` is "the most recently first-seen id in this session", which is
 *     the new order only when a new order was written. If the writer returns without creating one
 *     (its own failure arm), `end()` is an EARLIER order from the same session's earlier checkout,
 *     the store and payment-type checks both pass, and the pending record is written naming the
 *     wrong order -- so the buyer's replay shows a previous order as this payment's confirmation.
 *     The difference between the list before and after the write answers both exactly.
 *
 * `$sessionPlacedIt` is false for a machine caller: the list is restored to what it was, so the
 * writer's binding is used to identify the order and then dropped. `request_data` is restored with
 * it, because the trigger puts the BUYER'S cart and billing details into the caller's session a few
 * lines above for the writer to read.
 */
private function orderWrittenBy(callable $write, bool $sessionPlacedIt): ?int
{
    $before = \App\Services\OrderCompleteBinding::bound();
    $hadRequestData = \Illuminate\Support\Facades\Session::exists('request_data');
    $requestDataBefore = \Illuminate\Support\Facades\Session::get('request_data');

    $write();

    $created = array_values(array_diff(\App\Services\OrderCompleteBinding::bound(), $before));

    if (! $sessionPlacedIt) {
        session()->put(\App\Services\OrderCompleteBinding::SESSION_KEY, $before);

        if ($hadRequestData) {
            \Illuminate\Support\Facades\Session::put('request_data', $requestDataBefore);
        } else {
            \Illuminate\Support\Facades\Session::forget('request_data');
        }
    }

    return $created === [] ? null : (int) end($created);
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

    $statusData = $this->pollPaymentStatus(
        $pending['payment_id'],
        $pending['request_data']['store_id'] ?? null
    );

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
                /*
                 * RE-CHECK UNDER THE LOCK -- see freshPaymeraPendingOrNull().
                 * The pre-lock test at the top of this method and this use of
                 * the record straddled the lock, so a browser callback that
                 * finished first did not stop this trigger creating a second
                 * order for the same payment.
                 */
                $fresh = $this->freshPaymeraPendingOrNull($orderId);

                if ($fresh === null) {
                    return response()->json([
                        'status' => 'A',
                        'ok' => true,
                        'product_order_id' => $pending['product_order_id'] ?? null,
                    ]);
                }

                $pending = $fresh;

                $pending['status'] = 'A';
                Cache::put('paymera_pending_' . $orderId, $pending, now()->addHours(2));

                $this->paymentVerifier()->recordVerification(
                    'paymera',
                    $verification,
                    $pending['expected_total'] ?? ($verification->expectedAmount ?? '')
                );

                Session::put('request_data', $pending['request_data']);
                $freshRequest = Request::create('/', 'POST', $pending['request_data']);

                /*
                 * P1-ORD-13 FOLLOW-UP. This caller is the GATEWAY, not the buyer: the route is
                 * anonymous and CSRF-exempt. The writer's session binding identifies the order it
                 * created and is then dropped, so an anonymous POST cannot acquire a stranger's
                 * order; and the order is named from the before/after difference rather than from
                 * "the latest paymera order in this store", which recorded the OTHER buyer's order
                 * whenever two Paymera payments ran at once in one store. See orderWrittenBy().
                 */
                $createdId = $this->orderWrittenBy(
                    fn () => $this->getProductStatus($freshRequest, $pending['slug']),
                    false
                );

                // Close the double-processing window the callback relies on.
                $latestOrder = $createdId === null ? null : Order::where('id', $createdId)
                    ->where('store_id', $pending['request_data']['store_id'] ?? null)
                    ->where('payment_type', 'paymera')
                    ->first();

                if ($latestOrder === null) {
                    \Log::warning('Paymera trigger could not identify the order it created; the pending record names none', [
                        'paymera_reference' => $orderId,
                        'store_id' => $pending['request_data']['store_id'] ?? null,
                    ]);
                }

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

        // ====================================================================================
        // P01-SEC-14 H. THE CALLER'S `shipping_final_price` IS NOT ALLOWED INTO THE CART READ THIS
        // ORDER IS BUILT FROM -- the WEB half of the F5 fix, and the sharper of the two.
        //
        // MEASURED through this endpoint on a 100.00 basket, before this line existed:
        //
        //     nothing posted              product_price 100.0   final_price  100.0
        //     shipping_final_price = 500  product_price 100.0   final_price  600.0
        //     shipping_final_price = -900 product_price 100.0   final_price -800.0
        //
        // `orders.final_price` is the amount OWED -- for a `cod` order it is the cash the driver
        // collects -- and it lands there from `$final_sub_total_price`, which is
        // `$cartlist['total_sub_price']` (:1997 and :2025), which cart_list builds by adding
        // `(int)$request['shipping_final_price']` with no floor. So the shopper set what they owed,
        // to any value including a negative one.
        //
        // This is worse than the defect Work Package B closed on payment_sheet: that was the card
        // amount on an endpoint no shipped client calls, and this is the storefront web journey
        // real shoppers use. F5 closed the same hole on the three API order handlers and stopped
        // there -- the third time in this package that a guard was scoped to the API and the web
        // path was left open. Found by an adversarial review of commit F.
        //
        // ZERO rather than the real shipping, because the real figure is not known yet:
        // processShipping() runs at :1215 and prepareFinalRequestData() then puts the SERVER's
        // `$delivery_price` into exactly this request key (:1687), so the authoritative value
        // arrives on its own and the order carries it in `delivery_price`. Zeroing here only
        // removes the caller's.
        // ====================================================================================
        $request->merge(['shipping_final_price' => 0]);

        // Get cart data
        $cartData = $this->getCartData($request, $store, $customer, $isGuest);
        if (isset($cartData['error'])) {
            return redirect()->back()->with('error', $cartData['error']);
        }
        
        $cartlist = $cartData['cartlist'];
        $cartlist_final_price = $cartData['cartlist_final_price'];

        // PRE-FLIGHT STOCK CHECK. This is the only point in the whole checkout
        // where refusing an unfulfillable cart is free: no cart row has been
        // deleted, no order row exists, and processPayment() below has not yet
        // handed the shopper to a gateway. Every one of the nine
        // decrementStockOrFail() call sites is past that point, which is why all
        // nine pass $rejectOnShortfall = false. See assertCartAvailable().
        if ($unavailable = $this->assertCartAvailable($cartData['products'] ?? null, $store)) {
            return redirect()->back()->with('error', $this->stockErrorMessage($unavailable));
        }

        /*
         * AND THE BASKET MUST BELONG TO THIS STORE, P01-COMMERCE-SEC-03 FOLLOW-UP.
         *
         * This is the WEB checkout's single entry point: PaymentService::processPayment() below
         * dispatches every payment type from here, and two of them -- `cod` and `bank_transfer`
         * (PaymentService:316,318) -- write the order and decrement stock inline in
         * PaymentService::getProductStatus(), whose product lookup is
         * `Product::where('id', $product_id)->first()` (:2320) with NO store predicate and whose
         * plain-product arm decrements `Product::find($product_id)` (:2331). Neither pre-flight
         * existed on that path: it was the only live host-app order writer the six-site wiring in
         * 611357e6 missed, found by an adversarial review of that commit.
         *
         * Placed HERE rather than in the service because this is the one place that covers all
         * of them at once, and because it sits where the two sibling pre-flights already are --
         * before a cart row is deleted, before an order row exists, and before the shopper is
         * handed to a gateway. The service carries its own copy as defence in depth, for the same
         * reason the gateway returns each carry one: the state can be re-entered there.
         */
        if ($this->basketHasForeignProduct($cartData['products'] ?? null, $store)) {
            /*
             * The shared gatewayReturnForeignProductRefusal() is deliberately NOT used here.
             * Its message tells the shopper their payment "has not been applied to an order",
             * which is true on a gateway RETURN and false here: nothing has been charged yet.
             * Same refusal, same log, honest wording -- matching the non-positive-quantity
             * refusal immediately below, which is the sibling of this one on this path.
             */
            \Illuminate\Support\Facades\Log::warning('checkout: refused, basket names a product from another store', [
                'store_id' => $store->id ?? null,
                'customer_id' => $customer->id ?? null,
                'path' => $request->path(),
            ]);

            return redirect()->back()->with('error', __('Your cart contains an item this store does not sell.'));
        }

        // P01-SEC-14 G. A NON-POSITIVE LINE QUANTITY IS NOT A PAYABLE BASKET -- the WEB checkout's
        // copy of the guard the API order writers got in F, and the fourth and last money path.
        //
        // WHY THE STOCK CHECK ABOVE IS NOT THIS. assertCartAvailable() does
        // `if (... (int) $qty <= 0) { continue; }` at :315 -- it SKIPS a non-positive line rather
        // than refusing it, which is right for a stock question (there is nothing to reserve) and
        // wrong for a pricing one. The line is skipped for stock and then priced anyway:
        // getCartData's figures flow into `$order->product_price` (:2307) and `$order->final_price`
        // (:2313-2315), so the order is reduced by the negative line exactly as the API handlers
        // were before F.
        //
        // AND THE ROWS CAN STILL BE CREATED. F validated the two writers in the host app --
        // ApiController::addtocart and CartController::product_cartlist -- but TWO MORE live in
        // vendored module code that must not be edited:
        //   packages/workdo/BoostSales/.../BoostSalesController.php:59 -> :183
        //   packages/workdo/FrequentlyProduct/.../FrequentlyProductController.php:88 -> :209
        // both `$qty = $request->qty` with no validation, on `POST {storeSlug}/boost-sales-cart`
        // and `POST {storeSlug}/frequently_product_cart` under plain `web`. Found by an adversarial
        // review of commit F, which went looking for exactly the scope mistake F had just fixed.
        // So the read side is where this has to hold, and it now holds on all four paths that turn
        // a cart into money: here, ordersave, place_order and payment_sheet. The two module writers
        // are an owner item -- see S29 in docs/security/P01-SECURITY-WORK-QUEUE.md.
        $nonPositiveLines = collect($cartData['products'] ?? [])
            ->filter(function ($line) {
                $qty = $this->cartLineValue($line, 'qty');

                return $qty !== null && is_numeric($qty) && (int) $qty <= 0;
            })
            ->count();

        if ($nonPositiveLines > 0) {
            \Illuminate\Support\Facades\Log::warning('checkout: refused, basket holds a non-positive quantity', [
                'store_id' => $store->id ?? null,
                'customer_id' => $customer->id ?? null,
                'lines' => $nonPositiveLines,
            ]);

            return redirect()->back()->with('error', __('Your cart contains an invalid quantity.'));
        }

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

        // Prepare final request data. A refusal here means the checkout cannot be priced from the
        // server's own records -- see the `pending_payment` limb inside.
        $refusal = $this->prepareFinalRequestData($request, $cartlist, $cartlist_final_price, $delivery_price, $tax_price);
        if ($refusal !== null) {
            return $refusal;
        }

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
            // A FIXED, PUBLIC PASSWORD IS NOT A PASSWORD. This wrote Hash::make('1234') -- a
            // literal committed to this repository -- as a real, live credential, so anyone who
            // knew the address of an account created this way could sign in as them. It is the
            // same defect as the 'hello@1234' the password-recovery emergency removed from
            // Api\AuthController::forgot_password_verify_otp, in a different place.
            //
            // The customer never chose a password here, so there is no password to set: a long
            // random value makes the account unusable by password until the owner sets one
            // through the recovery flow, which is the standard pattern for an
            // account-created-on-your-behalf. Nothing in app/ ever attempted a login with the
            // literal (checked), so no journey depended on knowing it.
            $customerData['password'] = Hash::make(\Illuminate\Support\Str::random(64));
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
        // WRITTEN DIRECTLY, not by calling Api\ApiController::add_address().
        //
        // This used to build a synthetic Request carrying 'customer_id' and hand it to that API
        // handler, discarding the return value. It only ever worked because add_address had the
        // guard-class fallback `$customer_id = $request->customer_id` -- the very defect P01-SEC-11
        // closed. There is no customers session at this point in checkout:
        // handleCustomerRegistration() calls createCustomer() BEFORE auth('customers')->login(),
        // and the guest path never logs in at all. So once add_address required an authenticated
        // Customer, this call returned 401, the address was never created, and the discarded
        // return value meant nothing surfaced -- a shopper who registered at checkout got an empty
        // address on their profile and lost shipping-method resolution.
        //
        // Routing an internal write through a session-authenticated API handler was never the
        // right shape. The controller already holds both $customer and $store, so it writes the
        // row itself and the handler stays closed.
        $address = new DeliveryAddress();
        $address->customer_id = $customer->id;
        $address->full_name = $billing['firstname'] ?? null;
        $address->title = isset($billing['firstname']) ? strtolower($billing['firstname']) : null;
        $address->address = $billing['billing_address'] ?? null;
        // P1-ORD-15: delivery_addresses.country_id / state_id / city_id are foreign keys, and
        // these three took request input verbatim -- so a caller posting NAMES made this write a
        // foreign-key violation mid-checkout, on the registration path. Resolved, never created.
        $addressGeography = \App\Services\BillingGeography::resolveIds(
            $billing['billing_city'] ?? null,
            $billing['billing_state'] ?? null,
            $billing['billing_country'] ?? null
        );
        $address->country_id = $addressGeography['country_id'];
        $address->state_id = $addressGeography['state_id'];
        $address->city_id = $addressGeography['city_id'];
        $address->postcode = $billing['billing_postecode'] ?? null;
        $address->phone = $billing['billing_user_telephone'] ?? null;
        $address->default_address = 1;
        $address->store_id = $store->id;
        $address->save();

        // Scoped to this customer, which is what add_address's own demotion should always have
        // been: it keyed on `$request->customer_id` and so could demote another account's
        // defaults.
        DeliveryAddress::where('customer_id', $customer->id)
            ->where('id', '!=', $address->id)
            ->update(['default_address' => 0]);
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
        
        /*
         * THE POSTED `coupon_info` IS DISCARDED, NOT TRUSTED.  P1-ORD-02 criterion 2.
         *
         * THIS BRANCH USED TO BE A SHORT-CIRCUIT:
         *
         *     if (isset($request['coupon_info']) && $request['coupon_info']) {
         *         $coupon_price = $request['coupon_info']['coupon_discount_amount'] ?? 0;
         *         return [...];                      // apply_coupon() never ran
         *     }
         *
         * `coupon_info` is an ordinary request field. When a caller posted one, the server's own
         * coupon validation was skipped entirely -- and because that branch returned no `coupon_info`
         * key, the caller's array was never overwritten either. It travelled verbatim into the stored
         * checkout state (`$request->except('attachment','payment_receipt')`) and from there into the
         * order's records.
         *
         * MEASURED on the real route, signed in, with a genuine 10% coupon on a 100.00 basket (so the
         * truth is 10.00) and a posted `coupon_discount_amount` of 99999:
         *
         *     order_coupon_details.coupon_discount_amount = 99999
         *     order_coupon_details.coupon_final_amount    = 0.01
         *
         * THE CHARGE WAS NOT AFFECTED -- `prepareFinalRequestData()` computes the payable server-side,
         * and the shopper was still billed 100.00. What this was is a FALSIFIED FINANCIAL RECORD: the
         * order stated a discount of the shopper's choosing, which never happened, and every report,
         * reconciliation and `user_coupons` row built on it inherited that number.
         *
         * REMOVING THE BRANCH IS SAFE AND THAT WAS CHECKED, NOT ASSUMED: `apply_coupon()` is 870 lines
         * and performs ZERO writes -- no insert, update, save, increment, decrement or delete -- so
         * always calling it cannot double-consume a coupon. Consumption is written by the gateway
         * handlers through `UserCoupon`, not here. The branch was avoiding nothing.
         *
         * The server path below validates the code against the store's own `coupons` row (expiry,
         * limit, minimum spend, applicability) and returns the figures it computes, which are then
         * merged back into the request by the caller -- so the stored state carries the server's
         * coupon_info and the records follow.
         */
        
        /*
         * `final_sub_total` IS PINNED TO THE SERVER'S SUBTOTAL, and that is a second caller-supplied
         * override closed on this path.
         *
         * `apply_coupon()` prefers `$request->final_sub_total` over `sub_total` in FOUR places --
         * the maximum-spend gate, the minimum-spend gate, and twice as `$price`, the base the
         * discount is computed FROM (Api\ApiController.php:4015-4028, :4080, :4355-4359). Nothing in
         * this application ever sets that key: a repo-wide search of app/ and resources/views/ finds
         * it ONLY in those reads. It has no server-side producer and no view posts it, so on this
         * path it can only ever be the caller's.
         *
         * Posting `final_sub_total=1000000` therefore walks past a minimum-spend gate the basket does
         * not meet AND inflates the base a percentage coupon is taken from.
         *
         * Pinned rather than removed: `apply_coupon()` is 870 lines with other callers, and rewriting
         * its subtotal handling is a wider change than this path needs. Overwriting the key here
         * means the checkout cannot be the one that supplies it. THE SAME KEY ON THE DIRECT
         * `apply_coupon` API ENDPOINT IS NOT CLOSED BY THIS and is recorded in
         * docs/security/P01-ORD-02-PARTIAL-PAYMENT-TOTAL-SOURCE.md.
         */
        $apply_coupon = [
            'coupon_code' => $request['coupon_code'] ?? null,
            'sub_total' => $cartlist_final_price ?? 0,
            'final_sub_total' => $cartlist_final_price ?? 0,
        ];

        $request->request->add($apply_coupon);
        $coupon_apply = new ApiController();
        $apply_coupon_response = $coupon_apply->apply_coupon($request, $slug);
        $apply_coupon = (array)$apply_coupon_response->getData()->data;
        
        if (!empty($apply_coupon['final_price'])) {
            $cartlist_final_price = $apply_coupon['final_price'];
            $coupon_price = $apply_coupon['amount'];
        }
        
        /*
         * A REFUSAL IS AN ANSWER, NOT A CRASH -- the second defect in this method, and almost
         * certainly the reason the short-circuit above existed.
         *
         * `$apply_coupon['coupon_info']` was dereferenced unconditionally, but `apply_coupon()`
         * returns an ERROR SHAPE when it declines the code -- `data` is then just
         * `['message' => '...']` with no `coupon_info` key at all. MEASURED: a coupon whose
         * `minimum_spend` is not satisfied returns
         *
         *     {"status":0,"message":"fail","data":{"message":" The minimum spend for this coupon is ..."}}
         *
         * and the next line raised `Undefined array key "coupon_info"` -- an ErrorException, i.e. a
         * 500 on the checkout, for the ordinary case of a shopper typing a code they do not qualify
         * for. Removing the short-circuit above made that reachable on every such attempt, which is
         * why it is fixed here rather than left for later.
         *
         * A DECLINED COUPON NOW MEANS NO COUPON: the subtotal is untouched, `coupon_price` stays 0,
         * and `coupon_info` is an EMPTY ARRAY -- never the caller's. The empty array matters as much
         * as the refusal: the caller's own `coupon_info` is still sitting in the request, and
         * returning nothing here would let the merge in processOrder leave it in place, which is the
         * very defect the short-circuit removal closes.
         */
        if (! isset($apply_coupon['coupon_info'])) {
            return [
                'cartlist_final_price' => $cartlist_final_price,
                'coupon_price' => 0,
                'coupon_info' => [],
            ];
        }

        return [
            'cartlist_final_price' => $cartlist_final_price,
            'coupon_price' => $coupon_price,
            'coupon_info' => (array) $apply_coupon['coupon_info'],
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
     * Prepare final request data.
     *
     * RETURNS A REFUSAL, OR NULL. The `pending_payment` limb below can discover that the balance the
     * caller named is not theirs, not this store's, already settled, or not a balance at all -- and
     * there is no safe payable to continue with in any of those cases. Returning the redirect rather
     * than throwing keeps the existing shape of processOrder()'s other early exits.
     *
     * @return \Illuminate\Http\RedirectResponse|null
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
                        /*
                         * THE SHOPPER DECIDED WHAT THEY OWED.  P1-ORD-02 criterion 2.
                         *
                         * This limb is the one a shopper reaches when they settle the OUTSTANDING
                         * BALANCE of an earlier order, and it used to read
                         *
                         *     $new_array['cartlist_final_price'] = $request->payment_amount ?? 0;
                         *
                         * `cartlist_final_price` is the figure every gateway arm in PaymentService
                         * hands the provider, so the hidden field the module's view prints was the
                         * money. MEASURED on POST {slug}/process-order, signed in, against a recorded
                         * balance of 40.00: posting payment_amount = 1.00 stored and charged 1.00.
                         *
                         * The balance is now read from the `order_partial_payments` row itself, and
                         * only if that row is THIS store's, THIS signed-in customer's and still
                         * awaiting payment -- which also closes the identity half, because the same
                         * unchecked `partial_payment_id` is what the gateway return hands to
                         * ManagePartialPayment() to flip a row to paid.
                         *
                         * See App\Http\Controllers\Concerns\ResolvesPendingPartialPayment.
                         */
                        /*
                         * AND THE CHOSEN GATEWAY MUST BE ABLE TO SETTLE A BALANCE AT ALL.
                         *
                         * Raised by the read-only review of this change: `KhaltiPaymnetController`
                         * reads the stored checkout state and writes an order but carries NO
                         * `partial_payment` branch, so a khalti pending-balance checkout was priced
                         * at the balance, charged it, and then written as a FULL-PRICE order with
                         * the balance left unsettled. Refused HERE rather than on the return,
                         * because by then the card has been charged. See
                         * ResolvesPendingPartialPayment::PENDING_PAYMENT_UNSUPPORTED_TYPES.
                         */
                        if (! $this->pendingPaymentTypeCanSettle($request->input('payment_type'))) {
                            \Illuminate\Support\Facades\Log::warning('Checkout refused: this payment method cannot settle an outstanding balance.', [
                                'store_id' => $store->id,
                                'customer_id' => \Auth::guard('customers')->id(),
                                'payment_type' => $request->input('payment_type'),
                            ]);

                            return redirect()->back()->with('error',
                                __('This payment method cannot be used to settle an outstanding balance. Please choose another.'));
                        }

                        $payable = $this->pendingPartialPaymentPayable(
                            $request->input('partial_payment_id'),
                            $store->id,
                            \Auth::guard('customers')->id()
                        );

                        if ($payable === null) {
                            \Illuminate\Support\Facades\Log::warning('Checkout refused: no recorded balance matches this pending payment.', [
                                'store_id' => $store->id,
                                'customer_id' => \Auth::guard('customers')->id(),
                                'partial_payment_id' => $request->input('partial_payment_id'),
                            ]);

                            return redirect()->back()->with('error',
                                __('This payment could not be verified. Please reopen the order and try again.'));
                        }

                        if ($this->pendingPaymentAmountDisagrees($request->input('payment_amount'), $payable)) {
                            /*
                             * REFUSED, NOT SILENTLY CORRECTED -- the owner's instruction, and the
                             * kinder answer. The module's view posts the row's own figure, so an
                             * honest body always agrees; charging a different amount from the one on
                             * screen would be a surprise whether the cause was tampering or a page
                             * left open while the balance moved.
                             */
                            \Illuminate\Support\Facades\Log::warning('Checkout refused: the posted pending-payment amount disagrees with the record.', [
                                'store_id' => $store->id,
                                'customer_id' => \Auth::guard('customers')->id(),
                                'partial_payment_id' => $request->input('partial_payment_id'),
                            ]);

                            return redirect()->back()->with('error',
                                __('The amount shown for this payment is out of date. Please reopen the order and try again.'));
                        }

                        $new_array['cartlist_final_price'] = $payable;
                        // So nothing downstream -- in this app or in a module -- reads the caller's
                        // figure out of the request after this point.
                        $new_array['payment_amount'] = $payable;
                    }
                }
            }
        }
        
        $request->merge($new_array);

        return null;
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
        /*
         * P01-COMMERCE-SEC-01. THE CHECKOUT STATE IS THE SERVER'S, OR THERE IS NO ORDER.
         *
         * This is the GENERIC storefront payment return -- route('store.payment.status'), the
         * handler the PayU return redirects into, and the writer for every provider whose
         * initiation ends at this URL. It had the same `$request->all()` fallback the six gateway
         * controllers had (`if ($requests_data == null) { $requests_data = $request->all(); }`,
         * removed below), so a caller who sent no session cookie supplied the cart, the prices,
         * the shipping, the coupon, the STORE the order is written to and the CUSTOMER it is
         * written for. See App\Http\Controllers\Concerns\GuardsGatewayReturn.
         */
        $requests_data = $this->storedCheckoutState($request, 'storefront-return');

        if ($requests_data === null) {
            return $this->gatewayReturnRefusal();
        }
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

        /*
         * P01-COMMERCE-SEC-01. This was a bare `$request->merge($requests_data)`, and a bare merge
         * is not enough: Request::merge() can only overwrite a key the stored state CARRIES. A
         * checkout where the shopper chose no shipping method carries no `method_id`, so the
         * POSTED one survived and `ShippingMethod::find()` -- no store predicate, and the model is
         * not in config/tenancy.php's scoped_models -- resolved it against every tenant's methods.
         *
         * MEASURED on this handler, which is the generic storefront return and PayU's real order
         * writer: a store-B 'Local pickup' method at cost '-500', posted here on a store-A guest
         * return, wrote delivery_price -500.0. The five gateway controllers were fixed first;
         * this one was left on its pre-existing merge and the defect stayed live here.
         *
         * adoptStoredCheckoutState() merges AND pins customer_id and method_id. The is_array()
         * test it replaces is redundant -- storedCheckoutState() above returns ?array and this
         * method returns early on null.
         */
        /*
         * P1-PAY-02. ONE CHECKOUT, AND ONE PAYMENT, PRODUCE AT MOST ONE ORDER -- and the control
         * is NOT the session. `Session::forget()` below makes the stored state single-use, which
         * stops a SEQUENTIAL replay; it cannot stop two returns that OVERLAP, because Laravel
         * holds no session lock for the life of these requests and both read the state before
         * either writes the forget. That is the read-check-use straddle already fixed on the
         * Paymera path, where it produced a second order, a second stock decrement and a second
         * wallet credit.
         *
         * TAKEN HERE, BELOW THE GATEWAY-FINALITY CHECKS, and that placement is the whole point.
         * This is the generic return for some twenty payment methods, and the PhonePe, toyyibpay
         * and Tap blocks above return EARLY WITH THE STATE INTACT -- deliberately, because a
         * payment reported PENDING (toyyibpay status_id 2, which is even flashed as a success)
         * is not finished and the shopper must be able to come back. The first version of this
         * change claimed above those exits, so a pending payment that later completed met "This
         * payment has already been recorded against an order" for an order that was never
         * written. An independent review caught it. Everything between the stored-state read and
         * this line is reads and one outbound status poll, so the claim still sits above every
         * write and above the forget, and two overlapping returns still race here rather than on
         * the session.
         */
        if (! $this->claimGatewayReturn($request, 'storefront-return', $requests_data)) {
            return $this->gatewayReturnReplayRefusal();
        }

        $this->adoptStoredCheckoutState($request, $requests_data);

        // Re-pointed from $request->cartlist to the stored state. The merge above makes the two
        // the same whenever the stored state carries a cartlist, but NOT when it does not -- there
        // a posted cartlist would have survived and chosen the discount. The fallback that used to
        // follow this block was the defect named at the top of this method.
        if (!isset($requests_data['coupon_info']) && isset($requests_data['cartlist']['coupon_info'])) {
            $requests_data['coupon_info'] = is_object($requests_data['cartlist']['coupon_info']) ? (array) $requests_data['cartlist']['coupon_info'] : $requests_data['cartlist']['coupon_info'];
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
                /*
                 * `&&`, WHERE IT USED TO SAY `||` -- and `isset()` alone is true for ANY value of a
                 * caller-supplied key, so posting `partial_payment_type=x` on an ORDINARY checkout
                 * diverted the gateway return into the settle-a-balance branch. That branch answers
                 * redirect()->back() without writing an order, and its first statement is
                 * `OrderPartialPayments::find($requests_data['partial_payment_id'])`, which is
                 * find(NULL) when the state holds no such key -- a fatal on the next line. Either
                 * way the shopper is charged and no order row exists. P1-ORD-02 criterion 2.
                 *
                 * AND THE PREDICATE READS THE STORED STATE, not the live request. `Request::merge()` only
                 * overwrites keys the state HOLDS (GuardsGatewayReturn:174-176), so an ordinary checkout's
                 * state -- which carries no `partial_payment_type` -- let a posted one survive the merge and
                 * divert the return. By then the replay claim is consumed and the state forgotten, so the
                 * shopper's payment stands with no order and no retry.
                 *
                 * AND THE ROW IS CHECKED BEFORE IT IS FLIPPED. ManagePartialPayment() is three
                 * statements of vendored code with no store_id and no customer_id predicate, on a
                 * model that is in no scoped_models entry -- so without this, a signed-in shopper
                 * could mark ANY tenant's pending balance paid by naming its id. The initiation path
                 * validates the same row (prepareFinalRequestData), and this is the half that holds
                 * when the store has `enable_partial_payment` off, where that limb never runs.
                 *
                 * WHAT THIS GUARD DOES NOT ANSWER, stated so the next reader is not misled: it proves the
                 * balance is THIS customer's and still owed. It does NOT prove it was PAID. Pre-existing,
                 * not closed here, and recorded in
                 * docs/security/P01-ORD-02-PARTIAL-PAYMENT-TOTAL-SOURCE.md section 5.
                 */
                if (($requests_data['partial_payment_type'] ?? null) === 'pending_payment') {
                    if ($this->pendingPartialPaymentRow(
                        $requests_data['partial_payment_id'] ?? null,
                        $store->id ?? null,
                        \Auth::guard('customers')->id()
                    ) === null) {
                        \Illuminate\Support\Facades\Log::warning('Pending partial payment refused: the balance named does not belong to this customer.', [
                            'store_id' => $store->id ?? null,
                            'customer_id' => \Auth::guard('customers')->id(),
                            'partial_payment_id' => $requests_data['partial_payment_id'] ?? null,
                        ]);

                        return redirect()->back()->with('error', __('This payment could not be verified.'));
                    }

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

        // P01-COMMERCE-SEC-01. A non-positive or fractional line quantity is refused BEFORE any
        // stock row moves. A negative qty makes the atomic decrement an INCREMENT -- free stock
        // minted on a return -- and processOrder's own quantity check does not cover this entry
        // point, which is reached directly by the gateway redirect. Unlike whatsapp() below, this
        // method has no assertCartAvailable() call to lean on.
        if ($this->basketHasUnusableQuantity($products ?? null)) {
            return $this->gatewayReturnQuantityRefusal($request, 'storefront-return');
        }

        /*
         * AND THE BASKET MUST BE THIS STORE'S -- product AND variant. P01-COMMERCE-SEC-03.
         * See GuardsGatewayReturn::basketHasForeignProduct(). This handler's stock loop resolves
         * both halves from the caller's values -- `Product::where('id', $product_id)` with no
         * store predicate at all, and the variant from `id` + `product_id` -- so this is the only
         * place on the path where ownership is decided, and it is decided before any row moves.
         */
        if ($this->basketHasForeignProduct($products ?? null, $store)) {
            return $this->gatewayReturnForeignProductRefusal($request, 'storefront-return');
        }

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
            foreach ($products as $key => $product) {
                $prodduct_id_array[] = $product->product_id;

                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;

                $Product = Product::where('id', $product_id)->first();
                $datas = Product::find($product_id);
                if (($settings['stock_management'] ?? null) === 'on') {
                    if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {
                        $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();
                        $variationOptions = explode(',', (string) ($ProductStock->variation_option ?? ''));   // P1-PAY-01: null-safe; a bare deref here throws ErrorException
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
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock", json_decode($settings['notification'] ?? '[]'))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $ProductStock, $settings);
                                        }

                                    }
                                }
                                if ($ProductStock->stock <= ($settings['out_of_stock_threshold'] ?? '0')) {
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock", json_decode($settings['notification'] ?? '[]'))) {
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
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock", json_decode($settings['notification'] ?? '[]'))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $datas, $settings);
                                        }

                                    }
                                }
                                if ($datas->product_stock <= ($settings['out_of_stock_threshold'] ?? '0')) {
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock", json_decode($settings['notification'] ?? '[]'))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_out_of_stock($product, $datas, $settings);
                                        }
                                    }
                                }
                                if ($datas->product_stock <= ($settings['out_of_stock_threshold'] ?? '0') && $datas->stock_order_status == 'notify_customer') {
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
                                if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock", json_decode($settings['notification'] ?? '[]'))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::low_stock_threshold($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= ($settings['out_of_stock_threshold'] ?? '0')) {
                                if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock", json_decode($settings['notification'] ?? '[]'))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::out_of_stock($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= ($settings['out_of_stock_threshold'] ?? '0') && $Product->stock_order_status == 'notify_customer') {
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
                // P1-SEC-23. The owner of the lines to clear is the AUTHENTICATED customer, or -- for a guest --
                // the basket named by their own cart cookie. NOT `$request->customer_id`: for a guest that is
                // the id of the Customer processOrder() found or created from the billing e-mail they TYPED,
                // so keying the clear on it let a stranger delete a registered customer's basket lines.
                $cartLinesToClear[] = [
                    'customer_id' => auth('customers')->id(),
                    'cookie_session_id' => Cookie::get('cart'),
                    'product_id' => $product_id,
                    'variant_id' => $variant_id,
                    'store_id' => $store->id,
                ];
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
            __METHOD__,
            // P1-PAY-03. WHICH CHECKOUT THIS WRITER IS SETTLING. A verification record now names
            // the checkout that produced it, and this is the shared storefront writer -- the one
            // a record planted by an aborted capture on another route was measured being redeemed
            // at. Presenting the nonce is also what keeps a LEGITIMATE record usable here:
            // without it every bound record would be refused, which is an outage, not a control.
            $this->checkoutNonceOf($requests_data)
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
        // TRANSACTION BOUNDARY -- OPENS HERE.
        //
        // THE DEFECT. There was NO DB::transaction anywhere in this method: the
        // order row, its billing detail, its coupon detail, the UserCoupon
        // consumption row and its tax detail rows were each saved on their own.
        // A failure part-way left an order with no billing address, or a coupon
        // marked consumed with no OrderCouponDetail recording what it was
        // consumed for, or the reverse -- a coupon recorded as applied to the
        // order but never marked used, so it could be redeemed again.
        //
        // WHAT THIS COVERS. Every LOCAL write from the order row through the
        // last OrderTaxDetail row: Order, City (billing and delivery
        // auto-create), OrderBillingDetail, OrderCouponDetail, UserCoupon and
        // OrderTaxDetail. They now commit together or not at all.
        //
        // WHAT THIS DOES NOT COVER, stated precisely rather than implied:
        //
        //  1. The stock loop and the per-line cart deletion above are OUTSIDE
        //     it, and deliberately so. That loop calls Utility::sendEmailTemplate
        //     and Utility::SendMsgs -- SMTP and SMS -- on the low-stock and
        //     back-in-stock branches. Holding row locks across an external
        //     call is the mistake this boundary exists to avoid, so inventory
        //     is compensated instead of rolled back: the catch below gives back
        //     every decrement this request applied, via the accumulator the
        //     loop already maintains.
        //
        //  2. The cart rows deleted inside that loop are NOT restored. They are
        //     deleted per line before this boundary opens, and recreating them
        //     would mean reconstructing cart state this method no longer holds.
        //     A failed order therefore still empties the cart. That is
        //     unchanged, pre-existing behaviour and it is reported, not hidden.
        //
        //  3. The Firebase push in StoreNotificationService::orderCreated() is
        //     moved to AFTER the commit, below. It calls
        //     $this->firebase->sendToToken() (StoreNotificationService:169) --
        //     an outbound HTTP call. It must not sit inside a transaction, and
        //     notifying about an order that may still roll back is wrong
        //     regardless.
        //
        // The exception is RE-THROWN, so the caller sees exactly what it saw
        // before: the transaction adds atomicity and stock compensation without
        // changing the happy path or the failure response.
        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $order->save();






            // add in  Order table end
            if (module_is_active('CheckoutAttachment')) {
                \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachment($order, $slug, $request);
            }
            // Utility::paymentWebhook($order);

            // P1-ORD-15: the posted values are IDS and this looked the city up by NAME. One resolver,
            // which also keeps a posted NAME out of cities.state_id / country_id (foreign keys).
            $billing_city_id = \App\Services\BillingGeography::resolveCityId(
                $billing['billing_city'] ?? null,
                $billing['billing_state'] ?? null,
                $billing['billing_country'] ?? null
            );

            // P1-ORD-15: the posted values are IDS and this looked the city up by NAME. One resolver,
            // which also keeps a posted NAME out of cities.state_id / country_id (foreign keys).
            $delivery_city_id = \App\Services\BillingGeography::resolveCityId(
                $billing['delivery_city'] ?? null,
                $billing['delivery_state'] ?? null,
                $billing['delivery_country'] ?? null
            );

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

            \Illuminate\Support\Facades\DB::commit();

            // The order exists now, so the cart may be emptied. Inside the
            // try but after the commit: a cart row that will not delete must
            // not roll back an order whose money has already moved, which is
            // why clearCartLines() swallows and logs per line.
            $this->clearCartLines($cartLinesToClear);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();

            // Compensate the inventory this request consumed before the
            // boundary opened. Without this, a failed order write left stock
            // decremented with no order to account for it.
            $this->restoreStockDecrements($appliedStockChanges);

            \Log::critical('Order write failed and was rolled back; stock decrements were given back', [
                'store_id' => $store->id ?? null,
                'payment_type' => $requests_data['payment_type'] ?? null,
                'restored_lines' => count($appliedStockChanges),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

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
                    $variationOptions = explode(',', (string) ($variant_data->variation_option ?? ''));   // P1-PAY-01: null-safe; a bare deref here throws ErrorException
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
            $cart = Cart::forGuestCookie($cart)->delete();
            if (auth('customers')->user()) {
                event(new AddRewardClubPoint($requests_data, $order, $slug));
            }

            // P1-ORD-13: the thank-you page resolves the PRIMARY KEY this session placed, because
            // `product_order_id` is a bare timestamp two same-second orders in one store share --
            // the number alone showed one buyer the other's order, and after P1-ORD-08 refused both.
            \App\Services\OrderCompleteBinding::remember($order->id);
            return redirect()->route('order.complete', $slug)->with('data', $order->product_order_id);
        } else {
            return $this->error(['message' => 'Somthing went wrong.']);
        }
    }

    public function getWhatsappUrl(Request $request, $slug)
    {
        $store = getStore($slug);

        // P1-SEC-22 a. This route is anonymous and, for `data[type]=telegram`, makes the server post to the merchant's Telegram bot. Both
        // refusals are 404/403 and not the JSON envelope below: the only page that calls it (the payment page for the method) is reached
        // only when the method is switched on, so a refusal here is never a shopper's mistake. An unknown slug used to be a 500.
        if (! $store) {
            abort(404);
        }

        $relay = ($request->input('data.type') === 'telegram') ? 'is_telegram_enabled' : 'is_whatsapp_enabled';

        if (\App\Models\Utility::GetValueByName($relay, $store->id) !== 'on') {
            abort(403);
        }

        $requests_data = Session::get('request_data');
        $telegram_access_token = \App\Models\Utility::GetValueByName('telegram_access_token', $store->id);
        $telegram_chat_id = \App\Models\Utility::GetValueByName('telegram_chat_id', $store->id);
        $whatsapp_number = \App\Models\Utility::GetValueByName('whatsapp_number', $store->id);
        $cartlist_final_price = 0;
        $final_price = 0;
        $customer_id = $requests_data['customer_id'] ?? 0;
        $cartOwnerId = (int) (auth('customers')->id() ?? 0);

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
                        // CHECKED, NOT DECREMENTED. This route is anonymous and
                        // creates no order, so decrementing here let any caller
                        // destroy a store's inventory, and double-counted every
                        // real WhatsApp order. See assertStockAvailable().
                        // DO NOT RETURN AN ERROR HERE. This method's contract is to
                        // return a bare URL string (see `return $url;` at the end), and its
                        // only caller -- resources/views/payment/whatsapp.blade.php -- opens
                        // a browser window on whatever comes back. $this->error() answers
                        // HTTP 200 with a JSON envelope (app/Traits/ApiResponser.php:54-60),
                        // so the caller's success branch ran and the shopper got a window
                        // full of serialised JSON instead of a WhatsApp link.
                        //
                        // There is nothing here to refuse: this route writes no Order, and
                        // the lines processed before this one have already had their cart
                        // rows deleted. The shortfall is recorded and the URL is still
                        // built. A shopper-visible refusal belongs on the order-writing
                        // whatsapp() path, before anything is destroyed -- not on a URL
                        // builder.
                        if ($stockFailure = $this->assertStockAvailable('variant', $ProductStock, $qtyy)) {
                            \Illuminate\Support\Facades\Log::warning(
                                'WhatsApp URL built for a cart line that is short on stock',
                                ['kind' => 'variant', 'model_id' => $ProductStock->id ?? null, 'requested_quantity' => $qtyy]
                            );
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
                        // CHECKED, NOT DECREMENTED. See the note above and
                        // assertStockAvailable().
                        // DO NOT RETURN AN ERROR HERE. This method's contract is to
                        // return a bare URL string (see `return $url;` at the end), and its
                        // only caller -- resources/views/payment/whatsapp.blade.php -- opens
                        // a browser window on whatever comes back. $this->error() answers
                        // HTTP 200 with a JSON envelope (app/Traits/ApiResponser.php:54-60),
                        // so the caller's success branch ran and the shopper got a window
                        // full of serialised JSON instead of a WhatsApp link.
                        //
                        // There is nothing here to refuse: this route writes no Order, and
                        // the lines processed before this one have already had their cart
                        // rows deleted. The shortfall is recorded and the URL is still
                        // built. A shopper-visible refusal belongs on the order-writing
                        // whatsapp() path, before anything is destroyed -- not on a URL
                        // builder.
                        if ($stockFailure = $this->assertStockAvailable('product', $Product, $qtyy)) {
                            \Illuminate\Support\Facades\Log::warning(
                                'WhatsApp URL built for a cart line that is short on stock',
                                ['kind' => 'product', 'model_id' => $Product->id ?? null, 'requested_quantity' => $qtyy]
                            );
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
                // P1-SEC-21 (a). The owner of the lines being cleared is the AUTHENTICATED customer, never
                // the body's: on the guest arm nothing overwrites `customer_id`, so a guest holding product
                // P in their own basket could post `customer_id=<victim>` and delete the victim's line for
                // P in this store. A guest has nothing of their own to clear here (guest rows carry
                // customer_id 0, which the old `IS NULL` lookup never matched), so with no customer this is
                // skipped -- NOT run with 0, which would delete every guest's rows for the product.
                if ($cartOwnerId > 0) {
                    Cart::forCustomer($cartOwnerId)->where('product_id', $product_id)->where('variant_id', $variant_id)->where('store_id', $store->id)->delete();
                }
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
        // P1-SEC-21 (b). With no arguments getAdminAllSetting() resolves the user from `auth()->user()` (the
        // `web` guard -- null for every storefront shopper) and falls through to the LOWEST-ID MERCHANT ON
        // THE PLATFORM, so every store's order message used the first merchant's `whatsapp_content`.
        // Read by name AND store, like the other settings this handler reads (`whatsapp_number`,
        // `telegram_*`, `whatsapp_item_variable`): NOT through getAdminAllSetting($owner, $store), which also
        // filters on `created_by`, and SettingController::customMassage upserts on [name, store_id] and
        // stamps `created_by` with whoever saved last -- a template saved by a staff member would be missed.
        $resp = Utility::replaceVariable(Utility::GetValueByName('whatsapp_content', $store->id), $arr) ?? null;

        if ($request['data']['type'] == 'telegram') {
            $this->sendTelegramOrderMessage($telegram_access_token, $telegram_chat_id, $resp, $store->id);

            // P1-SEC-17 F1. This used to return the request URL it had just posted to --
            // `https://api.telegram.org/bot<TOKEN>/sendMessage` -- to an ANONYMOUS caller. The only
            // page that calls this for Telegram (resources/views/payment/telegram.blade.php) stores
            // the body in a hidden input that nothing reads; the message is sent here, by the server.
            // So the body had no use except to hand out the merchant's bot token. The WhatsApp branch
            // below still returns its link, which the WhatsApp page does open.
            $url = '';
        } else {
            $url = 'https://api.whatsapp.com/send?phone=' . $whatsapp_number . '&text=' . urlencode(string: $resp);
        }
        $new_order_id = str_replace('#', '', $request->order_id);
        return $url;
    }

    /**
     * Post the order text to the merchant's Telegram bot. P1-SEC-17 F1.
     *
     * Through the Http client, not `file_get_contents()`, for two reasons that are both about the
     * token. A refused call (a revoked or mistyped token answers 401) made `file_get_contents()`
     * raise a PHP warning whose MESSAGE is the full request URL, i.e. the bot token, and that text
     * became the exception message -- which is written to the log and reported to the error
     * tracker. And a connection error from the Http client carries the URL too, so a failure is
     * recorded here by CLASS and status only, never by message and never as the exception object.
     *
     * A failure no longer fails the request: the shopper's page ignores the answer (it has no error
     * handler) and the order itself is written by whatsapp(), not here.
     */
    private function sendTelegramOrderMessage($token, $chatId, $text, $storeId): void
    {
        if (empty($token) || empty($chatId)) {
            \Illuminate\Support\Facades\Log::warning('Telegram order message not sent: the store has no bot token or chat id', [
                'store_id' => $storeId,
            ]);

            return;
        }

        try {
            $answer = \Illuminate\Support\Facades\Http::asForm()
                ->timeout(10)
                ->post('https://api.telegram.org/bot' . $token . '/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);

            if (! $answer->successful()) {
                \Illuminate\Support\Facades\Log::warning('Telegram refused an order message', [
                    'store_id' => $storeId,
                    'status' => $answer->status(),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Telegram order message could not be sent', [
                'store_id' => $storeId,
                'class' => get_class($e),
            ]);
        }
    }



    /**
     * THE WHATSAPP / TELEGRAM ORDER WRITER. P01-COMMERCE-SEC-02.
     * =========================================================================================
     *
     * WHY THIS ONE KEEPS ITS `$request->all()` FALLBACK when the seven gateway returns lost
     * theirs. It is not only a return. Three views post to this route
     * (`POST {storeSlug?}/whatsapp` and `{storeSlug?}/telegram`, routes/customer.php:179-180):
     * resources/views/payment/whatsapp.blade.php:42 is a genuine return with stored state, while
     * front_end/sections/payment_list.blade.php:21 and front_end/pages/checkout.blade.php:191
     * post from the checkout page WITHOUT passing through processOrder(), so no server state
     * exists for them to read. Requiring stored state would refuse a live payment method, which
     * is the one thing the preserve-first rule does not allow a security fix to do quietly.
     *
     * SO THE BOUNDARY IS DRAWN PER FIELD INSTEAD, and the three fields that decide WHOSE order
     * this is, and in WHICH store, stop being the caller's:
     *
     *   1. THE STORE IS THE URL'S, NOT THE BODY'S. The method received `$slug` from the route and
     *      then overwrote it with `$requests_data['slug']` -- so a POST to storeA's URL carrying
     *      `slug=storeB` wrote storeB's order, read storeB's settings and emptied carts in
     *      storeB. Every legitimate caller builds this URL with its own slug
     *      (`route('user.whatsapp', $slug)` in all three views), so the route parameter is both
     *      the authoritative value and the one already being sent. The body is consulted ONLY
     *      when the route carries no slug -- `{storeSlug?}` is optional -- and a mismatch is
     *      logged rather than silently preferred.
     *
     *   2. THE CUSTOMER IS THE PRINCIPAL'S OR THE STORED STATE'S, NEVER THE BODY'S. This is the
     *      same defect 0eee8524 closed on the gateway returns, still live here: the
     *      `$request->merge($requests_data)` below sits on the AUTHENTICATED arm only, so for a
     *      guest every later `$request->customer_id` was the POSTED one -- it became
     *      `orders.customer_id`, the `product_order_id`, and the `customer_id` of every row in
     *      `$cartLinesToClear`. A guest could post a victim's id and write an order owned by the
     *      victim while emptying that victim's basket in this store.
     *
     *   3. The shipping method was already closed one leg deeper, at the
     *      CartController::get_shipping_method chokepoint (0ad3f7e0), so a posted `method_id`
     *      can no longer price this checkout from another tenant's table or below zero.
     *
     * WHAT WAS ALREADY SAFE HERE, and is left alone: every money figure is read from the
     * SERVER's cart (Cart::cart_list_cookie / ApiController::cart_list), the tax comes from
     * OrderTaxResolver, the stock loop is atomic with give-back, the order row and its four
     * children commit in one transaction, and payment_status is derived through
     * PaymentVerificationService. Those are P01-SEC-14's, not this package's.
     */
    public function whatsapp(Request $request, $slug)
    {
        $storedState = Session::get('request_data');
        $hasStoredState = is_array($storedState) && $storedState !== [];

        $requests_data = $hasStoredState ? $storedState : $request->all();

        /*
         * THE ROUTE'S SLUG WINS. See point 1 in the method docblock. `$slug` is the route
         * parameter as declared by `{storeSlug?}`; the body is the fallback for the case where
         * the optional parameter is genuinely absent.
         */
        $routeSlug = is_string($slug) ? trim($slug) : '';
        $bodySlug = !empty($requests_data['slug']) ? (string) $requests_data['slug'] : '';

        if ($routeSlug !== '' && $bodySlug !== '' && $routeSlug !== $bodySlug) {
            \Illuminate\Support\Facades\Log::warning('Whatsapp order writer: posted slug does not match the route slug; the route wins.', [
                'route_slug' => $routeSlug,
                'posted_slug' => $bodySlug,
                'path' => $request->path(),
                'ip' => $request->ip(),
                'authenticated_customer' => optional(auth('customers')->user())->id,
                'had_stored_state' => $hasStoredState,
            ]);
        }

        $slug = $routeSlug !== '' ? $routeSlug : $bodySlug;
        $store = getStore($slug);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')]);
        }

        /*
         * And the state agrees with it from here on. `$requests_data['slug']` is read again lower
         * down -- it is merged into the request for the two apply_coupon() calls -- so leaving
         * the body's value in place would have the handler resolving one store for the order and
         * passing another store's slug around inside it. apply_coupon() happens to scope on its
         * own `$slug` ARGUMENT (ApiController.php:3714), which is this one, so nothing was
         * exploitable through it; the divergence is removed rather than left for the next reader
         * to re-derive.
         */
        $requests_data['slug'] = $slug;

        /*
         * AND ON THE FALLBACK PATH THE CALLER DOES NOT SET THE SHIPPING FIGURE THE CART IS
         * PRICED WITH. Traced end to end by an adversarial review of this package:
         *
         *   $requests_data = $request->all()                          (no stored state)
         *     -> Cart::cart_list_cookie($requests_data, $store->id)
         *     -> app/Models/Cart.php:199  $shipping_price = (int) ($data['shipping_final_price'] ?? 0)
         *     -> Cart.php:621             $final_total = ... + $shipping_price + ...
         *     -> Cart.php:635             $cart_array['total_sub_price'] = SetNumber($final_total)
         *     -> $final_sub_total_price   -> $order->product_price
         *
         * So one extra field on the direct checkout post moved `orders.product_price` to any
         * value the caller liked. `orders.final_price` is NOT affected -- it is built from
         * `total_final_price`, which excludes the shipping term -- so this is a recorded-total
         * defect rather than a payable one, and it is reported as that.
         *
         * ZEROED ONLY WHEN THERE IS NO STORED STATE. On the stored-state arm the value is the
         * server's own, written by prepareFinalRequestData() (:1716-1758) from processShipping();
         * zeroing it unconditionally would change `orders.product_price` for every legitimate
         * WhatsApp return. This is the same one-line pattern processOrder() already uses at
         * :1082, for the same reason, with the same comment: it removes only the caller's.
         */
        if (! $hasStoredState) {
            $requests_data['shipping_final_price'] = 0;
            $request->merge(['shipping_final_price' => 0]);
        }

        Session::forget('request_data');

        /*
         * THE ORDER'S OWNER. See point 2 in the method docblock. An authenticated shopper is
         * themselves; otherwise it is whatever the SERVER stored at initiation -- '' for a real
         * guest -- and never what arrived in the body. Pinned onto the request too, because the
         * guest arm below reads `$request->customer_id` directly in four places and no merge
         * runs on it.
         */
        $authenticatedCustomerId = optional(auth('customers')->user())->id;
        $customer_id = $authenticatedCustomerId
            ?? ($hasStoredState ? ($storedState['customer_id'] ?? '') : '');
        $requests_data['customer_id'] = $customer_id;
        $request->merge(['customer_id' => $customer_id]);

        if (!empty($requests_data['method_id'])) {

            $request['method_id'] = $requests_data['method_id'];
        }
        $user = Cache::remember('admin_details', 3600, function () {
            return User::where('type','admin')->first();
        });
        $plan = null;
        if ($user && $user->type == 'admin') {
            $plan = Plan::find($user->plan_id);
        }

        /*
         * `$plan->shipping_method` is read three times below and `$plan` was only ever assigned
         * inside the `type == 'admin'` test above -- so on a platform whose admin row is missing
         * or is not of that type, this method died on a null property read instead of saying
         * anything. Shipping is simply off when there is no plan to read it from.
         */
        $planShippingMethod = $plan->shipping_method ?? 'off';
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
        if ($planShippingMethod == 'on') {
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

        /*
         * AN UNUSABLE LINE QUANTITY REFUSES THE WHOLE BASKET, before any stock moves and before
         * any order is priced from it. See
         * GuardsGatewayReturn::basketHasUnusableQuantity() for the measurement behind both
         * bounds; this handler had neither. The cart is read server-side here, but `carts.qty`
         * itself is written straight from the request with no validation by two vendored module
         * routes under plain `web` (BoostSales, FrequentlyProduct -- S30), and processOrder()'s
         * own check at initiation cannot cover a row added between the checkout page and this
         * POST.
         *
         * A negative line ADDS stock on every decrement it reaches and REDUCES every total it is
         * summed into; an absurd one (2147483647) drives stock as far negative as the column
         * goes. Refusing the basket rather than skipping the line is deliberate: skipping prices
         * an order the shopper did not ask for.
         */
        if ($this->basketHasUnusableQuantity($products)) {
            return $this->error(['message' => __('Your cart contains an invalid quantity. Please review your cart and try again.')]);
        }

        /*
         * AND THE BASKET MUST BE THIS STORE'S -- product AND variant. P01-COMMERCE-SEC-03.
         * Same reasoning as the sibling in getProductStatus() above; this writer's loop resolves
         * `Product::where('id', $product_id)` with no store predicate either. The refusal is an
         * `error()` envelope rather than a redirect because this handler answers an AJAX caller.
         */
        if ($this->basketHasForeignProduct($products, $store)) {
            return $this->error(['message' => __('Your cart contains an item this store does not sell. Please review your cart and try again.')]);
        }

        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();

        // PRE-FLIGHT STOCK CHECK, and safe on THIS order-writing path because
        // no gateway has captured anything: WhatsApp is settled off-platform by
        // arrangement (PaymentVerificationService::OFFLINE_PAYMENT_TYPES lists
        // it). So a refusal here costs the shopper no money and destroys
        // nothing, unlike the decrementStockOrFail() sites in the loop below,
        // which run after cart rows have already been deleted.
        //
        // processOrder() already pre-flighted this cart before rendering the
        // WhatsApp view, but the shopper posts back to this route afterwards, so
        // stock can have moved in between. Re-checking is the point.
        if ($unavailable = $this->assertCartAvailable($products, $store, $settings)) {
            return $this->error(['message' => $this->stockErrorMessage($unavailable)]);
        }

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
            foreach ($products as $key => $product) {
                $prodduct_id_array[] = $product->product_id;

                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;

                $Product = Product::where('id', $product_id)->first();
                $datas = Product::find($product_id);
                if (($settings['stock_management'] ?? null) === 'on') {
                    if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {
                        $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();
                        $variationOptions = explode(',', (string) ($ProductStock->variation_option ?? ''));   // P1-PAY-01: null-safe; a bare deref here throws ErrorException
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
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock", json_decode($settings['notification'] ?? '[]'))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $ProductStock, $settings);
                                        }

                                    }
                                }
                                if ($ProductStock->stock <= ($settings['out_of_stock_threshold'] ?? '0')) {
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock", json_decode($settings['notification'] ?? '[]'))) {
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
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock", json_decode($settings['notification'] ?? '[]'))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $datas, $settings);
                                        }

                                    }
                                }
                                if ($datas->product_stock <= ($settings['out_of_stock_threshold'] ?? '0')) {
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock", json_decode($settings['notification'] ?? '[]'))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_out_of_stock($product, $datas, $settings);
                                        }
                                    }
                                }
                                if ($datas->product_stock <= ($settings['out_of_stock_threshold'] ?? '0') && $datas->stock_order_status == 'notify_customer') {
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
                                if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock", json_decode($settings['notification'] ?? '[]'))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::low_stock_threshold($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= ($settings['out_of_stock_threshold'] ?? '0')) {
                                if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock", json_decode($settings['notification'] ?? '[]'))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::out_of_stock($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= ($settings['out_of_stock_threshold'] ?? '0') && $Product->stock_order_status == 'notify_customer') {
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
                // P1-SEC-23. The owner of the lines to clear is the AUTHENTICATED customer, or -- for a guest --
                // the basket named by their own cart cookie. NOT `$request->customer_id`: for a guest that is
                // the id of the Customer processOrder() found or created from the billing e-mail they TYPED,
                // so keying the clear on it let a stranger delete a registered customer's basket lines.
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
        $order->product_price = $final_sub_total_price;
        $order->coupon_price = $coupon_price;
        $order->delivery_price = $delivery_price;
        $order->tax_price = $tax_price;
        if (!auth('customers')->user()) {
            if ($planShippingMethod == "on") {
                $order->final_price = $final_price + $delivery_price;
            } else {
                $order->final_price = $final_price;
            }

        } else {
            if (module_is_active('RewardClubPoint') && isset($requests_data['club_point_is_active']) && $requests_data['club_point_is_active'] == 'on') {
                $customerDetail = Customer::find($customer_id);
                $rewardSetting = getAdminAllSetting($store->created_by, $store->id) ?? null;
                $saveRewardPrice = ($customerDetail->total_club_point * $rewardSetting['reward_point_price'] ?? 0) / ($rewardSetting['reward_points'] ?? 0);
                if ($planShippingMethod == "on") {
                    $order->final_price = $final_price + $delivery_price - $saveRewardPrice;
                } else {
                    $order->final_price = $final_price - $saveRewardPrice;
                }
            } else {
                if ($planShippingMethod == "on") {
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
            __METHOD__,
            // P1-PAY-03. WHICH CHECKOUT THIS WRITER IS SETTLING. A verification record now names
            // the checkout that produced it, and this is the shared storefront writer -- the one
            // a record planted by an aborted capture on another route was measured being redeemed
            // at. Presenting the nonce is also what keeps a LEGITIMATE record usable here:
            // without it every bound record would be refused, which is an outage, not a control.
            $this->checkoutNonceOf($requests_data)
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
        /*
         * TRANSACTION BOUNDARY -- OPENS HERE.
         *
         * THE DEFECT. This is the THIRD order writer, and the only one that
         * still had no transaction: the twins in
         * PaymentController::getProductStatus() (:2207) and
         * PaymentService::getProductStatus() (:2614) each got a boundary, this
         * one did not, although it writes the Order row plus the same FOUR
         * child tables in the same way -- OrderBillingDetail,
         * OrderCouponDetail, UserCoupon and OrderTaxDetail, each saved on its
         * own. A failure part-way left an order with no billing address, or a
         * coupon marked consumed with no OrderCouponDetail recording what it
         * was consumed for, or the reverse -- a coupon recorded as applied to
         * the order but never marked used, so redeemable again. It also
         * auto-creates City rows (billing and delivery), which were likewise
         * uncommitted siblings.
         *
         * WHAT THIS COVERS. Every LOCAL write from the order row through the
         * last OrderTaxDetail row. They now commit together or not at all.
         *
         * WHAT THIS DOES NOT COVER, stated precisely rather than implied:
         *
         *  1. The stock loop and the per-line cart deletion above are OUTSIDE
         *     it, deliberately. That loop calls Utility::sendEmailTemplate and
         *     Utility::SendMsgs -- SMTP and SMS -- on its low-stock and
         *     back-in-stock branches. Holding row locks across a third party's
         *     response time is a worse defect than the one being fixed, so
         *     inventory is COMPENSATED in the catch instead of rolled back,
         *     via the $appliedStockChanges accumulator the loop already keeps.
         *
         *  2. The cart rows deleted inside that loop are NOT restored. They go
         *     before this boundary opens and recreating them would mean
         *     reconstructing cart state this method no longer holds. A failed
         *     order therefore still empties the cart. Unchanged, pre-existing
         *     behaviour, reported rather than hidden.
         *
         *  3. NOTHING EXTERNAL IS INSIDE. In particular there is no gateway
         *     call here at all -- whatsapp() builds a wa.me URL, it does not
         *     POST to anyone -- so the boundary wraps local writes only, which
         *     is the rule the other two boundaries follow.
         *
         * The exception is RE-THROWN, so the caller sees exactly what it saw
         * before: this adds atomicity and stock compensation without changing
         * the happy path or the failure response.
         *
         * The body below is NOT re-indented. A 170-line reindent of a 3,500-line
         * file that five agents are editing concurrently is a merge hazard that
         * buys nothing: PHP does not read indentation, and the begin/commit
         * markers delimit the block unambiguously.
         */
        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
        $order->save();
        // add in  Order table end
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentOrderStore($order, $slug);
        }
        // Utility::paymentWebhook($order);

        // P1-ORD-15: the posted values are IDS and this looked the city up by NAME. One resolver,
        // which also keeps a posted NAME out of cities.state_id / country_id (foreign keys).
        $billing_city_id = \App\Services\BillingGeography::resolveCityId(
            $billing['billing_city'] ?? null,
            $billing['billing_state'] ?? null,
            $billing['billing_country'] ?? null
        );

        // P1-ORD-15: the posted values are IDS and this looked the city up by NAME. One resolver,
        // which also keeps a posted NAME out of cities.state_id / country_id (foreign keys).
        $delivery_city_id = \App\Services\BillingGeography::resolveCityId(
            $billing['delivery_city'] ?? null,
            $billing['delivery_state'] ?? null,
            $billing['delivery_country'] ?? null
        );
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

            \Illuminate\Support\Facades\DB::commit();

            // The order exists now, so the cart may be emptied. Inside the
            // try but after the commit: a cart row that will not delete must
            // not roll back an order whose money has already moved, which is
            // why clearCartLines() swallows and logs per line.
            $this->clearCartLines($cartLinesToClear);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();

            // Compensate the inventory this request consumed before the
            // boundary opened. Without this a failed order write left stock
            // decremented with no order to account for it.
            $this->restoreStockDecrements($appliedStockChanges);

            \Log::critical('Order write failed and was rolled back; stock decrements were given back', [
                'site' => __METHOD__,
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
                    $variationOptions = explode(',', (string) ($variant_data->variation_option ?? ''));   // P1-PAY-01: null-safe; a bare deref here throws ErrorException
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

            // P1-ORD-08. BIND THE THANK-YOU PAGE TO THE SESSION THAT PLACED THE ORDER.
            //
            // The success payload below is forwarded by resources/views/payment/{whatsapp,telegram}
            // .blade.php to `order-complete?data[order_id]=<primary key>`. OrderController::
            // orderComplete() used to resolve that request input with Order::find(), which let any
            // visitor read any order by typing a sequential integer. It now honours the id only if
            // this session is the one that placed the order -- recorded here, server-side, so the
            // buyer's own redirect keeps working and nobody else's does. Capped, so a long-lived
            // session does not grow without bound.
            // P1-ORD-13 lifted this into App\Services\OrderCompleteBinding so the ten gateway
            // returns share one definition instead of copying this block.
            \App\Services\OrderCompleteBinding::remember($order->id);

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

                // P1-SEC-23. A customer's rows are theirs by `customer_id`. A GUEST's rows carry customer_id 0
                // and are told apart ONLY by `cookie_session_id`. The callers queue the AUTHENTICATED
                // customer's id (null for a guest), so a guest never reaches the numeric branch; matching
                // `customer_id = ''` used to make MySQL (non-strict, production) read it as 0 and delete EVERY
                // guest's row for the product in the store. No customer and no cookie means there is nothing
                // of this shopper's to clear -- skipped, never matched against 0.
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
