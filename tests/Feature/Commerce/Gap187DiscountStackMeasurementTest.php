<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\FlashSale;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-187 / `PRICE-004` + `PRICE-005` — WHAT THE DISCOUNT STACK ACTUALLY DOES. A MEASUREMENT.
 * =================================================================================================
 *
 * THE TWO RECOVERED REQUIREMENTS, verbatim from `DECISION-ID-REGISTER.md:101-102`:
 *
 *   `PRICE-004`  قواعد صريحة لأولويات الخصومات.  — *Explicit discount-priority rules*
 *   `PRICE-005`  عدم اختيار أقل سعر أو جمع الخصومات تلقائياً. — *Never auto-pick the lowest price,
 *                never auto-stack discounts*
 *
 * The gap register records `PRICE-004` as **"Not located. No discount-priority rule set."** and its
 * next action as *"Measure what the current coupon and discount stack actually does when two offers
 * apply, then dispose."* `PRICE-005` is a PROHIBITION, so its absence is a question about what the
 * code does today rather than only about a missing feature. This file is that measurement.
 *
 * -------------------------------------------------------------------------------------------------
 * THIS FILE CHANGES NO AMOUNT, AND THAT IS A HARD BOUND, NOT A PREFERENCE.
 * -------------------------------------------------------------------------------------------------
 * Every case below asserts the figure the SHIPPED code produces. Where that figure looks wrong it is
 * pinned as **KNOWN BAD** with its arithmetic written out, exactly as this repository already does
 * for the double-applied tax band on this same surface (267.00 charged where 245.00 is correct —
 * `GAP-143`, an open OWNER decision because it changes what every taxed order is charged). A second
 * money change must not arrive as a side effect of a measurement. **When a figure here is fixed,
 * INVERT the case and record which change did it; do not relax it.**
 *
 * -------------------------------------------------------------------------------------------------
 * THE STACK, AS MEASURED — FIVE LAYERS, NO RULE, APPLIED WHEREVER EACH HAPPENS TO LIVE
 * -------------------------------------------------------------------------------------------------
 * There is no priority table, no ordering constant and no "one discount only" gate anywhere in the
 * path. The order is an emergent property of which function each layer sits in:
 *
 *   1. `sale_price` **overrides** `price`  — `Product::ProductPrice():321-328`. Not a comparison:
 *      whichever value `sale_price` holds wins when it is non-empty, even when it is HIGHER.
 *   2. **flash sale**, on `$product->sale_price` — `:394-400`. Note the base: NOT the `$price`
 *      resolved by layer 1, and not the caller's `$price` argument either.
 *   3. **tax**, added per band — `:417-443`.
 *   4. **coupon**, per line, through `CouponBasketAllocator` — on a line total that already
 *      includes layer 3's tax.
 *   5. **tax again**, on the post-coupon line price (recorded on `GAP-143`).
 *
 * Layers 1, 2 and 4 are three independent discounts and they COMPOUND. Nothing asks whether another
 * discount has already applied, which is the `PRICE-005` prohibition stated the other way round.
 *
 * WHAT IS *NOT* A FINDING, measured and reported as clean so the row is not left ambiguous: the
 * product reader does **not** auto-pick the lowest price. `sale_price` is an unconditional
 * override, so a `sale_price` ABOVE `price` is honoured and the shopper is charged the higher
 * figure. That is half of `PRICE-005` already satisfied, by accident rather than by rule, and it is
 * worth recording because the obvious assumption is the opposite.
 *
 * @see \App\Services\Commerce\CouponBasketAllocator
 * @see \Tests\Feature\Commerce\MultiLineCouponArithmeticTest   the per-basket coupon bound
 * @see \Tests\Feature\Commerce\Gap143ShippingTaxOrderTotalsTest  layer 5, owner-gated
 */
