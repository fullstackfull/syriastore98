<?php

namespace Tests\Feature\Commerce;

use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F270 — a double submit credited the merchant's wallet twice, and the row lock did not stop it.
 * =================================================================================================
 *
 * `StoreFinanceController::addBalance()` and `deductBalance()` each wrap their work in
 * `DB::transaction()` around a `StoreWallet::where('store_id', $storeId)->lockForUpdate()
 * ->firstOrFail()`. That is correct, and it is not the problem. **A row lock SERIALISES two
 * writes; it does not make the second one redundant.** So two submits of the same manual deposit
 * took the lock one after the other and credited the wallet twice, leaving two `manual_deposit`
 * rows indistinguishable from two deliberate deposits.
 *
 * AND THE FORM INVITES IT. `finance/index.blade.php:466-487` is a plain `<form method="POST">`
 * with a submit button and no disable-on-submit, inside a Bootstrap modal. A double-click submits
 * twice. Nothing client-side would be a control anyway.
 *
 * THE API TWINS CARRY THE SAME DEFECT, and this file drives them too. `addBalanceApi()` and
 * `deductBalanceApi()` are `api/admin/wallet/add-balance` and `.../deduct-balance`, which the
 * SHIPPED Flutter admin posts to (`wallet_repository.dart:28-43`) from a bottom sheet whose button
 * has no in-flight guard either. Fixing the web pair and leaving these would have been half a fix.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TABLES ARE CREATED HERE, AND THAT IS NOT A SHORTCUT.
 *
 * `store_wallets` and `store_wallet_transactions` are GATE F tables: live code reads and writes
 * them, they exist in production with real rows, and NO migration declares them. The established
 * pattern for a test that needs them is a `markTestSkipped`, declared by name in
 * `.github/known-test-outcomes.json` — but a conditionally-skipped test cannot measure money
 * moving, and CLAUDE.md records that an undeclared skip breaks the known-outcome gate in both
 * directions.
 *
 * So this file builds the two tables itself, from the MEASURED production structure in
 * `database/migrations/_gate_f_pending/0001_…` and `0002_…` (reconciled 2026-10-24 against a
 * restored copy of the production dump). That promotes no migration, changes no schema anybody
 * deploys, and adds no skip to the gate's declared set — it just lets the test run for real.
 * `uq_store_wallet_store_id` is kept because "one wallet per store" is load-bearing for the
 * handlers under test.
 * -------------------------------------------------------------------------------------------------
 *
 * WHAT THIS FILE CANNOT SHOW: `phpunit.xml` sets `CACHE_STORE=array`, and `ArrayStore` has no
 * `add()`, so `Cache::add()` here is `Repository::add()` — a `get()` then a `put()`. These tests
 * prove the control's LOGIC. That the second of two SIMULTANEOUS callers loses because the
 * database said so is measured on real MariaDB by
 * `tests/Support/Security/bin/verify-pos-sale-claim-concurrency-on-mysql.php`, which exercises the
 * same `Cache::add()` primitive against the same `cache` primary key.
 */
class WalletChangeIdempotencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    private StoreWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->assertGateFWalletTablesAreMigrated();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = User::find($this->merchant->id);

        $this->wallet = StoreWallet::create([
            'store_id' => $this->store->id,
            'currency_code' => 'USD',
            'available_balance' => 500.00,
            'pending_balance' => 0,
            'withdrawal_pending_balance' => 0,
            'total_income' => 500.00,
            'total_withdrawn' => 0,
        ]);
    }

    /**
     * ASSERT THAT GATE F'S MIGRATION IS PRESENT. It does not create anything, and the name says so
     * since 2026-11-02 (it was `createGateFWalletTables`).
     *
     * WHAT USED TO BE HERE, AND WHY ITS REMOVAL IS THE POINT. This method built the table itself,
     * behind `if (! Schema::hasTable(...))`, because the table was declared by no migration. That
     * fallback is DELETED rather than left dead. It was a loaded gun: it declared COLUMNS ONLY,
     * with none of production's unique keys or foreign keys, so if the real migration were ever
     * reverted or renamed this suite would have gone GREEN against a fabricated money schema --
     * the exact failure GATE F exists to prevent. And `GateFSchemaContainmentTest` could not see
     * it, because a `Schema::create()` inside a test is not a discoverable migration.
     *
     * The structure now comes from the migration, measured against a restored copy of production
     * and recorded in `docs/GATE-F-VERIFIED-SCHEMA.md` section 2. A missing table is reported as a
     * missing table.
     */
    private function assertGateFWalletTablesAreMigrated(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('store_wallets'),
            '`store_wallets` is missing from a database built by this repository\'s migrations. '
            . 'GATE F declared it on 2026-11-02 with a structure measured against a restored '
            . 'copy of production, so this means the migration has been reverted or renamed. '
            . 'Do NOT answer this by creating the table here: a Schema::create() in a test is '
            . 'not a discoverable migration, carries none of production\'s unique keys or '
            . 'foreign keys, and would make this suite green against a fabricated money schema.'
        );

        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('store_wallet_transactions'),
            '`store_wallet_transactions` is missing from a database built by this repository\'s migrations. '
            . 'GATE F declared it on 2026-11-02 with a structure measured against a restored '
            . 'copy of production, so this means the migration has been reverted or renamed. '
            . 'Do NOT answer this by creating the table here: a Schema::create() in a test is '
            . 'not a discoverable migration, carries none of production\'s unique keys or '
            . 'foreign keys, and would make this suite green against a fabricated money schema.'
        );
    }

    // ---------------------------------------------------------------------------------------
    //  reading the money RAW -- StoreWallet is not Cachable, but a model read can still serve
    //  an in-memory copy a handler mutated inside this process
    // ---------------------------------------------------------------------------------------

    /**
     * FORMATTED, NOT CAST -- and the first version of this file compared the raw value.
     *
     * The column is `decimal(15,2)`, but the suite runs on SQLite, whose type affinity stores it
     * as a plain numeric and hands back `600` where MySQL hands back `'600.00'`. Every money
     * assertion in this file failed on the format while the handler was working perfectly. This
     * is the hazard CLAUDE.md records as "SQLite's type affinity hides real MySQL defects" --
     * here it manufactured a false RED rather than a false green, which is the better direction
     * but still a wrong reading.
     *
     * Formatting to two places before comparing is engine-independent and keeps the comparison
     * exact, which a float tolerance would not.
     */
    private function money($raw): string
    {
        return number_format((float) $raw, 2, '.', '');
    }

    private function balance(): string
    {
        return $this->money(
            DB::table('store_wallets')->where('id', $this->wallet->id)->value('available_balance')
        );
    }

    private function totalIncome(): string
    {
        return $this->money(
            DB::table('store_wallets')->where('id', $this->wallet->id)->value('total_income')
        );
    }

    private function ledgerRows(string $type): int
    {
        return (int) DB::table('store_wallet_transactions')
            ->where('wallet_id', $this->wallet->id)
            ->where('type', $type)
            ->count();
    }

    /** The operator, because the six Finance permissions are seeded by nothing. */
    private function financeActor(): User
    {
        /* `Gate::allows('finance', …)` admits a super admin without a seeded row; `isAbleTo()`
           refuses EVERY user, operator included, because Utility::addNewData() creates no
           permission row containing "Finance" (FinancePermissionTest pins both halves). So an
           ordinary merchant cannot reach these handlers at all on a database built from source,
           and a test that used one would be measuring a 403 rather than the claim. */
        $operator = $this->makeMerchant(['type' => 'super admin']);
        $operator->current_store = $this->store->id;
        $operator->save();

        return User::find($operator->id);
    }

    private function addBalance(User $as, string $amount = '100.00', string $description = 'Cash top-up')
    {
        return $this->actingAs($as)->post(route('finance.add.balance'), [
            'amount' => $amount,
            'description' => $description,
        ]);
    }

    private function deductBalance(User $as, string $amount = '100.00', string $description = 'Cash withdrawal')
    {
        return $this->actingAs($as)->post(route('finance.deduct.balance'), [
            'amount' => $amount,
            'description' => $description,
        ]);
    }

    // =========================================================================================
    //  1. THE POSITIVE CONTROL -- every refusal below is worthless without it
    // =========================================================================================

    public function test_a_single_manual_deposit_still_credits_the_wallet(): void
    {
        $response = $this->addBalance($this->financeActor());

        $this->assertNotSame(500, $response->getStatusCode(),
            'the deposit crashed: ' . substr((string) $response->getContent(), 0, 400));
        $this->assertSame('600.00', $this->balance(), 'a single manual deposit no longer credits');
        $this->assertSame('600.00', $this->totalIncome());
        $this->assertSame(1, $this->ledgerRows('manual_deposit'));
    }

    public function test_a_single_manual_deduction_still_debits_the_wallet(): void
    {
        $this->deductBalance($this->financeActor());

        $this->assertSame('400.00', $this->balance(), 'a single manual deduction no longer debits');
        $this->assertSame(1, $this->ledgerRows('manual_withdrawal'));
    }

    // =========================================================================================
    //  2. THE DEFECT
    // =========================================================================================

    /**
     * THE DOUBLE-CLICK. Two identical submits, one credit.
     *
     * Both requests carry the same amount and the same description, which is what a double-click
     * on an un-disabled submit button produces. The row lock makes them orderly; it does not make
     * the second one a no-op.
     */
    public function test_two_identical_deposits_credit_the_wallet_once(): void
    {
        $actor = $this->financeActor();

        $this->addBalance($actor);
        $this->assertSame('600.00', $this->balance(), 'premise: the first deposit did not land');

        $second = $this->addBalance($actor);

        $this->assertSame('600.00', $this->balance(),
            'a double submit credited the wallet TWICE. The lockForUpdate serialises the two '
            . 'writes but does not make the second redundant.');
        $this->assertSame('600.00', $this->totalIncome(),
            'total_income was credited twice even if available_balance was not');
        $this->assertSame(1, $this->ledgerRows('manual_deposit'),
            'two manual_deposit ledger rows exist for one deposit, which is indistinguishable '
            . 'from two deliberate deposits once the admin has moved on');

        $this->assertNotNull($second->getSession()->get('error'),
            'the refused submit said nothing, so the admin cannot tell whether their deposit '
            . 'landed once or not at all');
    }

    public function test_two_identical_deductions_debit_the_wallet_once(): void
    {
        $actor = $this->financeActor();

        $this->deductBalance($actor);
        $this->assertSame('400.00', $this->balance(), 'premise');

        $this->deductBalance($actor);

        $this->assertSame('400.00', $this->balance(),
            'a double submit debited the merchant twice');
        $this->assertSame(1, $this->ledgerRows('manual_withdrawal'));
    }

    // =========================================================================================
    //  3. THE CLAIM IS NOT A BLANKET REFUSAL
    // =========================================================================================

    public function test_a_different_amount_is_not_refused(): void
    {
        $actor = $this->financeActor();

        $this->addBalance($actor, '100.00');
        $this->addBalance($actor, '250.00');

        $this->assertSame('850.00', $this->balance(),
            'a genuinely different deposit was refused as a duplicate, which stops an admin '
            . 'working');
        $this->assertSame(2, $this->ledgerRows('manual_deposit'));
    }

    public function test_a_different_description_is_not_refused(): void
    {
        $actor = $this->financeActor();

        $this->addBalance($actor, '100.00', 'Cash top-up');
        $this->addBalance($actor, '100.00', 'Refund correction for order 1182');

        $this->assertSame('700.00', $this->balance());
        $this->assertSame(2, $this->ledgerRows('manual_deposit'));
    }

    /**
     * A CREDIT AND A DEBIT OF THE SAME FIGURE ARE NOT THE SAME CHANGE. The direction is part of
     * the fingerprint, so these must not collide — and if they did, correcting a mistaken deposit
     * by deducting the same amount would be silently refused.
     */
    public function test_a_credit_and_a_debit_of_the_same_amount_do_not_collide(): void
    {
        $actor = $this->financeActor();

        $this->addBalance($actor, '100.00', 'Adjustment');
        $this->assertSame('600.00', $this->balance(), 'premise');

        $this->deductBalance($actor, '100.00', 'Adjustment');

        $this->assertSame('500.00', $this->balance(),
            'the correcting deduction was refused as a duplicate of the deposit it corrects');
        $this->assertSame(1, $this->ledgerRows('manual_deposit'));
        $this->assertSame(1, $this->ledgerRows('manual_withdrawal'));
    }

    /**
     * AND THE AMOUNT IS CANONICALISED, so `100`, `100.00` and `100.000` are ONE deposit rather
     * than three. Without that, the guard is trivially bypassed by a form that formats differently
     * — or by a client that does.
     */
    public function test_the_same_amount_written_differently_is_still_one_deposit(): void
    {
        $actor = $this->financeActor();

        $this->addBalance($actor, '100', 'Cash top-up');
        $this->addBalance($actor, '100.00', 'Cash top-up');
        $this->addBalance($actor, '100.000', 'Cash top-up');

        $this->assertSame('600.00', $this->balance(),
            'the same deposit written with different precision was accepted more than once, so '
            . 'the fingerprint can be defeated by formatting alone');
        $this->assertSame(1, $this->ledgerRows('manual_deposit'));
    }

    // =========================================================================================
    //  4. THE CLAIM IS RELEASED ON A PATH THAT WROTE NOTHING
    // =========================================================================================

    /**
     * A REFUSED DEDUCTION MUST NOT BLOCK THE CORRECTED ONE.
     *
     * "Insufficient wallet balance." is thrown inside the transaction, which rolls back, so
     * nothing was written. An admin who tops the wallet up and retries the SAME figure must be
     * allowed through — otherwise the guard converts a recoverable mistake into a minute of
     * inexplicable refusals.
     */
    public function test_a_deduction_that_failed_for_insufficient_funds_can_be_retried(): void
    {
        $actor = $this->financeActor();

        $this->deductBalance($actor, '900.00', 'Payout');
        $this->assertSame('500.00', $this->balance(),
            'premise: the over-large deduction was supposed to be refused, not applied');
        $this->assertSame(0, $this->ledgerRows('manual_withdrawal'), 'premise: nothing was written');

        /* The wallet is topped up out of band, then the SAME deduction is retried. */
        DB::table('store_wallets')->where('id', $this->wallet->id)->update(['available_balance' => 1000.00]);

        $this->deductBalance($actor, '900.00', 'Payout');

        $this->assertSame('100.00', $this->balance(),
            'the retry of a deduction that had written nothing was refused as a duplicate, so '
            . 'the claim is being held on a path that wrote nothing');
        $this->assertSame(1, $this->ledgerRows('manual_withdrawal'));
    }

    // =========================================================================================
    //  5. ONE TENANT'S CLAIM DOES NOT BLOCK ANOTHER'S
    // =========================================================================================

    public function test_another_stores_identical_deposit_is_not_refused(): void
    {
        $actorA = $this->financeActor();
        $this->addBalance($actorA, '100.00', 'Cash top-up');
        $this->assertSame('600.00', $this->balance(), 'premise');

        $merchantB = $this->makeMerchant();
        $storeB = $this->makeStore($merchantB, ['default_language' => 'en']);
        $walletB = StoreWallet::create([
            'store_id' => $storeB->id,
            'currency_code' => 'USD',
            'available_balance' => 0,
            'pending_balance' => 0,
            'withdrawal_pending_balance' => 0,
            'total_income' => 0,
            'total_withdrawn' => 0,
        ]);

        $actorB = $this->makeMerchant(['type' => 'super admin']);
        $actorB->current_store = $storeB->id;
        $actorB->save();

        $this->addBalance(User::find($actorB->id), '100.00', 'Cash top-up');

        $this->assertSame(
            '100.00',
            $this->money(DB::table('store_wallets')->where('id', $walletB->id)->value('available_balance')),
            'store B\'s identical deposit was refused as a duplicate of store A\'s, so one '
            . 'tenant\'s claim is blocking another\'s'
        );
    }

    // =========================================================================================
    //  6. THE API TWINS -- the surface the Flutter admin posts to
    // =========================================================================================

    /**
     * A BEARER TOKEN, NOT `actingAs()` — and the first version of this file used `actingAs()` and
     * got a flat 401 on every API case.
     *
     * These routes carry `custom.auth` (`routes/api.php:380,383`), which resolves a SANCTUM TOKEN
     * and logs its owner into whichever guard matches the tokenable class. The `web` session
     * `actingAs()` establishes is invisible to it. A 401 would have satisfied every "the balance
     * moved once" assertion below for free, which is why each API case asserts its first post
     * SUCCEEDED before measuring anything.
     */
    private function apiAs(User $as)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $as->createToken('wallet-test')->plainTextToken
        );
    }

    private function addBalanceApi(User $as, string $amount = '100.00', string $description = 'Cash top-up')
    {
        return $this->apiAs($as)->postJson(
            '/api/admin/wallet/add-balance?store_id=' . $this->store->id,
            ['amount' => $amount, 'description' => $description]
        );
    }

    private function deductBalanceApi(User $as, string $amount = '100.00', string $description = 'Cash withdrawal')
    {
        return $this->apiAs($as)->postJson(
            '/api/admin/wallet/deduct-balance?store_id=' . $this->store->id,
            ['amount' => $amount, 'description' => $description]
        );
    }

    public function test_the_api_credits_once_for_two_identical_posts(): void
    {
        $actor = $this->financeActor();

        $first = $this->addBalanceApi($actor);

        /* THE PREMISE. These endpoints are `custom.auth`-gated and GATE F tables; a 401, 403 or
           500 would satisfy every "the balance moved once" assertion below for free. */
        $this->assertSame(1, (int) $first->json('status'),
            'the API deposit did not succeed, so nothing below measures the claim. Status '
            . $first->getStatusCode() . ': ' . substr((string) $first->getContent(), 0, 400));
        $this->assertSame('600.00', $this->balance(), 'premise: the first API deposit did not land');

        $second = $this->addBalanceApi($actor);

        $this->assertSame('600.00', $this->balance(),
            'two identical API posts credited the wallet twice. The Flutter admin posts here '
            . 'from a bottom sheet with no in-flight guard.');
        $this->assertSame(1, $this->ledgerRows('manual_deposit'));

        /* 409, AND THE MESSAGE HAS TO BE WHERE THE FLUTTER CLIENT LOOKS. Its error parser prefers
           `data.message` and falls back to `message` (network.dart:242-252), and 409 lands in the
           parser's `default` arm -- so it neither logs the merchant out (401) nor arms the
           rate-limit backoff (429). */
        $this->assertSame(409, $second->getStatusCode(),
            'the duplicate API post was not answered with a status the client treats as a '
            . 'failure: ' . substr((string) $second->getContent(), 0, 300));
        $this->assertSame(0, (int) $second->json('status'));
        $this->assertNotEmpty($second->json('data.message'),
            'the refusal carries no `data.message`, which is the first key the Flutter error '
            . 'parser reads, so the merchant would see a generic toast instead of the reason');
    }

    public function test_the_api_deduction_debits_once_for_two_identical_posts(): void
    {
        $actor = $this->financeActor();

        $first = $this->deductBalanceApi($actor);
        $this->assertSame(1, (int) $first->json('status'),
            'the API deduction did not succeed, so nothing below measures the claim. Status '
            . $first->getStatusCode() . ': ' . substr((string) $first->getContent(), 0, 400));
        $this->assertSame('400.00', $this->balance(), 'premise');

        $second = $this->deductBalanceApi($actor);

        $this->assertSame('400.00', $this->balance(),
            'two identical API posts debited the merchant twice');
        $this->assertSame(1, $this->ledgerRows('manual_withdrawal'));
        $this->assertSame(409, $second->getStatusCode());
    }

    /**
     * AND THE CLAIM IS TAKEN AFTER THE OWNERSHIP CHECK, which is what stops it being used AS an
     * attack. `$storeId` arrives as `?store_id=` — caller-supplied. If the claim were taken
     * before the store was tied to the caller, any authenticated principal could name another
     * store's id and sit on its claim for a minute, blocking that merchant's own deposits.
     */
    public function test_a_foreign_store_id_cannot_sit_on_another_stores_claim(): void
    {
        $outsider = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsider, ['default_language' => 'en']);
        $outsider->current_store = $outsiderStore->id;
        $outsider->save();

        $refused = $this->apiAs(User::find($outsider->id))->postJson(
            '/api/admin/wallet/add-balance?store_id=' . $this->store->id,
            ['amount' => '100.00', 'description' => 'Cash top-up']
        );

        $this->assertNotSame(1, (int) $refused->json('status'),
            'an outsider credited another store\'s wallet');
        $this->assertSame('500.00', $this->balance(), 'the outsider moved the victim\'s balance');

        /* THE FINDING: the victim's own deposit of the same shape must still go through. If the
           outsider's refused attempt had taken the claim, this would be refused for a minute. */
        $owner = $this->financeActor();
        $this->addBalance($owner, '100.00', 'Cash top-up');

        $this->assertSame('600.00', $this->balance(),
            'the outsider\'s REFUSED attempt took the victim\'s claim, so naming another '
            . 'store\'s id is now a denial-of-service against that store\'s wallet');
    }

    // =========================================================================================
    //  7. A PRINCIPAL WHO RESOLVES NO STORE
    // =========================================================================================

    /**
     * `where('store_id', null)` compiles to `store_id IS NULL`, so a principal who resolves no
     * store would either fail on `firstOrFail()` or, if a null-store wallet row existed, credit
     * THAT row.
     *
     * FINDING THE PRINCIPAL AT ALL TOOK TWO TRIES, and the first was a contradiction. The Finance
     * gate admits a super admin without any seeded row — but `getCurrentStore()` returns the
     * hard-coded `1` for type `super admin` (helper.php:772-774), so a super admin can never
     * resolve no store. The reachable combination is the gate's OTHER limb: a plain user holding
     * a real `Add Finance Balance` permission row, owning no store and with `current_store` null.
     * `FinancePermissionTest` mints that row for the same reason — production seeds none of the
     * six Finance names, so the row has to be created to reach the delegated branch at all.
     */
    public function test_a_principal_who_resolves_no_store_changes_no_wallet(): void
    {
        $staff = $this->makeStaff($this->merchant, ['current_store' => null]);
        $this->grantPermission(User::find($staff->id), 'Add Finance Balance');
        $staff = User::find($staff->id);

        $this->assertNull(getCurrentStore($staff->id), 'premise: this principal still resolves a store');
        $this->assertTrue($staff->isAbleTo('Add Finance Balance'),
            'premise: this principal cannot reach the handler at all, so a refusal below would '
            . 'be the permission gate rather than the store guard');

        /*
         * AND THE NULL-STORE WALLET ROW CANNOT EXIST, so this test does NOT claim to stop a
         * cross-tenant credit — the first version of it tried to plant such a row and hit
         * `NOT NULL constraint failed: store_wallets.store_id`. Production declares
         * `store_id` NOT NULL (the measured structure in `_gate_f_pending/0001_…`), so the
         * `IS NULL` predicate finds nothing and `firstOrFail()` would simply throw.
         *
         * What the guard really buys, therefore, is two narrower things, and they are what is
         * asserted: the handler REFUSES rather than throwing a `ModelNotFoundException` out of a
         * money path, and the fingerprint is never keyed on an empty store — which would have put
         * every storeless principal onto ONE shared claim, where any of them could block the
         * others.
         */
        $response = $this->addBalance($staff);

        $this->assertNotSame(500, $response->getStatusCode(),
            'the handler threw out of a money path rather than refusing: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(__('No store is selected.'), $response->getSession()->get('error'),
            'the refusal is not the store guard, so this test is measuring some other refusal');
        $this->assertSame('500.00', $this->balance(), 'a storeless principal moved a wallet');
        $this->assertSame(0, StoreWalletTransaction::count(), 'a ledger row was written for no store');
    }
}
