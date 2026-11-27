<?php

namespace Tests\Unit\Commerce;

use App\Services\StockMovement;
use PHPUnit\Framework\TestCase;

/**
 * `INV-002`, `INV-003`, `INV-007`, `INV-008` -- WHAT THE STOCK MODEL CAN AND CANNOT EXPRESS.
 * ==========================================================================================
 *
 * Four approved decisions, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *     INV-002  مصدر موحد للكميات الفعلية والمحجوزة والمتاحة.
 *              A single source for actual, reserved and available quantities
 *
 *     INV-003  حركات مخزنية وجرد وتحويلات وتسويات.
 *              Stock movements, stocktakes, transfers and adjustments
 *
 *     INV-007  اختيار مستودع التنفيذ تلقائياً مع تعديل مصرح به.
 *              Automatic fulfilment-warehouse selection, with an authorised override
 *
 *     INV-008  توزيع التنفيذ على عدة مستودعات وشحنات جزئية.
 *              Split fulfilment across warehouses, and partial shipments
 *
 * None of the four appears in `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` or
 * `MASTER-PLAN-GAP-REGISTER.md`. Measured across all host and vendored migrations, three of
 * them had no schema whatsoever and the fourth had one of its three quantities.
 *
 * -------------------------------------------------------------------------------------------------
 * **SUPERSEDED IN PART, 2026-11-27.** `P04-P4-02` built the WAREHOUSE DIMENSION for `INV-001` and
 * `INV-007`. This file's own closing paragraph said *"the first commit that starts one of them fails
 * here and has to update the disposition"* — this is that update, and the paragraphs below are
 * re-measured rather than relaxed. What changed and what did not:
 *
 *   `INV-001`  **BUILT** (was not tracked here at all; it belongs to `P04-P4-02`'s row).
 *              `warehouses` + `product_warehouse_stock`, `App\Services\WarehouseInventory`.
 *   `INV-002`  **STILL ONE THIRD BUILT, and the fraction did not move.** The warehouse dimension
 *              adds no reserved quantity and no stored available quantity — see below.
 *   `INV-003`  **UNCHANGED.** Still one of four nouns, still order-events-only, still absent in
 *              production. A transfer between two warehouses is now *expressible* in the schema
 *              and is still not *implemented*: there is no transfer surface, no movement reason for
 *              one, and nothing that writes a pair of allocations as one event.
 *   `INV-007`  **BUILT.** `App\Support\Commerce\WarehouseSelector` — automatic selection plus an
 *              authorised override. **The override has no UI door**: the fulfilment / picking
 *              surface `ORD-008` asks for does not exist, so the plumbing is reachable only from
 *              the service boundary where `WarehouseSelectorTest` drives it.
 *   `INV-008`  **STILL UNBUILT, and it is the half of its bullet that matters.** «توزيع التنفيذ
 *              على عدة مستودعات وشحنات جزئية.» needs a SHIPMENT entity and there is none under any
 *              spelling — `ShipCod04DFamilyCensusTest` still asserts that. The allocation-level
 *              sourcing `WarehouseSelector` performs is bookkeeping about where units came from; it
 *              produces no shipment, no second delivery state and no change to any order's
 *              lifecycle, and must not be read as `INV-008` delivered.
 *
 * -------------------------------------------------------------------------------------------------
 * `INV-002`: ONE OF THE THREE QUANTITIES EXISTS.
 *
 * `INV-002` asks for a single source for THREE quantities -- actual (الفعلية), reserved (المحجوزة)
 * and available (المتاحة). Measured:
 *
 *   ACTUAL     EXISTS and genuinely is single-source. `products.product_stock` and
 *              `product_variants.stock` are authoritative, and `ProductStockService` is the only
 *              thing that moves them on an order path (asserted separately by
 *              {@see Inv005ReservationPointTest}).
 *   RESERVED   NO REPRESENTATION. No table, no column, no code concept, under any spelling.
 *   AVAILABLE  NO REPRESENTATION, and it is not derivable either: available = actual - reserved,
 *              and reserved does not exist.
 *
 * The four schema hits for a broad reserved/available pattern across 277 migrations are all
 * something else, and each is declared below: `jobs.reserved_at` and `jobs.available_at` are
 * Laravel's queue, `store_wallets.available_balance` is money, and `pre_order_settings.
 * available_date` is a date belonging to `INV-006`'s pre-order feature.
 *
 * So `INV-002` is **one third built**. Saying "single source: satisfied" because the actual
 * quantity has one owner would answer a different question from the one the decision asks.
 *
 * -------------------------------------------------------------------------------------------------
 * `INV-003`: ONE OF ITS FOUR NOUNS EXISTS, AND IT IS NARROWER THAN THE WORD SUGGESTS.
 *
 *   MOVEMENTS    PARTIAL. `stock_movements` exists, but its own migration docblock declares it
 *                **"DEVELOPMENT AND TEST ONLY"** -- it does not exist in production, where
 *                `StockMovement::available()` is false and every answer is `UNKNOWN` by design.
 *                Its `reason` vocabulary is five values and all five are ORDER or REFUND events
 *                plus `manual`: there is no movement CLASS for a stocktake, a transfer or an
 *                adjustment, so those events could not be recorded even if a surface existed.
 *                The table is also explicitly **not a ledger** -- its docblock forbids summing it
 *                into a balance -- so it cannot stand in for `INV-002` either.
 *   STOCKTAKES   NO SCHEMA, no surface.
 *   TRANSFERS    NO SCHEMA, no surface. A transfer needs two warehouses and there are none.
 *   ADJUSTMENTS  NO SCHEMA. The nearest thing is a merchant retyping `product_stock` on the
 *                product edit screen, which leaves no record of what changed, by whom or why.
 *
 * -------------------------------------------------------------------------------------------------
 * `INV-007` AND `INV-008`: THE WAREHOUSE NOW EXISTS. THE SHIPMENT DOES NOT.
 *
 * **THE PARAGRAPH THAT STOOD HERE IS NOW FALSE AND IS QUOTED SO THE DATE IS RECOVERABLE:** *"Not
 * 'no selection logic' -- no warehouse ENTITY. Across 277 migrations there is no `warehouses` table
 * and not one column whose name contains `warehouse`; the word appears in `app/` exactly once, in a
 * comment in `PackingSlip` saying that `picked_up` is the courier and 'not warehouse picking'."*
 * That was true when it was written and is the measurement `P04-P4-02` started from.
 *
 * As of 2026-11-27 there IS a warehouse entity (`warehouses`), a per-warehouse quantity
 * (`product_warehouse_stock`) and an automatic fulfilment selector with an authorised override.
 * `INV-007` is built. **`INV-008` IS NOT, AND ITS BLOCKER IS UNCHANGED:** there is still no
 * `shipments` table and no `shipment_id` column anywhere, which
 * {@see test_the_schema_knows_nothing_of_reservations_or_shipments} still asserts over the whole
 * schema. A partial shipment also changes what `orders.delivered_status` means, which is
 * `P04-P4-03`'s open owner question `H4`, so it is not an engineering choice to make.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IS STILL NOT BUILT, AND THAT IS A DISPOSITION RATHER THAN A SHORTFALL.
 *
 * A reservation ledger and a stocktake/transfer/adjustment workflow are each a subsystem, and
 * building them beside the existing one is the "parallel commerce engine" `EX-002` and `ARCH-002`
 * forbid without a decision. They also change stock arithmetic on existing data, which CLAUDE.md
 * §2 puts in the escalate-to-owner set: a reservation changes WHEN stock becomes unbuyable, and an
 * adjustment moves `products.product_stock` itself.
 *
 * **THE WAREHOUSE DIMENSION IS NOT ONE OF THOSE, AND THE DISTINCTION IS THE WHOLE ARGUMENT FOR WHY
 * IT COULD BE BUILT WITHOUT A NEW DECISION.** `products.product_stock` REMAINS the single
 * authoritative sellable total; the default warehouse's figure is the arithmetic remainder and is
 * not stored, so the two cannot disagree and nothing reconciles them. Oversell behaviour is
 * untouched — `ProductStockService`'s conditional UPDATE is still the only sale gate and still
 * names `product_stock`. A store with no warehouses behaves byte-for-byte as before, asserted with
 * a positive control by `WarehouseStockSourcingTest`.
 *
 * What this file still delivers is the exact shape of each REMAINING gap, asserted, so the owner's
 * decision is about a measured scope rather than an impression -- and so that the first commit that
 * starts one of them fails here and has to update the disposition.
 */
