<?php

namespace Tests\Feature\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P5-8 — THE EXPORTS OF THE HONEST FIGURES.
 *
 * Phase 05's rule for this slice: an export carries the same caveats as the screen, and one that
 * drops a caveat is a defect. These are the three things that can go wrong with that, so these are
 * what is asserted:
 *
 *  1. THE DISCLOSURE IS A COLUMN, not a footnote, so sorting or copying in a spreadsheet cannot
 *     separate a number from its limits.
 *  2. A NULL IS THE WORDS, not a blank cell. An empty cell in a spreadsheet reads as zero, which is
 *     the exact misreading this whole workstream exists to prevent.
 *  3. THE GATE IS THE SCREEN'S GATE. An export route with a weaker guard than the surface it
 *     exports is a standard way to lose a control -- and for the platform file it would lose it
 *     across every tenant at once.
 *
 * Why these exports are testable at all, when `ReportController`'s six are not: they read the metric
 * layer through Eloquent. MOST of `ReportController` cannot (37 `MONTH(` / 10
 * `YEAR(` in `selectRaw`, absent from SQLite, so THOSE handlers answer HTTP 500 in a test).
 *
 * Corrected 2026-11-21: "every handler" was wrong. `order_category_reports` and
 * `order_brand_reports` hold zero MySQL-only constructs and are suite-reachable, which is what
 * makes ANA-007 closable with a real test rather than only an executable script.
 */
