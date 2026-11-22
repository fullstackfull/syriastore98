<?php

namespace Tests\Feature\Analytics;

use App\Models\Order;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Shetabit\Visitor\Models\Visit;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-004 / GAP-227(a) — A WINDOW THAT STARTS BEFORE PAGE-VIEW LOGGING DID INFLATES THE RATE.
 * =============================================================================================
 *
 * `conversion` is orders ÷ page views over the SAME window. Orders go back as far as the store
 * does. Page views only go back as far as `shetabit_visits` has rows for this store, and that
 * table begins whenever `ActiveTheme` first logged one — when the store first set a theme, or
 * when the feature shipped, whichever is later.
 *
 * So for any window that reaches back past that first row, the numerator covers the whole window
 * and the denominator covers only part of it. The quotient is not a conversion rate; it is a
 * larger number that looks like one. A merchant asking "this year" on a store whose logging began
 * in October reads a rate several times the truth and has nothing on screen to tell them.
 *
 * WHY THE EXISTING HINT DOES NOT COVER THIS. `conversion()` already warns when
 * `$orders > $visits`: "some orders arrived by a route that logs no page view". That is the
 * EXTREME case. The ordinary one — a few hundred orders against a truncated few thousand views —
 * stays well under that threshold and prints silently. Measured below: a rate reported as 20.00%
 * whose covered-period value is 5.00%, with no warning of any kind on the old code.
 *
 * THIS IS A DISCLOSURE, NOT A RECOMPUTATION. The figures are not silently re-scoped to the
 * covered period: doing that would answer a question the merchant did not ask and would make the
 * tile disagree with the Orders tile beside it. The window stays as asked and the tile says what
 * part of it has page-view data. Which of the two a merchant wants is a product question; saying
 * nothing is wrong under either answer.
 *
 * NOT AN OWNER DECISION: no threshold, no monetary rule, no new policy. The coverage start is a
 * fact already in the table (`MIN(created_at)` for the store), and stating a fact the screen
 * already depends on is the ANA-007 requirement itself.
 */
class VisitCoverageStartTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function visit($store, string $when): void
    {
        $visit = new Visit();
        $visit->timestamps = false;
        $visit->store_id = $store->id;
        $visit->created_at = now()->parse($when);
        $visit->updated_at = now()->parse($when);
        $visit->save();
    }

    private function order($store, string $when): void
    {
        DB::table('orders')->insert([
            'product_order_id' => 'VC-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id, 'customer_id' => 0, 'is_guest' => 1,
            'order_source' => Order::SOURCE_STOREFRONT, 'final_price' => 10.00,
            'order_date' => now()->parse($when), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * THE RATE WITHOUT ITS BIDI ISOLATES. Every figure on this screen goes through `ltr()`, which
     * wraps it in U+2066 / U+2069 so a Latin numeral does not reorder itself inside an Arabic
     * sentence. That is correct and must stay, so a test comparing against a bare `'20.00%'`
     * compares against something the screen never produces. Stripping the isolates here keeps the
     * assertions about the ARITHMETIC rather than about the wrapper.
     */
    private function rate(array $conversion): ?string
    {
        return $conversion['value'] === null
            ? null
            : str_replace(["\u{2066}", "\u{2069}"], '', $conversion['value']);
    }

    private function conversionOver($store, string $from, string $to): array
    {
        return MerchantAnalyticsFigures::forStore(
            $store->id,
            $store->currency ?? 'USD',
            null,
            AnalyticsPeriod::fromRequest([
                AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
                AnalyticsPeriod::KEY_FROM => $from,
                AnalyticsPeriod::KEY_TO => $to,
            ], now()->parse($to)->endOfDay())
        )['conversion'];
    }

    /**
     * THE DEFECT ITSELF, with arithmetic chosen so the numbers say it out loud.
     *
     * Window: 1 Jan - 28 Feb. Orders: 10, spread across both months. Page views: 50, ALL of them
     * in February, because logging began on 1 February.
     *
     * Reported rate = 10 / 50 = 20.00%.
     * Rate over the period that actually has data = 5 orders / 50 views = 10.00%.
     *
     * The tile prints 20.00% either way -- that is the disclosure decision above. What it must not
     * do is print it with nothing to say that half the window has no page-view data at all.
     */
    public function test_the_tile_discloses_that_page_view_data_starts_after_the_window_does(): void
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['2026-01-10', '2026-01-20', '2026-01-25', '2026-02-05', '2026-02-10'] as $d) {
            $this->order($store, $d . ' 10:00:00');
            $this->order($store, $d . ' 11:00:00');
        }

        for ($i = 0; $i < 50; $i++) {
            $this->visit($store, '2026-02-01 12:00:00');
        }

        $conversion = $this->conversionOver($store, '2026-01-01', '2026-02-28');

        $this->assertSame('20.00%', $this->rate($conversion),
            'Premise: the inflated rate is not what this fixture produces, so the test below measures nothing.');

        $this->assertStringContainsString('1 Feb 2026', $conversion['hint'],
            'The window reaches back past the first page view and the tile does not say so, so the '
            . 'rate reads as a whole-window figure when half the window has no page-view data.');
    }

    /**
     * THE OTHER DIRECTION, and it is the half that keeps the disclosure honest. A permanent
     * "data may be incomplete" note on every render is not a disclosure, it is wallpaper: it
     * carries no information and merchants stop reading it, which costs the real warning above
     * its meaning. A window entirely inside the covered period must say nothing.
     */
    public function test_a_window_entirely_inside_the_covered_period_carries_no_warning(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store, '2026-01-01 09:00:00');

        for ($i = 0; $i < 20; $i++) {
            $this->visit($store, '2026-02-10 12:00:00');
        }

        $this->order($store, '2026-02-11 10:00:00');

        $conversion = $this->conversionOver($store, '2026-02-01', '2026-02-28');

        $this->assertSame('5.00%', $this->rate($conversion),
            'Premise: this fixture does not produce the rate the assertion below is about.');

        $this->assertStringNotContainsString('Page views for this store begin', $conversion['hint'],
            'A window fully inside the covered period was warned about anyway, which turns the '
            . 'warning into wallpaper and costs the real one its meaning.');
    }

    /**
     * COVERAGE IS PER STORE, not per platform. Store B logging since last year must not make
     * store A's two-week-old log look complete. `shetabit_visits` carries `store_id` but is NOT in
     * `config/tenancy.php`'s `scoped_models`, so no global scope filters it for anyone -- the
     * predicate has to be written, and a missing one is invisible in a single-tenant fixture.
     */
    public function test_the_coverage_start_is_this_stores_own(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->visit($storeB, '2025-01-01 09:00:00');

        for ($i = 0; $i < 20; $i++) {
            $this->visit($storeA, '2026-02-10 12:00:00');
        }

        $this->order($storeA, '2026-01-15 10:00:00');

        $conversion = $this->conversionOver($storeA, '2026-01-01', '2026-02-28');

        $this->assertStringContainsString('10 Feb 2026', $conversion['hint'],
            "Store A's coverage start was read from a row belonging to another store, so a tenant "
            . 'with a long log makes every other tenant look fully covered.');
    }

    /**
     * THE COUNT ITSELF IS SHORT, so the note belongs on the visits tile and not only on the rate
     * derived from it. A merchant who reads "1,240 page views" over a year that only has two
     * months of logging has been told a wrong number about their own storefront, whether or not
     * they go on to look at the conversion tile.
     *
     * Written because a mutation proved it was missing: deleting the note from the visits tile
     * left every other test in this file green.
     */
    public function test_the_visits_count_carries_the_same_note(): void
    {
        [$merchant, $store] = $this->makeTenant();

        for ($i = 0; $i < 20; $i++) {
            $this->visit($store, '2026-02-10 12:00:00');
        }

        $figures = MerchantAnalyticsFigures::forStore(
            $store->id,
            $store->currency ?? 'USD',
            null,
            AnalyticsPeriod::fromRequest([
                AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
                AnalyticsPeriod::KEY_FROM => '2026-01-01',
                AnalyticsPeriod::KEY_TO => '2026-02-28',
            ], now()->parse('2026-02-28')->endOfDay())
        );

        $this->assertSame('20', $this->rate(['value' => $figures['visits']['value']]),
            'Premise: the visits tile is not showing the truncated count this test is about.');

        $this->assertStringContainsString('10 Feb 2026', $figures['visits']['hint'],
            'The visits COUNT is short by everything before logging began and does not say so, so '
            . 'the number reads as a whole-period total.');
    }

    /**
     * THE BOUNDARY, in the direction that matters. A window starting on the SAME DAY coverage
     * does is fully covered and must stay silent -- off by one day in that direction puts a
     * warning on every correctly-covered period, which is the wallpaper failure above.
     *
     * The comparison is made on whole days deliberately: coverage begins at a clock time
     * (the first request logged, 09:00 here) while a chosen window begins at midnight, so a
     * naive instant comparison would warn about every window that starts on the coverage day.
     */
    public function test_a_window_starting_on_the_coverage_day_itself_is_silent(): void
    {
        [$merchant, $store] = $this->makeTenant();

        for ($i = 0; $i < 10; $i++) {
            $this->visit($store, '2026-02-01 09:00:00');
        }

        $this->order($store, '2026-02-15 10:00:00');

        $conversion = $this->conversionOver($store, '2026-02-01', '2026-02-28');

        $this->assertStringNotContainsString('Page views for this store begin', $conversion['hint'],
            'A window starting on the very day coverage begins was warned about, so every fully '
            . 'covered period carries a warning and the warning stops meaning anything.');
    }

    /**
     * A STORE WITH A LOG BUT NO VIEWS IN THIS WINDOW takes the existing refusal, not a coverage
     * sentence. This is a different case from the never-logged one below -- here `MIN(created_at)`
     * returns a real date, so the guard that matters is the one on the COUNT, not the one on the
     * start. A mutation removing it survives every other test in this file.
     */
    public function test_a_logged_store_with_no_views_in_the_window_keeps_the_refusal(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store, '2026-06-01 09:00:00');
        $this->order($store, '2026-02-11 10:00:00');

        $conversion = $this->conversionOver($store, '2026-01-01', '2026-02-28');

        $this->assertNull($conversion['value']);
        $this->assertStringContainsString('nothing to divide by', $conversion['hint']);
        $this->assertStringNotContainsString('Page views for this store begin', $conversion['hint'],
            'A period with no page views at all was given a partial-coverage sentence as well as '
            . 'the refusal, which answers one question with two different answers.');
    }

    /**
     * THE EARLIER WINDOW HAS THE SAME PROBLEM AND IS THE ONE NOBODY LOOKS AT.
     *
     * Raised by an independent review of the first version of this change, which disclosed only
     * the CHOSEN window. A window can start AFTER coverage -- so it is fully covered and rightly
     * silent -- while the equally long window BEFORE it straddles the coverage start. Its count
     * is then a real, non-zero number taken over only part of its window, so the delta between
     * the two looks sound and is not: "up 120% on the previous 30 days" when traffic in fact fell.
     *
     * The delta is WITHHELD rather than shown with a caveat. A percentage that is wrong by an
     * unknown amount is not improved by a sentence beside it; a merchant reads the number.
     */
    public function test_a_baseline_window_that_straddles_coverage_gets_no_comparison(): void
    {
        [$merchant, $store] = $this->makeTenant();

        // Coverage begins mid-baseline: the chosen window (last 30 days) is fully covered, the
        // 30 days before it are half covered.
        $this->visit($store, now()->subDays(45)->toDateTimeString());

        for ($i = 0; $i < 10; $i++) {
            $this->visit($store, now()->subDays(40)->toDateTimeString());
            $this->visit($store, now()->subDays(5)->toDateTimeString());
        }

        $figures = MerchantAnalyticsFigures::forStore($store->id, $store->currency ?? 'USD');

        $this->assertNull($figures['visits']['comparison'],
            'The page-views tile compared against a half-covered earlier window, so it can report '
            . 'a rise or a fall that is an artefact of when logging started.');
        $this->assertNull($figures['conversion']['comparison'],
            'The conversion rate compared against a half-covered earlier window.');

        $this->assertStringContainsString('No change against the earlier period is shown',
            $figures['visits']['hint'],
            'The comparison vanished with no explanation, so a merchant sees an inconsistent '
            . 'screen rather than a stated limit.');
    }

    /**
     * THE OTHER SIDE OF THAT BOUNDARY, and it is a contract rather than an edge case. A baseline
     * window lying WHOLLY before coverage counts zero, and `comparison()` has a deliberate answer
     * for zero -- "There is no figure for the earlier period to compare against" -- which several
     * tests in `AnalyticsComparisonTest` and `ConversionBaselineTest` encode.
     *
     * A first version of this guard fired on that case too and reddened seven of them. Replacing
     * a stated answer with silence is a regression, not a fix, so the guard is narrowed to the
     * straddling case and this test holds it there.
     */
    public function test_a_baseline_wholly_before_coverage_keeps_the_existing_zero_answer(): void
    {
        [$merchant, $store] = $this->makeTenant();

        // Coverage begins inside the CHOSEN window, so the whole baseline window precedes it.
        for ($i = 0; $i < 10; $i++) {
            $this->visit($store, now()->subDays(5)->toDateTimeString());
        }

        $figures = MerchantAnalyticsFigures::forStore($store->id, $store->currency ?? 'USD');

        $this->assertNotNull($figures['visits']['comparison'],
            'The deliberate zero-baseline answer was suppressed, which replaces a stated result '
            . 'with silence.');
        $this->assertSame('0', str_replace(["\u{2066}", "\u{2069}"], '',
            $figures['visits']['comparison']['baseline']));
    }

    /**
     * NO ROWS AT ALL IS NOT A COVERAGE PROBLEM, it is the no-page-views case the tile already
     * handles by refusing the division. A coverage sentence on top of that would be a second,
     * confusing answer to a question already answered.
     */
    public function test_a_store_that_has_never_logged_a_page_view_keeps_the_existing_refusal(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->order($store, '2026-02-11 10:00:00');

        $conversion = $this->conversionOver($store, '2026-01-01', '2026-02-28');

        $this->assertNull($conversion['value']);
        $this->assertStringContainsString('nothing to divide by', $conversion['hint']);
        $this->assertStringNotContainsString('Page views for this store begin', $conversion['hint'],
            'A store with no page views at all was given a coverage-start sentence, which answers '
            . 'the same question twice and in two different ways.');
    }
}
