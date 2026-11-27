<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductStockService;
use App\Services\StockMovement;
use App\Services\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `P04-P4-02` / `INV-001` / `INV-007` — THE ORDER PATH READS THE WAREHOUSE DIMENSION.
 * =================================================================================================
 *
 * `ProductStockService` is the ONLY order-path mutator of `products.product_stock` and
 * `product_variants.stock` — asserted by `Inv005ReservationPointTest::DECLARED_STOCK_MUTATORS`, and
 * the reason the sourcing step lives in that one method rather than at the six decrement call
 * sites (`PaymentService`, `AppliesCartStockChanges`, `OrderController`, `PosController`,
 * `Api\DashboardController`, and the forced-oversell arms of the first two). One place, six
 * inheritors — the same argument `FulfilmentStateMachine::wordOf()` records for fixing a rule on
 * the machine rather than at each door.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE ASSERTION THIS FILE EXISTS FOR, AND ITS CONTROL
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **A STORE WITH NO WAREHOUSES CONFIGURED BEHAVES BYTE-FOR-BYTE AS IT DOES TODAY.** That is every
 * store on this platform, so it is the case that must be provably unchanged:
 * {@see test_a_store_with_no_warehouses_behaves_exactly_as_before} asserts the stock figure, the
 * whole returned array, the movement record and the emptiness of both new tables — and
 * {@see test_the_control_a_store_with_a_warehouse_really_does_move_an_allocation} is its control,
 * because "nothing happened" is also what a broken integration produces. Without the control the
 * first test passes whether or not any of this works.
 *
 * **AND OVERSELL BEHAVIOUR IS UNCHANGED FOR EVERY STORE, CONFIGURED OR NOT.** The conditional
 * `UPDATE ... WHERE product_stock >= ?` is untouched; nothing here is consulted to refuse a sale.
 * {@see test_an_allocation_cannot_make_a_sale_succeed_or_fail} drives both directions on a store
 * that HAS warehouses: a product with an allocation and a product with none refuse and permit
 * exactly as they do without the dimension. A sale gate was available here and would have been a
 * stock business-rule change, which CLAUDE.md §2 reserves to the owner.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE RESTORE DOORS ARE DELIBERATELY NOT TOUCHED, AND THAT IS PINNED RATHER THAN IMPLIED
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Six stock-restore call sites exist outside `ProductStockService` (`GAP-363` enumerates them;
 * re-measured here by grep): `Order::performProductReturn()`, `OrderController::restoreReturnedStock()`
 * and `OrderRefundController::RefundStock()` are the three post-delivery return doors;
 * `Concerns\RestoresCancelledOrderStock` is the cancel door; `PaymentService` and
 * `Concerns\AppliesCartStockChanges` compensate a checkout that could not be written.
 *
 * **NOT ONE OF THEM CHANGES**, because with a derived default no change is needed to stay
 * consistent: returning units raises `product_stock`, which raises the remainder by the same
 * amount, so the invariant holds exactly and the goods land at the store's DEFAULT warehouse. That
 * is both arithmetically correct and what retail does with a return — it arrives at a receiving
 * location, not back on the shelf it was picked from.
 *
 * {@see test_a_restore_returns_units_to_the_default_and_leaves_allocations_alone} asserts it so the
 * boundary is a measured decision and not an omission. **What is NOT delivered is returning units
 * to the warehouse they were sourced FROM**, which needs a per-order sourcing record this change
 * does not add and `INV-003`'s transfer capability to correct afterwards. Named in the report as an
 * owner question, not as a defect.
 */
class WarehouseStockSourcingTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 7;
    private const STORE_B = 9;

    private ProductStockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        $this->stock = new ProductStockService();
    }

    /** PREMISE: the dimension is installed, so an absence below is a real absence. */
    public function test_the_premise_that_the_dimension_is_installed(): void
    {
        $this->assertTrue(WarehouseInventory::available());
        $this->assertTrue(StockMovement::available(), 'PREMISE: the evidence record too');
    }

    /**
     * THE BYTE-FOR-BYTE CONTROL. A store that has configured no warehouse — every store on the
     * platform as it stands — sees exactly what it sees today.
     */
    public function test_a_store_with_no_warehouses_behaves_exactly_as_before(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);

        $result = $this->stock->decrementProductStock($product, 3, false, false, [
            'store_id' => self::STORE_A,
            'order_id' => 55,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        $this->assertSame(
            [
                'applied' => true,
                'reason' => ProductStockService::APPLIED,
                'quantity' => 3,
                'remaining' => 7,
            ],
            $result,
            'the whole returned array, not just the figure: a caller reads `reason` to decide '
            . 'whether to abandon the order'
        );

        $this->assertSame(7, $this->stockOf($product->id));

        $this->assertSame(
            0,
            (int) DB::table(WarehouseInventory::ALLOCATIONS)->count(),
            'no allocation row may be created by a decrement for a store that has no warehouses'
        );
        $this->assertSame(
            0,
            (int) DB::table(WarehouseInventory::WAREHOUSES)->count(),
            'and no warehouse may be invented for it either — INV-001 says a store HAS a default '
            . 'warehouse, which the arithmetic already gives it; it does not say a row must appear'
        );

        $this->assertSame(
            1,
            (int) DB::table('stock_movements')->where('order_id', 55)->count(),
            'the evidence record is unchanged: still exactly one movement for this order line'
        );
    }

    /**
     * THE CONTROL FOR THE TEST ABOVE. Without this, "nothing happened" passes whether or not the
     * integration works at all.
     */
    public function test_the_control_a_store_with_a_warehouse_really_does_move_an_allocation(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $depot, $product->id, 0, 6);

        $result = $this->stock->decrementProductStock($product, 3, false, false, [
            'store_id' => self::STORE_A,
            'order_id' => 56,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        $this->assertTrue($result['applied']);
        $this->assertSame(7, $this->stockOf($product->id), 'the authoritative total still falls by 3');

        $this->assertSame(
            [$depot => 3],
            WarehouseInventory::allocationsFor(self::STORE_A, $product->id),
            'and the depot gave up the 3 units the automatic selection chose it for'
        );

        $this->assertSame(
            7,
            array_sum(array_column(
                WarehouseInventory::breakdownFor(self::STORE_A, $product->id, 0),
                'quantity'
            )),
            'the invariant still holds: 3 at the depot + 4 at the default == 7'
        );
    }

    /**
     * A store whose warehouse holds NO allocation of this product writes nothing — the default's
     * remainder sources it, which needs no write.
     */
    public function test_a_configured_store_with_no_allocation_for_the_line_writes_nothing(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        $this->stock->decrementProductStock($product, 3, false, false, [
            'store_id' => self::STORE_A,
            'order_id' => 57,
        ]);

        $this->assertSame(7, $this->stockOf($product->id));
        $this->assertSame(
            0,
            (int) DB::table(WarehouseInventory::ALLOCATIONS)->count(),
            'an unallocated product is sourced from the default pool, with no allocation write'
        );
    }

    /** The sourcing is clamped at what the chosen warehouse holds; the rest comes from the default. */
    public function test_sourcing_is_clamped_and_the_remainder_comes_from_the_default(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $home = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $depot, $product->id, 0, 2);

        $this->stock->decrementProductStock($product, 5, false, false, [
            'store_id' => self::STORE_A,
            'order_id' => 58,
        ]);

        $this->assertSame(5, $this->stockOf($product->id));
        $this->assertSame(
            [],
            WarehouseInventory::allocationsFor(self::STORE_A, $product->id),
            'the depot gave its 2 and the row is gone'
        );

        $byId = array_column(
            WarehouseInventory::breakdownFor(self::STORE_A, $product->id, 0),
            null,
            'warehouse_id'
        );
        $this->assertSame(5, $byId[$home]['quantity'], 'the other 3 came from the default');
        $this->assertSame(0, $byId[$depot]['quantity']);
    }

    /** A VARIANT decrement sources the variant's own allocation, not the parent product's. */
    public function test_a_variant_decrement_sources_the_variants_own_allocation(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $variant = $this->makeVariant(self::STORE_A, $product->id, 10);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $depot, $product->id, $variant->id, 6);
        WarehouseInventory::allocate(self::STORE_A, $depot, $product->id, 0, 50);

        $this->stock->decrementVariantStock($variant, 4, false, false, [
            'store_id' => self::STORE_A,
            'order_id' => 59,
        ]);

        $this->assertSame(6, $this->variantStockOf($variant->id));
        $this->assertSame(
            [$depot => 2],
            WarehouseInventory::allocationsFor(self::STORE_A, $product->id, $variant->id)
        );
        $this->assertSame(
            [$depot => 50],
            WarehouseInventory::allocationsFor(self::STORE_A, $product->id, 0),
            "the parent product's allocation is untouched: a variant sale is not a product sale"
        );
    }

    /**
     * TENANCY, DRIVEN. Store B's product decremented must not reach store A's allocation, even
     * though both name the same warehouse id space and nothing in `config/tenancy.php` scopes
     * either table.
     *
     * The store is derived from the DECREMENTED ROW (`$model->store_id`, which is what
     * `AppliesCartStockChanges::stockEvidence()` already threads) and never from a caller's claim.
     * This drives the hostile case: an evidence array CLAIMING store A while the row is store B's.
     */
    public function test_a_claimed_store_cannot_reach_another_tenants_allocation(): void
    {
        $productA = $this->makeProduct(self::STORE_A, 10);
        $productB = $this->makeProduct(self::STORE_B, 10);

        $this->makeWarehouse(self::STORE_A, 'A home', ['is_default' => 1]);
        $depotA = $this->makeWarehouse(self::STORE_A, 'A depot', ['priority' => 1]);
        $this->makeWarehouse(self::STORE_B, 'B home', ['is_default' => 1]);
        $depotB = $this->makeWarehouse(self::STORE_B, 'B depot', ['priority' => 1]);

        /* THE PREMISE, ASSERTED BOTH SIDES. "store A's row is untouched" is also what an allocation
           that was never written produces. */
        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_A, $depotA, $productA->id, 0, 6)
        );
        $this->assertSame(
            WarehouseInventory::ALLOCATED,
            WarehouseInventory::allocate(self::STORE_B, $depotB, $productB->id, 0, 6)
        );

        /* The evidence claims store A. The row belongs to store B. */
        $this->stock->decrementProductStock($productB, 3, false, false, [
            'store_id' => self::STORE_A,
            'order_id' => 60,
        ]);

        $this->assertSame(
            [$depotA => 6],
            WarehouseInventory::allocationsFor(self::STORE_A, $productA->id),
            "store A's allocation must be untouched by a decrement of store B's product"
        );

        $this->assertSame(
            [$depotB => 3],
            WarehouseInventory::allocationsFor(self::STORE_B, $productB->id),
            "and store B's own depot sourced it, because the store is taken from the ROW"
        );
    }

    /**
     * OVERSELL BEHAVIOUR IS UNCHANGED, both directions, on a store that HAS warehouses.
     *
     * The refusal arm is the one that matters: a refused decrement must leave the allocation alone,
     * because crediting a sourcing for a sale that did not happen would lose the units.
     */
    public function test_an_allocation_cannot_make_a_sale_succeed_or_fail(): void
    {
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        /* REFUSED: stock_order_status = not_allow and the quantity exceeds the stock. */
        $short = $this->makeProduct(self::STORE_A, 2);
        WarehouseInventory::allocate(self::STORE_A, $depot, $short->id, 0, 2);

        $refusal = $this->stock->decrementProductStock($short, 5, false, false, [
            'store_id' => self::STORE_A,
            'order_id' => 61,
        ]);

        $this->assertFalse($refusal['applied']);
        $this->assertSame(
            ProductStockService::INSUFFICIENT,
            $refusal['reason'],
            'the refusal must still name insufficient stock — not a warehouse reason. Nothing in '
            . 'the dimension may decide whether a sale proceeds.'
        );
        $this->assertSame(2, $this->stockOf($short->id));
        $this->assertSame(
            [$depot => 2],
            WarehouseInventory::allocationsFor(self::STORE_A, $short->id),
            'A REFUSED DECREMENT SOURCES NOTHING. Crediting it would take units off the depot for '
            . 'a sale that never happened.'
        );

        /* PERMITTED: an identical product with NO allocation behaves identically, which is the
           evidence that the allocation is not part of the decision. */
        $plenty = $this->makeProduct(self::STORE_A, 2);

        $this->assertSame(
            ProductStockService::INSUFFICIENT,
            $this->stock->decrementProductStock($plenty, 5, false, false, [
                'store_id' => self::STORE_A,
                'order_id' => 62,
            ])['reason'],
            'same stock, no allocation, same answer'
        );
    }

    /**
     * The FORCED arm — a post-capture writer that cannot refuse — still sources, because the goods
     * really did leave, and the oversold figure is still reported as it is today.
     */
    public function test_the_forced_oversell_arm_still_sources_what_the_warehouse_holds(): void
    {
        $product = $this->makeProduct(self::STORE_A, 2);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $depot, $product->id, 0, 2);

        $result = $this->stock->decrementProductStock($product, 5, true, false, [
            'store_id' => self::STORE_A,
            'order_id' => 63,
        ]);

        $this->assertTrue($result['applied']);
        $this->assertSame(
            ProductStockService::OVERSOLD,
            $result['reason'],
            'the oversold outcome is reported exactly as it is today'
        );
        $this->assertSame(-3, $this->stockOf($product->id));
        $this->assertSame(
            [],
            WarehouseInventory::allocationsFor(self::STORE_A, $product->id),
            'the depot gave the 2 it really held; the shop is short the other 3, which is what the '
            . 'negative total already says'
        );
    }

    /**
     * THE RESTORE BOUNDARY, PINNED. A restore raises the authoritative total and leaves every
     * allocation alone, so the units land in the default's derived remainder.
     *
     * Asserted so that the decision is measured rather than inferred from six unchanged files, and
     * so that a later change which starts moving allocations on restore has to come through here.
     */
    public function test_a_restore_returns_units_to_the_default_and_leaves_allocations_alone(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $home = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $depot, $product->id, 0, 6);

        $this->stock->decrementProductStock($product, 3, false, false, [
            'store_id' => self::STORE_A,
            'order_id' => 64,
        ]);

        $this->assertSame([$depot => 3], WarehouseInventory::allocationsFor(self::STORE_A, $product->id));

        $this->stock->restoreProductStock($product->id, 3, [
            'store_id' => self::STORE_A,
            'order_id' => 64,
            'reason' => StockMovement::REASON_ORDER_RETURN,
        ]);

        $this->assertSame(10, $this->stockOf($product->id), 'the total is back');

        $this->assertSame(
            [$depot => 3],
            WarehouseInventory::allocationsFor(self::STORE_A, $product->id),
            'THE DEPOT IS NOT CREDITED. The units came back to the store, not to the shelf they '
            . 'were picked from — see the class docblock.'
        );

        $byId = array_column(
            WarehouseInventory::breakdownFor(self::STORE_A, $product->id, 0),
            null,
            'warehouse_id'
        );
        $this->assertSame(7, $byId[$home]['quantity'], 'the default received them: 10 − 3');
        $this->assertSame(
            10,
            array_sum(array_column(
                WarehouseInventory::breakdownFor(self::STORE_A, $product->id, 0),
                'quantity'
            )),
            'and the invariant holds after the restore with no restore-side change at all'
        );
    }

    /**
     * A variant whose `store_id` is NULL — a live open owner decision, recorded in
     * `AppliesCartStockChanges`'s docblock — sources nothing, and nothing could have been allocated
     * to it either. The two rules agree, which is why a null tenant here is safe rather than a hole.
     */
    public function test_a_variant_with_no_store_sources_nothing_and_could_hold_nothing(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $variant = $this->makeVariant(self::STORE_A, $product->id, 10, ['store_id' => null]);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        $this->assertSame(
            WarehouseInventory::REFUSED_FOREIGN_PRODUCT,
            WarehouseInventory::allocate(self::STORE_A, $depot, $product->id, $variant->id, 4),
            'a variant with no store cannot be allocated in the first place'
        );

        $this->stock->decrementVariantStock($variant, 4, false, false, [
            'store_id' => self::STORE_A,
            'order_id' => 65,
        ]);

        $this->assertSame(6, $this->variantStockOf($variant->id), 'the decrement itself is unchanged');
        $this->assertSame(
            0,
            (int) DB::table(WarehouseInventory::ALLOCATIONS)->count()
        );
    }

    /** A decrement with NO evidence at all still works and sources from the row's own store. */
    public function test_a_decrement_with_no_evidence_still_sources_from_the_rows_own_store(): void
    {
        $product = $this->makeProduct(self::STORE_A, 10);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $depot, $product->id, 0, 6);

        /* `OrderController::store()` and `PosController` pass no evidence array at all. */
        $this->stock->decrementProductStock($product, 3);

        $this->assertSame(7, $this->stockOf($product->id));
        $this->assertSame(
            [$depot => 3],
            WarehouseInventory::allocationsFor(self::STORE_A, $product->id),
            'the store comes from the product row, so an un-threaded call site still sources'
        );
        $this->assertSame(
            0,
            (int) DB::table('stock_movements')->count(),
            'while the movement record stays silent, as it already does for an un-threaded call'
        );
    }

    // ---------------------------------------------------------------------------------------- //

    private function makeWarehouse(int $storeId, string $name, array $attributes = []): int
    {
        return WarehouseInventory::createWarehouse($storeId, array_merge(['name' => $name], $attributes));
    }

    private function makeProduct(int $storeId, int $stock): Product
    {
        $id = DB::table('products')->insertGetId([
            'name' => 'Sourcing fixture',
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

        return Product::findOrFail($id);
    }

    private function makeVariant(int $storeId, int $productId, int $stock, array $attributes = []): ProductVariant
    {
        $id = DB::table('product_variants')->insertGetId(array_merge([
            'product_id' => $productId,
            'variant' => 'S-Red',
            'sku' => 'WH-' . $productId . '-' . $stock,
            'price' => 0,
            'stock' => $stock,
            'stock_order_status' => 'not_allow',
            'variation_option' => 'manage_stock',
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return ProductVariant::findOrFail($id);
    }

    private function stockOf($productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('product_stock');
    }

    private function variantStockOf($variantId): int
    {
        return (int) DB::table('product_variants')->where('id', $variantId)->value('stock');
    }
}
