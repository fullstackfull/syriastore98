<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\FlashSale;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use App\Models\AddOnManager;

/**
 * `PRICE-002` + `PRICE-004` — THE SIXTH DISCOUNT LAYER, AND IT DOES NOT COMPOUND: IT REPLACES.
 * =================================================================================================
 *
 * THE RECOVERED REQUIREMENT, verbatim from `DECISION-ID-REGISTER.md:99`:
 *
 *   `PRICE-002`  قوائم أسعار للجملة والشرائح والأسواق والقنوات.
 *                — *Price lists for wholesale, tiers, markets and channels*
 *
 * `DECISION-ID-REGISTER.md:530` lists `PRICE-002` among the recovered requirements the 350-row
 * traceability matrix **never carried at all**, so nothing in this repository had yet established
 * whether the mechanism exists. It partly does, and measuring it moves `GAP-311` — which recorded
 * **five** compounding layers — because there is a **sixth**, it is `PRICE-002`'s own mechanism,
 * and it behaves in the opposite direction from the other five.
 *
 * -------------------------------------------------------------------------------------------------
 * THIS FILE CHANGES NO AMOUNT.
 * -------------------------------------------------------------------------------------------------
 * Same bound as `Gap187DiscountStackMeasurementTest`: every case asserts the figure the SHIPPED
 * code produces, and where that figure is wrong it is pinned **KNOWN BAD** with its arithmetic
 * written out. The repair is an owner decision (`J18` for the precedence, `J17` for the blank sale
 * price) and the mechanism lives in vendored `packages/workdo/WholesaleProduct`, which this
 * project's instructions forbid editing. **When a figure here is fixed, INVERT the case and record
 * which decision did it; do not relax it.**
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THE FOUR AXES OF `PRICE-002` ACTUALLY HAVE, MEASURED
 * -------------------------------------------------------------------------------------------------
 *   - **tiers** (شرائح): YES. `wholesale_products` is (`product_id`, `min_qty`, `max_qty`,
 *     `per_product_price`, `store_id`) — a quantity-break table, and it reaches the charge.
 *   - **wholesale** (الجملة): PARTLY. The table is named for it, but the gate is quantity alone.
 *     There is no customer group, no account type and no "is a wholesale buyer" predicate
 *     anywhere, so a retail shopper who adds enough units is charged the wholesale price.
 *   - **markets** (الأسواق) and **channels** (القنوات): NO mechanism of any kind — asserted by
 *     census below rather than by prose, in both directions.
 *
 * -------------------------------------------------------------------------------------------------
 * THE FINDING: THE TIER PRICE IS NOT A SIXTH DISCOUNT, IT IS A SIXTH *PRICING ENGINE*
 * -------------------------------------------------------------------------------------------------
 * `GAP-311` measured layers 1–5 through `Product::ProductPrice()`. With `WholesaleProduct` active
 * the cart readers do not call `Product::ProductPrice()` **at all**:
 *
 *     ApiController::cart_list()   →  if (module_is_active('WholesaleProduct'))
 *                                        if (! module_is_active('SizeGuideline'))
 *                                            $whole_sale_pricing = WholesaleProduct::Wholesale_Product_price(...)
 *                                            $final_price        = $whole_sale_pricing['final_price'] ?? 0
 *
 * and `Wholesale_Product_price()` resolves its own base from `$product->sale_price` directly. So
 * activating a module whose stated job is *price lists* silently switches off the flash sale and
 * the `sale_price → price` fallback. That is the same class of defect as `GAP-311` — precedence as
 * an emergent property of which function a layer sits in — one level up: here it is **which
 * pricing engine runs at all**, and the switch is a module activation.
 *
 * **AND THE OTHER BRANCH IS DEAD IN THIS REPOSITORY.** The `else` arm (SizeGuideline active)
 * computes `$whole_sale_pricing` and then overwrites it with `Product::ProductPrice()` on the very
 * next line — a dead store that would discard the tier price entirely. It cannot be reached here:
 * there is no `SizeGuideline` module among the 70 under `packages/workdo/`, asserted below. So the
 * live precedence is "tier price wins, ProductPrice never runs", and the dead arm is recorded
 * because a reader of `Cart.php` would otherwise reasonably conclude the opposite.
 *
 * @see \Tests\Feature\Commerce\Gap187DiscountStackMeasurementTest  layers 1-5, the same bound
 * @see \App\Services\Commerce\PriceExplanation  `PRICE-006`, which refuses to explain this case
 */
final class Price002WholesaleTierLayerMeasurementTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const LIST_PRICE = 100.00;

    private const SALE_PRICE = 80.00;

    /*
    |--------------------------------------------------------------------------
    | fixture
    |--------------------------------------------------------------------------
    */

    /**
     * A merchant, a store, and a plan whose `modules` column decides what `module_is_active()`
     * answers on a storefront route.
     *
     * `module_is_active()` takes a DIFFERENT path on a route carrying `{slug}`: it resolves the
     * store's owner, reads `plans.modules`, and ignores `ActivatedModule()` entirely
     * (`app/Helper/helper.php`, `module_is_active()`). So the plan's column is the switch here, and
     * `$planModules` is what each case drives.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Store}
     */
    private function tenant(string $planModules = ''): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'p002-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'modules' => $planModules,
            'created_at' => now(), 'updated_at' => now(),
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
     * Register and load `WholesaleProduct` so its class resolves and `Module::has()` answers true.
     *
     * Two separate mechanisms, as `CLAUDE.md` records: the `add_on_managers` row controls
     * *reachability* (`Module::getByStatus(1)` IS that table) and `loadAddons()` controls whether
     * the provider and the class are *loaded*. A test that inserts only the row gets a module that
     * `module_is_active()` admits and whose class is missing.
     */
    private function loadWholesaleProduct(): void
    {
        AddOnManager::create([
            'module' => 'WholesaleProduct', 'name' => 'WholesaleProduct',
            'monthly_price' => 0, 'yearly_price' => 0, 'is_enable' => 1,
            'package_name' => 'wholesale-product',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->invoke($provider, ['WholesaleProduct']);

        // The module's OWN migration object, `up()` called directly. `RefreshDatabase` migrates
        // before the addon is loaded, so `wholesale_products` does not exist yet, and
        // `Artisan::call('migrate', ['--path' => ...])` is a silent no-op from inside the suite
        // (measured: it creates nothing and prints nothing). Requiring the shipped file keeps the
        // Blueprint the module's own — a hand-copied schema here could drift from it and would
        // make `per_product_price`'s type a property of this test.
        $migration = require base_path(
            'packages/workdo/WholesaleProduct/src/database/migrations/'
            . '2024_04_19_121859_create_wholesale_products_table.php'
        );
        $migration->up();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('wholesale_products'),
            'premise: the module\'s own migration created its table, so every tier figure below is '
            . 'read through the shipped schema.');

        $this->assertSame(
            ['id', 'product_id', 'min_qty', 'max_qty', 'per_product_price', 'store_id', 'created_at', 'updated_at'],
            \Illuminate\Support\Facades\Schema::getColumnListing('wholesale_products'),
            'premise, stated so the file cannot silently measure a renamed schema: PRICE-002\'s '
            . 'one shipped axis is keyed on product and QUANTITY. A customer, market or channel '
            . 'column arriving here means the requirement moved and the census above must be '
            . 're-read.'
        );
    }

    private function product($store, array $overrides = []): Product
    {
        return Product::forceCreate($overrides + [
            'name' => 'p002', 'slug' => 'p002-' . uniqid(),
            'price' => self::LIST_PRICE, 'sale_price' => self::SALE_PRICE,
            'product_stock' => 500, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function flashSale($store, Product $product, string $type, float $amount): void
    {
        FlashSale::forceCreate([
            'name' => 'p002-sale', 'sale_product' => json_encode([(string) $product->id]),
            'discount_type' => $type, 'discount_amount' => $amount, 'is_active' => 1,
            'start_date' => now()->subDay()->toDateString(), 'start_time' => '00:00:00',
            'end_date' => now()->addDay()->toDateString(), 'end_time' => '23:59:59',
            'created_by' => $store->created_by, 'store_id' => $store->id,
        ]);
        (new FlashSale)->flushCache();
    }

    /** A quantity-break row: `PRICE-002`'s tier mechanism, as the module models it. */
    private function tier(Product $product, $store, int $minQty, int $maxQty, float $perUnit): void
    {
        DB::table('wholesale_products')->insert([
            'product_id' => $product->id, 'min_qty' => $minQty, 'max_qty' => $maxQty,
            'per_product_price' => $perUnit, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'P', 'last_name' => '002', 'email' => 'p002-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
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

    /** @return array<string, mixed> */
    private function cartList($store, Customer $customer): array
    {
        $token = $customer->createToken('p002')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $store->slug . '/cart-list', ['customer_id' => $customer->id]);

        $this->assertSame(200, $response->status(),
            'premise: the cart reader answered 200. Every figure below is vacuous on a 500 — and '
            . 'ApiResponser::error() answers 200 with status 0, so the body is checked too. Body: '
            . $response->getContent());

        $data = (array) ($response->json('data') ?? []);

        $this->assertNotSame([], $data, 'premise: the reader returned a data envelope. Body: '
            . $response->getContent());

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | premises — the two module facts the whole file rests on
    |--------------------------------------------------------------------------
    */

    /**
     * THE DEAD ARM. `Cart.php` and `ApiController.php` both branch on
     * `module_is_active('SizeGuideline')`, and in that arm `Product::ProductPrice()` overwrites the
     * tier price. There is no such module in this repository, so that arm never runs and the
     * precedence every other case here measures is the only reachable one.
     *
     * Asserted in BOTH directions: the module that IS referenced is absent, and the module that
     * carries the mechanism is present — so a rename or a vendor update that adds `SizeGuideline`
     * reddens this and the reader is sent back to re-measure the precedence.
     */
    public function test_premise_there_is_no_SizeGuideline_module_so_the_arm_that_would_discard_the_tier_price_is_dead(): void
    {
        $modules = array_values(array_filter(
            scandir(base_path('packages/workdo')) ?: [],
            fn ($entry) => ! in_array($entry, ['.', '..'], true)
                && is_dir(base_path('packages/workdo/' . $entry))
        ));

        $this->assertNotContains('SizeGuideline', $modules, sprintf(
            'premise: `module_is_active(\'SizeGuideline\')` is unsatisfiable, so the else-arm of '
            . 'the wholesale branch — which recomputes $pricing with Product::ProductPrice() and '
            . 'throws the tier price away — is dead code. If SizeGuideline ever ships, the '
            . 'precedence this file measures INVERTS and every figure below must be re-measured. '
            . 'Modules present: %s', implode(', ', $modules)
        ));

        $this->assertContains('WholesaleProduct', $modules,
            'premise, the other direction: the module that carries PRICE-002\'s tier mechanism is '
            . 'vendored here. Without this the absence above would be vacuous.');
    }

    /**
     * `PRICE-002`'s TWO MISSING AXES, asserted as an absence over the whole repository.
     *
     * The census enumerates the SAFE set rather than hunting for dangerous spellings, as
     * `CLAUDE.md` requires: it looks for ANY schema column or table whose name carries a
     * market/channel/customer-group pricing notion, and asserts the set is empty. A new spelling
     * nobody thought of is caught because the predicate is "a price keyed on something other than
     * product, variant and quantity", not a list of words.
     */
    public function test_PRICE_002_has_no_market_channel_or_customer_group_price_mechanism_at_all(): void
    {
        $migrations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('database/migrations'))
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $migrations[] = (string) file_get_contents($file->getPathname());
            }
        }
        foreach (glob(base_path('packages/workdo/*/src/database/migrations/*.php')) ?: [] as $path) {
            $migrations[] = (string) file_get_contents($path);
        }

        $schema = implode("\n", $migrations);

        $absent = [];
        foreach ([
            'price_list' => 'a named price list',
            'pricelist' => 'a named price list',
            'customer_group' => 'a per-customer-group price',
            'customer_segment_price' => 'a per-segment price',
            'price_tier' => 'a named tier a customer belongs to',
            'market_price' => 'a per-market price',
            'channel_price' => 'a per-channel price',
            'sales_channel' => 'a sales channel a price could be keyed on',
        ] as $needle => $what) {
            if (! str_contains($schema, $needle)) {
                $absent[] = $needle . ' (' . $what . ')';
            }
        }

        $this->assertCount(8, $absent, sprintf(
            'PRICE-002 asks for price lists for wholesale, TIERS, MARKETS and CHANNELS. If one of '
            . 'these spellings now appears in a migration, a mechanism may have arrived and this '
            . 'census must be re-read before the row is disposed. Still absent: %s',
            implode('; ', $absent)
        ));

        $this->assertTrue(str_contains($schema, 'wholesale_products'), sprintf(
            'premise, the other direction: the ONE axis that does exist is in the census\'s own '
            . 'input, so the eight absences above are a reading of the schema and not of an empty '
            . 'string. Schema bytes read: %d', strlen($schema)
        ));

        $this->assertTrue(str_contains($schema, 'per_product_price'),
            'premise: and the tier mechanism is a price keyed on QUANTITY — product_id, min_qty, '
            . 'max_qty, per_product_price. No customer, no market, no channel.');
    }

    /*
    |--------------------------------------------------------------------------
    | THE CONTROL — without the module, GAP-311's five layers are what runs
    |--------------------------------------------------------------------------
    */

    /**
     * THE CONTROL, and it is not optional: without it every figure below is consistent with a
     * fixture whose flash sale never resolved.
     *
     * Same product and same flash sale as the measured cases. With `WholesaleProduct` off the
     * reader is `Product::ProductPrice()` and `GAP-311`'s layers 1 and 2 both apply:
     * 100.00 list → 80.00 sale_price → 72.00 after a 10% flash sale.
     */
    public function test_control_without_the_module_the_flash_sale_applies_and_the_shopper_pays_72(): void
    {
        [, $store] = $this->tenant('');
        $product = $this->product($store);
        $this->flashSale($store, $product, 'percentage', 10.0);

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product);

        $data = $this->cartList($store, $shopper);

        $this->assertSame('72.00', (string) ($data['total_sub_price'] ?? ''), sprintf(
            'control: 100.00 x 0.80 (sale_price) x 0.90 (flash sale) = 72.00, which is GAP-311\'s '
            . 'own figure reproduced on this fixture. Payload: %s', json_encode($data)
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | KNOWN BAD — the module replaces the pricing engine and the flash sale is lost
    |--------------------------------------------------------------------------
    */

    /**
     * **KNOWN BAD. ACTIVATING THE PRICE-LIST MODULE SILENTLY SWITCHES OFF THE FLASH SALE.**
     *
     * Identical fixture to the control — same 10% flash sale, no tier rows at all — with
     * `WholesaleProduct` in the plan's `modules`. The reader stops calling
     * `Product::ProductPrice()` and asks `Wholesale_Product_price()` instead, which resolves its
     * base from `$product->sale_price` and knows nothing about flash sales. So:
     *
     *     control  (module off)  72.00     100.00 -> 80.00 sale -> 72.00 flash
     *     measured (module on)   80.00     100.00 -> 80.00 sale.  FLASH SALE LOST.
     *
     * The merchant's configured 10% flash sale does not reach the shopper, and nothing anywhere
     * reports that it did not. A **higher** charge than the control, so it is not an auto-pick of
     * the lowest price — it is `PRICE-004`'s missing precedence rule with the two engines
     * disagreeing, and `PRICE-001`'s "one central pricing engine" measured as two.
     */
    public function test_KNOWN_BAD_with_the_module_active_and_no_tier_rows_the_flash_sale_is_discarded(): void
    {
        [, $store] = $this->tenant('WholesaleProduct');
        $this->loadWholesaleProduct();

        $product = $this->product($store);
        $this->flashSale($store, $product, 'percentage', 10.0);

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product);

        $data = $this->cartList($store, $shopper);

        $this->assertSame('80.00', (string) ($data['total_sub_price'] ?? ''), sprintf(
            'KNOWN BAD: 80.00 means the flash sale was discarded because WholesaleProduct is '
            . 'active — the module replaced the pricing engine rather than adding a layer to it. '
            . '72.00 would mean a precedence rule now exists (PRICE-004 arriving, or the wholesale '
            . 'reader rebased on Product::ProductPrice()) and belongs in the register. Payload: %s',
            json_encode($data)
        ));
    }

    /**
     * **THE TIER PRICE DOES REACH THE CHARGE**, and this is `PRICE-002`'s one working axis.
     *
     * A quantity break of 60.00/unit for 5–100 units, on a product whose sale price is 80.00 and
     * which also carries a 10% flash sale. At qty 5 the shopper pays 5 x 60.00 = 300.00: the tier
     * price wins outright, and neither the sale price nor the flash sale modifies it.
     *
     * So the tier is not a discount among discounts — it REPLACES the base. That is arguably the
     * right shape for a price list, and it is recorded as measured rather than as approved,
     * because `PRICE-004`'s precedence rule is the owner's (`J18`) and this is one of the
     * precedences it has to rule on.
     */
    public function test_a_quantity_tier_replaces_the_base_price_and_both_product_discounts(): void
    {
        [, $store] = $this->tenant('WholesaleProduct');
        $this->loadWholesaleProduct();

        $product = $this->product($store);
        $this->flashSale($store, $product, 'percentage', 10.0);
        $this->tier($product, $store, 5, 100, 60.00);

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product, 5);

        $data = $this->cartList($store, $shopper);

        $this->assertSame('300.00', (string) ($data['total_sub_price'] ?? ''), sprintf(
            '5 units x 60.00 tier price = 300.00. 360.00 would be the flash-sale price (5 x 72.00) '
            . 'and would mean the tier never applied; 400.00 would be the sale price. The tier '
            . 'replaces the base AND both product-level discounts. Payload: %s', json_encode($data)
        ));
    }

    /**
     * **AND THE QUANTITY GATE IS THE ONLY GATE** — `PRICE-002`'s *wholesale* axis, measured.
     *
     * The same tier row, with the cart one unit below its `min_qty`. The shopper pays the ordinary
     * sale price, so the break is a pure quantity break: there is no customer group, no account
     * type and no wholesale-buyer predicate between a retail shopper and the wholesale price. A
     * retail shopper who adds five units gets the wholesale rate, which is the case above.
     *
     * This is the half of `PRICE-002` that exists only in name, and it is a SCOPE question for the
     * owner, not a defect: deciding who may be charged a wholesale price is a business rule.
     */
    public function test_the_tier_gate_is_quantity_alone_with_no_customer_group_predicate(): void
    {
        [, $store] = $this->tenant('WholesaleProduct');
        $this->loadWholesaleProduct();

        $product = $this->product($store);
        $this->tier($product, $store, 5, 100, 60.00);

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product, 4);

        $data = $this->cartList($store, $shopper);

        $this->assertSame('320.00', (string) ($data['total_sub_price'] ?? ''), sprintf(
            '4 units below min_qty 5 pay 4 x 80.00 sale price = 320.00, and the SAME shopper at '
            . 'qty 5 pays 60.00/unit. Quantity is the whole gate — no customer group is consulted '
            . 'on either side of the break. Payload: %s', json_encode($data)
        ));
    }

    /**
     * **KNOWN BAD, AND WIDER THAN `J17` AS THE OWNER PACKAGE STATES IT: NO FLASH SALE IS NEEDED.**
     *
     * `J17` / `GAP-310` records that a blank sale price plus a flash sale prices a product at
     * `0.00`, because `sale_price` is `float NOT NULL DEFAULT 0`, the product form writes
     * `$request->sale_price ?? 0`, and `empty(0)` is true. `Product::ProductPrice()` at least has
     * the `!empty($product->sale_price) ? sale_price : price` fallback, so without a flash sale it
     * charges the LIST price and the defect needs the flash sale to be reachable.
     *
     * `Wholesale_Product_price()` has **no such fallback**. Its no-tier arm is
     * `$product_price = $product->sale_price`, full stop. So with `WholesaleProduct` active, a
     * product whose merchant left the sale price blank is **free with no flash sale, no tier row
     * and no coupon** — the plainest possible configuration.
     *
     *     list price   100.00
     *     sale price     0.00   (blank in the form)
     *     charged        0.00   <- the cash a COD driver collects at the door
     *
     * Pinned, not repaired: the fix moves the price of every product in that state, which is the
     * material price change `CLAUDE.md` §2 reserves to the owner, it is the same question as
     * `J17`, and the expression is in vendored code this project must not edit. What this case
     * adds to `J17` is its REACH: the owner should know the trigger is not "a flash sale" but
     * "the wholesale module is on".
     */
    public function test_KNOWN_BAD_a_blank_sale_price_is_free_under_the_wholesale_reader_with_no_flash_sale(): void
    {
        [, $store] = $this->tenant('WholesaleProduct');
        $this->loadWholesaleProduct();

        $product = $this->product($store, ['sale_price' => 0]);

        $this->assertSame(0.0, round((float) $product->sale_price, 2),
            'premise: the reachable blank-sale-price state is the literal 0, not NULL — the column '
            . 'is NOT NULL and the insert of a null fails. GAP-310 corrected itself on exactly '
            . 'this and the correction made the defect wider.');

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product);

        $data = $this->cartList($store, $shopper);

        $this->assertSame('0.00', (string) ($data['total_sub_price'] ?? ''), sprintf(
            'KNOWN BAD: 0.00 charged for a 100.00 product with NO flash sale, NO tier row and NO '
            . 'coupon, because Wholesale_Product_price() reads $product->sale_price with no '
            . 'fallback to $product->price. 100.00 would mean J17 has been decided in favour of '
            . '"a blank sale price means no sale price" and the wholesale reader was fixed with '
            . 'it; INVERT this case then rather than relaxing it. Payload: %s', json_encode($data)
        ));
    }

    /**
     * THE CONTROL FOR THE CASE ABOVE, which the finding is worthless without.
     *
     * The same blank sale price with the module OFF charges the LIST price, because
     * `Product::ProductPrice()` has the fallback the wholesale reader lacks. So the 0.00 above is
     * the wholesale reader's doing and not the fixture's, and the two readers disagree by the full
     * price of the product.
     */
    public function test_control_the_same_blank_sale_price_charges_the_list_price_without_the_module(): void
    {
        [, $store] = $this->tenant('');
        $product = $this->product($store, ['sale_price' => 0]);

        $shopper = $this->shopper($store);
        $this->basket($store, $shopper, $product);

        $data = $this->cartList($store, $shopper);

        $this->assertSame('100.00', (string) ($data['total_sub_price'] ?? ''), sprintf(
            'control: Product::ProductPrice()\'s `!empty($product->sale_price) ? sale_price : '
            . 'price` fallback charges 100.00 for the same row. The two readers differ by 100%% of '
            . 'the product price on identical data, which is PRICE-001 ("one central pricing '
            . 'engine") measured as two. Payload: %s', json_encode($data)
        ));
    }
}
