<?php

namespace App\Support\Reports;

use App\Support\Metrics\MoneyText;
use App\Support\Money\CurrencySymbol;
use App\Support\Money\OrderCurrencyStamp;
use Illuminate\Support\Facades\DB;

/**
 * ANA-TOP — ORDER VALUE PER CATEGORY OR BRAND, PER PERIOD, PER CURRENCY.
 * =================================================================================================
 *
 * `ReportController::order_category_reports()` and `::order_brand_reports()` each have four period
 * branches, and all eight contained the same three defects:
 *
 *   1. `+= (float) ($product['final_price'])` with no reference to `orders.currency_code`, so a
 *      store that had taken 25 EUR and 90 USD was shown `115.00` -- not a quantity of anything --
 *      behind one symbol from `Utility::GetValueByName('CURRENCY')`.
 *   2. `Product::find($product['product_id'])` once per ORDER LINE inside a triple-nested loop.
 *      `Product` registers no `StoreScope` and is absent from `config/tenancy.php`'s
 *      `scoped_models`, so that reaches every tenant's catalogue; the comparison that followed was
 *      `$product_data->category_id == $categoryId` against this store's own ids, so a foreign
 *      product whose row id collided was counted into this store's report and attributed by the
 *      FOREIGN catalogue.
 *   3. Eight separate copies of all of it.
 *
 * THE RULE IS THE CARD'S RULE, NOT A NEW ONE. `TopSellersByRevenue` fixed the same figure on the
 * dashboard: the store's configured currency is totalled, orders carrying no recorded currency are
 * folded into it and disclosed, amounts in other currencies are LISTED and never added. There is
 * no approved conversion basis in this product, so the only alternatives are separate buckets or a
 * withheld aggregate. Both classes resolve the currency through
 * `OrderCurrencyStamp::configuredCodeFor()` and phrase their caveats from the same four
 * translation keys, so the card and the report cannot drift.
 *
 * WHY THIS IS NOT JUST A CALL TO `TopSellersByRevenue`. That class windows on `orders.created_at`
 * and these handlers window on `orders.order_date` -- a divergence its own `revenueByProduct()`
 * records as deliberate and measured. Substituting it would make the report's tiles count a
 * different set of orders than the report's own chart, which is worse than the defect. So the RULE
 * is shared and the WINDOW is not: the caller hands over the orders it has already selected.
 *
 * NOT A `Metric`. The metric layer answers "one figure for one scope and window"; this answers
 * "one figure per dimension per bucket", which `MetricResult` has no shape for. Making it one
 * would mean either a metric per category per day or a new result type, and neither is a change
 * this slice is entitled to make to a layer four other screens depend on.
 */
final class DimensionPeriodRevenue
{
    public const CATEGORY = 'category';

    public const BRAND = 'brand';

    /** @var array<int, array<int, array<string, float>>> dimension id => bucket => currency => amount */
    private array $buckets = [];

    private string $primary;

    private bool $primaryIsConfigured;

    private int $bucketCount;

    private function __construct(array $buckets, string $primary, bool $primaryIsConfigured, int $bucketCount)
    {
        $this->buckets = $buckets;
        $this->primary = $primary;
        $this->primaryIsConfigured = $primaryIsConfigured;
        $this->bucketCount = $bucketCount;
    }

