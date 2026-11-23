<?php

namespace App\Support;

use App\Support\Money\CurrencySymbol;
use App\Support\Money\OrderCurrencyStamp;
use App\Support\Metrics\MoneyText;
use App\Support\Reports\ReportFigureNote;
use Illuminate\Support\Facades\DB;

/**
 * F242 — "TOP CATEGORY / TOP BRAND BY SALES", COMPUTED FROM THE REAL PER-LINE VALUES.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG, and it was wrong in two independent ways
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `HomeController::top_brand_category_chart()` built twelve queries that CROSS JOINED a generated
 * series `numbers.n` of 1..100 and matched
 * `products.id = SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)`,
 * then took `SUM(orders.final_price)` and the view divided by 100.
 *
 * **1. THE FAN-OUT IS NOT UNIFORM, so no divisor can correct it.** Nothing bounds `n` to the number
 * of elements in the list. For `n` past the end, `SUBSTRING_INDEX(list, ',', n)` returns the WHOLE
 * list and the `-1` then takes its LAST element. So for an order of `k` products:
 *
 *     products 1 … k-1   match exactly 1 time each
 *     product  k         matches 101 - k times
 *
 * With `k = 1` that is 100 matches and `/100` is exact — which is why the divisor looked right.
 * With `k = 2` the first product's category is credited 1/100th of the order and the second 99/100.
 * With `k = 3`, 1/100, 1/100 and 98/100. The divisor is only ever correct for single-product orders,
 * and every multi-product order under-reports its earlier categories a hundredfold.
 *
 * **2. THE WHOLE ORDER TOTAL WAS ATTRIBUTED TO EVERY MATCHED CATEGORY.** Even with the fan-out
 * removed, `SUM(orders.final_price)` credits each category the ENTIRE order — shipping, tax and all
 * — so a two-category order would be counted twice over. "Top categories by sales" cannot be
 * computed from an order-level total at all; it needs the per-line values.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS DOES INSTEAD
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `orders.product_json` is the authoritative per-line record — it is what the order detail page,
 * the invoice and the replacement engine all read. Each line carries its own product, quantity and
 * price. So revenue is summed per line, attributed to that line's product's category or brand, in
 * plain PHP:
 *
 *   - **No divisor**, arbitrary or otherwise.
 *   - **No cross join**, so no fan-out to compensate for.
 *   - **Correct for 1, 2, 3 and more products**, because each line is read once.
 *   - **Store isolation is explicit on every query.** `Product` registers no `StoreScope` and is
 *     absent from `config/tenancy.php`'s `scoped_models` (the S19 shape), so `whereIn('id', …)`
 *     alone would read every tenant's catalogue. The store predicate is written, not inherited.
 *   - **Date isolation is a half-open window** `[from, to)`, so an order at exactly midnight
 *     belongs to one period and not two.
 *   - **Runs on SQLite as well as MySQL**, which matters more than it sounds: the old query used
 *     `SUBSTRING_INDEX` and `CURDATE`, so the Feature suite could not execute it at all and the
 *     only coverage was a MariaDB script. These figures are now testable in the ordinary suite.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT A LINE'S REVENUE IS, and why the reader is defensive
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Measured across `app/` and the vendored modules, the writers do not agree on key names:
 * `product_id` 97 sites, `price` 56, `final_price` 37, `variant_id` 36, **`quantity` 33 and `qty`
 * 23**. So a reader that knows only one spelling returns zero revenue for a third of the platform's
 * orders — silently, which is the failure mode this card already had.
 *
 * The rule: a line's own stated total (`final_price`, then `total_orignal_price`) wins when it is
 * present and numeric, because that is what the line says it was sold for. Otherwise quantity ×
 * unit price. Quantity reads `qty` then `quantity`; unit price reads `price` then `orignal_price`.
 *
 * **THIS IS PRODUCT REVENUE, NOT ORDER TOTAL, and the difference is deliberate.** Shipping, tax and
 * coupon discounts are order-level and belong to no category, so the sum across categories does not
 * equal the sum of `final_price` — it should not. The card's label says so.
 */