class InventoryQuantitySourceAndWarehouseTest extends TestCase
{
    /**
     * Names that would mean a reserved or available QUANTITY, a warehouse, a shipment, a stocktake,
     * a transfer or an adjustment.
     *
     * Whole snake_case words, so `transferred_at` matches but `pilot` does not. Dangerous by
     * default: every hit must be declared.
     */
    private const CANDIDATE = '/(^|_)(reserved|reservation|reservations|available|availability|onhand|on_hand|warehouse|warehouses|shipment|shipments|stocktake|stock_take|stocktakes|transfer|transfers|adjustment|adjustments)(_|$)/i';

    /**
     * EVERY schema name matching that pattern, across all 277 migrations, and why each is not one
     * of the four decisions' subject matter.
     *
     * @var array<string, string>
     */
    private const PERMITTED_COLUMNS = [
        // Laravel's queue: when a job was reserved by a worker and when it becomes runnable.
        'reserved_at' => "Laravel's queue -- when a worker took the job",
        'available_at' => "Laravel's queue -- when the job becomes runnable",

        // MONEY, not stock. A merchant's withdrawable wallet balance.
        'available_balance' => 'the merchant wallet balance, in money',

        // A DATE, and it belongs to INV-006's pre-order feature, not to INV-002.
        'available_date' => "the pre-order settings' release date (INV-006, a different row)",

        /* DECLARED 2026-11-27 — `P04-P4-02` / `INV-001` / `INV-007`. The warehouse dimension's own
           key. It names a PLACE, not a reserved or available quantity: `INV-002`'s fraction is
           unchanged by it, because the dimension stores no reservation and no available figure.
           `product_warehouse_stock.quantity` is units PRESENT at a place; the available figure is
           `INV-009`'s derived clamp and is computed, never stored, so it cannot drift. */
        'warehouse_id' => 'the warehouse dimension\'s key (INV-001/INV-007) — a PLACE, not a '
            . 'reserved or available quantity',
    ];