final class Gap187DiscountStackMeasurementTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const LIST_PRICE = 100.00;

    private const SALE_PRICE = 80.00;

    /*
    |--------------------------------------------------------------------------
    | fixture
    |--------------------------------------------------------------------------
    */

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'g187-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
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

        return [$merchant, $store];
    }

    /**
     * A product with NO tax class, deliberately.
     *
     * `ProductPrice()` adds every matching tax band on top (layer 3) and the checkout then charges
     * tax AGAIN on the post-coupon price (layer 5) — which is `GAP-143`'s open owner decision and
     * is NOT this row's subject. Seeding no tax keeps every figure below a pure reading of the
     * DISCOUNT layers; `Gap143ShippingTaxOrderTotalsTest` owns the tax interaction, and
     * `test_premise_no_tax_band_is_seeded_so_these_figures_are_discounts_only` asserts the premise
     * rather than leaving it implied.
     */
    private function product($store, array $overrides = []): Product
    {
        return Product::forceCreate($overrides + [
            'name' => 'g187', 'slug' => 'g187-' . uniqid(),
            'price' => self::LIST_PRICE, 'sale_price' => self::SALE_PRICE,
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /**
     * A flash sale covering `$product`, with a window wide enough that the handler's own timezone
     * cannot fall outside it.
     *
     * `ProductPrice():336` calls `date_default_timezone_set('Asia/Kolkata')` before comparing, so a
     * window of "today" would straddle the boundary depending on when the suite runs. A day either
     * side removes the harness from the measurement — the subject is the arithmetic, not the clock.
     */
    private function flashSale($store, Product $product, string $type, float $amount): FlashSale
    {
        $sale = FlashSale::forceCreate([
            'name' => 'g187-sale', 'sale_product' => json_encode([(string) $product->id]),
            'discount_type' => $type, 'discount_amount' => $amount, 'is_active' => 1,
            'start_date' => now()->subDay()->toDateString(), 'start_time' => '00:00:00',
            'end_date' => now()->addDay()->toDateString(), 'end_time' => '23:59:59',
            'created_by' => $store->created_by, 'store_id' => $store->id,
        ]);
        (new FlashSale)->flushCache();

        return $sale;
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'G', 'last_name' => '187', 'email' => 'g187-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function coupon($store, string $code, string $type, float $amount): Coupon
    {
        $coupon = Coupon::forceCreate([
            'coupon_name' => $code, 'coupon_code' => $code,
            'coupon_type' => $type, 'discount_amount' => $amount,
            'coupon_limit' => 1000, 'coupon_limit_user' => 1000,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'free_shipping_coupon' => 0, 'status' => 1, 'store_id' => $store->id,
        ]);
        (new Coupon)->flushCache();

        return $coupon;
    }

    /**
     * The signed-in cart reader over HTTP, as the storefront and the web checkout reach it.
     *
     * The same driver `MultiLineCouponArithmeticTest` uses. `custom.auth` logs the token's owner
     * into the guard matching its tokenable class, so a `Customer` token puts the shopper on the
     * `customers` guard — the safe branch, and the one a real shopper takes.
     */
    private function cartList($store, Customer $customer, string $couponCode = ''): array
    {
        $token = $customer->createToken('g187')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $store->slug . '/cart-list', [
                'customer_id' => $customer->id, 'coupon_code' => $couponCode,
            ]);

        $this->assertNotSame(404, $response->status(),
            'premise: api/{slug}/cart-list exists. A figure assertion is satisfied by a 404.');
        $this->assertSame(200, $response->status(),
            'premise: the cart reader answered 200. Every figure below is vacuous on a 500 — and '
            . 'ApiResponser::error() answers 200 with status 0, so the body is checked too. Body: '
            . $response->getContent());

        $data = (array) ($response->json('data') ?? []);

        $this->assertNotSame([], $data, 'premise: the reader returned a data envelope. Body: '
            . $response->getContent());

        return $data;
    }

    private function basket($store, Customer $customer, Product $product, int $qty = 1): void
    {
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => $product->sale_price ?? $product->price,
            'store_id' => $store->id,
        ]);
        (new Cart)->flushCache();
    }

    /*
    |--------------------------------------------------------------------------
    | premises
    |--------------------------------------------------------------------------
    */

    public function test_premise_no_tax_band_is_seeded_so_these_figures_are_discounts_only(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);

        $this->assertSame(0, DB::table('taxes')->where('store_id', $store->id)->count(),
            'premise: no tax class for this store, so ProductPrice() adds nothing on top and every '
            . 'figure in this file is a reading of the DISCOUNT layers alone. The tax interaction '
            . 'is GAP-143, an open owner decision, and is not this row.');

        $this->assertSame(
            self::SALE_PRICE,
            round((float) Product::ProductPrice($store->slug, $product->id), 2),
            'premise: with no discount beyond sale_price the reader answers sale_price exactly.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LAYER 1 — sale_price vs price
    |--------------------------------------------------------------------------
    */

    /**
     * CLEAN AT THE READER, BUT THE STATE IS REFUSED AT THE WRITER -- and the second half of that
     * sentence is a correction my own first draft got wrong.
     *
     * `ProductPrice():321-328` is an unconditional override, not a `min()`, so a `sale_price` ABOVE
     * `price` is honoured and the shopper is charged the HIGHER figure. So the READER satisfies
     * `PRICE-005`'s "never auto-pick the lowest price" half.
     *
     * BUT THE STATE IS NOT REACHABLE THROUGH THE PRODUCT FORM. `ProductController:631` and `:1868`
     * validate `'sale_price' => 'nullable|numeric|min:0|lt:price'` -- **`lt:price`** -- so a sale
     * price at or above the list price is refused on both the create and the update path. Measured,
     * not assumed: this case writes the row with `forceCreate`, which is a DIRECT WRITE and bypasses
     * that validator.
     *
     * So the honest disposition is narrower than "clean": the reader has no lowest-price rule, and
     * the question does not arise in practice because the writer forbids the input that would
     * expose one. It is recorded because a future "fix" that made the reader a `min()` would
     * introduce exactly the behaviour `PRICE-005` prohibits, and because the reader is also reached
     * by importers and module writers that do not go through that validator.
     */
    public function test_layer_1_the_reader_honours_a_sale_price_above_list_though_the_form_refuses_one(): void
    {
        [, $store] = $this->tenant();

        /* THE WRITER'S OWN RULE, asserted first so the reader result below is read with it. */
        $this->assertStringContainsString(
            'lt:price',
            $this->sourceWithoutComments('app/Http/Controllers/ProductController.php'),
            'premise: the product form still refuses a sale price that is not less than the list '
            . 'price. If that rule is gone, the reader behaviour below becomes merchant-reachable '
            . 'and this case stops being a direct-write-only observation.'
        );

        /* forceCreate, NOT the form: a direct write, which is the only way to reach this state. */
        $product = $this->product($store, ['price' => 100.00, 'sale_price' => 120.00]);

        $this->assertSame(
            120.00,
            round((float) Product::ProductPrice($store->slug, $product->id), 2),
            'the reader picked the LOWER of price and sale_price. `PRICE-005` prohibits '
            . 'auto-picking the lowest price, and the shipped reader satisfies that half by taking '
            . 'sale_price unconditionally. If this now answers 100.00 something introduced a '
            . 'min() -- that is a behaviour change against a recovered requirement, not a fix.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LAYER 2 — the flash sale, and WHICH BASE it uses
    |--------------------------------------------------------------------------
    */

    /**
     * **AUTO-STACKING, MEASURED: the flash sale compounds on the sale price.** `PRICE-005`'s other
     * half is NOT satisfied.
     *
     * list 100.00, sale_price 80.00, flash sale 10% →
     *     80.00 − (80.00 × 10 / 100) = **72.00**
     *
     * Two offers applied to one line, with nothing asking whether one had already applied. A rule
     * honouring `PRICE-005` would have to choose ONE of 80.00 or 90.00 and would need `PRICE-004`'s
     * priority table to decide which. Neither exists.
     */
    public function test_layer_2_a_flash_sale_compounds_on_the_sale_price(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);           // 100.00 list, 80.00 sale
        $this->flashSale($store, $product, 'percentage', 10.0);

        $this->assertSame(
            72.00,
            round((float) Product::ProductPrice($store->slug, $product->id), 2),
            'the flash sale and the sale price no longer compound. 72.00 is 10% off the 80.00 sale '
            . 'price — TWO discounts on one line. 90.00 would be 10% off the 100.00 list price (the '
            . 'flash sale alone) and 80.00 would be the sale price alone; either would mean a '
            . 'priority rule now exists, which is PRICE-004, and the register should be updated '
            . 'rather than this case relaxed.'
        );
    }

    /**
     * **KNOWN BAD, AND THE SHARPEST FIGURE IN THIS FILE: a flash-sale product whose sale price is
     * blank is priced at ZERO -- and "blank" is what the product form stores by DEFAULT.**
     *
     * `ProductPrice():394-400` takes its base from `$product->sale_price` -- **not** from the
     * `$price` layer 1 just resolved, and not from the `$price` argument either:
     *
     *     $price = $product->sale_price - $saleData['discount_amount']   // 0 - 10.00 = -10.00
     *     $price = max($price, 0)                                        // :416  ->  0.00
     *
     * **MY FIRST HYPOTHESIS WAS WRONG AND THE MEASUREMENT CORRECTED IT, WHICH MATTERS BECAUSE THE
     * CORRECTION MAKES THE FINDING WORSE.** I expected a NULL `sale_price`. That is UNREACHABLE:
     * `products.sale_price` is `float NOT NULL DEFAULT 0`
     * (`2023_12_26_092822_create_products_table.php:32`), and the insert fails outright -- measured,
     * `NOT NULL constraint failed: products.sale_price`. The reachable spelling is **`0`**, and
     * `empty(0)` is TRUE, so it takes the identical branch. And `0` is not an edge case: the form
     * validates `'sale_price' => 'nullable|...'` and then writes
     * `$Product->sale_price = $request->sale_price ?? 0` (`ProductController:659`, `:836`), so
     * **every product whose merchant left the sale price blank carries `sale_price = 0`**. Put one
     * in a flash sale and the storefront prices it at nothing.
     *
     * MEASURED FOR BOTH DISCOUNT TYPES, because they reach 0.00 by different routes and only one
     * meets the floor: `flat` gives `0 - 10.00 = -10.00` which `max(…, 0)` clamps, and `percentage`
     * gives `0 - (0 x 10/100) = 0.00` directly. Either way the answer is 0.00 where 90.00 (10% off
     * list) or 100.00 is defensible.
     *
     * AND IT REACHES THE BASKET, not just the product page -- the case below drives the cart reader,
     * because a wrong figure on a product tile is a display defect and a wrong figure in
     * `total_sub_price` is the cash a COD driver collects.
     *
     * **NOT FIXED HERE.** The repair is a one-word base change, but it moves the price of every
     * flash-sale product with a blank sale price -- upward, from 0.00 -- and this row's brief is
     * explicit that a measurement must not change an amount. It also needs the `PRICE-004` decision
     * it is evidence FOR: should the flash sale's base be the list price, the sale price, or the
     * price layer 1 already resolved? Recorded for the owner with the arithmetic above.
     */
    public function test_layer_2_known_bad_a_flash_sale_on_a_product_with_a_blank_sale_price_prices_it_at_zero(): void
    {
        [, $store] = $this->tenant();

        /* THE PREMISE THAT MAKES THIS ORDINARY RATHER THAN CONTRIVED: the form stores 0 for a blank
           sale price, so this fixture is the product form's own output. */
        $controller = $this->sourceWithoutComments('app/Http/Controllers/ProductController.php');
        $this->assertStringContainsString('$request->sale_price ?? 0', $controller,
            'premise: a blank sale price is still stored as 0 by the product writer. If that '
            . 'changed, `sale_price = 0` may no longer be the default catalogue state and the '
            . 'severity of this finding changes with it.');

        foreach (['flat' => 10.0, 'percentage' => 10.0] as $type => $amount) {
            $product = $this->product($store, ['price' => self::LIST_PRICE, 'sale_price' => 0]);

            $this->assertSame(
                self::LIST_PRICE,
                round((float) Product::ProductPrice($store->slug, $product->id), 2),
                "premise ({$type}): with no flash sale the reader falls back to `price` correctly, "
                . 'so the zero below is layer 2 discarding that fallback and not a broken fixture.'
            );

            $this->flashSale($store, $product, $type, $amount);

            $this->assertSame(
                0.00,
                round((float) Product::ProductPrice($store->slug, $product->id), 2),
                sprintf(
                    'GAP-187 KNOWN BAD (%s) IS FIXED: a flash-sale product with a blank '
                    . '(0) sale price no longer prices at 0.00. That is an improvement AND an '
                    . 'amount change -- confirm it was an owner decision, then INVERT this case to '
                    . 'the new figure and record which change did it. Do not relax it.',
                    $type
                )
            );
        }
    }

    /**
     * THE SAME ZERO, IN THE BASKET -- so the finding is money and not a label.
     *
     * One line, list 100.00, blank (0) sale price, flash sale 10% flat. The product reader answers
     * 0.00, and the cart reader carries it straight through to `total_sub_price`: the shopper
     * checks out a 100.00 product for nothing.
     */
    public function test_layer_2_known_bad_the_zero_price_reaches_the_basket_total(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store, ['price' => self::LIST_PRICE, 'sale_price' => 0]);
        $this->flashSale($store, $product, 'flat', 10.0);

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product);

        $data = $this->cartList($store, $shopper, '');

        $this->assertSame(0.0, round((float) ($data['total_sub_price'] ?? -1), 2), sprintf(
            'GAP-187 KNOWN BAD IS FIXED IN THE BASKET: a flash-sale product with a blank sale '
            . 'price no longer checks out at 0.00. Confirm the amount change was an owner '
            . 'decision, then INVERT this case. Payload: %s', json_encode($data)
        ));

        $this->assertSame(0.0, round((float) ($data['total_coupon_price'] ?? -1), 2),
            'and no coupon was involved -- the zero is the flash-sale base, not a discount.');
    }

    /*
    |--------------------------------------------------------------------------
    | LAYER 4 — the coupon, on top of the product-level discounts
    |--------------------------------------------------------------------------
    */

    /**
     * **THE ROW'S OWN QUESTION, DRIVEN THROUGH THE REAL CART READER: when two offers apply, BOTH
     * apply.**
     *
     * One line, list 100.00, sale_price 80.00, flash sale 10% → 72.00, then a flat 20.00 coupon:
     *
     *     total_final_price   72.00   (the basket subtotal AFTER layers 1 and 2)
     *     total_coupon_price  20.00   (layer 4, on top)
     *     total_sub_price     52.00   (what the shopper pays)
     *
     * So **three** discounts reach one line — sale price, flash sale, coupon — and 52.00 is paid on
     * a 100.00 list price. No gate anywhere asks whether another offer has already applied, and no
     * ordering is declared: layers 1 and 2 happen in `Product::ProductPrice()` and layer 4 in the
     * cart readers, which is why there is no single place a priority rule could be read from today.
     * That IS the `PRICE-004` gap, shown rather than asserted.
     */
    public function test_layer_4_a_coupon_stacks_on_top_of_a_flash_sale_and_a_sale_price(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);            // 100.00 list, 80.00 sale
        $this->flashSale($store, $product, 'percentage', 10.0);
        $this->coupon($store, 'G187FLAT', 'flat', 20.0);

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product);

        $data = $this->cartList($store, $shopper, 'G187FLAT');

        $this->assertSame('72.00', (string) ($data['total_final_price'] ?? ''), sprintf(
            'premise: the subtotal the coupon is applied TO already carries layers 1 and 2. '
            . 'Payload: %s', json_encode($data)
        ));

        $this->assertSame(20.0, round((float) ($data['total_coupon_price'] ?? -1), 2), sprintf(
            'the coupon no longer stacks on the product-level discounts. 20.00 means all three '
            . 'offers applied, which is the PRICE-005 prohibition being broken; 0.00 would mean a '
            . 'rule now suppresses it, which is PRICE-004 arriving and belongs in the register. '
            . 'Payload: %s', json_encode($data)
        ));

        $this->assertSame('52.00', (string) ($data['total_sub_price'] ?? ''), sprintf(
            'THE STACK, END TO END: 100.00 list -> 80.00 sale_price -> 72.00 flash sale -> 52.00 '
            . 'after a flat 20.00 coupon. Three discounts, one line, no priority rule. Payload: %s',
            json_encode($data)
        ));
    }

    /**
     * THE CONTROL, and it is not optional: without it every figure above is consistent with a
     * reader that simply ignores coupons, or with a fixture whose coupon never resolved.
     */
    public function test_the_control_the_same_basket_without_a_coupon_pays_the_flash_sale_price(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);
        $this->flashSale($store, $product, 'percentage', 10.0);
        $this->coupon($store, 'G187FLAT', 'flat', 20.0);

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product);

        $data = $this->cartList($store, $shopper, '');

        $this->assertSame(0.0, round((float) ($data['total_coupon_price'] ?? -1), 2),
            'control: with no coupon code posted the reader discounts nothing.');
        $this->assertSame('72.00', (string) ($data['total_sub_price'] ?? ''), sprintf(
            'control: and the shopper pays the flash-sale price, so the 52.00 above is the '
            . 'coupon\'s doing and not the fixture\'s. Payload: %s', json_encode($data)
        ));
    }

    /**
     * AND A PERCENTAGE COUPON COMPOUNDS TOO, which is the worse shape of the same gap.
     *
     * A percentage coupon takes its cut of the ALREADY-DISCOUNTED line, so the three layers
     * multiply rather than add:
     *
     *     100.00 × (80/100 sale) × 0.90 (flash) × 0.75 (25% coupon) = **54.00**
     *
     * Measured rather than computed in the assertion, so the test states a figure the code must
     * produce instead of restating the code's own arithmetic — which is failure mode 9 in the
     * project skill ("the test cannot tell the two readings apart").
     */
    public function test_layer_4_a_percentage_coupon_compounds_with_the_product_level_discounts(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);            // 100.00 list, 80.00 sale
        $this->flashSale($store, $product, 'percentage', 10.0);
        $this->coupon($store, 'G187PCT', 'percentage', 25.0);

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product);

        $data = $this->cartList($store, $shopper, 'G187PCT');

        $this->assertSame(18.0, round((float) ($data['total_coupon_price'] ?? -1), 2), sprintf(
            '25%% of 72.00 is 18.00 — the coupon is taken off the already-twice-discounted line, '
            . 'not off the 100.00 list price (which would be 25.00). Payload: %s', json_encode($data)
        ));

        $this->assertSame('54.00', (string) ($data['total_sub_price'] ?? ''), sprintf(
            'THE COMPOUNDING, stated as a product: 100.00 x 0.80 x 0.90 x 0.75 = 54.00. A shopper '
            . 'pays 54%% of list where the merchant configured a 20%% sale, a 10%% flash sale and '
            . 'a 25%% coupon -- offers that sum to 55%%, not 46%%. Payload: %s', json_encode($data)
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | PRICE-004 — the absence itself, measured rather than asserted in prose
    |--------------------------------------------------------------------------
    */

    /**
     * THE REGISTER SAYS "NO DISCOUNT-PRIORITY RULE SET". This asserts that, so the row's own
     * finding cannot go stale silently: if somebody adds a priority rule, this test fails and
     * `GAP-187` gets looked at.
     *
     * COMMENT-STRIPPED AND SCOPED, because CLAUDE.md §3 records EIGHT occasions where a census was
     * satisfied — or broken — by prose about the thing it measures, four of them in one day. This
     * file's own docblocks contain the phrase "discount priority" repeatedly; without stripping,
     * this census would be satisfied by its own explanation of why it exists.
     */
    public function test_no_discount_priority_rule_exists_in_the_pricing_or_coupon_path(): void
    {
        $paths = [
            'app/Models/Product.php',
            'app/Services/Commerce/CouponBasketAllocator.php',
        ];

        $needles = ['discount_priority', 'discountPriority', 'DISCOUNT_PRIORITY',
            'priorityFor', 'stackingRule', 'STACKING', 'exclusiveDiscount', 'noStack'];

        foreach ($paths as $path) {
            $this->assertFileExists(base_path($path), 'premise: the file this census reads still '
                . 'exists at this path. A census over a renamed file passes vacuously.');

            /* The TRAIT method, which takes a path relative to the repository root and asserts for
               itself that stripping did not empty the file. */
            $source = $this->sourceWithoutComments($path);

            /* REACHABILITY: prove the stripped source is still the file, so a stripper that
               returned '' could not make every assertion below pass. */
            $this->assertStringContainsString('function', $source,
                'premise: comment-stripping left real code behind.');

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $source, sprintf(
                    'A discount-priority or anti-stacking mechanism named `%s` now exists in %s. '
                    . 'GAP-187 records PRICE-004 as "Not located. No discount-priority rule set." '
                    . 'If that is no longer true the register is stale — update it rather than '
                    . 'this census.',
                    $needle, basename($path)
                ));
            }
        }
    }
}
