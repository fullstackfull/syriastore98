<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * A FLAT COUPON IS SUBTRACTED ONCE PER CART LINE, SO A MULTI-LINE BASKET CAN BE DRIVEN FREE.
 * ==========================================================================================
 * P4-F001 / P4-F063 / P4-F062 — the Phase 04 findings register, `findings/cart.md` and
 * `findings/pricing-discounts.md`.
 *
 * `Cart::getCouponTotalAmount()` is a pure function of ONE line's price: its `flat` arm returns
 * `$subTotal - $coupon->discount_amount`, floored at zero (`Cart.php:1173`). Both cart readers call
 * it INSIDE their per-line loop and accumulate the difference:
 *
 *     $coupon_apply_price = Cart::getCouponTotalAmount($coupon, $final_price, ...);
 *     $coupon_price      += $final_price - $coupon_apply_price;        // once PER LINE
 *
 * So a flat 50.00 coupon takes 50.00 off *every* line. The floor makes it worse rather than
 * better: five 40.00 lines each floor to zero, the accumulated discount is 200.00, and the basket
 * goes free. `apply_coupon()` — the endpoint the shopper actually presses — gets this right
 * (`ApiController.php:4581`, one subtraction from one basket subtotal), so the two coupon
 * arithmetics in this codebase disagree, and the wrong one is the one that reaches the order.
 *
 * WHY IT IS MONEY AND NOT A DISPLAY BUG. `PaymentService::getProductStatus()` — the COD and
 * bank-transfer order writer — takes `$total_sub_price = $response['total_sub_price']`
 * (`PaymentService.php:2368`) and writes it straight into `orders.final_price` (`:2807`, `:2809`,
 * `:2843`, `:2845`). `total_sub_price` is the cart reader's figure, computed with the per-line
 * discount. `AuthorizeNetPaymnetController:550` and `PayUPaymentController:360` read the same key.
 *
 * THE SECOND DEFECT IN THE SAME ARITHMETIC (P4-F062). `getCouponTotalAmount()` checks expiry,
 * `coupon_limit` and `coupon_limit_user`, and never reads `minimum_spend` or `maximum_spend`. The
 * server-side spend predicate `ApiController::couponSpendRefusal()` has exactly one caller,
 * `apply_coupon` at `:4509`. So a coupon the basket does not qualify for still reduces the cash a
 * COD driver collects, while `orders.coupon_price` stays 0 because `apply_coupon` correctly
 * refused — the order's own records disagree with its total.
 *
 * WHAT THESE TESTS DELIBERATELY DO NOT CHANGE, because two of the three coupon types are right:
 *
 *   - `percentage` does not multiply. D% of each line summed IS D% of the basket, so
 *     `test_a_percentage_coupon_is_not_changed_by_the_per_basket_fix` must pass before and after.
 *   - `fixed product discount` is per-matching-line BY DESIGN — it is a per-product discount, and
 *     `test_a_fixed_product_discount_stays_per_matching_line` pins that so the fix cannot
 *     over-reach into it.
 *
 * MULTI-LINE IS THE WHOLE POINT, and it is why this was not already covered. Every existing coupon
 * test builds exactly ONE Cart row: `CouponDiscountAuthorizationTest`'s fixture helper (:152-179),
 * `OrderTotalCouponTest`, `CouponAmountSourceTest::shopperWithBasket` and
 * `RefusedCouponLinePriceTest` (which calls `getCouponTotalAmount()` directly, one line at a time,
 * and is therefore structurally incapable of seeing this). A one-line basket is the one case where
 * per-line and per-basket agree.
 */
class MultiLineCouponArithmeticTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Three 40.00 lines. Subtotal 120.00. */
    private const LINE = 40.00;
    private const FLAT = 50.00;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'mlc-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
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

    private function productIn($store, float $price = self::LINE): Product
    {
        return Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 0,
            'variant_product' => 0, 'price' => $price, 'sale_price' => $price,
            'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'mlc-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * @param  int  $lines  DISTINCT products, one Cart row each — not one row with qty N. The
     *                      defect is per-ROW, and a qty of 3 on one row is a single row.
     * @return \App\Models\Product[]
     */
    private function basketFor($store, Customer $customer, int $lines): array
    {
        $products = [];
        for ($i = 0; $i < $lines; $i++) {
            $product = $this->productIn($store);
            Cart::forceCreate([
                'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => self::LINE, 'store_id' => $store->id,
            ]);
            $products[] = $product;
        }

        return $products;
    }

    private function guestBasketFor($store, string $session, int $lines): array
    {
        $products = [];
        for ($i = 0; $i < $lines; $i++) {
            $product = $this->productIn($store);
            Cart::forceCreate([
                'cookie_session_id' => $session, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => self::LINE, 'store_id' => $store->id,
            ]);
            $products[] = $product;
        }

        return $products;
    }

    private function coupon($store, string $code, array $overrides = []): int
    {
        return (int) DB::table('coupons')->insertGetId($overrides + [
            'coupon_name' => $code, 'coupon_code' => $code,
            'coupon_type' => 'flat', 'discount_amount' => self::FLAT,
            'coupon_limit' => 1000, 'coupon_limit_user' => 1000,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'free_shipping_coupon' => 0, 'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The signed-in cart reader, over HTTP, as the Flutter storefront and the web checkout reach it. */
    private function cartList($store, Customer $customer, string $code): array
    {
        $token = $customer->createToken('t')->plainTextToken;
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $store->slug . '/cart-list', [
                'customer_id' => $customer->id, 'coupon_code' => $code,
            ]);

        return (array) ($response->json('data') ?? []);
    }

    // =============================================================================================
    // The defect
    // =============================================================================================

    /**
     * THE HEADLINE. Three 40.00 lines, one flat 50.00 coupon: the discount is 50.00, not 150.00.
     */
    public function test_a_flat_coupon_reduces_a_three_line_basket_by_its_amount_once(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 3);
        $this->coupon($store, 'FLAT50');

        $data = $this->cartList($store, $customer, 'FLAT50');

        $this->assertCount(3, $data['product_list'] ?? [],
            'Premise: the cart read three lines. A shorter basket cannot show a per-line discount.');
        $this->assertEqualsWithDelta(120.0, (float) ($data['total_final_price'] ?? -1), 0.005,
            'Premise: the pre-coupon subtotal is 3 x 40.00.');

        $this->assertEqualsWithDelta(self::FLAT, (float) ($data['total_coupon_price'] ?? -1), 0.005,
            'A flat 50.00 coupon took ' . ($data['total_coupon_price'] ?? '?') . ' off a 120.00 '
            . 'basket. getCouponTotalAmount() is called once per cart line and each call subtracts '
            . 'the whole discount_amount.');
        $this->assertEqualsWithDelta(70.0, (float) ($data['total_sub_price'] ?? -1), 0.005,
            'total_sub_price is what PaymentService::getProductStatus writes into '
            . 'orders.final_price for COD, so this figure is the cash a driver collects.');
    }

    /**
     * AND THE FLOOR MAKES IT WORSE, NOT SAFER: five lines of 40.00 each floor to zero against a
     * flat 50.00, so the accumulated discount is the whole 200.00 basket.
     */
    public function test_a_flat_coupon_cannot_drive_a_multi_line_basket_to_nothing(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 5);
        $this->coupon($store, 'FLAT50');

        $data = $this->cartList($store, $customer, 'FLAT50');

        $this->assertEqualsWithDelta(200.0, (float) ($data['total_final_price'] ?? -1), 0.005,
            'Premise: the pre-coupon subtotal is 5 x 40.00.');
        $this->assertEqualsWithDelta(150.0, (float) ($data['total_sub_price'] ?? -1), 0.005,
            'A 200.00 basket with a flat 50.00 coupon came to '
            . ($data['total_sub_price'] ?? '?') . '. Each 40.00 line floors to zero against the '
            . 'full 50.00 discount, so the basket was given away.');
    }

    /**
     * A single line is the one case where per-line and per-basket agree, and it is already right —
     * so this test passes before the fix as well as after. It is here as a guard: the bound is
     * `min(basket, discount_amount)`, and a fix that wrote the flat amount in unconditionally would
     * turn this 40.00 line into a 50.00 credit.
     *
     * MEASURED BEFORE THE FIX: coupon 40.00, total_sub_price 0.00. My first version of this test
     * expected 50.00 and was simply wrong — the floor at `Cart.php:1173` is correct and is what
     * caps a single line at its own value.
     */
    public function test_a_flat_coupon_on_a_single_line_is_capped_at_that_line(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 1);
        $this->coupon($store, 'FLAT50');

        $data = $this->cartList($store, $customer, 'FLAT50');

        $this->assertEqualsWithDelta(self::LINE, (float) ($data['total_coupon_price'] ?? -1), 0.005,
            'A flat 50.00 coupon on a single 40.00 line discounts 40.00, not 50.00.');
        $this->assertEqualsWithDelta(0.0, (float) ($data['total_sub_price'] ?? -1), 0.005,
            'One 40.00 line against a flat 50.00 coupon floors at zero: the coupon may take the '
            . 'line to nothing, but it may not pay the shopper.');
    }

    /**
     * THE GUEST READER IS A SEPARATE CODE PATH with its own copy of the loop, and the web
     * storefront's unsigned-in checkout goes through it (`PaymentController::getCartData`).
     */
    public function test_the_guest_cart_reader_applies_a_flat_coupon_once(): void
    {
        [, $store] = $this->tenant();
        $session = 'guest-' . uniqid();
        $this->guestBasketFor($store, $session, 3);
        $this->coupon($store, 'FLAT50');

        // Cookie::get() reads the bound request, so shouldReceive() does not intercept it.
        $this->app['request']->cookies->set('cart', $session);

        $data = json_decode(json_encode(
            Cart::cart_list_cookie(['coupon_code' => 'FLAT50'], $store->id)
        ), true)['data'] ?? [];

        $this->assertCount(3, $data['product_list'] ?? [],
            'Premise: the guest cart read three lines. Check the `cart` cookie reached Cookie::get().');
        $this->assertEqualsWithDelta(self::FLAT, (float) ($data['total_coupon_price'] ?? -1), 0.005,
            'The guest reader took ' . ($data['total_coupon_price'] ?? '?') . ' off a 120.00 basket.');
        $this->assertEqualsWithDelta(70.0, (float) ($data['total_sub_price'] ?? -1), 0.005);
    }

    // =============================================================================================
    // P4-F062 — the spend gates the cart reader never had
    // =============================================================================================

    /**
     * A coupon the basket does not qualify for must not reduce the cart's figure either.
     *
     * `apply_coupon` refuses this coupon outright, so `orders.coupon_price` stays 0 while the cart's
     * `total_sub_price` — the figure that becomes `orders.final_price` — was reduced anyway.
     */
    public function test_a_basket_below_the_minimum_spend_gets_no_cart_discount(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 3);
        $this->coupon($store, 'BIGONLY', ['minimum_spend' => 500]);

        $data = $this->cartList($store, $customer, 'BIGONLY');

        $this->assertEqualsWithDelta(120.0, (float) ($data['total_final_price'] ?? -1), 0.005,
            'Premise: a 120.00 basket, below the 500.00 minimum spend.');
        $this->assertEqualsWithDelta(0.0, (float) ($data['total_coupon_price'] ?? -1), 0.005,
            'A coupon restricted to baskets of 500.00 discounted a 120.00 one by '
            . ($data['total_coupon_price'] ?? '?') . '. getCouponTotalAmount() never reads '
            . 'minimum_spend; only apply_coupon() does, through couponSpendRefusal().');
        $this->assertEqualsWithDelta(120.0, (float) ($data['total_sub_price'] ?? -1), 0.005);
    }

    /** The same gate in the other direction. */
    public function test_a_basket_above_the_maximum_spend_gets_no_cart_discount(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 3);
        $this->coupon($store, 'SMALLONLY', ['maximum_spend' => 50]);

        $data = $this->cartList($store, $customer, 'SMALLONLY');

        // An empty product_list also reports total_coupon_price = 0 with HTTP 200, so this test
        // would pass against a fixture regression without the premise. Found by review.
        $this->assertEqualsWithDelta(120.0, (float) ($data['total_final_price'] ?? -1), 0.005,
            'Premise: a 120.00 basket, above the 50.00 maximum spend.');
        $this->assertEqualsWithDelta(0.0, (float) ($data['total_coupon_price'] ?? -1), 0.005,
            'A coupon capped at 50.00 of spend discounted a 120.00 basket by '
            . ($data['total_coupon_price'] ?? '?') . '.');
    }

    /**
     * A zero in either column means "no limit", not "limit of zero" — the reading
     * `couponSpendRefusal()` already uses (`!= 0.0`). Getting this wrong would refuse every
     * ordinary coupon, since both columns default to 0.
     */
    public function test_a_zero_spend_limit_is_no_limit(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 3);
        $this->coupon($store, 'NOLIMIT', ['minimum_spend' => 0, 'maximum_spend' => 0]);

        $data = $this->cartList($store, $customer, 'NOLIMIT');

        $this->assertEqualsWithDelta(self::FLAT, (float) ($data['total_coupon_price'] ?? -1), 0.005,
            'A coupon with minimum_spend = 0 and maximum_spend = 0 was refused. Both columns '
            . 'default to 0, so this would silently disable every coupon in the catalogue.');
    }

    // =============================================================================================
    // What must NOT change
    // =============================================================================================

    /** D% per line summed IS D% of the basket. The percentage arm never multiplied. */
    public function test_a_percentage_coupon_is_not_changed_by_the_per_basket_fix(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 3);
        $this->coupon($store, 'PCT10', ['coupon_type' => 'percentage', 'discount_amount' => 10]);

        $data = $this->cartList($store, $customer, 'PCT10');

        $this->assertEqualsWithDelta(12.0, (float) ($data['total_coupon_price'] ?? -1), 0.005,
            '10% of a 120.00 basket is 12.00.');
        $this->assertEqualsWithDelta(108.0, (float) ($data['total_sub_price'] ?? -1), 0.005);
    }

    /**
     * `fixed product discount` IS per-line, and this pins it so the fix cannot reach into it: a
     * basket where two lines earn the discount must still show 2 x 10.00 after the change. A fix
     * that collapsed every coupon type to one subtraction per basket would report 10.00 here and
     * under-discount the shopper.
     *
     * WHICH LINES EARN IT IS NOT WHAT THE MERCHANT FORM SAYS, and that is a separate finding this
     * fixture had to be built around rather than a thing this test endorses. The live arm is
     * `Cart.php:1115-1123`, and its membership test is INVERTED:
     *
     *     if (! in_array($productId, $exclude_product) && ! in_array($productId, $coupon_applied))
     *
     * — the discount is given to products NOT in `applied_product`. `$products[0]` is the one named
     * by the coupon and is therefore the one line that does NOT get it. Three further facts
     * measured while building this fixture:
     *
     *   1. The other four arms are UNREACHABLE. Each needs some `count(explode(',', $column)) == 0`,
     *      and `count(explode(',', ''))` is 1, never 0. Only the first arm can ever fire.
     *   2. `exclude_categories` must not be empty or nothing is discounted at all: the arm's last
     *      limb is `! in_array($categoryId, $exclude_categories)`, and with an empty column that is
     *      `in_array(null, [''])`, which PHP 8 reads as TRUE. An unset category silently refuses
     *      every line. That is why this fixture sets a real `category_id` and a sentinel exclusion.
     *   3. So the coupon as the merchant configures it in `coupon/create.blade.php` does not
     *      describe what the shopper gets.
     *
     * Recorded as P4-F118 in the register. NOT fixed here: it changes which products a merchant's
     * existing coupons apply to, so it is a product decision, and conflating it with the flat-coupon
     * arithmetic would make both impossible to review.
     */
    public function test_a_fixed_product_discount_stays_per_line(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $products = $this->basketFor($store, $customer, 3);
        // products.category_id carries a foreign key to `categories`, so the row has to exist.
        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'c-' . uniqid(), 'slug' => 'c-' . uniqid(), 'status' => 1,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($products as $product) {
            $product->category_id = $categoryId;
            $product->save();
        }
        $this->coupon($store, 'FIXED10', [
            'coupon_type' => 'fixed product discount', 'discount_amount' => 10,
            'applied_product' => (string) $products[0]->id,
            'exclude_product' => '', 'applied_categories' => '', 'exclude_categories' => '999',
        ]);

        $data = $this->cartList($store, $customer, 'FIXED10');

        $this->assertEqualsWithDelta(20.0, (float) ($data['total_coupon_price'] ?? -1), 0.005,
            'Two of the three lines earn this per-product discount, so the total is 2 x 10.00. '
            . 'If this reads 10.00 the fix has collapsed the per-line arm into a per-basket one.');
    }

    // =============================================================================================
    // And the money: the COD order the cart figure becomes
    // =============================================================================================

    /**
     * THE DISCOUNT MUST NOT DEPEND ON CART ROW ORDER, and this is a money test, not tidiness.
     *
     * The first version of the fix consumed a running allowance first-come, so which line absorbed
     * the discount was decided by row order -- and the two readers do not even iterate the same
     * way (`ApiController::cart_list` orders by id DESC, `Cart::cart_list_cookie` has no
     * `orderBy`). Tax is charged per line, at that line's own rate, on the price AFTER its share of
     * the coupon, so an order-dependent allocation moves `total_tax_price` and with it
     * `orders.final_price`. A shopper could shift it by removing and re-adding a product.
     *
     * Two 100.00 lines, one product taxed at 20% and one untaxed, against a flat 100.00 coupon.
     * Pro rata gives each line 50.00 of discount, so the tax is 20% of the remaining 50.00 on the
     * taxed line = 10.00, whichever row is newer. First-come allocation gives the whole 100.00 to
     * one line and taxes 20% of 100.00 or of 0.00 -- a 20.00 swing.
     */
    public function test_the_flat_discount_does_not_depend_on_cart_row_order(): void
    {
        $totals = [];

        foreach (['taxed-first', 'taxed-second'] as $order) {
            [, $store] = $this->tenant();
            $customer = $this->shopper($store);

            // The rate lives in `tax_methods`, not `taxes` -- the shape OrderTotalCouponTest:354 uses.
            $taxId = (int) DB::table('taxes')->insertGetId([
                'name' => 'VAT', 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('tax_methods')->insert([
                'name' => 'VAT 20%', 'tax_id' => $taxId, 'tax_rate' => 20, 'priority' => 1,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $taxed = $this->productIn($store, 100.00);
            $taxed->tax_id = $taxId;
            $taxed->save();
            $plain = $this->productIn($store, 100.00);

            $first = $order === 'taxed-first' ? $taxed : $plain;
            $second = $order === 'taxed-first' ? $plain : $taxed;

            foreach ([$first, $second] as $product) {
                Cart::forceCreate([
                    'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
                    'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
                ]);
            }

            $this->coupon($store, 'FLAT100', ['discount_amount' => 100]);
            $data = $this->cartList($store, $customer, 'FLAT100');

            /*
             * The subtotal is NOT asserted as 200.00: `Product::ProductPrice()` folds the tax
             * method into a taxed line's own price, so the measured figure is 220.00. That is
             * pre-existing behaviour and not what this test is about -- what matters is that the
             * figure is the SAME for both orderings, which is asserted below along with the rest.
             */
            $this->assertGreaterThan(0.0, (float) ($data['total_final_price'] ?? 0),
                "Premise ({$order}): the cart priced a basket at all.");
            $this->assertEqualsWithDelta(100.0, (float) ($data['total_coupon_price'] ?? -1), 0.005,
                'The flat 100.00 coupon discounted '
                . ($data['total_coupon_price'] ?? '?') . " ({$order}).");

            $totals[$order] = [
                'final' => round((float) ($data['total_final_price'] ?? -1), 2),
                'tax' => round((float) ($data['total_tax_price'] ?? -1), 2),
                'sub' => round((float) ($data['total_sub_price'] ?? -1), 2),
            ];
        }

        $this->assertSame($totals['taxed-first'], $totals['taxed-second'],
            'The same basket priced differently according to which row is newer: '
            . json_encode($totals) . '. The flat allowance is being consumed in iteration order, '
            . 'and the per-line tax base moves with it.');
    }

    /**
     * A coupon the basket does not qualify for must not be ECHOED as applied either.
     *
     * The `coupon_info` block re-resolves the coupon and re-applies expiry and the two usage
     * limits, but not the spend gate -- so the response carried the coupon's real id, name and
     * headline discount beside an amount of 0.00, and a client reading `coupon_id != 0` concluded
     * it was active. `coupon_final_amount` is read as money by `PaymentService.php:2460` on the
     * path where no top-level `coupon_code` was posted. Found by review.
     */
    public function test_a_spend_refused_coupon_is_not_echoed_as_applied(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 3);
        $this->coupon($store, 'BIGONLY', ['minimum_spend' => 500]);

        $data = $this->cartList($store, $customer, 'BIGONLY');

        $this->assertEqualsWithDelta(120.0, (float) ($data['total_final_price'] ?? -1), 0.005,
            'Premise: a 120.00 basket, below the 500.00 minimum spend.');
        $this->assertSame(0, (int) ($data['coupon_info']['coupon_id'] ?? -1),
            'The response reported coupon_id ' . ($data['coupon_info']['coupon_id'] ?? '?')
            . ' for a coupon this basket does not qualify for.');
        $this->assertSame('-', (string) ($data['coupon_info']['coupon_code'] ?? ''),
            'The response echoed the refused coupon code back as applied.');
    }

    /**
     * AND THE POSITIVE CONTROL: a coupon that DOES apply is still reported, or the assertions above
     * are satisfied by a response that never reports any coupon.
     */
    public function test_an_accepted_coupon_is_still_echoed(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 3);
        $couponId = $this->coupon($store, 'GOODONE');

        $data = $this->cartList($store, $customer, 'GOODONE');

        $this->assertSame($couponId, (int) ($data['coupon_info']['coupon_id'] ?? -1),
            'An applicable coupon was not reported in coupon_info, so the refusal assertions '
            . 'prove nothing.');
        $this->assertSame('GOODONE', (string) ($data['coupon_info']['coupon_code'] ?? ''));
    }

    /**
     * THE DISCOUNT MAY NEVER EXCEED THE BASKET, WHATEVER THE LINES LOOK LIKE.
     *
     * This is the universal invariant for this arithmetic, and it is here because the PRO-RATA
     * REWRITE BROKE IT — a regression introduced by the fix for the order-dependence above, caught
     * by a second round of independent review rather than by any test.
     *
     * Pro rata divides by the whole basket subtotal while a non-positive line contributes nothing
     * to the numerator, so `Σ shares = D × (positive mass) / (total mass)`. One negative line makes
     * the denominator the smaller of the two and the sum overshoots `D`. With the basket this
     * codebase already records at `ApiController.php:3555-3565` — one 1000.00 product plus a 50.00
     * product at `qty = -5`, subtotal 750.00 — and a flat 700.00 coupon, the discount came out at
     * **933.33** and `total_sub_price` at **-183.33**. The first-come version it replaced could not
     * do that: its running allowance was a hard cap.
     *
     * NEGATIVE `carts.qty` IS REACHABLE TODAY, which is why this is a live cart-read defect and not
     * a thought experiment: `GuardsGatewayReturn.php:621-627` names `BoostSalesController` and
     * `FrequentlyProductController` writing `$qty = $request->qty` into `carts.qty` with no
     * validation, on plain `web` routes, in vendored code this project must not edit (S29).
     *
     * IT DOES NOT REACH `orders.final_price`, and the reason is worth keeping: three order writers
     * refuse a non-positive line quantity before pricing — `ApiController.php:3571-3574`,
     * `PaymentService.php:2422-2429` and `processOrder`'s own floor. Those refusals are the only
     * thing standing between this and a negative charged total, so this test asserts the cart
     * figure rather than relying on them.
     */
    public function test_the_discount_never_exceeds_the_basket_even_with_a_negative_line(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);

        $big = $this->productIn($store, 1000.00);
        $negative = $this->productIn($store, 50.00);

        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $big->id,
            'variant_id' => 0, 'qty' => 1, 'price' => 1000.00, 'store_id' => $store->id]);
        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $negative->id,
            'variant_id' => 0, 'qty' => -5, 'price' => 50.00, 'store_id' => $store->id]);

        $this->coupon($store, 'FLAT700', ['discount_amount' => 700]);

        $data = $this->cartList($store, $customer, 'FLAT700');

        $basket = (float) ($data['total_final_price'] ?? -1);
        $discount = (float) ($data['total_coupon_price'] ?? -1);

        $this->assertEqualsWithDelta(750.0, $basket, 0.005,
            'Premise: the subtotal is 1000.00 + (-250.00). If this is not 750.00 the basket is not '
            . 'the malformed one this test is about.');
        $this->assertLessThanOrEqual($basket + 0.005, $discount,
            'The coupon discounted ' . $discount . ' from a basket of ' . $basket
            . '. A discount larger than the basket makes total_sub_price negative.');
        $this->assertLessThanOrEqual(700.005, $discount,
            'The coupon is worth 700.00 and discounted ' . $discount . '. Pro rata divides by the '
            . 'whole subtotal while the negative line contributes nothing to the numerator, so the '
            . 'shares sum to more than the coupon.');
        $this->assertGreaterThanOrEqual(-0.005, (float) ($data['total_sub_price'] ?? -1),
            'total_sub_price came out at ' . ($data['total_sub_price'] ?? '?')
            . '. Neither reader floors it.');
    }

    /**
     * THE END OF THE CHAIN. `orders.final_price` for a COD order is the cart reader's
     * `total_sub_price`, so the per-line discount is the cash the driver fails to collect.
     *
     * Driven over the real checkout route rather than by calling the writer, because the point is
     * the hand-off: `getCartData()` passes the shopper's posted `coupon_code` straight into the
     * cart reader, and nothing between there and `orders.final_price` re-checks it.
     */
    public function test_a_cod_order_carries_one_flat_subtraction_not_one_per_line(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 3);
        $this->coupon($store, 'FLAT50');

        $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order', [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'cod', 'coupon_code' => 'FLAT50',
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => $customer->email,
                'billing_user_telephone' => '123', 'billing_address' => 'x', 'billing_postecode' => '1',
                'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
                'billing_company_name' => '', 'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ]);

        $order = Order::withoutGlobalScopes()->where('store_id', $store->id)->latest('id')->first();

        $this->assertNotNull($order, 'Premise: the COD checkout wrote an order.');
        $this->assertEqualsWithDelta(70.0, (float) $order->final_price, 0.005,
            'The COD order total is ' . ($order->final_price ?? '?') . ' on a 120.00 basket with a '
            . 'flat 50.00 coupon. orders.final_price is the cart reader\'s total_sub_price, and '
            . 'that figure carried one 50.00 subtraction per line.');

        /*
         * AND THE ORDER'S OWN RECORD OF THE DISCOUNT NOW AGREES WITH ITS TOTAL.
         *
         * `orders.coupon_price` comes from `apply_coupon`, which was always right (one subtraction
         * from one basket subtotal), while `final_price` came from the cart reader, which was not.
         * Before the fix those two columns on the same row disagreed by 70.00 -- the order stated a
         * 50.00 discount on a 120.00 basket and a total of 0.00. Asserting both is what makes the
         * row internally checkable: 120.00 - 50.00 = 70.00.
         */
        $this->assertEqualsWithDelta(self::FLAT, (float) $order->coupon_price, 0.005,
            'orders.coupon_price is ' . ($order->coupon_price ?? '?') . ', so the order\'s own '
            . 'columns do not reconcile against its total.');
        $this->assertEqualsWithDelta(120.0, (float) $order->product_price, 0.005,
            'orders.product_price should be the pre-coupon subtotal.');
    }
}