class TopSellersByRevenue
{
    /*
     * ──────────────────────────────────────────────────────────────────────────────────────────
     * ANA-TOP — WHAT A MERCHANT WHO CHANGED CURRENCY WILL ACTUALLY SEE, stated because it is a
     * large visible change and the honest answer at the same time.
     * ──────────────────────────────────────────────────────────────────────────────────────────
     *
     * `StoreCurrencyConversionService::convertStoreCurrentValues()` rewrites `orders.currency_code`
     * on ONLY `payment_type = 'cod'` AND `delivered_status = 0` rows (that service, :138-142 and
     * :205). So a store that switched currency keeps its entire DELIVERED history under the old
     * code -- which is also the measured proof that mixed `currency_code` within one store really
     * happens, rather than being a theoretical worry.
     *
     * On the all-time tab such a store now sees every row's headline figure WITHHELD, with the old
     * currency's money in the caveat beside it, because there is no approved basis for adding the
     * two. Before this change it saw one confident number that was the two added together under one
     * symbol. The new reading is worse-looking and correct; the old one was comfortable and false.
     *
     * NOT HIDDEN FROM THE OWNER: this is the one consequence of the slice a merchant will notice
     * without being told, and it is a product question (offer a per-currency tab? a stated rate?)
     * rather than an engineering one. No conversion is invented here to avoid it.
     */
    public const CATEGORY = 'category';
    public const BRAND = 'brand';

    /* The same last-resort label `FinanceLedgerFigures` uses, and for the same reason: a code is
       needed to name an amount, and inventing a different default here would make two screens
       disagree about an unconfigured store. It labels; it never converts. */
    private const FALLBACK_CURRENCY = 'USD';

