<?php

namespace Tests\Support\Security;

use Illuminate\Database\ConnectionInterface;

/**
 * D-N34 — HOW MANY ORDERS CARRY SOMETHING OTHER THAN A CURRENCY CODE. READ ONLY.
 * =================================================================================================
 *
 * Until 2026-11-22 the two storefront-API order writers stamped `orders.currency_code` from the
 * store's currency SYMBOL setting (`P5-R14`). The writers are repaired. What they already wrote is
 * historical financial data, and whether to correct it, reinterpret it or leave it is the owner's
 * decision — which cannot be taken without knowing how much of it there is. Engineering has no
 * production access, so the count has to be something the owner can run.
 *
 * THIS CLASS COUNTS AND CHANGES NOTHING. There is no INSERT, UPDATE or DELETE in it; the safety
 * model is the absence of a write statement, not a flag. It also returns nothing about any person:
 * counts, store counts, and the recorded currency strings themselves — which are settings values,
 * not customer data.
 *
 * WHAT COUNTS AS "NOT A CODE", and the reason for the rule. A currency code in this product is a
 * short run of Latin letters — `USD`, `SYP`, and the admin API's validator allows up to ten
 * characters, so not strictly three. A symbol is whatever else was recorded: `$`, `€`, `ل.س`. The
 * rule is therefore "Latin letters only, two to ten of them", applied to each DISTINCT recorded
 * value in PHP, so that it means the same thing on the engine production runs and on the one the
 * suite runs. A missing value (NULL or empty) is reported on its own line and is NOT counted as a
 * symbol: an order with no recorded currency is a different, already-handled case.
 *
 * THE FIGURE IS A FLOOR, NOT A COUNT, and the report says so where it is printed. A symbol that
 * happens to be Latin letters — `SP`, `Dh`, `Bs` — passes the rule above and is counted as a code.
 * Found by an independent review of the writers' fix, which pointed out that one of my own test
 * fixtures uses exactly such a symbol. Comparing each value against that store's own
 * `currency_rates.currency_code` would not have the blind spot; it is not done here because that
 * table may hold nothing for a store, and a report that silently skipped those stores would be a
 * worse answer than one that states its limit.
 *
 * WHY IT TAKES A CONNECTION, and lives under tests/: the same reasons as `GateFReconciliation` —
 * so the code the suite proves in both directions is the code run against a restored copy, and so
 * nothing in the request path can be mistaken for calling it.
 */
class OrderCurrencyCodeAudit
{
    /** Two to ten Latin letters. See the class docblock for why not exactly three. */
    private const CODE_SHAPE = '/^[A-Za-z]{2,10}$/';

    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * @return array{
     *     orders: int,
     *     recorded_as_a_code: int,
     *     not_recorded: int,
     *     recorded_as_something_else: int,
     *     stores_holding_such_orders: int,
     *     stores_whose_orders_carry_more_than_one_value: int,
     *     values_that_are_not_codes: array<string, int>
     * }
     */
    public function report(): array
    {
        $rows = $this->db->table('orders')
            ->selectRaw('currency_code as recorded, COUNT(*) as orders')
            ->groupBy('currency_code')
            ->get();

        $asCode = 0;
        $missing = 0;
        $other = [];

        foreach ($rows as $row) {
            $value = $row->recorded === null ? '' : trim((string) $row->recorded);

            if ($value === '') {
                $missing += (int) $row->orders;
            } elseif (preg_match(self::CODE_SHAPE, $value) === 1) {
                $asCode += (int) $row->orders;
            } else {
                $other[$value] = ($other[$value] ?? 0) + (int) $row->orders;
            }
        }

        arsort($other);

        return [
            'orders' => $asCode + $missing + array_sum($other),
            'recorded_as_a_code' => $asCode,
            'not_recorded' => $missing,
            'recorded_as_something_else' => array_sum($other),
            'stores_holding_such_orders' => $other === []
                ? 0
                : (int) $this->db->table('orders')->whereIn('currency_code', array_map('strval', array_keys($other)))
                    ->distinct()->count('store_id'),
            'stores_whose_orders_carry_more_than_one_value' => $this->storesWithMoreThanOneValue(),
            'values_that_are_not_codes' => $other,
        ];
    }

    /**
     * The stores a per-currency total cannot be given for: their orders disagree about the
     * currency. NULL is not a value here — `COUNT(DISTINCT ...)` ignores it on both engines — so a
     * store with some unrecorded orders and one code is counted as consistent, which it is.
     */
    private function storesWithMoreThanOneValue(): int
    {
        return $this->db->table('orders')
            ->select('store_id')
            ->whereNotNull('currency_code')
            ->where('currency_code', '<>', '')
            ->groupBy('store_id')
            ->havingRaw('COUNT(DISTINCT currency_code) > 1')
            ->get()
            ->count();
    }
}