    /**
     * @param iterable<object>       $orders      rows carrying `product_json` and `currency_code`
     * @param callable(object): ?int $bucketFor   which slot an order falls in; null drops it
     */
    public static function forOrders(
        int $storeId,
        string $dimension,
        iterable $orders,
        int $bucketCount,
        callable $bucketFor
    ): self {
        $column = $dimension === self::BRAND ? 'brand_id' : 'category_id';

        /* Decoded once. The orders are re-walked below and `json_decode` of every order's line
           list, once per selected category, is a measurable share of the old handler's time. */
        $decoded = [];
        $productIds = [];

        foreach ($orders as $order) {
            $bucket = $bucketFor($order);

            if ($bucket === null || $bucket < 0 || $bucket >= $bucketCount) {
                continue;
            }

            $lines = json_decode((string) ($order->product_json ?? ''), true);

            if (! is_array($lines)) {
                /* A row whose JSON will not parse is skipped, not fatal -- the same decision
                   `TopSellersByRevenue` makes, for the same reason: one unreadable order must not
                   take the whole screen down. */
                continue;
            }

            $currency = CurrencySymbol::code((string) ($order->currency_code ?? ''));
            $decoded[] = [$bucket, $currency, $lines];

            foreach ($lines as $line) {
                if (is_array($line) && isset($line['product_id'])) {
                    $productIds[(int) $line['product_id']] = true;
                }
            }
        }

        /* ONE SCOPED QUERY FOR EVERY PRODUCT, replacing `Product::find()` per order line. The
           `store_id` predicate is the point of it: without one a line naming another tenant's
           product is attributed by that tenant's own catalogue. A product this store does not own
           is simply absent from the map, so its line falls out. */
        $owned = $productIds === []
            ? []
            : DB::table('products')
                ->where('store_id', $storeId)
                ->whereIn('id', array_keys($productIds))
                ->whereNotNull($column)
                ->pluck($column, 'id')
                ->all();

        $buckets = [];

        foreach ($decoded as [$bucket, $currency, $lines]) {
            foreach ($lines as $line) {
                if (! is_array($line) || ! isset($line['product_id'])) {
                    continue;
                }

                $productId = (int) $line['product_id'];

                if (! array_key_exists($productId, $owned)) {
                    continue;
                }

                $dimensionId = (int) $owned[$productId];
                $amount = (float) ($line['final_price'] ?? 0);

                $buckets[$dimensionId][$bucket][$currency]
                    = ($buckets[$dimensionId][$bucket][$currency] ?? 0.0) + $amount;
            }
        }

        [$primary, $configured] = self::primaryCurrencyFor($storeId, $buckets);

        return new self($buckets, $primary, $configured, $bucketCount);
    }

    /**
     * The per-bucket series in the store's own currency, with unrecorded-currency money folded in.
     *
     * Always `$bucketCount` long and always numeric, because the chart component reads it as a
     * series: a short or sparse array is what made `order_category_chart` print `1` per row.
     *
     * @return array<int, float>
     */
    public function series(int $dimensionId): array
    {
        $series = array_fill(0, $this->bucketCount, 0.0);

        foreach ($this->buckets[$dimensionId] ?? [] as $bucket => $byCurrency) {
            $series[$bucket] = round(
                ($byCurrency[$this->primary] ?? 0.0) + ($byCurrency[''] ?? 0.0),
                2
            );
        }

        return $series;
    }

    /** The store-currency total, or null when nothing was earned in it — never a 0.00 stand-in. */
    public function total(int $dimensionId): ?float
    {
        $total = round(array_sum($this->series($dimensionId)), 2);

        return $total > 0 ? $total : null;
    }

    /**
     * Amounts in every OTHER currency, listed and never added to the total.
     *
     * @return array<string, float>
     */
    public function others(int $dimensionId): array
    {
        $others = [];

        foreach ($this->buckets[$dimensionId] ?? [] as $byCurrency) {
            foreach ($byCurrency as $code => $amount) {
                if ($code !== '' && $code !== $this->primary) {
                    $others[$code] = round(($others[$code] ?? 0.0) + $amount, 2);
                }
            }
        }

        $others = array_filter($others, static fn ($amount) => $amount > 0);

        /* Ordered, so the same figure reads the same way twice. */
        ksort($others);

        return $others;
    }

    /** Money on orders that recorded no currency at all, counted in the store's currency. */
    public function unrecorded(int $dimensionId): float
    {
        $sum = 0.0;

        foreach ($this->buckets[$dimensionId] ?? [] as $byCurrency) {
            $sum += $byCurrency[''] ?? 0.0;
        }

        return round($sum, 2);
    }

