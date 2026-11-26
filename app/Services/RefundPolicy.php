<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * THE STORE'S REFUND-POLICY TOGGLES, READ SERVER-SIDE. GAP-140 / P04-P6-04 / P4-F086.
 * =============================================================================================
 *
 * WHAT WAS WRONG. `order_refund_settings` holds three per-store switches a merchant sets on the
 * Settings screen's Refund tab, and before this class EVERY ONE OF THEM GATED MARKUP AND NOTHING
 * ELSE.
 *
 * RE-MEASURED AT THE PARENT COMMIT, because this paragraph's first form said "four files" and then
 * named three of them. `git grep -n 'OrderRefundSetting\|order_refund_settings' <parent> -- app/`
 * is TEN lines in FIVE files; outside the model's own file, EIGHT lines in FOUR files, and in not
 * one of them does a value decide anything a writer does:
 *
 *   AccountProfileController.php:31,1646   an import, then `order_refund()` handing an array to a VIEW
 *   OrderRefundController.php:7,289        an import, then `show()` handing an array to a VIEW
 *   OrderRefundController.php:311,319      `updateStatus()`, the writer of the rows THEMSELVES
 *   OrderDocumentAccessGuard.php:283       the table's name inside a COMMENT
 *   UserDeletionSafety.php:71              the table's name in the cascade-dependency list
 *
 * That last row is the one the first form missed, and it is the reason this one is a command
 * rather than a sentence: a census keyed on a count somebody remembered is not a census.
 * `UserDeletionSafety` reads the table as a FOREIGN KEY to `users`, never a toggle, so the claim
 * survives the correction -- but it had to be re-run to know that.
 *
 * So the server restored stock for a merchant who had switched restocking off, and stored
 * attachments for a merchant who had switched attachments off. That is worse than an absent
 * feature: the product showed each merchant a control, and the control did nothing.
 *
 * ABSENT IS OFF, AND THAT IS A MEASUREMENT RATHER THAN A CHOICE. Nothing seeds this table --
 * `is_active` defaults to 0, no seeder writes it, and `Utility::addNewData()` does not know the
 * table exists. A row appears only when somebody saves the Refund tab. The settings screen
 * renders an absent row as an OFF switch (`(int) ($order_refunds['manage_stock'] ?? 0) === 1`),
 * and so do both consuming views, so "never configured" and "configured off" are the SAME claim
 * to the merchant. A null-permissive reading here would contradict the screen.
 *
 * THE READ IS RAW, for two reasons. `OrderRefundSetting` is one of the 79 `Cachable` models, so an
 * Eloquent read can serve a row from before the merchant's own save -- and under `CACHE_STORE=array`
 * it would live for the rest of the request. And `DB::table()` in a service outside `app/Models`,
 * `app/Models/Concerns`, `app/Scopes` and `app/Helper/helper.php` is outside
 * `ResidualTenancyExposureTest`'s raw-query inventory, so it does not put a row in a census of
 * SCOPE BYPASSES -- which this is not: the predicate is written here, explicitly, and
 * `OrderRefundSetting` carries no global scope to bypass in the first place.
 *
 * DUPLICATE ROWS ARE POSSIBLE AND THE LAST ONE WINS, which matches the screen exactly.
 * `updateStatus()` writes one row per (store_id, user_id, name), so two users of one store
 * produce two rows per name; both consuming views read `where('store_id', ...)
 * ->pluck('is_active', 'name')` with NO user predicate, and an array key written twice keeps the
 * value written last. This reproduces that answer deterministically by ordering on `id`, rather
 * than inheriting whatever order an engine happens to return. The duplicate-row shape itself is a
 * pre-existing wrinkle of the writer and is reported rather than changed here: a gate is not the
 * place to decide which of two merchants' choices is the store's.
 *
 * `shiping_deduct` IS DELIBERATELY NOT A CONSTANT HERE, AND THE REASON IS STRONGER THAN THE ONE
 * FIRST WRITTEN DOWN. The first form said the server "cannot tell a total that already includes
 * shipping from one that does not", which is true of `$request->refund_amount` and beside the
 * point. The measured reason is a chain of two writes inside ONE transaction in
 * `OrderRefundController::RefundAmonut()`. NO LINE NUMBERS, deliberately -- the first form of this
 * paragraph cited two and my own edit to the same file moved both before it was committed; a test
 * asks the question instead, so the claim cannot go stale silently:
 *
 *   the flag's write       `if ($request->isTrendingChecked == 'true') { $order['delivery_price'] = 0.0; ... }`
 *   the total's recompute  `$grandTotal = ($lineTotal + (float) $order->delivery_price + ...` then
 *                          `$order->final_price = max(0.0, $grandTotal);`
 *
 * The second reads the column the first zeroed, and
 * `RefundPolicyEnforcementTest::test_the_shipping_flag_still_reaches_the_order_total` pins exactly
 * that ordering. So honouring a switched-off
 * `shiping_deduct` -- i.e. declining to zero `delivery_price` -- RAISES `orders.final_price` by
 * the delivery charge on every offline settlement where the merchant ticked the box, and
 * `final_price` is the figure `OrderEntitlementReversal::reverse()` then takes back off the
 * merchant's entitlement. That is a refund AMOUNT and a money-policy change, which is reserved to
 * the owner, so it is REPORTED and not implemented. `isTrendingChecked` itself is applied
 * unconditionally and that behaviour is unchanged by this class.
 *
 * Pinned by `RefundPolicyEnforcementTest::test_the_shipping_deduction_toggle_is_read_by_no_php_in_the_application`,
 * which now asks the question over the whole of `app/` rather than over three files.
 */
