<?php

namespace App\Support\Customers;

use Illuminate\Database\Eloquent\Builder;

/**
 * P2.5-R-06 — ONE SET OF FILTER SEMANTICS for the customer screens, because there were two and
 * they disagreed in three places.
 * =================================================================================================
 *
 * WHAT WAS HERE INSTEAD. `CustomerDataTable::query()` and `CustomerController::CustomFilterData()`
 * each carried their own ~100-line copy of the same five filters. The copies were not merely
 * duplicated, they had drifted: `CustomFilterData()`'s three numeric branches threw away the query
 * they had been handed and started again from a fresh `Customer::where('store_id', ...)` through
 * throwaway variables named `$queryyy`, `$queryss` and `$queryes`.
 *
 * AND ALL THREE NUMERIC FAULTS EXISTED IN BOTH COPIES — six instances of three defects:
 *
 *   1. `'Equal' && $AOV = $text_field_float` — one `=`. An ASSIGNMENT. PHP evaluates it to the
 *      assigned value, truthy for every non-zero input, so "AOV Equal 150" matched every customer
 *      who had any computable average at all. It also clobbered `$AOV` mid-loop.
 *
 *   2. `'No. of Orders'` and `'Total Spend'` each had TWO branches keyed on `'Less Than'`, the
 *      second testing `>`. As `else if` chains that made "Less Than 3" match `< 3` OR `> 3`:
 *      everything except the exact matches. A filter inverted, with its boundary punched out.
 *
 *   3. Neither of those two fields had a `'More Than'` branch at all, though
 *      `Customer::$fields_status2` offers the operator and `CustomFilter()` renders it. The
 *      candidate list stayed empty, `whereIn('id', [])` matched nothing, and the merchant read an
 *      empty screen as "you have no such customers".
 *
 *   4. A FOURTH FAULT, found while bite-testing the first three and recorded nowhere before:
 *      every branch was guarded by `!empty($text_field)`, and `empty("0")` is TRUE in PHP. A
 *      threshold of zero was therefore thrown away and NO filter was applied, so "Total Spend
 *      Equal 0" and "No. of Orders Equal 0" -- "who has never spent / never ordered" -- each
 *      answered with the ENTIRE customer list. See the guard below.
 *
 * So this class exists for a measured reason, not for tidiness: a reader who repaired one copy had
 * not repaired the screen, and that is precisely how fault 3 survived a previous round of work on
 * this very filter (`P2.5-R-02`, whose own test file documents faults 1 and 3 as deferred).
 *
 * WHAT THIS CLASS DOES NOT DO, DELIBERATELY
 * -------------------------------------------------------------------------------------------------
 *
 * IT DOES NOT ESTABLISH TENANCY AND MUST NEVER BE ASKED TO. It receives a Builder that the caller
 * has ALREADY scoped, and it only ever narrows it. There is no `$storeId` parameter and no read of
 * the request: a tenant predicate built from caller-supplied input is not a tenant check, and
 * `CLAUDE.md` records four separate defects from exactly that. The three values it does take are
 * the field name, the operator and the threshold — none of which can name a store.
 *
 * IT DOES NOT CHANGE THE MONEY. `total_spend()` and `Ordercount()` are called exactly as before,
 * through `Customer::ownStoreOrders()`, and the AOV expression keeps its `number_format(..., 2)`
 * then strip-commas round trip verbatim. Owner decision D-40 forbids changing financial
 * calculations to repair a filter, so the arithmetic is copied, not improved.
 *
 * IT DOES NOT CHANGE WHICH CUSTOMERS PARTICIPATE IN AN AVERAGE. A customer with no orders is
 * skipped by every AOV operator, as before: an average over zero orders is undefined, not zero.
 * That is a semantic choice with money in it, so it is preserved and pinned by a test rather than
 * silently "fixed".
 *
 * IT DOES NOT RENAME THE REQUEST KEYS. The list sends `field_name` and the grid sends
 * `customer_field`; both spellings are live in bookmarks and in the shipped markup. Each caller
 * reads its own key and passes the resolved value in, which keeps both contracts working — D-40
 * asks for backward-compatible repairs.
 *
 * THE ONE INPUT-PARSING CORRECTION, NAMED BECAUSE IT IS A BEHAVIOUR CHANGE
 * -------------------------------------------------------------------------------------------------
 *
 * `'Total Spend'` cast its threshold with `(int)`, so "Total Spend Equal 300.50" silently became
 * "Equal 300" and answered a question the merchant did not ask. A spend is money and its threshold
 * is read as money here. `'No. of Orders'` keeps `(int)`, because an order count genuinely is a
 * whole number. This changes no stored or computed amount — only how the typed threshold is read —
 * and it is reported to the owner as its own sub-finding rather than folded into the three faults.
 *
 * THE QUERY SHAPE IS UNCHANGED, INCLUDING ITS COST. The numeric operators still materialise the
 * candidate set and call two aggregate methods per row, which is N+1 by construction. Replacing it
 * with SQL aggregates would change how the figures are computed, which is the thing D-40 reserves,
 * so the cost is recorded in the ledger as an open item instead of being optimised here.
 */
