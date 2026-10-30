<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * D-13 RESIDUAL — THE THREE ORDER WRITERS THAT HAD NO COMPENSATION, AND THE FALSE CLAIM THAT THEY
 * WERE COVERED.
 * =================================================================================================
 *
 * D-13 fixed four gateway return handlers: a failure between the stock loop and the order write
 * left the inventory consumed with no order to claim it, so each one was wrapped in a guard that
 * gives the stock back unless the order had already been written. `GatewayStockDecrementRoutingTest`
 * ratchets those four.
 *
 * ITS COMMENT THEN SAID the remaining authorised writers *"reach this property through
 * `OrderWriteTransactionTest`, whose data provider covers them and not these."* **That was not
 * true, and I wrote it.** `OrderWriteTransactionTest::writers()` has exactly three rows —
 * `PaymentController::getProductStatus`, `PaymentService::getProductStatus` and
 * `PaymentController::whatsapp`. `AuthorizeNetPaymnetController::getOrderPaymentStatus`,
 * `PayUPaymentController::getProductStatus`, `Api\ApiController::place_order` and
 * `Api\ApiController::ordersave` appear in neither file. All four decrement stock through
 * `decrementStockOrFail()` and all four wrote the order afterwards with nothing to undo the loop —
 * the same defect, in four more places, behind a sentence asserting it was already handled.
 *
 * That is the costliest kind of error in this repository: not an open hole, but an open hole with a
 * note on it saying it was closed. A reviewer reading the ratchet had no reason to look.
 *
 * WHY THESE TWO ARE THE ONES DRIVEN END TO END
 * -------------------------------------------------------------------------------------------------
 * All four are now wrapped. Only `place-order` and `order-save` can be DRIVEN here: they are
 * storefront API routes that this suite already exercises with a real Sanctum token and a real
 * cart. AuthorizeNet needs a live Authorize.Net SDK response and PayU needs a valid salted hash;
 * neither can be faked in this environment, so for those two the evidence is the source ratchet in
 * `GatewayStockDecrementRoutingTest` and that limit is stated there rather than implied.
 *
 * SO THIS FILE IS DELIBERATELY NOT A SOURCE SCAN. The ratchet proves the guard is present; this
 * proves it WORKS — the stock figure comes back, through the real route, with the real middleware,
 * on the writers every mobile and storefront client actually calls.
 */
final class ApiOrderWriterStockCompensationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const OPENING_STOCK = 50;

    protected function setUp(): void
    {
        parent::setUp();

        // Both writers mail the customer and the store owner on success.
        Mail::fake();
    }

    /** @return array<string, array{0: string}> */
    public static function apiOrderWriters(): array
    {
        return [
            'place-order' => ['place-order'],
            'order-save' => ['order-save'],
        ];
    }

    /**
     * THE DEFECT. A failure during the order write must give back the stock the loop consumed.
     *
     * The injection is at `Order::creating`, which is after the stock loop and before the row
     * exists — exactly the window D-13 describes. Without the guard the shopper has no order, the
     * merchant has no sale, and the goods are off the shelf; and because this is the storefront
     * API, it is reachable with an ordinary COD checkout and no payment gateway at all.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_a_failure_during_the_order_write_gives_the_stock_back(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        \App\Models\Order::creating(static function (): void {
            throw new \RuntimeException('simulated failure during the order write');
        });

        $this->withoutExceptionHandling();

        $threw = null;
        try {
            $this->placeOrder($path, $store, $customer, $shippingId);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        /* THE PREMISE, and without it the stock assertion below passes for the wrong reason: the
           injection really fired, and it fired after the loop rather than before it. A fixture
           that never reached the order write would leave the stock untouched too. */
        $this->assertNotNull($threw,
            'the injected failure never reached the order write, so this case proves nothing about '
            . 'compensation — re-check the fixture before trusting the assertion below');
        $this->assertSame(0, (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'an order row survived the injected failure, so the window under test is not the one '
            . 'D-13 describes');

        $this->assertSame(self::OPENING_STOCK, $this->stockOf($product),
            "`$path` consumed the stock and then lost the order, leaving the inventory gone with "
            . 'nothing to claim it. The handler must give the decrements back when the order write '
            . 'fails (D-13 residual).');
    }

    /**
     * THE OTHER HALF OF THE RULE, and it is not symmetry for its own sake.
     *
     * Once the order EXISTS it claims its goods. Giving the stock back then would put the shopper's
     * items on the shelf for someone else while their paid order stands — overselling by way of a
     * safety net. The guard is gated on `$ssOrderWritten` for exactly that reason, and this case is
     * what stops a future simplification from dropping the gate and "fixing" D-13 into an
     * oversell.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_a_failure_after_the_order_is_written_keeps_the_stock_consumed(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        \App\Models\Order::created(static function (): void {
            throw new \RuntimeException('simulated failure after the order write');
        });

        $this->withoutExceptionHandling();

        try {
            $this->placeOrder($path, $store, $customer, $shippingId);
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(self::OPENING_STOCK - 2, $this->stockOf($product),
            "`$path` gave the stock back although the order had already been written. An order "
            . 'that exists claims its goods; returning them oversells the same units to the next '
            . 'shopper while this order stands.');
    }

    /**
     * THE PREMISE OF BOTH CASES ABOVE: the fixture really moves stock on the happy path.
     *
     * If this checkout decremented nothing — a missing `stock_management` row, a plan that fails to
     * resolve, a cart the handler does not find — then "the stock came back" and "the stock never
     * left" are the same observation and both cases are vacuous. This is the control.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_the_fixture_really_decrements_on_a_successful_order(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $this->placeOrder($path, $store, $customer, $shippingId);

        $this->assertSame(1, (int) DB::table('orders')->where('store_id', $store->id)->count(),
            "`$path` wrote no order on the happy path, so neither failure case above is measuring "
            . 'the window it claims to measure');
        $this->assertSame(self::OPENING_STOCK - 2, $this->stockOf($product),
            "`$path` moved no stock on the happy path, so the compensation cases above cannot "
            . 'distinguish a restored decrement from one that never happened');
    }

    // ============================================================================== the fixture

    /**
     * A store with stock management ON, a customer, a two-unit cart and a shipping row.
     *
     * @return array{0: \App\Models\Store, 1: Customer, 2: Product, 3: int}
     */
    private function checkout(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'd13-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        /* Several handlers read the platform admin through `Cache::remember('admin_details', ...)`,
           so without this they see the pre-fixture User. */
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

        DB::table('countries')->insertOrIgnore([
            'id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = Customer::forceCreate([
            'first_name' => 'D13', 'last_name' => 'Shopper',
            'email' => 'd13-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'd13-' . uniqid(), 'slug' => 'd13-' . uniqid(),
            'product_stock' => self::OPENING_STOCK, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 20.00, 'store_id' => $store->id,
        ]);

        return [$store, $customer, $product, $shippingId];
    }

    private function placeOrder(string $path, $store, Customer $customer, int $shippingId)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('d13')->plainTextToken,
        ])->postJson('/api/' . $store->slug . '/' . $path, [
            'customer_id' => $customer->id,
            'billing_info' => [
                'firstname' => 'D13', 'lastname' => 'Shopper', 'email' => 'd13@example.test',
                'billing_user_telephone' => '555111222',
                'billing_address' => 'Street', 'billing_postecode' => '11111',
                'billing_country' => 'Testland', 'billing_state' => 'Teststate',
                'billing_city' => 'Testcity', 'billing_company_name' => '',
                'delivery_address' => 'Street', 'delivery_postcode' => '11111',
                'delivery_country' => 'Testland', 'delivery_state' => 'Teststate',
                'delivery_city' => 'Testcity',
            ],
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ]);
    }

    /** Read through the table, NOT the model: `Product` is `Cachable`. */
    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }
}
