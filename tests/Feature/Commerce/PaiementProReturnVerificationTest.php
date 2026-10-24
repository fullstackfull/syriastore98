<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE PAIEMENTPRO RETURN HAD NO GATEWAY CHECK AT ALL, SO IT WROTE A PAID ORDER FOR ANY RETURN.
 * P1-PAY-03 (#25), under the owner's Phase 01 final execution directive.
 * =================================================================================================
 *
 * `PaiementProPaymentController::getProductStatus` opened with
 *
 *     // if ($request->responsecode == 0) {
 *
 * commented out. `git blame` puts that at the boundary commit, so it arrived that way rather than
 * being disabled by this project. The handler therefore wrote an order whatever PaiementPro said,
 * including for a declined payment and for a bare GET to the callback URL.
 *
 * WHY THE COMMENT WAS NOT SIMPLY RESTORED, and both reasons are measured rather than argued:
 *
 *   1. THAT GATE NEVER WORKED. `== 0` is loose, and in PHP 8 `null == 0` is TRUE, so an ABSENT
 *      responsecode satisfied it. A prior cycle recorded exactly this on the sibling PLAN path in
 *      the same file (:149-151). Restoring it would install a check that passes the attack it is
 *      meant to stop -- which is what `test_a_return_with_no_response_code_is_not_paid` pins, and
 *      what the mutation run confirms: relaxing the strict comparison back to `== 0` turns that
 *      test red.
 *   2. RESTORING IT WOULD REFUSE RATHER THAN RECONCILE. An `if` around the whole handler stops the
 *      order being written at all, and if PaiementPro's real return does not carry `responsecode`
 *      under that name -- which this repository cannot establish -- every legitimate customer is
 *      charged with nothing recorded. That is what the directive's second sentence forbids:
 *      "Do not create a falsely paid order, and preserve a safe reconciliation path for customers
 *      who may have been charged."
 *
 * SO THE ORDER IS ALWAYS WRITTEN AND ONLY ITS STATUS MOVES. A strictly-'0' responsecode records a
 * verification and the order is Paid; anything else records none and
 * `PaymentVerificationService::resolveOrderPaymentStatus()` resolves it to Pending. Fail-closed on
 * the money, fail-open on the record.
 *
 * THE ASSERTIONS READ `orders.payment_status` THROUGH `DB::table()` because `Order` is `Cachable`.
 */
class PaiementProReturnVerificationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const BASKET = 100.00;

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'pp-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $n => $v) {
            DB::table('settings')->insert(['name' => $n, 'value' => $v, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        (new \App\Models\Setting)->flushCache();

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $store];
    }

    private function shopperWithBasket($store): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'pp-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $product = Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => self::BASKET, 'sale_price' => self::BASKET,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'qty' => 1, 'price' => self::BASKET, 'store_id' => $store->id]);

        return $customer;
    }

    private function checkoutState($store, $customerId): array
    {
        return [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customerId,
            'payment_type' => 'Paiementpro', 'shipping_final_price' => 0, 'tax_price' => 0,
            'cartlist_final_price' => self::BASKET, 'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101', 'billing_state' => '1',
                'billing_city' => '1', 'billing_company_name' => '', 'delivery_address' => 'x',
                'delivery_postcode' => '1', 'delivery_country' => '101', 'delivery_state' => '1',
                'delivery_city' => '1',
            ],
        ];
    }

    /** Drive the real return and hand back the order row that was written, if any. */
    private function driveReturn($store, Customer $customer, array $body): ?object
    {
        $before = (int) DB::table('orders')->max('id');

        $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->checkoutState($store, $customer->id)])
            ->post('/' . $store->slug . '/get-payment-paiementpro',
                array_merge(['txnid' => 'pp-' . uniqid()], $body));

        return DB::table('orders')->where('id', '>', (int) $before)->orderBy('id')->first();
    }

    /**
     * THE PREMISE: this fixture reaches the order writer at all.
     *
     * Every status assertion below is satisfied by a handler that writes nothing, so if this route
     * is not drivable from here the rest of the file measures nothing.
     *
     * INVERTED, AND THE INVERSION IS THE FINDING. This test previously asserted
     * `assertSame('Paid', ...)` under the heading "PRESERVE FIRST: a genuine PaiementPro success
     * must still be Paid", because the handler recorded `responsecode === '0'` as a verification.
     * That was wrong under owner decision D2 -- "a return URL, redirect or client flag proves
     * nothing" -- which the commit that wrote this test did not weigh. `responsecode` arrives from
     * the caller's own browser on a `Route::any`; the handler makes no provider call of any kind;
     * so the value proves only that somebody typed it. Both exploits it enabled were reproduced on
     * the real route and are pinned by the three tests at the end of this file.
     *
     * So the premise this test now carries is narrower and still load-bearing: the fixture reaches
     * the ORDER WRITER. The payment status it asserts is Pending, like every other case here --
     * which means this file no longer contains a positive control for "Paid", because under D2
     * PaiementPro has no path to Paid at all. The positive control for the SEAM lives in
     * PaymentStatusDerivationTest, where a recorded verification does yield Paid.
     */
    public function test_a_successful_looking_return_still_writes_an_order_and_it_is_pending(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $order = $this->driveReturn($store, $customer, ['responsecode' => '0', 'status' => 'success']);

        $this->assertNotNull($order,
            'Premise: the return wrote an order. Without one nothing below is measuring the '
            . 'payment status of anything -- and D2 requires the order, because a refusal would '
            . 'leave a charged customer with no record.');
        $this->assertSame('Pending', (string) $order->payment_status,
            'A PaiementPro return that LOOKS successful is still not provider-verified. D2: an '
            . 'unverifiable gateway stays Pending and writes no paid ledger entry.');
    }

    /**
     * THE DEFECT: a return that says nothing about the payment is not a payment.
     *
     * This is the case the old loose `== 0` gate ACCEPTED, because `null == 0` is true in PHP 8.
     * A bare POST to the callback with no responsecode at all produced a Paid order.
     */
    public function test_a_return_with_no_response_code_is_not_paid(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $order = $this->driveReturn($store, $customer, ['status' => 'success']);

        $this->assertNotNull($order,
            'the order must STILL BE WRITTEN. A refusal here would leave a charged customer with '
            . 'no record, which is the failure the owner decision explicitly forbids.');
        $this->assertSame('Pending', (string) $order->payment_status,
            'a return carrying no responsecode produced a PAID order. `null == 0` is true in PHP 8, '
            . 'which is why the comparison is strict and why restoring the commented-out gate '
            . 'would not have closed this.');
    }

    /** AND A DECLINED PAYMENT IS NOT A PAYMENT EITHER. */
    public function test_a_declined_return_is_not_paid(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $order = $this->driveReturn($store, $customer, ['responsecode' => '1', 'status' => 'failed']);

        $this->assertNotNull($order, 'the order is still written, so the customer has a record.');
        $this->assertSame('Pending', (string) $order->payment_status,
            'a return whose responsecode says the payment did NOT succeed produced a Paid order.');
    }

    /**
     * AND A VALUE THAT MERELY LOOKS LIKE SUCCESS IS NOT SUCCESS.
     *
     * `'0abc'` is what a loose comparison would coerce, and `'00'` is the near-miss a string
     * comparison has to decide about deliberately rather than by accident.
     *
     * `' 0'` IS DELIBERATELY NOT IN THIS LIST, and measuring it is how that was established. It was
     * here first, expecting Pending, and the test reported Paid -- because Laravel's `TrimStrings`
     * middleware is in the GLOBAL stack (the framework registers it at
     * Foundation/Configuration/Middleware.php:460 and this application does not remove it), so a
     * posted `' 0'` reaches the handler already trimmed to `'0'`. That is not a bypass of the
     * strict comparison: by the time the handler runs, the value genuinely IS the success code, and
     * a provider sending `" 0"` means 0. Asserting otherwise would have pinned the framework's
     * behaviour as a defect.
     */
    public function test_a_response_code_that_only_resembles_success_is_not_paid(): void
    {
        foreach (['0abc', '00', 'false', ''] as $code) {
            [, $store] = $this->tenant();
            $customer = $this->shopperWithBasket($store);

            $order = $this->driveReturn($store, $customer, ['responsecode' => $code]);

            $this->assertNotNull($order, "responsecode={$code}: the order is still written.");
            $this->assertSame('Pending', (string) $order->payment_status,
                "responsecode=" . var_export($code, true) . " was accepted as PaiementPro's "
                . 'success code. Only the exact string 0 is.');
        }
    }

    // =====================================================================================
    //  TWO PAID-WITHOUT-PAYMENT SHAPES THIS FILE'S FIRST VERSION DID NOT COVER, found by an
    //  independent verification pass over the commit that created it. Written RED.
    // =====================================================================================

    /**
     * SHAPE 1 -- A VERIFICATION PLANTED BY A REFUSED REQUEST SURVIVES INTO THE NEXT ONE.
     *
     * `getProductStatus()` records the verification BEFORE it reads the stored checkout state, and
     * the refusal at `storedCheckoutState() === null` returns without clearing it. Measured on the
     * real route: a POST carrying `responsecode=0` and no stored state writes no order and leaves
     *
     *   ['payment_type' => 'paiementpro', 'reason' => 'PaiementPro return responsecode=0', ...]
     *
     * in the session. A LATER return in the same session -- a genuine checkout, with no
     * `responsecode` at all -- then redeems it.
     *
     * This is the exact defect P1-PAY-03 exists to close, re-opened by the commit that closed it,
     * on the one gateway that commit wired end to end.
     */
    public function test_a_verification_from_a_refused_return_cannot_be_redeemed_by_a_later_one(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        // The plant: a success code with NO stored checkout state. The handler must refuse.
        $before = (int) DB::table('orders')->max('id');

        $this->actingAs($customer, 'customers')
            ->post('/' . $store->slug . '/get-payment-paiementpro', ['responsecode' => '0']);

        $this->assertNull(DB::table('orders')->where('id', '>', $before)->first(),
            'Fixture premise: the planting request must write NO order, or this measures something else.');

        // The redemption: a genuine checkout state, and no success code of its own.
        $order = $this->driveReturn($store, $customer, []);

        $this->assertNotNull($order, 'Fixture premise: the second return must reach the order writer.');
        $this->assertSame('Pending', $order->payment_status,
            'A verification recorded by a REFUSED request was redeemed by a later return that carried no '
            . 'success code of its own. That is an order marked Paid with no payment.');
    }

    /**
     * SHAPE 2 -- `responsecode` IS THE CALLER'S OWN FIELD, AND OWNER DECISION D2 SAYS IT PROVES
     * NOTHING.
     *
     * THIS TEST INVERTS `test_a_successful_return_writes_a_paid_order` ABOVE, and the inversion is
     * the point rather than a repair. That test asserted that `responsecode=0` yields Paid, which
     * was right under the reasoning of the commit that wrote it: a strict `=== '0'` is far better
     * than the commented-out loose `== 0` that preceded it, and it is what a genuine PaiementPro
     * success carries.
     *
     * It is wrong under D2, which this project is bound by and which that commit did not weigh:
     *
     *   "Gateways: a return URL, redirect or client flag proves nothing; require provider-side
     *    verification of ownership, reference, amount, currency and status; ... an unverifiable
     *    gateway stays Pending and writes no paid ledger entry"
     *
     * `getProductStatus()` makes NO provider call. There is no `Http::` request, no transaction
     * lookup, no signature check -- `responsecode` arrives in the request bag from the caller's own
     * browser, on a `Route::any` (routes/customer.php:195) that accepts GET as readily as POST. So
     * recording it as a *verification* told `PaymentVerificationService` that something had been
     * verified when nothing had, and any caller who could reach the return URL could mint a Paid
     * order for a basket they never paid for.
     *
     * PaiementPro is therefore an UNVERIFIABLE gateway in D2's sense, and the correct behaviour is
     * the one D2 names: Pending, order written, merchant reconciles. The code change is to stop
     * recording a verification from a caller-supplied field at all -- which also removes shape 1,
     * because there is then nothing to plant.
     *
     * WHAT THIS COSTS, stated rather than discovered later: every PaiementPro order is now Pending
     * and no merchant wallet is credited for one until a human confirms it. That is the price D2
     * sets. Restoring automatic settlement needs PaiementPro's own status endpoint and a
     * credential, which is per-gateway work recorded on #25.
     */
    public function test_a_caller_supplied_success_code_is_not_a_verification(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $order = $this->driveReturn($store, $customer, ['responsecode' => '0']);

        $this->assertNotNull($order, 'Fixture premise: the return must still WRITE the order -- D2 requires '
            . 'the reconciliation path, so a refusal here would be the worse failure.');
        $this->assertSame('Pending', $order->payment_status,
            'A success code the CALLER supplied was treated as a provider verification. getProductStatus() '
            . 'makes no provider call, so there is nothing behind this value but the browser that sent it.');
    }

    /** And nothing may be left in the session for a later request to find. */
    public function test_no_verification_record_is_left_behind_by_a_paiementpro_return(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $this->actingAs($customer, 'customers')
            ->post('/' . $store->slug . '/get-payment-paiementpro', ['responsecode' => '0']);

        $this->assertNull(session(\App\Services\PaymentVerificationService::SESSION_KEY),
            'A PaiementPro return left a verification record in the session. Whatever writes one must '
            . 'also be reached by the code that consumes it, or a refused request arms the next one.');
    }
}
