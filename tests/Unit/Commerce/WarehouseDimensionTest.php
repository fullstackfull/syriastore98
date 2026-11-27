<?php

namespace Tests\Unit\Commerce;

use App\Services\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `INV-001` — ONE DEFAULT WAREHOUSE, WITH THE CAPABILITY FOR SEVERAL.
 * =================================================================================================
 *
 * The approved decision, verbatim from `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md`
 * §04-B (المخزون والمستودعات) and `DECISION-ID-REGISTER.md`:
 *
 *     INV-001  مستودع افتراضي واحد مع إمكانية تعدد المستودعات.
 *              One default warehouse, with multi-warehouse capability
 *
 * read with its clause-mates:
 *
 *     INV-002  مصدر موحد للكميات الفعلية والمحجوزة والمتاحة.
 *              A single source for actual, reserved and available quantities
 *     INV-009  عدم اعتبار المخزون السالب متاحاً للبيع الفوري.
 *              Negative stock is not available for immediate sale
 *
 * -------------------------------------------------------------------------------------------------
 * THE ONE DESIGN DECISION THIS FILE EXISTS TO PIN: THE DEFAULT WAREHOUSE IS **DERIVED**, NOT STORED.
 *
 * `products.product_stock` and `product_variants.stock` stay the single authoritative sellable
 * total. A named warehouse holds an **allocation** — a row saying "this many of those units sit
 * here" — and the default warehouse holds the arithmetic remainder:
 *
 *     default = total − Σ(allocations at named warehouses)
 *
 * Nothing else would satisfy `ARCH-002` «عدم تكرار محركات المنتجات والمخزون والطلبات والتسعير.»
 * / *no duplicate product, inventory, order or pricing engines*. A per-warehouse table that stored
 * the default's figure as well would be a **second source of truth for the sellable total**: it can
 * disagree with `product_stock`, and once it can disagree something has to reconcile them. There is
 * no reconciliation here because there is nothing to reconcile — the sum of the breakdown is the
 * total **by construction**, which is what {@see test_the_breakdown_always_sums_to_the_authoritative_total}
 * asserts and what makes this additive rather than parallel.
 *
 * Three consequences, each asserted below:
 *
 *   1. A merchant retyping `product_stock` on the product screen (twelve assignment sites in
 *      `ProductController`, four more in `Api\DashboardController`) cannot put the dimension out of
 *      step. The remainder absorbs the change. **No migration of existing data, and no backfill.**
 *   2. An order decrement that sources from nowhere in particular is absorbed by the remainder too,
 *      so a store with no allocations needs the order path to write nothing at all — which is what
 *      makes {@see \Tests\Feature\Commerce\WarehouseStockSourcingTest}'s byte-for-byte control
 *      possible.
 *   3. The remainder CAN go negative, when a merchant allocates more than the product holds. That is
 *      `INV-009`'s subject: the figure is REPORTED as it really is and the **available** figure is
 *      clamped at zero. Hiding the negative would lose the merchant's only signal that the
 *      allocation is wrong; treating it as sellable is what `INV-009` forbids.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THERE IS NO ELOQUENT MODEL, and it is not a shortcut.
 *
 * Two independent reasons, the first of which is a measured blocker:
 *
 *   - `tests/Unit/Tenancy/ClaudeMdCachableModelClaimTest::test_claude_md_states_the_measured_totals`
 *     asserts that CLAUDE.md contains the literal `"<N> of the <M> models"` where M is
 *     `count(glob('app/Models/*.php'))`. **Any** new file in `app/Models/` turns that required test
 *     red, and this workstream may not edit CLAUDE.md. A model in a subdirectory would evade the
 *     census, which is the one thing this project forbids doing to a ratchet.
 *   - On the merits it is the same answer. Most models in `app/Models` are `Cachable`; this table is
 *     read on the checkout path immediately after a stock write, and a `Cachable` model would serve
 *     a pre-write row — CLAUDE.md's three measured false readings. `App\Services\StockMovement` and
 *     `App\Services\ReturnInspection` are raw `DB::table()` services for exactly this reason, and
 *     the second was written three days before this one.
 *
 * Neither table is in `config/tenancy.php`'s `scoped_models`, so **no global scope supplies a
 * predicate** and `null_tenant => 'open'` means none would be added when no tenant resolves. Every
 * statement in the service names `store_id` at the call site; {@see test_one_stores_dimension_is_invisible_to_another}
 * drives that rather than reading it.
 */
class WarehouseDimensionTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 7;
    private const STORE_B = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );
    }

    /** PREMISE: the two tables exist, so every assertion below can distinguish anything at all. */
    public function test_the_premise_that_the_dimension_is_installed(): void
    {
        $this->assertTrue(
            WarehouseInventory::available(),
            'PREMISE: both warehouses and product_warehouse_stock must exist under the suite. '
            . 'Without them every absence asserted below is vacuous.'
        );
    }

    /**
     * `INV-001`: a store has exactly one default warehouse, and creating a second default moves it
     * rather than producing two.
     */
    public function test_exactly_one_warehouse_is_the_default_for_a_store(): void
    {
        $first = $this->makeWarehouse(self::STORE_A, 'Damascus', ['is_default' => 1]);
        $second = $this->makeWarehouse(self::STORE_A, 'Aleppo', ['is_default' => 1]);

        $this->assertSame($second, WarehouseInventory::defaultWarehouseId(self::STORE_A));

        $this->assertSame(
            1,
            (int) DB::table('warehouses')
                ->where('store_id', self::STORE_A)
                ->where('is_default', 1)
                ->count(),
            'INV-001 says ONE default warehouse. Two would make the derived remainder ambiguous.'
        );

        $this->assertSame(
            0,
            (int) DB::table('warehouses')->where('id', $first)->value('is_default'),
            'the previous default must have been demoted, not duplicated'
        );
    }

    /**
     * THE FIRST WAREHOUSE A STORE CREATES IS ITS DEFAULT WHETHER IT ASKED OR NOT.
     *
     * `INV-001` says a store HAS a default warehouse. A store with allocations and no default would
     * have nowhere for the remainder to live, so {@see WarehouseInventory::breakdownFor()} would
     * show only the named warehouses and the sum would be less than the total — the invariant
     * broken by a merchant forgetting a checkbox. Added after mutation planning found that nothing
     * asserted this rule.
     */
    public function test_the_first_warehouse_a_store_creates_is_its_default_unasked(): void
    {
        $first = WarehouseInventory::createWarehouse(self::STORE_A, ['name' => 'Only one']);

        $this->assertSame(
            $first,
            WarehouseInventory::defaultWarehouseId(self::STORE_A),
            'no is_default was passed, and it must still be the default'
        );

        /* AND THE SECOND IS NOT, so the rule is "the first", not "every one". */
        $second = WarehouseInventory::createWarehouse(self::STORE_A, ['name' => 'Second']);

        $this->assertSame($first, WarehouseInventory::defaultWarehouseId(self::STORE_A));
        $this->assertNotSame($second, WarehouseInventory::defaultWarehouseId(self::STORE_A));
    }

    /**
     * THE DIMENSION ABSENT: with the tables dropped, every entry point answers its empty value and
     * nothing throws.
     *
     * **THIS IS THE PRODUCTION CASE.** The migration is development-and-test only, so on the live
     * platform neither table exists and `available()` is what keeps that safe. `RefreshDatabase`
     * always creates them, so the only way to drive the real production shape under the suite is to
     * drop them inside the test — which is what this does, and what mutation planning showed was
     * otherwise unreachable.
     */
    public function test_with_the_tables_absent_every_entry_point_answers_empty_and_nothing_throws(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $warehouse = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);

        \Illuminate\Support\Facades\Schema::drop('product_warehouse_stock');
        \Illuminate\Support\Facades\Schema::drop('warehouses');

        $this->assertFalse(WarehouseInventory::available(), 'PREMISE: the drop really happened');

        $this->assertSame([], WarehouseInventory::warehousesFor(self::STORE_A));
        $this->assertNull(WarehouseInventory::defaultWarehouseId(self::STORE_A));
        $this->assertFalse(WarehouseInventory::ownsWarehouse(self::STORE_A, $warehouse));
        $this->assertSame([], WarehouseInventory::allocationsFor(self::STORE_A, $product));
        $this->assertSame([], WarehouseInventory::breakdownFor(self::STORE_A, $product, 0));
        $this->assertSame(0, WarehouseInventory::availableAcross(self::STORE_A, $product, 0));
        $this->assertSame(0, WarehouseInventory::createWarehouse(self::STORE_A, ['name' => 'x']));
        $this->assertFalse(WarehouseInventory::updateWarehouse(self::STORE_A, $warehouse, ['name' => 'y']));
        $this->assertFalse(WarehouseInventory::deleteWarehouse(self::STORE_A, $warehouse));
        $this->assertSame(
            WarehouseInventory::REFUSED_UNAVAILABLE,
            WarehouseInventory::allocate(self::STORE_A, $warehouse, $product, 0, 1)
        );
        $this->assertSame(0, WarehouseInventory::sourceFrom(self::STORE_A, $warehouse, $product, 0, 1));

        /* `totalFor()` deliberately does NOT depend on the dimension: it reads the product's own
           authoritative figure, which exists whether or not any warehouse does. */
        $this->assertSame(10, WarehouseInventory::totalFor(self::STORE_A, $product, 0));
    }

    /** A store with no warehouse row at all has no default, and that is not an error. */
    public function test_a_store_with_no_warehouses_has_no_default(): void
    {
        $this->assertNull(WarehouseInventory::defaultWarehouseId(self::STORE_A));
        $this->assertSame([], WarehouseInventory::warehousesFor(self::STORE_A));
        $this->assertSame([], WarehouseInventory::allocationsFor(self::STORE_A, 1));
    }

    /**
     * THE CENTRAL INVARIANT. The breakdown's quantities sum to the authoritative total, always,
     * whatever the allocations say — because the default's figure is the remainder and not a stored
     * number that could disagree.
     */
    public function test_the_breakdown_always_sums_to_the_authoritative_total(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $home = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 30);

        $breakdown = WarehouseInventory::breakdownFor(self::STORE_A, $product, 0);

        $this->assertSame(
            100,
            array_sum(array_column($breakdown, 'quantity')),
            'the breakdown must sum to products.product_stock. A sum that can differ is a second '
            . 'source of truth for the sellable total, which ARCH-002 forbids.'
        );

        $byId = array_column($breakdown, null, 'warehouse_id');
        $this->assertSame(30, $byId[$depot]['quantity'], 'the named allocation');
        $this->assertSame(70, $byId[$home]['quantity'], 'the default holds the remainder');
        $this->assertTrue($byId[$home]['is_default']);
        $this->assertFalse($byId[$depot]['is_default']);
    }

    /**
     * The merchant retyping the product's stock figure cannot put the dimension out of step: the
     * remainder absorbs it, with no write to the dimension and no backfill.
     */
    public function test_editing_the_products_own_stock_is_absorbed_by_the_remainder(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $home = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 30);

        DB::table('products')->where('id', $product)->update(['product_stock' => 40]);

        $byId = array_column(
            WarehouseInventory::breakdownFor(self::STORE_A, $product, 0),
            null,
            'warehouse_id'
        );

        $this->assertSame(30, $byId[$depot]['quantity'], 'the allocation is untouched');
        $this->assertSame(10, $byId[$home]['quantity'], 'the remainder absorbed the edit');
        $this->assertSame(
            40,
            array_sum(array_column(
                WarehouseInventory::breakdownFor(self::STORE_A, $product, 0),
                'quantity'
            )),
            'and the invariant still holds'
        );
    }

    /**
     * `INV-009`: «عدم اعتبار المخزون السالب متاحاً للبيع الفوري.» A negative remainder is REPORTED
     * as negative and is NOT available for immediate sale.
     *
     * Both halves matter and they fail in opposite directions. Clamping the reported figure would
     * hide the merchant's only signal that an allocation exceeds the stock; leaving `available`
     * negative would let a caller add it into a sellable total.
     */
    public function test_a_negative_remainder_is_reported_but_is_not_available_for_sale(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $home = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        /* 25 allocated against a total of 10 is only reachable by editing the product's stock DOWN
           after allocating, which is exactly what the previous test shows is permitted. */
        WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 10);
        DB::table('products')->where('id', $product)->update(['product_stock' => 4]);

        $byId = array_column(
            WarehouseInventory::breakdownFor(self::STORE_A, $product, 0),
            null,
            'warehouse_id'
        );

        $this->assertSame(-6, $byId[$home]['quantity'], 'the real figure is reported');
        $this->assertSame(0, $byId[$home]['available'], 'INV-009: not available for immediate sale');

        $this->assertSame(10, $byId[$depot]['quantity']);
        $this->assertSame(10, $byId[$depot]['available'], 'a positive figure is available as it is');

        $this->assertSame(
            10,
            WarehouseInventory::availableAcross(self::STORE_A, $product, 0),
            'the available total excludes the negative remainder rather than subtracting it'
        );
    }

    /**
     * `INV-002`: the caller cannot invent the total. `breakdownFor()` reads it from the product row
     * itself, under the store predicate, so there is no parameter through which a wrong total could
     * enter.
     */
    public function test_the_total_is_read_from_the_product_row_and_not_supplied(): void
    {
        $reflection = new \ReflectionMethod(WarehouseInventory::class, 'breakdownFor');
        $names = array_map(fn ($p) => $p->getName(), $reflection->getParameters());

        $this->assertSame(
            ['storeId', 'productId', 'variantId'],
            $names,
            'breakdownFor() must not take a total. A caller-supplied total is a second source for '
            . 'the figure INV-002 requires to have exactly one.'
        );
    }

    /** A variant's stock is its own dimension, keyed separately from the parent product's. */
    public function test_a_variant_carries_its_own_breakdown(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $variant = $this->makeVariant(self::STORE_A, $product, 20);
        $home = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        WarehouseInventory::allocate(self::STORE_A, $depot, $product, $variant, 8);

        $variantBreakdown = array_column(
            WarehouseInventory::breakdownFor(self::STORE_A, $product, $variant),
            null,
            'warehouse_id'
        );

        $this->assertSame(8, $variantBreakdown[$depot]['quantity']);
        $this->assertSame(12, $variantBreakdown[$home]['quantity'], '20 − 8');

        /* The PARENT's dimension is untouched: a variant allocation is not a product allocation. */
        $productBreakdown = array_column(
            WarehouseInventory::breakdownFor(self::STORE_A, $product, 0),
            null,
            'warehouse_id'
        );

        $this->assertSame(0, $productBreakdown[$depot]['quantity']);
        $this->assertSame(100, $productBreakdown[$home]['quantity']);
    }

    /** `allocate()` refuses to put more units at a warehouse than the product holds in total. */
    public function test_an_allocation_beyond_the_total_is_refused_with_its_reason(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        $this->assertSame(
            WarehouseInventory::REFUSED_BEYOND_TOTAL,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 11)
        );

        $this->assertSame(
            [],
            WarehouseInventory::allocationsFor(self::STORE_A, $product),
            'a refused allocation writes nothing'
        );

        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 10),
            'the control: exactly the total is permitted'
        );
    }

    /** Two named warehouses cannot together be allocated more than the total. */
    public function test_a_second_allocation_is_measured_against_the_first(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $one = $this->makeWarehouse(self::STORE_A, 'One');
        $two = $this->makeWarehouse(self::STORE_A, 'Two');

        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $one, $product, 0, 6)
        );
        $this->assertSame(
            WarehouseInventory::REFUSED_BEYOND_TOTAL,
            WarehouseInventory::allocate(self::STORE_A, $two, $product, 0, 5),
            '6 + 5 > 10'
        );
        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $two, $product, 0, 4),
            'the control: 6 + 4 == 10 is permitted'
        );
    }

    /** `allocate()` REPLACES a warehouse's figure; it does not add to it. */
    public function test_allocating_again_replaces_that_warehouses_figure(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        /* THE PREMISE, ASSERTED. Without it this case passes vacuously the moment anything starts
           refusing the allocation -- which is exactly what happened when
           REFUSED_DEFAULT_WAREHOUSE arrived and this fixture's only warehouse WAS the default. */
        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 4)
        );
        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 7)
        );

        $this->assertSame(
            [$depot => 7],
            WarehouseInventory::allocationsFor(self::STORE_A, $product),
            'a second allocate() for the same warehouse must set, not accumulate — otherwise the '
            . 'merchant typing 7 into a box that showed 4 lands 11'
        );

        $this->assertSame(
            1,
            (int) DB::table('product_warehouse_stock')
                ->where('store_id', self::STORE_A)
                ->where('product_id', $product)
                ->count(),
            'and one row per warehouse per product/variant, not a row per edit'
        );
    }

    /** A zero allocation removes the row rather than leaving an empty one behind. */
    public function test_allocating_zero_removes_the_row(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 4)
        );
        /* THE PREMISE: there really is a row to remove. "[] afterwards" is also what a refused
           FIRST allocation produces, which is how this case passed vacuously for one commit. */
        $this->assertSame([$depot => 4], WarehouseInventory::allocationsFor(self::STORE_A, $product));

        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 0)
        );

        $this->assertSame([], WarehouseInventory::allocationsFor(self::STORE_A, $product));
    }

    /**
     * ALLOCATING TO THE DEFAULT WAREHOUSE IS REFUSED, AND THIS IS A DEFECT FOUND IN THIS CHANGE'S
     * OWN CODE while enumerating what a mutation could break.
     *
     * The default's figure is `total − Σ(allocations)`. If an allocation row could exist FOR the
     * default, `$allocated` would include it and the default's derived figure would be reduced by
     * its own allocation — so the breakdown would sum to `total − (the default's row)` and THE ONE
     * INVARIANT THIS WHOLE DESIGN RESTS ON WOULD BE BROKEN by a merchant typing a number into the
     * wrong box. Measured before the fix: total 100, 30 allocated to the default, breakdown summed
     * to **70**.
     *
     * Refused rather than special-cased in the reader, because "the default holds the remainder" is
     * one rule and a reader that subtracted its own row back out would be a second, drifting
     * statement of it.
     */
    public function test_allocating_to_the_default_warehouse_is_refused(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $home = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        $this->assertSame(
            WarehouseInventory::REFUSED_DEFAULT_WAREHOUSE,
            WarehouseInventory::allocate(self::STORE_A, $home, $product, 0, 30),
            'the default holds the derived remainder; an allocation row for it would be counted '
            . 'twice and the breakdown would no longer sum to the total'
        );

        $this->assertSame(
            [],
            WarehouseInventory::allocationsFor(self::STORE_A, $product),
            'and nothing is written'
        );

        /* THE CONSEQUENCE THE REFUSAL PREVENTS, asserted directly: the invariant holds. */
        $this->assertSame(
            100,
            array_sum(array_column(
                WarehouseInventory::breakdownFor(self::STORE_A, $product, 0),
                'quantity'
            ))
        );

        /* THE CONTROL: a NAMED warehouse is still allocatable, so the refusal is about the default
           and not about allocation. */
        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 30)
        );
    }

    /** A negative allocation is refused: it would mint units at a warehouse. */
    public function test_a_negative_allocation_is_refused(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        $this->assertSame(
            WarehouseInventory::REFUSED_NEGATIVE,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, -1)
        );
        $this->assertSame([], WarehouseInventory::allocationsFor(self::STORE_A, $product));

        /* THE CONTROL: the same warehouse accepts a positive figure, so the refusal is about the
           SIGN and not about anything else refusing first. */
        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 1)
        );
    }

    /**
     * TENANCY, DRIVEN RATHER THAN READ. `warehouses` and `product_warehouse_stock` are absent from
     * `config/tenancy.php`'s `scoped_models`, so NO global scope adds a predicate for any principal
     * and `null_tenant => 'open'` would add none anyway. Every read must therefore carry `store_id`
     * at the call site.
     *
     * Store B's warehouse id is passed to store A's reads on purpose: that is the shape of the
     * defect — a caller holding a number from somewhere else.
     */
    public function test_one_stores_dimension_is_invisible_to_another(): void
    {
        $productA = $this->makeProduct(self::STORE_A, 100);
        $productB = $this->makeProduct(self::STORE_B, 50);

        $homeA = $this->makeWarehouse(self::STORE_A, 'A home', ['is_default' => 1]);
        $depotA = $this->makeWarehouse(self::STORE_A, 'A depot');
        $this->makeWarehouse(self::STORE_B, 'B home', ['is_default' => 1]);
        $depotB = $this->makeWarehouse(self::STORE_B, 'B depot');

        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_B, $depotB, $productB, 0, 20),
            'THE PREMISE: store B really holds 20 units at its depot, so every "store A reads '
            . 'nothing" below is a reading of a row that exists'
        );

        $this->assertSame(
            [$homeA, $depotA],
            array_column(WarehouseInventory::warehousesFor(self::STORE_A), 'id'),
            "store A must see its own two warehouses and not store B's"
        );

        $this->assertSame(
            [],
            WarehouseInventory::allocationsFor(self::STORE_A, $productB),
            "store A must read nothing for store B's product, even though the row exists"
        );

        $this->assertSame(
            [],
            WarehouseInventory::breakdownFor(self::STORE_A, $productB, 0),
            "store A cannot read a breakdown for a product it does not own"
        );

        /* THE WRITE DIRECTION. Store A naming store B's warehouse must be refused, not silently
           written against B's row. */
        $this->assertSame(
            WarehouseInventory::REFUSED_FOREIGN_WAREHOUSE,
            WarehouseInventory::allocate(self::STORE_A, $depotB, $productA, 0, 5)
        );

        $this->assertSame(
            [$depotB => 20],
            WarehouseInventory::allocationsFor(self::STORE_B, $productB),
            "and store B's own row is unchanged by the attempt"
        );

        /* AND A FOREIGN PRODUCT. Store A naming store B's product at store A's own NAMED
           warehouse -- named rather than the default, because the default is refused by its own
           earlier rule and that refusal would mask this one. CLAUDE.md §3: a refusal asserted
           without its reason is satisfied by any refusal. */
        $this->assertSame(
            WarehouseInventory::REFUSED_FOREIGN_PRODUCT,
            WarehouseInventory::allocate(self::STORE_A, $depotA, $productB, 0, 5)
        );

        /* THE CONTROL for that one: the same warehouse accepts store A's OWN product, so the
           refusal is about the product's tenant and not about the warehouse. */
        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depotA, $productA, 0, 5)
        );
    }

    /**
     * TENANCY ON THE TWO REMAINING WRITE PATHS. `updateWarehouse()` and `deleteWarehouse()` refuse
     * a warehouse the store does not own.
     *
     * Added by the authorization self-review against `.claude/agents/tenant-authz-reviewer.md`:
     * both are public write methods a future merchant controller will call with an id out of a URL,
     * and neither had a test. `ownsWarehouse()` is the guard; this drives it rather than reading it.
     */
    public function test_updating_and_deleting_refuse_another_stores_warehouse(): void
    {
        $this->makeWarehouse(self::STORE_A, 'A home', ['is_default' => 1]);
        $this->makeWarehouse(self::STORE_B, 'B home', ['is_default' => 1]);
        $depotB = $this->makeWarehouse(self::STORE_B, 'B depot');

        $this->assertFalse(
            WarehouseInventory::updateWarehouse(self::STORE_A, $depotB, ['name' => 'taken over']),
            "store A must not rename store B's warehouse"
        );
        $this->assertFalse(
            WarehouseInventory::deleteWarehouse(self::STORE_A, $depotB),
            "store A must not delete store B's warehouse"
        );

        $this->assertSame(
            'B depot',
            DB::table('warehouses')->where('id', $depotB)->value('name'),
            "and store B's row is untouched by either attempt"
        );

        /* THE CONTROL for both: store B itself can do each, so the refusals are about the tenant
           and not about the method. */
        $this->assertTrue(WarehouseInventory::updateWarehouse(self::STORE_B, $depotB, ['name' => 'renamed']));
        $this->assertSame('renamed', DB::table('warehouses')->where('id', $depotB)->value('name'));
        $this->assertTrue(WarehouseInventory::deleteWarehouse(self::STORE_B, $depotB));
        $this->assertFalse(WarehouseInventory::ownsWarehouse(self::STORE_B, $depotB));
    }

    /**
     * THE DEFAULT CANNOT BE DELETED WHILE ANOTHER WAREHOUSE EXISTS, and deleting a NAMED one
     * releases its units into the remainder rather than losing them.
     *
     * The first rule is what keeps the invariant: a store with named allocations and no default has
     * nowhere for the remainder to live, so the breakdown would sum to less than the total. The
     * second is the honest reading of a merchant saying a place no longer exists — and the
     * authoritative total is untouched either way, which is why neither can lose or mint stock.
     */
    public function test_the_default_cannot_be_deleted_while_another_warehouse_exists(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $home = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 30)
        );

        $this->assertFalse(
            WarehouseInventory::deleteWarehouse(self::STORE_A, $home),
            'the default holds the remainder; removing it while a named warehouse exists would '
            . 'leave the breakdown summing to less than the total'
        );

        /* DELETING THE NAMED ONE releases its 30 units into the remainder. */
        $this->assertTrue(WarehouseInventory::deleteWarehouse(self::STORE_A, $depot));
        $this->assertSame([], WarehouseInventory::allocationsFor(self::STORE_A, $product));

        $byId = array_column(
            WarehouseInventory::breakdownFor(self::STORE_A, $product, 0),
            null,
            'warehouse_id'
        );
        $this->assertSame(100, $byId[$home]['quantity'], 'all 100 are back in the pool');

        $this->assertSame(
            100,
            (int) DB::table('products')->where('id', $product)->value('product_stock'),
            'AND THE AUTHORITATIVE TOTAL NEVER MOVED: deleting a warehouse cannot lose or mint '
            . 'sellable stock'
        );

        /* THE LAST warehouse may be deleted, default or not: there is no remainder to house. */
        $this->assertTrue(WarehouseInventory::deleteWarehouse(self::STORE_A, $home));
        $this->assertSame([], WarehouseInventory::warehousesFor(self::STORE_A));
    }

    /**
     * `sourceFrom()` takes units off a NAMED warehouse's allocation, clamped at what that warehouse
     * actually holds, and answers how many it really took.
     *
     * The clamp is what keeps the invariant: sourcing more than a warehouse holds would drive its
     * allocation negative while the total fell by the same amount, so the remainder would rise —
     * inventing units at the default.
     */
    public function test_sourcing_takes_units_off_a_named_allocation_and_clamps(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot');

        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 10),
            'THE PREMISE: there are units at the depot to source'
        );

        $this->assertSame(4, WarehouseInventory::sourceFrom(self::STORE_A, $depot, $product, 0, 4));
        $this->assertSame([$depot => 6], WarehouseInventory::allocationsFor(self::STORE_A, $product));

        $this->assertSame(
            6,
            WarehouseInventory::sourceFrom(self::STORE_A, $depot, $product, 0, 9),
            'clamped at the 6 the warehouse holds'
        );
        $this->assertSame(
            [],
            WarehouseInventory::allocationsFor(self::STORE_A, $product),
            'an emptied allocation leaves no row'
        );

        $this->assertSame(
            0,
            WarehouseInventory::sourceFrom(self::STORE_A, $depot, $product, 0, 1),
            'and sourcing from an empty allocation takes nothing'
        );
    }

    /** `sourceFrom()` cannot reach another store's allocation. */
    public function test_sourcing_cannot_reach_another_stores_allocation(): void
    {
        $productB = $this->makeProduct(self::STORE_B, 50);
        $this->makeWarehouse(self::STORE_B, 'B home', ['is_default' => 1]);
        $depotB = $this->makeWarehouse(self::STORE_B, 'B depot');

        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_B, $depotB, $productB, 0, 20),
            'THE PREMISE: store B really does hold 20 units at that depot, so "store A took '
            . 'nothing" is a reading of a real row'
        );

        $this->assertSame(
            0,
            WarehouseInventory::sourceFrom(self::STORE_A, $depotB, $productB, 0, 5),
            "store A must take nothing off store B's allocation"
        );

        $this->assertSame(
            [$depotB => 20],
            WarehouseInventory::allocationsFor(self::STORE_B, $productB)
        );
    }

    // ---------------------------------------------------------------------------------------- //

    private function makeWarehouse(int $storeId, string $name, array $attributes = []): int
    {
        return WarehouseInventory::createWarehouse($storeId, array_merge([
            'name' => $name,
        ], $attributes));
    }

    private function makeProduct(int $storeId, int $stock): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'Warehouse fixture',
            'track_stock' => 1,
            'stock_order_status' => 'not_allow',
            'product_stock' => $stock,
            'low_stock_threshold' => 0,
            'price' => 0,
            'sale_price' => 0,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeVariant(int $storeId, int $productId, int $stock): int
    {
        return (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'variant' => 'S-Red',
            'sku' => 'WH-FIXTURE-' . $productId,
            'price' => 0,
            'stock' => $stock,
            'variation_option' => 'manage_stock',
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