    /**
     * Tables the warehouse dimension owns, declared so the census above can still catch an
     * UNDECLARED warehouse-shaped table.
     *
     * DECLARED 2026-11-27 (`P04-P4-02`). `test_the_warehouse_dimension_really_is_installed` asserts
     * the other direction, so neither entry can outlive its table.
     *
     * @var array<string, string>
     */
    private const PERMITTED_TABLES = [
        'warehouses' => 'INV-001: the warehouse entity, one default per store',
        'product_warehouse_stock' => 'INV-001: per-warehouse quantities. An ALLOCATION of units '
            . 'already counted in products.product_stock, never a second sellable total',
    ];

    /** @return list<string> every host and vendored migration path */
    private function migrationFiles(): array
    {
        $root = dirname(__DIR__, 3);

        $files = array_merge(
            glob($root . '/database/migrations/*.php') ?: [],
            glob($root . '/packages/workdo/*/src/database/migrations/*.php') ?: [],
            glob($root . '/packages/workdo/*/src/Database/Migrations/*.php') ?: []
        );

        sort($files);

        return $files;
    }

    /** THE PREMISE: 277 migrations are really being read, and the pattern really discriminates. */
    public function test_the_census_reads_the_migrations_and_the_pattern_discriminates(): void
    {
        $this->assertGreaterThan(
            200,
            count($this->migrationFiles()),
            'PREMISE: host and vendored migrations together are several hundred files. A small '
            . 'number means every absence asserted below is vacuous.'
        );

        foreach ([
            'reserved_qty', 'reserved_quantity', 'available_qty', 'warehouse_id',
            'shipment_id', 'stocktake_id', 'stock_transfer', 'adjustment_reason', 'on_hand',
        ] as $guilty) {
            $this->assertSame(
                1,
                preg_match(self::CANDIDATE, $guilty),
                "'$guilty' names what INV-002/003/007/008 ask for and must match."
            );
        }

        foreach (['pilot', 'variant_id', 'product_stock', 'delivered_status', 'availables'] as $innocent) {
            $this->assertSame(
                0,
                preg_match(self::CANDIDATE, $innocent),
                "'$innocent' must not match."
            );
        }
    }

