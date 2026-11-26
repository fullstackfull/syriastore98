<?php

namespace App\Support\Operator;

use App\Models\PlanOrder;
use App\Services\SaasRevenueFigures;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * GAP-231 — WHICH `plan_orders.payment_status` SPELLINGS MEAN THE PLATFORM WAS PAID, AS STORED.
 * =================================================================================================
 *
 * The operator's two ranking cards (`Popular Plan`, `Popular Coupon`) must count settled purchases
 * only: a ranking led by rows representing no money is a defect, not a reporting definition. This
 * class answers the one question both need — *which stored spellings are settled* — and hands back
 * a list a SQL predicate can match exactly.
 *
 * ============================================================================================
 *  WHY NOT JUST `whereIn('payment_status', SaasRevenueFigures::SETTLED_PLAN_STATUSES)`.
 * ============================================================================================
 *
 * That constant is LOWER-CASED (`['success', 'succeeded', 'approved']`) and the comparator beside
 * it lower-cases and trims the stored value before comparing. The application really does store
 * capitalised spellings -- censused from its literal writes: `'success'` 40 sites, `'succeeded'`
 * 21, but also **`'Succeeded'` 9** and **`'Approved'` 6**. `'Approved'` is what
 * `BankTransferController::orderapprove()` writes when the OPERATOR approves a bank-transfer
 * receipt, which is as settled as a payment gets.
 *
 * A plain `whereIn` against that constant therefore means two different things in the two engines
 * this project runs. MySQL's `utf8mb4_unicode_ci` is case-insensitive and matches `'Approved'`;
 * SQLite's default collation is case-SENSITIVE and does not. The suite runs on SQLite and
 * production is MySQL, so such a predicate would be GREEN in the suite and differently correct in
 * production -- CLAUDE.md's standing warning that SQLite hides MySQL facts, in its collation form
 * rather than its affinity form.
 *
 * ============================================================================================
 *  SO THE SPELLINGS ARE DERIVED FROM THE DATA AND CLASSIFIED WITH THE DECLARED COMPARATOR.
 * ============================================================================================
 *
 * `DISTINCT payment_status`, each value run through `isSettled()`, and the ones that pass are
 * returned AS STORED. That is the census inversion this project insists on: enumerate what is
 * really in the column and classify it, rather than list the spellings somebody thought of. A list
 * of spellings is always short by the one nobody imagined -- here that would be a casing -- and
 * this cannot be, because it reads the column. It also behaves identically on both engines,
 * because the comparison that decides anything happens in PHP.
 *
 * THE EXTRA QUERY IS BOUNDED BY THE NUMBER OF DISTINCT STATUSES, which is single digits: the
 * census that classifies this column enumerates EIGHT spellings in total. This is not the
 * every-row-into-PHP pattern that `Plan::most_purchese_plan()` was repaired of twice -- the
 * aggregation still happens in the database; only the tiny vocabulary comes back.
 *
 * `SETTLED_PLAN_STATUSES` IS READ, NEVER WIDENED. If a ranking ever needs a spelling that set
 * lacks, that is a finding for `PlanOrderSettledStatusCensusTest` to classify and not an edit
 * here: this class owns no vocabulary of its own.
 */
final class SettledPlanOrders
{
    /**
     * Whether a stored spelling means the platform was paid.
     *
     * DELIBERATELY THE SAME TWO OPERATIONS AS `SaasRevenueFigures::isSettled()` -- `strtolower`
     * then `trim` against the same public constant -- because the two must never disagree about
     * one row. That helper is `private`, so it cannot be called from here, and making it public
     * would be editing another workstream's service to save a line.
     * `SettledPlanOrderSpellingsTest` asserts the two answer alike for every spelling the census
     * classifies, in both directions, so the duplication cannot drift silently.
     */
    public static function isSettled(?string $status): bool
    {
        return in_array(
            strtolower(trim((string) $status)),
            SaasRevenueFigures::SETTLED_PLAN_STATUSES,
            true
        );
    }

    /**
     * The settled spellings PRESENT IN THE COLUMN, exactly as stored, for a SQL predicate.
     *
     * An empty answer is meaningful and is not a bug: it means nothing on the platform has been
     * paid for yet. A `whereIn(..., [])` then matches no row, so a ranking built on it reports
     * nothing rather than the least-bad loser -- which is the correct answer and is asserted by
     * `test_no_settled_purchase_anywhere_ranks_nothing`.
     *
     * @return list<string>
     */
    public static function spellingsInUse(): array
    {
        $stored = PlanOrder::query()
            ->distinct()
            ->pluck('payment_status')
            ->all();

        $settled = [];

        foreach ($stored as $spelling) {
            if ($spelling !== null && self::isSettled((string) $spelling)) {
                $settled[] = (string) $spelling;
            }
        }

        /* Sorted so the generated predicate is stable between calls, which keeps the query cache
           key stable and makes a logged query comparable with the next one. */
        sort($settled);

        return array_values(array_unique($settled));
    }

    /**
     * Narrows a `plan_user_coupons` query to redemptions against a SETTLED plan order.
     *
     * `whereExists` AND NOT A JOIN, and the reason is a schema fact rather than taste.
     * `plan_user_coupons.order` is a STRING matched against `plan_orders.order_id`, with no
     * foreign key on it (the table's migration declares FKs to `users` and `plan_coupons` and
     * none to `plan_orders`). A join on that column multiplies a coupon row by however many plan
     * orders share the id, so its correctness would rest on `plan_orders.order_id` being unique.
     * `EXISTS` is a per-row boolean test and cannot multiply or collapse rows whatever the index
     * situation is -- so this is correct even on a table whose unique index was never applied.
     *
     * (Measured anyway, because the question deserved an answer:
     * `2023_12_11_102124_create_plan_orders_table.php` declares `->unique()` on `order_id`, no
     * later migration drops it, and applied to a real MariaDB 10.11 it materialises as a UNIQUE
     * KEY -- see `tests/Support/Security/bin/verify-plan-order-id-uniqueness-on-mysql.php`. That
     * establishes the migrations and the engine, NOT the live table: CLAUDE.md records that the
     * migrations are not the schema. Using `EXISTS` is what makes the live answer not matter.)
     *
     * A REDEMPTION NAMING NO ORDER AT ALL IS NOT SETTLED. `EXISTS` is false for it, so it is
     * excluded -- which is the right answer: a row cannot be counted as paid on the strength of
     * being unmatchable. The dashboard discloses that the ranking counts matched, settled
     * redemptions.
     */
    public static function redeemedAgainstSettledOrder(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->from('plan_orders')
            ->whereColumn('plan_orders.order_id', 'plan_user_coupons.order')
            ->whereIn('plan_orders.payment_status', self::spellingsInUse());
    }
}
