<?php

namespace App\Services;

use App\Models\StoreWallet;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * ONE PLACE THAT CREATES A `store_wallets` ROW.  P4-F032.
 * =================================================================================================
 *
 * WHY THIS EXISTS AT ALL. Before it there were three different spellings of "make sure this store
 * has a wallet", in three files, and two doors that forgot entirely:
 *
 *   - `StoreController` :179 and :336 — `StoreWallet::firstOrCreate(..., ['currency_code' => 'USD', ...])`
 *   - `StoreFinanceController` :738, :1956, :2189 — a `first()` then a bare `StoreWallet::create()`
 *     with the STORE's currency, lazily, the first time a merchant opens `/finance`
 *   - `Auth\RegisteredUserController::store` and `Api\DashboardController::createStoreApi` — nothing
 *
 * A merchant who arrived through either of those last two doors had a store with no wallet, so
 * `OrderFinancialService::createForOrder()`'s `firstOrFail()` threw into
 * `OrderObserver::created()`'s catch and EVERY order they ever took lost its credit, silently and
 * permanently.
 *
 * THE RECOVERY SHAPE IS MEASURED, NOT ASSUMED, AND IT IS NOT `firstOrCreate`.
 * --------------------------------------------------------------------------
 * `tests/Support/Security/bin/verify-store-wallet-provisioning-on-mysql.php`, on
 * MariaDB 10.11.14 / InnoDB / REPEATABLE-READ:
 *
 *   ok    firstOrCreate outside a transaction returns the winner's row instead of throwing
 *   ok    8 concurrent processes produced exactly one wallet row
 *   ok    firstOrCreate INSIDE an open transaction with a prior read snapshot THROWS
 *   ok    a lockForUpdate() re-read DOES see the winner's row inside the same transaction
 *
 * Laravel's `firstOrCreate()` delegates to `createOrFirst()`, whose recovery from a lost race is a
 * PLAIN consistent read (Builder.php:631-638). Under REPEATABLE READ that read is served from the
 * snapshot the transaction took at its first read, which predates the winner's commit — so it finds
 * nothing and re-throws. `createForOrder()` runs entirely inside `DB::transaction()` and reads
 * before it would provision, so it is exactly that case: a defensive `firstOrCreate` there would
 * still throw, still land in the observer's catch, and still lose the credit.
 *
 * A LOCKING read is a *current* read in InnoDB — it must lock a row that really exists, so it sees
 * the latest committed version regardless of the snapshot. Hence: locking read, then insert, then
 * on a unique violation a locking RE-read. That shape is correct both inside and outside a
 * transaction, which is why there is one method rather than two.
 *
 * SQLITE CANNOT TELL THESE APART. It serialises writers, so the wrong implementation is green on
 * the suite. That is why the question is settled by a script on a real engine.
 *
 * THIS CLASS OPENS NO TRANSACTION OF ITS OWN. Its callers are already inside one (the order write)
 * or do not need one (store creation), and wrapping would nest a pointless savepoint around a
 * single insert.
 *
 * IT NEVER TOUCHES AN EXISTING WALLET. If a row is there it is returned as-is: no balance is
 * written, reset or defaulted. `StoreWalletProvisioningTest::test_provisioning_never_resets_an_existing_balance`
 * holds that, because resetting a live merchant balance to zero is the worst thing this code could
 * possibly do.
 *
 * IT BACKFILLS NOTHING. Provisioning a wallet today does not credit a single order taken before
 * today. Owner instruction: "Do not backfill or credit existing production orders automatically."
 * Recovering already-lost credits is a separate, approval-gated, read-only reconciliation.
 */
class StoreWalletProvisioning
{
    /**
     * The currency a brand-new wallet is opened in when the caller does not name one.
     *
     * 'USD' because that is what `StoreController` has always used ("Create store wallet in USD"),
     * and a store-creation call that suddenly opened wallets in a different currency would change
     * what `OrderFinancialService` converts on every subsequent order.
     *
     * `StoreFinanceController` uses the STORE's currency instead, so the two existing shapes
     * already disagree. That disagreement is PRE-EXISTING and is deliberately not resolved here:
     * picking a side would silently re-denominate somebody's wallet. Callers that know which one
     * they want pass it explicitly — `OrderFinancialService` does, so a wallet it opens needs no
     * conversion on the very order that opened it.
     */
    public const DEFAULT_CURRENCY = 'USD';

    /**
     * Return this store's wallet, creating it if it does not exist yet.
     *
     * Safe to call inside an open transaction and outside one — see the class docblock for the
     * measurement that makes that true.
     *
     * @param  int          $storeId
     * @param  string|null  $currencyCode  the currency to OPEN a new wallet in; ignored entirely
     *                                     when a wallet already exists.
     */
    public function ensure(int $storeId, ?string $currencyCode = null): StoreWallet
    {
        /*
         * A LOCKING read, not a plain one, and not only for the race.
         *
         * Callers inside `DB::transaction()` go on to mutate this row's balance, so they need the
         * lock anyway — `OrderFinancialService` took it explicitly before this class existed.
         * Outside a transaction MySQL runs in autocommit, so the lock is taken and released by the
         * statement itself and costs nothing.
         */
        $wallet = StoreWallet::where('store_id', $storeId)
            ->lockForUpdate()
            ->first();

        if ($wallet !== null) {
            return $wallet;
        }

        try {
            return StoreWallet::create([
                'store_id' => $storeId,
                'currency_code' => $currencyCode ?: self::DEFAULT_CURRENCY,
                'available_balance' => 0,
                'pending_balance' => 0,
                'withdrawal_pending_balance' => 0,
                'total_income' => 0,
                'total_withdrawn' => 0,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            /*
             * WE LOST THE RACE, which `uq_store_wallet_store_id` guarantees can only mean that
             * somebody else's row for this store is now committed.
             *
             * The re-read MUST be locking. A plain one is what `createOrFirst()` does and what the
             * MySQL script measured re-throwing inside a transaction.
             */
            $raced = StoreWallet::where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if ($raced !== null) {
                return $raced;
            }

            /*
             * A unique violation with no row behind it is not a race — it is something this method
             * does not understand, and inventing a wallet on top of it would be worse than failing.
             */
            throw $e;
        }
    }
}
