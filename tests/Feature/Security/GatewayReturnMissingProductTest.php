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
