<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P5-3 export integration -- the category and brand CSV exports must carry EVERY series, not just the first.
 * ============================================================================================================
 *
 * The on-screen category and brand charts walk `data.NetSaleTotal.forEach(...)` -- one series per category / per brand.
 * The download handlers in the same views built their CSV with `data.NetSaleTotal[0].data[i]` inside a `for` over the
 * periods, so the exported file held ONLY the first category / brand, repeated down every row and stamped with that one
 * name. A merchant with ten brands exported a file about one. The fix walks the same `forEach` the chart uses and emits
 * one row per (series, period), quoting the merchant-typed name the way the header row is already quoted.
 *
 * This is a SOURCE PIN and says so: nothing in the suite runs a Blade view's JavaScript (the export is built client-side),
 * and the handlers this pins cannot run under the suite (MONTH()/YEAR() in selectRaw). It pins the shape in both views:
 *
 * NOTE, corrected 2026-11-21: "ReportController cannot run under the suite AT ALL" -- which this file
 * used to say, following CLAUDE.md -- is too strong. `order_category_reports` (1893-2360) and
 * `order_brand_reports` (2382-2903) hold ZERO MySQL-only constructs and ARE suite-reachable. That
 * does not weaken this source pin (the suite runs no browser either), but it does mean a reader
 * must check the handler's own range before concluding it cannot be tested.
 * the per-row first-series read is gone, the row loop walks every series, and the name is CSV-quoted rather than
 * interpolated raw. The next spelling is for the next reader.
 */
final class ReportExportBuildsEverySeriesTest extends TestCase
{
    /** @return array<string,array{0:string,1:string}> view path, the series variable name the chart/export use */
    public static function exportViews(): array
    {
        return [
            'category' => ['resources/views/reports/sale_by_category.blade.php', 'category'],
            'brand' => ['resources/views/reports/sale_by_brand.blade.php', 'brand'],
        ];
    }

    private static function view(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
    }

    #[DataProvider('exportViews')]
    public function test_the_export_does_not_read_only_the_first_series(string $relative, string $noun): void
    {
        $view = self::view($relative);

        // the executable bug signature -- a per-row read of the first series' data. It lived only in the export row loop;
        // the empty-guard and the comments speak of `NetSaleTotal[0].name`/`.length`, never `[0].data[`.
        $this->assertStringNotContainsString('NetSaleTotal[0].data[', $view,
            $relative . ": the export still reads data.NetSaleTotal[0] per row, so it exports only the first {$noun}. Walk data.NetSaleTotal.forEach and emit one row per series.");
    }

    #[DataProvider('exportViews')]
    public function test_the_export_walks_every_series_and_quotes_the_name(string $relative, string $noun): void
    {
        $view = self::view($relative);

        // forEach appears twice: once for the chart, once for the export row loop. Before the fix it appeared once.
        $this->assertGreaterThanOrEqual(2, substr_count($view, 'data.NetSaleTotal.forEach'),
            $relative . ': the export row loop must walk every series with data.NetSaleTotal.forEach, as the chart does.');

        // the merchant-typed name is quoted through the shared helper, not interpolated raw into the row
        $this->assertStringContainsString("csvField({$noun}.name)", $view,
            $relative . ": the {$noun} name must be CSV-quoted (csvField({$noun}.name)), not interpolated raw -- a name with a comma or quote would corrupt the row.");
        $this->assertDoesNotMatchRegularExpression('/\$\{\s*' . $noun . 'Name\s*\}/', $view,
            $relative . ": a raw \${{$noun}Name} interpolation remains in a CSV row; quote it with csvField().");
    }

    public function test_the_pins_are_not_vacuous(): void
    {
        // the forbidden pattern is exactly what the bug was, and the required helper call is exactly the fix
        $buggy = 'var netSale = data.NetSaleTotal[0].data[i];';
        $this->assertStringContainsString('NetSaleTotal[0].data[', $buggy, 'premise: the forbidden pattern matches the shape that was found');
        $fixed = "csvField(category.name)";
        $this->assertStringContainsString('csvField(category.name)', $fixed, 'premise: the required pattern matches the fix');
    }
}