final class CustomerListFilter
{
    /**
     * The three fields whose operators are numeric, and which therefore have to be evaluated in
     * PHP because their figures come from methods rather than columns.
     */
    public const NUMERIC_FIELDS = ['AOV', 'No. of Orders', 'Total Spend'];

    /**
     * Both spellings of the last-active field. `Customer::customer_field()` offers the option value
     * 'Last active' (lower-case a); the original code tested 'Last Active' and so never matched.
     * That was P2.5-R-03, and matching both keeps any bookmark carrying the old spelling working.
     * The comparison is EXACT on purpose: a loose match would pull unrelated field names into the
     * date branch.
     */
    public const LAST_ACTIVE_FIELDS = ['Last active', 'Last Active'];

    /**
     * Narrow an ALREADY TENANT-SCOPED customer query by one field/operator/value triple.
     *
     * @param Builder     $query    scoped to the caller's store before it gets here
     * @param string|null $field    the chosen customer field, as `Customer::customer_field()` spells it
     * @param string|null $operator the chosen operator, as `Customer::$fields_status*` spells it
     * @param mixed       $value    the merchant's typed threshold, raw off the request
     */
    public static function apply(Builder $query, ?string $field, ?string $operator, $value): Builder
    {
        if ($field === null || $field === '') {
            return $query;
        }

        /*
         * FAULT 4 -- `empty("0") === true`, AND THAT IS NOT A STYLE POINT.
         *
         * Both original copies guarded every branch with
         * `!empty($requestData['selected_name']) && !empty($requestData['text_field'])`. PHP's
         * `empty()` is true for the STRING "0", so a threshold of zero was DISCARDED and no filter
         * was applied at all -- which means the merchant got THE WHOLE CUSTOMER LIST back.
         *
         * "No. of Orders Equal 0" and "Total Spend Equal 0" -- "which of my customers have never
         * ordered / never spent anything" -- are ordinary merchant questions, and both answered
         * with every customer in the store. MEASURED on the original code: 'AOV More Than 0'
         * returned all five fixture customers, including the one with no orders at all.
         *
         * This is the same defect class as the missing 'More Than' branch: an operator the screen
         * offers, which silently does nothing, and whose "nothing" reads as an answer. So the test
         * is strict emptiness -- a MISSING or BLANK value means "no filter"; "0" is a value.
         *
         * IT IS A BEHAVIOUR CHANGE and it is named as one. A merchant who typed 0 yesterday saw
         * the full list; today they see the customers who actually match. It is reported to the
         * owner as a fourth fault rather than folded into the three.
         */
        if ($operator === null || $operator === '' || $value === null || $value === '') {
            return $query;
        }

        if ($field === 'Name' || $field === 'Email') {
            return self::applyText($query, $operator, (string) $value);
        }

        if (in_array($field, self::LAST_ACTIVE_FIELDS, true)) {
            return self::applyLastActive($query, $operator, (string) $value);
        }

        if (in_array($field, self::NUMERIC_FIELDS, true)) {
            return self::applyNumeric($query, $field, $operator, $value);
        }

        return $query;
    }

