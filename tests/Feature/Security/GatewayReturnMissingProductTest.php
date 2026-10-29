<?php

namespace Tests\Feature\Security;

use App\Khalti\Khalti;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * D-22 / F121 -- A CART LINE WHOSE PRODUCT ROW IS GONE, ON A PAYMENT RETURN.
 *
 * HOW THIS DEFECT WAS FOUND, because it is the reason the owner's instruction about method is
 * attached to it. D-13's restoration work added `restoreStockDecrements()` at the three in-loop
 * error exits and a behavioural test was written for it. The test passed -- and then passed again
 * against the UNFIXED handlers, so it was deleted. Driving the fixture produced an HTTP 500 from
 * the cart read, which happens EARLIER in the request than the loop, so those exits were never
 * reached from there at all. The restores are defensive code, and the crash is this finding.
 *
 * THE OWNER SCOPED IT AS TWO HALVES THAT PULL APART:
 *
 *   - BEFORE the decrement, a missing row should refuse cleanly rather than 500.
 *   - AFTER the capture, a refusal is the wrong answer: the customer has paid, and the fix must
 *     not lose a paid order or ask them to pay twice.
 *
 * and closed with "لا تعتمد على اختبارات تمر حتى بعد التراجع عن الإصلاح" -- do not rely on tests
 * that pass even after the fix is reverted.
 *
 * SO THIS FILE MEASURES FIRST AND CLAIMS NOTHING. Each case drives a real gateway return whose
 * basket names a deleted product.
 *
 * SCOPE, corrected after review: every case here drives the AUTHENTICATED arm, which is the arm
 * that reads `ApiController::cart_list()` and the only one that can be paired with a real
 * verification outcome. An earlier version of this sentence claimed both arms and that was false --
 * load-bearingly so, since the guest arm is where two defects in this very change turned out to
 * live. The GUEST arm, which reads `Cart::cart_list_cookie()` and has its own copy of the
 * unguarded dereference, is driven by `GatewaySiblingMissingProductTest` across all four handlers,
 * and the completion of a verified guest capture by
 * `GatewayStockBeforeVerificationTest::test_a_verified_guest_capture_completes_and_reports_khaltis_status_code`.
 */
class GatewayReturnMissingProductTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SERVER_TOTAL = 100.00;
    private const SERVER_PAISA = 10000;

    /**
     * MEASURED FIRST, 2026-10-28, before anything was changed, by driving a real return whose
     * basket names a deleted row:
     *
     *   AUTH / capture VERIFIED, products row gone   -> ErrorException "Attempt to read property
     *                                                   \"id\" on null", 0 orders, no decrement
     *   AUTH / capture REJECTED, products row gone   -> the same ErrorException
     *   AUTH / capture VERIFIED, variant row gone    -> ErrorException "Attempt to read property
     *                                                   \"discount_price\" on null", 0 orders,
     *                                                   stock still 50
     *
     * So F121 is confirmed on a driven request, and two things follow. The crash PRECEDES the
     * decrement, so it is not a drain -- the stock figures above are the untouched ones. And it
     * precedes it because the cart read happens before the loop, which is exactly why D-13's
     * restoration test could never reach the in-loop exits it was written for.
     *
     * WHAT IS FIXED HERE, which is the half the owner authorised in those words: "before proof of
     * collection -> structured refusal without HTTP 500 and no illegitimate deduction". A dangling
     * basket line is detected BEFORE the cart read, and refused. The verification is also moved
     * above the cart read -- it only ever needed the stored checkout state and the store -- so that
     * when the refusal does fire on a capture that WAS collected, the log can name that capture.
     *
     * WHAT IS NOT FIXED, and is not claimed: "after proof -> do not lose the paid order". A paid
     * customer whose product was deleted mid-checkout still gets no order. They now get a
     * structured refusal and a `critical` log naming the verified capture and its amount, instead
     * of an unhandled exception and silence, which is strictly more recoverable but is not the
     * order. Writing one would mean deciding what an order line for a deleted product contains --
     * a shape the merchant UI, the invoices and the Flutter app all read -- and the owner forbade
     * inventing a substitute product or variant. That decision is recorded in the register with
     * its options; this file pins the half that is settled.
     *
     * THE BITE MATRIX, because "لا تعتمد على اختبارات تمر حتى بعد التراجع عن الإصلاح" is attached
     * to this finding and because running it taught me something about my own tests:
     *
     *   against HEAD (no hoist, no guard)        4 of 5 fail
     *   with the hoist only, guard removed       3 of 5 fail
     *   with both                                5 pass
     *
     * So `test_a_rejected_capture_...` does NOT detect the guard. It detects the HOIST: once the
     * verification sits above the cart read, a capture the gateway rejected is refused before the
     * read can crash, guard or no guard. It is kept, labelled as what it is, because it is the
     * case that would regress if the verification were ever moved back down -- but it is evidence
     * for D-13's change, not for this one. The three cases that bite on the guard are the VERIFIED
     * paths, which is the right answer: a verified capture is exactly the caller the hoist cannot
     * turn away.
     *
     * `test_an_intact_basket_is_untouched_by_the_guard` passes in all three states. That is not a
     * weak test, it is the control: a guard that refused every basket would satisfy every other
     * case in this file.
     */
    public function test_a_rejected_capture_on_a_dangling_basket_refuses_without_a_500(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => 100]]);
        DB::table('products')->where('id', $product->id)->delete();

        $o = $this->driveAuthenticated($store, $customer);

        $this->assertNull($o['exception'], 'an unpaid return on a dangling basket must not throw');
        $this->assertSame(0, $o['orders']);
    }

    public function test_a_verified_capture_on_a_dangling_basket_refuses_without_a_500(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);
        DB::table('products')->where('id', $product->id)->delete();

        $o = $this->driveAuthenticated($store, $customer);

        $this->assertNull($o['exception'], 'a PAID return on a dangling basket must not throw either');
        $this->assertSame(0, $o['orders'], 'and the order is still not written -- the BLOCKED half');
    }

    public function test_a_missing_variant_row_refuses_without_a_500(): void
    {
        [, $store, $customer, $product] = $this->checkout([], true);
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);
        DB::table('product_variants')->where('product_id', $product->id)->delete();

        $o = $this->driveAuthenticated($store, $customer);

        $this->assertNull($o['exception'], 'the variant half of the deref must be guarded too');
        $this->assertSame(0, $o['orders']);
        $this->assertSame(50, (int) DB::table('products')->where('id', $product->id)->value('product_stock'));
    }

    /**
     * NEITHER REFUSAL MAY TAKE STOCK, and the sharpest way to ask that is a basket with TWO lines
     * -- one dangling, one intact. If the guard fired per line, or anywhere below the loop, the
     * intact line would already be decremented by the time the bad one was noticed. It must be
     * refused whole.
     *
     * (The obvious version of this case does not work and the reason is worth recording: deleting a
     * `products` row CASCADES to its `product_variants` rows -- `onDelete('cascade')` on
     * `product_id` -- so a variant of the deleted product cannot survive to be measured. The first
     * cut of this test asserted its stock was still 50 and read 0, which was the cascade, not a
     * decrement.)
     */
    public function test_neither_refusal_consumes_the_catalogue(): void
    {
        [, $store, $customer, $product] = $this->checkout();

        $survivor = Product::forceCreate([
            'name' => 'd22-live-' . uniqid(), 'slug' => 'd22-live-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => self::SERVER_TOTAL, 'sale_price' => self::SERVER_TOTAL,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        DB::table('carts')->insert([
            'product_id' => $survivor->id, 'variant_id' => 0, 'qty' => 1,
            'customer_id' => $customer->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);
        DB::table('products')->where('id', $product->id)->delete();

        $o = $this->driveAuthenticated($store, $customer);

        $this->assertNull($o['exception']);
        $this->assertSame(
            50,
            (int) DB::table('products')->where('id', $survivor->id)->value('product_stock'),
            'the basket must be refused WHOLE -- a healthy line must not be decremented on the way '
            . 'to discovering a dangling one'
        );
    }

    /**
     * THE CONTROL. A basket with nothing missing must be completely unaffected -- same order, same
     * decrement. A guard that refused every basket would pass every case above.
     */
    public function test_an_intact_basket_is_untouched_by_the_guard(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        $o = $this->driveAuthenticated($store, $customer);

        $this->assertNull($o['exception']);
        $this->assertSame(1, $o['orders'], 'the guard must not refuse an ordinary basket');
        $this->assertSame(
            49,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            'and an ordinary basket must still decrement'
        );
    }

    /*
     * ------------------------------------------------------------------------------------------
     * D-22's SECOND HALF, 2026-10-29 — the capture is no longer only a log line.
     *
     * The cases above establish what the slice closed in October: no 500, no decrement, and no
     * order. The last of those is the part the owner's instruction names —
     * *"so that a paid order is not lost"* — and it is still not an order. What it IS now is a
     * `store_notifications` row the merchant sees in the web bell and in the shipped app, so a
     * confirmed capture can be settled or refunded deliberately instead of being discovered in a
     * support ticket. Writing the ORDER is option (A) in the register and stays BLOCKED, because
     * its safety is a claim about how a second repository renders a line whose product is gone.
     * ------------------------------------------------------------------------------------------
     */

    public function test_a_confirmed_capture_that_produced_no_order_reaches_the_merchant(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);
        DB::table('products')->where('id', $product->id)->delete();

        $this->driveAuthenticated($store, $customer);

        $row = DB::table('store_notifications')
            ->where('store_id', $store->id)
            ->where('type', 'payment_needs_attention')
            ->first();

        $this->assertNotNull(
            $row,
            'a CONFIRMED capture produced no order and left the merchant nothing to act on.'
            . ' A `critical` log is not a merchant-visible record.'
        );

        $data = json_decode((string) $row->data, true);
        $this->assertSame('khalti', $data['gateway'] ?? null);
        $this->assertSame('confirmed', $data['capture_state'] ?? null, 'the capture state must be the proven one');
        $this->assertSame('missing_product_row', $data['cause'] ?? null);
        $this->assertNotEmpty($row->message, 'the notification must say what happened');

        /*
         * `reference_type` IS THE FLUTTER CONTRACT, and it is asserted here rather than trusted.
         * `notification_screen.dart` gates BOTH its order footer and its tap-to-open on
         * `referenceType == 'order'`. A row that claimed to be an order notification and carried
         * no order id would render an empty footer and a tap that goes nowhere.
         */
        $this->assertSame('payment', $row->reference_type, 'this is not an order notification');
        $this->assertNull($row->reference_id, 'there is no order to reference -- that is the point');

        $this->assertSame(0, (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'recording the capture must NOT be mistaken for writing the order');
    }

    /**
     * A REJECTED CAPTURE RINGS NO BELL — AND THE FIRST VERSION OF THIS TEST PASSED FOR THE WRONG
     * REASON, which a bite caught.
     *
     * It asserted only that no notification exists, and that is true for a reason that has nothing
     * to do with the condition it was written to pin: `KhaltiPaymnetController` refuses a REJECTED
     * capture at the verification, which D-22 hoisted ABOVE the cart read, so the basket guard is
     * never reached and the code that writes the bell never runs. Deleting the
     * `$captureState !== 'rejected'` test from the refusal left this green.
     *
     * So it now asserts the MECHANISM: the handler refuses before the guard. If the verification
     * is ever moved back below the cart read, this fails — which is the regression that matters,
     * because that move is also what makes F121's crash reachable again.
     */
    public function test_a_rejected_capture_is_refused_before_the_basket_guard_is_reached(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        // An amount that does not match the server's expectation -> the verifier REJECTS it.
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => 100]]);
        DB::table('products')->where('id', $product->id)->delete();

        $o = $this->driveAuthenticated($store, $customer);

        $this->assertNull($o['exception']);
        $this->assertSame(0, $o['orders']);
        $this->assertSame(
            0,
            (int) DB::table('store_notifications')->where('type', 'payment_needs_attention')->count(),
            'no money was captured, so there is nothing for the merchant to settle — and the'
            . ' handler does not even reach the code that would decide that, because the'
            . ' verification sits above the cart read.'
        );
    }

    /**
     * AN UNVERIFIABLE CAPTURE IS THE DANGEROUS ONE, and it is the branch the bite matrix showed
     * was untested.
     *
     * `KhaltiPaymnetController` refuses only when the capture is `! isVerified() && ! unverifiable`
     * — so VERIFIED and UNVERIFIABLE both fall through to the basket guard. `UNVERIFIABLE` is
     * defined on `PaymentVerificationResult` as *"verification could not be performed at all"*,
     * with the explicit note that it is NOT success: an unreachable gateway or a missing shared
     * secret. The card may well have been charged.
     *
     * A `status_code` of 0 is exactly that case (`verifyKhaltiCapture`, `$status === 0`).
     *
     * The first mapping I wrote collapsed this into `rejected` and told the merchant nothing. No
     * test reached it, because the three unverified gateways produce `$verification === null` and
     * take a different branch — so the bite "unverifiable is collapsed into rejected" did NOT
     * bite, and that is why this case exists.
     */
    public function test_an_unverifiable_capture_tells_the_merchant_the_state_is_unknown(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        // No status at all -> the gateway could not be reached -> UNVERIFIABLE, not rejected.
        $this->fakeKhalti(['data' => ['amount' => self::SERVER_PAISA]]);
        DB::table('products')->where('id', $product->id)->delete();

        $o = $this->driveAuthenticated($store, $customer);

        $this->assertNull($o['exception']);
        $this->assertSame(0, $o['orders']);

        $row = DB::table('store_notifications')
            ->where('store_id', $store->id)
            ->where('type', 'payment_needs_attention')
            ->first();

        $this->assertNotNull(
            $row,
            'an UNVERIFIABLE capture reaches the basket guard and may already have charged the'
            . ' card. Saying nothing is the worst of the three answers.'
        );

        $data = json_decode((string) $row->data, true);
        $this->assertSame(
            'unknown',
            $data['capture_state'] ?? null,
            'unverifiable is NOT rejected: the gateway could not be reached, so the state is'
            . ' unknown and the message must not claim the payment was confirmed'
        );
    }

    public function test_a_refreshed_return_does_not_ring_the_bell_twice(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);
        DB::table('products')->where('id', $product->id)->delete();

        $this->driveAuthenticated($store, $customer);
        $this->driveAuthenticated($store, $customer);
        $this->driveAuthenticated($store, $customer);

        $this->assertSame(
            1,
            (int) DB::table('store_notifications')
                ->where('store_id', $store->id)
                ->where('type', 'payment_needs_attention')
                ->count(),
            'the gateway return is reachable by refresh, so three returns must leave one row'
        );
    }

    /**
     * THE TENANCY CLAIM, DRIVEN RATHER THAN DECORATED — and the first version of this case was
     * half decorative, which the independent review caught.
     *
     * It asserted that store B got no row while nothing in the request, the fixture or the code
     * mentioned store B at all: store B had no cart, no checkout state and no product, so that
     * assertion could not have failed however the handler behaved. A test that cannot fail is not
     * evidence of isolation.
     *
     * The assertion that CAN fail is the one below: the return request carries a crafted
     * `store_id` and `slug` naming store B while the session state names store A. If any handler
     * on this path ever derives its store from request input — the single most productive defect
     * shape in this codebase — the row lands in store B's bell and this fails.
     */
    public function test_a_crafted_store_in_the_return_request_cannot_move_the_notification(): void
    {
        [, $storeA, $customerA, $productA] = $this->checkout();
        [, $storeB] = $this->makeTenant();

        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);
        DB::table('products')->where('id', $productA->id)->delete();

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($customerA, 'customers')
                ->withSession(['request_data' => $this->state])
                ->post('/' . $storeA->slug . '/order-khalti', [
                    'payload' => ['token' => 'tok-' . uniqid(), 'amount' => self::SERVER_PAISA],
                    // The attacker-supplied half. Neither may be believed.
                    'store_id' => $storeB->id,
                    'slug' => $storeB->slug,
                ]);
        } catch (\Throwable $e) {
            $this->fail('the crafted return must not throw: ' . get_class($e) . ': ' . $e->getMessage());
        }

        $this->assertSame(
            1,
            (int) DB::table('store_notifications')
                ->where('store_id', $storeA->id)->where('type', 'payment_needs_attention')->count(),
            'the capture belongs to the store whose checkout state the session holds'
        );
        $this->assertSame(
            0,
            (int) DB::table('store_notifications')
                ->where('store_id', $storeB->id)->where('type', 'payment_needs_attention')->count(),
            'a store_id or slug in the REQUEST must not move another tenant\'s capture into this'
            . " bell. `StoreNotification` is not in `config/tenancy.php`'s `scoped_models`, so the"
            . ' explicit store predicate is the only control there is.'
        );
    }

    public function test_the_new_type_is_registered_with_a_permission(): void
    {
        /*
         * `MerchantNotificationController` REFUSES AN UNREGISTERED TYPE, so a row written by the
         * service and absent from the registry would never reach the web bell -- the capture
         * would be recorded and still invisible, which is the defect this half set out to fix.
         * The registry is also what stops the bell becoming a way around `Manage Order`: this
         * message names a gateway and an amount.
         */
        $this->assertArrayHasKey(
            'payment_needs_attention',
            \App\Http\Controllers\MerchantNotificationController::TYPE_PERMISSIONS,
            'the type the gateway refusal writes is not registered, so the web bell drops it'
        );
        $this->assertSame(
            'Manage Order',
            \App\Http\Controllers\MerchantNotificationController::TYPE_PERMISSIONS['payment_needs_attention']
        );
    }

    // ---------------------------------------------------------------- measurement

    private function driveAuthenticated($store, $customer): array
    {
        $exception = null;
        $status = 0;
        $this->withoutExceptionHandling();

        try {
            $response = $this->actingAs($customer, 'customers')
                ->withSession(['request_data' => $this->state])
                ->post('/' . $store->slug . '/order-khalti', [
                    'payload' => ['token' => 'tok-' . uniqid(), 'amount' => self::SERVER_PAISA],
                ]);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            $exception = get_class($e) . ': ' . $e->getMessage();
        }

        return [
            'status' => $status,
            'exception' => $exception,
            'orders' => (int) DB::table('orders')->where('store_id', $store->id)->count(),
        ];
    }

    // ---------------------------------------------------------------- fixture

    private array $state = [];

    /** @return array{0:\App\Models\User,1:\App\Models\Store,2:Customer,3:Product} */
    private function checkout(array $stateOverrides = [], bool $variant = false): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'd22-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            ['countries', ['id' => 101, 'name' => 'Syria']],
            ['states', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101]],
            ['cities', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1]],
        ] as [$table, $row]) {
            DB::table($table)->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        $customer = Customer::forceCreate([
            'first_name' => 'D22', 'last_name' => 'Buyer',
            'email' => 'd22-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'd22-' . uniqid(), 'slug' => 'd22-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => $variant ? 1 : 0,
            'price' => self::SERVER_TOTAL, 'sale_price' => self::SERVER_TOTAL,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $variantId = 0;
        if ($variant) {
            $variantId = DB::table('product_variants')->insertGetId([
                'product_id' => $product->id, 'variant' => 'Red',
                'sku' => 'd22-sku-' . uniqid(), 'variation_option' => 'manage_stock',
                'stock' => 50, 'price' => self::SERVER_TOTAL,
                'low_stock_threshold' => 0, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('carts')->insert([
            'product_id' => $product->id, 'variant_id' => $variantId, 'qty' => 1,
            'customer_id' => $customer->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->state = array_merge([
            'slug' => $store->slug, 'store_id' => $store->id,
            'customer_id' => $customer->id, 'payment_type' => 'khalti',
            'shipping_final_price' => 0, 'tax_price' => 0,
            'cartlist_final_price' => self::SERVER_TOTAL, 'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ], $stateOverrides);

        return [$merchant, $store, $customer, $product];
    }

    private function fakeKhalti(array $response): Khalti
    {
        $spy = new class($response) extends Khalti {
            public int $calls = 0;

            public function __construct(private array $canned)
            {
            }

            public function verifyPayment($secret, $token, $amount)
            {
                $this->calls++;

                return $this->canned;
            }
        };

        $this->app->instance(Khalti::class, $spy);

        return $spy;
    }
}
