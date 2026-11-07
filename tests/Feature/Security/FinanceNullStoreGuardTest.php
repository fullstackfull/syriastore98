<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\StoreWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * THE NULL-STORE GUARD ON THE FOUR FINANCE HANDLERS THAT LACKED ONE.
 * =================================================================================================
 *
 * `StoreFinanceController` has ten handlers that resolve a tenant through `getCurrentStore()`.
 * SIX of them already refuse a principal for whom it answers nothing -- `addBalance` and
 * `deductBalance` (the F270 rationale), `approveWithdrawal`, `rejectWithdrawal` and
 * `payWithdrawal` (through `financeStoreId()`), and `unreconciledCaptures`. FOUR did not:
 * `index()`, `requestWithdrawal()`, `withdrawals()` and `updateFinancialPaymentStatus()`.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS IS *NOT*. The exposure originally alleged for these four -- a shared null-store
 * wallet, cross-tenant withdrawal visibility, money moving to the wrong tenant -- IS REFUTED, and
 * this file is written so that nobody re-derives it from the guard's existence. `store_id` is
 * `NOT NULL` in all three tables (`App\Support\Database\GateFProductionSchema::DDL` for
 * `store_wallets`, `store_withdrawals` and `store_order_financials`, measured against a restored
 * copy of production). `where('store_id', null)` compiles to `store_id IS NULL`, which therefore
 * matches NO ROW IN ANY TENANT, and the matching INSERT cannot succeed at all.
 *
 * WHAT IS REALLY THERE, and it is why the guard is worth adding:
 *
 *   index()                        500s. `StoreWallet::create(['store_id' => null, ...])` violates
 *                                  NOT NULL, so the finance dashboard is an error page.
 *   requestWithdrawal()            flashes the RAW `ModelNotFoundException` message out of
 *                                  `firstOrFail()`, naming `App\Models\StoreWallet` to the user.
 *   withdrawals()                  the QUERY answers an empty result set -- and this controller
 *                                  already names that outcome, in `unreconciledCaptures`, "the
 *                                  worst possible wrong answer on this page: it is the one that
 *                                  makes a reviewer stop looking". That is the real reason to fix
 *                                  it. A CORRECTION TO THE REVIEW THIS SLICE CAME FROM, which
 *                                  called the answer `200` with that empty table: THE PAGE NEVER
 *                                  REACHED 200. `partision/header.blade.php` hands the resolved
 *                                  store to `HomeController::getThemeUrl()`, which reads
 *                                  `$store->slug` on null, so the rendered answer was a 500 out
 *                                  of the SHARED CHROME. Measured here, and again directly:
 *                                  `/dashboard` is a 500 for this principal too. The chrome
 *                                  defect belongs to every merchant screen a storeless principal
 *                                  opens, is NOT finance's, is reported separately, and is why
 *                                  the refusal below is a correct diagnosis rather than a working
 *                                  page.
 *   updateFinancialPaymentStatus() refuses already, but says "Financial record not found" -- it
 *                                  blames the record for a condition of the session.
 *
 * Severity LOW, hardening. A PURE GUARD: it refuses only requests that already fail.
 * -------------------------------------------------------------------------------------------------
 *
 * WHO THE STORELESS PRINCIPAL ACTUALLY IS, because the first obvious candidate cannot exist.
 * `getCurrentStore()` (helper.php:767-781) returns the HARDCODED LITERAL 1 for a user of type
 * `super admin` whose `current_store` is empty, so the platform operator can never be storeless.
 * The reachable principal is the finance gate's OTHER limb: a delegated user who holds a real
 * permission row, owns no store, and whose `current_store` is null -- `getCurrentStore()` then
 * falls through to `Store::where('created_by', $user->id)->first()`, and staff do not create
 * stores, so it returns NULL.
 *
 * THE OPERATOR'S HARDCODED STORE 1 IS DELIBERATELY NOT TOUCHED by the change under test, and no
 * case here asserts anything about it. Refusing it would REMOVE a capability that works today
 * (store 1's wallet is created, its withdrawals are listed, its wallet is credited), so it is an
 * owner decision and is escalated rather than taken.
 *
 * EVERY REFUSAL BELOW IS WORTHLESS WITHOUT THE POSITIVE CONTROLS in section 4: a merchant WITH a
 * resolved store must still reach all four handlers, and `index()` must still perform its lazy
 * wallet create for a store whose wallet predates `StoreController`'s own `firstOrCreate`.
 */
class FinanceNullStoreGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The four permissions the handlers under test gate on, one each. */
    private const FINANCE_PERMISSIONS = [
        'View Finance Dashboard',          // index()
        'Request Finance Withdrawal',      // requestWithdrawal()
        'View Finance Withdrawal Requests',// withdrawals()
        'Edit Finance Payment Status',     // updateFinancialPaymentStatus()
    ];

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->assertGateFFinanceTablesAreMigrated();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        foreach (self::FINANCE_PERMISSIONS as $permission) {
            $this->grantPermission(User::find($this->merchant->id), $permission);
        }

        $this->merchant = User::find($this->merchant->id);
    }

    /**
     * The four GATE F tables these handlers read are declared by migrations since 2026-11-02.
     * This asserts their PRESENCE and creates nothing: a `Schema::create()` inside a test is not
     * a discoverable migration and would make this suite green against a fabricated money schema.
     */
    private function assertGateFFinanceTablesAreMigrated(): void
    {
        foreach ([
            'store_wallets',
            'store_wallet_transactions',
            'store_withdrawals',
            'store_order_financials',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table),
                "`{$table}` is missing from a database built by this repository's migrations, so "
                . 'the GATE F migration has been reverted or renamed. Do NOT answer this by '
                . 'creating the table here.');
        }
    }

    // =============================================================================================
    //  FIXTURES
    // =============================================================================================

    /**
     * A delegated principal who resolves NO store, with every finance permission really granted.
     *
     * `User` is Cachable, so the row is re-read after each write -- `grantPermission()` flushes,
     * and `getCurrentStore($id)` does its own `User::find()`, which would otherwise answer from
     * the pre-write copy and make a refusal look like the store guard when it is the permission
     * gate, or the other way round.
     */
    private function storelessPrincipal(): User
    {
        $staff = $this->makeStaff($this->merchant, ['current_store' => null]);

        foreach (self::FINANCE_PERMISSIONS as $permission) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        $staff = User::find($staff->id);

        $this->assertNull(getCurrentStore($staff->id),
            'premise: this principal still resolves a store, so the null-store path is not being '
            . 'driven and every assertion below would be vacuous');

        foreach (self::FINANCE_PERMISSIONS as $permission) {
            $this->assertTrue(Gate::forUser($staff)->allows('finance', $permission),
                "premise: this principal cannot pass the finance gate for '{$permission}', so a "
                . 'refusal below would be the permission gate rather than the store guard');
        }

        return $staff;
    }

    private function wallet(?float $balance = 500.00): StoreWallet
    {
        return StoreWallet::create([
            'store_id' => $this->store->id,
            'currency_code' => 'USD',
            'available_balance' => $balance,
            'pending_balance' => 0,
            'withdrawal_pending_balance' => 0,
            'total_income' => $balance,
            'total_withdrawn' => 0,
        ]);
    }

    /**
     * An unpaid financial row for this store, WRITTEN BY THE PRODUCTION WRITER.
     *
     * The first version of this helper inserted the row by hand and hit
     * `UNIQUE constraint failed: store_order_financials.order_id`, which is how the real writer
     * was found: `App\Observers\OrderObserver::created` (registered in AppServiceProvider:47)
     * calls `OrderFinancialService::createForOrder()`, so creating an Order ALREADY creates the
     * financial row -- but only when the store has a wallet, because that service opens with
     * `StoreWallet::where('store_id', ...)->firstOrFail()` and the observer swallows the throw
     * into a log line. Hence the wallet argument: it is the precondition, not decoration.
     */
    private function financial(StoreWallet $wallet): int
    {
        $this->assertSame(1, DB::table('store_wallets')->where('id', $wallet->id)->count(),
            'premise: no wallet, so OrderObserver::created will swallow a ModelNotFound and '
            . 'write no financial row at all');

        $order = $this->makeOrder($this->store);

        $financialId = (int) DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->value('id');

        $this->assertNotSame(0, $financialId,
            'premise: OrderObserver::created no longer writes a store_order_financials row for a '
            . 'new order, so this fixture builds nothing for the handler to find');
        $this->assertSame('unpaid', $this->paymentStatusOf($financialId),
            'premise: the fixture row is not unpaid, so marking it paid below is a no-op and the '
            . 'positive control proves nothing');

        return $financialId;
    }

    private function money($raw): string
    {
        return number_format((float) $raw, 2, '.', '');
    }

    /** The money as the DATABASE holds it, with no model copy in the way. */
    private function balanceOf(StoreWallet $wallet): string
    {
        return $this->money(
            DB::table('store_wallets')->where('id', $wallet->id)->value('available_balance')
        );
    }

    private function paymentStatusOf(int $financialId): string
    {
        return (string) DB::table('store_order_financials')
            ->where('id', $financialId)->value('payment_status');
    }

    /** The refusal the six guarded siblings already give, so all ten answer alike. */
    private function assertRefusedForNoStore($response, string $what): void
    {
        $this->assertNotSame(500, $response->getStatusCode(),
            "{$what}: the handler threw instead of refusing -- "
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(302, $response->getStatusCode(),
            "{$what}: a storeless principal was SERVED rather than refused, and on this surface an "
            . 'empty answer is indistinguishable from a clean one');
        $this->assertSame(__('No store is selected.'), $response->getSession()->get('error'),
            "{$what}: the refusal is not the store guard, so this case is measuring some other "
            . 'refusal');
    }

    // =============================================================================================
    //  1. THE PREMISE -- the fixture really builds what the refusals are about
    // =============================================================================================

    public function test_the_three_finance_tables_declare_store_id_not_null(): void
    {
        $ddl = \App\Support\Database\GateFProductionSchema::DDL;

        foreach (['store_wallets', 'store_withdrawals', 'store_order_financials'] as $table) {
            $this->assertStringContainsString('`store_id` bigint unsigned NOT NULL', $ddl[$table],
                "{$table}.store_id is no longer NOT NULL in the measured production structure. "
                . 'This file argues from that fact that there is no shared null-store row and no '
                . 'cross-tenant exposure on these four handlers -- if it has changed, re-triage '
                . 'the severity before trusting anything here.');
        }
    }

    /**
     * THE GUARD CANNOT REACH A FLUTTER CLIENT, and that is asserted rather than assumed.
     *
     * All four guarded handlers answer with an HTML REDIRECT. `ApiResponser::error()` returns HTTP
     * 200 by default precisely because the shipped Flutter admin FORCES A LOGOUT ON ANY 401, so a
     * new non-JSON answer on a path a client calls is a user-visible regression. This controller's
     * API surface is three SEPARATE methods -- `walletTransactionsApi`, `addBalanceApi`,
     * `deductBalanceApi`, which have their own `financeApiDenied()` JSON refusal and are NOT
     * touched by this change. This case pins that separation, so a later edit that routes one of
     * the four under `api/admin` is caught rather than discovered by a logged-out merchant.
     */
    public function test_no_api_route_reaches_any_of_the_four_guarded_handlers(): void
    {
        $guarded = ['index', 'requestWithdrawal', 'withdrawals', 'updateFinancialPaymentStatus'];
        $found = [];

        foreach (app('router')->getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, 'StoreFinanceController@')) {
                continue;
            }

            [, $method] = explode('@', $action, 2);

            if (in_array($method, $guarded, true)
                && str_starts_with(ltrim($route->uri(), '/'), 'api/')) {
                $found[] = $route->uri() . ' -> ' . $method;
            }
        }

        $this->assertSame([], $found,
            'a guarded handler is now reachable from the API surface, where its HTML redirect is '
            . 'not a contract any client understands: ' . implode(', ', $found));
    }

    // =============================================================================================
    //  2. THE FOUR UNGUARDED HANDLERS
    // =============================================================================================

    /**
     * index() -- `StoreWallet::create(['store_id' => null, ...])` cannot succeed against a NOT NULL
     * column, so the finance dashboard was a 500 for this principal rather than a refusal.
     */
    public function test_a_storeless_principal_is_refused_the_finance_dashboard(): void
    {
        $staff = $this->storelessPrincipal();

        $response = $this->actingAs($staff)->get(route('finance.index'));

        $this->assertRefusedForNoStore($response, 'storeless finance dashboard');
        $this->assertSame(0, DB::table('store_wallets')->count(),
            'a wallet row was written for a principal who resolves no store');
    }

    /**
     * requestWithdrawal() -- `firstOrFail()` on an `IS NULL` predicate threw, and the handler's
     * own `catch (\Exception $e)` flashed `$e->getMessage()`, putting a model class name on screen.
     */
    public function test_a_storeless_principal_is_refused_a_withdrawal_request(): void
    {
        $wallet = $this->wallet();
        $staff = $this->storelessPrincipal();

        $response = $this->actingAs($staff)->post(route('finance.withdrawal.request'), [
            'amount' => '10.00',
            'payment_method' => 'bank_transfer',
            'payment_details' => 'IBAN SY00 0000',
        ]);

        $this->assertRefusedForNoStore($response, 'storeless withdrawal request');
        $this->assertStringNotContainsString('StoreWallet',
            (string) $response->getSession()->get('error'),
            'the refusal leaks the raw ModelNotFoundException text, naming an internal model class');
        $this->assertSame(0, StoreWithdrawal::withoutGlobalScopes()->count(),
            'a storeless principal created a withdrawal request');
        $this->assertSame('500.00', $this->balanceOf($wallet),
            "a storeless principal moved another tenant's money");
    }

    /**
     * withdrawals() -- THE ONE BEHAVIOUR CHANGE IN THE WHOLE SLICE. This handler answered
     * 200-with-an-empty-list, which is the answer that makes a reviewer stop looking; it now
     * refuses. No row was ever disclosed, because `store_id IS NULL` matches none.
     */
    public function test_a_storeless_principal_is_refused_the_withdrawal_list(): void
    {
        $wallet = $this->wallet();
        StoreWithdrawal::create([
            'store_id' => $this->store->id,
            'wallet_id' => $wallet->id,
            'amount' => 25.00,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'payment_details' => 'IBAN-POSITIVE-CONTROL-777',
            'requested_by' => $this->merchant->id,
            'requested_at' => now(),
        ]);

        $staff = $this->storelessPrincipal();

        $response = $this->actingAs($staff)->get(route('finance.withdrawals'));

        $this->assertRefusedForNoStore($response, 'storeless withdrawal list');
        $this->assertStringNotContainsString('IBAN-POSITIVE-CONTROL-777',
            (string) $response->getContent(),
            "a storeless principal was served another tenant's withdrawal -- which would REFUTE "
            . "this file's premise that store_id IS NULL matches no row, so re-triage the severity");
    }

    /**
     * updateFinancialPaymentStatus() -- already refused, but blamed the RECORD for a condition of
     * the SESSION. "Financial record not found" sends the merchant looking for a missing order.
     */
    public function test_a_storeless_principal_is_told_no_store_rather_than_no_record(): void
    {
        $wallet = $this->wallet();
        $financialId = $this->financial($wallet);
        $staff = $this->storelessPrincipal();

        $response = $this->actingAs($staff)
            ->post(route('finance.order-financial.status', $financialId), [
                'payment_status' => 'paid',
            ]);

        $this->assertRefusedForNoStore($response, 'storeless payment-status change');
        $this->assertSame('unpaid', $this->paymentStatusOf($financialId),
            'a storeless principal marked a financial record paid');
        $this->assertSame('500.00', $this->balanceOf($wallet),
            'a storeless principal credited a wallet through the payment-status path');
    }

    // =============================================================================================
    //  3. THE REFUSALS MUST TERMINATE -- the hazard this slice nearly shipped
    // =============================================================================================

    /**
     * NO REFUSAL CHAIN MAY REVISIT A URL, and this case exists because the obvious implementation
     * loops.
     *
     * `index()` IS `finance.index`, so addBalance's `redirect()->route('finance.index')` would
     * redirect to itself. The other shape the six guarded siblings use -- `redirect()->back()` --
     * loops too, for a reason that is invisible in the diff: Laravel 11's
     * `StartSession::storeCurrentUrl()` sets the previous URL on EVERY routed GET, including one
     * that answers a redirect, so for a merchant whose last page was /finance `back()` returns
     * /finance and the pair bounces forever. A 302 chain is worse than the 500 it replaces.
     *
     * So this walks each of the four refusals to its end and asserts it reaches one. It also pins
     * that the dashboard, the chain's terminus, is NOT a finance route -- which is the structural
     * property that makes the other three safe to keep their own targets.
     */
    public function test_every_finance_refusal_chain_terminates_outside_the_finance_surface(): void
    {
        $wallet = $this->wallet();
        $financialId = $this->financial($wallet);
        $staff = $this->storelessPrincipal();

        /*
         * THE PRECONDITION THAT MAKES `back()` LOOP, established rather than assumed: the
         * previous URL must already be /finance. A GET that answers a 302 still records itself,
         * so one refused visit is enough to set it.
         */
        $this->actingAs($staff)->get(route('finance.index'));

        $chains = [
            'finance.index' => fn () => $this->actingAs($staff)->get(route('finance.index')),
            'finance.withdrawals' => fn () => $this->actingAs($staff)->get(route('finance.withdrawals')),
            'finance.withdrawal.request' => fn () => $this->actingAs($staff)
                ->post(route('finance.withdrawal.request'), [
                    'amount' => '10.00',
                    'payment_method' => 'bank_transfer',
                    'payment_details' => 'IBAN SY00 0000',
                ]),
            'finance.order-financial.status' => fn () => $this->actingAs($staff)
                ->post(route('finance.order-financial.status', $financialId), [
                    'payment_status' => 'paid',
                ]),
        ];

        foreach ($chains as $name => $start) {
            $response = $start();
            $seen = [];
            $hops = 0;

            while ($response->getStatusCode() === 302 && $hops < 10) {
                $next = (string) $response->headers->get('Location');

                $this->assertNotContains($next, $seen,
                    "{$name}: the refusal chain revisited {$next}, so a storeless merchant is in "
                    . 'an infinite redirect loop -- which is worse than the answer this guard '
                    . 'replaced. Visited: ' . implode(' -> ', $seen));

                $seen[] = $next;
                $response = $this->actingAs($staff)->get($next);
                $hops++;
            }

            $this->assertLessThan(10, $hops, "{$name}: the refusal chain did not terminate");
            $this->assertNotEmpty($seen, "{$name}: no refusal happened at all, so nothing was walked");
            $this->assertStringNotContainsString('/finance', (string) end($seen),
                "{$name}: the chain ends on the finance surface, which a storeless principal "
                . 'cannot use, so the next edit to any finance guard can close the loop');
        }
    }

    // =============================================================================================
    //  4. THE POSITIVE CONTROLS -- a merchant WITH a store still reaches all four
    // =============================================================================================

    /**
     * THE LAZY WALLET CREATE IS PRESERVED, and that is the only reason the guard can be called
     * pure. `StoreController` creates a wallet with its own `firstOrCreate` (:179-191, :336), so
     * this path exists for stores whose wallet PREDATES that -- it must keep working.
     */
    public function test_a_merchant_with_a_store_still_gets_the_lazy_wallet_create(): void
    {
        $this->assertSame(0, DB::table('store_wallets')->count(), 'premise: a wallet already exists');

        $response = $this->actingAs($this->merchant)->get(route('finance.index'));

        $this->assertSame(200, $response->getStatusCode(),
            'the finance dashboard no longer renders for a merchant with a resolved store: '
            . substr((string) $response->getContent(), 0, 400));
        $this->assertSame(1, DB::table('store_wallets')
            ->where('store_id', $this->store->id)->count(),
            'the lazy wallet create was lost, so a store whose wallet predates '
            . "StoreController's firstOrCreate can no longer open the finance dashboard");
    }

    public function test_a_merchant_with_a_store_still_requests_a_withdrawal(): void
    {
        $wallet = $this->wallet();

        $response = $this->actingAs($this->merchant)->post(route('finance.withdrawal.request'), [
            'amount' => '10.00',
            'payment_method' => 'bank_transfer',
            'payment_details' => 'IBAN SY00 0000',
        ]);

        $this->assertNull($response->getSession()->get('error'),
            'a legitimate withdrawal request was refused: '
            . (string) $response->getSession()->get('error'));
        $this->assertSame(1, StoreWithdrawal::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->count(),
            'a legitimate withdrawal request no longer creates a row');
        $this->assertSame('490.00', $this->balanceOf($wallet),
            'a legitimate withdrawal request no longer moves the available balance');
    }

    public function test_a_merchant_with_a_store_still_sees_its_withdrawal_list(): void
    {
        $wallet = $this->wallet();
        StoreWithdrawal::create([
            'store_id' => $this->store->id,
            'wallet_id' => $wallet->id,
            'amount' => 25.00,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'payment_details' => 'IBAN-POSITIVE-CONTROL-777',
            'requested_by' => $this->merchant->id,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($this->merchant)->get(route('finance.withdrawals'));

        $this->assertSame(200, $response->getStatusCode(),
            'the withdrawal list no longer renders for a merchant with a resolved store: '
            . substr((string) $response->getContent(), 0, 400));
        $this->assertStringContainsString('IBAN-POSITIVE-CONTROL-777',
            (string) $response->getContent(),
            "the guard is refusing a merchant who DOES resolve a store, so it is not a pure guard");
    }

    public function test_a_merchant_with_a_store_still_changes_a_payment_status(): void
    {
        $wallet = $this->wallet();
        $financialId = $this->financial($wallet);

        $response = $this->actingAs($this->merchant)
            ->post(route('finance.order-financial.status', $financialId), [
                'payment_status' => 'paid',
            ]);

        $this->assertNull($response->getSession()->get('error'),
            'a legitimate payment-status change was refused: '
            . (string) $response->getSession()->get('error'));
        $this->assertSame('paid', $this->paymentStatusOf($financialId),
            'a legitimate payment-status change no longer takes effect');
    }
}