    /**
     * The one-line disclosure this dimension's figure needs.
     *
     * The FOUR sentences are the card's four, by the same translation keys, so a merchant reading
     * the dashboard and the report is told the same thing in the same words. `ReportFigureNote`
     * owns the first because §11 makes it the single source a screen's `:hint` and an export cell
     * both read.
     */
    public function caveat(int $dimensionId): string
    {
        /* `orderValueHint()`, NOT `orderValue()`, and four pre-existing tests are why. These
           screens already carried the definition PLUS `MetricCaveat::FLOAT_PRECISION`, and the
           first draft of this method replaced the pair with the definition alone -- dropping a
           caveat the screen used to show, which is the exact defect Phase 05's export rule names.
           The precision caveat still applies: this class accumulates money as repeated float
           addition, the same arithmetic the removed handler loops did. */
        $notes = [ReportFigureNote::orderValueHint()];

        if ($this->total($dimensionId) === null) {
            $notes[] = __('Nothing was earned in your store currency here, so this row has no comparable total.');
        }

        $others = $this->others($dimensionId);

        if ($others !== []) {
            $amounts = [];

            foreach ($others as $code => $amount) {
                $amounts[] = MoneyText::amount($amount, $code);
            }

            $notes[] = __('Also earned in another currency, which is not added in and not converted: :amounts.', [
                'amounts' => implode(', ', $amounts),
            ]);
        }

        if ($this->unrecorded($dimensionId) > 0) {
            $notes[] = __('Includes :amount from orders that carry no currency, counted in your store currency.', [
                'amount' => MoneyText::amount($this->unrecorded($dimensionId), $this->primary),
            ]);
        }

        return implode(' ', $notes);
    }

    /** The code every `series()` and `total()` is denominated in. */
    public function primaryCurrency(): string
    {
        return $this->primary;
    }

    /**
     * Whether the merchant CHOSE that denomination or it was inferred.
     *
     * A figure drawn with the store's configured symbol while denominated in an inferred currency
     * says two different things at once, which is the defect ANA-TOP part 1 measured on the card.
     */
    public function primaryCurrencyIsConfigured(): bool
    {
        return $this->primaryIsConfigured;
    }

    /**
     * The aggregate row -- "All Categories" / "All Brands" -- as a series in the store's currency.
     *
     * ELEMENTWISE, NOT A RE-SUM, and both halves of that matter. The per-dimension series are
     * already denominated in one currency, so adding them bucket by bucket is arithmetic on like
     * quantities. Re-reading the raw buckets would silently fold the foreign ones back in, which
     * is exactly the defect this class exists to stop -- on the one row a merchant is most likely
     * to read as "my whole month".
     *
     * SUMMED BY ID, WHILE THE DISPLAYED ROWS ARE KEYED BY NAME -- review finding 4, recorded
     * because it is a real behaviour change and the more defensible of the two. The old
     * arithmetic summed the name-keyed array, so two categories (or brands) sharing a name were
     * counted ONCE in the aggregate and collapsed to the last one in the tile list: the aggregate
     * agreed with the table by losing money. This counts both, so on a store with duplicate names
     * the aggregate can exceed the sum of the rows above it. There is no uniqueness constraint on
     * `categories(name, store_id)`, so duplicates are reachable through ordinary data entry. The
     * row list collapsing duplicates is the defect; the aggregate is right. Making the tile list
     * id-keyed would change what every one of these screens displays and is not this slice's.
     *
     * @param  list<int>  $dimensionIds  the rows the screen is showing, so the aggregate covers
     *                                   the SELECTION rather than the catalogue
     * @return array<int, float>
     */
    public function aggregateSeries(array $dimensionIds): array
    {
        $total = array_fill(0, $this->bucketCount, 0.0);

        foreach ($dimensionIds as $id) {
            foreach ($this->series((int) $id) as $bucket => $amount) {
                $total[$bucket] = round($total[$bucket] + $amount, 2);
            }
        }

        return $total;
    }

