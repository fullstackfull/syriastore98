<?php

namespace Tests\Feature\Commerce;

use App\Models\FlashSale;
use App\Models\Product;
use App\Services\Commerce\PriceExplanation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `PRICE-006` — THE PRICE EXPLANATION, AND THE ONE PROPERTY THAT MAKES IT SAFE TO SHOW.
 * =================================================================================================
 *
 * THE RECOVERED REQUIREMENT, verbatim from `DECISION-ID-REGISTER.md:103`:
 *
 *   `PRICE-006`  معاينة السعر النهائي وشرح طريقة حسابه.
 *                — *Preview the final price and explain how it was calculated*
 *
 * The requirement is easy to satisfy badly: compute a plausible breakdown, print it, and let it
 * drift away from what the till charges. An explanation that disagrees with the charge is worse
 * than no explanation, so the load-bearing test in this file is not "the components are right" —
 * it is **`test_the_explanation_refuses_to_reconcile_when_the_engine_charges_something_else`**,
 * which moves the engine out from under the explanation and asserts the explanation NOTICES.
 *
 * Every figure below is checked twice: against the component arithmetic, and against
 * `Product::ProductPrice()`'s own return value. The two must agree or `reconciled` is false.
 *
 * @see \App\Services\Commerce\PriceExplanation
 * @see \Tests\Feature\Commerce\Price002WholesaleTierLayerMeasurementTest  why `engine` exists
 * @see \Tests\Feature\Commerce\Gap187DiscountStackMeasurementTest  the layers being explained
 */
