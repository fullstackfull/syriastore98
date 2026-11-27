<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * `INV-001` / `INV-002` / `INV-009` — THE WAREHOUSE DIMENSION ON STOCK.
 *
 * The approved decisions, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`
 * §04-B (المخزون والمستودعات):
 *
 *     INV-001  مستودع افتراضي واحد مع إمكانية تعدد المستودعات.
 *              One default warehouse, with multi-warehouse capability
 *     INV-002  مصدر موحد للكميات الفعلية والمحجوزة والمتاحة.
 *              A single source for actual, reserved and available quantities
 *     INV-009  عدم اعتبار المخزون السالب متاحاً للبيع الفوري.
 *              Negative stock is not available for immediate sale
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE ONE INVARIANT EVERYTHING HERE PRESERVES
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *     Σ(breakdown quantities) === products.product_stock   (or product_variants.stock)
 *
 * because the DEFAULT warehouse's figure is the REMAINDER and not a stored number:
 *
 *     default = total − Σ(allocations at this store's named warehouses)
 *
 * `products.product_stock` therefore remains the **single authoritative sellable total**, which is
 * both `INV-002`'s "single source" for the actual quantity and `ARCH-002`'s «عدم تكرار محركات
 * ... المخزون ...» / *no duplicate inventory engine*. A table that stored the default's figure too
 * could disagree with `product_stock`, and once two numbers can disagree something has to reconcile
 * them — which is the parallel engine `ARCH-002` and `EX-002` forbid.
 *
 * **OVERSELL BEHAVIOUR IS UNTOUCHED.** `ProductStockService`'s conditional
 * `UPDATE ... WHERE product_stock >= ?` is still the only thing that decides whether a sale may
 * proceed, and its predicate still names `product_stock`. Nothing here is consulted to refuse a
 * sale. This dimension answers **where the units are**, never **whether they may be sold** — which
 * is why adding it cannot change what any shopper is charged or whether any order is accepted.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY RAW `DB::table()` AND NO ELOQUENT MODEL
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Two independent reasons that happen to agree, which is the comfortable case:
 *
 *   1. **MEASURED BLOCKER.** `tests/Unit/Tenancy/ClaudeMdCachableModelClaimTest::
 *      test_claude_md_states_the_measured_totals` asserts that CLAUDE.md contains the literal
 *      `"<N> of the <M> models"` where M is `count(glob('app/Models/*.php'))`. **Any** new file
 *      there turns a required CI test red, and this workstream may not edit CLAUDE.md. Putting a
 *      model in a subdirectory would evade that census, which is the one thing this project
 *      forbids doing to a ratchet.
 *   2. **ON THE MERITS, THE SAME ANSWER.** Most models in `app/Models` are `Cachable` (CLAUDE.md
 *      carries the measured figure; do not restate it here). This table is read on the checkout
 *      path *immediately after* a stock write, and a `Cachable` model would serve the pre-write row
 *      — CLAUDE.md's three measured false readings. `App\Services\StockMovement` and
 *      `App\Services\ReturnInspection` are raw services for exactly this reason.
 *
 * Neither table is in `config/tenancy.php`'s `scoped_models`, so **no global scope would supply a
 * predicate even if a model existed**, and `null_tenant => 'open'` means none is added when no
 * tenant resolves. **Every statement below names `store_id`**, and every store id arrives as an
 * argument derived from an authenticated principal or from the product row itself — never from
 * request input. `ServiceLayerRawQueryInventoryTest` ratchets that property for this whole
 * directory.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * FAILURE POSTURE: THIS CLASS NEVER BREAKS A CHECKOUT
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * {@see available()} answers false where the tables do not exist — which is production until an
 * owner runs the migration — and every order-path entry point checks it first. {@see sourceFrom()}
 * returns 0 rather than throwing on any database trouble, for the reason
 * `StockMovement::record()`'s docblock gives: the stock write has already happened by the time it
 * is called, so raising here would turn a successful order into an error after money may have been
 * taken. **The degraded state is CONSISTENT, not merely survivable:** a sourcing write that does
 * not happen leaves the units attributed to the default's remainder, which is a true statement
 * about a store that has not recorded otherwise, and the invariant above still holds exactly.
 */
class WarehouseInventory
{
    public const WAREHOUSES = 'warehouses';
    public const ALLOCATIONS = 'product_warehouse_stock';

    /** This codebase's own spelling for "the product itself, not a variant". */
    public const NO_VARIANT = 0;

    /** {@see allocate()} outcomes. A refusal names its reason; a caller may print it. */
    public const ALLOCATED = 'allocated';
    public const REFUSED_NEGATIVE = 'refused_negative';
    public const REFUSED_BEYOND_TOTAL = 'refused_beyond_total';
    public const REFUSED_FOREIGN_WAREHOUSE = 'refused_foreign_warehouse';
    public const REFUSED_FOREIGN_PRODUCT = 'refused_foreign_product';
    public const REFUSED_UNAVAILABLE = 'refused_unavailable';

    /**
     * The DEFAULT warehouse holds the derived remainder, so it has no allocation row to set.
     *
     * **THIS REFUSAL IS A DEFECT FOUND IN THIS CLASS'S OWN FIRST FORM**, while enumerating what a
     * mutation could break. {@see breakdownFor()} computes the default's figure as
     * `total − Σ(allocations)`; a row FOR the default would be inside that sum, so the default's
     * figure would be reduced by its own allocation and the breakdown would sum to
     * `total − (that row)`. Measured: total 100, 30 allocated to the default, breakdown summed to
     * **70**. The one invariant this design rests on, broken by a merchant typing into the wrong
     * box. Pinned by `WarehouseDimensionTest::test_allocating_to_the_default_warehouse_is_refused`.
     */
    public const REFUSED_DEFAULT_WAREHOUSE = 'refused_default_warehouse';

    /**
     * Are both tables installed?
     *
     * **THIS IS THE METHOD THAT MAKES THE WHOLE CHANGE SAFE IN PRODUCTION.** The migration is
     * development-and-test only, so in production neither table exists and every entry point here
     * returns its empty answer without touching the database beyond this check. No caller may infer
     * anything from a false answer except "there is no dimension recorded"; in particular nothing
     * may refuse a sale on it.
     *
     * Deliberately not memoised, matching `StockMovement::available()`. A static memo would outlive
     * a test's schema and is the "memoised container bindings" pollution this project's
     * systematic-debugging notes name; the cost is a question for a measurement, not a guess.
     */
    public static function available(): bool
    {
        try {
            return Schema::hasTable(self::WAREHOUSES) && Schema::hasTable(self::ALLOCATIONS);
        } catch (\Throwable $theSchemaIsUnreadable) {
            return false;
        }
    }

    /**
     * `INV-001`: this store's warehouses, in automatic-selection order.
     *
     * @return list<array{id:int,name:string,code:?string,is_default:bool,is_active:bool,priority:int,address:?string}>
     */
    public static function warehousesFor(int $storeId): array
    {
        if (! self::available() || $storeId <= 0) {
            return [];
        }

        $rows = DB::table(self::WAREHOUSES)
            ->where('store_id', $storeId)
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'is_default', 'is_active', 'priority', 'address']);

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'code' => $row->code === null ? null : (string) $row->code,
            'is_default' => (bool) $row->is_default,
            'is_active' => (bool) $row->is_active,
            'priority' => (int) $row->priority,
            'address' => $row->address === null ? null : (string) $row->address,
        ])->all();
    }

    /** `INV-001`: the one default, or null for a store that has configured none. */
    public static function defaultWarehouseId(int $storeId): ?int
    {
        if (! self::available() || $storeId <= 0) {
            return null;
        }

        $id = DB::table(self::WAREHOUSES)
            ->where('store_id', $storeId)
            ->where('is_default', 1)
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Does this warehouse id belong to this store? The write-side tenancy check. */
    public static function ownsWarehouse(int $storeId, int $warehouseId): bool
    {
        if (! self::available() || $storeId <= 0 || $warehouseId <= 0) {
            return false;
        }

        return DB::table(self::WAREHOUSES)
            ->where('store_id', $storeId)
            ->where('id', $warehouseId)
            ->exists();
    }

    /**
     * The named allocations for one product or variant.
     *
     * @return array<int,int> warehouse id => allocated units
     */
    public static function allocationsFor(int $storeId, int $productId, int $variantId = self::NO_VARIANT): array
    {
        if (! self::available() || $storeId <= 0 || $productId <= 0) {
            return [];
        }

        $rows = DB::table(self::ALLOCATIONS)
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('variant_id', max(0, $variantId))
            ->orderBy('warehouse_id')
            ->get(['warehouse_id', 'quantity']);

        $allocations = [];

        foreach ($rows as $row) {
            $allocations[(int) $row->warehouse_id] = (int) $row->quantity;
        }

        return $allocations;
    }

    /**
     * `INV-002`: THE AUTHORITATIVE TOTAL, READ FROM THE ROW ITSELF.
     *
     * Deliberately not a parameter anywhere in this class's public surface. A caller-supplied total
     * would be a second source for the figure `INV-002` requires to have exactly one, and a wrong
     * one would silently move the derived remainder. The store predicate is here too, so a product
     * id from somewhere else answers null rather than another tenant's figure.
     *
     * @return int|null null when the row does not exist, or does not belong to this store
     */
    public static function totalFor(int $storeId, int $productId, int $variantId = self::NO_VARIANT): ?int
    {
        if ($storeId <= 0 || $productId <= 0) {
            return null;
        }

        if ($variantId > 0) {
            $total = DB::table('product_variants')
                ->where('store_id', $storeId)
                ->where('id', $variantId)
                ->where('product_id', $productId)
                ->value('stock');

            return $total === null ? null : (int) $total;
        }

        $total = DB::table('products')
            ->where('store_id', $storeId)
            ->where('id', $productId)
            ->value('product_stock');

        return $total === null ? null : (int) $total;
    }

    /**
     * `INV-001` + `INV-009`: the per-warehouse breakdown, with the default's DERIVED remainder.
     *
     * `quantity` is the real figure, which for the default can be negative when a merchant has
     * allocated more than the product holds. `available` is `INV-009`'s answer: a negative figure is
     * **not available for immediate sale** and reads 0.
     *
     * Both are present because they fail in opposite directions. Clamping `quantity` would hide the
     * merchant's only signal that an allocation is wrong; leaving `available` negative would let a
     * caller subtract it from a sellable figure.
     *
     * A store with no warehouse rows gets `[]` — there is no breakdown to show, not a breakdown of
     * one. The caller prints the product's own figure, exactly as it does today.
     *
     * @return list<array{warehouse_id:int,name:string,is_default:bool,is_active:bool,quantity:int,available:int}>
     */
    public static function breakdownFor(int $storeId, int $productId, int $variantId = self::NO_VARIANT): array
    {
        $warehouses = self::warehousesFor($storeId);

        if ($warehouses === []) {
            return [];
        }

        $total = self::totalFor($storeId, $productId, $variantId);

        if ($total === null) {
            /* The product is not this store's. Not an error and not an empty breakdown of a real
               product — there is nothing to break down, and saying so is the honest answer. */
            return [];
        }

        $allocations = self::allocationsFor($storeId, $productId, $variantId);
        $allocated = array_sum($allocations);

        $breakdown = [];

        foreach ($warehouses as $warehouse) {
            $quantity = $warehouse['is_default']
                ? $total - $allocated
                : ($allocations[$warehouse['id']] ?? 0);

            $breakdown[] = [
                'warehouse_id' => $warehouse['id'],
                'name' => $warehouse['name'],
                'is_default' => $warehouse['is_default'],
                'is_active' => $warehouse['is_active'],
                'quantity' => $quantity,
                'available' => self::availableUnits($quantity),
            ];
        }

        return $breakdown;
    }

    /**
     * `INV-009`: «عدم اعتبار المخزون السالب متاحاً للبيع الفوري.»
     *
     * One line, in one place, so no reader invents a second reading of the same decision.
     */
    public static function availableUnits(int $quantity): int
    {
        return max(0, $quantity);
    }

    /**
     * `INV-009`: the units available for immediate sale across this store's warehouses.
     *
     * The sum of the CLAMPED figures, which is not the same as clamping the sum: a negative
     * remainder is excluded rather than subtracted from the named warehouses' real units, because
     * units that are physically at a depot are available whatever arithmetic says about the pool
     * they were over-allocated from.
     *
     * This is a REPORT, never a sale gate. Nothing in the order path consults it.
     */
    public static function availableAcross(int $storeId, int $productId, int $variantId = self::NO_VARIANT): int
    {
        $sum = 0;

        foreach (self::breakdownFor($storeId, $productId, $variantId) as $line) {
            $sum += $line['available'];
        }

        return $sum;
    }

    /**
     * Create a warehouse for a store. `INV-001`'s "one default" is enforced here.
     *
     * @param  array{name?:string,code?:?string,is_default?:mixed,is_active?:mixed,priority?:mixed,address?:?string,created_by?:?int} $attributes
     * @return int the new warehouse's id, or 0 when the dimension is not installed
     */
    public static function createWarehouse(int $storeId, array $attributes): int
    {
        if (! self::available() || $storeId <= 0) {
            return 0;
        }

        /* THE FIRST WAREHOUSE A STORE CREATES IS ITS DEFAULT whether it asked or not. A store with
           allocations but no default would have nowhere for the remainder to live, so the breakdown
           would not sum to the total and the invariant this class exists to keep would be broken by
           a merchant forgetting a checkbox. */
        $isFirst = ! DB::table(self::WAREHOUSES)->where('store_id', $storeId)->exists();
        $wantsDefault = $isFirst || ! empty($attributes['is_default']);

        return (int) DB::transaction(function () use ($storeId, $attributes, $wantsDefault) {
            if ($wantsDefault) {
                self::demoteDefaults($storeId);
            }

            return DB::table(self::WAREHOUSES)->insertGetId([
                'store_id' => $storeId,
                'name' => (string) ($attributes['name'] ?? ''),
                'code' => $attributes['code'] ?? null,
                'is_default' => $wantsDefault ? 1 : 0,
                'is_active' => array_key_exists('is_active', $attributes)
                    ? (int) (bool) $attributes['is_active']
                    : 1,
                'priority' => (int) ($attributes['priority'] ?? 0),
                'address' => $attributes['address'] ?? null,
                'created_by' => $attributes['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Update one of a store's warehouses. Refuses a warehouse the store does not own.
     *
     * @param  array<string,mixed> $attributes
     */
    public static function updateWarehouse(int $storeId, int $warehouseId, array $attributes): bool
    {
        if (! self::ownsWarehouse($storeId, $warehouseId)) {
            return false;
        }

        $wantsDefault = ! empty($attributes['is_default']);

        return (bool) DB::transaction(function () use ($storeId, $warehouseId, $attributes, $wantsDefault) {
            if ($wantsDefault) {
                self::demoteDefaults($storeId);
            }

            $update = ['updated_at' => now()];

            foreach (['name', 'code', 'address'] as $text) {
                if (array_key_exists($text, $attributes)) {
                    $update[$text] = $attributes[$text];
                }
            }

            if (array_key_exists('priority', $attributes)) {
                $update['priority'] = (int) $attributes['priority'];
            }

            if (array_key_exists('is_active', $attributes)) {
                $update['is_active'] = (int) (bool) $attributes['is_active'];
            }

            if ($wantsDefault) {
                $update['is_default'] = 1;
            }

            DB::table(self::WAREHOUSES)
                ->where('store_id', $storeId)
                ->where('id', $warehouseId)
                ->update($update);

            /* THE LAST DEFAULT CANNOT BE GIVEN UP WITHOUT A SUCCESSOR. Clearing it would leave the
               remainder homeless; see createWarehouse(). A merchant who wants a different default
               sets it on the OTHER warehouse, which demotes this one. */
            return true;
        });
    }

    /**
     * Delete one of a store's warehouses.
     *
     * Its allocations go with it, which moves those units into the default's remainder — the honest
     * reading: the merchant has said that place no longer exists, so its units are back in the pool.
     * The sellable total is untouched either way, which is why this cannot lose or mint stock.
     *
     * The DEFAULT warehouse cannot be deleted while another warehouse exists: the remainder needs
     * somewhere to live.
     */
    public static function deleteWarehouse(int $storeId, int $warehouseId): bool
    {
        if (! self::ownsWarehouse($storeId, $warehouseId)) {
            return false;
        }

        $isDefault = (bool) DB::table(self::WAREHOUSES)
            ->where('store_id', $storeId)
            ->where('id', $warehouseId)
            ->value('is_default');

        $others = (int) DB::table(self::WAREHOUSES)
            ->where('store_id', $storeId)
            ->where('id', '!=', $warehouseId)
            ->count();

        if ($isDefault && $others > 0) {
            return false;
        }

        return (bool) DB::transaction(function () use ($storeId, $warehouseId) {
            DB::table(self::ALLOCATIONS)
                ->where('store_id', $storeId)
                ->where('warehouse_id', $warehouseId)
                ->delete();

            DB::table(self::WAREHOUSES)
                ->where('store_id', $storeId)
                ->where('id', $warehouseId)
                ->delete();

            return true;
        });
    }

    /**
     * SET a named warehouse's allocation for one product or variant.
     *
     * SET, not add: the merchant types a figure into a box that already shows one, so accumulating
     * would land the sum of what they saw and what they typed.
     *
     * Refused, each with its own reason so a caller can tell them apart and a test can assert which
     * rule fired (CLAUDE.md §3: a refusal asserted without its reason is satisfied by any refusal):
     *
     *   - a negative figure — it would mint units at a place;
     *   - a warehouse this store does not own — the write-side tenancy check;
     *   - a product this store does not own — likewise, via {@see totalFor()} answering null;
     *   - a figure that would put the store's named allocations above the authoritative total,
     *     because the remainder would then be negative by the merchant's own instruction rather than
     *     by a later stock edit. `INV-009` tolerates a negative remainder that ARRIVES; it is no
     *     reason to let one be created on purpose.
     *
     * @return string one of the outcome constants
     */
    public static function allocate(
        int $storeId,
        int $warehouseId,
        int $productId,
        int $variantId,
        int $quantity
    ): string {
        if (! self::available()) {
            return self::REFUSED_UNAVAILABLE;
        }

        if ($quantity < 0) {
            return self::REFUSED_NEGATIVE;
        }

        if (! self::ownsWarehouse($storeId, $warehouseId)) {
            return self::REFUSED_FOREIGN_WAREHOUSE;
        }

        /* See REFUSED_DEFAULT_WAREHOUSE: the default's figure is derived, and a row for it would be
           inside the sum that derives it. */
        if (self::defaultWarehouseId($storeId) === $warehouseId) {
            return self::REFUSED_DEFAULT_WAREHOUSE;
        }

        $variantId = max(0, $variantId);
        $total = self::totalFor($storeId, $productId, $variantId);

        if ($total === null) {
            return self::REFUSED_FOREIGN_PRODUCT;
        }

        $allocations = self::allocationsFor($storeId, $productId, $variantId);
        unset($allocations[$warehouseId]);

        if (array_sum($allocations) + $quantity > $total) {
            return self::REFUSED_BEYOND_TOTAL;
        }

        if ($quantity === 0) {
            DB::table(self::ALLOCATIONS)
                ->where('store_id', $storeId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->delete();

            return self::ALLOCATED;
        }

        /*
         * AN UPSERT, NOT `updateOrInsert()`, AND THAT CHOICE IS A MEASURED DEFECT IN THIS METHOD'S
         * FIRST FORM. `updateOrInsert()` is SELECT-then-INSERT and is therefore not atomic: two
         * merchants saving the same product's allocation in the same instant both see "no row" and
         * both insert. Driven on MariaDB 10.11.14 by
         * `tests/Support/Security/bin/verify-warehouse-allocation-on-mysql.php` §F — both callers
         * read `false`, and the second INSERT raised `SQLSTATE[23000] 1062 Duplicate entry
         * '3-11-0'`. The unique index contained the damage (one row, never two), so the stock
         * figures stayed correct — but the losing merchant's save became a 500. **SQLITE COULD NOT
         * HAVE SHOWN THIS**: it serialises writers at the database level, so the race does not
         * occur under the suite.
         *
         * `upsert()` is ONE statement — `INSERT ... ON DUPLICATE KEY UPDATE` on MySQL/MariaDB,
         * `INSERT ... ON CONFLICT DO UPDATE` on SQLite 3.24+ — so the engine resolves the race and
         * the last writer wins, which is the correct answer for a merchant typing a figure.
         *
         * THE CONFLICT TARGET IS THE UNIQUE INDEX AND NOT `store_id`, AND THAT IS THE ONE STATEMENT
         * HERE WHOSE TENANCY IS AN ARGUMENT RATHER THAN A PREDICATE. Both limbs are proven above
         * and neither is a caller's claim:
         *
         *   - `ownsWarehouse($storeId, $warehouseId)` returned true, and a warehouse row has
         *     exactly one `store_id`, so `warehouse_id` DETERMINES the store;
         *   - `totalFor($storeId, $productId, $variantId)` returned non-null, which it only does
         *     under `where('store_id', $storeId)` on the product or variant row.
         *
         * So the key `(warehouse_id, product_id, variant_id)` can only name a row of this store,
         * and `store_id` is written from the verified value. `store_id` is deliberately NOT in the
         * update list: a conflict means the row already exists for that place, and its tenant
         * cannot have changed.
         */
        DB::table(self::ALLOCATIONS)->upsert(
            [[
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['warehouse_id', 'product_id', 'variant_id'],
            ['quantity', 'updated_at']
        );

        return self::ALLOCATED;
    }

    /**
     * Take units off a named warehouse's allocation, clamped at what it holds, and answer how many
     * were really taken.
     *
     * **THE CLAMP IS WHAT KEEPS THE INVARIANT.** Sourcing more than a warehouse holds would drive
     * its allocation below zero while the authoritative total fell by the full quantity, so the
     * derived remainder would RISE — inventing units at the default. The conditional
     * `WHERE quantity >= ?` is also what makes two concurrent checkouts sourcing the same depot
     * produce one success and one clamp rather than a lost update; it is the same argument
     * `ProductStockService`'s docblock makes for its own single conditional UPDATE.
     *
     * Never throws. See the class docblock's failure posture: a sourcing write that does not happen
     * leaves those units attributed to the default's remainder, which is consistent.
     */
    public static function sourceFrom(
        int $storeId,
        int $warehouseId,
        int $productId,
        int $variantId,
        int $quantity
    ): int {
        if (! self::available() || $quantity <= 0 || $storeId <= 0 || $warehouseId <= 0 || $productId <= 0) {
            return 0;
        }

        $variantId = max(0, $variantId);

        try {
            $held = DB::table(self::ALLOCATIONS)
                ->where('store_id', $storeId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->value('quantity');

            if ($held === null) {
                return 0;
            }

            $take = min($quantity, (int) $held);

            if ($take <= 0) {
                return 0;
            }

            /* The conditional decrement. `quantity >= $take` makes "zero rows affected" the
               authoritative answer that somebody else got there first, exactly as
               `ProductStockService` reads it for `product_stock`. */
            $affected = DB::table(self::ALLOCATIONS)
                ->where('store_id', $storeId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->where('quantity', '>=', $take)
                ->decrement('quantity', $take);

            if ($affected === 0) {
                return 0;
            }

            DB::table(self::ALLOCATIONS)
                ->where('store_id', $storeId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->where('quantity', '<=', 0)
                ->delete();

            return $take;
        } catch (\Throwable $theWriteFailed) {
            Log::warning('Warehouse sourcing could not be recorded', [
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'error' => $theWriteFailed->getMessage(),
            ]);

            return 0;
        }
    }

    /** Demote whichever warehouse currently holds the default flag. */
    private static function demoteDefaults(int $storeId): void
    {
        DB::table(self::WAREHOUSES)
            ->where('store_id', $storeId)
            ->where('is_default', 1)
            ->update(['is_default' => 0, 'updated_at' => now()]);
    }
}
