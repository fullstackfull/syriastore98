<?php

namespace Tests\Feature\Commerce;

use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\User;
use App\Services\OrderFinancialService;
use App\Services\StoreCurrencyConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    // ADDED 2026-11-02 WITH GATE F. This class deliberately had NO RefreshDatabase, because its
    // whole point was that the six tables did not exist -- and without a migrated database
    // `Schema::hasTable()` was false for every table, which made its absence assertions pass
    // against a schema that had nothing in it at all. That is a test passing for the wrong reason,
    // and it was the reason the inverse assertion below could not simply be flipped: the class had
    // to gain a real database first.
    use RefreshDatabase;

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

        $service = new StoreCurrencyConversionService();

        $this->assertSame(10.13, $service->convertAmount(10.125, 'USD', 'USD', 1));
        $this->assertSame(10.12, $service->convertAmount(10.124, 'USD', 'USD', 1));
    }

    /** getRate('USD') is 1.0 and returns before the currency_rates query. */
    public function test_the_usd_rate_is_one_without_consulting_the_rate_table(): void
    {

        $service = new StoreCurrencyConversionService();

        $this->assertSame(1.0, $service->getRate('USD', 1));
        $this->assertSame(1.0, $service->getRate(' usd ', 1));
    }

    /**
     * A MISSING RATE THROWS, and that is reported rather than changed.
     *
     * getRate() throws when no active `currency_rates` row exists
     * (StoreCurrencyConversionService.php:67), and nothing silently falls back to 1.0 -- which
     * would be the dangerous outcome, because it converts money at par.
     *
     * CORRECTED 2026-11-02, because the old reason had gone stale and a stale reason attached to a
     * correct test is how a test outlives its purpose. This used to say "with the table absent
     * entirely the query itself raises instead, so what this proves is narrower" -- true while
     * `currency_rates` had no migration. GATE F closed: the table now EXISTS and is empty, so this
     * exercises the service's real `throw` rather than a `QueryException` about a missing table.
     * The test is strictly stronger than its old docblock claimed.
     * Whether throwing is right for the CHECKOUT path is an open question for the
     * owner, not something to change blind: the throw propagates into
     * OrderFinancialService::createForOrder() (:134) and
     * convertStoreCurrentValues() (:147), and neither can be exercised here.
     */
    public function test_a_non_usd_rate_cannot_resolve_and_never_defaults_to_par(): void
    {

        $service = new StoreCurrencyConversionService();

        $this->expectException(\Throwable::class);

        $service->getRate('SYP', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | BLOCKED -- and asserted to be blocked, not merely assumed
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | WAS BLOCKED, NOW VERIFIED -- GATE F closed on 2026-11-02
    |--------------------------------------------------------------------------
    |
    | Seven tests in this section were unconditional `markTestSkipped()` calls, each naming the
    | table that blocked it. Every one of those tables now has a migration whose structure was
    | MEASURED against a restored copy of production rather than inferred from `$fillable` --
    | docs/GATE-F-VERIFIED-SCHEMA.md section 2, reproduced by
    | App\Support\Database\GateFProductionSchema and verified on a real MariaDB by
    | tests/Support/Security/bin/verify-gate-f-migrations-on-mysql.php.
    |
    | So the skips are DELETED and the bodies are written. This is the opposite of rebaselining:
    | .github/known-test-outcomes.json asserts its declared set in BOTH directions, so a listed
    | skip that stops skipping FAILS CI until the entry is removed. Leaving them would have been
    | the silent lie that file forbids.
    |
    | WHAT THESE TESTS STILL CANNOT SEE. They run on SQLite, where type affinity means a
    | `decimal(15,2)` column accepts and returns whatever it likes -- so the SCALES are not
    | verified here and are not claimed to be. That is the MySQL script's job, and it is why these
    | tests assert BEHAVIOUR (a balance moved by the right amount, a ledger row with the right
    | direction) rather than column metadata.
    */

    /**
     * The inverse of what this test used to assert, in the same place, deliberately.
     *
     * It used to be `test_the_gate_f_tables_are_still_undeclared`, asserting
     * `assertFalse(Schema::hasTable(...))` for all six, with the message *"<table> now exists. GATE
     * F may have been answered -- revisit every skip in this suite"*. That is what happened. The
     * file is not left with a vacuous assertion: the property is inverted, so the class still
     * fails loudly if one of these migrations is ever reverted or renamed, which is the ratchet
     * the original was protecting.
     */
    public function test_the_gate_f_tables_are_declared_by_a_migration(): void
    {
        foreach (self::GATE_F_TABLES as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                $table . ' is missing from a database built by this repository\'s migrations. '
                    . 'GATE F closed on 2026-11-02; if a migration has been reverted, the seven '
                    . 'tests below cannot run and the declared-skip set in '
                    . '.github/known-test-outcomes.json no longer matches reality.'
            );
        }
    }

    /**
     * A cross-currency conversion now resolves a real stored rate. WAS BLOCKED on `currency_rates`.
     *
     * Three properties, because the first alone would pass against a broken rate lookup:
     * the rate is read back as stored, the conversion applies it in the right DIRECTION, and a
     * round trip returns the original amount. The direction matters -- `convertAmount` divides by
     * the from-rate and multiplies by the to-rate, so a transposed implementation would still
     * produce a plausible-looking number for a single conversion.
     */
    public function test_a_cross_currency_conversion_uses_the_stored_rate(): void
    {
        $store = $this->seedStore();

        CurrencyRate::create([
            'store_id'      => $store->id,
            'currency_code' => 'SYP',
            'currency_name' => 'Syrian Pound',
            'symbol'        => 'SYP',
            'rate_per_usd'  => 13000.00000000,
            'is_active'     => 1,
        ]);

        $service = new StoreCurrencyConversionService();

        $this->assertSame(13000.0, $service->getRate('SYP', (int) $store->id));
        $this->assertSame(1300000.0, $service->convertAmount(100.0, 'USD', 'SYP', (int) $store->id));
        $this->assertSame(100.0, $service->convertAmount(1300000.0, 'SYP', 'USD', (int) $store->id));
    }

    /**
     * An INACTIVE rate must not resolve, and must not silently fall back to par.
     *
     * This is the dangerous direction: a fallback of 1.0 would convert money at par, so a merchant
     * who deactivated a currency would have every amount in it treated as dollars.
     */
    public function test_a_deactivated_rate_does_not_resolve_and_never_falls_back_to_par(): void
    {
        $store = $this->seedStore();

        CurrencyRate::create([
            'store_id'      => $store->id,
            'currency_code' => 'SYP',
            'currency_name' => 'Syrian Pound',
            'rate_per_usd'  => 13000.00000000,
            'is_active'     => 0,
        ]);

        $this->expectException(\Throwable::class);

        (new StoreCurrencyConversionService())->getRate('SYP', (int) $store->id);
    }

    /**
     * Crediting a merchant's wallet for an order. WAS BLOCKED on all three wallet tables.
     *
     * This is the function the old skip called "the single most consequential unverified function
     * in the financial surface", because it is what moves an order's money into a merchant's
     * balance. Four things are asserted: the P&L row exists with the right arithmetic, the wallet
     * balance moved by exactly the order total, a ledger row records the movement as a CREDIT with
     * matching before/after balances, and a second call is a no-op.
     *
     * The no-op case is the one that matters most in production: `createForOrder` guards on an
     * existing row for the same order, and `uq_store_order_financial_order` now enforces the same
     * thing at the database. Without both, a retried webhook credits a wallet twice.
     */
    public function test_creating_an_order_financial_record_credits_the_wallet_once(): void
    {
        $store  = $this->seedStore();
        $wallet = $this->seedWallet($store);
        $order  = $this->seedOrder($store, [
            'product_price' => 120,
            'coupon_price'  => 20,
            'tax_price'     => 5,
            'delivery_price' => 10,
            'final_price'   => 115,
            'payment_status' => 'Paid',
        ]);

        // THE OBSERVER HAS ALREADY DONE THIS, and the explicit call is the no-op the service's own
        // `$exists` guard provides. `OrderObserver::created()` reaches `createForOrder()` for every
        // Order::create now that the table is migrated, so `seedOrder()` above already wrote the
        // financial row and credited the wallet. The call is kept because the assertions must fail
        // if `createForOrder` breaks -- but nobody should read it as the thing that credits the
        // wallet. Pointed out by independent review.
        app(OrderFinancialService::class)->createForOrder($order);

        $financial = DB::table('store_order_financials')->where('order_id', $order->id)->first();

        $this->assertNotNull($financial, 'No store_order_financials row was written for the order.');
        $this->assertEqualsWithDelta(120.0, (float) $financial->product_amount, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $financial->discount_amount, 0.001);
        $this->assertEqualsWithDelta(115.0, (float) $financial->customer_total, 0.001);
        $this->assertSame('paid', $financial->payment_status);
        $this->assertEqualsWithDelta(115.0, (float) $financial->paid_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $financial->remaining_amount, 0.001);

        // gross_profit = (product_amount - discount_amount) - product_cost, and the order has no
        // priced line items, so product_cost is 0.
        $this->assertEqualsWithDelta(100.0, (float) $financial->gross_profit, 0.001);

        $wallet->refresh();
        $this->assertEqualsWithDelta(
            115.0,
            (float) $wallet->available_balance,
            0.001,
            'A paid order must move its total into the merchant balance.'
        );
        $this->assertEqualsWithDelta(115.0, (float) $wallet->total_income, 0.001);

        $ledger = StoreWalletTransaction::where('wallet_id', $wallet->id)->get();
        $this->assertCount(1, $ledger, 'Exactly one ledger row should record the credit.');
        $this->assertSame('credit', $ledger[0]->direction);
        $this->assertSame('order_paid', $ledger[0]->type);
        $this->assertEqualsWithDelta(0.0, (float) $ledger[0]->balance_before, 0.001);
        $this->assertEqualsWithDelta(115.0, (float) $ledger[0]->balance_after, 0.001);

        // And again: a second call must change nothing.
        app(OrderFinancialService::class)->createForOrder($order->fresh());

        $this->assertSame(
            1,
            DB::table('store_order_financials')->where('order_id', $order->id)->count(),
            'A second createForOrder() wrote a SECOND financial row for one order. '
            . 'uq_store_order_financial_order exists to make that impossible.'
        );
        $this->assertEqualsWithDelta(
            115.0,
            (float) $wallet->fresh()->available_balance,
            0.001,
            'A second createForOrder() credited the wallet twice.'
        );
    }

    /**
     * AND A LATENT FINDING THESE TWO TESTS NORMALISE, recorded by independent review rather than
     * by the run: `markFinancialAsPaid()` and `markFinancialAsUnpaid()` look up
     * `store_order_financials` **by `id` alone** and then load the wallet by
     * `$financial->wallet_id`, with **no store predicate** on either `lockForUpdate()` lookup.
     *
     * They are safe today only because their single web caller scopes first --
     * `StoreFinanceController` does `where('id', $id)->where('store_id', $storeId)` before calling
     * the service. That is a **caller-dependent invariant that nothing enforces**, and the two
     * tests below now call the unscoped form DIRECTLY, which makes the unscoped shape look
     * sanctioned.
     *
     * Not a hole: no handler reaches these methods with a caller-supplied id and no ownership
     * predicate. Recorded as **latent**, with the one-line hardening named so it is not re-derived:
     * take `(int $financialId, $storeId)` and add `->where('store_id', $storeId)` to both
     * `lockForUpdate()` lookups. Deliberately NOT done inside a schema change -- it alters a money
     * service's signature and belongs in its own package.
     */

    /**
     * The paid transition. WAS BLOCKED on `store_order_financials` / `store_wallets`.
     *
     * An UNPAID order creates a financial row without moving money; marking it paid is what moves
     * it. So this asserts the wallet was untouched first -- otherwise a test that only checked the
     * end state would pass even if the credit had happened at creation time.
     */
    public function test_marking_a_financial_record_paid_moves_the_money(): void
    {
        $store  = $this->seedStore();
        $wallet = $this->seedWallet($store);
        $order  = $this->seedOrder($store, ['final_price' => 250, 'payment_status' => 'Unpaid']);

        app(OrderFinancialService::class)->createForOrder($order);

        $financial = DB::table('store_order_financials')->where('order_id', $order->id)->first();
        $this->assertSame('unpaid', $financial->payment_status);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $wallet->fresh()->available_balance,
            0.001,
            'An UNPAID order must not credit the wallet at creation time.'
        );

        app(OrderFinancialService::class)->markFinancialAsPaid((int) $financial->id);

        $after = DB::table('store_order_financials')->where('id', $financial->id)->first();
        $this->assertSame('paid', $after->payment_status);
        $this->assertNotNull($after->paid_at);
        $this->assertEqualsWithDelta(250.0, (float) $after->paid_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $after->remaining_amount, 0.001);

        $this->assertEqualsWithDelta(250.0, (float) $wallet->fresh()->available_balance, 0.001);

        $credits = StoreWalletTransaction::where('wallet_id', $wallet->id)
            ->where('direction', 'credit')->get();
        $this->assertCount(1, $credits, 'Marking a record paid must write exactly one credit.');

        // Idempotence: the service returns early when the record is already paid.
        app(OrderFinancialService::class)->markFinancialAsPaid((int) $financial->id);

        $this->assertEqualsWithDelta(
            250.0,
            (float) $wallet->fresh()->available_balance,
            0.001,
            'markFinancialAsPaid() credited the wallet a second time for one record.'
        );
    }

    /**
     * The REVERSAL path. WAS BLOCKED, and the old skip called it "load-bearing for the COD work in
     * this phase" because a cancelled order runs it.
     *
     * The property that matters is that the reversal returns the balance to where it started -- not
     * merely that it debits something. So the opening balance is recorded and compared at the end.
     */
    public function test_marking_a_financial_record_unpaid_reverses_the_credit(): void
    {
        $store  = $this->seedStore();
        $wallet = $this->seedWallet($store);
        $order  = $this->seedOrder($store, ['final_price' => 400, 'payment_status' => 'Paid']);

        $opening = (float) $wallet->available_balance;

        app(OrderFinancialService::class)->createForOrder($order);

        $financial = DB::table('store_order_financials')->where('order_id', $order->id)->first();
        $this->assertSame('paid', $financial->payment_status);
        $this->assertEqualsWithDelta($opening + 400.0, (float) $wallet->fresh()->available_balance, 0.001);

        app(OrderFinancialService::class)->markFinancialAsUnpaid((int) $financial->id);

        $after = DB::table('store_order_financials')->where('id', $financial->id)->first();
        $this->assertNotSame('paid', $after->payment_status, 'The record is still marked paid.');

        $this->assertEqualsWithDelta(
            $opening,
            (float) $wallet->fresh()->available_balance,
            0.001,
            'A reversal must return the balance to exactly where it started. Anything else means '
            . 'a cancelled order leaves money behind in, or takes money out of, the merchant balance.'
        );

        $debits = StoreWalletTransaction::where('wallet_id', $wallet->id)
            ->where('direction', 'debit')->get();
        $this->assertCount(1, $debits, 'The reversal must write exactly one debit row.');
        $this->assertEqualsWithDelta(400.0, (float) $debits[0]->amount, 0.001);
    }

    /**
     * A merchant changing store currency. WAS BLOCKED on `store_wallets` and `currency_rates`.
     *
     * Reached from SettingController when a merchant changes their store currency, so this is a
     * merchant-facing action that rewrites wallet balances.
     */
    public function test_converting_a_stores_current_values_rewrites_the_wallet(): void
    {
        $store  = $this->seedStore();
        $wallet = $this->seedWallet($store, ['available_balance' => 100, 'total_income' => 100]);

        CurrencyRate::create([
            'store_id'      => $store->id,
            'currency_code' => 'SYP',
            'currency_name' => 'Syrian Pound',
            'rate_per_usd'  => 13000.00000000,
            'is_active'     => 1,
        ]);

        app(StoreCurrencyConversionService::class)
            ->convertStoreCurrentValues((int) $store->id, 'USD', 'SYP');

        $this->assertEqualsWithDelta(
            1300000.0,
            (float) $wallet->fresh()->available_balance,
            0.01,
            'A store currency change must convert the stored wallet balance, not leave the old '
            . 'number under a new currency label.'
        );
    }

    /**
     * A same-currency change must be a no-op, which is the guard at the top of the method.
     *
     * Without this, the test above would also pass if the method converted unconditionally.
     */
    public function test_converting_a_store_to_its_own_currency_changes_nothing(): void
    {
        $store  = $this->seedStore();
        $wallet = $this->seedWallet($store, ['available_balance' => 100, 'total_income' => 100]);

        app(StoreCurrencyConversionService::class)
            ->convertStoreCurrentValues((int) $store->id, 'USD', 'usd');

        $this->assertEqualsWithDelta(100.0, (float) $wallet->fresh()->available_balance, 0.001);
    }

    /**
     * `delivery_earnings` is written from OrderController, and the table now exists.
     *
     * TWO HALVES, and both are needed. The first writes a row through the model and reads it back,
     * which is what the old skip could not do at all. The second keeps the ORIGINAL value of that
     * skip: it derives the OrderController write sites from the source at run time and asserts
     * there still are some, so removing or renaming the writes fails here rather than going
     * unnoticed.
     *
     * The citations were never typed into this file and still are not: they were :1074 and :1094
     * when first surveyed and :1268 and :1288 after a restock helper and a transaction boundary
     * were inserted earlier in the same file. A hardcoded line number in a test message is a claim
     * about the codebase that rots.
     */
    public function test_delivery_earnings_rows_persist_and_the_controller_still_writes_them(): void
    {
        $store = $this->seedStore();
        $order = $this->seedOrder($store);

        $boy = \App\Models\DeliveryBoy::create([
            'name'       => 'Driver ' . uniqid(),
            'email'      => 'driver' . uniqid() . '@example.test',
            'password'   => bcrypt('secret-not-used'),
            'store_id'   => $store->id,
            'created_by' => $store->created_by,
        ]);

        $earning = \App\Models\DeliveryEarning::create([
            'delivery_boy_id'       => $boy->id,
            'order_id'              => $order->id,
            'shipping_cost'         => 30.00,
            'commission_percentage' => 12.50,
            'commission_amount'     => 3.75,
            'status'                => 'pending',
        ]);

        $row = DB::table('delivery_earnings')->where('id', $earning->id)->first();

        $this->assertNotNull($row, 'No delivery_earnings row was persisted.');
        $this->assertEqualsWithDelta(30.0, (float) $row->shipping_cost, 0.001);
        $this->assertEqualsWithDelta(12.5, (float) $row->commission_percentage, 0.001);
        $this->assertEqualsWithDelta(3.75, (float) $row->commission_amount, 0.001);
        $this->assertSame('pending', $row->status);

        $this->assertStringNotContainsString(
            'NO DeliveryEarning write found',
            $this->deliveryEarningWriteSites(),
            'OrderController no longer writes DeliveryEarning anywhere. Either the feature was '
            . 'removed -- in which case this table and its migration should go too -- or a rename '
            . 'has made this assertion blind.'
        );
    }

    /**
     * Where OrderController touches DeliveryEarning, as `:line (kind)` pairs read out of the file
     * now.
     *
     * DERIVED, NOT TYPED, and that was true before GATE F closed too. These two sites were :1074
     * and :1094 when the financial surface was first surveyed and :1268 and :1288 after a restock
     * helper and a transaction boundary were inserted earlier in the same file. A hardcoded line
     * number in a test message is a claim about the codebase that rots silently.
     */
    private function deliveryEarningWriteSites(): string
    {
        $path = app_path('Http/Controllers/OrderController.php');

        if (! is_file($path)) {
            return 'OrderController.php (NOT FOUND -- the survey needs re-deriving)';
        }

        $found = [];

        foreach (file($path) as $index => $line) {
            // Skip comment lines: a comment mentioning the model is not a write.
            if (preg_match('/^\s*(\*|\/\/|\/\*)/', $line)) {
                continue;
            }

            if (! str_contains($line, 'DeliveryEarning::')) {
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
    | Premise and fixtures
    |--------------------------------------------------------------------------
    */

    /*
     * THE PREMISE HELPER WAS REMOVED, NOT INVERTED -- and that is the second decision about it.
     *
     * It began as `assertGateFTablesAreAbsent()`, called from the five short-circuit tests above so
     * that a fixture creating one of these tables with GUESSED columns could not make them pass for
     * the wrong reason. When GATE F closed it was inverted to `assertGateFTablesArePresent()`, which
     * kept the file non-vacuous but coupled five tests to a schema **they never consult**:
     * `convertAmount()` returns at its same-currency guard and `getRate('USD')` returns 1.0, both
     * before any query. A reverted migration would have reddened five tests with a message about
     * tables they do not touch.
     *
     * So the coupling is gone and the ratchet lives in exactly one place --
     * `test_the_gate_f_tables_are_declared_by_a_migration` above. Pointed out by independent review.
     *
     * Worth keeping on the record: the ORIGINAL version had a quieter problem than either. This
     * class had no `RefreshDatabase`, so `Schema::hasTable()` was false for EVERY table, not just
     * these six -- it asserted the absence of six tables in a schema that contained none.
     */

    /*
    | Fixtures are local rather than inherited: this class extends Tests\TestCase, while the richer
    | helpers live on Tests\Feature\Security\SecurityTestCase. Only what these tests need is here.
    */

    private function seedStore(): Store
    {
        $owner = User::factory()->create([
            'type'              => 'admin',
            'created_by'        => 1,
            'is_active'         => 1,
            'email_verified_at' => now(),
        ]);

        return Store::create([
            'name'       => 'Finance Store ' . uniqid(),
            'email'      => 'finance' . uniqid() . '@example.test',
            'theme_id'   => 'stylique',
            'slug'       => 'finance-' . uniqid(),
            'created_by' => $owner->id,
            'is_active'  => 1,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function seedWallet(Store $store, array $attributes = []): StoreWallet
    {
        return StoreWallet::create(array_merge([
            'store_id'                   => $store->id,
            'currency_code'              => 'USD',
            'available_balance'          => 0,
            'pending_balance'            => 0,
            'withdrawal_pending_balance' => 0,
            'total_income'               => 0,
            'total_withdrawn'            => 0,
        ], $attributes));
    }

    /** @param array<string,mixed> $attributes */
    private function seedOrder(Store $store, array $attributes = []): Order
    {
        // App\Models\Order's `creating` hook writes orders.currency_code and
        // orders.currency_symbol. Both are declared now; asserted rather than assumed, because a
        // missing one surfaces as "table orders has no column named currency_code" from inside an
        // unrelated financial assertion.
        foreach (['currency_code', 'currency_symbol'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('orders', $column),
                "orders.{$column} is written by App\\Models\\Order's creating hook but is not "
                . 'declared, so no Order row can be inserted.'
            );
        }

        return Order::create(array_merge([
            'product_order_id' => 'ORD-' . uniqid(),
            'order_date'       => now(),
            'customer_id'      => 0,
            'is_guest'         => 1,
            'product_json'     => '[]',
            'final_price'      => 100,
            'payment_type'     => 'cod',
            'payment_status'   => 'Unpaid',
            'delivered_status' => 0,
            'store_id'         => $store->id,
        ], $attributes));
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
