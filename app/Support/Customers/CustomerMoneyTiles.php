<?php

namespace App\Support\Customers;

use App\Models\Customer;
use App\Support\Metrics\CustomerLedgerMoney;
use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricResult;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\MoneyText;
use App\Models\Setting;

/**
 * GAP-156 -- THE TWO CUSTOMER FIGURES AS A SURFACE CAN PRINT THEM, in the customer's own
 * currency, with what they do not cover said out loud.
 * =============================================================================================
 *
 * `CustomerMoneyFigures` answers WHICH money and WHOSE orders. This turns that answer into the
 * three strings `x-ss.stat` takes -- `value`, `period`, `hint` -- and it is deliberately the
 * SAME shape `FinanceLedgerFigures::tile()` already produces for /finance, because a merchant
 * who learns to read one financial tile should not have to learn a second grammar for another.
 *
 * `value => null` IS THE REFUSAL AND IT RENDERS AS "Not available", never as a zero. That is the
 * whole point of the type: §15's failure mode is under-reporting to 0.00 without saying why, and
 * a tile that cannot tell the difference between "collected nothing" and "cannot see the orders"
 * would commit exactly that.
 *
 * THREE THINGS SEND A FIGURE TO null, and they are different questions:
 *
 *   1. NO TENANT     -- a customer with no `store_id`. There is no ledger to read.
 *   2. MIXED         -- real amounts in more than one currency. They are LISTED in the hint and
 *                       never added, because adding them would invent an exchange rate. This is
 *                       the owner's "preserve recorded currency identity" rule, and it is why the
 *                       store's configured currency is a FALLBACK for labelling an unmarked row,
 *                       never a target to convert into.
 *   3. UNCOVERED     -- the customer has orders the ledger cannot see. Treated as unknown rather
 *                       than as zero; the count is in the hint.
 *
 * WHY THE COVERAGE SIGNAL IS READ OFF THE *COLLECTED* RESULT FOR BOTH TILES. The two figures read
 * two different tables, and only `collected` carries `orders_without_ledger_row` -- `netRetained`
 * aggregates `store_wallet_transactions`, whose own gap (`movements_without_resolvable_order`) is
 * scope-level and answers a different question: movements that no order explains, rather than
 * orders that no record covers. But both figures are about the SAME ORDER SET, so a shopper whose
 * orders all predate `store_order_financials` has an unreadable retained figure for precisely the
 * reason the collected one records. Reading that count off the collected result and applying it to
 * both is what stops the retained tile printing a confident 0.00 next to a collected tile that has
 * just admitted it cannot see anything.
 */
final class CustomerMoneyTiles
{
    private const FALLBACK_CURRENCY = 'USD';

    /**
     * The two tiles for one customer, plus the line that must now sit under `Total Spend`.
     *
     * @return array{
     *     collected: array{value: ?string, period: string, hint: string},
     *     retained: array{value: ?string, period: string, hint: string},
     *     spend_hint: string,
     *     attribution: string
     * }
     */
    public static function forCustomer(Customer $customer, ?MetricWindow $window = null): array
    {
        $figures = CustomerMoneyFigures::forCustomer($customer, $window);

        return self::fromFigures($figures, (int) ($customer->store_id ?? 0));
    }

    /**
     * The same, from figures a caller has already resolved -- the list resolves a whole page in
     * one call and must not re-resolve per row.
     *
     * @param  array{collected: MetricResult, retained: MetricResult, attribution: string}  $figures
     * @return array{
     *     collected: array{value: ?string, period: string, hint: string},
     *     retained: array{value: ?string, period: string, hint: string},
     *     spend_hint: string,
     *     attribution: string
     * }
     */
    public static function fromFigures(array $figures, int $storeId): array
    {
        $currency = self::storeCurrency($storeId);
        $period = __('all time');
        $attribution = (string) ($figures['attribution'] ?? 'none');

        if ($attribution === 'unresolvable') {
            $refusal = __('This customer is not attached to a store, so no financial record can be read for them.');

            return [
                'collected' => ['value' => null, 'period' => $period, 'hint' => $refusal],
                'retained' => ['value' => null, 'period' => $period, 'hint' => $refusal],
                'spend_hint' => $refusal,
                'attribution' => $attribution,
            ];
        }

        $uncovered = CustomerMoneyFigures::uncoveredOrders($figures);

        return [
            'collected' => self::tile(
                $figures['collected'],
                $currency,
                $period,
                $uncovered,
                __('Money this customer actually handed over, from the financial record. Includes payments that were later cancelled or refunded, so it is a history of collection and not a balance.'),
                $attribution,
            ),
            'retained' => self::tile(
                $figures['retained'],
                $currency,
                $period,
                $uncovered,
                __('What is left after completed refunds and reversals are taken off. This is the smaller of the two figures whenever anything was given back.'),
                $attribution,
                /*
                 * THE SECOND COVERAGE BOUNDARY, AND IT IS NOT THE ONE §15 ALREADY NAMES. The two
                 * figures read two different tables. `orders_without_ledger_row` says the
                 * FINANCIAL RECORD cannot see an order; this says the WALLET recorded no movement
                 * for orders that record says were paid.
                 *
                 * It is a live divergence, not a theoretical one: `syncFromOrder()`'s early
                 * return blocks a late-collection wallet credit (AUD-3), so a store can hold a
                 * paid financial row with no movement behind it. Without this check the tile
                 * would print "collected 125.00, retained 0.00" -- which reads as "all of it was
                 * refunded", a statement about this customer that nothing in the data supports.
                 */
                CustomerMoneyFigures::walletSawNothing($figures),
            ),
            'spend_hint' => self::spendHint(),
            'attribution' => $attribution,
        ];
    }

