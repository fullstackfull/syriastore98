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
 * OWNER DECISION 7 -- AN OPERATOR WHO HAS CHOSEN NO MERCHANT MUST NOT MOVE A MERCHANT'S MONEY.
 * =================================================================================================
 *
 * The owner's words: *"An operator who has not explicitly selected a merchant must not create a
 * wallet, credit a wallet, approve a payment, or perform a tenant-specific money operation merely
 * because getCurrentStore() falls back to store ID 1. Fail closed on the identified financial
 * write paths when no explicit merchant selection exists. Preserve authorized operator behavior
 * after selecting a real store. Do not globally rewrite getCurrentStore() or change unrelated
 * operator workflows."*
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT WAS MEASURED, AND IT IS NOT HARDENING. The sibling slice that added six `empty($storeId)`
 * guards to this controller argued -- correctly, for the principal it was about -- that nothing
 * could move because `store_id` is `NOT NULL` in all three money tables, so a null store matched
 * no row and inserted nothing. THAT ARGUMENT DOES NOT TRANSFER TO THE OPERATOR, and the reason is
 * the whole finding: the operator's store is not NULL, it is the HARDCODED LITERAL 1.
 *
 * Driven through the real route with an operator whose `current_store` is NULL, against a store 1
 * owned by a DIFFERENT merchant (`tests/Feature/Security/OperatorImplicitStoreFinancialGuardTest`
 * and a throwaway probe before it):
 *
 *     POST /finance/add-balance  amount=77.00
 *       -> 302 to /finance, flash "Balance added successfully."
 *       -> store_wallets.available_balance for store 1: 100.00 -> 177.00
 *       -> store_wallet_transactions: 0 rows -> 1 row, type manual_deposit
 *
 * That is a completed cross-tenant credit of another merchant's wallet, by a principal who named
 * no merchant. No null anywhere, no refused INSERT, no exception: the money simply landed in
 * whoever owns store 1. Severity is NOT "low, hardening"; this is a real money write to the wrong
 * tenant, and `unreconciledCaptures` had already identified the same fallback (its F157 note) and
 * refused it for the review surface while every write path stayed open.
 * -------------------------------------------------------------------------------------------------
 *
 * THE OWNER'S STOP CONDITION WAS THE FIRST QUESTION: *"If the required guard cannot distinguish an
 * explicit choice from the fallback reliably, stop this specific change and report the technical
 * blocker."* IT CAN, and section 1 proves each leg by execution rather than by reading:
 *
 *   1. `getCurrentStore()` (helper.php:758-782) returns the literal 1 on exactly one branch:
 *      `empty($user->current_store)` AND `$user->type == 'super admin'`. There is no other path to
 *      that value, so the fallback is a FUNCTION of the column plus the type.
 *   2. The guard reads the same `empty()` on the same column off the same authenticated user, so
 *      THE GUARD AND THE HELPER CANNOT DISAGREE about whether the fallback was taken. This matters
 *      more than it looks: `empty()` is also false-y for `0` and `'0'`, and because the helper uses
 *      the identical test, a hypothetical `current_store = 0` is "unset" to BOTH -- the guard
 *      refuses exactly the inputs for which the helper defaults.
 *   3. Nothing writes a sentinel into the column. All nine persisted writes assign a real
 *      `Store->id` (`Api\DashboardController:4860/:5039/:5111`, `Auth\RegisteredUserController:147/
 *      :284`, `StoreController:297/:715/:869`, `UserController:161/:802`), and the migration
 *      declares it `unsignedBigInteger()->nullable()` with no default
 *      (`2014_10_12_000000_create_users_table.php:25`), so a fresh operator is NULL.
 *      THE ONE NON-NUMERIC ASSIGNMENT IS NOT A WRITE: `UserController:190` sets
 *      `$user->current_store = $storeDetail->name` to shape `json_encode($user)` for a webhook,
 *      AFTER `$user->save()` at :168, and the method returns a redirect with no further save. It
 *      never reaches the column -- but if anyone ever adds a `save()` after that block, the column
 *      would hold a store NAME, `empty()` would be false, and `getCurrentStore()` would return the
 *      name. That hazard is the HELPER's, not this guard's: the guard would simply stop refusing,
 *      exactly as the helper would stop defaulting. Reported, not fixed here.
 *
 * THE DISTINCTION IS NOT AVAILABLE FROM THE RETURN VALUE, which is why the column is read instead
 * of the helper's answer, and `test_the_fallback_and_an_explicit_choice_of_store_1_are_
 * indistinguishable_from_the_return_value_alone` pins that. An operator who has chosen store 1 and
 * an operator who has chosen nothing both see `getCurrentStore() === 1`. Comparing against 1 would
 * therefore have broken the authorized case, which is the owner's second requirement.
 *
 * WHAT IS GUARDED: the eight write paths that resolve their tenant through `getCurrentStore()` --
 * `index()` (its lazy `StoreWallet::create`), `addBalance`, `deductBalance`, `requestWithdrawal`,
 * `updateFinancialPaymentStatus`, `approveWithdrawal`, `rejectWithdrawal`, `payWithdrawal`.
 *
 * WHAT IS DELIBERATELY NOT GUARDED, each with a case below so it is visibly a decision:
 *   - `unreconciledCaptures()` -- ALREADY refuses this principal. It is the in-repo precedent this
 *     guard copies and is untouched.
 *   - `withdrawals()` -- a read. It writes nothing, so it is outside the owner's enumerated write
 *     paths, and refusing a read would be the "unrelated operator workflow" change the owner
 *     excluded. It does disclose store 1's withdrawal list to an unselected operator; that is
 *     REPORTED for an owner decision and pinned below as measured, not endorsed.
 *   - `walletTransactionsApi` / `addBalanceApi` / `deductBalanceApi` -- they never call
 *     `getCurrentStore()`. They read `$request->query('store_id')`, which IS an explicit naming of
 *     a merchant, and the shipped Flutter admin posts to them. The fallback cannot reach them, and
 *     a case below asserts that separation so a later edit cannot quietly route one through the
 *     helper.
 *
 * EVERY REFUSAL HERE IS WORTHLESS WITHOUT SECTIONS 3-6: an operator who HAS selected store 1 must
 * still be able to do all eight things, an operator who selected store 2 must act on store 2, a
 * merchant must be untouched, a cross-tenant attempt must still be denied, and the arithmetic must
 * be bit-for-bit what it was.
 */
class OperatorImplicitStoreFinancialGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Every finance permission this controller gates on. */
    private const FINANCE_PERMISSIONS = [
        'View Finance Dashboard',
        'Add Finance Balance',
        'Deduct Finance Balance',
        'Request Finance Withdrawal',
        'View Finance Withdrawal Requests',
        'Edit Finance Payment Status',
    ];

    private User $operator;

    /** The merchant who owns store 1 -- the tenant the fallback silently targets. */
    private User $merchantOne;

    private Store $storeOne;

    /** A second tenant, so "explicitly chose a store" can be told from "chose store 1". */
    private User $merchantTwo;

    private Store $storeTwo;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        foreach ([
            'store_wallets',
            'store_wallet_transactions',
            'store_withdrawals',
            'store_order_financials',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table),
                "`{$table}` is missing from a database built by this repository's migrations, so "
                . 'the GATE F migration has been reverted or renamed. Do NOT answer this by '
                . 'creating the table here: a Schema::create() inside a test is not a discoverable '
                . 'migration and would make this suite green against a fabricated money schema.');
        }

        // ORDER MATTERS. The first Store insert takes id 1, which is the literal the helper
        // returns, and it must belong to somebody OTHER than the operator for the cross-tenant
        // reading to be real rather than nominal.
        $this->merchantOne = $this->makeMerchant();
        $this->storeOne = $this->makeStore($this->merchantOne, ['default_language' => 'en']);
        $this->merchantOne->current_store = $this->storeOne->id;
        $this->merchantOne->save();

        $this->merchantTwo = $this->makeMerchant();
        $this->storeTwo = $this->makeStore($this->merchantTwo, ['default_language' => 'en']);
        $this->merchantTwo->current_store = $this->storeTwo->id;
        $this->merchantTwo->save();

        foreach (self::FINANCE_PERMISSIONS as $permission) {
            $this->grantPermission(User::find($this->merchantOne->id), $permission);
        }

        $this->merchantOne = User::find($this->merchantOne->id);

        $this->operator = $this->makePlatformAdmin(['current_store' => null]);
        $this->operator = User::find($this->operator->id);
    }

    // =============================================================================================
    //  FIXTURES
    // =============================================================================================

    /** The operator, with `current_store` set to whatever an explicit choice would have set. */
    private function operatorWhoChose(?int $storeId): User
    {
        // Eloquent, not DB::table(): `User` is Cachable, and a raw write leaves `User::find()` --
        // which getCurrentStore($id) itself calls -- serving the pre-write row for the rest of the
        // test. That is three measured false readings in this project.
        $this->operator->current_store = $storeId;
        $this->operator->save();

        return User::find($this->operator->id);
    }

    private function wallet(Store $store, float $available, float $pending = 0.0): StoreWallet
    {
        return StoreWallet::create([
            'store_id' => $store->id,
            'currency_code' => 'USD',
            'available_balance' => $available,
            'pending_balance' => 0,
            'withdrawal_pending_balance' => $pending,
            'total_income' => $available,
            'total_withdrawn' => 0,
        ]);
    }

    private function withdrawal(Store $store, StoreWallet $wallet, string $status, float $amount): StoreWithdrawal
    {
        return StoreWithdrawal::create([
            'store_id' => $store->id,
            'wallet_id' => $wallet->id,
            'amount' => $amount,
            'status' => $status,
            'payment_method' => 'bank',
            'payment_details' => 'IBAN SY00 0000',
            'requested_by' => $this->merchantOne->id,
            'requested_at' => now(),
        ]);
    }

    /**
     * An unpaid financial row, WRITTEN BY THE PRODUCTION WRITER rather than inserted by hand.
     *
     * `App\Observers\OrderObserver::created` calls `OrderFinancialService::createForOrder()`, which
     * opens with `StoreWallet::where('store_id', ...)->firstOrFail()` and whose throw the observer
     * swallows into a log line -- so the wallet is the PRECONDITION, not decoration, and a
     * hand-written row would collide with `uq_store_order_financial_order`.
     */
    private function financial(Store $store, StoreWallet $wallet): int
    {
        $this->assertSame(1, DB::table('store_wallets')->where('id', $wallet->id)->count(),
            'premise: no wallet, so OrderObserver::created swallows a ModelNotFound and writes no '
            . 'financial row for the handler to find');

        $order = $this->makeOrder($store);

        $financialId = (int) DB::table('store_order_financials')
            ->where('order_id', $order->id)->value('id');

        $this->assertNotSame(0, $financialId,
            'premise: OrderObserver::created no longer writes a store_order_financials row, so '
            . 'this fixture builds nothing and every assertion about it would be vacuous');
        $this->assertSame('unpaid', $this->statusOf($financialId),
            'premise: the fixture row is already paid, so marking it paid is a no-op and the '
            . 'positive control proves nothing');

        return $financialId;
    }

    private function money($raw): string
    {
        return number_format((float) $raw, 2, '.', '');
    }

    /** The money as the DATABASE holds it, with no Cachable model copy in the way. */
    private function availableOf(int $walletId): string
    {
        return $this->money(
            DB::table('store_wallets')->where('id', $walletId)->value('available_balance')
        );
    }

    private function pendingOf(int $walletId): string
    {
        return $this->money(
            DB::table('store_wallets')->where('id', $walletId)->value('withdrawal_pending_balance')
        );
    }

    private function statusOf(int $financialId): string
    {
        return (string) DB::table('store_order_financials')
            ->where('id', $financialId)->value('payment_status');
    }

    private function withdrawalStatusOf(int $id): string
    {
        return (string) DB::table('store_withdrawals')->where('id', $id)->value('status');
    }

    /**
     * THE GUARD'S OWN REFUSAL, told apart from every other way this handler can say no.
     *
     * Four assertions and all four earn their place, because this project has measured all four
     * ways such a case passes for the wrong reason:
     *   - not 500: a handler that threw "wrote nothing" too, so an unchanged row proves nothing.
     *   - not 404: a row assertion is satisfied by a missing route.
     *   - 302 with THIS message: a 403 from the permission gate would also leave the row alone.
     */
    private function assertRefusedAsUnselectedOperator($response, string $what): void
    {
        $this->assertNotSame(500, $response->getStatusCode(),
            "{$what}: the handler THREW instead of refusing, so an unchanged row below is the "
            . 'exception and not the guard -- ' . substr(strip_tags((string) $response->getContent()), 0, 400));
        $this->assertNotSame(404, $response->getStatusCode(),
            "{$what}: the route did not resolve, so nothing was driven and every row assertion "
            . 'below is vacuous');
        $this->assertSame(302, $response->getStatusCode(),
            "{$what}: an operator who selected no merchant was SERVED rather than refused");
        $this->assertSame(__('No store is selected.'), session('error'),
            "{$what}: the refusal is not this guard -- it is some other refusal, so this case is "
            . 'not measuring the fallback at all');
    }

    // =============================================================================================
    //  1. THE PREMISE -- the distinction the owner made a stop condition out of
    // =============================================================================================

    public function test_store_one_exists_and_belongs_to_another_merchant(): void
    {
        $this->assertSame(1, (int) $this->storeOne->id,
            'premise: the first store is not id 1, so the hardcoded fallback does not point at '
            . 'this fixture and nothing below measures the real target');
        $this->assertSame((int) $this->merchantOne->id, (int) $this->storeOne->created_by,
            'premise: store 1 is not owned by a merchant other than the operator, so a write to '
            . 'it would not be cross-tenant and the severity argument in this docblock is wrong');
        $this->assertNotSame((int) $this->operator->id, (int) $this->storeOne->created_by,
            'premise: the operator owns store 1, so the fallback is not foreign');
    }

    public function test_an_operator_who_chose_nothing_resolves_the_hardcoded_literal_one(): void
    {
        $this->assertNull($this->operator->current_store,
            'premise: the operator already has a selection, so the fallback branch is not reached');
        $this->assertSame('super admin', $this->operator->type,
            'premise: the type is not the literal the helper compares against, so the fallback '
            . 'branch is not reached');

        $this->assertSame(1, (int) getCurrentStore($this->operator->id),
            'premise: getCurrentStore() no longer answers the hardcoded 1 for an operator with no '
            . 'selection. THE DEFECT THIS WHOLE FILE IS ABOUT HAS CHANGED SHAPE -- re-read '
            . 'app/Helper/helper.php:758-782 before trusting any case here.');
    }

    public function test_the_fallback_and_an_explicit_choice_of_store_one_are_indistinguishable_from_the_return_value_alone(): void
    {
        $unselected = (int) getCurrentStore($this->operator->id);

        $chose = $this->operatorWhoChose(1);
        $selected = (int) getCurrentStore($chose->id);

        $this->assertSame($unselected, $selected,
            'premise: the two cases now differ in the helper\'s RETURN VALUE. If that is true the '
            . 'guard could have been a comparison against 1, and reading the column would be '
            . 'unnecessary complexity -- but it is not true, and this is why.');
        $this->assertSame(1, $selected, 'premise: neither case resolves store 1 any more');

        // AND THE COLUMN DOES distinguish them -- which is the whole design. If either half of
        // this ever fails, the owner's STOP CONDITION is met: stop and report the blocker.
        $this->assertNotEmpty($chose->current_store,
            'the explicit choice is not readable off the authenticated user, so the guard has no '
            . 'signal to act on');

        $this->operator->current_store = null;
        $this->operator->save();

        $this->assertEmpty(User::find($this->operator->id)->current_store,
            'the UNSELECTED operator\'s column is not empty, so `empty()` cannot tell the two '
            . 'cases apart and the guard has no signal to act on');
    }

    /**
     * The refusals below must be THE STORE GUARD, never the permission gate. This is the ordering
     * CLAUDE.md requires and it is counter-intuitive enough to assert: `isAbleTo()` REFUSES the
     * platform operator on all six finance names, while the `finance` gate ALLOWS all six.
     */
    public function test_the_operator_passes_the_finance_gate_while_is_able_to_refuses_every_name(): void
    {
        foreach (self::FINANCE_PERMISSIONS as $permission) {
            $this->assertTrue(Gate::forUser($this->operator)->allows('finance', $permission),
                "premise: the operator cannot pass the finance gate for '{$permission}', so every "
                . 'refusal below is the permission gate rather than the store guard');
            $this->assertFalse($this->operator->isAbleTo($permission),
                "premise: isAbleTo('{$permission}') now answers true for an ungranted operator. "
                . 'Laratrust has gained a super-admin bypass, which this codebase documents as '
                . 'absent -- re-read the operator-first ordering before trusting anything here.');
        }
    }

    /**
     * THE GUARD USES THE ONE SHARED OPERATOR HELPER, AND THIS CASE EXISTS BECAUSE THE FIRST DRAFT
     * OF THE GUARD DID NOT.
     *
     * It carried `strtolower(trim((string) $caller->type)) === 'super admin'`, copied from
     * `unreconciledCaptures` in the same file. `OperatorCheckCensusTest` records a MEASURED defect
     * of exactly that shape -- `OrderController::fileExport` used the helper while
     * `mayUseOrderScreen` carried its own `strtolower(trim(...))`, and for `users.type` =
     * `' super admin'` the two answered DIFFERENTLY, so the order screen admitted a principal the
     * document guard refused. P1-SEC-11 criterion 3 is "the operator is defined once: normalised
     * comparison, one helper".
     *
     * KEYED ON THE CONSTRUCT, NOT ON A MENTION, for the reason that census gives: the first
     * version of its own equivalent only asked whether the file mentions the helper, and a drift
     * hid inside a file that passed it.
     *
     * NOTE FOR WHOEVER READS THIS NEXT: neither spelling is counted by
     * `OperatorCheckCensusTest::PATTERNS`. Its three PCREs require `type` immediately followed by
     * the comparison operator, and `type)) === 'super admin'` does not match -- so that ceiling
     * could not have caught this. Reported; this case is the local substitute.
     */
    public function test_the_guard_defines_the_operator_through_the_one_shared_helper(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Http/Controllers/StoreFinanceController.php')
        );

        $start = strpos($source, 'private function operatorHasChosenNoStore()');
        $this->assertNotFalse($start,
            'premise: operatorHasChosenNoStore() is gone, so this case scans nothing');

        $body = substr($source, $start, 400);

        $this->assertStringContainsString('UserPolicy::isOperator', $body,
            'the guard no longer defines the operator through the one shared helper. A hand-rolled '
            . 'comparison beside UserPolicy::isOperator is the measured "two gates disagree about '
            . 'who the operator is" defect that P1-SEC-11 criterion 3 exists to end.');
        $this->assertStringNotContainsString("=== 'super admin'", $body,
            'a second spelling of operator status sits beside the shared helper inside the guard');
        $this->assertStringNotContainsString("== 'super admin'", $body,
            'a second spelling of operator status sits beside the shared helper inside the guard');
    }

    /**
     * THE GUARD IS A STRICT SUPERSET OF THE FALLBACK CONDITION -- the reliability claim, measured
     * across every spelling of `users.type` rather than argued from the source.
     *
     * `getCurrentStore()` reaches the literal 1 only when `$user->type == 'super admin'`, and PHP's
     * `==` between two non-numeric strings is a plain string comparison, so the EXACT literal is
     * the only value that gets there. Every other spelling falls through to
     * `Store::where('created_by', $user->id)->first()`, which is NULL for a principal who owns no
     * store and is caught by the pre-existing `empty($storeId)` guard.
     *
     * So the two guards together must leave the money alone for ALL FIVE spellings, and exactly one
     * of them must be the fallback. If either half of that ever changes, the owner's STOP CONDITION
     * is back on the table and this case says so.
     */
    public function test_no_spelling_of_the_operator_type_can_move_money_without_an_explicit_choice(): void
    {
        $reachedTheFallback = [];

        foreach (['super admin', 'Super Admin', 'super admin ', ' super admin', 'admin'] as $spelling) {
            $wallet = $this->wallet($this->storeOne, 100.00);

            $probe = User::factory()->create([
                'type' => $spelling,
                'created_by' => null,
                'is_active' => 1,
                'email_verified_at' => now(),
                'current_store' => null,
            ]);
            $probe = User::find($probe->id);

            $this->assertEmpty($probe->current_store,
                "premise [{$spelling}]: the probe has a selection, so the fallback is not driven");
            $this->assertSame(0, DB::table('stores')->where('created_by', $probe->id)->count(),
                "premise [{$spelling}]: the probe owns a store, so getCurrentStore() resolves it "
                . 'legitimately and this row is not about the fallback at all');

            $resolved = getCurrentStore($probe->id);

            if ((int) $resolved === 1) {
                $reachedTheFallback[] = $spelling;
            }

            $response = $this->actingAs($probe)->post(route('finance.add.balance'), [
                'amount' => '13.00',
                'description' => 'probe credit for type spelling ' . $spelling,
            ]);

            $this->assertNotSame(500, $response->getStatusCode(),
                "[{$spelling}]: the handler threw, so an unchanged balance proves nothing");
            $this->assertSame('100.00', $this->availableOf($wallet->id),
                "[{$spelling}]: a principal with NO explicit store selection credited store 1 "
                . '(getCurrentStore answered ' . var_export($resolved, true) . '). NOTE WHICH '
                . 'CONTROL IS BEING MEASURED, because an independent review found this message '
                . 'misattributed it: only `super admin` reaches a store guard at all. The probe '
                . 'holds no granted permission, and `AuthServiceProvider:117`\'s operator limb is '
                . 'a strict `===`, so the four other spellings are refused earlier by '
                . 'FinancePermission with a 403 and never enter the handler. That still makes this '
                . 'a real ratchet -- reverting the operator guard reddens the `super admin` row at '
                . '113.00 -- but the four 403s prove the middleware, not the guard.');

            DB::table('store_wallet_transactions')->delete();
            DB::table('store_wallets')->delete();
        }

        $this->assertSame(['super admin'], $reachedTheFallback,
            'the set of users.type spellings that reach the hardcoded store-1 fallback has '
            . 'changed. The guard is built on the claim that only the EXACT literal gets there, '
            . 'because getCurrentStore() compares with PHP == between two non-numeric strings. '
            . 'Re-read app/Helper/helper.php:758-782 and re-derive the predicate before trusting '
            . 'this file.');
    }

    public function test_no_api_route_resolves_its_tenant_through_the_fallback(): void
    {
        $viaHelper = ['index', 'addBalance', 'deductBalance', 'requestWithdrawal', 'withdrawals',
            'updateFinancialPaymentStatus', 'approveWithdrawal', 'rejectWithdrawal',
            'payWithdrawal', 'unreconciledCaptures'];
        $found = [];

        // Keyed on getActionName(), not on uri words or on Route::post lines: this project has
        // four measured censuses that missed what they claimed to cover by keying on spelling.
        foreach (app('router')->getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, 'StoreFinanceController@')) {
                continue;
            }

            [, $method] = explode('@', $action, 2);

            if (in_array($method, $viaHelper, true)
                && str_starts_with(ltrim($route->uri(), '/'), 'api/')) {
                $found[] = $route->uri() . ' -> ' . $method;
            }
        }

        $this->assertSame([], $found,
            'a handler that resolves its tenant through getCurrentStore() is now on the API '
            . 'surface, where this guard answers an HTML REDIRECT that no client understands and '
            . 'where the three existing API methods instead take an explicit store_id: '
            . implode(', ', $found));

        // Reachability, so a rename cannot make the scan above pass vacuously.
        $this->assertNotSame([], array_values(array_filter(
            iterator_to_array(app('router')->getRoutes()->getIterator()),
            fn ($r) => str_contains($r->getActionName(), 'StoreFinanceController@')
        )), 'no route points at StoreFinanceController at all, so the census scanned nothing');
    }

    // =============================================================================================
    //  2. FAIL CLOSED -- the eight write paths, each asserting the MONEY is unchanged
    // =============================================================================================

    public function test_index_creates_no_wallet_for_the_fallback_store(): void
    {
        $this->assertSame(0, DB::table('store_wallets')->count(),
            'premise: a wallet already exists, so the lazy create is not reached and "no wallet '
            . 'was created" holds either way');

        $response = $this->actingAs($this->operator)->get(route('finance.index'));

        $this->assertRefusedAsUnselectedOperator($response, 'index');
        $this->assertSame(0, DB::table('store_wallets')->where('store_id', 1)->count(),
            'A WALLET WAS CREATED FOR ANOTHER MERCHANT because the operator opened /finance '
            . 'without choosing them -- the owner named "create a wallet" first');
    }

    public function test_add_balance_credits_nothing_for_the_fallback_store(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00);

        $this->assertSame('100.00', $this->availableOf($wallet->id), 'premise: fixture balance');
        $this->assertSame(0, DB::table('store_wallet_transactions')->count(),
            'premise: the ledger is not empty, so a count assertion below would be ambiguous');

        $response = $this->actingAs($this->operator)->post(route('finance.add.balance'), [
            'amount' => '77.00',
            'description' => 'operator credit with no merchant chosen',
        ]);

        $this->assertRefusedAsUnselectedOperator($response, 'addBalance');
        $this->assertSame('100.00', $this->availableOf($wallet->id),
            'ANOTHER MERCHANT\'S WALLET WAS CREDITED by an operator who named no merchant. This is '
            . 'the measured defect: 100.00 -> 177.00 through POST /finance/add-balance.');
        $this->assertSame(0, DB::table('store_wallet_transactions')->count(),
            'a manual_deposit row was written against the fallback tenant\'s ledger');
    }

    public function test_deduct_balance_debits_nothing_for_the_fallback_store(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00);

        $this->assertSame('100.00', $this->availableOf($wallet->id), 'premise: fixture balance');

        $response = $this->actingAs($this->operator)->post(route('finance.deduct.balance'), [
            'amount' => '40.00',
            'description' => 'operator debit with no merchant chosen',
        ]);

        $this->assertRefusedAsUnselectedOperator($response, 'deductBalance');
        $this->assertSame('100.00', $this->availableOf($wallet->id),
            'another merchant\'s wallet was debited by an operator who named no merchant');
        $this->assertSame(0, DB::table('store_wallet_transactions')->count(),
            'a manual_withdrawal row was written against the fallback tenant\'s ledger');
    }

    public function test_request_withdrawal_creates_nothing_for_the_fallback_store(): void
    {
        $wallet = $this->wallet($this->storeOne, 500.00);

        $this->assertSame('500.00', $this->availableOf($wallet->id), 'premise: fixture balance');
        $this->assertSame(0, DB::table('store_withdrawals')->count(),
            'premise: a withdrawal already exists, so a count assertion would be ambiguous');

        $response = $this->actingAs($this->operator)->post(route('finance.withdrawal.request'), [
            'amount' => '120.00',
            'payment_method' => 'bank',
            'payment_details' => 'IBAN SY00 0000',
        ]);

        $this->assertRefusedAsUnselectedOperator($response, 'requestWithdrawal');
        $this->assertSame(0, DB::table('store_withdrawals')->count(),
            'a withdrawal was raised against another merchant\'s wallet by an operator who named '
            . 'no merchant');
        $this->assertSame('500.00', $this->availableOf($wallet->id),
            'another merchant\'s available balance moved into withdrawal_pending');
        $this->assertSame('0.00', $this->pendingOf($wallet->id),
            'another merchant\'s withdrawal_pending_balance moved');
    }

    public function test_update_financial_payment_status_approves_nothing_for_the_fallback_store(): void
    {
        $wallet = $this->wallet($this->storeOne, 0.00);
        $financialId = $this->financial($this->storeOne, $wallet);
        $balanceBefore = $this->availableOf($wallet->id);

        $response = $this->actingAs($this->operator)
            ->post(route('finance.order-financial.status', $financialId), [
                'payment_status' => 'paid',
            ]);

        $this->assertRefusedAsUnselectedOperator($response, 'updateFinancialPaymentStatus');
        $this->assertSame('unpaid', $this->statusOf($financialId),
            'ANOTHER MERCHANT\'S ORDER WAS MARKED PAID by an operator who named no merchant -- the '
            . 'owner named "approve a payment" explicitly');
        $this->assertSame($balanceBefore, $this->availableOf($wallet->id),
            'markFinancialAsPaid() moves store_wallets.total_income and available_balance, and it '
            . 'moved them for the fallback tenant');
    }

    public function test_approve_withdrawal_approves_nothing_for_the_fallback_store(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00, 50.00);
        $withdrawal = $this->withdrawal($this->storeOne, $wallet, 'pending', 50.00);

        $this->assertSame('pending', $this->withdrawalStatusOf($withdrawal->id),
            'premise: the fixture withdrawal is not pending, so approving it is a no-op');

        $response = $this->actingAs($this->operator)
            ->post(route('finance.withdrawals.approve', $withdrawal->id));

        $this->assertRefusedAsUnselectedOperator($response, 'approveWithdrawal');
        $this->assertSame('pending', $this->withdrawalStatusOf($withdrawal->id),
            'another merchant\'s withdrawal was APPROVED by an operator who named no merchant');
    }

    public function test_reject_withdrawal_rejects_nothing_for_the_fallback_store(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00, 50.00);
        $withdrawal = $this->withdrawal($this->storeOne, $wallet, 'pending', 50.00);

        $this->assertSame('100.00', $this->availableOf($wallet->id), 'premise: fixture available');
        $this->assertSame('50.00', $this->pendingOf($wallet->id), 'premise: fixture pending');

        $response = $this->actingAs($this->operator)
            ->post(route('finance.withdrawals.reject', $withdrawal->id), ['admin_note' => 'no']);

        $this->assertRefusedAsUnselectedOperator($response, 'rejectWithdrawal');
        $this->assertSame('pending', $this->withdrawalStatusOf($withdrawal->id),
            'another merchant\'s withdrawal was REJECTED by an operator who named no merchant');
        $this->assertSame('100.00', $this->availableOf($wallet->id),
            'the rejection restored 50.00 to another merchant\'s available balance');
        $this->assertSame('50.00', $this->pendingOf($wallet->id),
            'the rejection released another merchant\'s withdrawal_pending_balance');
    }

    public function test_pay_withdrawal_pays_nothing_for_the_fallback_store(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00, 50.00);
        $withdrawal = $this->withdrawal($this->storeOne, $wallet, 'approved', 50.00);

        $this->assertSame('approved', $this->withdrawalStatusOf($withdrawal->id),
            'premise: the fixture withdrawal is not approved, so payWithdrawal refuses on STATE '
            . 'and the refusal below would not be the store guard');
        $this->assertSame('50.00', $this->pendingOf($wallet->id), 'premise: fixture pending');

        $response = $this->actingAs($this->operator)
            ->post(route('finance.withdrawals.pay', $withdrawal->id));

        $this->assertRefusedAsUnselectedOperator($response, 'payWithdrawal');
        $this->assertSame('approved', $this->withdrawalStatusOf($withdrawal->id),
            'another merchant\'s withdrawal was MARKED PAID by an operator who named no merchant');
        $this->assertSame('50.00', $this->pendingOf($wallet->id),
            'another merchant\'s withdrawal_pending_balance was drawn down');
    }

    // =============================================================================================
    //  3. THE HARD CASE -- an EXPLICIT 1 must still work while a fallback 1 must not
    // =============================================================================================

    public function test_an_operator_who_explicitly_selected_store_one_keeps_every_authorized_write(): void
    {
        $chose = $this->operatorWhoChose(1);

        $this->assertSame(1, (int) $chose->current_store, 'premise: the selection did not persist');
        $this->assertSame(1, (int) getCurrentStore($chose->id),
            'premise: an explicit choice of store 1 no longer resolves store 1');

        // 3a. index() still performs the lazy wallet create for the chosen store.
        $this->assertSame(0, DB::table('store_wallets')->count(), 'premise: no wallet yet');

        $index = $this->actingAs($chose)->get(route('finance.index'));

        $this->assertNotSame(404, $index->getStatusCode(), 'premise: /finance did not resolve');
        $this->assertNotSame(__('No store is selected.'), session('error'),
            'THE GUARD REFUSED AN EXPLICIT CHOICE OF STORE 1. This is the owner\'s second '
            . 'requirement and the single thing this change could most easily get wrong.');
        $this->assertSame(1, DB::table('store_wallets')->where('store_id', 1)->count(),
            'the lazy wallet create no longer runs for an operator who chose store 1');

        $walletId = (int) DB::table('store_wallets')->where('store_id', 1)->value('id');

        // 3b. addBalance still credits it, with the arithmetic untouched.
        $add = $this->actingAs($chose)->post(route('finance.add.balance'), [
            'amount' => '77.00',
            'description' => 'authorized operator credit on an explicitly chosen store 1',
        ]);

        $this->assertNotSame(__('No store is selected.'), session('error'),
            'addBalance refused an operator who explicitly selected store 1');
        $this->assertSame(__('Balance added successfully.'), session('success'),
            'addBalance did not complete for an operator who explicitly selected store 1 -- '
            . 'error was: ' . var_export(session('error'), true));
        $this->assertSame('77.00', $this->availableOf($walletId),
            'the credit arithmetic changed: 0.00 + 77.00 must be exactly 77.00');
        $this->assertSame(1, DB::table('store_wallet_transactions')
            ->where('store_id', 1)->where('type', 'manual_deposit')->count(),
            'the ledger row for an authorized operator credit is gone');

        // 3c. deductBalance still debits it.
        $deduct = $this->actingAs($chose)->post(route('finance.deduct.balance'), [
            'amount' => '20.00',
            'description' => 'authorized operator debit on an explicitly chosen store 1',
        ]);

        $this->assertSame(__('Balance deducted successfully.'), session('success'),
            'deductBalance did not complete for an operator who explicitly selected store 1 -- '
            . 'error was: ' . var_export(session('error'), true));
        $this->assertSame('57.00', $this->availableOf($walletId),
            'the debit arithmetic changed: 77.00 - 20.00 must be exactly 57.00');

        // 3d. requestWithdrawal still raises one and still moves the balance the same way.
        $request = $this->actingAs($chose)->post(route('finance.withdrawal.request'), [
            'amount' => '25.00',
            'payment_method' => 'bank',
            'payment_details' => 'IBAN SY00 0000',
        ]);

        $this->assertSame(__('Withdrawal request submitted successfully.'),
            session('success'),
            'requestWithdrawal did not complete for an operator who explicitly selected store 1 -- '
            . 'error was: ' . var_export(session('error'), true));
        $this->assertSame('32.00', $this->availableOf($walletId),
            'the withdrawal arithmetic changed: 57.00 - 25.00 must be exactly 32.00');
        $this->assertSame('25.00', $this->pendingOf($walletId),
            'the withdrawal did not move 25.00 into withdrawal_pending_balance');

        $withdrawalId = (int) DB::table('store_withdrawals')->where('store_id', 1)->value('id');
        $this->assertNotSame(0, $withdrawalId, 'no withdrawal row was created for store 1');

        // 3e. approveWithdrawal still approves it.
        $approve = $this->actingAs($chose)
            ->post(route('finance.withdrawals.approve', $withdrawalId));

        $this->assertSame(__('Withdrawal approved.'), session('success'),
            'approveWithdrawal did not complete for an operator who explicitly selected store 1 -- '
            . 'error was: ' . var_export(session('error'), true));
        $this->assertSame('approved', $this->withdrawalStatusOf($withdrawalId),
            'the withdrawal was not approved');

        // 3f. payWithdrawal still pays it, and the money still lands the same way.
        $pay = $this->actingAs($chose)->post(route('finance.withdrawals.pay', $withdrawalId));

        $this->assertSame(__('Withdrawal marked as paid.'), session('success'),
            'payWithdrawal did not complete for an operator who explicitly selected store 1 -- '
            . 'error was: ' . var_export(session('error'), true));
        $this->assertSame('paid', $this->withdrawalStatusOf($withdrawalId),
            'the withdrawal was not paid');
        $this->assertSame('0.00', $this->pendingOf($walletId),
            'paying the withdrawal must draw withdrawal_pending_balance down to exactly 0.00');
        $this->assertSame('32.00', $this->availableOf($walletId),
            'paying a withdrawal must not touch available_balance: it must stay exactly 32.00');
    }

    public function test_an_operator_who_explicitly_selected_store_one_can_still_reject_and_approve_a_payment(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00, 50.00);
        $withdrawal = $this->withdrawal($this->storeOne, $wallet, 'pending', 50.00);
        $financialId = $this->financial($this->storeOne, $wallet);

        $chose = $this->operatorWhoChose(1);
        $this->assertSame(1, (int) getCurrentStore($chose->id), 'premise: store 1 not resolved');

        $reject = $this->actingAs($chose)
            ->post(route('finance.withdrawals.reject', $withdrawal->id), ['admin_note' => 'no']);

        $this->assertNotSame(__('No store is selected.'), session('error'),
            'rejectWithdrawal refused an operator who explicitly selected store 1');
        $this->assertSame('rejected', $this->withdrawalStatusOf($withdrawal->id),
            'rejectWithdrawal no longer rejects for an authorized operator -- error was: '
            . var_export(session('error'), true));
        $this->assertSame('150.00', $this->availableOf($wallet->id),
            'the rejection arithmetic changed: 100.00 + 50.00 restored must be exactly 150.00');
        $this->assertSame('0.00', $this->pendingOf($wallet->id),
            'the rejection must release withdrawal_pending_balance to exactly 0.00');

        $status = $this->actingAs($chose)
            ->post(route('finance.order-financial.status', $financialId), [
                'payment_status' => 'paid',
            ]);

        $this->assertNotSame(__('No store is selected.'), session('error'),
            'updateFinancialPaymentStatus refused an operator who explicitly selected store 1');
        $this->assertSame('paid', $this->statusOf($financialId),
            'an authorized operator can no longer approve a payment on their chosen store -- '
            . 'error was: ' . var_export(session('error'), true));
    }

    // =============================================================================================
    //  3b. NO LOCKOUT -- the refusal must leave a way out, and that is measured, not assumed
    // =============================================================================================

    /**
     * THE REFUSAL MUST NOT STRAND THE OPERATOR, which is the one way a guard like this turns into
     * the defect it was meant to prevent. `App\Http\Middleware\FinancePermission` records a real
     * lockout happening in this very controller -- six finance names gated on while none was
     * seeded, so every finance mutator was 403 for every user INCLUDING the platform operator --
     * and "reversing those two limbs is a lockout, not a tightening" is the project skill's words.
     *
     * TWO LEGS, BOTH DRIVEN:
     *
     *   (a) THE LANDING PAGE IS A 500, AND THAT IS A CORRECTION TO THIS SLICE'S OWN FIRST DRAFT.
     *       The draft asserted `assertNotSame(500, ...)` on `/dashboard` and reasoned that because
     *       `getCurrentStore()` answers 1 for this operator the shared chrome resolves a real store
     *       and the page renders. MEASURED, AND THAT IS FALSE:
     *
     *         /dashboard  operator, no selection   -> 500
     *         /dashboard  operator, store selected -> 200
     *         /dashboard  merchant, store resolved -> 200
     *         /dashboard  merchant, no store       -> 500
     *
     *       AND THE CAUSE IS NOT THE ONE THE NULL-STORE SLICE RECORDED. That slice attributes the
     *       storeless `/dashboard` 500 to `partision/header.blade.php` handing a null store to
     *       `HomeController::getThemeUrl()`, which reads `$store->slug`. The exception actually
     *       raised here is
     *       `SQLSTATE[HY000] ... no such column: total_user (update "users" set "remember_token" =
     *       ..., "total_user" = 0, "total_orders" = 0, "total_plan" = 0 ...)`:
     *       `HomeController:224` assigns `$user['total_user'] = $user->countCompany()` on the
     *       no-store branch, and the next `remember_token` write flushes those three attributes --
     *       WHICH NO MIGRATION DECLARES (`grep -rln total_user database/migrations/` is empty).
     *       Undeclared columns, the same class as the missing `audit_logs` table that
     *       `SecurityTestCase` already documents. So on THIS engine the page cannot render for a
     *       principal who resolves no store, whatever the chrome does.
     *
     *       WHAT THAT MEANS FOR THIS GUARD, stated plainly rather than minimised: for an operator
     *       with no selection, `index()` used to answer a WORKING finance dashboard for store 1
     *       (200) and now answers 302 -> a 500. The write is prevented either way, which is what
     *       the owner asked for, but the refusal does not reach the eye. NO NEW TARGET IS INVENTED
     *       HERE: this controller's only three refusal targets are `dashboard`, `finance.index`
     *       (which now refuses this principal too and forwards to `dashboard`) and `back()` (a
     *       documented loop for a GET on this handler), so a landing page that works for this
     *       principal does not exist inside the decision's scope. It is REPORTED.
     *
     *       The 500 is asserted here as a MEASUREMENT, in the shape this repository already uses
     *       for a recorded open finding (`EloquentScopeBypassInventoryTest`'s "and that is an open
     *       finding" cases). WHOEVER FIXES THE CHROME OR DECLARES `users.total_user` SHOULD CHANGE
     *       THIS CASE rather than delete it.
     *
     *   (b) THE OPERATOR CAN FIX IT THEMSELVES AND THEN PROCEED, which is what makes this a gate
     *       and not a lockout. `POST /change-store/{id}` (`StoreController::changeStore`, route
     *       `change.store`) writes `users.current_store`, and for `type === 'super admin'` it
     *       applies NO `created_by` predicate -- so an operator can select any store. This case
     *       drives the whole remediation path end to end: refused, switch, succeed.
     */
    public function test_the_refusal_does_not_strand_the_operator(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00);

        // (a) refused at /finance, and the measured state of where it sends them.
        $refused = $this->actingAs($this->operator)->get(route('finance.index'));
        $this->assertRefusedAsUnselectedOperator($refused, 'index');
        $this->assertSame(route('dashboard'), $refused->headers->get('Location'),
            'the refusal no longer terminates at the dashboard, so the measurement below is about '
            . 'a page this guard no longer sends anybody to');

        $landing = $this->actingAs($this->operator)->get(route('dashboard'));
        $this->assertSame(500, $landing->getStatusCode(),
            'RECORDED OPEN FINDING, NOT A REQUIREMENT. /dashboard was measured as a 500 for a '
            . 'principal who resolves no store, because HomeController:224 sets users.total_user / '
            . 'total_orders / total_plan, which NO migration declares. If this now passes, the '
            . 'chrome or the schema has been repaired -- that is good news: change this assertion '
            . 'to assertNotSame(500, ...) and delete this note.');

        // THE SAME 500 FOR A STORELESS MERCHANT, so it is a shared-chrome / schema defect and not
        // something this operator guard introduced. Without this the case above would read as a
        // defect created here.
        $storelessMerchant = User::find($this->makeMerchant(['current_store' => null])->id);
        $this->assertSame(0, DB::table('stores')->where('created_by', $storelessMerchant->id)->count(),
            'premise: this merchant owns a store, so they are not storeless');
        $this->assertSame(500,
            $this->actingAs($storelessMerchant)->get(route('dashboard'))->getStatusCode(),
            'a storeless MERCHANT now renders /dashboard while the unselected OPERATOR does not. '
            . 'That would make the 500 above specific to this guard, which it is not supposed to '
            . 'be -- re-measure before trusting either case.');

        /*
         * (b) THE OPERATOR SWITCHES STORE THEMSELVES -- from a FRESHLY READ instance, and that is
         * not tidiness. Driving `/dashboard` above left `total_user`, `total_orders` and
         * `total_plan` SET ON THE IN-MEMORY `User`, because `HomeController:224` assigns them and
         * the failed save does not unset them. Reusing that instance makes
         * `StoreController::changeStore`'s `$user->update()` fail against the test schema, so
         * `current_store` silently stays null and this case reports a LOCKOUT THAT DOES NOT EXIST.
         * MEASURED both ways: same instance -> current_store NULL; fresh instance -> 1.
         *
         * Over HTTP every request rebuilds the principal from the session, so this is an artefact
         * of a test driving two requests through one object -- but it is worth naming, because any
         * test that opens /dashboard for a storeless principal and then saves that same User will
         * silently write nothing.
         */
        $polluted = array_intersect_key(
            $this->operator->getAttributes(),
            array_flip(['total_user', 'total_orders', 'total_plan'])
        );
        $this->assertNotSame([], $polluted,
            'premise: the /dashboard request no longer leaves undeclared attributes on the User '
            . 'instance, so the re-read below is no longer necessary -- simplify this case and '
            . 'delete the note above');

        $switch = $this->actingAs(User::find($this->operator->id))
            ->post(route('change.store', $this->storeOne->id));

        $this->assertNotSame(404, $switch->getStatusCode(),
            'premise: the store switcher route did not resolve, so the escape path is not being '
            . 'driven and this case proves nothing about a lockout');
        $this->assertNotSame(403, $switch->getStatusCode(),
            'the operator cannot select a store at all, so the refusal above IS a lockout');
        $this->assertSame(1, (int) User::find($this->operator->id)->current_store,
            'the switcher did not persist the operator\'s selection, so there is no way out of '
            . 'the refusal -- error was: ' . var_export(session('error'), true));

        $after = $this->actingAs(User::find($this->operator->id))
            ->post(route('finance.add.balance'), [
                'amount' => '9.00',
                'description' => 'credit after the operator switched store',
            ]);

        $this->assertSame(__('Balance added successfully.'), session('success'),
            'the operator still cannot credit after selecting a store, so the guard is a lockout '
            . 'rather than a gate -- error was: ' . var_export(session('error'), true)
            . ' status ' . $after->getStatusCode());
        $this->assertSame('109.00', $this->availableOf($wallet->id),
            'the credit after switching did not land: 100.00 + 9.00 must be exactly 109.00');
    }

    // =============================================================================================
    //  4. AN EXPLICIT CHOICE OF SOME OTHER STORE -- that tenant is used, and only that one
    // =============================================================================================

    public function test_an_operator_who_selected_store_two_acts_on_store_two_and_never_on_store_one(): void
    {
        $walletOne = $this->wallet($this->storeOne, 100.00);
        $walletTwo = $this->wallet($this->storeTwo, 10.00);

        $chose = $this->operatorWhoChose((int) $this->storeTwo->id);

        $this->assertSame((int) $this->storeTwo->id, (int) getCurrentStore($chose->id),
            'premise: an explicit choice of store 2 does not resolve store 2, so this case is not '
            . 'driving the tenant it claims to');
        $this->assertNotSame(1, (int) $this->storeTwo->id,
            'premise: store 2 IS store 1, so "store one untouched" is meaningless here');

        $response = $this->actingAs($chose)->post(route('finance.add.balance'), [
            'amount' => '5.00',
            'description' => 'credit on an explicitly chosen store 2',
        ]);

        $this->assertSame(__('Balance added successfully.'), session('success'),
            'the guard refused an operator who explicitly selected store 2 -- error was: '
            . var_export(session('error'), true));
        $this->assertSame('15.00', $this->availableOf($walletTwo->id),
            'the chosen tenant was not credited: 10.00 + 5.00 must be exactly 15.00');
        $this->assertSame('100.00', $this->availableOf($walletOne->id),
            'store 1 was credited although the operator chose store 2 -- the fallback is still '
            . 'reaching the write path');
        $this->assertSame(0, DB::table('store_wallet_transactions')->where('store_id', 1)->count(),
            'a ledger row landed on store 1 for a write aimed at store 2');
    }

    // =============================================================================================
    //  5. THE MERCHANT IS UNTOUCHED -- the guard must be about the operator and nobody else
    // =============================================================================================

    public function test_a_merchant_with_a_resolved_store_is_unaffected(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00);

        $this->assertSame('admin', $this->merchantOne->type,
            'premise: the merchant fixture is not type admin, so this is not the merchant path');
        $this->assertSame(1, (int) getCurrentStore($this->merchantOne->id),
            'premise: the merchant does not resolve their own store');

        $response = $this->actingAs($this->merchantOne)->post(route('finance.add.balance'), [
            'amount' => '30.00',
            'description' => 'merchant credit on their own store',
        ]);

        $this->assertSame(__('Balance added successfully.'), session('success'),
            'A MERCHANT WAS CAUGHT BY AN OPERATOR-ONLY GUARD -- error was: '
            . var_export(session('error'), true));
        $this->assertSame('130.00', $this->availableOf($wallet->id),
            'the merchant credit arithmetic changed: 100.00 + 30.00 must be exactly 130.00');
    }

    /**
     * A MERCHANT WHOSE `current_store` IS EMPTY IS STILL THE SIBLING SLICE'S CASE, NOT MINE.
     *
     * `getCurrentStore()` falls through to `Store::where('created_by', $user->id)->first()` for a
     * non-operator, so a merchant who owns a store resolves it WITHOUT an explicit selection --
     * and that is a legitimate implicit resolution of their own single tenant, which the owner's
     * "normal merchant -> unchanged" requires this guard to leave alone.
     */
    public function test_a_merchant_with_no_selection_still_resolves_their_own_store_and_is_not_refused(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00);

        $this->merchantOne->current_store = null;
        $this->merchantOne->save();
        $merchant = User::find($this->merchantOne->id);

        $this->assertEmpty($merchant->current_store, 'premise: the selection was not cleared');
        $this->assertSame(1, (int) getCurrentStore($merchant->id),
            'premise: a merchant with no selection no longer falls through to their own store, so '
            . 'this case is not the one the docblock describes');

        $response = $this->actingAs($merchant)->post(route('finance.add.balance'), [
            'amount' => '11.00',
            'description' => 'merchant credit with no explicit selection',
        ]);

        $this->assertSame(__('Balance added successfully.'), session('success'),
            'the operator guard caught a MERCHANT whose store resolved implicitly from ownership. '
            . 'That is the "normal merchant -> unchanged" requirement broken -- error was: '
            . var_export(session('error'), true));
        $this->assertSame('111.00', $this->availableOf($wallet->id),
            'the merchant credit did not land');
    }

    // =============================================================================================
    //  6. CROSS-TENANT -- still denied, and the guard must not have replaced that check
    // =============================================================================================

    public function test_an_operator_who_selected_store_two_cannot_act_on_store_ones_withdrawal(): void
    {
        $walletOne = $this->wallet($this->storeOne, 100.00, 50.00);
        $withdrawalOne = $this->withdrawal($this->storeOne, $walletOne, 'pending', 50.00);
        $this->wallet($this->storeTwo, 10.00);

        $chose = $this->operatorWhoChose((int) $this->storeTwo->id);

        $this->assertSame((int) $this->storeTwo->id, (int) getCurrentStore($chose->id),
            'premise: the operator does not resolve store 2, so this is not a cross-tenant attempt');
        $this->assertSame('pending', $this->withdrawalStatusOf($withdrawalOne->id),
            'premise: store 1\'s withdrawal is not pending, so approving it is a no-op');

        $response = $this->actingAs($chose)
            ->post(route('finance.withdrawals.approve', $withdrawalOne->id));

        $this->assertNotSame(500, $response->getStatusCode(),
            'the handler threw rather than refusing, so an unchanged row proves nothing');
        $this->assertSame('pending', $this->withdrawalStatusOf($withdrawalOne->id),
            'AN OPERATOR WHO SELECTED STORE 2 APPROVED STORE 1\'S WITHDRAWAL. The existing '
            . '`->where(\'store_id\', $storeId)` predicate is the control here and this guard must '
            . 'not have displaced it.');
    }

    public function test_a_merchant_cannot_act_on_another_merchants_withdrawal(): void
    {
        $walletTwo = $this->wallet($this->storeTwo, 100.00, 50.00);
        $withdrawalTwo = $this->withdrawal($this->storeTwo, $walletTwo, 'pending', 50.00);

        $this->assertSame(1, (int) getCurrentStore($this->merchantOne->id),
            'premise: merchant one does not resolve store 1');
        $this->assertSame('pending', $this->withdrawalStatusOf($withdrawalTwo->id),
            'premise: store 2\'s withdrawal is not pending');

        $response = $this->actingAs($this->merchantOne)
            ->post(route('finance.withdrawals.approve', $withdrawalTwo->id));

        $this->assertNotSame(500, $response->getStatusCode(),
            'the handler threw rather than refusing');
        $this->assertSame('pending', $this->withdrawalStatusOf($withdrawalTwo->id),
            'merchant one approved merchant two\'s withdrawal');
    }

    // =============================================================================================
    //  7. WHAT IS DELIBERATELY NOT GUARDED -- recorded as a decision, not left as a silence
    // =============================================================================================

    /**
     * `unreconciledCaptures` ALREADY refuses this principal and this change does not touch it.
     * Its message is deliberately different, so this case also proves the two refusals are
     * distinguishable in the response rather than only in the source.
     */
    public function test_unreconciled_captures_still_refuses_the_unselected_operator_with_its_own_message(): void
    {
        $response = $this->actingAs($this->operator)
            ->getJson(route('finance.unreconciled.captures'));

        $this->assertSame(409, $response->getStatusCode(),
            'unreconciledCaptures no longer refuses the unselected operator. It is the in-repo '
            . 'precedent this guard was copied from; if it has changed, re-read why.');
        $this->assertSame('select a store first', $response->json('reason'),
            'the refusal is the no-store leg rather than the operator leg, so the precedent this '
            . 'guard follows is not the one being measured');
    }

    /**
     * `withdrawals()` IS A READ AND IS NOT GUARDED. This case records the measured behaviour so
     * that the omission is visibly a decision: the owner enumerated WRITE paths, and refusing a
     * read would be the "unrelated operator workflow" change the decision excluded.
     *
     * IT IS NOT AN ENDORSEMENT. An unselected operator does see store 1's withdrawal list, which
     * is a cross-tenant disclosure of money data, and that is reported for an owner decision.
     * WHOEVER CLOSES IT SHOULD CHANGE THIS CASE, not delete it.
     */
    public function test_withdrawals_is_a_read_and_this_guard_deliberately_does_not_refuse_it(): void
    {
        $wallet = $this->wallet($this->storeOne, 100.00);
        $withdrawal = $this->withdrawal($this->storeOne, $wallet, 'pending', 50.00);

        $this->assertSame(1, DB::table('store_withdrawals')->where('store_id', 1)->count(),
            'premise: store 1 has no withdrawal, so "the list is served" is meaningless');

        $response = $this->actingAs($this->operator)->get(route('finance.withdrawals'));

        $this->assertNotSame(404, $response->getStatusCode(),
            'premise: /finance/withdrawals did not resolve, so nothing was driven');
        $this->assertNotSame(__('No store is selected.'), session('error'),
            'withdrawals() now refuses the unselected operator. That is a WIDER change than owner '
            . 'decision 7 authorised -- it is a read, not a write. If it was deliberate, say so '
            . 'here and record the owner decision that authorised it.');
        $this->assertSame('pending', $this->withdrawalStatusOf($withdrawal->id),
            'a GET changed a withdrawal\'s state');
    }

    /**
     * THE API TRIO RESOLVES ITS TENANT FROM AN EXPLICIT `store_id`, SO THE FALLBACK CANNOT REACH
     * IT -- asserted on the source rather than assumed, because the shipped Flutter admin posts
     * to these three and an HTML redirect there would be a user-visible regression.
     */
    public function test_the_three_api_handlers_do_not_resolve_their_tenant_through_the_helper(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Http/Controllers/StoreFinanceController.php')
        );

        foreach (['walletTransactionsApi', 'addBalanceApi', 'deductBalanceApi'] as $method) {
            $this->assertStringContainsString("function {$method}(", $source,
                "premise: {$method}() is gone from this controller, so this case scans nothing");
        }

        // The body of each API method, from its own signature to the next `public function`.
        foreach (['walletTransactionsApi', 'addBalanceApi', 'deductBalanceApi'] as $method) {
            $start = strpos($source, "function {$method}(");
            $rest = substr($source, $start);
            $end = strpos($rest, 'public function', 10);
            $body = $end === false ? $rest : substr($rest, 0, $end);

            $this->assertStringNotContainsString('getCurrentStore(', $body,
                "{$method}() now resolves its tenant through getCurrentStore(), so the hardcoded "
                . 'store-1 fallback reaches an endpoint the shipped Flutter admin calls -- and '
                . 'this guard answers an HTML redirect there, not the JSON envelope that client '
                . 'parses. Guard it with financeApiDenied() instead, and update this case.');
            $this->assertStringContainsString("query('store_id')", $body,
                "premise: {$method}() no longer reads an explicit store_id, so the argument that "
                . 'the fallback cannot reach it no longer holds');
        }
    }
}
