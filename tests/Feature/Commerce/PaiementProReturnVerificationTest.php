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
     * is not drivable from here the rest of the file measures nothing. A successful return is the
     * control because it is the one case whose outcome was already correct.
     */
    public function test_a_successful_return_writes_a_paid_order(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $order = $this->driveReturn($store, $customer, ['responsecode' => '0', 'status' => 'success']);

        $this->assertNotNull($order,
            'Premise: the return wrote an order. Without one nothing below is measuring the '
            . 'payment status of anything.');
        $this->assertSame('Paid', (string) $order->payment_status,
            'PRESERVE FIRST: a genuine PaiementPro success must still be Paid. The gateway said '
            . 'responsecode=0, which is its success code, and that is recorded as a verification.');
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
}
