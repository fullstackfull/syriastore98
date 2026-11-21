<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-80, round 3 (finding C-2) -- a brand's or a category's NAME is not handed to ApexCharts as it is.
 * =================================================================================================
 *
 * The bundled ApexCharts (v3.33.2) writes a series name into the legend and into the tooltip with `innerHTML`, and `sale_by_brand` / `sale_by_category` build one series per brand or category
 * from the report's JSON, on page load. A brand named `<img src=x onerror=...>` therefore ran in the page of whoever opened the report. Executed in Chromium on the bundled library: the raw name
 * fires on render, the same name escaped first shows as text and fires nothing.
 *
 * This is a SOURCE PIN, and says so: the report handler cannot run under the suite (`MONTH()` in `selectRaw`) and the suite runs no browser, so nothing here executes the chart. It pins the one
 * spelling the fix uses -- the name goes through `$('<div>').text(...).html()` -- and the absence of the raw one.
 */
final class ReportChartsEscapeSeriesNamesTest extends TestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function charts(): array
    {
        return ['sales by brand' => ['sale_by_brand', 'brand'], 'sales by category' => ['sale_by_category', 'category']];
    }

    #[DataProvider('charts')]
    public function test_a_series_name_is_escaped_before_apexcharts_gets_it(string $view, string $variable): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/reports/' . $view . '.blade.php');

        $this->assertMatchesRegularExpression('/\bname:\s*\$\(\'<div>\'\)\.text\(\s*' . $variable . '\.name\b.*?\)\.html\(\)/', $source, 'premise: the series name is built from the ' . $variable . ' by the escaping spelling');
        $this->assertDoesNotMatchRegularExpression('/\bname:\s*' . $variable . '\.name\s*,/', $source, 'the ' . $variable . '\'s name reaches ApexCharts raw');
    }
}
