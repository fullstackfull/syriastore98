<?php

namespace App\Support\Reports;

/**
 * The display label for a report row whose ARRAY KEY is also its label.
 *
 * `ReportController::order_brand_reports()` and `order_category_reports()` both return a map of
 * `name => money`, where every key is a brand or category the MERCHANT named -- except one. Each
 * map carries a single extra row for the aggregate, under a literal the application itself wrote:
 * `'All Brands'` (:2401, :2405, :2471, :2475) and `'All Categories'` (:1927, :2001, :2085, :2167).
 *
 * `order_brand_chart.blade.php:12` and `order_category_chart.blade.php:12` iterate that map and
 * pass the key straight to `<x-ss.stat :label="$name">`, so the key is what a merchant reads. The
 * merchant's own names are already in whatever language they typed; the aggregate was stuck in
 * English, and `shoot-fragments.cjs` photographed "All Brands" sitting in an Arabic report.
 *
 * TWO RULES, AND THE SECOND IS THE IMPORTANT ONE.
 *
 * 1. The key stays English. `ReportController` reads `$NetSalesofbrand['All Brands']` back by that
 *    literal to build the summary figure, so translating the key itself would break the lookup in
 *    Arabic. Same split F154 drew for order statuses: a stable machine identifier, and a label
 *    resolved for display only.
 *
 * 2. ONLY THE PINNED KEYS ARE TRANSLATED -- never `__($name)` on whatever arrives. Every other key
 *    in those maps is merchant data. `__()` returns the key unchanged when it misses, which makes
 *    a blanket call look harmless right up until a merchant's record name COLLIDES with one of the
 *    1,400+ keys in `resources/lang/ar.json`: a brand called "Save" would be shown to an Arabic
 *    merchant as "حفظ", and a category called "Products" would silently change its name on the
 *    report. The application may translate the two strings it authored and nothing else.
 *
 * Pinned by tests/Unit/Reports/AggregateSeriesLabelTest.
 */
final class AggregateSeriesLabel
{
    /**
     * The only labels in those maps that the application authored rather than the merchant.
     *
     * Adding a key here is a claim that NO merchant record can legitimately be called that, so it
     * takes a reason. Both of these are produced by ReportController itself and are never read
     * from the database.
     */
    public const AGGREGATE_KEYS = [
        'All Brands',
        'All Categories',
    ];

    public static function display(string $key): string
    {
        if (! in_array($key, self::AGGREGATE_KEYS, true)) {
            return $key;
        }

        return __($key);
    }
}
