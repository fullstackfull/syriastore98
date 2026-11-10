<?php

namespace Tests\Unit\Reports;

use App\Support\Reports\AggregateSeriesLabel;
use Tests\TestCase;

/**
 * P2.5-WEB-FRAGMENTS — the brand and category report cards printed an English aggregate label
 * to an Arabic merchant.
 * =================================================================================================
 *
 * MEASURED. `shoot-fragments.cjs` captured `.chart_data` on `/Brand-order-sale-reports` and
 * `/category-order-sale-reports` in all four combinations and read **"All Brands"** out of the
 * Arabic ones. Evidence: evidence/web-fragments-2026-11-10/after/brand-sales-body__ar_rtl_*.png.
 *
 * WHY IT SURVIVED. `ReportController` builds `$NetSalesofbrand` as a map whose KEY is the brand
 * name, and appends one extra entry under the literal key `'All Brands'` for the aggregate
 * (:2401, :2405, :2471, :2475). `order_brand_chart.blade.php:12-13` then does
 * `@foreach ($NetSalesofbrand as $name => $saleAmount)` and passes `:label="$name"` straight
 * through. So the ARRAY KEY IS THE DISPLAY LABEL, and a key is not a translatable string.
 *
 * `All Categories` was already translated in `resources/lang/ar.json` ('جميع الفئات'). The
 * translation was never the problem -- nothing ever called `__()` on it.
 *
 * WHY THIS IS AN ALLOWLIST AND NOT `__($name)`.
 *
 * Every other key in that array is a MERCHANT RECORD NAME -- a brand or category the merchant
 * typed. Running those through `__()` would silently translate the merchant's own data whenever
 * it happened to collide with a translation key: a brand named "Save" would render as "حفظ" to
 * an Arabic merchant, and a category named "Products" would change name on the report. There are
 * 1,400+ keys in ar.json, so collisions are not hypothetical. The aggregate rows are the only two
 * strings in that array the application itself authored, so they are the only two it may
 * translate.
 *
 * The KEY stays English on purpose: `ReportController` reads `$NetSalesofbrand['All Brands']`
 * back by that literal (:2405, :2475). Translating the key would break the lookup in Arabic --
 * the same stable-identifier-versus-display-label split F154 drew for order statuses.
 */
class AggregateSeriesLabelTest extends TestCase
{
    public function test_it_translates_the_two_aggregate_keys_the_application_authored(): void
    {
        app()->setLocale('ar');

        $this->assertSame(__('All Categories'), AggregateSeriesLabel::display('All Categories'));
        $this->assertSame(__('All Brands'), AggregateSeriesLabel::display('All Brands'));
    }

    public function test_the_arabic_aggregate_labels_are_actually_arabic(): void
    {
        app()->setLocale('ar');

        foreach (AggregateSeriesLabel::AGGREGATE_KEYS as $key) {
            $label = AggregateSeriesLabel::display($key);

            $this->assertNotSame($key, $label,
                "The aggregate key [$key] resolved to itself in Arabic, which means the key is "
                . 'missing from resources/lang/ar.json. A merchant reading the brand or category '
                . 'report in Arabic sees the English literal.');

            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $label,
                "The Arabic label for [$key] contains no Arabic letter.");
        }
    }

    /**
     * THE PROPERTY THAT MATTERS MOST. A merchant's own brand and category names must come back
     * byte-identical, whatever the locale and whatever they are called.
     */
    public function test_it_never_touches_a_merchant_record_name(): void
    {
        app()->setLocale('ar');

        $names = [
            'Damascene Silk',       // real fixture brands, read off the captures
            'Aleppo Weave',
            'Homs Cotton',
            'Scarves & Shawls',     // real fixture categories
            'Homeware',
            'Soaps',
            'Save',                 // a name that COLLIDES with a UI translation key
            'Products',
            'Delete',
            'حرير دمشقي',           // an Arabic brand name stays Arabic
            '',                     // degenerate, must not throw
        ];

        foreach ($names as $name) {
            $this->assertSame($name, AggregateSeriesLabel::display($name),
                "AggregateSeriesLabel rewrote the merchant record name [$name]. Only the two "
                . 'aggregate keys the application itself authored may be translated.');
        }
    }

    public function test_english_is_unchanged_so_no_existing_report_reading_moves(): void
    {
        app()->setLocale('en');

        $this->assertSame('All Brands', AggregateSeriesLabel::display('All Brands'));
        $this->assertSame('All Categories', AggregateSeriesLabel::display('All Categories'));
    }
}