    /**
     * `INV-002`/`INV-003`/`INV-008`: the complete schema hit set is the declared names, and no
     * undeclared table at all.
     *
     * RE-MEASURED AND RENAMED 2026-11-27. This read
     * `test_the_schema_knows_nothing_of_reservations_warehouses_or_shipments`, and the warehouse
     * clause of that name became false when `P04-P4-02` built the dimension. The warehouse names
     * are now DECLARED, with the argument for each, rather than the predicate being narrowed — so a
     * table or column named for a reservation, a shipment, a stocktake, a transfer or an adjustment
     * is still caught by default, and so is a warehouse name nobody declared.
     *
     * Both directions, so a stale declaration fails too — see the loops at the end and
     * {@see test_the_warehouse_dimension_really_is_installed}.
     */
    public function test_the_schema_knows_nothing_of_reservations_or_shipments(): void
    {
        $tables = [];
        $columns = [];

        foreach ($this->migrationFiles() as $file) {
            $code = php_strip_whitespace($file);

            if (preg_match_all("/Schema::(?:create|table)\(\s*'([^']+)'/", $code, $matches) > 0) {
                foreach ($matches[1] as $table) {
                    if (preg_match(self::CANDIDATE, $table) === 1
                        && ! array_key_exists($table, self::PERMITTED_TABLES)) {
                        $tables[$table] = basename($file);
                    }
                }
            }

            if (preg_match_all("/->\s*[a-zA-Z]+\(\s*'([^']+)'/", $code, $matches) > 0) {
                foreach ($matches[1] as $column) {
                    if (preg_match(self::CANDIDATE, $column) === 1
                        && ! array_key_exists($column, self::PERMITTED_COLUMNS)) {
                        $columns[$column] = basename($file);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $tables,
            "An UNDECLARED table named for a reservation, a shipment, a stocktake, a transfer, an "
            . "adjustment or a warehouse now exists. One of INV-002/003/008 has started, or the "
            . "warehouse dimension has grown a table nobody declared, and the disposition in this "
            . "file's header must be updated:\n" . json_encode($tables, JSON_PRETTY_PRINT)
        );

        $this->assertSame(
            [],
            $columns,
            "A column named for one of those things now exists and is not declared:\n"
            . json_encode($columns, JSON_PRETTY_PRINT)
        );

        // Every declared exception still matches, so a stale entry cannot sit here blunting the
        // census.
        foreach (array_keys(self::PERMITTED_COLUMNS) as $declared) {
            $this->assertSame(
                1,
                preg_match(self::CANDIDATE, $declared),
                "PERMITTED_COLUMNS carries '$declared', which no longer matches. Delete it."
            );
        }

        foreach (array_keys(self::PERMITTED_TABLES) as $declared) {
            $this->assertSame(
                1,
                preg_match(self::CANDIDATE, $declared),
                "PERMITTED_TABLES carries '$declared', which no longer matches. Delete it."
            );
        }
    }

    /**
     * THE OTHER DIRECTION OF `PERMITTED_TABLES`: each declared table really exists.
     *
     * Added 2026-11-27 with `P04-P4-02`. A declaration that outlives its table silently widens
     * what the census waves through — the same failure the `PERMITTED_COLUMNS` loop above exists
     * to prevent, and the one CLAUDE.md §3 records for every ratchet that carries an exception
     * list.
     *
     * It reads the MIGRATIONS rather than the live schema, because this is a `PHPUnit\TestCase`
     * with no database and the census it guards reads the same input.
     */
    public function test_the_warehouse_dimension_really_is_installed(): void
    {
        $created = [];

        foreach ($this->migrationFiles() as $file) {
            if (preg_match_all(
                "/Schema::create\(\s*'([^']+)'/",
                php_strip_whitespace($file),
                $matches
            ) > 0) {
                foreach ($matches[1] as $table) {
                    $created[$table] = true;
                }
            }
        }

        foreach (self::PERMITTED_TABLES as $table => $why) {
            $this->assertArrayHasKey(
                $table,
                $created,
                "PERMITTED_TABLES declares '$table' ($why) and no migration creates it. The "
                . 'declaration is stale: delete it rather than leaving it to blunt the census.'
            );
        }
    }

    /**
     * `INV-002`, RE-ASSERTED AFTER THE WAREHOUSE DIMENSION LANDED: the fraction did not move.
     *
     * The dimension stores units PRESENT at a place. It stores no reservation, and it stores no
     * available figure — `WarehouseInventory::availableUnits()` is `INV-009`'s clamp, computed on
     * read, and `availableAcross()` sums clamped figures for a REPORT that no sale path consults.
     * Neither is a quantity held in a column, so neither can drift from `products.product_stock`
     * and neither is the "single source" `INV-002` is still waiting for two thirds of.
     *
     * Stated as its own case because the header's claim would otherwise be prose, and this file's
     * whole argument is that a disposition should be asserted.
     */
    public function test_the_warehouse_dimension_stores_no_reserved_or_available_quantity(): void
    {
        $migration = null;

        foreach ($this->migrationFiles() as $file) {
            if (str_contains(php_strip_whitespace($file), "Schema::create('product_warehouse_stock'")) {
                $migration = php_strip_whitespace($file);
                break;
            }
        }

        $this->assertNotNull($migration, 'PREMISE: the dimension migration must be found.');

        foreach (['reserved', 'reservation', 'available', 'on_hand', 'onhand'] as $absent) {
            $this->assertSame(
                0,
                preg_match("/->\s*[a-zA-Z]+\(\s*'[^']*" . preg_quote($absent, '/') . "[^']*'/", $migration),
                "`product_warehouse_stock` has grown a column naming '$absent'. INV-002 asks for "
                . 'ONE source for the actual, reserved and available quantities; a stored reserved '
                . 'or available figure here would be a second one, and this row\'s disposition '
                . '("one third built") must be re-read.'
            );
        }

        $this->assertSame(
            1,
            preg_match("/->\s*unsignedInteger\(\s*'quantity'\s*\)/", $migration),
            'the one quantity column must stay UNSIGNED: an allocation is units present at a '
            . 'place and cannot be negative. The figure that CAN go negative is the default '
            . "warehouse's remainder, which is derived and held in no column at all."
        );
    }

    /**
     * `INV-002`: no code concept of a reserved or available stock quantity either.
     *
     * The schema census above could be satisfied by a reservation held somewhere other than a
     * column -- in a cache, say. This asserts the absence in `app/` too.
     */
    public function test_no_code_concept_of_reserved_or_available_stock_exists(): void
    {
        $root = dirname(__DIR__, 3) . '/app';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $hits = [];
        $scanned = 0;

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;

            $code = php_strip_whitespace($file->getPathname());

            if (preg_match_all(
                '/\b(reservedStock|reserved_stock|stockReserved|stock_reserved|availableStock|available_stock|reserveStock|reserve_stock|releaseStock|release_stock)\b/',
                $code,
                $matches
            ) > 0) {
                $hits[str_replace($root . '/', '', $file->getPathname())]
                    = array_values(array_unique($matches[1]));
            }
        }

        $this->assertGreaterThan(500, $scanned, 'PREMISE: app/ holds hundreds of PHP files.');

        $this->assertSame(
            [],
            $hits,
            "INV-002 asks for a single source for actual, RESERVED and AVAILABLE quantities. A "
            . "reserved-stock concept now exists in app/, so INV-002 has moved past 'one third "
            . "built' and INV-005's reservation point needs re-reading against it:\n"
            . json_encode($hits, JSON_PRETTY_PRINT)
        );
    }

    /**
     * `INV-003`: the movement record's event vocabulary has no class for a stocktake, a transfer or
     * an adjustment.
     *
     * This is why `INV-003` cannot be reported as "movements: built". The five reasons are order
     * and refund events plus `manual`; the three other nouns in the decision have nothing to be
     * recorded AS.
     */
    public function test_the_movement_vocabulary_covers_order_events_only(): void
    {
        $reasons = [
            StockMovement::REASON_ORDER_PLACE,
            StockMovement::REASON_ORDER_CANCEL,
            StockMovement::REASON_ORDER_RETURN,
            StockMovement::REASON_REFUND,
            StockMovement::REASON_MANUAL,
        ];

        $this->assertSame(
            ['manual', 'order_cancel', 'order_place', 'order_return', 'refund'],
            $this->sorted($reasons),
            'The movement reason vocabulary has changed. INV-003 asks for stocktakes, transfers '
            . 'and adjustments; if a reason for one of those now exists, the decision has moved '
            . 'and its disposition must be updated.'
        );

        foreach (['stocktake', 'transfer', 'adjustment', 'stock_take'] as $absent) {
            $this->assertNotContains(
                $absent,
                $reasons,
                "A '$absent' movement reason now exists -- INV-003 has started."
            );
        }
    }

    /**
     * `INV-003`/`INV-002`: the movement record is an EVENT LOG, not a ledger, and its own schema is
     * what keeps it that way.
     *
     * `direction` plus an UNSIGNED `quantity` rather than a signed figure, because a signed column
     * invites a `SUM()` that reads as a balance -- and a balance read off this table would become a
     * second source for the quantity `INV-002` wants to have exactly one of. The migration's
     * docblock states the intent; this asserts the schema that enforces it.
     */
    public function test_the_movement_record_cannot_be_summed_into_a_stock_balance(): void
    {
        $migration = null;

        foreach ($this->migrationFiles() as $file) {
            if (str_contains(php_strip_whitespace($file), "Schema::create('stock_movements'")) {
                $migration = php_strip_whitespace($file);
                break;
            }
        }

        $this->assertNotNull($migration, 'PREMISE: the stock_movements migration must be found.');

        $this->assertSame(
            1,
            preg_match("/->\s*string\(\s*'direction'\s*,\s*3\s*\)/", $migration),
            'The movement record must keep `direction` as a separate column: one EVENT per row.'
        );

        $this->assertSame(
            1,
            preg_match("/->\s*unsignedInteger\(\s*'quantity'\s*\)/", $migration),
            'The quantity must stay UNSIGNED. A signed quantity invites a SUM() that reads as a '
            . 'stock balance, which would make this table a second source for the quantity '
            . 'INV-002 requires to have exactly one.'
        );
    }

    /**
     * `INV-003`: the movement record is development and test only, so even the one noun that exists
     * does not exist in production.
     */
    public function test_the_movement_record_is_absent_in_production_by_design(): void
    {
        $this->assertTrue(
            method_exists(StockMovement::class, 'available'),
            'StockMovement::available() is what makes the production absence safe: it answers '
            . 'false where the table does not exist, and readers must treat that as "no evidence" '
            . 'rather than "no movement". INV-003 cannot be reported as built while this is the '
            . 'shape of the only movement record.'
        );
    }

    /** @param list<string> $values */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
