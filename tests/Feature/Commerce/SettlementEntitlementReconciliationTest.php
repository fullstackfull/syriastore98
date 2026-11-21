<?php

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Support\Security\SettlementEntitlementReconciliation;
use Tests\TestCase;

/**
 * P4-F128 -- does the settlement-to-entitlement reconciliation actually bite?
 *
 * A reconciliation report is the easiest kind of code to get wrong invisibly: one that never fires
 * looks exactly like clean data, and one that always fires looks exactly like a broken system. So
 * every leg here is driven in BOTH directions -- a case that must be reported, and a clean case that
 * must not be -- and the exemptions are tested as hard as the detections, because an exemption that
 * is too wide is how a real divergence gets hidden.
 *
 * The money tables come from the REAL migrations via RefreshDatabase. Hand-writing them would both
 * break the GATE F containment guard and defeat the point: this class is tested against production's
 * structure or it is tested against nothing.
 */
class SettlementEntitlementReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function reporter(): SettlementEntitlementReconciliation
    {
        return new SettlementEntitlementReconciliation(DB::connection());
    }

    /** @return array<string, array<int, array<string, mixed>>> codes present, keyed by code */
    private function codes(): array
    {
        $out = [];
        foreach ($this->reporter()->items() as $item) {
            $out[$item['code']] = $item['detail'];
        }

        return $out;
    }

    /**
     * The GATE F money tables carry production's FOREIGN KEYs, because they come from the real
     * migrations rather than a blueprint typed here. So a wallet needs a store that exists. That is
     * the constraint doing its job, not an obstacle -- a fixture that could orphan a wallet would be
     * a fixture this reporter's leg 1 could never be tested against.
     */
    private function makeStore(int $storeId): void
    {
        if (DB::table('stores')->where('id', $storeId)->exists()) {
            return;
        }

        // stores.created_by is a FOREIGN KEY to users, so the owner must exist first. The required
        // set, enumerated from the migrated schema rather than discovered one error at a time, is
        // name, email, theme_id, slug, created_by.
        $ownerId = \App\Models\User::factory()->create([
            'email' => 'recon-owner-' . $storeId . '@example.test',
            'type' => 'admin',
        ])->id;

        DB::table('stores')->insert([
            'id' => $storeId,
            'name' => 'Recon store ' . $storeId,
            'slug' => 'recon-store-' . $storeId,
            'email' => 'recon-store-' . $storeId . '@example.test',
            'theme_id' => 'stylique',
            'created_by' => $ownerId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeWallet(int $storeId, float $balance = 0.0, string $currency = 'USD'): int
    {
        $this->makeStore($storeId);

        return DB::table('store_wallets')->insertGetId([
            'store_id' => $storeId,
            'currency_code' => $currency,
            'available_balance' => $balance,
            'pending_balance' => 0,
            'withdrawal_pending_balance' => 0,
            'total_income' => 0,
            'total_withdrawn' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeOrder(int $storeId, string $settlement): int
    {
        $this->makeStore($storeId);

        return DB::table('orders')->insertGetId([
            'product_order_id' => 'ORD-' . uniqid(),
            'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 100.0,
            'payment_type' => 'cod', 'payment_status' => $settlement,
            'store_id' => $storeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeFinancial(
        int $storeId,
        int $walletId,
        int $orderId,
        string $entitlement,
        float $paid = 0.0,
        float $total = 100.0,
        string $currency = 'USD'
    ): int {
        return DB::table('store_order_financials')->insertGetId([
            'store_id' => $storeId, 'wallet_id' => $walletId, 'order_id' => $orderId,
            'currency_code' => $currency, 'currency_symbol' => '$',
            'product_amount' => $total, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0,
            'customer_total' => $total, 'product_cost' => 0, 'gross_profit' => 0,
            'payment_status' => $entitlement,
            'paid_amount' => $paid, 'remaining_amount' => $total - $paid,
            'paid_at' => $entitlement === 'paid' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeLedgerRow(
        int $storeId,
        int $walletId,
        int $orderId,
        string $direction,
        float $amount,
        float $before = 0.0,
        ?float $after = null,
        string $currency = 'USD',
        string $type = 'order_paid'
    ): void {
        $after ??= $direction === 'credit' ? $before + $amount : $before - $amount;
        DB::table('store_wallet_transactions')->insert([
            'store_id' => $storeId, 'wallet_id' => $walletId,
            'currency_code' => $currency, 'type' => $type, 'direction' => $direction,
            'amount' => $amount, 'balance_before' => $before, 'balance_after' => $after,
            'reference_type' => 'order', 'reference_id' => $orderId,
            'description' => 'test', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A store whose settlement and entitlement agree in every leg. */
    private function cleanStore(int $storeId = 1): array
    {
        $wallet = $this->makeWallet($storeId, 100.0);
        $order = $this->makeOrder($storeId, 'Paid');
        $financial = $this->makeFinancial($storeId, $wallet, $order, 'paid', 100.0);
        $this->makeLedgerRow($storeId, $wallet, $order, 'credit', 100.0, 0.0, 100.0);

        return compact('wallet', 'order', 'financial');
    }

    // ---------------------------------------------------------------- the negative control

    public function test_a_consistent_store_reports_nothing(): void
    {
        $this->cleanStore();

        $this->assertSame(
            [],
            $this->reporter()->items(),
            'The reporter fired on data that reconciles. A report that always fires is worth nothing, '
            . 'so this case guards every positive control below.'
        );
    }

    public function test_an_empty_database_reports_nothing(): void
    {
        $this->assertSame([], $this->reporter()->items());
    }

    // ---------------------------------------------------------------- leg 2, the heart of F128

    public function test_the_finance_dropdown_divergence_is_reported(): void
    {
        // Exactly what StoreFinanceController::updateFinancialPaymentStatus does: move the financial
        // row to paid and credit the wallet, while orders.payment_status stays Unpaid.
        $storeId = 1;
        $wallet = $this->makeWallet($storeId, 100.0);
        $order = $this->makeOrder($storeId, 'Unpaid');
        $this->makeFinancial($storeId, $wallet, $order, 'paid', 100.0);
        $this->makeLedgerRow($storeId, $wallet, $order, 'credit', 100.0, 0.0, 100.0);

        $codes = $this->codes();

        $this->assertArrayHasKey('D1', $codes, 'The finance dropdown divergence was not reported.');
        $this->assertSame(1, $codes['D1']['total']);
        $this->assertSame('paid', $codes['D1']['sample'][0]['entitlement_state']);
        $this->assertSame('unpaid', $codes['D1']['sample'][0]['settlement_normalised']);
    }

    public function test_settlement_synonyms_are_normalised_before_comparison(): void
    {
        // 'success' normalises to 'paid', so this agrees and must NOT be reported. A reporter that
        // compared the raw strings would fire here -- which is the false positive that makes a
        // reconciliation unusable.
        $storeId = 1;
        $wallet = $this->makeWallet($storeId, 100.0);
        $order = $this->makeOrder($storeId, 'success');
        $this->makeFinancial($storeId, $wallet, $order, 'paid', 100.0);
        $this->makeLedgerRow($storeId, $wallet, $order, 'credit', 100.0, 0.0, 100.0);

        $this->assertArrayNotHasKey('D1', $this->codes());
    }

    /** @dataProvider terminalEntitlementStates */
    public function test_a_terminal_entitlement_state_exempts_settlement(string $terminal): void
    {
        // P4-F124: a cancelled or refunded order KEEPS the customer's settlement. Reporting that as a
        // divergence would train the reader to ignore the report.
        $storeId = 1;
        $wallet = $this->makeWallet($storeId, 0.0);
        $order = $this->makeOrder($storeId, 'Paid');
        $this->makeFinancial($storeId, $wallet, $order, $terminal, 100.0);
        $this->makeLedgerRow($storeId, $wallet, $order, 'credit', 100.0, 0.0, 100.0);
        $this->makeLedgerRow($storeId, $wallet, $order, 'debit', 100.0, 100.0, 0.0, 'USD', 'order_' . $terminal);

        $codes = $this->codes();

        $this->assertArrayNotHasKey(
            'D1',
            $codes,
            "A $terminal order kept its customer settlement, which is the F124 decision, and the "
            . 'reporter flagged it anyway.'
        );
    }

    public static function terminalEntitlementStates(): array
    {
        return ['cancelled' => ['cancelled'], 'refunded' => ['refunded']];
    }

    // ---------------------------------------------------------------- leg 1, control failure

    public function test_a_financial_row_pointing_at_another_stores_wallet_is_reported(): void
    {
        $walletA = $this->makeWallet(1, 0.0);
        $walletB = $this->makeWallet(2, 0.0);
        $order = $this->makeOrder(1, 'Unpaid');
        $this->makeFinancial(1, $walletB, $order, 'unpaid');   // store 1's order, store 2's wallet

        $codes = $this->codes();

        $this->assertArrayHasKey('D4', $codes, 'A cross-tenant wallet reference was not reported.');
        $this->assertSame(1, $codes['D4']['total']);
        $this->assertSame(2, (int) $codes['D4']['sample'][0]['wallet_store_id']);
        $this->assertSame(1, (int) $codes['D4']['sample'][0]['financial_store_id']);
    }

    public function test_an_order_with_no_store_is_reported(): void
    {
        // Order is NOT in scoped_models, so nothing guarantees it carries a store.
        $wallet = $this->makeWallet(1, 0.0);
        $order = DB::table('orders')->insertGetId([
            'product_order_id' => 'ORD-NOSTORE', 'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 100.0,
            'payment_type' => 'cod', 'payment_status' => 'Unpaid', 'store_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->makeFinancial(1, $wallet, $order, 'unpaid');

        $this->assertArrayHasKey('D4', $this->codes());
    }

    // ---------------------------------------------------------------- leg 3, amounts

    public function test_a_paid_financial_row_with_no_ledger_credit_is_reported_as_an_amount_divergence(): void
    {
        $storeId = 1;
        $wallet = $this->makeWallet($storeId, 0.0);
        $order = $this->makeOrder($storeId, 'Paid');
        $this->makeFinancial($storeId, $wallet, $order, 'paid', 100.0);
        // no ledger row at all -- entitlement claims 100 paid, the ledger says 0

        $codes = $this->codes();

        $this->assertArrayHasKey('D2', $codes);
        $this->assertSame(1, $codes['D2']['buckets']['amount_divergence']);
        $this->assertSame(0, $codes['D2']['buckets']['not_comparable']);
        $this->assertSame(0, $codes['D2']['buckets']['control_failure']);
    }

    public function test_a_mixed_currency_order_is_bucketed_as_not_comparable_rather_than_divergent(): void
    {
        $storeId = 1;
        $wallet = $this->makeWallet($storeId, 100.0, 'USD');
        $order = $this->makeOrder($storeId, 'Paid');
        $this->makeFinancial($storeId, $wallet, $order, 'paid', 100.0, 100.0, 'USD');
        $this->makeLedgerRow($storeId, $wallet, $order, 'credit', 60.0, 0.0, 60.0, 'USD');
        $this->makeLedgerRow($storeId, $wallet, $order, 'credit', 40.0, 60.0, 100.0, 'EUR');

        $codes = $this->codes();

        $this->assertArrayHasKey('D2', $codes);
        $this->assertSame(
            1,
            $codes['D2']['buckets']['not_comparable'],
            'A cross-currency order was reported as an amount divergence. Summing across currencies '
            . 'invents a discrepancy that is really a conversion.'
        );
        $this->assertSame(0, $codes['D2']['buckets']['amount_divergence']);
    }

    public function test_an_internally_inconsistent_financial_row_is_reported(): void
    {
        $storeId = 1;
        $wallet = $this->makeWallet($storeId, 0.0);
        $order = $this->makeOrder($storeId, 'Unpaid');
        DB::table('store_order_financials')->insert([
            'store_id' => $storeId, 'wallet_id' => $wallet, 'order_id' => $order,
            'currency_code' => 'USD', 'currency_symbol' => '$',
            'product_amount' => 100, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0,
            'customer_total' => 100, 'product_cost' => 0, 'gross_profit' => 0,
            'payment_status' => 'unpaid',
            'paid_amount' => 30.0, 'remaining_amount' => 50.0,   // 30 + 50 != 100
            'paid_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertArrayHasKey('D3', $this->codes());
    }

    public function test_a_reversed_row_keeping_paid_at_is_not_reported(): void
    {
        // OrderEntitlementReversal PRESERVES paid_at by design. Asserting "unpaid implies no paid_at"
        // would make every correctly-reversed order a false positive.
        $storeId = 1;
        $wallet = $this->makeWallet($storeId, 0.0);
        $order = $this->makeOrder($storeId, 'Paid');
        DB::table('store_order_financials')->insert([
            'store_id' => $storeId, 'wallet_id' => $wallet, 'order_id' => $order,
            'currency_code' => 'USD', 'currency_symbol' => '$',
            'product_amount' => 100, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0,
            'customer_total' => 100, 'product_cost' => 0, 'gross_profit' => 0,
            'payment_status' => 'refunded',
            'paid_amount' => 100.0, 'remaining_amount' => 0.0,
            'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->makeLedgerRow($storeId, $wallet, $order, 'credit', 100.0, 0.0, 100.0);
        $this->makeLedgerRow($storeId, $wallet, $order, 'debit', 100.0, 100.0, 0.0, 'USD', 'order_refunded');

        $codes = $this->codes();

        $this->assertArrayNotHasKey('D3', $codes, 'A correctly reversed row was reported as inconsistent.');
        $this->assertArrayNotHasKey('D1', $codes);
    }

    // ---------------------------------------------------------------- leg 4, the correction

    public function test_a_withdrawal_paid_row_does_not_create_a_phantom_balance_divergence(): void
    {
        // THE CORRECTION. withdrawal_paid carries balance_after == balance_before by design, because
        // the balance was already reserved at request time. A reconstruction that summed it would
        // debit the wallet twice and report every paid withdrawal as drift.
        $storeId = 1;
        $wallet = $this->makeWallet($storeId, 70.0);
        $order = $this->makeOrder($storeId, 'Paid');
        $this->makeFinancial($storeId, $wallet, $order, 'paid', 100.0);
        $this->makeLedgerRow($storeId, $wallet, $order, 'credit', 100.0, 0.0, 100.0);

        DB::table('store_wallet_transactions')->insert([
            'store_id' => $storeId, 'wallet_id' => $wallet, 'currency_code' => 'USD',
            'type' => 'withdrawal_request', 'direction' => 'debit', 'amount' => 30.0,
            'balance_before' => 100.0, 'balance_after' => 70.0,
            'reference_type' => 'withdrawal', 'reference_id' => 1,
            'description' => 'requested', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_wallet_transactions')->insert([
            'store_id' => $storeId, 'wallet_id' => $wallet, 'currency_code' => 'USD',
            'type' => 'withdrawal_paid', 'direction' => 'debit', 'amount' => 30.0,
            'balance_before' => 70.0, 'balance_after' => 70.0,     // did NOT move: already reserved
            'reference_type' => 'withdrawal', 'reference_id' => 1,
            'description' => 'paid', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertArrayNotHasKey(
            'D5',
            $this->codes(),
            'The paid-withdrawal row was counted a second time. This is the exact false positive the '
            . 'existing R1 / Q-F032-5 checks produce, and the reason leg 4 restricts to moving rows.'
        );
    }

    public function test_a_real_balance_drift_is_still_reported(): void
    {
        // The guard above must not have blinded leg 4 entirely.
        $storeId = 1;
        $wallet = $this->makeWallet($storeId, 999.0);           // nothing in the ledger explains this
        $order = $this->makeOrder($storeId, 'Paid');
        $this->makeFinancial($storeId, $wallet, $order, 'paid', 100.0);
        $this->makeLedgerRow($storeId, $wallet, $order, 'credit', 100.0, 0.0, 100.0);

        $codes = $this->codes();

        $this->assertArrayHasKey('D5', $codes, 'A real balance drift went unreported.');
        $this->assertEqualsWithDelta(999.0, (float) $codes['D5']['sample'][0]['available_balance'], 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $codes['D5']['sample'][0]['moving_rows_net'], 0.001);
    }

    // ---------------------------------------------------------------- the two halves cannot drift

    public function test_the_sql_normalisation_matches_the_php(): void
    {
        // The reporter reimplements OrderFinancialService::normalizePaymentStatus in SQL. If that
        // method gains an arm and the SQL does not, every order in the new state silently stops being
        // comparable. This holds the two together.
        $php = new ReflectionMethod(\App\Services\OrderFinancialService::class, 'normalizePaymentStatus');
        $php->setAccessible(true);
        $service = app(\App\Services\OrderFinancialService::class);

        $sql = (new ReflectionMethod(SettlementEntitlementReconciliation::class, 'normalisedSettlementSql'));
        $sql->setAccessible(true);
        $expression = $sql->invoke($this->reporter(), '?');

        // The expression repeats its argument once per WHEN arm, so a single binding would leave
        // four placeholders unfilled and every input would fall through to ELSE. Caught by this
        // very test on its first run, which is the argument for having written it.
        $placeholders = substr_count($expression, '?');

        $inputs = [
            'paid', 'success', 'succeeded', 'completed',
            'pending', 'processing',
            'partial', 'partially_paid',
            'refund', 'refunded',
            'cancel', 'cancelled', 'canceled',
            'unpaid', 'Paid', ' PAID ', '', 'something-else',
        ];

        foreach ($inputs as $input) {
            $fromPhp = $php->invoke($service, $input);
            $fromSql = DB::selectOne("SELECT $expression AS v", array_fill(0, $placeholders, $input))->v;

            $this->assertSame(
                $fromPhp,
                $fromSql,
                "The SQL normalisation and OrderFinancialService::normalizePaymentStatus disagree on "
                . var_export($input, true) . '. They must be changed together.'
            );
        }
    }

    public function test_the_reporter_performs_no_write(): void
    {
        // The safety model is the absence of a write statement. This asserts it rather than trusting
        // a reading of the file.
        $this->cleanStore();

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $this->reporter()->items();

        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression(
                '/^\s*(select|pragma)/i',
                $sql,
                "The reconciliation issued a non-SELECT statement: $sql"
            );
        }
        $this->assertNotEmpty($statements, 'No statement was observed, so this assertion proved nothing.');
    }

    /*
     * THE ORPHAN LIMB IS NOT TESTED HERE, AND THAT IS DELIBERATE RATHER THAN AN OMISSION.
     *
     * Leg 1 gained `o.id IS NULL` (and a LEFT JOIN) after independent review pointed out that an
     * INNER JOIN silently drops a financial row whose `order_id` matches no order -- the most
     * complete form of the failure the leg looks for.
     *
     * MEASURED WHILE FIXING IT, both facts:
     *   1. This schema CANNOT reach that state. Deleting an order CASCADES and takes the financial
     *      row with it, so with foreign keys enforced an orphan is impossible. The limb is
     *      DEFENSIVE -- against a RESTORED COPY that lost referential integrity, which is exactly
     *      the kind of database this reporter is pointed at.
     *   2. The suite cannot construct it either. SQLite's `PRAGMA foreign_keys` is a NO-OP inside a
     *      transaction, and RefreshDatabase wraps every test in one, so switching enforcement off
     *      here does nothing -- measured, the cascade still fired.
     *
     * A conditionally-skipped test would break the declared known-outcome set, so per CLAUDE.md the
     * evidence lives in an executable script that states its own preconditions:
     * `tests/Support/Security/bin/verify-orphaned-entitlement-is-reported.php`
     */
}