    /**
     * @param  string      $dimension  self::CATEGORY | self::BRAND
     * @param  int|string  $storeId
     * @param  string|null $from       inclusive lower bound (Y-m-d H:i:s), or null for all time
     * @param  string|null $to         EXCLUSIVE upper bound, or null for all time
     * @return list<object{sale_name:string, sale_image_path:?string, total_sale:?float, currency:string, currency_is_configured:bool, other_currencies:array<string,float>, unrecorded_currency_amount:float, caveat:string}>
     */
    public static function compute(string $dimension, $storeId, ?string $from = null, ?string $to = null): array
    {
        if (! is_numeric($storeId) || (int) $storeId <= 0) {
            /* No tenant, no figures. Returning every store's revenue because a store did not
               resolve is the shape S19 keeps producing, so it is refused rather than defaulted. */
            return [];
        }

        $storeId = (int) $storeId;
        $revenueByProduct = self::revenueByProduct($storeId, $from, $to);

        if ($revenueByProduct === []) {
            return [];
        }

        [$column, $table, $nameColumn, $imageColumn] = $dimension === self::BRAND
            ? ['brand_id', 'product_brands', 'name', 'logo']
            : ['category_id', 'categories', 'name', 'image_path'];

        /* THE STORE PREDICATE IS THE POINT OF THIS QUERY, not an optimisation. */
        $products = DB::table('products')
            ->where('store_id', $storeId)
            ->whereIn('id', array_keys($revenueByProduct))
            ->whereNotNull($column)
            ->pluck($column, 'id');

        $revenueByDimension = [];

        foreach ($products as $productId => $dimensionId) {
            if (! is_numeric($dimensionId) || (int) $dimensionId <= 0) {
                continue;
            }

            $dimensionId = (int) $dimensionId;

            /* PER CURRENCY, so two currencies are never added. The inner key is the normalised
               code, or `''` for a row whose currency could not be read. */
            foreach ($revenueByProduct[$productId] ?? [] as $currency => $amount) {
                $revenueByDimension[$dimensionId][$currency] =
                    ($revenueByDimension[$dimensionId][$currency] ?? 0.0) + $amount;
            }
        }

        if ($revenueByDimension === []) {
            return [];
        }

        $labels = DB::table($table)
            ->where('store_id', $storeId)
            ->whereIn('id', array_keys($revenueByDimension))
            ->get(['id', $nameColumn . ' as sale_name', $imageColumn . ' as sale_image_path'])
            ->keyBy('id');

        [$primary, $primaryIsConfigured] = self::primaryCurrency($storeId, $revenueByDimension);
        $rows = [];

        foreach ($revenueByDimension as $dimensionId => $byCurrency) {
            if (! isset($labels[$dimensionId])) {
                /* A category or brand belonging to another store, or deleted. Dropped rather than
                   rendered nameless — a row with revenue and no label is worse than no row. */
                continue;
            }

            /*
             * THE UNRECORDED BUCKET FOLDS INTO THE PRIMARY, AND SAYS SO. Dropping it would be the
             * other failure: trading a wrong number for a quietly smaller one. `MetricResult`
             * makes the same choice for an UNKNOWN currency, through
             * `assumingUnknownCurrencyIs()` plus a `CURRENCY_UNRECORDED` caveat, and this follows
             * it.
             *
             * IT DOES NOT FOLLOW IT FOR THE MIXED CASE, and the first version of this comment
             * claimed otherwise. `MetricResult::total()` REFUSES a multi-currency result outright;
             * this publishes the primary bucket as a single number and lists the rest. That is a
             * second rule, defensible for a RANKING -- a ranking with no number cannot be ranked
             * -- but it is a different choice and saying it mirrored the first was wrong.
             */
            $unrecorded = round($byCurrency[''] ?? 0.0, 2);
            $own = round(($byCurrency[$primary] ?? 0.0) + $unrecorded, 2);

            $others = [];

            foreach ($byCurrency as $code => $amount) {
                if ($code === '' || $code === $primary) {
                    continue;
                }

                $amount = round($amount, 2);

                if ($amount > 0) {
                    $others[$code] = $amount;
                }
            }

            if ($own <= 0 && $others === []) {
                continue;
            }

            /* Ordered, so a row's foreign list reads the same way twice. `MoneyText::buckets()`
               ksorts for this reason and an unsorted list here would have been the one place the
               app's currency lists were unstable. */
            ksort($others);

            $row = (object) [
                'sale_name' => (string) $labels[$dimensionId]->sale_name,
                'sale_image_path' => $labels[$dimensionId]->sale_image_path,
                /* `null`, never 0.00, when this row earned nothing in the store's own currency.
                   A zero would rank it against rows that really did earn nothing. */
                'total_sale' => $own > 0 ? $own : null,
                'currency' => $primary,
                /*
                 * WHETHER THE STORE CHOSE THIS CURRENCY OR WE INFERRED IT, because the VIEW has to
                 * know. `currency_format_with_sym()` prints `settings.defult_currancy_symbol` --
                 * a DIFFERENT row from the `defult_currancy` this figure is denominated in. When
                 * the store configured neither, the amount is denominated in the busiest bucket
                 * while the symbol still defaults to `$`: the figure would read `$100.00` and its
                 * own caveat, built from the real code, would say `100.00 EUR` -- disagreeing
                 * about the unit on one line.
                 *
                 * That is the GAP-156 shape again, between two settings ROWS rather than two
                 * stores, and the independent review of this slice found it. False here means the
                 * view must label with the CODE instead of the store's symbol.
                 */
                'currency_is_configured' => $primaryIsConfigured,
                'other_currencies' => $others,
                'unrecorded_currency_amount' => $unrecorded,
            ];

            /* THE CAVEAT IS BUILT HERE, not in the view. A Blade template that calls a static to
               decide what disclosure to print is a second place the rule lives, and the reports
               and exports would each need their own copy of the call. One row, carrying its own
               disclosure, is what lets four surfaces agree. */
            $row->caveat = self::rowCaveat($row);

            $rows[] = $row;
        }

        /* Ranked on the store's OWN currency only, because ranking across currencies would need a
           conversion basis nobody has approved. A row with revenue only in another currency is
           still listed -- after the ranked ones, and never silently dropped -- with its own amount
           disclosed instead of a comparable position it cannot honestly hold. */
        usort($rows, fn ($a, $b) => ($b->total_sale ?? -1) <=> ($a->total_sale ?? -1));

        return $rows;
    }

    /**
     * Which currency this store's ranking is denominated in.
     *
     * THE STORE'S CONFIGURED CODE WHEN IT HAS ONE, because that is the currency the merchant reads
     * every other figure in. Otherwise the bucket carrying the most money, which keeps a store
     * whose setting is missing or unreadable from having its whole ranking classed as foreign.
     *
     * @param  array<int, array<string, float>>  $revenueByDimension
     * @return array{0: string, 1: bool}  the code, and whether the STORE configured it
     */
    private static function primaryCurrency(int $storeId, array $revenueByDimension): array
    {
        /*
         * READ THROUGH `OrderCurrencyStamp`, NOT FROM A QUERY OF OUR OWN.
         *
         * The first draft of this method read `settings.defult_currancy` directly. That made it a
         * SECOND reader of a decision that already had an owner -- and it read one key where the
         * owner reads two. A store configured under the fallback `currency` key was therefore
         * called unconfigured here and the ranking was denominated in whichever currency happened
         * to be busiest, while every order that store placed was being stamped with the configured
         * one. Reading through the stamp also picks up the P1-SEC-80 sanitisation that
         * `Utility::GetValueByName()` applies at the point of read.
         */
        $configured = CurrencySymbol::code(
            (string) (OrderCurrencyStamp::configuredCodeFor($storeId) ?? '')
        );

        if ($configured !== '') {
            return [$configured, true];
        }

        $totals = [];

        foreach ($revenueByDimension as $byCurrency) {
            foreach ($byCurrency as $code => $amount) {
                if ($code !== '') {
                    $totals[$code] = ($totals[$code] ?? 0.0) + $amount;
                }
            }
        }

        if ($totals === []) {
            return [self::FALLBACK_CURRENCY, false];
        }

        /* `krsort` BEFORE `arsort` so an exact tie resolves by currency code rather than by the
           order the database happened to return rows in. PHP 8's sort is stable, so without this
           two engines could denominate an exactly-tied unconfigured store differently -- a
           difference nobody could explain from the data. */
        krsort($totals);
        arsort($totals);

        return [(string) array_key_first($totals), false];
    }

