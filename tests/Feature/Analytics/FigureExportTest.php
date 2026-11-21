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
 * layer through Eloquent. `ReportController` cannot run under the Feature suite (36 `MONTH(` / 9
 * `YEAR(` in `selectRaw`, absent from SQLite, so every handler answers HTTP 500 in a test).
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
