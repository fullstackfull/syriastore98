<?php

namespace Tests\Feature\Analytics;

use App\Support\Metrics\MerchantAnalyticsFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shetabit\Visitor\Models\Visit;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-002 — "Comparisons, filters and saved periods." The comparison half.
 * =================================================================================================
 *
 * Measured 2026-11-17: no period-over-period figure, prior-period baseline, delta, percent change
 * or growth rate existed anywhere in either repository. This is the first one, and the reason it
 * gets its own file is that a comparison is the easiest figure in an analytics screen to make
 * *confidently wrong*: it does not error, it does not look odd, and a merchant reads it as a fact
 * about their business rather than as an arithmetic result.
 *
 * SO THE THINGS ASSERTED HERE ARE THE THINGS THAT COULD BE SILENTLY FALSE:
 *
 *  1. The baseline is the RIGHT 30 days. An order 31 days back must land in the baseline and one
 *     61 days back must land in neither period. That is the boundary test, and it is driven through
 *     the real aggregator against real rows rather than against `MetricWindow` in isolation.
 *  2. A zero baseline yields NO percentage. Forty orders against nothing is not "+100%", not
 *     "+4000%" and not infinity; it is a start from nothing, and the only true statement is the
 *     absolute change plus the fact that there was no prior figure.
 *  3. Zero against zero IS computable and is not a refusal — 0.0%, direction `same`.
 *  4. The MONEY tiles get no comparison at all, and that is measured here so it cannot be added
 *     later without somebody reading why: they inherit `LedgerCoverage`'s verified start, and a
 *     baseline reaching 31-60 days back lands partly before `store_order_financials` holds
 *     anything, so the prior figure would be understated by an unknown amount.
 *
 * The instant is fixed so "the last 30 days" is the same window in every run, which is the same
 * reason `MerchantAnalyticsFiguresTest` fixes its own.
 */