    /**
     * Name / e-mail. Unchanged in behaviour: `Includes` and `Excludes` both search first_name OR
     * email, and the nesting is kept so the OR cannot leak out of the surrounding AND.
     */
    private static function applyText(Builder $query, string $operator, string $value): Builder
    {
        if ($operator === 'Includes') {
            $query->where(function ($subQuery) use ($value) {
                $subQuery->where('first_name', 'like', '%' . $value . '%')
                    ->orWhere('email', 'like', '%' . $value . '%');
            });
        } elseif ($operator === 'Excludes') {
            $query->where(function ($subQuery) use ($value) {
                $subQuery->where(function ($sQuery) use ($value) {
                    $sQuery->where('first_name', 'not like', '%' . $value . '%')
                        ->orWhere('email', 'not like', '%' . $value . '%');
                });
            });
        }

        return $query;
    }

    /**
     * Last active. `Before`/`After` are strict; anything else falls through to an exact date match,
     * which is what both original copies did for the `Equal` option.
     */
    private static function applyLastActive(Builder $query, string $operator, string $value): Builder
    {
        if ($operator === 'Before') {
            $query->whereDate('last_active', '<', $value);
        } elseif ($operator === 'After') {
            $query->whereDate('last_active', '>', $value);
        } else {
            $query->whereDate('last_active', $value);
        }

        return $query;
    }

    /**
     * AOV / order count / total spend.
     *
     * Each figure comes from a method on the model, so the candidate set is materialised and
     * filtered in PHP, then fed back as a `whereIn` — exactly as before, so the DataTable's later
     * ordering, searching and pagination still act on a Builder rather than a Collection.
     *
     * THE OPERATOR IS DISPATCHED ONCE, through a single comparison helper, rather than as three
     * hand-written `if` chains per field. That is the whole point: the duplicated `'Less Than'`
     * branch and the missing `'More Than'` branch were both only possible because the chain was
     * written out nine separate times.
     */
    private static function applyNumeric(Builder $query, string $field, string $operator, $value): Builder
    {
        // 'No. of Orders' is a count, so its threshold is a whole number. The two money fields
        // read theirs as money — see the class docblock for why that is a named correction.
        $threshold = $field === 'No. of Orders' ? (int) $value : (float) $value;

        $matched = [];

        foreach ($query->get() as $customer) {
            $figure = self::figureFor($customer, $field);

            // AOV returns null for a customer with no orders: no orders means no average, not an
            // average of zero. Preserved from the original, pinned by test.
            if ($figure === null) {
                continue;
            }

            if (self::compare($figure, $operator, $threshold)) {
                $matched[] = $customer->id;
            }
        }

        return $query->whereIn('id', $matched);
    }

    /**
     * The figure a given field is asking about, or null when the customer has none.
     *
     * The AOV expression is the original one, character for character: `number_format(..., 2)`
     * produces a grouped string and the comma strip turns it back into a float. It rounds to two
     * decimals, which is what makes an `Equal` comparison meaningful at all, so it is load-bearing
     * rather than incidental formatting.
     */
    private static function figureFor($customer, string $field): int|float|null
    {
        if ($field === 'No. of Orders') {
            return $customer->Ordercount();
        }

        if ($field === 'Total Spend') {
            return (float) $customer->total_spend();
        }

        // AOV
        $spend = $customer->total_spend();
        $orders = $customer->Ordercount();

        if ($spend == 0 || $orders == 0) {
            return null;
        }

        return (float) str_replace(',', '', number_format($spend / $orders, 2));
    }

    /**
     * ONE comparison, for every numeric field and every numeric operator.
     *
     * An unrecognised operator matches nothing, which is the same answer the original chains gave
     * for one — but now it is the stated default rather than the consequence of a branch somebody
     * forgot to write.
     */
    private static function compare(int|float $figure, string $operator, int|float $threshold): bool
    {
        return match ($operator) {
            'Less Than' => $figure < $threshold,
            'More Than' => $figure > $threshold,
            'Equal' => $figure == $threshold,
            default => false,
        };
    }
}
