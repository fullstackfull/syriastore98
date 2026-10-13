<?php

namespace Tests\Feature\Commerce;

use App\Services\OrderFinancialService;
use App\Services\StoreCurrencyConversionService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WHAT OF THE FINANCIAL SURFACE IS VERIFIED, AND WHAT IS BLOCKED BY GATE F.
 *
 * The owner asked explicitly for the list of financial functions that GATE F
 * leaves unverified. This suite IS that list, in executable form: the parts that
 * can be verified without the six undeclared money tables are verified here, and
 * the parts that cannot are SKIPPED with the blocking table named. A skipped test
 * is not a passing test and is not reported as one.
 *
 * GATE F, restated so this file stands on its own. Six tables are read and
 * written by live code and created by NO migration:
 *
 *   store_wallets, store_wallet_transactions, store_withdrawals,
 *   store_order_financials, currency_rates, delivery_earnings
 *
 * Their schema is not recoverable in this environment, and the models'
 * $fillable/$casts give column NAMES only -- never decimal precision,
 * nullability, indexes or foreign keys. For money columns the precision is
 * exactly the thing that must not be guessed, so no migration is invented and
 * no test is made to pass by inventing one.
 *
 * THE FUNCTIONS THIS LEAVES UNVERIFIED, by file:line:
 *
 *   App\Services\OrderFinancialService
 *     :17  createForOrder()        -- writes store_order_financials (:90),
 *                                    reads store_wallets (:21), writes
 *                                    store_wallet_transactions (:156). This is
 *                                    the function that CREDITS A MERCHANT'S
 *                                    WALLET for an order. Entirely unverifiable.
 *     :226 syncFromOrder()         -- reads (:228) and updates (:272)
 *                                    store_order_financials.
 *     :284 markFinancialAsPaid()   -- updates store_order_financials (:350),
 *                                    reads store_wallets (:303), writes
 *                                    store_wallet_transactions (:364).
 *     :397 markFinancialAsUnpaid() -- updates store_order_financials (:519),
 *                                    reads store_wallets (:416), reads
 *                                    store_wallet_transactions (:427), writes
 *                                    one (:537). This is the REVERSAL path, and
 *                                    it is the one a cancelled COD order runs.
 *
 *   App\Services\StoreCurrencyConversionService
 *     :49  getRate()               -- reads currency_rates (:57). Verified ONLY
 *                                    for the USD short-circuit, which returns
 *                                    before the query.
 *     :15  convertAmount()         -- verified ONLY for the same-currency
 *                                    short-circuit, which returns before
 *                                    getRate(). Every cross-currency conversion
 *                                    is blocked.
 *     :86  convertStoreCurrentValues() -- reads store_wallets under
 *                                    lockForUpdate (:126) and currency_rates
 *                                    (:147). Called by
 *                                    SettingController.php:1416 when a merchant
 *                                    changes store currency.
 *
 *   App\Http\Controllers\OrderController
 *     order_assign() -- a DeliveryEarning delete and a DeliveryEarning create,
 *     both against delivery_earnings. The line numbers are NOT pinned here:
 *     they were :1074 and :1094 when first surveyed and moved to :1268 and
 *     :1288 when this phase added a transaction boundary and a restock helper
 *     earlier in the same file. test_delivery_earnings_writes_are_blocked()
 *     therefore derives them from the source at run time instead, so the skip
 *     reason cannot go stale. The table is GATE F either way.
 *
 * WALLET AND WITHDRAWAL AUTHORIZATION specifically: none of it lives in the
 * paths this agent owns. StoreFinanceController holds it and belongs to another
 * agent; the route-level and controller-level denials are covered by
 * tests/Feature/Security/WithdrawalAuthorizationTest and
 * FinancePermissionTest, whose GATE-F-dependent cases are already skipped with
 * `store_wallets` named. Nothing is claimed here about them.
 *
 * NO MIGRATION IS CREATED BY THIS SUITE, and that is deliberate. Creating one of
 * these tables with guessed column types -- even in a test fixture -- would
 * normalise a guess, and the eventual real migration is a Schema::hasTable()
 * no-op, so a wrong guess would become permanent and invisible. The suite
 * asserts the tables are ABSENT before it skips, so a skip can never silently
 * outlive the gate.
 */
