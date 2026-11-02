<?php

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Security\GateFReconciliation;

/**
 * THE GATE F RECONCILIATION REPORT, PROVEN IN BOTH DIRECTIONS.
 * =================================================================================================
 *
 * Owner direction, 2026-10-24: "Prepare and test any safe reconciliation logic against isolated
 * copies. Do not activate unverified balances or payouts."
 *
 * WHY BOTH DIRECTIONS, AND WHY THAT IS THE WHOLE POINT. A reconciliation report that always says
 * "discrepancy" and one that always says "all clear" produce indistinguishable output without a
 * control. The production restore it was written against had all three items present, so running it
 * there proves only that it can say yes. The CLEAN fixture is what proves it can say no, and
 * therefore that the three items it reports on real data are findings rather than its own behaviour.
 *
 * WHY THE FIXTURE BUILDS ITS OWN TABLES. The six money-bearing tables have NO MIGRATION -- that is
 * the GATE F gap itself -- so `RefreshDatabase` does not create them and the reconciliation would
 * silently report nothing. The tables here are a FIXTURE shaped to the columns the reconciliation
 * reads; they are not a second declaration of the production schema, which is verified separately
 * against a restored copy in `docs/GATE-F-VERIFIED-SCHEMA.md` and reproduced by the parked drafts.
 * Keeping them minimal is deliberate: a fixture that tried to mirror production would drift from it.
 *
 * WHAT IS NOT TESTED HERE, said plainly: that the report's figures match production's. They were
 * measured once, on the restored copy, and recorded in `docs/GATE-F-RECONCILIATION-ITEMS.md`; the
 * copy has since been destroyed and nothing in this suite can re-derive them. What IS tested is that
 * the LOGIC classifies each shape correctly, which is what makes those recorded figures trustworthy.
 */
class GateFReconciliationReportTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createGateFFixtureTables();
    }

    /**
     * The columns the reconciliation reads, and nothing more. Money is `decimal(15,2)` because that
     * is what production measured; a wider fixture column would let a rounding defect hide.
     */
    private function createGateFFixtureTables(): void
    {
        Schema::create('store_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('currency_code', 10)->nullable();
            $table->decimal('available_balance', 15, 2)->default(0);
        });

        Schema::create('store_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('wallet_id');
            $table->string('currency_code', 10)->nullable();
            $table->string('type', 50);
            $table->string('direction', 10);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('balance_before', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
        });

        /*
         * `products.cost_price` NOW HAS A MIGRATION, and this comment used to say the opposite.
         * It was GATE F draft 0004, and the first run of this file failed on exactly that ("table
         * products has no column named cost_price"), so the fixture created the column itself --
         * the same decision as the three tables above. F58 promoted the draft
         * (2026_10_26_000100_add_cost_price_to_products_table), because `ProductController::store()`
         * validates `cost_price` as required and writes it unconditionally, so CREATING A PRODUCT
         * WAS IMPOSSIBLE on any database built from this repository.
         *
         * The unguarded `Schema::table()` then collided with it -- "duplicate column name:
         * cost_price" -- and took all TEN tests in this file down. That is what the
         * known-outcome gate caught, and it is the second time in this phase that promoting a
         * schema draft broke a fixture which had been standing in for the missing schema.
         *
         * The guard stays rather than the block being deleted, so this file still runs against a
         * tree without that migration; `GateFReconciliation` itself already asks
         * `hasColumn('products', 'cost_price')` (tests/Support/Security/GateFReconciliation.php:270)
         * and this keeps the fixture honest for both trees.
         */
        if (! Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('cost_price', 15, 2)->default(0);
            });
        }

        Schema::create('store_order_financials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('order_id');
            $table->decimal('product_cost', 15, 2)->default(0);
            $table->decimal('gross_profit', 15, 2)->default(0);
        });
    }

    private function reconciliation(): GateFReconciliation
    {
        return new GateFReconciliation(DB::connection());
    }

    /** @return array<string, array<string, mixed>> keyed by item code */
    private function itemsByCode(): array
    {
        $out = [];
        foreach ($this->reconciliation()->items() as $item) {
            $out[$item['code']] = $item['detail'];
        }

        return $out;
    }

    private function wallet(int $storeId, string $currency, float $balance): int
    {
        return (int) DB::table('store_wallets')->insertGetId([
            'store_id' => $storeId, 'currency_code' => $currency, 'available_balance' => $balance,
        ]);
    }

    private function txn(int $walletId, int $storeId, string $currency, string $direction, float $amount, float $before): void
    {
        $after = $direction === 'debit' ? $before - $amount : $before + $amount;

        DB::table('store_wallet_transactions')->insert([
            'store_id' => $storeId, 'wallet_id' => $walletId, 'currency_code' => $currency,
            'type' => 'order_paid', 'direction' => $direction, 'amount' => $amount,
            'balance_before' => $before, 'balance_after' => $after,
        ]);
    }

    private function productWithCost($store, float $cost): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'p-' . uniqid(), 'slug' => 'p-' . uniqid(),
            'price' => 100.00, 'sale_price' => 100.00, 'cost_price' => $cost,
            'product_stock' => 10, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function codOrder($store, string $paymentStatus, float $total, int $productId, int $qty): int
    {
        return (int) $this->makeOrder($store, [
            'payment_type' => 'cod',
            'payment_status' => $paymentStatus,
            'final_price' => $total,
            'delivered_status' => 0,
            'product_json' => json_encode([[
                'product_id' => $productId, 'variant_id' => 0, 'qty' => $qty,
                'final_price' => $total, 'return' => 0,
            ]]),
        ])->id;
    }

    // --------------------------------------------------------------------- the negative control

    /**
     * A STORE WHOSE DATA RECONCILES PRODUCES NO ITEMS AT ALL.
     *
     * Single currency, an unbroken balance chain summing to the stored balance, no COD order
     * recorded as paid, and a P&L row carrying a real product cost.
     */
    public function test_a_clean_store_reports_nothing(): void
    {
        [, $store] = $this->makeTenant();

        $walletId = $this->wallet($store->id, 'USD', 150.00);
        $this->txn($walletId, $store->id, 'USD', 'credit', 100.00, 0.00);
        $this->txn($walletId, $store->id, 'USD', 'credit', 80.00, 100.00);
        $this->txn($walletId, $store->id, 'USD', 'debit', 30.00, 180.00);

        $productId = $this->productWithCost($store, 40.00);
        $orderId = $this->codOrder($store, 'Unpaid', 100.00, $productId, 1);

        DB::table('store_order_financials')->insert([
            'store_id' => $store->id, 'order_id' => $orderId,
            'product_cost' => 40.00, 'gross_profit' => 60.00,
        ]);

        $this->assertSame([], $this->reconciliation()->items(),
            'THE REPORT CANNOT SAY "NO". Every other assertion in this file is then meaningless, '
            . 'because a reporter that always finds something finds something on real data too.');
    }

    // ------------------------------------------------------------------------- R1, the wallet

    /** The divergence, the currency mixing and the broken chain are reported separately. */
    public function test_r1_reports_divergence_currency_mixing_and_chain_breaks(): void
    {
        [, $store] = $this->makeTenant();

        // Stored balance says 100.00. The ledger says something else, in three currencies, and the
        // chain jumps between rows 2 and 3 -- production's exact shape in miniature.
        $walletId = $this->wallet($store->id, 'USD', 100.00);
        $this->txn($walletId, $store->id, 'SYP', 'credit', 5000.00, 0.00);
        $this->txn($walletId, $store->id, 'USD', 'credit', 50.00, 5000.00);
        $this->txn($walletId, $store->id, 'BYR', 'credit', 10.00, 999999.00);   // chain break

        $items = $this->itemsByCode();

        $this->assertArrayHasKey('R1', $items, 'the wallet divergence is not reported');

        $wallet = $items['R1']['wallets'][0];

        $this->assertSame(100.00, $wallet['stored_balance']);
        $this->assertSame(5060.00, $wallet['ledger_net'], 'the ledger net is not the signed sum');
        $this->assertSame(4960.00, $wallet['divergence']);
        $this->assertSame(3, count($wallet['currencies']),
            'three currencies summed into one balance column must be reported as such');
        $this->assertSame(1, $wallet['chain_breaks'],
            'balance_before 999999.00 does not follow balance_after 5050.00');
        $this->assertSame(0, $wallet['row_level_arithmetic_violations'],
            'every row is internally correct -- that is what makes the chain break the finding');
    }

    /**
     * AND THE ARITHMETIC CHECK KEYS ON `direction`, NOT ON `type`.
     *
     * A first version of this logic keyed on `type` -- which holds `order_paid`, `manual_deposit`
     * and friends -- and reported 60 arithmetic violations in production where there are none. The
     * wrong column produces a confident, precise, wrong number, so the right one is pinned here.
     */
    public function test_a_debit_is_recognised_by_direction_not_by_type(): void
    {
        [, $store] = $this->makeTenant();

        $walletId = $this->wallet($store->id, 'USD', 70.00);
        $this->txn($walletId, $store->id, 'USD', 'credit', 100.00, 0.00);
        $this->txn($walletId, $store->id, 'USD', 'debit', 30.00, 100.00);

        $this->assertSame([], $this->reconciliation()->items(),
            'a debit was summed as a credit: 100 - 30 = 70 reconciles, and only a reader that '
            . 'ignores `direction` sees a discrepancy here');
    }

    // ---------------------------------------------------------------------------- R2, the COD

    public function test_r2_reports_cod_orders_paid_without_a_collection_event(): void
    {
        [, $store] = $this->makeTenant();

        $productId = $this->productWithCost($store, 10.00);
        $this->codOrder($store, 'Paid', 500.00, $productId, 1);
        $this->codOrder($store, 'Paid', 250.00, $productId, 1);
        $this->codOrder($store, 'Unpaid', 900.00, $productId, 1);   // not an item

        $items = $this->itemsByCode();

        $this->assertArrayHasKey('R2', $items);
        $this->assertSame(2, $items['R2']['orders'], 'only the PAID cod orders are items');
        $this->assertSame(750.00, $items['R2']['total_recorded_as_collected']);
        $this->assertFalse($items['R2']['cod_collection_events_table_exists'],
            'the absence of the table is itself reported -- with no table no event CAN exist, and '
            . 'that must not read as a passing check');
    }

    /** A COD order WITH an event naming it is evidenced and is not an item. */
    public function test_an_evidenced_cod_order_is_not_an_item(): void
    {
        [, $store] = $this->makeTenant();

        Schema::create('cod_collection_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('amount', 15, 2);
        });

        $productId = $this->productWithCost($store, 10.00);
        $evidenced = $this->codOrder($store, 'Paid', 500.00, $productId, 1);
        $bare = $this->codOrder($store, 'Paid', 250.00, $productId, 1);

        DB::table('cod_collection_events')->insert(['order_id' => $evidenced, 'amount' => 500.00]);

        $items = $this->itemsByCode();

        $this->assertSame(1, $items['R2']['orders'] ?? 0,
            'the evidenced order is still counted, so the event is not being consulted');
        $this->assertSame([$bare], $items['R2']['order_ids'],
            'the wrong order is named');
    }

    // ------------------------------------------------------------------------- R3, the margins

    /**
     * THE CHECK THAT DISTINGUISHES THE TWO CAUSES, which is the reason R3 is worth computing at all.
     *
     * A zero `product_cost` is either a STALE row (the cost existed later -- a migration-ordering
     * problem) or a cost that was NEVER ENTERED. Recomputing against today's `cost_price` tells them
     * apart. Production answered unambiguously: 0 of 89 would change.
     */
    public function test_r3_separates_unentered_costs_from_stale_rows(): void
    {
        [, $store] = $this->makeTenant();

        $neverEntered = $this->productWithCost($store, 0.00);
        $hasCostNow = $this->productWithCost($store, 25.00);

        $orderA = $this->codOrder($store, 'Unpaid', 100.00, $neverEntered, 2);
        $orderB = $this->codOrder($store, 'Unpaid', 100.00, $hasCostNow, 2);

        foreach ([$orderA, $orderB] as $orderId) {
            DB::table('store_order_financials')->insert([
                'store_id' => $store->id, 'order_id' => $orderId,
                'product_cost' => 0.00, 'gross_profit' => 100.00,
            ]);
        }

        $items = $this->itemsByCode();

        $this->assertArrayHasKey('R3', $items);
        $this->assertSame(2, $items['R3']['with_zero_product_cost']);
        $this->assertSame(1, $items['R3']['would_change_if_recomputed_today'],
            'only the row whose product HAS a cost today is stale; the other cost was never entered');
        $this->assertSame(50.00, $items['R3']['cost_omitted_by_those_rows'],
            '25.00 x 2 units is the cost that row should have carried');
        $this->assertStringContainsString('stale', $items['R3']['cause']);
    }

    /** With every cost entered there is nothing to report, which is the per-item control. */
    public function test_r3_is_silent_when_costs_are_recorded(): void
    {
        [, $store] = $this->makeTenant();

        $productId = $this->productWithCost($store, 30.00);
        $orderId = $this->codOrder($store, 'Unpaid', 100.00, $productId, 1);

        DB::table('store_order_financials')->insert([
            'store_id' => $store->id, 'order_id' => $orderId,
            'product_cost' => 30.00, 'gross_profit' => 70.00,
        ]);

        $this->assertArrayNotHasKey('R3', $this->itemsByCode());
    }

    // ---------------------------------------------------------------------------- the whole set

    /** All three shapes at once, which is the production restore in miniature. */
    public function test_all_three_items_are_reported_together(): void
    {
        [, $store] = $this->makeTenant();

        $walletId = $this->wallet($store->id, 'USD', 10.00);
        $this->txn($walletId, $store->id, 'SYP', 'credit', 9999.00, 0.00);

        $productId = $this->productWithCost($store, 0.00);
        $paid = $this->codOrder($store, 'Paid', 400.00, $productId, 1);

        DB::table('store_order_financials')->insert([
            'store_id' => $store->id, 'order_id' => $paid,
            'product_cost' => 0.00, 'gross_profit' => 400.00,
        ]);

        $codes = array_keys($this->itemsByCode());

        sort($codes);
        $this->assertSame(['R1', 'R2', 'R3'], $codes);
    }

    /**
     * THE READ-ONLY PROPERTY IS ENFORCED AT THE SOURCE, not only observed at runtime.
     *
     * `test_the_report_changes_nothing` below is a behavioural check and only covers the paths this
     * fixture drives. This one reads the two files with PHP's own tokenizer and asserts that no
     * write-shaped method name appears as code at all -- so a write added to a branch no fixture
     * reaches still fails. Comments are not tokens of type T_STRING, which is why the docblocks
     * that contain the words "INSERT, UPDATE or DELETE" do not satisfy or break it; a `grep` would
     * have been satisfied by exactly that prose, which is this project's recorded ratchet failure.
     */
    public function test_the_reconciliation_contains_no_write_statement(): void
    {
        $files = [
            base_path('tests/Support/Security/GateFReconciliation.php'),
            base_path('tests/Support/Security/bin/report-gatef-reconciliation.php'),
        ];

        $forbidden = ['insert', 'insertgetid', 'update', 'updateorinsert', 'delete', 'truncate',
                      'save', 'upsert', 'increment', 'decrement', 'unprepared', 'statement'];

        foreach ($files as $file) {
            $this->assertFileExists($file);

            $found = [];

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (! is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                if (in_array(strtolower($token[1]), $forbidden, true)) {
                    $found[] = $token[2] . ':' . $token[1];
                }
            }

            $this->assertSame([], $found,
                basename($file) . ' contains a write-shaped call: ' . implode(', ', $found)
                . '. The reconciliation must resolve nothing -- no corrected balance, no fabricated '
                . 'collection event, no back-filled cost, no payout.');
        }
    }

    /** AND NOTHING IS WRITTEN. The safety model is the absence of a write, so it is asserted. */
    public function test_the_report_changes_nothing(): void
    {
        [, $store] = $this->makeTenant();

        $walletId = $this->wallet($store->id, 'USD', 10.00);
        $this->txn($walletId, $store->id, 'SYP', 'credit', 9999.00, 0.00);
        $productId = $this->productWithCost($store, 0.00);
        $orderId = $this->codOrder($store, 'Paid', 400.00, $productId, 1);
        DB::table('store_order_financials')->insert([
            'store_id' => $store->id, 'order_id' => $orderId,
            'product_cost' => 0.00, 'gross_profit' => 400.00,
        ]);

        $tables = [
            'store_wallets', 'store_wallet_transactions',
            'store_order_financials', 'orders', 'products',
        ];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        $this->reconciliation()->items();

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(),
                "the reconciliation report MODIFIED {$table}. It must resolve nothing: no corrected "
                . 'balance, no collection event, no filled cost, no payout.');
        }
    }
}