class RefundPolicy
{
    /**
     * Does this store allow the refund screen's "Manage Stock" action to credit the goods back?
     *
     * Gates `OrderRefundController::RefundStock()`, which is what the button at
     * `resources/views/order/refund_details.blade.php:224` posts to.
     */
    public const MANAGE_STOCK = 'manage_stock';

    /**
     * Does this store accept files with a shopper's refund request?
     *
     * Gates the attachment block of `AccountProfileController::order_refund_request()`, which is
     * what the file input at `resources/views/front_end/pages/refund_order.blade.php:156` posts
     * to.
     */
    public const ATTACHMENT = 'attachment';

    /**
     * Is the named toggle switched on for this store?
     *
     * Default-deny: an unresolvable store, an absent row, a non-numeric value and an explicit 0
     * all answer false, which is what the three screens already show.
     */
    public static function allows(string $name, mixed $storeId): bool
    {
        if (empty($storeId) || $name === '') {
            return false;
        }

        /*
         * FAILS CLOSED RATHER THAN THROWING, AND THE REASON IS A MEASURED REGRESSION IN THE
         * STOREFRONT DOOR -- not defensive habit.
         *
         * The first form of this method had no catch. Running the refund blast radius found
         * `Feature\Commerce\RefundStockIdempotencyTest` building a minimal schema by hand with no
         * `order_refund_settings` table, so the read raised
         * `SQLSTATE[HY000]: 1 no such table: order_refund_settings` out of a GATE. At the restock
         * door that is only a worse status (500 where 403 was meant, before any write).
         *
         * AT THE STOREFRONT DOOR IT IS WORSE-SHAPED, and this sentence is a correction: its first
         * form said a throw "loses the whole request", which MUTATING THE CATCH AWAY disproved.
         * `AccountProfileController::order_refund_request()` builds and saves the refund at :1959,
         * and reads this policy at :2007 -- AFTER it. So the return is already COMMITTED and a
         * throw hands the shopper HTTP 500 over a refund that WAS recorded: they cannot tell, and
         * the natural move is to send it again. A 500 after a commit is a half-written state, not
         * a refusal.
         *
         * `audit_logs` is the precedent this project already records -- applied to production out
         * of band, its migration a documented no-op there -- so the schema a request path depends
         * on is not something this code can assert. `order_refund_settings` does have a real
         * migration (2024_02_07_090422) and is in the production dump's CASCADE list
         * (`UserDeletionSafety::CASCADE_DEPENDENCIES`), so absence is not a production state I can
         * demonstrate; the measured one is a harness, and the storefront cost of being wrong is
         * the shopper's return.
         *
         * DENY IS THE SAFE DIRECTION FOR BOTH DOORS and it is already this class's answer for an
         * absent row: no stock is credited, no file is stored, and nothing about an amount, a
         * status or the refund record moves either way. It is logged at `warning` rather than
         * swallowed, because a refusal whose reason nobody can read is the trap this repository
         * keeps re-finding -- `docs/security/P01-OBSERVABILITY-INVENTORY.md` carries the line.
         */
        try {
            $value = DB::table('order_refund_settings')
                ->where('store_id', $storeId)
                ->where('name', $name)
                ->orderBy('id')
                ->pluck('is_active')
                ->last();
        } catch (\Illuminate\Database\QueryException $e) {
            \Log::warning('A store refund policy could not be read; the action was refused', [
                'name' => $name,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return is_numeric($value) && (int) $value === 1;
    }
}