final class AnalyticsComparisonTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-20 12:00:00');
    }

    private function logVisit(int $storeId, CarbonImmutable $when): void
    {
        $visit = new Visit();
        // The row's own date is the point of the fixture, so Eloquent must not stamp it with "now".
        $visit->timestamps = false;
        $visit->store_id = $storeId;
        $visit->created_at = $when;
        $visit->updated_at = $when;
        $visit->save();
    }

    private function orderDaysAgo($store, int $days): void
    {
        $this->makeOrder($store, [
            'order_date' => $this->now()->subDays($days)->toDateTimeString(),
        ]);
    }

    private function figures($store): array
    {
        return MerchantAnalyticsFigures::forStore($store->id, 'USD', $this->now());
    }

    /**
     * A STORE WHOSE PAGE-VIEW HISTORY PREDATES EVERY WINDOW THESE TESTS LOOK AT.
     *
     * The comparison tiles withhold their delta when the EARLIER window straddles the day page
     * views began being attributed to the store, because a count taken over part of a window and
     * compared with a full one reports a trend that did not happen (found by review, 2026-11-22).
     *
     * A fixture whose FIRST EVER visit sits inside the baseline window is exactly that case, so
     * it legitimately gets no comparison -- which is right for the product and useless for a test
     * about comparison ARITHMETIC. This seeds one visit far enough back that coverage is complete
     * for every window used here, which is what a real store of any age looks like. It is outside
     * both the chosen and the baseline window, so it changes no count either test asserts.
     */
    private function establishedVisitHistory(int $storeId): void
    {
        $this->logVisit($storeId, $this->now()->subDays(400));
    }

    public function test_the_premise_that_both_count_tiles_carry_a_comparison(): void
    {
        [, $store] = $this->makeTenant();

        $figures = $this->figures($store);

        foreach (['orders', 'visits'] as $key) {
            $this->assertArrayHasKey('comparison', $figures[$key],
                "The {$key} tile has no comparison, so nothing below this test measures anything.");

            foreach (['direction', 'change', 'baseline', 'period', 'percent', 'note'] as $field) {
                $this->assertArrayHasKey($field, $figures[$key]['comparison'],
                    "The {$key} comparison is missing `{$field}`.");
            }
        }
    }

    /**
     * THE MONEY TILES DELIBERATELY HAVE NONE. Asserted so that adding one later has to come with
     * an answer to the ledger-coverage question rather than with a passing test.
     */
    public function test_the_money_tiles_carry_no_comparison(): void
    {
        [, $store] = $this->makeTenant();

        $figures = $this->figures($store);

        foreach (['payments', 'profit'] as $key) {
            $this->assertArrayNotHasKey('comparison', $figures[$key], sprintf(
                'The %s tile now carries a comparison. Its baseline reaches 31-60 days back, which '
                . 'lands partly or wholly before store_order_financials holds anything -- the ledger '
                . 'is not backfilled, by owner decision -- so the prior figure is understated by an '
                . 'unknown amount and the delta is a false claim shaped like a trend. If this is '
                . 'intended, the coverage question has to be answered first and this test rewritten '
                . 'to say how.',
                $key
            ));
        }
    }

    public function test_the_baseline_is_the_thirty_days_before_the_window_and_not_one_day_more(): void
    {
        [, $store] = $this->makeTenant();

        // Inside the current window: days 0..29 back.
        $this->orderDaysAgo($store, 0);
        $this->orderDaysAgo($store, 29);

        // Inside the baseline: days 30..59 back.
        $this->orderDaysAgo($store, 30);
        $this->orderDaysAgo($store, 31);
        $this->orderDaysAgo($store, 59);

        // Outside BOTH: 60 days back is one day before the baseline begins.
        $this->orderDaysAgo($store, 60);

        $orders = $this->figures($store)['orders'];

        $this->assertStringContainsString('2', $orders['value'],
            'The current window should hold the two orders placed 0 and 29 days ago.');

        $this->assertStringContainsString('3', $orders['comparison']['baseline'], sprintf(
            'The baseline should hold exactly the three orders placed 30, 31 and 59 days ago. It '
            . 'reported %s. An order 60 days back belongs to neither period: the baseline is the '
            . 'thirty days ENDING the day before the window begins, so a fourth order here means '
            . 'the baseline is 31 days long and every percentage computed from it is wrong.',
            $orders['comparison']['baseline']
        ));

        $this->assertSame('down', $orders['comparison']['direction'],
            'Two against three is a decrease.');
        $this->assertStringContainsString('1', $orders['comparison']['change']);
    }

    public function test_growth_is_reported_as_up_with_a_percentage(): void
    {
        [, $store] = $this->makeTenant();

        $this->orderDaysAgo($store, 1);
        $this->orderDaysAgo($store, 2);
        $this->orderDaysAgo($store, 3);
        $this->orderDaysAgo($store, 35);
        $this->orderDaysAgo($store, 40);

        $comparison = $this->figures($store)['orders']['comparison'];

        $this->assertSame('up', $comparison['direction']);
        $this->assertStringContainsString('1', $comparison['change'], '3 against 2 is a change of 1.');
        $this->assertNotNull($comparison['percent']);
        $this->assertStringContainsString('50.0%', $comparison['percent'],
            '1 more than a baseline of 2 is 50.0%.');
        $this->assertNull($comparison['note'],
            'A computable percentage must carry no explanatory note.');
    }

    public function test_an_equal_period_is_reported_as_the_same_with_a_zero_percentage(): void
    {
        [, $store] = $this->makeTenant();

        $this->orderDaysAgo($store, 5);
        $this->orderDaysAgo($store, 45);

        $comparison = $this->figures($store)['orders']['comparison'];

        $this->assertSame('same', $comparison['direction']);
        $this->assertStringContainsString('0.0%', $comparison['percent']);
        $this->assertNull($comparison['note']);
    }

    /**
     * THE ONE THAT MATTERS MOST. A percentage against a zero baseline is the division this whole
     * method exists to refuse.
     */
    public function test_a_zero_baseline_yields_no_percentage_and_says_why(): void
    {
        [, $store] = $this->makeTenant();

        $this->orderDaysAgo($store, 1);
        $this->orderDaysAgo($store, 2);

        $comparison = $this->figures($store)['orders']['comparison'];

        $this->assertSame('up', $comparison['direction']);
        $this->assertStringContainsString('2', $comparison['change'],
            'The ABSOLUTE change is still true and must still be reported.');
        $this->assertStringContainsString('0', $comparison['baseline']);

        $this->assertNull($comparison['percent'], sprintf(
            'A percentage was computed against a baseline of zero: %s. Two orders where there were '
            . 'none is not 100%%, not 200%% and not infinity -- there is nothing to divide by, and '
            . 'printing any figure here is a false statement a merchant would read as growth.',
            (string) $comparison['percent']
        ));

        $this->assertNotNull($comparison['note'],
            'The percentage was withheld with no explanation, so the screen has a blank where a '
            . 'reason should be.');
        $this->assertStringContainsString('no figure for the earlier period', $comparison['note']);
    }

    public function test_nothing_against_nothing_is_zero_per_cent_rather_than_a_refusal(): void
    {
        [, $store] = $this->makeTenant();

        $comparison = $this->figures($store)['orders']['comparison'];

        $this->assertSame('same', $comparison['direction']);
        $this->assertStringContainsString('0.0%', (string) $comparison['percent'],
            'Zero against zero IS computable: no change, 0.0%. Refusing it would be over-caution, '
            . 'and the tile would say "we cannot tell you" about a store that simply had no orders.');
        $this->assertNull($comparison['note']);
    }

    /** Visits are compared the same way, on their own table. */
    public function test_visits_are_compared_on_their_own_table(): void
    {
        [, $store] = $this->makeTenant();

        $this->establishedVisitHistory($store->id);

        $this->logVisit($store->id, $this->now()->subDays(2));
        $this->logVisit($store->id, $this->now()->subDays(3));
        $this->logVisit($store->id, $this->now()->subDays(4));
        $this->logVisit($store->id, $this->now()->subDays(40));

        $comparison = $this->figures($store)['visits']['comparison'];

        $this->assertSame('up', $comparison['direction']);
        $this->assertStringContainsString('1', $comparison['baseline']);
        $this->assertStringContainsString('2', $comparison['change']);
        $this->assertStringContainsString('200.0%', $comparison['percent'],
            '2 more than a baseline of 1 is 200.0%.');
    }

    /**
     * THE BASELINE IS TENANT-SCOPED. `Order` is not in `config/tenancy.php`'s `scoped_models`, so
     * no global scope adds `store_id` for anybody -- operator included. A baseline that leaked
     * another store's orders would make the delta wrong in a way that looks like a trend.
     */
    public function test_another_stores_orders_do_not_reach_this_stores_baseline(): void
    {
        [, $store] = $this->makeTenant();
        [, $other] = $this->makeTenant();

        $this->orderDaysAgo($store, 5);

        $this->orderDaysAgo($other, 40);
        $this->orderDaysAgo($other, 45);
        $this->logVisit($other->id, $this->now()->subDays(40));

        $orders = $this->figures($store)['orders'];

        $this->assertStringContainsString('0', $orders['comparison']['baseline'], sprintf(
            "This store placed no orders in the baseline period; the other store placed two. The "
            . "baseline reported %s, so it is reading across the tenant boundary.",
            $orders['comparison']['baseline']
        ));

        $this->assertStringContainsString('0', $this->figures($store)['visits']['comparison']['baseline'],
            'The visits baseline is reading another store\'s visits.');
    }

    /** The baseline period label is translated, like every other period on this screen. */
    public function test_the_baseline_period_label_is_translated(): void
    {
        $this->assertSame(
            'الـ 30 يوماً السابقة لها',
            __('the :count days before that', ['count' => '30'], 'ar'),
            'The baseline period reads in English on an Arabic screen.'
        );
    }
}