class FinancialSurfaceCoverageTest extends TestCase
{
    /** The six tables GATE F holds. */
    public const GATE_F_TABLES = [
        'store_wallets',
        'store_wallet_transactions',
        'store_withdrawals',
        'store_order_financials',
        'currency_rates',
        'delivery_earnings',
    ];

    /*
    |--------------------------------------------------------------------------
    | VERIFIED -- the paths that return before touching a GATE F table
    |--------------------------------------------------------------------------
    */

    /**
     * convertAmount() with one currency is exact and touches no table.
     *
     * It short-circuits at StoreCurrencyConversionService.php:24 before
     * getRate(), so this is genuinely verified rather than blocked -- which
     * matters, because it is the common case for a single-currency store.
     */
    public function test_a_same_currency_conversion_is_exact_and_needs_no_rate_table(): void
    {
        $this->assertGateFTablesAreAbsent();

        $service = new StoreCurrencyConversionService();

        $this->assertSame(100.0, $service->convertAmount(100.0, 'USD', 'USD', 1));
        $this->assertSame(0.0, $service->convertAmount(0.0, 'SYP', 'SYP', 1));
        $this->assertSame(
            1250.5,
            $service->convertAmount(1250.5, 'KWD', 'KWD', 1),
            'a same-currency conversion must not alter the amount'
        );
    }

    /** The short-circuit is case- and whitespace-insensitive. */
    public function test_the_same_currency_short_circuit_normalises_the_code(): void
    {
        $this->assertGateFTablesAreAbsent();

        $service = new StoreCurrencyConversionService();

        $this->assertSame(
            100.0,
            $service->convertAmount(100.0, ' usd ', 'USD', 1),
            'a padded lowercase code must still match, or the service would query '
                . 'currency_rates for a conversion that is a no-op'
        );
    }

    /**
     * Rounding is to 2 decimal places, which is the precision the whole money
     * surface assumes and the one GATE F cannot confirm for the COLUMNS.
     *
     * Worth pinning separately: this is the only place the precision is
     * observable without the tables.
     */
    public function test_a_same_currency_conversion_rounds_to_two_places(): void
    {
        $this->assertGateFTablesAreAbsent();

        $service = new StoreCurrencyConversionService();

        $this->assertSame(10.13, $service->convertAmount(10.125, 'USD', 'USD', 1));
        $this->assertSame(10.12, $service->convertAmount(10.124, 'USD', 'USD', 1));
    }

    /** getRate('USD') is 1.0 and returns before the currency_rates query. */
    public function test_the_usd_rate_is_one_without_consulting_the_rate_table(): void
    {
        $this->assertGateFTablesAreAbsent();

        $service = new StoreCurrencyConversionService();

        $this->assertSame(1.0, $service->getRate('USD', 1));
        $this->assertSame(1.0, $service->getRate(' usd ', 1));
    }