    /**
     * The one-line disclosure a row needs, or '' when it needs none.
     *
     * Built here rather than in the view so the dashboard card, the reports and the exports can
     * carry the same sentence. A figure whose caveat differs between two screens showing the same
     * number is two figures.
     *
     * BUT THE SENTENCES HERE ARE NEW, AND THAT IS A DEBT RATHER THAN A DESIGN. The review of this
     * slice measured it: `MetricCaveat` already owns texts for two of these three situations --
     * "These amounts are in more than one currency and have not been converted." and "Some of
     * these orders predate currency recording and are counted in your store's currency." So the
     * app now has FOUR sentences for two situations, not one each, and the reports and exports are
     * not yet wired to any of them. Reconciling the wording onto `MetricCaveat` belongs with the
     * report/export half of ANA-TOP, where all four surfaces can be changed together; doing it
     * here alone would move the card's wording away from the two screens it already matches.
     *
     * @param  object{currency: string, other_currencies: array<string, float>, unrecorded_currency_amount: float, total_sale: ?float}  $row
     */
    public static function rowCaveat($row): string
    {
        /*
         * WHAT THE FIGURE IS, FIRST, AND FROM THE SHARED SOURCE -- this is the ranking-semantics
         * half of ANA-TOP.
         *
         * The cards were labelled "By Sales" while counting every order line in the window: unpaid,
         * cancelled and refunded at full value, with no payment predicate anywhere
         * (`revenueByProduct()` has none, by design -- owner Option A means
         * `orders.payment_status` is the customer's settlement record and a cancellation does not
         * rewrite it, so no predicate on `orders` could turn this into collected money). It is an
         * ORDER-DEMAND ranking and it now says so.
         *
         * `ReportFigureNote::orderValue()` IS THE APPROVED SENTENCE and §11 makes it the single
         * source both a screen's hint and an export cell read, "so the two cannot drift". Writing a
         * fourth sentence here would be the drift that rule exists to prevent -- and the figure's
         * own definition is exactly what this card never stated: "The total value of items ordered.
         * This is not payments received, and not profit."
         */
        $notes = [ReportFigureNote::orderValue()];

        if (($row->total_sale ?? null) === null) {
            $notes[] = __('Nothing was earned in your store currency here, so this row has no comparable total.');
        }

        if (! empty($row->other_currencies)) {
            $listed = [];

            foreach ($row->other_currencies as $code => $amount) {
                $listed[] = MoneyText::amount((float) $amount, (string) $code);
            }

            $notes[] = __('Also earned in another currency, which is not added in and not converted: :amounts.', [
                'amounts' => implode(', ', $listed),
            ]);
        }

        if (($row->unrecorded_currency_amount ?? 0.0) > 0) {
            $notes[] = __('Includes :amount from orders that carry no currency, counted in your store currency.', [
                'amount' => MoneyText::amount((float) $row->unrecorded_currency_amount, (string) $row->currency),
            ]);
        }

        return implode(' ', $notes);
    }