    /**
     * Every other currency across the given rows, for the aggregate row's own caveat.
     *
     * @param  list<int>  $dimensionIds
     * @return array<string, float>
     */
    public function aggregateOthers(array $dimensionIds): array
    {
        $others = [];

        foreach ($dimensionIds as $id) {
            foreach ($this->others((int) $id) as $code => $amount) {
                $others[$code] = round(($others[$code] ?? 0.0) + $amount, 2);
            }
        }

        ksort($others);

        return $others;
    }

    /**
     * The aggregate row's disclosure. Separate from `caveat()` because the aggregate's total and
     * foreign list are the union, not any one row's.
     *
     * @param  list<int>  $dimensionIds
     */
    public function aggregateCaveat(array $dimensionIds): string
    {
        /* `orderValueHint()`, NOT `orderValue()`, and four pre-existing tests are why. These
           screens already carried the definition PLUS `MetricCaveat::FLOAT_PRECISION`, and the
           first draft of this method replaced the pair with the definition alone -- dropping a
           caveat the screen used to show, which is the exact defect Phase 05's export rule names.
           The precision caveat still applies: this class accumulates money as repeated float
           addition, the same arithmetic the removed handler loops did. */
        $notes = [ReportFigureNote::orderValueHint()];

        if (round(array_sum($this->aggregateSeries($dimensionIds)), 2) <= 0) {
            $notes[] = __('Nothing was earned in your store currency here, so this row has no comparable total.');
        }

        $others = $this->aggregateOthers($dimensionIds);

        if ($others !== []) {
            $amounts = [];

            foreach ($others as $code => $amount) {
                $amounts[] = MoneyText::amount($amount, $code);
            }

            $notes[] = __('Also earned in another currency, which is not added in and not converted: :amounts.', [
                'amounts' => implode(', ', $amounts),
            ]);
        }

        $unrecorded = 0.0;

        foreach ($dimensionIds as $id) {
            $unrecorded += $this->unrecorded((int) $id);
        }

        if (round($unrecorded, 2) > 0) {
            $notes[] = __('Includes :amount from orders that carry no currency, counted in your store currency.', [
                'amount' => MoneyText::amount(round($unrecorded, 2), $this->primary),
            ]);
        }

        return implode(' ', $notes);
    }

    /** Every dimension id this accumulator saw money for. @return list<int> */
    public function dimensionIds(): array
    {
        return array_map('intval', array_keys($this->buckets));
    }

    /**
     * @param  array<int, array<int, array<string, float>>>  $buckets
     * @return array{0: string, 1: bool}
     */
    private static function primaryCurrencyFor(int $storeId, array $buckets): array
    {
        $configured = CurrencySymbol::code(
            (string) (OrderCurrencyStamp::configuredCodeFor($storeId) ?? '')
        );

        if ($configured !== '') {
            return [$configured, true];
        }

        /* No configured currency: denominate in the busiest recorded one rather than refuse every
           figure on the screen. `krsort` before `arsort` so an exact tie resolves by code and not
           by the order the database returned rows in -- PHP 8's sort is stable, so without it two
           engines could denominate the same store differently. */
        $totals = [];

        foreach ($buckets as $byBucket) {
            foreach ($byBucket as $byCurrency) {
                foreach ($byCurrency as $code => $amount) {
                    if ($code !== '') {
                        $totals[$code] = ($totals[$code] ?? 0.0) + $amount;
                    }
                }
            }
        }

        if ($totals === []) {
            return [OrderCurrencyStamp::DEFAULT_CODE, false];
        }

        krsort($totals);
        arsort($totals);

        return [(string) array_key_first($totals), false];
    }
}