final class PriceExplanationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const LIST_PRICE = 100.00;

    private const SALE_PRICE = 80.00;

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'p006-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'modules' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        return [$merchant, $store];
    }

    private function product($store, array $overrides = []): Product
    {
        return Product::forceCreate($overrides + [
            'name' => 'p006', 'slug' => 'p006-' . uniqid(),
            'price' => self::LIST_PRICE, 'sale_price' => self::SALE_PRICE,
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function flashSale($store, Product $product, string $type, float $amount): void
    {
        FlashSale::forceCreate([
            'name' => 'p006-sale', 'sale_product' => json_encode([(string) $product->id]),
            'discount_type' => $type, 'discount_amount' => $amount, 'is_active' => 1,
            'start_date' => now()->subDay()->toDateString(), 'start_time' => '00:00:00',
            'end_date' => now()->addDay()->toDateString(), 'end_time' => '23:59:59',
            'created_by' => $store->created_by, 'store_id' => $store->id,
        ]);
        (new FlashSale)->flushCache();
    }

    /** An exclusive tax band of `$rate`%, attached to `$product`. */
    private function taxBand($store, Product $product, float $rate): void
    {
        $taxId = DB::table('taxes')->insertGetId([
            'name' => 'p006-vat', 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tax_methods')->insert([
            'name' => 'VAT', 'tax_rate' => (string) $rate, 'tax_id' => $taxId,
            'priority' => '1', 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product->tax_id = $taxId;
        $product->save();
    }

    /** @param array<string, mixed> $explanation */
    private function amountFor(array $explanation, string $code): ?float
    {
        foreach ($explanation['components'] as $component) {
            if ($component['code'] === $code) {
                return $component['amount'];
            }
        }

        return null;
    }

    /**
     * THE INVARIANT, asserted on every case rather than written once: the components sum to the
     * charge, and the charge is the engine's own answer and not this class's.
     *
     * @param array<string, mixed> $explanation
     */
    private function assertReconciles(array $explanation, string $slug, Product $product, int $variantId = 0): void
    {
        $engineAnswer = round((float) Product::ProductPrice($slug, $product->id, $variantId), 2);

        $this->assertSame($engineAnswer, $explanation['unit_charged'], sprintf(
            'the explanation must report the ENGINE\'s price, not one of its own. Explanation: %s',
            json_encode($explanation)
        ));

        $this->assertTrue($explanation['reconciled'], sprintf(
            'the components do not account for the charge. Residual %s. Explanation: %s',
            (string) $explanation['residual'], json_encode($explanation)
        ));

        $this->assertSame(
            $engineAnswer,
            round(array_sum(array_column($explanation['components'], 'amount')), 2),
            sprintf('the components must SUM to the charge. Explanation: %s', json_encode($explanation))
        );

        $this->assertNull($this->amountFor($explanation, 'unexplained'),
            'a reconciled explanation carries no unexplained residual component.');
    }

    /*
    |--------------------------------------------------------------------------
    | the breakdown, layer by layer
    |--------------------------------------------------------------------------
    */

    public function test_a_product_with_no_discount_is_explained_as_its_list_price_alone(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store, ['sale_price' => 0]);

        $explanation = PriceExplanation::forProduct($store->slug, $product->id);

        $this->assertSame(PriceExplanation::ENGINE_PRODUCT_PRICE, $explanation['engine']);
        $this->assertSame(100.00, $explanation['unit_charged']);
        $this->assertSame(100.00, $this->amountFor($explanation, 'base_list_price'));
        $this->assertNull($this->amountFor($explanation, 'sale_price_override'),
            'with no sale price there is no override component to explain.');
        $this->assertReconciles($explanation, $store->slug, $product);
    }

    public function test_the_sale_price_is_explained_as_an_override_of_the_list_price(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);

        $explanation = PriceExplanation::forProduct($store->slug, $product->id);

        $this->assertSame(80.00, $explanation['unit_charged']);
        $this->assertSame(100.00, $this->amountFor($explanation, 'base_list_price'));
        $this->assertSame(-20.00, $this->amountFor($explanation, 'sale_price_override'));
        $this->assertReconciles($explanation, $store->slug, $product);

        $detail = '';
        foreach ($explanation['components'] as $component) {
            if ($component['code'] === 'sale_price_override') {
                $detail = $component['detail'];
            }
        }

        $this->assertStringContainsString('override, not a comparison', $detail,
            'the explanation must say WHY the sale price wins. It is an unconditional override, so '
            . 'a sale price ABOVE the list price is charged as-is (GAP-311), and a merchant reading '
            . '"the lower of the two" would be misinformed.');
    }

    public function test_a_percentage_flash_sale_is_explained_on_the_base_it_is_really_taken_off(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);
        $this->flashSale($store, $product, 'percentage', 10.0);

        $explanation = PriceExplanation::forProduct($store->slug, $product->id);

        $this->assertSame(72.00, $explanation['unit_charged'],
            '100.00 list -> 80.00 sale -> 72.00 after a 10% flash sale.');
        $this->assertSame(-8.00, $this->amountFor($explanation, 'flash_sale'),
            '10% of the SALE price (80.00), not of the list price (which would be 10.00).');
        $this->assertReconciles($explanation, $store->slug, $product);

        $this->assertContains('no_discount_priority_rule', $explanation['notes'],
            'two discounts apply and PRICE-004 does not exist, so the explanation must say that '
            . 'the order is not governed by any rule rather than implying one.');
    }

    public function test_a_flat_flash_sale_is_explained_as_a_fixed_reduction(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);
        $this->flashSale($store, $product, 'flat', 25.0);

        $explanation = PriceExplanation::forProduct($store->slug, $product->id);

        $this->assertSame(55.00, $explanation['unit_charged'], '80.00 sale price less 25.00 flat.');
        $this->assertSame(-25.00, $this->amountFor($explanation, 'flash_sale'));
        $this->assertReconciles($explanation, $store->slug, $product);
    }

    public function test_an_exclusive_tax_band_is_explained_as_its_own_line_on_the_post_discount_price(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);
        $this->flashSale($store, $product, 'percentage', 10.0);
        $this->taxBand($store, $product, 10.0);

        $explanation = PriceExplanation::forProduct($store->slug, $product->fresh()->id);

        $this->assertSame(79.20, $explanation['unit_charged'],
            '100.00 -> 80.00 sale -> 72.00 flash -> +10% tax = 79.20. The tax is charged on the '
            . 'DISCOUNTED price, which is the order the engine applies and not an assumption.');

        $taxLine = null;
        foreach ($explanation['components'] as $component) {
            if (str_starts_with($component['code'], 'tax_')) {
                $taxLine = $component;
            }
        }

        $this->assertNotNull($taxLine, 'the tax must be a named line the merchant can see.');
        $this->assertSame(7.20, $taxLine['amount']);
        $this->assertSame('VAT', $taxLine['label'],
            'the tax line carries the band\'s OWN name, so a store with two bands can tell them '
            . 'apart rather than reading one lumped total.');
        $this->assertStringContainsString('72.00', $taxLine['detail'],
            'and names the amount it was charged on, which is the post-discount price.');

        $this->assertReconciles($explanation, $store->slug, $product->fresh());
    }

    /**
     * A FLAT FLASH SALE LARGER THAN THE PRICE, which the engine clamps with `max($price, 0)`.
     *
     * The clamp is a real pricing decision and it is given its own line: without it the breakdown
     * would show a negative price and a merchant would not know why the shopper is charged 0.00.
     */
    public function test_the_zero_clamp_is_explained_rather_than_silently_absorbed(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);
        $this->flashSale($store, $product, 'flat', 500.0);

        $explanation = PriceExplanation::forProduct($store->slug, $product->id);

        $this->assertSame(0.00, $explanation['unit_charged'],
            'a 500.00 flat discount on an 80.00 sale price is clamped to 0.00, not charged as -420.00.');
        $this->assertSame(420.00, $this->amountFor($explanation, 'negative_price_clamp'),
            'the clamp is worth +420.00 to the shopper and appears as its own line.');
        $this->assertReconciles($explanation, $store->slug, $product);
    }

    /**
     * `J17` SURFACED TO THE MERCHANT WITHOUT CHANGING A PRICE.
     *
     * A blank sale price stores the literal `0` (`float NOT NULL DEFAULT 0`, and the product form
     * writes `$request->sale_price ?? 0`). `empty(0)` is true, so `ProductPrice()` falls back to
     * the list price — but `WholesaleProduct`'s reader does not, and a flash sale on the same row
     * prices it at 0.00 (`GAP-310`). The repair is an open owner decision, so the explanation
     * WARNS instead: the one thing engineering may do here is tell the merchant.
     */
    public function test_a_blank_sale_price_is_flagged_to_the_merchant_because_the_repair_is_owner_gated(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store, ['sale_price' => 0]);

        $explanation = PriceExplanation::forProduct($store->slug, $product->id);

        $this->assertContains('blank_sale_price_is_zero', $explanation['notes'],
            'J17 / GAP-310 is live and unrepaired. A preview that shows the list price without '
            . 'mentioning that this row is one flash sale away from being free would be hiding it.');

        $this->assertSame(100.00, $explanation['unit_charged'],
            'and the warning changes no amount — ProductPrice() still answers the list price here.');
    }

    public function test_the_explanation_says_coupons_are_not_in_it(): void
    {
        [, $store] = $this->tenant();
        $product = $this->product($store);

        $explanation = PriceExplanation::forProduct($store->slug, $product->id);

        $this->assertContains('coupon_not_included', $explanation['notes'],
            'a coupon is allocated per BASKET (CouponBasketAllocator), so it is not a property of '
            . 'a product price. An explanation that silently omitted it would overstate what the '
            . 'shopper pays, and PRICE-006 is about being able to trust the figure.');
    }

    /*
    |--------------------------------------------------------------------------
    | THE LOAD-BEARING TEST
    |--------------------------------------------------------------------------
    */

    /**
     * **THE EXPLANATION MUST NOTICE WHEN IT NO LONGER MATCHES THE CHARGE.** This is the property
     * the whole class exists for, and the one a plain reimplementation would fail.
     *
     * Driven against the refusal branch directly, with components deliberately short of the
     * charge. `reconcile()` is private and is reached by reflection, and that choice is deliberate
     * rather than convenient: there is no seam in the shipped pricing path that can be opened from
     * a test body without contriving one, and a contrived seam was tried first and REJECTED --
     * `Product::ProductPrice()` dereferences `getStore($slug)->id` unguarded, so passing an
     * unresolvable slug raises "Attempt to read property id on null" at `Product.php:333` rather
     * than charging a different price. (That unguarded dereference is recorded as a measured fact
     * found on the way, not as a finding of this row.)
     *
     * **THE SAME PROPERTY IS ALSO PROVED AGAINST THE REAL ENGINE, by mutation rather than in the
     * suite:** patching `Product::ProductPrice()` to add a surcharge before it returns turns the
     * reconciliation cases red. That is the evidence that the check is tied to the engine's own
     * output and not to a formula this file happens to agree with, and it is recorded in the
     * commit message because it cannot live in the suite -- a test that edits a model's source
     * would be editing the thing it measures.
     */
    public function test_the_refusal_branch_names_signs_and_flags_an_unaccounted_difference(): void
    {
        $reconcile = new \ReflectionMethod(PriceExplanation::class, 'reconcile');
        $reconcile->setAccessible(true);

        $components = [
            ['code' => 'base_list_price', 'label' => 'List price', 'amount' => 100.00, 'detail' => ''],
            ['code' => 'sale_price_override', 'label' => 'Sale price', 'amount' => -20.00, 'detail' => ''],
        ];

        $short = $reconcile->invoke(null, PriceExplanation::ENGINE_PRODUCT_PRICE, 7, 0, 2, 88.00, $components, []);

        $this->assertFalse($short['reconciled'], sprintf(
            'THE LOAD-BEARING ASSERTION. The engine charged 88.00 and the components account for '
            . '80.00. An explanation reporting `reconciled => true` here would be presenting an '
            . '8.00 lie to a merchant, which PRICE-006 is specifically about not doing. '
            . 'Explanation: %s', json_encode($short)
        ));

        $this->assertSame(8.00, $short['residual'],
            'the residual is the exact unaccounted amount, signed in the direction of the charge.');

        $this->assertSame(8.00, $this->amountFor($short, 'unexplained'),
            'and it is a NAMED, SIGNED component, so a surface that renders the breakdown anyway '
            . 'shows the hole rather than a total that does not add up.');

        $this->assertSame(88.00, $short['components_total'],
            'the stated total is the CHARGE, not the sum of the components it could explain -- a '
            . 'breakdown whose own total disagreed with the price would be a second defect.');

        $this->assertContains('breakdown_does_not_reconcile', $short['notes']);
        $this->assertArrayHasKey('breakdown_does_not_reconcile', PriceExplanation::noteText(),
            'and the note has merchant-facing prose, because a code alone is not an explanation.');

        // THE CONTROL. Without it the refusal above is consistent with a class that NEVER
        // reconciles, which would make every green case in this file vacuous.
        $exact = $reconcile->invoke(null, PriceExplanation::ENGINE_PRODUCT_PRICE, 7, 0, 2, 80.00, $components, []);

        $this->assertTrue($exact['reconciled'],
            'control: the same components against a charge they DO account for reconcile.');
        $this->assertNull($this->amountFor($exact, 'unexplained'));
        $this->assertNotContains('breakdown_does_not_reconcile', $exact['notes']);
    }

    /**
     * HALF A CENT EITHER SIDE OF THE TOLERANCE. `abs($residual) < 0.005` is a decision, and an
     * off-by-one in it either shows a merchant a rounding artefact as an unexplained difference or
     * swallows a real arithmetic change as rounding.
     */
    public function test_the_reconciliation_tolerance_is_half_a_cent_and_is_asserted_on_both_sides(): void
    {
        $reconcile = new \ReflectionMethod(PriceExplanation::class, 'reconcile');
        $reconcile->setAccessible(true);

        $component = [['code' => 'base_list_price', 'label' => 'x', 'amount' => 10.00, 'detail' => '']];

        $this->assertTrue($reconcile->invoke(null, PriceExplanation::ENGINE_PRODUCT_PRICE,
            1, 0, 1, 10.004, $component, [])['reconciled'],
            'four tenths of a cent is rounding, not a discrepancy.');

        $this->assertFalse($reconcile->invoke(null, PriceExplanation::ENGINE_PRODUCT_PRICE,
            1, 0, 1, 10.01, $component, [])['reconciled'],
            'a whole cent IS a discrepancy and must be shown.');
    }

    /*
    |--------------------------------------------------------------------------
    | the contract of the payload itself
    |--------------------------------------------------------------------------
    */

    public function test_an_unknown_product_is_refused_rather_than_explained_as_free(): void
    {
        [, $store] = $this->tenant();

        $explanation = PriceExplanation::forProduct($store->slug, 99999999);

        $this->assertNull($explanation['unit_charged'],
            'a missing product must not be explained as 0.00 — the cart readers already turn a '
            . 'missing price into `?? 0`, and a preview repeating that would confirm a wrong '
            . 'figure rather than refuse one.');
        $this->assertFalse($explanation['reconciled']);
        $this->assertSame([], $explanation['components']);
        $this->assertContains('product_not_found', $explanation['notes']);
    }

    /**
     * EVERY NOTE CODE THE CLASS CAN EMIT HAS MERCHANT-FACING PROSE, asserted in both directions.
     *
     * A census over the class's own source for `$notes[] = '...'` literals, so a new code added
     * without prose reddens here instead of rendering as a bare identifier on a merchant's screen.
     * The reverse direction is asserted too: prose with no emitter is dead text.
     */
    public function test_every_note_code_the_class_can_emit_has_prose_and_every_prose_has_an_emitter(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Services/Commerce/PriceExplanation.php')
        );

        preg_match_all('/\$notes\[\]\s*=\s*\'([a-z_]+)\'/', $source, $pushed);
        preg_match_all('/return \[\$reason, \'([a-z_]+)\'\]/', $source, $viaReason);
        preg_match_all('/\'notes\' => \[\'([a-z_]+)\', \'([a-z_]+)\'\]/', $source, $inline);

        $emitted = array_unique(array_merge(
            $pushed[1], $viaReason[1], $inline[1] ?? [], $inline[2] ?? [],
            // The two `unexplainable()` reasons are passed in by the callers rather than written
            // at the push site, so they are enumerated from the call sites' own literals.
            self::reasonsPassedToUnexplainable($source)
        ));

        $this->assertGreaterThanOrEqual(6, count($emitted), sprintf(
            'premise: the census found the note literals. A regex that matched nothing would make '
            . 'the assertion below vacuous. Found: %s', implode(', ', $emitted)
        ));

        $prose = PriceExplanation::noteText();

        foreach ($emitted as $code) {
            $this->assertArrayHasKey($code, $prose, sprintf(
                'the note code `%s` can be emitted and has no merchant-facing sentence, so it '
                . 'would render as a bare identifier. Add one to noteText().', $code
            ));
            $this->assertNotSame('', trim($prose[$code]));
        }

        foreach (array_keys($prose) as $code) {
            $this->assertContains($code, $emitted, sprintf(
                'the other direction: `%s` has prose but nothing emits it, so it is dead text a '
                . 'translator is paying to maintain.', $code
            ));
        }
    }

    /** @return list<string> */
    private static function reasonsPassedToUnexplainable(string $source): array
    {
        preg_match_all('/unexplainable\([^)]*\'([a-z_]+)\'\)/', $source, $matches);

        return $matches[1];
    }

    /**
     * THE INVARIANT ACROSS A MATRIX, because the per-case tests above each fix one configuration
     * and the property has to hold for combinations nobody enumerated.
     *
     * Eight configurations of sale price, flash-sale type and tax band, every one asserted to
     * reconcile against the engine's own answer. This is the test that would catch a component
     * being right in isolation and wrong in combination.
     */
    public function test_the_components_reconcile_against_the_engine_across_a_configuration_matrix(): void
    {
        $cases = [
            ['sale' => 0.0, 'flash' => null, 'tax' => null],
            ['sale' => 0.0, 'flash' => ['percentage', 10.0], 'tax' => null],
            ['sale' => 80.0, 'flash' => null, 'tax' => null],
            ['sale' => 80.0, 'flash' => ['flat', 15.0], 'tax' => null],
            ['sale' => 80.0, 'flash' => ['percentage', 33.0], 'tax' => 17.5],
            ['sale' => 120.0, 'flash' => null, 'tax' => 5.0],
            ['sale' => 120.0, 'flash' => ['percentage', 50.0], 'tax' => 20.0],
            ['sale' => 80.0, 'flash' => ['flat', 95.0], 'tax' => 10.0],
        ];

        foreach ($cases as $index => $case) {
            [, $store] = $this->tenant();
            $product = $this->product($store, ['sale_price' => $case['sale']]);

            if ($case['flash'] !== null) {
                $this->flashSale($store, $product, $case['flash'][0], $case['flash'][1]);
            }

            if ($case['tax'] !== null) {
                $this->taxBand($store, $product, $case['tax']);
                $product = $product->fresh();
            }

            $explanation = PriceExplanation::forProduct($store->slug, $product->id);

            $this->assertTrue($explanation['reconciled'], sprintf(
                'case %d %s did not reconcile. Residual %s. Explanation: %s',
                $index, json_encode($case), (string) $explanation['residual'], json_encode($explanation)
            ));

            $this->assertSame(
                round((float) Product::ProductPrice($store->slug, $product->id), 2),
                round(array_sum(array_column($explanation['components'], 'amount')), 2),
                sprintf('case %d %s: components must sum to the engine\'s price.', $index, json_encode($case))
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ENGINE 2 — the price-list engine, because "the final price" depends on WHICH engine charges
    |--------------------------------------------------------------------------
    */

    /** Load `WholesaleProduct` and point the store's plan at it, as the cart readers resolve it. */
    private function activateWholesale($merchant): void
    {
        \App\Models\AddOnManager::create([
            'module' => 'WholesaleProduct', 'name' => 'WholesaleProduct',
            'monthly_price' => 0, 'yearly_price' => 0, 'is_enable' => 1,
            'package_name' => 'wholesale-product',
        ]);

        DB::table('plans')->where('id', $merchant->plan_id)->update(['modules' => 'WholesaleProduct']);

        // BOTH switches, because `module_is_active()` reads a DIFFERENT one depending on the
        // route. On a storefront route carrying `{slug}` it reads `plans.modules`; with no slug it
        // falls through to `ActivatedModule()`, which intersects `Module::getByStatus(1)` with
        // `userActiveModule` rows for the owner. The merchant panel has no slug, so the preview
        // surface takes the second path and a fixture that set only the plan column would leave
        // the engine resolving as `product_price` while the shopper was charged by the other one.
        \App\Models\userActiveModule::create(['user_id' => $merchant->id, 'module' => 'WholesaleProduct']);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->invoke($provider, ['WholesaleProduct']);

        $migration = require base_path(
            'packages/workdo/WholesaleProduct/src/database/migrations/'
            . '2024_04_19_121859_create_wholesale_products_table.php'
        );
        $migration->up();
    }

    /**
     * **THE EXPLANATION NAMES WHICH ENGINE WILL CHARGE, AND EXPLAINS THAT ONE.**
     *
     * `Price002WholesaleTierLayerMeasurementTest` measures that an active `WholesaleProduct`
     * replaces `Product::ProductPrice()` on the cart paths and discards the flash sale. A preview
     * that explained `ProductPrice()`'s answer while the price-list engine was charging would be
     * wrong by up to the full price of the product, so `engineFor()` resolves the engine the way
     * the cart readers do and the breakdown follows it.
     *
     * The fixture is deliberately the one where the two engines DISAGREE: a flash sale is
     * configured, and the tier price must win with the flash sale absent from the breakdown,
     * because that is what the shopper is charged.
     */
    public function test_an_active_price_list_module_changes_which_engine_the_explanation_describes(): void
    {
        [$merchant, $store] = $this->tenant();
        $this->activateWholesale($merchant);

        $product = $this->product($store);
        $this->flashSale($store, $product, 'percentage', 10.0);

        DB::table('wholesale_products')->insert([
            'product_id' => $product->id, 'min_qty' => 5, 'max_qty' => 100,
            'per_product_price' => 60.00, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // `module_is_active()` reads the route's slug when there is one and the authenticated
        // principal otherwise, so the merchant is signed in -- from a test body with nobody
        // signed in it answers false while every row reads true (CLAUDE.md).
        $this->actingAs($merchant);

        $explanation = PriceExplanation::forProduct($store->slug, $product->id, 0, 5);

        $this->assertSame(PriceExplanation::ENGINE_WHOLESALE, $explanation['engine'], sprintf(
            'premise: the price-list engine governs. On `product_price` every figure below would '
            . 'be the wrong engine\'s. Explanation: %s', json_encode($explanation)
        ));

        $this->assertSame(300.00, $explanation['line_charged'],
            '5 units at the 60.00 tier price. 360.00 would be the flash-sale price and would mean '
            . 'the explanation described the engine that is NOT charging.');
        $this->assertSame(60.00, $explanation['unit_charged']);
        $this->assertSame(300.00, $this->amountFor($explanation, 'price_list_tier'));

        $this->assertNull($this->amountFor($explanation, 'flash_sale'), sprintf(
            'and the flash sale is NOT in the breakdown, because it is not in the price. An '
            . 'explanation that listed a discount the shopper does not receive is the specific '
            . 'failure PRICE-006 exists to prevent. Explanation: %s', json_encode($explanation)
        ));

        $this->assertTrue($explanation['reconciled'], sprintf(
            'and the components reconcile against the price-list engine\'s OWN return value, not '
            . 'against ProductPrice(). Explanation: %s', json_encode($explanation)
        ));

        $this->assertContains('price_list_engine_replaces_product_price', $explanation['notes'],
            'the merchant is told that a module is suppressing their other offers. They cannot '
            . 'see that anywhere else in the product.');
    }

    /**
     * AND THE NO-TIER FALLBACK IS EXPLAINED AS WHAT IT IS, including the part that is a live
     * defect: the price-list engine falls back to the SALE price and not to the list price, so a
     * blank sale price is free (`J17`, measured in
     * `Price002WholesaleTierLayerMeasurementTest::test_KNOWN_BAD_a_blank_sale_price_is_free...`).
     *
     * No amount is changed here. The explanation reports the 0.00 and names why.
     */
    public function test_the_price_list_fallback_and_its_blank_sale_price_hazard_are_both_explained(): void
    {
        [$merchant, $store] = $this->tenant();
        $this->activateWholesale($merchant);

        $product = $this->product($store, ['sale_price' => 0]);
        $this->actingAs($merchant);

        $explanation = PriceExplanation::forProduct($store->slug, $product->id, 0, 1);

        $this->assertSame(PriceExplanation::ENGINE_WHOLESALE, $explanation['engine']);
        $this->assertSame(0.00, $explanation['line_charged'],
            'the shipped figure, pinned not repaired: the price-list engine has no '
            . '`sale_price -> price` fallback, so a blank sale price is charged as 0.00.');

        $this->assertContains('price_list_fallback_ignores_list_price', $explanation['notes']);
        $this->assertContains('blank_sale_price_is_zero', $explanation['notes'], sprintf(
            'and the merchant is warned. This is the one thing engineering may do about J17 '
            . 'without the owner\'s decision: tell them. Explanation: %s', json_encode($explanation)
        ));

        $this->assertTrue($explanation['reconciled'],
            'and a 0.00 charge that the breakdown accounts for is reconciled — "reconciled" means '
            . 'the explanation matches the charge, not that the charge is correct.');
    }
}