    /**
     * Sum every order line's revenue per product id, for one store and one window.
     *
     * @return array<int|string, float>
     */
    private static function revenueByProduct(int $storeId, ?string $from, ?string $to): array
    {
        $query = DB::table('orders')->where('store_id', $storeId);

        /* HALF-OPEN `[from, to)`. A closed upper bound counts a midnight order in two periods, and
           `whereDate` would additionally force a per-row function call that no index can serve. */

        /*
         * `created_at`, AND THAT IS DELIBERATE RATHER THAN AN OVERSIGHT -- IT WAS MEASURED.
         * ------------------------------------------------------------------------------------
         * Every other panel on this dashboard, and every report, windows on `orders.order_date`
         * (HomeController has 20-odd such predicates). This one object is the outlier, which
         * looked like a defect worth a slice: two cards on one screen, one "This Month" tab,
         * different sets of orders.
         *
         * THE CENSUS REFUTED THE SWAP. All 19 sites in this repository that create an `orders`
         * row set `order_date` in the same statement block, every one of them to
         * `date('Y-m-d H:i:s')` or `now()` -- the same instant Eloquent stamps into `created_at`.
         * Nothing anywhere updates the column afterwards: there is no merchant-facing order-date
         * editor, so the value is write-once. For every row the application has ever written the
         * two columns therefore agree to the second, and the two windows select the same orders.
         *
         * What the swap WOULD change is the one case where they differ. `order_date` is
         * `dateTime()->nullable()` and `created_at` is not, so a row imported or back-filled
         * outside the application can hold a null `order_date`. Today this card counts such a row
         * and every other panel silently drops it. Swapping would align the card by making it
         * drop the row too -- agreement bought by losing data, which is the wrong direction.
         *
         * So the card keeps `created_at` and the divergence is recorded instead of "fixed".
         * `TopSellersRevenueAccuracyTest` pins both halves: that an order written the way the
         * application writes one lands in the same window under either column, and that a row
         * with a null `order_date` is still counted here.
         *
         * STILL OPEN, and NOT fixed by this slice: the HomeController panels' null-dropping.
         * Correcting those means a fallback on ~20 predicates, several inside the `MONTH(`/
         * `YEAR(` selectRaw handlers that cannot run under this suite at all, so it needs the
         * MySQL harness and a slice of its own.
         */
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<', $to);
        }

        $revenue = [];

        /* Chunked, because a busy store's year has more orders than one array should hold, and the
           old query's cost was the database's problem rather than the request's.

           ANA-TOP: `currency_code` IS NOW SELECTED, and that is the whole defect. This method used
           to return one bare float per product, so a store that changed currency had its USD and
           EUR line amounts added together as plain numbers and the view then printed the total
           under a single symbol. The amount was arithmetic on incommensurable units, and nothing
           on screen said so.

           `CurrencySymbol::code()` is the single definition of "is a currency code" in this
           repository and returns `''` for anything that is not three letters -- which is not
           hypothetical: P5-R14 measured the storefront API writing a SYMBOL into this column, and
           the column is nullable. Those rows land in the `''` bucket and are NOT dropped; see
           `compute()`, which folds them into the store's own currency and discloses the amount. */
        $query->orderBy('id')->select('id', 'product_json', 'currency_code')
            ->chunk(500, function ($orders) use (&$revenue) {
                foreach ($orders as $order) {
                    $lines = json_decode((string) $order->product_json, true);

                    if (! is_array($lines)) {
                        continue;
                    }

                    $currency = CurrencySymbol::code($order->currency_code ?? '');

                    foreach ($lines as $line) {
                        if (! is_array($line)) {
                            continue;
                        }

                        $productId = $line['product_id'] ?? null;

                        if (! is_numeric($productId) || (int) $productId <= 0) {
                            continue;
                        }

                        $amount = self::lineRevenue($line);

                        if ($amount <= 0) {
                            continue;
                        }

                        $productId = (int) $productId;
                        $revenue[$productId][$currency] =
                            ($revenue[$productId][$currency] ?? 0.0) + $amount;
                    }
                }
            });

        return $revenue;
    }

    /**
     * One line's revenue, reading every spelling the platform's writers actually use.
     *
     * Public so a test can drive the key precedence directly rather than inferring it from a total.
     */
    public static function lineRevenue(array $line): float
    {
        foreach (['final_price', 'total_orignal_price'] as $statedTotal) {
            if (isset($line[$statedTotal]) && is_numeric($line[$statedTotal]) && (float) $line[$statedTotal] > 0) {
                return (float) $line[$statedTotal];
            }
        }

        $quantity = 0;

        foreach (['qty', 'quantity'] as $key) {
            if (isset($line[$key]) && is_numeric($line[$key])) {
                $quantity = (int) $line[$key];
                break;
            }
        }

        $unitPrice = 0.0;

        foreach (['price', 'orignal_price'] as $key) {
            if (isset($line[$key]) && is_numeric($line[$key])) {
                $unitPrice = (float) $line[$key];
                break;
            }
        }

        return max(0, $quantity) * max(0.0, $unitPrice);
    }
}
