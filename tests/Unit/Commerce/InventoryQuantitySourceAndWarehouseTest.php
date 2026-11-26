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
 * `MASTER-PLAN-GAP-REGISTER.md`. Measured across all **277** host and vendored migrations, three of
 * them have no schema whatsoever and the fourth has one of its three quantities.
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
 * `INV-007` AND `INV-008`: THERE IS NO WAREHOUSE.
 *
 * Not "no selection logic" -- no warehouse ENTITY. Across 277 migrations there is no `warehouses`
 * table and not one column whose name contains `warehouse`; the word appears in `app/` exactly once,
 * in a comment in {@see \App\Support\Orders\PackingSlip} saying that `picked_up` is the courier and
 * *"not warehouse picking"*. There is likewise no `shipments` table and no `shipment_id` column.
 *
 * So both decisions are unimplementable as stated until a warehouse entity exists, and that is a
 * dependency on `INV-001` ("one default warehouse, with multi-warehouse capability"), which belongs
 * to a different tracking row. Reporting `INV-007` as "selection logic missing" would understate it
 * by a whole entity.
 *
 * -------------------------------------------------------------------------------------------------
 * NOTHING IS BUILT HERE, AND THAT IS A DISPOSITION RATHER THAN A SHORTFALL.
 *
 * Multi-warehouse stock, a reservation ledger and a stocktake/transfer/adjustment workflow are
 * each a subsystem, and three of them at once is the "parallel commerce engine" `EX-002` forbids
 * building without a decision. They also change stock arithmetic on existing data, which CLAUDE.md
 * §2 puts in the escalate-to-owner set. What this file delivers instead is the exact shape of each
 * gap, asserted, so the owner's decision is about a measured scope rather than an impression -- and
 * so that the first commit that starts one of them fails here and has to update the disposition.
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
     * `INV-002`/`INV-003`/`INV-007`/`INV-008`: the complete schema hit set is the declared four,
     * and no table at all.
     *
     * Both directions, so a stale declaration fails too.
     */
    public function test_the_schema_knows_nothing_of_reservations_warehouses_or_shipments(): void
    {
        $tables = [];
        $columns = [];

        foreach ($this->migrationFiles() as $file) {
            $code = php_strip_whitespace($file);

            if (preg_match_all("/Schema::(?:create|table)\(\s*'([^']+)'/", $code, $matches) > 0) {
                foreach ($matches[1] as $table) {
                    if (preg_match(self::CANDIDATE, $table) === 1) {
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
            "A table named for a reservation, a warehouse, a shipment, a stocktake, a transfer or "
            . "an adjustment now exists. One of INV-002/003/007/008 has started and its "
            . "disposition must be updated:\n" . json_encode($tables, JSON_PRETTY_PRINT)
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
