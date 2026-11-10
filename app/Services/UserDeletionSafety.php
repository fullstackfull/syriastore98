<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0-DELETE — IS IT SAFE TO HARD-DELETE THIS USER? Owner-approved, fail-closed.
 * =================================================================================================
 *
 * THE OWNER'S RULE, verbatim: *"Under no circumstances may deleting a user silently destroy a
 * store, subscription, financial ledger, wallet, transaction history, or other business-critical
 * data."* and *"Reject hard deletion if the target owns a store or has any dependent business,
 * financial, or historical records that could be lost or orphaned."*
 *
 * TWO SEPARATE QUESTIONS, and conflating them is how an authorization bug becomes a data-loss bug
 * -- the split `VariantRemovalPolicy` already draws in this codebase:
 *
 *   UserPolicy::delete()        MAY this principal delete this row?   (authorization)
 *   UserDeletionSafety          is deleting this row safe AT ALL?     (data safety)
 *
 * This class is the second. It is **not** an authorization control and holds for the platform
 * operator exactly as for a merchant: an operator deleting a store owner destroys the same wallet.
 *
 * WHY A ROW-DELETE IS A TENANT WIPE. `users` has no soft delete, no `deleting` hook and no
 * observer, so the schema decides everything. Measured on the committed production dump's schema
 * section: **15 foreign keys reference `users(id)` and all 15 are ON DELETE CASCADE**; `stores`
 * is one of them, and **12 further keys cascade off `stores(id)`** -- `store_wallets`,
 * `store_wallet_transactions`, `store_withdrawals`, `store_order_financials` among them. Of the
 * schema's 89 foreign keys exactly ONE is not CASCADE and NONE is RESTRICT, so the database will
 * never refuse. And the inverse is worse: `orders`, `customers`, `products` and `carts` carry no
 * foreign key at all, so they SURVIVE pointing at a store that no longer exists -- the per-order
 * accounting row is destroyed while the order remains.
 *
 * THE LIST IS PINNED, NOT DISCOVERED AT RUNTIME. A guard that reflects over the live schema
 * silently narrows itself on any database that is missing a table -- and this project's migrations
 * are not its schema (CLAUDE.md). So the pairs below are written down, and
 * `UserDeletionSafetyCoverageTest` asserts they still cover every CASCADE foreign key in the
 * production dump. A new cascade added to production and not added here FAILS THAT TEST rather
 * than quietly escaping the guard.
 *
 * A TABLE ABSENT FROM THE LIVE SCHEMA IS SKIPPED, DELIBERATELY, and this is the one place the
 * reasoning is subtle. `product_enquiries` and `sidemenu_order` exist in production and in no
 * migration, so they are absent from a freshly-migrated database. A row cannot be lost from a
 * table that does not exist *in the database being queried*, so skipping is correct **here**; what
 * would be wrong is letting that make the LIST incomplete, and the coverage ratchet is what stops
 * that. The alternative -- refusing every deletion wherever any listed table is missing -- would
 * disable the staff management the owner asked to preserve, on every fresh environment, to guard
 * against a row that cannot exist.
 */
final class UserDeletionSafety
{
    private function __construct()
    {
    }

    /**
     * Every CASCADE foreign key to `users(id)` in the production schema, as table => column.
     *
     * Source: `storesyria_ecommercego.sql`, the trailing `ALTER TABLE ... ADD CONSTRAINT` block,
     * lines 37205-37522. Fifteen, all `ON DELETE CASCADE`. Pinned by
     * tests/Unit/Security/UserDeletionSafetyCoverageTest.
     */
    public const CASCADE_DEPENDENCIES = [
        'delivery_boys' => 'created_by',
        'email_templates' => 'created_by',
        'lynomia_contact_verifications' => 'user_id',
        'notify_users' => 'user_id',
        'order_refund_settings' => 'user_id',
        'plan_orders' => 'user_id',
        'plan_requests' => 'user_id',
        'plan_user_coupons' => 'user_id',
        'product_brands' => 'created_by',
        'product_enquiries' => 'created_by',
        'product_labels' => 'created_by',
        'product_questions' => 'created_by',
        'sidemenu_order' => 'created_by',
        'stores' => 'created_by',
        'user_email_templates' => 'user_id',
    ];