    /**
     * A MISSING RATE THROWS, and that is reported rather than changed.
     *
     * getRate() throws when no active currency_rates row exists
     * (StoreCurrencyConversionService.php:67). With the table absent entirely the
     * query itself raises instead, so what this proves is narrower and is stated
     * as such: a non-USD rate CANNOT be resolved in this environment, by either
     * route, and nothing silently falls back to 1.0. A silent fallback would be
     * the dangerous outcome -- it would convert money at par.
     *
     * Whether throwing is right for the CHECKOUT path is an open question for the
     * owner, not something to change blind: the throw propagates into
     * OrderFinancialService::createForOrder() (:134) and
     * convertStoreCurrentValues() (:147), and neither can be exercised here.
     */
    public function test_a_non_usd_rate_cannot_resolve_and_never_defaults_to_par(): void
    {
        $this->assertGateFTablesAreAbsent();

        $service = new StoreCurrencyConversionService();

        $this->expectException(\Throwable::class);

        $service->getRate('SYP', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | BLOCKED -- and asserted to be blocked, not merely assumed
    |--------------------------------------------------------------------------
    */

    /**
     * Every GATE F table really is absent from the test schema.
     *
     * This is what keeps the skips below honest: if a migration for one of these
     * ever lands, this test fails and the skips must be revisited rather than
     * quietly persisting as permanent holes.
     */
    public function test_the_gate_f_tables_are_still_undeclared(): void
    {
        foreach (self::GATE_F_TABLES as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                $table . ' now exists. GATE F may have been answered -- revisit every '
                    . 'skip in this suite and in tests/Feature/Tenancy and '
                    . 'tests/Feature/Security rather than leaving them skipped.'
            );
        }
    }

    /** A cross-currency conversion cannot be verified: currency_rates. */
    public function test_a_cross_currency_conversion_is_blocked(): void
    {
        $this->markTestSkipped(
            'BLOCKED -- EXTERNAL DEPENDENCY (GATE F): `currency_rates` is declared by no '
            . 'migration, so no rate can exist and StoreCurrencyConversionService::getRate() '
            . '(:57) cannot be exercised. Every cross-currency amount in '
            . 'OrderFinancialService (:135, :329, :473, :495) is therefore unverified. The '
            . 'decimal precision of `rate_per_usd` is exactly what must not be guessed.'
        );
    }

    /** Crediting a merchant's wallet for an order cannot be verified. */
    public function test_creating_an_order_financial_record_is_blocked(): void
    {
        $this->markTestSkipped(
            'BLOCKED -- EXTERNAL DEPENDENCY (GATE F): OrderFinancialService::createForOrder() '
            . '(:17) writes `store_order_financials` (:90), reads `store_wallets` (:21) and '
            . 'writes `store_wallet_transactions` (:156). None is declared by any migration. '
            . 'This is the function that CREDITS A MERCHANT WALLET for an order, so it is the '
            . 'single most consequential unverified function in the financial surface.'
        );
    }

    /** The paid transition on a financial record cannot be verified. */
    public function test_marking_a_financial_record_paid_is_blocked(): void
    {
        $this->markTestSkipped(
            'BLOCKED -- EXTERNAL DEPENDENCY (GATE F): '
            . 'OrderFinancialService::markFinancialAsPaid() (:284) updates '
            . '`store_order_financials` (:350), reads `store_wallets` (:303) and writes '
            . '`store_wallet_transactions` (:364). None is declared by any migration.'
        );
    }

    /**
     * The REVERSAL path cannot be verified, and it is the one a cancelled COD
     * order runs -- so this skip is load-bearing for the COD work in this phase.
     */
    public function test_marking_a_financial_record_unpaid_is_blocked(): void
    {
        $this->markTestSkipped(
            'BLOCKED -- EXTERNAL DEPENDENCY (GATE F): '
            . 'OrderFinancialService::markFinancialAsUnpaid() (:397) updates '
            . '`store_order_financials` (:519), reads `store_wallets` (:416), reads '
            . '`store_wallet_transactions` (:427) and writes one (:537). This is the path a '
            . 'CANCELLED order runs -- Order::order_status_change() writes payment_status = '
            . '"Unpaid" (app/Models/Order.php:550) and an Observer reaches this service -- so '
            . 'the reversal behaviour behind the COD state machine added in this phase is '
            . 'unverified. That is one more reason the COD policy ships OFF by default.'
        );
    }

    /** Converting a store's stored values on a currency change is blocked. */
    public function test_converting_a_stores_current_values_is_blocked(): void
    {
        $this->markTestSkipped(
            'BLOCKED -- EXTERNAL DEPENDENCY (GATE F): '
            . 'StoreCurrencyConversionService::convertStoreCurrentValues() (:86) reads '
            . '`store_wallets` under lockForUpdate (:126) and `currency_rates` (:147). '
            . 'Neither is declared by any migration. Reached from '
            . 'SettingController.php:1416 when a merchant changes their store currency, so a '
            . 'merchant-facing action rewrites wallet balances through code that cannot be '
            . 'tested here.'
        );
    }

    /** Wallet and withdrawal authorization is not in this agent's files. */
    public function test_wallet_and_withdrawal_authorization_is_blocked_and_not_owned_here(): void
    {
        $this->markTestSkipped(
            'BLOCKED -- EXTERNAL DEPENDENCY (GATE F) and NOT OWNED by this agent: wallet and '
            . 'withdrawal authorization lives in StoreFinanceController, which belongs to '
            . 'another agent this phase. `store_wallets`, `store_wallet_transactions` and '
            . '`store_withdrawals` are all undeclared. The route- and controller-level '
            . 'denials are covered by tests/Feature/Security/WithdrawalAuthorizationTest and '
            . 'FinancePermissionTest, whose GATE-F cases are already skipped with '
            . '`store_wallets` named. Nothing about them is claimed here.'
        );
    }

    /**
     * `delivery_earnings` is written from OrderController but is undeclared.
     *
     * THE CITATIONS ARE DERIVED, NOT TYPED. A hardcoded line number in a skip
     * reason silently rots: these two were :1074 and :1094 when the survey was
     * written and are :1268 and :1288 after this phase inserted a restock
     * helper and a transaction boundary earlier in the same file. A stale
     * citation in a BLOCKED message is a false statement about the codebase, so
     * the lines are located in the source each run.
     *
     * This also makes the skip self-checking: if the DeliveryEarning writes are
     * ever removed or renamed, the reason says so instead of naming lines that
     * no longer hold them.
     */
    public function test_delivery_earnings_writes_are_blocked(): void
    {
        $this->markTestSkipped(
            'BLOCKED -- EXTERNAL DEPENDENCY (GATE F): `delivery_earnings` is declared by no '
            . 'migration, yet OrderController::order_assign() writes it at '
            . $this->deliveryEarningWriteSites() . '. A delivery boy\'s earnings are '
            . 'therefore written by code whose table has no schema of record.'
        );
    }

    /**
     * Where OrderController touches DeliveryEarning, as `:line (kind)` pairs
     * read out of the file now.
     */
    private function deliveryEarningWriteSites(): string
    {
        $path = app_path('Http/Controllers/OrderController.php');

        if (!is_file($path)) {
            return 'OrderController.php (NOT FOUND -- the survey needs re-deriving)';
        }

        $found = [];

        foreach (file($path) as $index => $line) {
            // Skip comment lines: a comment mentioning the model is not a write.
            if (preg_match('/^\s*(\*|\/\/|\/\*)/', $line)) {
                continue;
            }

            if (!str_contains($line, 'DeliveryEarning::')) {
                continue;
            }

            if (str_contains($line, '->delete()')) {
                $found[] = ':' . ($index + 1) . ' (delete)';
            } elseif (str_contains($line, '::create(')) {
                $found[] = ':' . ($index + 1) . ' (create)';
            } else {
                $found[] = ':' . ($index + 1) . ' (other)';
            }
        }

        return $found === []
            ? 'NO DeliveryEarning write found any more -- re-derive this survey'
            : implode(', ', $found);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    /**
     * No GATE F table may exist when a "verified" assertion runs.
     *
     * Without this, a later fixture that creates one of these tables with
     * guessed columns would make the verified tests above pass for the wrong
     * reason.
     */
    private function assertGateFTablesAreAbsent(): void
    {
        foreach (self::GATE_F_TABLES as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                'this assertion is only meaningful while ' . $table . ' is undeclared'
            );
        }
    }

    /** Referenced so a reader can see the service under discussion is real. */
    public function test_the_financial_services_are_resolvable(): void
    {
        $this->assertInstanceOf(
            OrderFinancialService::class,
            app(OrderFinancialService::class),
            'the service exists and is wired; only its table-dependent behaviour is blocked'
        );
        $this->assertInstanceOf(
            StoreCurrencyConversionService::class,
            app(StoreCurrencyConversionService::class)
        );
    }
}