final class FigureExportTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const MERCHANT_URI = '/analytics/export';

    private const PLATFORM_URI = '/platform/analytics/export';

    private const PERMISSION = 'Manage Reports';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /** @return list<list<string>> the file parsed back into rows */
    private function parse(string $body): array
    {
        $body = preg_replace('/^\x{FEFF}/u', '', $body);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $body);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function paidOrder($store, string $currency, float $amount): void
    {
        $order = $this->makeOrder($store, ['order_date' => CarbonImmutable::now()->subDay()]);

        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'paid',
            'currency_code' => $currency,
            'paid_amount' => $amount,
            'remaining_amount' => 0.00,
            'paid_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The merchant export
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_guest_cannot_download_the_merchant_export(): void
    {
        $response = $this->get(self::MERCHANT_URI);

        $this->assertTrue($response->isRedirect(), 'the export was served to a guest');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client');
    }

    public function test_a_staff_member_with_no_report_permission_cannot_download_it(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $this->assertFalse($staff->isAbleTo(self::PERMISSION), 'Premise: this principal holds no report permission.');

        $response = $this->actingAs($staff->fresh())->get(self::MERCHANT_URI);

        $this->assertTrue($response->status() === 403 || ($response->isRedirect() && session()->has('error')),
            'a principal with no report permission downloaded the figures (status '.$response->status().')');
    }

    public function test_the_seeded_report_permission_downloads_it(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);

        $response = $this->actingAs($holder->fresh())->get(self::MERCHANT_URI);

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * THE FILE'S ROWS MUST BE THE SCREEN'S TILES. If a tile is added to the Blade and not to the
     * export's label list, the merchant silently downloads less than they were shown.
     */
    public function test_the_file_carries_every_figure_the_screen_shows(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $rows = $this->parse($this->actingAs($merchant->fresh())->get(self::MERCHANT_URI)->getContent());

        $labels = array_column(array_slice($rows, 1), 0);

        foreach (['Orders', 'Page views', 'Conversion', 'Buyers', 'Repeat purchase',
                  'Payments received', 'Estimated gross profit', 'Coupon discounts given'] as $tile) {
            $this->assertContains($tile, $labels, "the export is missing the screen's '{$tile}' figure");
        }
    }

    /**
     * EVERY ROW CARRIES ITS OWN PERIOD AND ITS OWN DISCLOSURE. This is the whole point of the
     * slice: four columns, and the last two are never empty for a figure that has them on screen.
     */
    public function test_every_row_carries_its_own_period_and_disclosure(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $rows = $this->parse($this->actingAs($merchant->fresh())->get(self::MERCHANT_URI)->getContent());

        $this->assertSame(['Figure', 'Value', 'Period', 'What this leaves out'], $rows[0]);

        foreach (array_slice($rows, 1) as $row) {
            $this->assertCount(4, $row, 'every row must have all four columns: '.implode(' | ', $row));
            $this->assertNotSame('', trim($row[2]), "'{$row[0]}' was exported with no period");
            $this->assertNotSame('', trim($row[3]), "'{$row[0]}' was exported with no disclosure");
        }
    }

    /**
     * A NULL IS THE WORDS "Not available", NEVER A BLANK. `Conversion` is the reliable null here:
     * it is a ratio and there are no page views in this fixture, so there is nothing to divide by.
     */
    public function test_an_unavailable_figure_exports_as_words_and_not_as_a_blank(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $rows = $this->parse($this->actingAs($merchant->fresh())->get(self::MERCHANT_URI)->getContent());

        $conversion = null;
        foreach ($rows as $row) {
            if (($row[0] ?? null) === 'Conversion') {
                $conversion = $row;
            }
        }

        $this->assertNotNull($conversion, 'Premise: the Conversion row must be in the file.');
        $this->assertSame('Not available', $conversion[1],
            'an unavailable figure must export as words; a blank cell reads as zero in a spreadsheet');
    }

    /**
     * The bidi isolates the figures add for HTML must not reach a CSV cell: a spreadsheet treats
     * them as text, so `=SUM()` over the column fails and the cell shows a stray glyph.
     */
    public function test_the_value_column_holds_no_bidi_isolate(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->makeOrder($store, ['order_date' => CarbonImmutable::now()->subDay()]);

        $rows = $this->parse($this->actingAs($merchant->fresh())->get(self::MERCHANT_URI)->getContent());

        foreach (array_slice($rows, 1) as $row) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2066}-\x{2069}]/u', $row[1],
                "'{$row[0]}' exported its value with a bidi isolate still in it");
        }
    }

    /**
     * EVERY CELL, AND THE PLATFORM FILE, because `FigureCsv`'s docblock claims the invariant is
     * TOTAL -- "NO isolate ever reaches the file" -- and it was not.
     *
     * MEASURED BY AN INDEPENDENT REVIEW on the real download: `currencyRows()` plained only the
     * amount, so the DISCLOSURE column carried U+2066/U+2069 around the counts in "At least 1 paid
     * orders ..." and "Stores left out ...: 1" -- for the payments block as well as the new profit
     * one. `rows()` and `breakdownRows()` were both corrected on 2026-11-22 and the third builder
     * was missed, which the test above could not see for two reasons at once: it reads column 1
     * only, and it drives the MERCHANT file, whose rows do not go through that builder at all.
     *
     * Both halves are fixed here: every column, and the surface that actually has the builder.
     */
    public function test_no_column_of_the_platform_file_holds_a_bidi_isolate(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        // A cost-less paid order gives the cost caveat a count to wrap; a store the ledger cannot
        // vouch for gives the excluded-store sentence one. Both counts go through `ltr()`.
        $this->paidOrder($storeA, 'USD', 100.00);
        \Illuminate\Support\Facades\DB::table('store_order_financials')->update(['product_cost' => 0.00]);
        $this->makeOrder($storeB, ['order_date' => CarbonImmutable::now()->subDay()]);
        \Illuminate\Support\Facades\DB::table('store_order_financials')
            ->where('store_id', $storeB->id)->delete();

        $operator = $this->makePlatformAdmin();
        $rows = $this->parse($this->actingAs($operator->fresh())->get(self::PLATFORM_URI)->getContent());

        $withIsolates = 0;

        foreach (array_slice($rows, 1) as $row) {
            foreach ($row as $column => $cell) {
                if (preg_match('/[\x{2066}-\x{2069}]/u', (string) $cell)) {
                    $withIsolates++;
                }
            }
        }

        $this->assertSame(0, $withIsolates,
            'a platform export cell still carries a bidi isolate. They are correct on the rendered '
            . 'page and wrong in a data file: invisible, and they defeat an exact-match filter or a '
            . 'lookup against the same text typed by hand.');
    }

    /**
     * THE PROFIT ROWS REPEAT THE NOT-ADDED SENTENCE TOO, which the payments-only test above did not
     * cover although the controller's own comment makes that per-row travel the reason for the
     * change. A spreadsheet is the one tool that invites a reader to total a column.
     */
    public function test_each_profit_row_repeats_that_the_rows_are_not_added(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->paidOrder($storeA, 'USD', 100.00);
        $this->paidOrder($storeB, 'SYP', 250.00);

        $operator = $this->makePlatformAdmin();
        $rows = $this->parse($this->actingAs($operator->fresh())->get(self::PLATFORM_URI)->getContent());

        $profit = array_values(array_filter(array_slice($rows, 1),
            fn ($row) => str_starts_with($row[0], 'Estimated gross profit — ')));

        $this->assertCount(2, $profit,
            'premise failed: the fixture did not produce one profit row per currency, so there is '
            . 'nothing per-row to assert.');

        foreach ($profit as $row) {
            $this->assertStringContainsString('NOT added together', $row[3],
                'every currency row must repeat that the rows are not to be totalled');
            $this->assertStringContainsString('prices with tax included', $row[3],
                'the tax disclosure is on the screen and not in the file');
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The platform export
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_merchant_holding_manage_reports_cannot_download_the_platform_export(): void
    {
        [$merchant] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);

        $holder = $holder->fresh();

        // Premise: the same permission really does open the merchant export.
        $this->actingAs($holder)->get(self::MERCHANT_URI)->assertStatus(200);

        $this->actingAs($holder)->get(self::PLATFORM_URI)->assertStatus(403);
    }

    public function test_the_operator_downloads_the_platform_export(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())->get(self::PLATFORM_URI)->assertStatus(200);
    }

    /**
     * THE PLATFORM FILE'S ROWS MUST BE THE PLATFORM SCREEN'S FIGURES — the twin of
     * `test_the_file_carries_every_figure_the_screen_shows`, which this file had only for the
     * MERCHANT side.
     *
     * THAT ASYMMETRY IS WHY A FIGURE WENT MISSING, and it has now happened twice in this
     * codebase. `AnalyticsExportRows::breakdownLabels()`'s own comment records the first:
     * "ADDED after shipping the campaign breakdown to the SCREEN and not to the FILE." The second
     * is *Repeat purchase*, which `PlatformAnalyticsFigures::forPlatform()` returns and
     * `platform/analytics.blade.php` renders, but which never reached the CSV — because
     * `PlatformAnalyticsController::export()` hands `FigureCsv::rows()` a hard-coded label map and
     * `FigureCsv::rows()` iterates the LABELS, not the figures. A figure with no label is dropped
     * in silence.
     *
     * So the gap was never that anyone forgot; it is that the merchant side has a test which
     * fails when the two drift and the operator side had none. This is that test.
     *
     * WHAT IS DERIVED AND WHAT IS NOT, stated exactly, because an earlier draft of this docblock
     * overclaimed. The KEYS are derived from `forPlatform()`, so a figure added to the screen and
     * not to the export's map fails this test without anyone remembering to update it. The
     * LABELS are not derived -- they are listed below. They cannot be taken from the controller's
     * map, which is the thing under test, and taking them from the Blade would be brittle. So the
     * key set is a real ratchet and the label text is a second record that a reader must keep
     * honest; the `assertArrayHasKey` below is what makes a new key impossible to ignore.
     *
     * AND THE LABEL MATCH IS EXACT ON THE CELL, not a substring. A mutation changing the export's
     * label from "Repeat purchase" to "Repeat purchases" SURVIVED the first version of this test,
     * because the longer string contains the shorter one. The money figure is the one exception:
     * its rows carry the label plus a currency, so it matches as a prefix.
     */
    public function test_the_platform_file_carries_every_figure_the_platform_screen_shows(): void
    {
        /*
         * TWO TENANTS PAYING IN TWO CURRENCIES, so the per-currency money figures are on their
         * populated branch and their cells read "<label> — <CURRENCY>". Without this the whole
         * platform screen answers from its zero-store branches and every money cell is the bare
         * label, which makes the prefix limb below unreachable -- see its comment.
         */
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->paidOrder($storeA, 'USD', 100.00);
        $this->paidOrder($storeB, 'SYP', 250.00);

        $operator = $this->makePlatformAdmin();

        $figures = \App\Support\Metrics\PlatformAnalyticsFigures::forPlatform();

        /*
         * BOTH money figures, not just one. Found by the independent review: a premise covering
         * `payments` alone leaves `profit` free to be empty, in which case its cell falls back to
         * the bare label and the first limb matches again -- the very fallback this premise exists
         * to close.
         */
        foreach (['payments', 'profit'] as $moneyFigure) {
            $this->assertNotEmpty($figures[$moneyFigure]['rows'],
                "premise failed: '{$moneyFigure}' produced no per-currency money rows, so the "
                . 'prefix limb of the match below is unreachable for it and this test is back to '
                . 'proving only that a bare label exists.');
        }

        // `coverage` is a LedgerCoverage object, not a figure; it is rendered as a sentence and
        // has no row of its own. Anything else in the array IS a figure and must reach the file.
        $notFigures = ['coverage'];
        $expected = array_diff(array_keys($figures), $notFigures);

        $this->assertNotEmpty(array_intersect(array_keys($figures), $notFigures),
            'premise failed: the excluded keys are not in the figure array, so this test is '
            . 'excluding nothing and its expectation is not what it claims.');
        $this->assertGreaterThan(3, count($expected),
            'premise failed: fewer figures than the three the export has always carried, so the '
            . 'figure source itself has changed shape and this test needs re-reading.');

        $rows = $this->parse($this->actingAs($operator->fresh())->get(self::PLATFORM_URI)->getContent());
        $cells = array_column(array_slice($rows, 1), 0);

        // The file is keyed by LABEL, not by key, so each figure is looked for by the label the
        // screen gives it. A key with no entry here is a figure nobody has decided how to name --
        // which is itself the bug this test exists to catch.
        /*
         * THE SINGLE-VALUE LABELS ARE NOW READ FROM THE CONTROLLER, not re-declared here. The two
         * money blocks stay hard-coded because they do not go through `FigureCsv::rows()`'s map at
         * all -- `export()` calls `currencyRows()` for each -- so there is no source map to read.
         */
        $labelFor = \App\Http\Controllers\PlatformAnalyticsController::figureLabels() + [
            'payments' => __('Payments collected'),
            // A per-currency money block, so its cells read "<label> — <CURRENCY>".
            'profit' => __('Estimated gross profit'),
        ];

        /*
         * THE BREAKDOWN SET IS SOURCE-DERIVED, NOT A THIRD HARD-CODED LIST. A breakdown is a
         * figure of a DIFFERENT SHAPE -- `{rows, period, hint}`, with no `value` -- so its cell in
         * the file is not the bare label: `FigureCsv::breakdownRows()` writes
         * "<label> — <row label>" per row, and the bare label only for an empty breakdown.
         *
         * THIS TEST CAUGHT MY OWN CHANGE, which is the whole point of it. Adding `devices` to
         * `forPlatform()` made it fail, because its premise -- "everything that is not `coverage`
         * is a figure with a `value`" -- was true when written and stopped being true the moment
         * the screen gained a figure of another shape. Reading the breakdown labels from
         * `PlatformAnalyticsController::breakdownLabels()` keeps the expectation derived from the
         * source on BOTH shapes, so the next breakdown added to the screen and not to the export
         * is still a red test rather than a silent omission.
         */
        $breakdownLabels = \App\Http\Controllers\PlatformAnalyticsController::breakdownLabels();

        $this->assertNotEmpty($breakdownLabels,
            'premise failed: the platform export declares no breakdowns, so the breakdown half of '
            . 'this assertion checks nothing.');

        foreach ($expected as $key) {
            $isBreakdown = array_key_exists($key, $breakdownLabels);

            $this->assertTrue($isBreakdown || array_key_exists($key, $labelFor),
                "the platform screen gained the figure '{$key}' and this test does not know what "
                . 'the file should call it. Name it here AND in '
                . 'PlatformAnalyticsController::export() (or its breakdownLabels(), if it is a '
                . 'breakdown), or the download silently omits it.');

            $label = $isBreakdown ? $breakdownLabels[$key] : $labelFor[$key];

            /*
             * Exact on the cell, or "<label> — <currency or row>" for the two shapes that emit one
             * row each. Never a bare substring: a cell that merely CONTAINS the expected text is
             * drift, not a match. That distinction already caught one defect here, where "Repeat
             * purchases" satisfied a containment check for "Repeat purchase".
             *
             * THE MONEY LIMB USED TO READ `' ('` AND WAS DEAD CODE. Measured while adding
             * `profit`: `FigureCsv::currencyRows()` writes "<label> — <CURRENCY>", never
             * "<label> (<CURRENCY>)", so no money cell has ever satisfied that limb. The test
             * passed anyway because this method seeded NO STORE, so `$coverage['included']` was
             * empty, every money figure took its zero-store branch and emitted the bare label --
             * which the first limb matches. A money figure dropped from the export would have been
             * caught only by its absence, not by its label, and a RENAMED one not at all. The
             * method now seeds two tenants in two currencies, so the prefix limb is the one that
             * has to hold.
             */
            $matched = array_filter($cells, fn ($cell) => $cell === $label
                || str_starts_with($cell, $label . ' — '));

            $this->assertNotEmpty($matched,
                "the platform export has no row labelled exactly '{$label}'. Either the figure is "
                . 'missing from export()\'s label map -- which drops it from the download with no '
                . 'error, because FigureCsv::rows() iterates the map and not the figures, and its '
                . 'array_key_exists(\'value\') limb skips a breakdown outright -- or the export '
                . 'labels it differently from the screen.');
        }

        /*
         * AND THE OTHER DIRECTION, which this test did not assert until a mutation showed why it
         * has to. Deleting a figure from `forPlatform()` left its entry in the export's label map,
         * `FigureCsv::rows()` skipped it silently -- an absent figure looks exactly like a
         * breakdown to its `array_key_exists('value')` limb -- and the expectation above, being
         * derived from the now-shorter figure array, expected nothing. The map and the source were
         * free to disagree in one of the two possible ways.
         */
        foreach (array_keys(\App\Http\Controllers\PlatformAnalyticsController::figureLabels()) as $mapped) {
            $this->assertArrayHasKey($mapped, $figures,
                "the platform export's label map names the figure '{$mapped}' and "
                . 'PlatformAnalyticsFigures::forPlatform() does not produce it, so FigureCsv::rows() '
                . 'skips it and the column disappears from the download with no error anywhere.');
        }
    }

    /**
     * EACH CURRENCY IS ITS OWN ROW, THE SUM IS ABSENT, AND EVERY ROW REPEATS THE SENTENCE SAYING
     * SO. A spreadsheet is the tool that invites someone to total a column, so the disclosure has
     * to travel on each row rather than once.
     */
    public function test_each_currency_is_its_own_row_and_none_holds_the_sum(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->paidOrder($storeA, 'USD', 100.00);
        $this->paidOrder($storeB, 'SYP', 250.00);

        $operator = $this->makePlatformAdmin();
        $rows = $this->parse($this->actingAs($operator->fresh())->get(self::PLATFORM_URI)->getContent());

        $money = array_values(array_filter(array_slice($rows, 1),
            fn ($row) => str_contains($row[0], 'Payments collected')));

        $this->assertCount(2, $money, 'one row per currency was expected');

        $amounts = array_map(fn ($row) => $row[1], $money);
        $this->assertContains('100.00', $amounts);
        $this->assertContains('250.00', $amounts);
        $this->assertNotContains('350.00', $amounts, 'the two currencies were summed in the file');

        foreach ($money as $row) {
            $this->assertStringContainsString('NOT added together', $row[3],
                'every currency row must repeat that the rows are not to be totalled');
        }
    }

    /**
     * The disclosures contain commas and colons. A hand-rolled `implode(',')` would split them
     * across cells -- which is the class of bug `category_order_export` carried until P5-3.
     */
    public function test_a_disclosure_containing_commas_stays_in_one_cell(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $rows = $this->parse($this->actingAs($merchant->fresh())->get(self::MERCHANT_URI)->getContent());

        $withCommas = 0;
        foreach (array_slice($rows, 1) as $row) {
            $this->assertCount(4, $row);

            if (str_contains($row[3], ',')) {
                $withCommas++;
            }
        }

        $this->assertGreaterThan(0, $withCommas,
            'Premise: at least one disclosure must contain a comma, or this proves nothing.');
    }
}