    /**
     * Business, financial and historical tables keyed to a user that have NO foreign key, so they
     * would SURVIVE the delete pointing at a row that is gone.
     *
     * These are the orphans, and they are the half that cannot be undone by restoring a backup of
     * one table: `orders` keeps its rows while `store_order_financials` loses the matching
     * accounting line, so revenue stops reconciling.
     */
    public const ORPHAN_DEPENDENCIES = [
        'orders' => 'created_by',
        'customers' => 'created_by',
        'products' => 'created_by',
        'order_notes' => 'created_by',
        'settings' => 'created_by',

        /* ---- ADDED 2026-11-10, BY INDEPENDENT REVIEW, AND THEY ARE THE MONEY ONES -----------
           The first draft of this class declared a `STORE_MONEY_TABLES` constant with a docblock
           saying it covered "a user who owns no store but is named on a store's money rows" --
           and `blockers()` never read it. A constant nothing calls is not a check; it is a
           paragraph that tells an auditor a control exists when it does not.
           Rather than delete it, each of the four was measured against the production dump:
             store_wallets             no user-bearing column            -> nothing to check
             store_order_financials    no user-bearing column            -> nothing to check
             store_wallet_transactions `created_by`, bigint, NO FK       -> a real orphan
             store_withdrawals         `requested_by`, bigint, NO FK     -> a real orphan
           Both are written from `auth()->id()` (OrderFinancialService :179, :456, :629 and
           StoreFinanceController :1346), so they hold a real user id, and both tables carry live
           rows in the production dump. A ledger line and a payout request whose requester is a
           row that no longer exists is exactly the "financial ledger / wallet / transaction
           history" the owner's decision names. */
        'store_wallet_transactions' => 'created_by',
        'store_withdrawals' => 'requested_by',

        /* The same shape, found by the same sweep: a module table in the production dump whose
           actor column is an `unsignedBigInteger` with no foreign key
           (LynomiaChat 2026_10_28_000100 :71, written from `$actor?->id`). */
        'lynomia_platform_notices' => 'requested_by',
    ];

    /**
     * WHY THIS USER CANNOT BE HARD-DELETED. An empty array means it is safe.
     *
     * Each entry is a human-readable reason naming the table and the count, because the merchant
     * sees it and because a refusal with no reason is indistinguishable from a bug.
     *
     * @return array<int, string>
     */
    public static function blockers(User $user): array
    {
        $blockers = [];

        // 1. STORE OWNERSHIP FIRST. It is the single edge that turns a row-delete into a tenant
        //    wipe, and refusing on it short-circuits the entire second hop and the whole orphan
        //    surface behind it.
        if (Schema::hasTable('stores')) {
            $stores = DB::table('stores')->where('created_by', $user->id)->count();

            if ($stores > 0) {
                $blockers[] = sprintf(
                    'owns %d store(s): deleting this account would cascade to the store and to '
                    . 'its wallet, wallet transactions, withdrawals and per-order financials',
                    $stores
                );
            }
        }

        // 2. EVERYTHING THAT CASCADES. Already counted `stores` above; skip it here so the
        //    merchant is not told the same thing twice.
        foreach (self::CASCADE_DEPENDENCIES as $table => $column) {
            if ($table === 'stores') {
                continue;
            }

            $count = self::countRows($table, $column, $user->id);

            if ($count > 0) {
                $blockers[] = sprintf('%d row(s) in `%s` would be destroyed by the cascade', $count, $table);
            }
        }

        // 3. EVERYTHING THAT WOULD BE ORPHANED.
        foreach (self::ORPHAN_DEPENDENCIES as $table => $column) {
            $count = self::countRows($table, $column, $user->id);

            if ($count > 0) {
                $blockers[] = sprintf(
                    '%d row(s) in `%s` would be left pointing at a deleted account',
                    $count,
                    $table
                );
            }
        }

        return $blockers;
    }

    /**
     * Count, tolerating a table or column this database does not have.
     *
     * A table that is not in THIS schema cannot hold a row that THIS delete would lose. The
     * completeness of the list is guaranteed by the coverage ratchet instead -- see the class
     * docblock.
     */
    private static function countRows(string $table, string $column, int $userId): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        if (! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) DB::table($table)->where($column, $userId)->count();
    }
}
