<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `INV-001` — THE WAREHOUSE DIMENSION. «مستودع افتراضي واحد مع إمكانية تعدد المستودعات.»
 *
 * The master plan's §04-B (المخزون والمستودعات), verbatim from
 * `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md` and
 * `DECISION-ID-REGISTER.md`: *one default warehouse, with multi-warehouse capability*. Tracked as
 * `P04-P4-02` in `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`, whose entry read **NOT IMPLEMENTED —
 * "NOTHING EXISTS AND NOTHING WAS ATTEMPTED"**, which this change makes partly false and whose
 * remaining clauses are named at the bottom of this header.
 *
 * **DEVELOPMENT AND TEST ONLY, exactly as `order_return_inspections`, `plan_purchase_intents` and
 * `orders.replaced_order_id` are, and for the reason their headers give:** the restriction is on
 * who may run a migration against production, which is the owner's decision, and it is not a
 * property of this file. Nothing here has been run against production and nothing may be without
 * that decision.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE DESIGN, AND THE ONE THING IT DELIBERATELY DOES NOT STORE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `products.product_stock` and `product_variants.stock` REMAIN the single authoritative sellable
 * total. Nothing here changes what either column means, and no existing total's value is altered.
 *
 * A row in `product_warehouse_stock` is an **allocation**: "this many of that product's units sit
 * at that named warehouse". The DEFAULT warehouse's figure is **not stored** — it is the remainder:
 *
 *     default = products.product_stock − Σ(allocations at this store's named warehouses)
 *
 * `ARCH-002` «عدم تكرار محركات المنتجات والمخزون والطلبات والتسعير.» / *no duplicate product,
 * inventory, order or pricing engines* is why. A table that also stored the default's figure would
 * be a **second source of truth for the sellable total**: it could disagree with `product_stock`,
 * and something would then have to reconcile the two. There is nothing to reconcile here, because
 * the breakdown sums to the total by construction — `WarehouseDimensionTest::
 * test_the_breakdown_always_sums_to_the_authoritative_total` asserts exactly that, and it is what
 * makes this additive rather than parallel. `INV-002`'s *"single source for the actual quantity"*
 * is preserved rather than duplicated.
 *
 * Three consequences:
 *
 *   1. **No backfill and no data migration.** A store with no rows here has its whole stock at its
 *      default warehouse by arithmetic, which is `INV-001`'s "one default warehouse" exactly.
 *   2. **A merchant retyping `product_stock` cannot desynchronise anything.** The remainder absorbs
 *      it. There are sixteen such write sites across `ProductController` and
 *      `Api\DashboardController` and not one of them needs to know this table exists.
 *   3. **The order path writes here only for a store that has allocated units to a NAMED
 *      warehouse.** A decrement sourced from the default pool needs no write at all, because the
 *      remainder falls out of `product_stock` falling. That is what lets
 *      `WarehouseStockSourcingTest` assert a byte-for-byte control for every store on the platform
 *      as it stands.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IS NOT HERE, AND WHY EACH ABSENCE IS DELIBERATE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *   - **No reserved or available COLUMN.** `INV-002` asks for actual, reserved and available
 *      quantities from one source. *Actual* exists and stays where it is. *Reserved* has no
 *      representation anywhere in the platform (measured across all host and vendored migrations by
 *      `InventoryQuantitySourceAndWarehouseTest`), and building a reservation ledger changes when
 *      stock becomes unbuyable — a stock business rule, which CLAUDE.md §2 reserves to the owner.
 *      *Available* is derived here (`INV-009`'s clamp) and is not stored, so it cannot drift.
 *   - **No `shipments` table and no partial-shipment plumbing.** `INV-008` «توزيع التنفيذ على عدة
 *      مستودعات وشحنات جزئية.» needs both, and a partial shipment changes what `orders.
 *      delivered_status` means — the subject of `P04-P4-03`'s still-open owner question `H4`.
 *   - **No stocktake, transfer or adjustment table.** `INV-003`. A transfer moves two allocations
 *      and would fit here; a stocktake or an adjustment moves `product_stock` itself, which is the
 *      money-adjacent total. They are one subsystem and it is not this one.
 *   - **No batch, lot or expiry anything.** `INV-010` / `EX-004` exclude them from the current
 *      scope, and `Inv010BatchLotExpiryExclusionTest` asserts the absence in both directions.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * NO ELOQUENT MODEL, NO FOREIGN KEYS, `variant_id` NOT NULL DEFAULT 0
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **No model.** `App\Services\WarehouseInventory` is the only reader and writer, raw
 * `DB::table()`, every statement naming `store_id`. Two reasons, the first measured: a new file in
 * `app/Models/` fails `ClaudeMdCachableModelClaimTest::test_claude_md_states_the_measured_totals`,
 * which asserts CLAUDE.md's `"<N> of the <M> models"` against `glob('app/Models/*.php')` — and on
 * the merits the answer is the same one `StockMovement` and `ReturnInspection` reached, because
 * most models here are `Cachable` and this table is read on the checkout path immediately after a
 * stock write, where a cached pre-write row is a real oversell.
 *
 * **No foreign keys**, for the reason `orders.replaced_order_id` and `order_return_inspections`
 * give: `products` and `product_variants` are read by 70 vendored modules, a real FK changes what a
 * `DELETE` does for all of them, and the integrity this needs — *"the row belongs to this store"* —
 * is not something a FK can express. `WarehouseInventory` writes it in PHP, where the store
 * predicate can be written and refused.
 *
 * **`variant_id` NOT NULL DEFAULT 0** is this codebase's own spelling for "the product itself, not
 * a variant" (`Order::performProductReturn()`, `OrderController::restoreReturnedStock()`, and
 * `product_json` lines all carry `variant_id: 0`). A NULLable column would make the unique index
 * below behave differently on MySQL and SQLite, because the SQL standard treats NULLs as distinct —
 * so two allocations for one simple product would both insert and the reader would have to choose.
 * `0` makes the key total and the index real.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table) {
                $table->bigIncrements('id');

                /*
                 * The tenancy predicate, and not inherited from anything. There is no
                 * `Warehouse` model, so no `StoreScope`; and `null_tenant => 'open'` means a scope
                 * that resolves no tenant adds NO predicate even where one is registered
                 * (CLAUDE.md). Every statement in `WarehouseInventory` writes
                 * `where('store_id', ...)` itself, and `WarehouseDimensionTest::
                 * test_one_stores_dimension_is_invisible_to_another` drives both directions.
                 */
                $table->unsignedBigInteger('store_id')->index();

                $table->string('name');

                /* The merchant's own short code, for a packing slip or a label. Theirs, not ours. */
                $table->string('code', 64)->nullable();

                /*
                 * `INV-001`'s "one default". At most ONE row per store carries 1, which
                 * `WarehouseInventory::createWarehouse()`/`updateWarehouse()` enforce by demoting
                 * the previous holder inside the same transaction. It is not a unique index: MySQL
                 * cannot express "unique among rows where is_default = 1" without a generated
                 * column, and a partial index is not portable to the SQLite the suite runs on. The
                 * invariant is asserted instead — `test_exactly_one_warehouse_is_the_default_for_a_store`.
                 */
                $table->boolean('is_default')->default(0);

                /*
                 * An INACTIVE warehouse is skipped by automatic fulfilment selection and keeps its
                 * allocations. It is the merchant's way of taking a location out of service without
                 * losing the record of what is in it — which deleting the row would do, and which
                 * would also silently move those units into the default's remainder.
                 */
                $table->boolean('is_active')->default(1);

                /*
                 * `INV-007`'s automatic selection order: lower is preferred. A plain integer rather
                 * than a rule engine, because the decision text asks for a selection and an
                 * authorised override, not for a strategy language.
                 */
                $table->integer('priority')->default(0);

                $table->string('address')->nullable();

                /* `users.id` of whoever created it. Nullable, so a future automated path does not
                   have to invent an attribution. */
                $table->unsignedBigInteger('created_by')->nullable();

                $table->timestamps();

                $table->index(['store_id', 'is_active', 'priority'], 'warehouses_store_selection_idx');
            });
        }

        if (! Schema::hasTable('product_warehouse_stock')) {
            Schema::create('product_warehouse_stock', function (Blueprint $table) {
                $table->bigIncrements('id');

                /* See `warehouses.store_id`. Same reason, same enforcement. */
                $table->unsignedBigInteger('store_id')->index();

                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('product_id');

                /* 0 for the product itself — see the header. */
                $table->unsignedBigInteger('variant_id')->default(0);

                /*
                 * UNSIGNED, deliberately. An allocation is a count of units present at a place and
                 * cannot be negative; `WarehouseInventory::allocate()` refuses a negative figure and
                 * `sourceFrom()` clamps at what the row holds, so the column's own constraint and
                 * the service's agree. The figure that CAN go negative is the DEFAULT's remainder,
                 * which is derived and therefore unconstrained by any column — that is `INV-009`'s
                 * subject and it is handled in the reader, not here.
                 */
                $table->unsignedInteger('quantity')->default(0);

                $table->timestamps();

                /* ONE allocation per (warehouse, product, variant). A second edit replaces the
                   figure rather than appending a row, so no reader has to resolve a disagreement
                   between two rows for the same place — the failure `RefundPolicy::allows()`
                   documents for `order_refund_settings`, which permits duplicates and is read three
                   times with no `ORDER BY`. */
                $table->unique(
                    ['warehouse_id', 'product_id', 'variant_id'],
                    'product_warehouse_stock_line_unique'
                );

                /* The reader's own shape: everything for one store's product, in one index. */
                $table->index(
                    ['store_id', 'product_id', 'variant_id'],
                    'product_warehouse_stock_lookup_idx'
                );
            });
        }
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as `create_order_return_inspections_table`,
     * `create_marketing_nudge_sends_table` and every GATE F migration.
     *
     * `product_warehouse_stock` holds a merchant's record of **where physical goods are**. Nothing
     * else in the platform holds it, and nothing can derive it — the figure is the merchant's own
     * count, entered by hand. A `down()` that dropped these tables would destroy that record
     * irrecoverably while leaving every sellable total correct, so the loss would be silent: the
     * shop would keep selling and nobody would be able to say which building to pick from.
     *
     * Two GATE F drafts dropped a column *because* it existed and a rollback destroyed 45
     * `cost_price` values (CLAUDE.md §1). The lesson this repository took from that is that a
     * reversible-looking `down()` on a data table is a loaded gun. Removing these tables is a
     * deliberate operator action, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
