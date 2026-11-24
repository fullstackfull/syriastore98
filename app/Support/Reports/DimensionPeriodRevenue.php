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

    /**
     * P5-R7-A. THE PRODUCT ITSELF AS A DIMENSION, for `order_product_reports` -- the third copy
     * of this figure, which ANA-TOP did not cover and which still added currencies together.
     *
     * The mapping is the identity: for a category the accumulator asks the catalogue which
     * `category_id` a line's product carries, and for a product it asks which `id` it carries,
     * which is the product id it already has. That sounds like a no-op and is NOT one -- the
     * lookup is `where('store_id', ...)->whereIn('id', ...)`, so it is what drops a line naming
     * ANOTHER TENANT'S product. `Product` registers no `StoreScope` and is absent from
     * `config/tenancy.php`'s `scoped_models`, so without that query a product id is a global
     * identifier and the report counts whatever the line names.
     */
    public const PRODUCT = 'product';

    /** @var array<int, array<int, array<string, float>>> dimension id => bucket => currency => amount */
    private array $buckets = [];

    /**
     * @var array<int, array<int, int>> dimension id => bucket => units
     *
     * UNITS ARE CURRENCY-FREE, so they are not bucketed by currency and are never withheld: a
     * count of things ordered means the same whatever was paid for them. `order_product_reports`
     * shows a money series and a units series on one chart, and only the money one has a
     * currency problem. Keeping them in one accumulator is what stops the two series being built
     * from two different passes over the orders and disagreeing about which rows are in window.
     */
    private array $quantities = [];

    /**
     * How many order LINES were inside the window but left out of every figure.
     *
     * F3 (independent review). A line is dropped when its `product_id` is not in this store's
     * `products` -- the product was hard-deleted (`Product` has no SoftDeletes), its `store_id`
     * is NULL (the column is nullable), or it belongs to another tenant. The exclusion is right
     * for a per-product report, and it was SILENT: for the seven-day and date-range arms it is
     * also NEW, because those two previously summed every line of every order with no product
     * filter at all. So the tile could be strictly less than the window's order value with
     * nothing on the screen accounting for the difference -- the GAP-223(a) class.
     *
     * A COUNT AND NOT A SUM, deliberately. The dropped lines can be in any mix of currencies, so
     * a total of them is the very figure this class exists to refuse to state. Saying how many
     * lines were left out discloses the gap without inventing a denomination for it.
     */
    private int $droppedLines = 0;

    private string $primary;

    private bool $primaryIsConfigured;

    private int $bucketCount;

    private function __construct(
        array $buckets,
        string $primary,
        bool $primaryIsConfigured,
        int $bucketCount,
        array $quantities = [],
        int $droppedLines = 0
    ) {
        $this->buckets = $buckets;
        $this->primary = $primary;
        $this->primaryIsConfigured = $primaryIsConfigured;
        $this->bucketCount = $bucketCount;
        $this->quantities = $quantities;
        $this->droppedLines = $droppedLines;
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
        $column = match ($dimension) {
            self::BRAND => 'brand_id',
            self::PRODUCT => 'id',
            default => 'category_id',
        };

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
        $quantities = [];
        $droppedLines = 0;

        foreach ($decoded as [$bucket, $currency, $lines]) {
            foreach ($lines as $line) {
                if (! is_array($line) || ! isset($line['product_id'])) {
                    continue;
                }

                $productId = (int) $line['product_id'];

                if (! array_key_exists($productId, $owned)) {
                    /* Counted, not summed -- see `$droppedLines`. */
                    $droppedLines++;

                    continue;
                }

                $dimensionId = (int) $owned[$productId];
                $amount = (float) ($line['final_price'] ?? 0);

                $buckets[$dimensionId][$bucket][$currency]
                    = ($buckets[$dimensionId][$bucket][$currency] ?? 0.0) + $amount;

                /* `qty` OR `quantity`, because the order JSON carries both spellings -- the
                   handlers this replaces each normalised one to the other before reading it, and
                   a line written by the older of the two writers has only `quantity`. Defaulting
                   to 1 is those handlers' own behaviour, preserved rather than tightened. */
                $units = $line['qty'] ?? $line['quantity'] ?? 1;

                $quantities[$dimensionId][$bucket]
                    = ($quantities[$dimensionId][$bucket] ?? 0) + (int) $units;
            }
        }

        [$primary, $configured] = self::primaryCurrencyFor($storeId, $buckets);

        return new self($buckets, $primary, $configured, $bucketCount, $quantities, $droppedLines);
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

        /*
         * F4 (independent review), AND THE NUMERIC RULE IS DELIBERATELY UNCHANGED.
         *
         * The review is right that zero is a legitimate answer -- a quiet week, a new store --
         * and that `null` makes the tile read "Not available" for it. I tried returning 0.0 in
         * that case and TWO EXISTING ASSERTIONS went red: `DimensionPeriodRevenueTest`'s "a
         * dimension with no money still answers a zero series" and "another tenant's product in
         * our order is dropped, not attributed" both encode `null` on purpose, and the category
         * and brand screens have shipped it. Changing the number here is exactly the drift the
         * review warned about, so it is not changed.
         *
         * What WAS false is the sentence, and that is fixed in `caveat()`: the claim "nothing was
         * earned in your store currency here" may only be made when money in another currency
         * actually exists. For a store that simply had no orders it implied foreign trade that
         * does not exist.
         */
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

        if ($this->primaryIsConfigured && $this->primary !== '') {
            $notes[] = $this->denominationNote();
        }

        /*
         * F4: THE CLAIM IS ABOUT MONEY IN ANOTHER CURRENCY, so it may only be made when some
         * exists. This fired whenever the total was unstateable, which includes the commonest
         * case on these screens by far -- no orders in the window at all. A merchant whose quiet
         * week showed "Not available" was then told "nothing was earned in your store currency
         * here", which implies trade in some other currency that never happened.
         *
         * `$others` and not `total() === null` is the condition, because `$others` IS the money
         * the sentence is about. With no orders it is empty and the tile says only what the
         * shared note says.
         */
        if ($this->total($dimensionId) === null && $this->others($dimensionId) !== []) {
            $notes[] = __('Nothing was earned in your store currency here, so this row has no comparable total.');
        }

        if ($this->droppedLines > 0) {
            $notes[] = $this->droppedLinesNote();
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
    /**
     * The per-bucket UNITS series for one dimension.
     *
     * NO CURRENCY BRANCHING, because a count of units ordered is not denominated in anything. So
     * unlike `series()` this folds nothing and withholds nothing -- every bucket's units are the
     * units, whatever currencies the orders behind them were placed in. `order_product_reports`
     * draws this beside the money series on one chart.
     *
     * @return array<int, int>
     */
    public function quantitySeries(int $dimensionId): array
    {
        $series = array_fill(0, $this->bucketCount, 0);

        foreach ($this->quantities[$dimensionId] ?? [] as $bucket => $units) {
            $series[$bucket] = (int) $units;
        }

        return $series;
    }

    /**
     * The units series summed across several dimensions, bucket by bucket.
     *
     * ADDS THE PER-DIMENSION SERIES, not the raw buckets -- the same decision `aggregateSeries()`
     * records, and for the same reason: a dimension that appears twice in `$dimensionIds` must be
     * counted twice here exactly as it is there, so the two series a chart draws agree about
     * which dimensions they cover.
     *
     * @return array<int, int>
     */
    public function aggregateQuantitySeries(array $dimensionIds): array
    {
        $total = array_fill(0, $this->bucketCount, 0);

        foreach ($dimensionIds as $id) {
            foreach ($this->quantitySeries((int) $id) as $bucket => $units) {
                $total[$bucket] += $units;
            }
        }

        return $total;
    }

    /**
     * Which currency the figure is counted in, as one sentence.
     *
     * P5-R7-A, review area 6. The CSV's money column did not say what it was denominated in, and
     * the review's suggestion -- a term in the Blade header array -- CANNOT WORK: that array is
     * rendered as Blade and asserted to parse as JSON by `ReportCsvHeaderLocalisationTest`, which
     * also pins its column count, so a runtime value cannot live in it. I tried and took four of
     * its cases red.
     *
     * The disclosure belongs in the caveat instead, which both the screen's `:hint` and the
     * export's caveat column already carry, and which is built HERE -- so one sentence covers the
     * product, category and brand screens and all three of their files at once, rather than three
     * header hacks. Only when the store HAS configured a currency: an unconfigured store's code is
     * a default, and naming a default as if the merchant had chosen it is worse than silence.
     */
    private function denominationNote(): string
    {
        return __('Amounts are counted in :code.', ['code' => $this->primary]);
    }

    /** How many in-window order lines were left out, as one sentence. See `$droppedLines`. */
    private function droppedLinesNote(): string
    {
        return __(':count order line(s) in this period are left out because this store does not own the product they name.', [
            'count' => $this->droppedLines,
        ]);
    }

    /** Total units across every bucket of several dimensions. */
    public function aggregateQuantityTotal(array $dimensionIds): int
    {
        return (int) array_sum($this->aggregateQuantitySeries($dimensionIds));
    }

    /**
     * Total money across every bucket of several dimensions, or null when none can be stated.
     *
     * `> 0 ? : null` IS `total()`'s RULE, COPIED RATHER THAN RE-DERIVED. The first version asked
     * instead whether the dimensions had ANY buckets, which is a different question and the wrong
     * one: a store whose every order is in a foreign currency HAS buckets -- they are just all in
     * currencies this figure may not add -- so it answered `0.00`, a claim that nothing was
     * ordered, for a store that had traded 90 USD. Found by a test, and fixed by using the
     * sibling's definition, because ten report arms already depend on that one and a second
     * rule for the same question is how the arms drifted apart in the first place.
     */
    public function aggregateTotal(array $dimensionIds): ?float
    {
        $total = round((float) array_sum($this->aggregateSeries($dimensionIds)), 2);

        /* `> 0 ? : null` is `total()`'s rule, and `total()` records why F4 did not change it. */
        return $total > 0 ? $total : null;
    }

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

        if ($this->primaryIsConfigured && $this->primary !== '') {
            $notes[] = $this->denominationNote();
        }

        /* F4: see `caveat()` -- the sentence is about money in another currency and may only be
           made when some exists. `aggregateOthers()` is computed just below; it is asked here
           rather than re-deriving "<= 0", which could not tell a quiet period from an
           unstateable one. */
        if ($this->aggregateTotal($dimensionIds) === null && $this->aggregateOthers($dimensionIds) !== []) {
            $notes[] = __('Nothing was earned in your store currency here, so this row has no comparable total.');
        }

        if ($this->droppedLines > 0) {
            $notes[] = $this->droppedLinesNote();
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