    /**
     * THE LINE `Total Spend` HAS NEVER CARRIED, and the reason this whole slice exists.
     *
     * `Customer::total_spend()` sums `orders.final_price` over the customer's orders with NO
     * payment predicate of any kind. A cancelled order, a refunded order and an order nobody ever
     * paid for all count in full. The owner's Option A is what makes that permanent rather than
     * fixable in place: `orders.payment_status` is the customer's settlement record and a
     * cancellation deliberately does NOT rewrite it, so there is no state on `orders` that could
     * be filtered to turn this into a money figure. It is an ORDER-VALUE figure, it is useful as
     * one, and it must stop being read as money received.
     */
    public static function spendHint(): string
    {
        return __('The value of this customer\'s orders, before any payment is considered. Cancelled, refunded and unpaid orders are all counted in full, so this is not money received.');
    }

    /**
     * @return array{value: ?string, period: string, hint: string}
     */
    private static function tile(
        MetricResult $result,
        string $currency,
        string $period,
        ?int $uncovered,
        string $meaning,
        string $attribution,
        bool $unrecorded = false,
    ): array {
        $result = $result->assumingUnknownCurrencyIs($currency);
        $notes = [$meaning];

        if ($attribution === 'billing_email') {
            /*
             * SAID OUT LOUD BECAUSE THE NUMBER IS OTHERWISE INEXPLICABLE. This customer has no
             * order of their own; the figure comes from unattributed orders matched on the
             * billing address. `Customer::ownStoreOrders()` has always done this for `Total
             * Spend`, silently -- a merchant seeing money against a customer with zero orders of
             * record had nothing to read that explained it.
             */
            $notes[] = __('This customer has no orders recorded against their account. The figure comes from guest orders billed to their email address.');
        }

        if ($result->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED)) {
            $notes[] = __('Includes :amount from records that carry no currency, counted in your store currency.', [
                'amount' => MoneyText::amount(
                    (float) ($result->notes()['currency_assumed_amount'] ?? 0.0),
                    $currency,
                ),
            ]);
        }

        /* `true`: a bucket that rounds to nothing is not a second currency, and must not send an
           otherwise readable tile to the mixed refusal. Same argument, same flag, as /finance. */
        $buckets = MoneyText::buckets($result->byCurrency(), true);

        if (count($buckets) > 1) {
            $listed = [];

            foreach ($buckets as $code => $amount) {
                $listed[] = MoneyText::amount($amount, $code);
            }

            $notes[] = __('These amounts are in more than one currency and are not combined: :amounts.', [
                'amounts' => implode(', ', $listed),
            ]);

            return ['value' => null, 'period' => $period, 'hint' => implode(' ', $notes)];
        }

        if ($unrecorded) {
            $notes[] = __('Payments are recorded for this customer but the wallet holds no matching movement, so what was kept after refunds cannot be worked out. The two records disagree and neither is silently preferred here.');

            return ['value' => null, 'period' => $period, 'hint' => implode(' ', $notes)];
        }

        if ($uncovered !== null && $uncovered > 0) {
            /*
             * UNCOVERED OUTRANKS AN AMOUNT, and that ordering is the honest one: when some of the
             * customer's orders have no financial record, whatever the rest sum to is a floor and
             * not the figure. Printing it with a footnote invites the footnote to be missed;
             * refusing it does not.
             */
            $notes[] = __('Orders of this customer with no financial record: :count. The financial record is not backfilled, so orders placed before it began cannot be read and no figure can be given.', [
                'count' => number_format($uncovered),
            ]);

            return ['value' => null, 'period' => $period, 'hint' => implode(' ', $notes)];
        }

        $code = array_key_first($buckets) ?? $currency;

        return [
            'value' => MoneyText::amount($buckets[$code] ?? 0.0, $code),
            'period' => $period,
            'hint' => implode(' ', $notes),
        ];
    }

    /**
     * The store's configured currency CODE -- a label of last resort for an unmarked row, never a
     * conversion target. Read with the same query and the same `'USD'` fallback as
     * `ReportController::salesCollectionTile()`.
     */
    private static function storeCurrency(int $storeId): string
    {
        if ($storeId <= 0) {
            return self::FALLBACK_CURRENCY;
        }

        $code = strtoupper(trim((string) (Setting::where('store_id', $storeId)
            ->where('name', 'defult_currancy')
            ->value('value') ?? '')));

        return $code === '' ? self::FALLBACK_CURRENCY : $code;
    }
}
