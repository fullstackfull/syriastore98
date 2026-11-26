<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\User;
use App\Services\OrderFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F032 — A STORE WITH NO WALLET LOSES EVERY ORDER'S CREDIT, PERMANENTLY AND SILENTLY.
 * =================================================================================================
 *
 * `OrderFinancialService::createForOrder()` opened with
 * `StoreWallet::where('store_id', ...)->lockForUpdate()->firstOrFail()`. A store whose
 * `store_wallets` row does not exist therefore throws `ModelNotFoundException` into
 * `OrderObserver::created()`'s catch. The order commits, **no `store_order_financials` row is
 * written at all**, and nothing on any path ever revisits it: the merchant's credit for that order
 * is gone.
 *
 * TWO WRITERS CREATE A STORE WITHOUT A WALLET. `Auth\RegisteredUserController::store` (two
 * `Store::create()` sites, :138 and :275, with no `StoreWallet` reference anywhere in the file) and
 * `Api\DashboardController::createStoreApi`. `StoreController` — the third writer — ALREADY
 * provisions one (`StoreWallet::firstOrCreate` at :179 and :336), which is what makes this a gap in
 * two specific doors rather than a missing feature.
 *
 * WHY NO TEST EVER NOTICED, and it is worth stating because it is a fact about the SUITE and not
 * about the code: `SecurityTestCase::makeStore()` creates no wallet either, so EVERY store in this
 * repository's test suite is wallet-less. The financial subsystem has been inert in every test run
 * that has ever happened here — each one silently swallowing the same exception. The premise case
 * below asserts exactly that, so the rest of this file cannot be read as testing something it isn't.
 *
 * THE FIX IS NOT `firstOrCreate`, AND THAT IS MEASURED, NOT REASONED.
 * -------------------------------------------------------------------
 * The decision package records that "`store_wallets` already carries
 * UNIQUE KEY uq_store_wallet_store_id (store_id), so `firstOrCreate` is race-safe at the database
 * level". That is true OUTSIDE a transaction and **false inside one**.
 * `tests/Support/Security/bin/verify-store-wallet-provisioning-on-mysql.php` measured it on
 * MariaDB 10.11.14 / InnoDB / REPEATABLE-READ:
 *
 *   - outside a transaction, `firstOrCreate` recovers from a lost race and returns the winner's row;
 *   - eight genuinely concurrent processes produce exactly one row and none dies;
 *   - **inside an open transaction that has already taken a read snapshot, it re-throws**, because
 *     its recovery is a plain consistent read served from a snapshot predating the winner's commit;
 *   - a `lockForUpdate()` re-read in that same shape DOES see the winner's row, because a locking
 *     read is a current read in InnoDB.
 *
 * `createForOrder()` wraps its whole body in `DB::transaction()` and reads before it provisions, so
 * it is the inside-a-transaction case. A defensive `firstOrCreate()` dropped into it would still
 * throw under concurrency and still lose the credit. SQLite serialises writers, so the suite could
 * not have found that — which is why the engine question is asked by a script on a real engine.
 *
 * WHAT IS DELIBERATELY NOT DONE HERE: no backfill, no credit of any existing order, no rewrite of
 * any historical row. Owner instruction: "Do not backfill or credit existing production orders
 * automatically." Recovery of already-lost credits is a separate, approval-gated, read-only
 * reconciliation question.
 *
 * @see \App\Services\StoreWalletProvisioning
 * @see \App\Services\OrderFinancialService::createForOrder()
 */
class StoreWalletProvisioningTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['store_wallets', 'store_order_financials', 'store_wallet_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped($table . ' is not in the test schema, so every assertion '
                    . 'in this file would be vacuous');
            }
        }
    }

    private function walletRows(int $storeId): int
    {
        return (int) DB::table('store_wallets')->where('store_id', $storeId)->count();
    }

    private function financialRows(int $orderId): int
    {
        return (int) DB::table('store_order_financials')->where('order_id', $orderId)->count();
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISE — what state the suite is actually in
    |--------------------------------------------------------------------------
    */

    /**
     * PREMISE: the fixture really does produce a wallet-less store.
     *
     * If this ever goes red because `makeStore()` started provisioning, the defect cases below stop
     * measuring anything and must be re-pointed at a store built without it.
     */
    public function test_premise_the_fixture_store_has_no_wallet(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $this->assertSame(0, $this->walletRows($store->id),
            'the test fixture now provisions a wallet, so this file no longer measures the defect '
            . 'it was written for');
    }

    /*
    |--------------------------------------------------------------------------
    | THE MONEY — the consequence, driven through the real service
    |--------------------------------------------------------------------------
    */

    /**
     * THE DEFECT: a paid order on a wallet-less store records NOTHING.
     *
     * Driven through `OrderFinancialService` directly rather than through the observer, so the
     * assertion is about the service's own behaviour and cannot be greened by an observer that
     * stopped firing.
     */
    public function test_a_paid_order_on_a_wallet_less_store_still_gets_a_financial_record(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        /*
         * EVENTS SUPPRESSED SO THE STORE IS GENUINELY WALLET-LESS WHEN THE SERVICE IS CALLED.
         *
         * `makeOrder()` fires `OrderObserver::created()`, which now provisions -- so without this
         * the premise below would be false and the case would be measuring a second call against
         * an already-provisioned store rather than the defect. The end-to-end observer path has
         * its own case below; this one is about the service in isolation.
         */
        $order = Order::withoutEvents(fn () => $this->makeOrder($store, [
            'payment_status' => 'Paid',
            'final_price' => 120.00,
            'product_price' => 120.00,
            'product_json' => '[]',
        ]));

        $this->assertSame(0, $this->walletRows($store->id), 'premise: no wallet before the call');
        $this->assertSame(0, $this->financialRows($order->id), 'premise: no financial row either');

        app(OrderFinancialService::class)->createForOrder($order);

        $this->assertSame(1, $this->financialRows($order->id),
            'the order produced no store_order_financials row, so the merchant\'s credit for it is '
            . 'lost permanently — nothing on any path revisits it');

        $this->assertSame(1, $this->walletRows($store->id),
            'no wallet was provisioned for the store');
    }

    /**
     * THE SAME THING THROUGH THE REAL OBSERVER PATH, which is how an order is actually written.
     *
     * The case above drives the service directly, so it would still pass if `OrderObserver` stopped
     * calling it. This one drives nothing but `Order::create()` and asserts the ledger appeared --
     * which is the behaviour a merchant depends on and the one that was silently absent.
     *
     * It is also the case that measures the SUITE-WIDE change: before this fix every order created
     * anywhere in this test suite swallowed a `ModelNotFoundException` here.
     */
    public function test_the_observer_path_provisions_and_records_end_to_end(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $order = $this->makeOrder($store, [
            'payment_status' => 'Paid',
            'final_price' => 90.00,
            'product_price' => 90.00,
            'product_json' => '[]',
        ]);

        $this->assertSame(1, $this->walletRows($store->id),
            'creating an order did not provision the store\'s wallet, so OrderObserver::created() '
            . 'is still swallowing the missing-wallet exception');

        $this->assertSame(1, $this->financialRows($order->id),
            'creating an order wrote no financial record through the observer path');

        $wallet = DB::table('store_wallets')->where('store_id', $store->id)->first();

        $this->assertEquals(90.00, (float) $wallet->available_balance,
            'the merchant was not credited for a paid order written the normal way');
    }

    /** And the credit actually lands on the wallet, once, for the right amount. */
    public function test_the_provisioned_wallet_is_credited_exactly_once_for_the_right_amount(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $order = $this->makeOrder($store, [
            'payment_status' => 'Paid',
            'final_price' => 120.00,
            'product_price' => 120.00,
            'product_json' => '[]',
        ]);

        app(OrderFinancialService::class)->createForOrder($order);

        $wallet = DB::table('store_wallets')->where('store_id', $store->id)->first();

        $this->assertNotNull($wallet, 'no wallet row to credit');

        $this->assertEquals(120.00, (float) $wallet->available_balance,
            'the provisioned wallet was not credited the order total');

        $this->assertSame(1,
            (int) DB::table('store_wallet_transactions')
                ->where('store_id', $store->id)
                ->where('reference_id', $order->id)
                ->where('type', 'order_paid')
                ->count(),
            'the credit was recorded more or less than once'
        );
    }

    /**
     * IDEMPOTENT: calling the service twice for the same order neither duplicates the financial
     * row nor double-credits the wallet.
     *
     * The existing `$exists` guard covers the financial row; this case holds the WALLET, which is
     * the half that moves money.
     */
    public function test_a_repeated_call_does_not_double_credit(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $order = $this->makeOrder($store, [
            'payment_status' => 'Paid',
            'final_price' => 120.00,
            'product_price' => 120.00,
            'product_json' => '[]',
        ]);

        app(OrderFinancialService::class)->createForOrder($order);
        app(OrderFinancialService::class)->createForOrder($order);

        $this->assertSame(1, $this->financialRows($order->id),
            'a repeated call wrote a second financial row');

        $this->assertSame(1, $this->walletRows($store->id),
            'a repeated call created a second wallet, which the UNIQUE key should make impossible');

        $wallet = DB::table('store_wallets')->where('store_id', $store->id)->first();

        $this->assertEquals(120.00, (float) $wallet->available_balance,
            'the merchant was credited twice for one order');
    }

    /**
     * AN UNPAID ORDER STILL MOVES NO MONEY, even though it now provisions a wallet.
     *
     * The case a provisioning change could plausibly break: creating the wallet must not also
     * start crediting orders that credit nothing today.
     */
    public function test_an_unpaid_order_provisions_the_wallet_but_credits_nothing(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $order = $this->makeOrder($store, [
            'payment_status' => 'Unpaid',
            'final_price' => 120.00,
            'product_price' => 120.00,
            'product_json' => '[]',
        ]);

        app(OrderFinancialService::class)->createForOrder($order);

        $this->assertSame(1, $this->financialRows($order->id),
            'an unpaid order should still get its financial record');

        $wallet = DB::table('store_wallets')->where('store_id', $store->id)->first();

        $this->assertEquals(0.00, (float) $wallet->available_balance,
            'an UNPAID order credited the merchant\'s wallet');
    }

    /*
    |--------------------------------------------------------------------------
    | THE TWO DOORS THAT CREATE A STORE WITHOUT ONE
    |--------------------------------------------------------------------------
    */

    /**
     * THE DEFECT, at the registration door.
     *
     * THE ROLE FIXTURE IS NOW PART OF THE PRECONDITION, and so is the reason. This case used to
     * rely on `Tests\Feature\Auth\RegistrationTest::test_new_users_can_register` being a DECLARED
     * failure in `.github/known-test-outcomes.json`: `roles` is empty under `RefreshDatabase`, the
     * merchant never got the `admin` role, and that entry's own message recorded that the request
     * "still returns 302 and the user, the store and the login are all committed" -- which is what
     * made this door drivable. GAP-014 (2026-11-25) closed that: a missing `admin` role now
     * REFUSES the registration before anything is written, precisely so a half-provisioned
     * merchant cannot survive, and the manifest entry is deleted. So this case seeds the role the
     * way `PermissionTableSeeder` does -- the production state -- and the door opens again.
     * Without it, `$user` below is null and the premise assertion says so.
     */
    public function test_registration_provisions_a_wallet_for_the_new_store(): void
    {
        \App\Models\Role::create(['name' => 'admin', 'created_by' => null]);

        $email = 'merchant-' . uniqid() . '@example.test';

        $this->post('/register', [
            'name' => 'New Merchant',
            'email' => $email,
            'password' => 'Password!2345',
            'password_confirmation' => 'Password!2345',
            'store_name' => 'Wallet Store ' . uniqid(),
        ]);

        $user = User::where('email', $email)->first();

        $this->assertNotNull($user,
            'registration did not create the user, so this case never reached the question');

        $store = Store::withoutGlobalScopes()->where('created_by', $user->id)->first();

        $this->assertNotNull($store,
            'registration did not create a store, so this case never reached the question');

        $this->assertSame(1, $this->walletRows($store->id),
            'a merchant who registered through the front door has a store with no wallet, so every '
            . 'order they ever take loses its credit silently');
    }

    /**
     * THE ORACLE: the third store writer already provisions.
     *
     * This is what makes the two cases above a gap in two specific doors rather than a claim that
     * the platform never provisions wallets. If this goes red, the gap has to be re-described.
     */
    public function test_oracle_the_store_controller_path_already_provisions(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/StoreController.php'));

        $this->assertStringContainsString('StoreWallet::firstOrCreate', $source,
            'StoreController no longer provisions a wallet, so the premise that this is a gap in '
            . 'the OTHER writers is no longer true');
    }

    /*
    |--------------------------------------------------------------------------
    | THE PROVISIONING HELPER ITSELF
    |--------------------------------------------------------------------------
    */

    /** Provisioning twice yields exactly one wallet. */
    public function test_provisioning_is_idempotent(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $first = app(\App\Services\StoreWalletProvisioning::class)->ensure((int) $store->id);
        $second = app(\App\Services\StoreWalletProvisioning::class)->ensure((int) $store->id);

        $this->assertSame(1, $this->walletRows($store->id),
            'provisioning twice created two wallets');

        $this->assertSame((int) $first->id, (int) $second->id,
            'the second call returned a different wallet row');
    }

    /** An existing wallet is returned untouched — balances are never reset by provisioning. */
    public function test_provisioning_never_resets_an_existing_balance(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        StoreWallet::create([
            'store_id' => $store->id,
            'currency_code' => 'USD',
            'available_balance' => 500.00,
            'pending_balance' => 0,
            'withdrawal_pending_balance' => 0,
            'total_income' => 500.00,
            'total_withdrawn' => 0,
        ]);

        app(\App\Services\StoreWalletProvisioning::class)->ensure((int) $store->id);

        $wallet = DB::table('store_wallets')->where('store_id', $store->id)->first();

        $this->assertEquals(500.00, (float) $wallet->available_balance,
            'provisioning reset a merchant\'s existing balance to zero — the single worst outcome '
            . 'this change could produce');
    }
}
