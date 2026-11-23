<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P5-R7-B — THE SALES SCREEN SHOWS PAYMENTS RECEIVED AND THE FILE IT DOWNLOADS DOES NOT.
 * =================================================================================================
 *
 * ANA-005 put a ledger-sourced "Payments received" tile on the sales report: `_sales_stats.blade.php`
 * renders it from `ReportController::salesCollectionTile()`, which goes through
 * `FinanceLedgerFigures` to `CollectedCustomerPayments`. Both the line chart and the bar chart
 * include that partial, so both screens show demand AND collected money side by side.
 *
 * THE EXPORTS DO NOT, AND THE REASON IS ONE LINE IN EACH HANDLER. Measured: the chart handlers
 * pass the tile to the VIEW --
 *
 *     'collection' => $this->salesCollectionTile($request, $periodLabel),
 *
 * -- at `ReportController.php:1258` and `:1801`, inside the `view(...)` argument, and never put it
 * in `$return`. The exports replay the SESSION (`Session::get('order_line_chart_report')`), so the
 * figure cannot reach them: a merchant reading the page sees what customers paid, and the
 * spreadsheet they download has only what customers were asked to pay. Phase 05's rule is that an
 * export carries the same caveats as the screen, and this is the largest one it drops.
 *
 * WHAT IS *NOT* WRONG HERE, because an earlier pass overstated it. The claim was that these files
 * "lack any statement that the money is order demand rather than payments received". That is
 * REFUTED by their column headers, which carry the settled D1/D2 labels verbatim -- "Order value",
 * "Average order value", "Order value, including shipping and tax" (`order_report.blade.php:303-313`,
 * `bar_chart_order_report.blade.php:316-326`). Those names already say demand. And
 * `ReportFigureNote::moneyRowHint()` carrying only the PLURAL precision caveat is deliberate and
 * documented: these rows have nine columns, six of them money, so no single definition covers a
 * row. The gap is the MISSING LEDGER FIGURE, not a mislabelled one.
 *
 * WHY THE SCREENS THEMSELVES ARE NOT DRIVEN HERE. Measured 2026-11-23: `order_reports_chart()`
 * holds 5 `MONTH(` and 1 `YEAR(` in `selectRaw`, and `order_reports_chart_data()` holds 12 and 4.
 * SQLite implements neither, so both answer HTTP 500 under this suite before any arithmetic runs --
 * CLAUDE.md's standing warning. Converting them is a far larger change than the product report's
 * two constructs and is not this slice's. So the split is the one `OperatorUnchosenStoreReportsTest`
 * and `LastMonthWindowOverflowTest` already use: the EXPORT handlers are independently reachable
 * and driven over HTTP with a seeded session; the two chart handlers' contribution is pinned in
 * source. The premise test below fails if that reachability ever changes, so this file cannot
 * quietly keep testing less than it claims.
 */
class SalesExportCarriesTheCollectedFigureTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function exports(): array
    {
        return [
            'line chart' => ['order_line_chart_report', '/order-report-export'],
            'bar chart' => ['order_bar_chart_report', '/order-barchart-report-export'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exports')]
    public function test_the_export_replays_the_collected_payments_figure(string $sessionKey, string $uri): void
    {
        [$merchant] = $this->makeTenant();

        /* The session as the SCREEN leaves it, including the tile the screen rendered. Seeded
           rather than driven because the chart handlers are not suite-reachable -- see the
           docblock -- and seeding is what the export handler actually reads. */
        Session::put($sessionKey, [
            'monthList' => ['01-Jan'],
            'NetSaleTotal' => [100.0],
            'AverageNetSales' => [100.0],
            'GrossSaleTotal' => [110.0],
            'AverageGrossSales' => [110.0],
            'TotalOrderCount' => [1],
            'PurchasedItemTotal' => [1],
            'ShippingTotal' => [10.0],
            'CouponTotal' => [0.0],
            'collection' => [
                'value' => '40.00 EUR',
                'period' => 'This month',
                'hint' => 'Money customers actually paid, counted on the day it was paid.',
            ],
        ]);

        $response = $this->actingAs($merchant->fresh())->get($uri);

        $this->assertSame(200, $response->status(), $uri . ': the export handler did not run');

        $payload = $response->json();
        $this->assertIsArray($payload, $uri . ': the export did not return JSON');

        $this->assertArrayHasKey('collection', $payload,
            $uri . ': the screen shows a ledger-sourced "Payments received" figure and the file it '
            . 'downloads does not carry it, so the spreadsheet is less honest than the page');

        $this->assertSame('40.00 EUR', $payload['collection']['value'] ?? null,
            $uri . ': the collected figure was not replayed verbatim from what the screen showed');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exports')]
    public function test_an_absent_collected_figure_is_replayed_as_absent_not_invented(string $sessionKey, string $uri): void
    {
        /* `salesCollectionTile()` returns NULL for an operator with no store chosen, for a window
           the report cannot express, and for a store id of 0 (`ReportController:616-640`). A null
           is "the question cannot be asked", not "no money" -- so the export must hand the builder
           nothing rather than a zero it would print as a figure. */
        [$merchant] = $this->makeTenant();

        Session::put($sessionKey, [
            'monthList' => ['01-Jan'],
            'NetSaleTotal' => [100.0],
        ]);

        $response = $this->actingAs($merchant->fresh())->get($uri);

        $this->assertSame(200, $response->status());

        $payload = $response->json();
        $this->assertArrayHasKey('collection', $payload, $uri . ': the key is missing entirely');
        $this->assertNull($payload['collection'],
            $uri . ': an unavailable ledger figure was replayed as something other than null, so '
            . 'the file can print a number the screen refused to state');
    }

    public function test_both_chart_handlers_put_the_tile_in_the_session_not_only_in_the_view(): void
    {
        /*
         * THE SOURCE HALF of the split, and the defect was exactly this shape: the tile was
         * computed inside the `view(...)` argument list, so it reached the browser's HTML and
         * nothing else. A test can only see this in source, because the handlers cannot run here.
         */
        $src = $this->sourceWithoutComments('app/Http/Controllers/ReportController.php');

        $this->assertSame(2, preg_match_all('/salesCollectionTile\(/', $src) - 1,
            'the number of salesCollectionTile() call sites changed; this file assumes the two '
            . 'chart handlers plus the definition');

        foreach (['order_line_chart_report', 'order_bar_chart_report'] as $key) {
            $put = strpos($src, "Session::put('" . $key . "'");
            $this->assertIsInt($put, $key . ': the session write is gone');

            /* The handler's own body, back to its signature. */
            $fn = strrpos(substr($src, 0, $put), 'public function ');
            $this->assertIsInt($fn);
            $body = substr($src, $fn, $put - $fn);

            $this->assertMatchesRegularExpression(
                "/\\\$return\\['collection'\\]\\s*=/",
                $body,
                $key . ": the handler computes the ledger tile for the screen but never puts it in "
                . '$return, so the session the export replays cannot contain it'
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exports')]
    public function test_the_disclosure_cell_the_file_prints_carries_the_collected_figure(string $sessionKey, string $uri): void
    {
        /*
         * THE FIGURE REACHES THE FILE THROUGH `figureNote`, THE COLUMN THE BUILDERS ALREADY WRITE,
         * and that is why neither Blade changed. My first version of this case asserted that the
         * builders read a `collection` key -- they do not need to, because the sentence is
         * composed server-side by `ReportFigureNote::salesRowHint()` and dropped into the cell the
         * builders have printed since P05-15. Asserting the source would have pinned a mechanism
         * instead of the outcome; this asserts the outcome.
         *
         * It goes in the disclosure column rather than an eleventh column on purpose: every other
         * column here is per period and this is one total for the whole window, so a column would
         * repeat it on every row and read as that row's collected amount.
         */
        [$merchant] = $this->makeTenant();

        Session::put($sessionKey, [
            'monthList' => ['01-Jan'],
            'NetSaleTotal' => [100.0],
            'collection' => ['value' => '40.00 EUR', 'period' => 'This month', 'hint' => 'x'],
        ]);

        $note = (string) $this->actingAs($merchant->fresh())->get($uri)->json('figureNote');

        $this->assertStringContainsString('40.00 EUR', $note,
            $uri . ': the cell the CSV prints on every row does not carry the collected figure, so '
            . 'the downloaded file still shows only what customers were asked to pay');

        $this->assertStringContainsString(
            \App\Support\Metrics\MetricCaveat::message(\App\Support\Metrics\MetricCaveat::MONEY_FLOAT_PRECISION),
            $note,
            $uri . ': the precision caveat these files already carried was dropped to make room, '
            . 'which is the defect this work exists to prevent, committed in reverse'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exports')]
    public function test_the_disclosure_cell_states_no_figure_when_the_ledger_cannot_answer(string $sessionKey, string $uri): void
    {
        /* A null tile is "the question cannot be asked", not "nothing was collected". The cell
           must then say exactly what it said before this change -- not "0.00", and not a sentence
           with an empty value in it. */
        [$merchant] = $this->makeTenant();

        Session::put($sessionKey, ['monthList' => ['01-Jan'], 'collection' => null]);

        $note = (string) $this->actingAs($merchant->fresh())->get($uri)->json('figureNote');

        $this->assertSame(
            \App\Support\Metrics\MetricCaveat::message(\App\Support\Metrics\MetricCaveat::MONEY_FLOAT_PRECISION),
            $note,
            $uri . ': an unavailable ledger figure changed the disclosure cell, so the file says '
            . 'something about collected money that the ledger could not support'
        );
    }

    public function test_a_tile_present_but_valueless_is_treated_as_unavailable(): void
    {
        /* `FinanceLedgerFigures::tile()` returns an ARRAY with a null `value` for several
           unavailable cases -- a window the ledger does not cover, a store with no rows -- so the
           guard has to look one level in, not merely at the tile being present. */
        [$merchant] = $this->makeTenant();

        Session::put('order_line_chart_report', [
            'monthList' => ['01-Jan'],
            'collection' => ['value' => null, 'period' => 'This month', 'hint' => 'not covered yet'],
        ]);

        $note = (string) $this->actingAs($merchant->fresh())->get('/order-report-export')->json('figureNote');

        $this->assertStringNotContainsString('Payments received over', $note,
            'a tile whose value is null produced a sentence promising a figure it does not have');
    }

    public function test_the_two_chart_handlers_are_still_the_unreachable_ones_this_file_declares(): void
    {
        /*
         * The split above is load-bearing: the exports are DRIVEN, the chart handlers are PINNED.
         * If a later change removes the MySQL-only constructs the handlers become reachable and
         * these cases should be promoted to behavioural ones, exactly as P5-R7-A did for the
         * product report. This fails then, which is the point.
         */
        $src = $this->sourceWithoutComments('app/Http/Controllers/ReportController.php');

        foreach (['order_reports_chart', 'order_reports_chart_data'] as $method) {
            $at = strpos($src, 'public function ' . $method . '(');
            $this->assertIsInt($at, $method . ' is gone from the controller');

            $next = strpos($src, 'public function ', $at + 20);
            $body = substr($src, $at, $next === false ? strlen($src) - $at : $next - $at);

            $this->assertGreaterThan(0, preg_match_all('/\bMONTH\s*\(/', $body),
                $method . ' no longer holds MONTH(, so it may now be suite-reachable -- drive the '
                . 'screen and promote the source pins in this file to behavioural cases');
        }
    }
}
