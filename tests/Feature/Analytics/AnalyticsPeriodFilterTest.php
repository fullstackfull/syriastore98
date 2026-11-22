<?php

namespace Tests\Feature\Analytics;

use App\Models\SavedView;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shetabit\Visitor\Models\Visit;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-002 — THE PERIOD FILTER AND THE SAVED PERIODS.
 * =================================================================================================
 *
 * `/analytics` showed a fixed thirty days. The requirement is "comparisons, filters and saved
 * periods": the comparison shipped first, this is the other two.
 *
 * THIS FILE IS ORGANISED AROUND WHAT A FILTER MUST NOT DO, because a period is the first piece of
 * caller-supplied input this screen has ever taken and CLAUDE.md records that caller-supplied input
 * has produced four separate tenant defects in this codebase.
 *
 *  1. IT MUST NOT CHANGE THE UNFILTERED SCREEN. A merchant who filters nothing must see exactly the
 *     figures they saw before the filter existed, or every pre-existing test is proving something
 *     about a surface that no longer ships.
 *  2. IT MUST NOT WIDEN THE TENANT. The store comes from `getCurrentStore()`; a period is a choice
 *     among the caller's OWN days. Driven with a second store's rows present in every window.
 *  3. IT MUST NOT BECOME A PARSER. `CarbonImmutable::parse()` accepts `yesterday` and `+1 week`,
 *     and rolls `2026-02-30` forward to 2 March. Any of those would let a URL carry a period the
 *     product never offered, or turn a typo into a different real period rather than a rejected one.
 *  4. IT MUST NOT ADJUST A WINDOW IN SILENCE. A clamp a merchant cannot see is a figure whose label
 *     does not describe it -- so the cap and the future-end clamp are asserted to reach both the
 *     screen and the file.
 *  5. `ss_view` MUST NOT BE A BARE ROW ID. `saved_views` is not in `config/tenancy.php`'s
 *     `scoped_models`, so no global scope adds a predicate for anybody; a `SavedView::find($id)`
 *     here would be the exact pattern CLAUDE.md calls the most productive defect class in this
 *     codebase. Driven with a real foreign row rather than argued from the source.
 */
final class AnalyticsPeriodFilterTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/analytics';

    private const PERMISSION = 'Manage Reports';

    private function visit(int $storeId, int $daysAgo): void
    {
        $visit = new Visit();
        $visit->timestamps = false;
        $visit->store_id = $storeId;
        $visit->device = 'iPhone';
        $visit->created_at = CarbonImmutable::now()->subDays($daysAgo);
        $visit->updated_at = CarbonImmutable::now()->subDays($daysAgo);
        $visit->save();
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
        $this->visit($storeId, 400);
    }

    /** The figure's value with the bidi isolates removed, for an exact assertion. */
    private function plain(?string $value): ?string
    {
        return $value === null
            ? null
            : (string) preg_replace('/[\x{2066}-\x{2069}]/u', '', $value);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 1. The unfiltered screen did not change
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE DEFAULT PRESET IS THE OLD HARDCODED WINDOW, to the second.
     *
     * `MerchantAnalyticsFigures` used to compute `subDays(RECENT_DAYS - 1)->startOfDay()` to
     * `endOfDay()` inline. If `AnalyticsPeriod::DEFAULT` ever resolves to anything else, every
     * figure on the screen silently covers a different period while still being labelled the same
     * -- so the two are compared directly rather than trusted to agree.
     */
    public function test_the_default_period_is_exactly_the_window_the_screen_always_had(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        $period = AnalyticsPeriod::default($now);

        $this->assertSame(AnalyticsPeriod::DEFAULT, $period->key());
        $this->assertSame(
            $now->subDays(MerchantAnalyticsFigures::RECENT_DAYS - 1)->startOfDay()->toDateTimeString(),
            $period->from()->toDateTimeString(),
            'The default period no longer starts where the old hardcoded window started.'
        );
        $this->assertSame(
            $now->endOfDay()->toDateTimeString(),
            $period->to()->toDateTimeString()
        );
        $this->assertSame(MerchantAnalyticsFigures::RECENT_DAYS, $period->days());
        $this->assertSame('last 30 days', $period->label(),
            'The default label changed, so every tile on the screen now says something different.');
    }

    public function test_an_unfiltered_request_renders_the_default_and_marks_it_selected(): void
    {
        [$merchant] = $this->makeTenant();

        $html = $this->actingAs($merchant->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('name="ss_period"', $html, 'The period filter is not on the screen.');
        $this->assertMatchesRegularExpression(
            '/<option value="last_30_days"[^>]*\bselected\b/',
            $html,
            'The default period is not pre-selected, so the form disagrees with the figures beside it.'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 2. The filter narrows, and only within the caller's own store
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_shorter_preset_excludes_rows_outside_it(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store->id, 2);   // inside 7 days
        $this->visit($store->id, 20);  // inside 30, outside 7

        $thirty = $this->actingAs($merchant->fresh())->get(self::URI);
        $seven = $this->actingAs($merchant->fresh())->get(self::URI.'?ss_period=last_7_days');

        $this->assertSame('2', $this->plain($thirty->viewData('figures')['visits']['value']));
        $this->assertSame('1', $this->plain($seven->viewData('figures')['visits']['value']),
            'The 7-day filter counted a visit from 20 days ago, so the window is not being applied.');
        $this->assertSame('last 7 days', $seven->viewData('figures')['visits']['period'],
            'The figure is filtered but still labelled with the old period.');
    }

    /**
     * THE PERIOD IS NOT A TENANT PREDICATE, driven with a neighbour whose rows sit in every window.
     *
     * This is the assertion that would have caught the four defects CLAUDE.md records: if the
     * handler ever took its store from request input, a filter is exactly the shape of request that
     * would carry it.
     */
    public function test_no_period_reaches_another_stores_rows(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [, $otherStore] = $this->makeTenant();

        $this->visit($store->id, 1);

        foreach ([1, 5, 40, 200, 500] as $daysAgo) {
            $this->visit($otherStore->id, $daysAgo);
        }

        foreach (['last_7_days', 'last_30_days', 'last_90_days', 'last_365_days', 'this_year'] as $preset) {
            $figures = $this->actingAs($merchant->fresh())
                ->get(self::URI.'?ss_period='.$preset)
                ->assertStatus(200)
                ->viewData('figures');

            $this->assertSame('1', $this->plain($figures['visits']['value']), sprintf(
                'Preset "%s" counted another store\'s visits. The window must never widen the '
                . 'tenant: shetabit_visits is not in config/tenancy.php\'s scoped_models, so the '
                . 'explicit store_id predicate is the ONLY thing keeping these apart.',
                $preset
            ));
        }

        // And a custom range spanning every foreign row.
        $figures = $this->actingAs($merchant->fresh())
            ->get(self::URI.'?ss_period=custom&ss_from=2020-01-01&ss_to='.CarbonImmutable::now()->toDateString())
            ->assertStatus(200)
            ->viewData('figures');

        $this->assertSame('1', $this->plain($figures['visits']['value']),
            'A custom range reached another store\'s visits.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 3. The input is an allowlist and a strict parser, never a parser of English
     * ---------------------------------------------------------------------------------------------
     */

    public function test_an_unknown_preset_falls_back_to_the_default_rather_than_erroring(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->visit($store->id, 20);

        $response = $this->actingAs($merchant->fresh())->get(self::URI.'?ss_period=all_time_please');

        $response->assertStatus(200);
        $this->assertSame('last 30 days', $response->viewData('figures')['visits']['period'],
            'An unknown preset did not resolve to the default. A stale bookmark must show the '
            . 'usual screen, not a validation page and not the slowest query in the application.');
    }

    /**
     * THE RELATIVE-ENGLISH CASES, which are the reason the parser is strict.
     *
     * Every one of these is a string `CarbonImmutable::parse()` accepts and turns into a real date.
     * Accepting them would mean `/analytics?ss_period=custom&ss_from=yesterday` is a working
     * product feature nobody designed, documented or translated.
     */
    public function test_a_relative_or_impossible_date_is_refused_and_not_reinterpreted(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->visit($store->id, 20);

        foreach ([
            'yesterday',
            'now',
            '+1 week',
            'last monday',
            '2026-02-30',   // Carbon rolls this to 2 March
            '2026-13-01',
            '20261101',
            '2026-11-1',
            '',
            '2026-11-01T00:00:00Z',
            /*
             * THE TWO ALL-ZERO / ALL-NINE SHAPES, added 2026-11-22 after an independent review
             * traced `AnalyticsPeriod::strictDate()`'s `$parsed === false` limb and found it
             * UNREACHABLE: `Carbon::rawCreateFromFormat` THROWS under strict mode, which is
             * Carbon's default and which nothing in `app/` turns off, so a format PHP refuses
             * outright would 500 rather than fall to the guard. These two are the shapes the list
             * did not already cover. They are here to settle by measurement which side of that
             * line they fall on -- a 200 proves PHP rolls them over and the round-trip check
             * catches them, which is the behaviour the guard was written for.
             */
            '0000-00-00',
            '9999-99-99',
        ] as $bad) {
            $response = $this->actingAs($merchant->fresh())->get(
                self::URI.'?ss_period=custom&ss_from='.urlencode($bad).'&ss_to=2026-11-21'
            );

            $response->assertStatus(200);

            $this->assertSame('last 30 days', $response->viewData('figures')['visits']['period'], sprintf(
                'The date "%s" was accepted instead of refused. A URL must not be able to carry a '
                . 'period the product does not offer.',
                $bad
            ));
        }
    }

    public function test_a_valid_custom_range_is_honoured(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->visit($store->id, 2);
        $this->visit($store->id, 45);

        $to = CarbonImmutable::now()->toDateString();
        $from = CarbonImmutable::now()->subDays(60)->toDateString();

        $figures = $this->actingAs($merchant->fresh())
            ->get(self::URI."?ss_period=custom&ss_from={$from}&ss_to={$to}")
            ->assertStatus(200)
            ->viewData('figures');

        $this->assertSame('2', $this->plain($figures['visits']['value']),
            'A 61-day custom range did not include the 45-day-old visit.');
        $this->assertSame("{$from} to {$to}", $figures['visits']['period'],
            'A custom range must be LABELLED with its own dates: "last 61 days" would be a true '
            . 'length attached to a window the merchant did not describe that way.');
    }

    public function test_a_reversed_range_is_swapped_rather_than_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->visit($store->id, 2);

        $late = CarbonImmutable::now()->toDateString();
        $early = CarbonImmutable::now()->subDays(10)->toDateString();

        $figures = $this->actingAs($merchant->fresh())
            ->get(self::URI."?ss_period=custom&ss_from={$late}&ss_to={$early}")
            ->assertStatus(200)
            ->viewData('figures');

        $this->assertSame("{$early} to {$late}", $figures['period']['label'],
            'A range picked in the wrong order was not swapped. That is a date-picker bug and the '
            . 'merchant should not be the one who pays for it.');
        $this->assertSame('1', $this->plain($figures['visits']['value']));
    }

    /**
     * THE COMPARISON'S BASELINE IS LABELLED WITH THE CHOSEN PERIOD'S OWN LENGTH.
     *
     * Found by mutation, not by reading: replacing `$chosen->baselineLabel()` with the old
     * hardcoded `RECENT_DAYS` sentence passed every one of the ten existing comparison tests. On a
     * 7-day filter the tile would then read "Up 3 from 1 in the 30 days before that" while the
     * delta was computed over SEVEN days -- a true number under a false description of where it
     * came from, which is the same class of defect as a wrong number and harder to notice because
     * the arithmetic checks out.
     *
     * `MetricWindow::previous()` returns the equally long window ending the day before, so the
     * baseline really is 7 days here and the sentence has to say 7.
     */
    public function test_the_comparison_baseline_is_labelled_with_the_chosen_periods_length(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->establishedVisitHistory($store->id);

        $this->visit($store->id, 2);    // inside the chosen 7 days
        $this->visit($store->id, 10);   // inside the 7 days BEFORE that, so the baseline is 1

        foreach ([
            'last_7_days' => 'the 7 days before that',
            'last_30_days' => 'the 30 days before that',
            'last_90_days' => 'the 90 days before that',
        ] as $preset => $expected) {
            $figures = $this->actingAs($merchant->fresh())
                ->get(self::URI.'?ss_period='.$preset)
                ->assertStatus(200)
                ->viewData('figures');

            foreach (['orders', 'visits'] as $tile) {
                $this->assertNotNull($figures[$tile]['comparison'] ?? null, sprintf(
                    'The %s tile lost its comparison under preset %s.', $tile, $preset
                ));

                $this->assertSame($expected, $figures[$tile]['comparison']['period'], sprintf(
                    'Under preset "%s" the %s tile describes its baseline as "%s". The delta is '
                    . 'computed over the chosen period\'s own length, so any other sentence names a '
                    . 'window the comparison does not use.',
                    $preset,
                    $tile,
                    $figures[$tile]['comparison']['period']
                ));
            }
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 4. A clamp is never silent
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_period_longer_than_the_cap_is_shortened_and_says_so(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant->fresh())->get(
            self::URI.'?ss_period=custom&ss_from=2010-01-01&ss_to='.CarbonImmutable::now()->toDateString()
        );

        $response->assertStatus(200);
        $period = $response->viewData('figures')['period'];

        $this->assertSame(AnalyticsPeriod::MAX_SPAN_DAYS, $period['days'],
            'A range of several thousand days was not capped.');
        $this->assertNotNull($period['clamped'],
            'The period was shortened with nothing on the screen saying so, which makes every '
            . 'figure on it carry a label that does not describe the window it covers.');
        $this->assertStringContainsString((string) $period['clamped'], $response->getContent(),
            'The clamp sentence was computed but never rendered.');
    }

    /**
     * A FUTURE *START* DATE WAS A LIVE HTTP 500, and this is the case that was missing.
     *
     * Found by an independent review, reproduced before fixing. The chain: a merchant picks
     * TOMORROW in both date fields. The reversed-range swap does nothing because the two are equal;
     * the end is then clamped back to today while the start is left in the future, so `from > to`.
     * The span cap did not catch it either -- Carbon 2's `diffInDays()` is ABSOLUTE, so it reported
     * one day for a window running backwards. `MetricWindow::between()` threw
     * `InvalidArgumentException: A metric window ends before it starts`, uncaught, and `/analytics`
     * answered 500.
     *
     * REACHABLE BY CLICKING, not only by a hand-made URL: the form offers "a period I choose" and
     * two `type="date"` inputs, and before this fix they carried no `max`.
     *
     * The existing future-END case passed throughout, because there `to` is clamped and `from`
     * is already in the past. That is why the suite was green: the two look like the same case and
     * are not.
     */
    public function test_a_start_date_in_the_future_does_not_error_and_says_what_happened(): void
    {
        [$merchant] = $this->makeTenant();

        $today = CarbonImmutable::now()->toDateString();

        foreach ([
            'both tomorrow' => [1, 1],
            'start tomorrow, end later' => [1, 2],
            'both far in the future' => [5000, 5001],
        ] as $name => [$fromDays, $toDays]) {
            $from = CarbonImmutable::now()->addDays($fromDays)->toDateString();
            $to = CarbonImmutable::now()->addDays($toDays)->toDateString();

            $response = $this->actingAs($merchant->fresh())
                ->get(self::URI."?ss_period=custom&ss_from={$from}&ss_to={$to}");

            $this->assertNotSame(500, $response->status(), sprintf(
                'The %s case answered 500. A caller-chosen period must never be able to throw: '
                . 'the clamp can reverse the window on its own, and the span cap cannot repair it '
                . 'because an absolute day difference reads a backwards window as short.',
                $name
            ));
            $response->assertStatus(200);

            $period = $response->viewData('figures')['period'];

            $this->assertSame($today, $period['from'], sprintf(
                'The %s case did not move the start to today.', $name
            ));
            $this->assertSame($today, $period['to']);
            $this->assertNotNull($period['clamped'], sprintf(
                'The %s case silently showed a different period from the one asked for.', $name
            ));
        }
    }

    /**
     * AND THE WINDOW IS NEVER BACKWARDS, asserted as a property rather than per-case.
     *
     * `MetricWindow` throws on a reversed window, so "no 500" and "from <= to" are the same claim --
     * but stating it directly means a future caller that catches the exception somewhere cannot
     * make this test pass with a broken window.
     */
    public function test_no_caller_supplied_period_can_produce_a_backwards_window(): void
    {
        foreach ([
            ['2026-11-22', '2026-11-22'],
            ['2999-01-01', '2999-12-31'],
            ['2026-11-30', '2026-11-25'],
            ['1900-01-01', '2999-12-31'],
            ['2026-11-21', '2026-11-21'],
        ] as [$from, $to]) {
            $period = AnalyticsPeriod::fromRequest([
                'ss_period' => 'custom',
                'ss_from' => $from,
                'ss_to' => $to,
            ]);

            $this->assertTrue(
                $period->from()->lessThanOrEqualTo($period->to()),
                sprintf('ss_from=%s ss_to=%s produced %s .. %s, which runs backwards.',
                    $from, $to, $period->from()->toDateTimeString(), $period->to()->toDateTimeString())
            );

            $this->assertGreaterThanOrEqual(1, $period->days());
            $this->assertLessThanOrEqual(AnalyticsPeriod::MAX_SPAN_DAYS, $period->days());
        }
    }

    public function test_an_end_date_in_the_future_is_moved_to_today_and_says_so(): void
    {
        [$merchant] = $this->makeTenant();

        $future = CarbonImmutable::now()->addDays(30)->toDateString();

        $response = $this->actingAs($merchant->fresh())->get(
            self::URI.'?ss_period=custom&ss_from='.CarbonImmutable::now()->subDays(5)->toDateString()."&ss_to={$future}"
        );

        $response->assertStatus(200);
        $period = $response->viewData('figures')['period'];

        $this->assertSame(CarbonImmutable::now()->toDateString(), $period['to'],
            'A future end date was not moved to today.');
        $this->assertNotNull($period['clamped']);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 5. The export is the same window, and carries the same clamp
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_export_honours_the_screens_period(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->visit($store->id, 2);
        $this->visit($store->id, 20);

        $csv = $this->actingAs($merchant->fresh())
            ->get('/analytics/export?ss_period=last_7_days')
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('last 7 days', $csv,
            'The file does not name the period the screen was filtered to.');
        $this->assertStringNotContainsString('last 30 days', $csv,
            'The export ignored the filter and produced the default window. A merchant filters to '
            . 'one week, downloads, and gets thirty days of figures correctly labelled -- nothing '
            . 'false, and the wrong answer to what was asked.');
    }

    public function test_the_export_carries_the_clamp_sentence(): void
    {
        [$merchant] = $this->makeTenant();

        $csv = $this->actingAs($merchant->fresh())
            ->get('/analytics/export?ss_period=custom&ss_from=2010-01-01&ss_to='.CarbonImmutable::now()->toDateString())
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('was shortened', $csv,
            'The file does not say the period was shortened, so a spreadsheet reader sees figures '
            . 'for a window nobody told them about.');
    }

    /*
     * THE TWO TESTS ABOVE TYPE THE QUERY STRING BY HAND, AND THAT IS HOW A REAL GAP SURVIVED THEM.
     *
     * They prove the HANDLER honours a period it is sent. Nothing proved the PAGE sends one. The
     * screen's only download control was `href="{{ route('analytics.export') }}"` -- no query
     * string -- so a merchant who filtered to a week and pressed Download got a thirty-day file.
     * Each row of it was labelled with the period it really covered, so nothing in the file was
     * false; it was simply not the figures on the screen it was downloaded from. The handler's own
     * comment names this as "the worst available outcome", and the view did it anyway.
     *
     * Found on 2026-11-22 by a reviewer instructed to refute the claim that the export row was
     * complete, who followed the link instead of typing the address. So do these.
     */

    /** The href of the screen's own download control, as the browser would follow it. */
    private function downloadLinkOn(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<a\b[^>]*href="([^"]*analytics\/export[^"]*)"[^>]*>\s*' . preg_quote(__('Download as CSV'), '/') . '\s*<\/a>/u', $html, $m),
            'PREMISE: the screen no longer carries a download link this test can find.'
        );

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    public function test_the_screens_own_download_link_carries_the_period_on_screen(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->visit($store->id, 2);
        $this->visit($store->id, 20);

        $screen = $this->actingAs($merchant->fresh())->get(self::URI . '?ss_period=last_7_days')->assertStatus(200);

        $this->assertSame('last 7 days', $screen->viewData('figures')['visits']['period'],
            'PREMISE: the screen itself is not showing the seven-day window.');

        $csv = $this->actingAs($merchant->fresh())
            ->get($this->downloadLinkOn((string) $screen->getContent()))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('last 7 days', $csv,
            'The file downloaded from the screen does not cover the period the screen was showing.');
        $this->assertStringNotContainsString('last 30 days', $csv,
            'The screen showed seven days and its own Download button produced thirty.');
    }

    /**
     * AND THE SENTENCE SAYING THE PERIOD WAS SHORTENED. The screen prints it; the file must too,
     * so the link has to carry what was ASKED FOR and let the export shorten it again -- carrying
     * the already-shortened dates would produce the right rows and lose the disclosure.
     */
    public function test_the_screens_own_download_link_carries_a_shortened_period_with_its_sentence(): void
    {
        [$merchant] = $this->makeTenant();

        $screen = $this->actingAs($merchant->fresh())
            ->get(self::URI . '?ss_period=custom&ss_from=2010-01-01&ss_to=' . CarbonImmutable::now()->toDateString())
            ->assertStatus(200);

        $this->assertStringContainsString('was shortened', (string) $screen->getContent(),
            'PREMISE: the screen is not showing the shortening sentence, so there is nothing for the file to carry.');

        $csv = $this->actingAs($merchant->fresh())
            ->get($this->downloadLinkOn((string) $screen->getContent()))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('was shortened', $csv,
            'The screen says the period was shortened and the file downloaded from it does not.');
    }

    /** A period applied from a SAVED view is on screen too, and the address then holds only its id. */
    public function test_the_screens_own_download_link_carries_a_saved_period(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->visit($store->id, 2);
        $this->visit($store->id, 20);

        $this->actingAs($merchant->fresh())->post('/saved-views', [
            'screen' => 'analytics', 'name' => 'Last week', 'filters' => ['ss_period' => 'last_7_days'],
        ])->assertRedirect();

        $saved = SavedView::withoutGlobalScopes()->where('screen', 'analytics')->firstOrFail();

        $screen = $this->actingAs($merchant->fresh())->get(self::URI . '?ss_view=' . $saved->id)->assertStatus(200);

        $this->assertSame('last 7 days', $screen->viewData('figures')['visits']['period'],
            'PREMISE: applying the saved period did not change the screen.');

        $csv = $this->actingAs($merchant->fresh())
            ->get($this->downloadLinkOn((string) $screen->getContent()))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('last 7 days', $csv,
            'A saved period was on screen and the file downloaded from it covers a different one.');
    }

    /**
     * WITH NOTHING CHOSEN THE LINK IS THE BARE ADDRESS, as it always was. The default period is
     * resolved by the handler; a link that spelled it out would pin today's default into every
     * bookmark of it.
     */
    public function test_with_no_period_chosen_the_download_link_is_unchanged(): void
    {
        [$merchant] = $this->makeTenant();

        $screen = $this->actingAs($merchant->fresh())->get(self::URI)->assertStatus(200);

        $this->assertSame(route('analytics.export'), $this->downloadLinkOn((string) $screen->getContent()));
    }

    /**
     * ONLY THE PERIOD'S OWN KEYS TRAVEL. The link is built from the request, so anything else in
     * the address -- and anything that is not a plain value -- stays out of it.
     */
    public function test_the_download_link_carries_nothing_but_the_period(): void
    {
        [$merchant] = $this->makeTenant();

        $screen = $this->actingAs($merchant->fresh())
            ->get(self::URI . '?ss_period=last_7_days&store_id=999&next=https%3A%2F%2Fevil.example&ss_from[]=x')
            ->assertStatus(200);

        $link = $this->downloadLinkOn((string) $screen->getContent());

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertSame(['ss_period' => 'last_7_days'], $query,
            'The download link carried something from the address other than the period.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 6. Saved periods: ownership, and the gate
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_merchant_can_save_a_period_and_apply_it_again(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->visit($store->id, 2);
        $this->visit($store->id, 20);

        $this->actingAs($merchant->fresh())->post('/saved-views', [
            'screen' => 'analytics',
            'name' => 'Last week',
            'filters' => ['ss_period' => 'last_7_days'],
        ])->assertRedirect();

        $saved = SavedView::withoutGlobalScopes()->where('screen', 'analytics')->first();

        $this->assertNotNull($saved, 'Saving a period wrote no row.');
        $this->assertSame($store->id, (int) $saved->store_id);
        $this->assertSame($merchant->id, (int) $saved->created_by);
        $this->assertSame(['ss_period' => 'last_7_days'], $saved->appliedFilters());

        $figures = $this->actingAs($merchant->fresh())
            ->get(self::URI.'?ss_view='.$saved->id)
            ->assertStatus(200)
            ->viewData('figures');

        $this->assertSame('last 7 days', $figures['visits']['period'],
            'Applying the saved period did not change the window.');
        $this->assertSame('1', $this->plain($figures['visits']['value']));
    }

    /**
     * THE STORE OWNER MAY SAVE, which is not the same question as whether they may look.
     *
     * `/analytics` admits the tenant owner without the permission ROW, because a merchant created
     * before the name existed holds none. `SavedViewPolicy::useScreen()` has only an operator limb
     * and a permission limb, so without the `OWNER_ADMITTED_SCREENS` exception the owner reads the
     * screen and is refused its Save button -- a visible control that always says "Permission
     * denied", which a merchant reads as a broken product rather than as a policy.
     */
    public function test_the_store_owner_may_save_a_period_without_holding_the_permission_row(): void
    {
        [$merchant] = $this->makeTenant();

        $this->assertFalse($merchant->fresh()->isAbleTo(self::PERMISSION),
            'Premise: this owner must NOT hold the permission row, or this test proves nothing.');

        $this->actingAs($merchant->fresh())->post('/saved-views', [
            'screen' => 'analytics',
            'name' => 'Owner period',
            'filters' => ['ss_period' => 'last_7_days'],
        ]);

        $this->assertSame(1, SavedView::withoutGlobalScopes()->where('screen', 'analytics')->count(),
            'The store owner was refused saving a period on a screen they are admitted to read.');
    }

    public function test_a_staff_member_without_the_report_permission_cannot_save_a_period(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $this->actingAs($staff->fresh())->post('/saved-views', [
            'screen' => 'analytics',
            'name' => 'Sneaky',
            'filters' => ['ss_period' => 'last_7_days'],
        ]);

        $this->assertSame(0, SavedView::withoutGlobalScopes()->where('screen', 'analytics')->count(),
            'A staff member with no report permission saved a period on the analytics screen.');
    }

    /**
     * `ss_view` IS A ROW ID AND IS NOT TRUSTED AS ONE.
     *
     * The foreign row is created directly rather than through the controller, so the test does not
     * depend on another store's merchant being able to save -- only on whether THIS store's request
     * can read it. `saved_views` is not in `scoped_models`, so a bare `find()` would succeed.
     */
    public function test_a_saved_period_belonging_to_another_store_is_ignored(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$otherMerchant, $otherStore] = $this->makeTenant();

        $this->visit($store->id, 20);

        $foreign = new SavedView();
        $foreign->screen = 'analytics';
        $foreign->name = 'Theirs';
        $foreign->filters = ['ss_period' => 'last_7_days'];
        $foreign->store_id = $otherStore->id;
        $foreign->created_by = $otherMerchant->id;
        $foreign->save();

        $response = $this->actingAs($merchant->fresh())->get(self::URI.'?ss_view='.$foreign->id);

        $response->assertStatus(200);
        $this->assertSame('last 30 days', $response->viewData('figures')['visits']['period'],
            'Another store\'s saved period was applied. The id came from the query string and was '
            . 'resolved without the ownership predicate -- the pattern CLAUDE.md names as the most '
            . 'productive defect class in this codebase.');

        $this->assertStringNotContainsString('Theirs', $response->getContent(),
            'Another store\'s saved-period NAME was disclosed on this merchant\'s screen.');
    }

    public function test_an_explicitly_chosen_period_beats_a_saved_one(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->visit($store->id, 20);

        $saved = new SavedView();
        $saved->screen = 'analytics';
        $saved->name = 'Mine';
        $saved->filters = ['ss_period' => 'last_7_days'];
        $saved->store_id = $store->id;
        $saved->created_by = $merchant->id;
        $saved->save();

        $figures = $this->actingAs($merchant->fresh())
            ->get(self::URI.'?ss_view='.$saved->id.'&ss_period=last_90_days')
            ->assertStatus(200)
            ->viewData('figures');

        $this->assertSame('last 90 days', $figures['visits']['period'],
            'A saved period overrode an explicit choice. A saved view is a starting point, not a '
            . 'lock, and the reverse order makes the filter look broken the first time one is saved.');
    }

    /**
     * AN OPERATOR WHO HAS CHOSEN NO STORE CANNOT SAVE A PERIOD, because they cannot read the
     * screen either. Found by an independent review as the one asymmetry in the gate.
     *
     * `getCurrentStore()` returns the HARDCODED LITERAL 1 for a `super admin`, so the policy's
     * `! empty(getCurrentStore())` check passed for a principal who has selected nothing -- and
     * `SavedViewController::store()` would then file the operator's private filter set inside
     * merchant 1's partition. Nothing was disclosed (every read carries `created_by`), but
     * `/analytics` refuses this exact principal with "No store is selected", so they were refused
     * the screen and allowed its Save button.
     */
    public function test_an_operator_who_has_chosen_no_store_cannot_save_a_period(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $refused = $this->actingAs($operator->fresh())->get(self::URI);

        $this->assertTrue(
            in_array($refused->status(), [301, 302], true),
            'Premise: the analytics screen must refuse an operator with no store, or this test is '
            . 'about nothing. Status was ' . $refused->status() . '.'
        );

        $this->actingAs($operator->fresh())->post('/saved-views', [
            'screen' => 'analytics',
            'name' => 'Operator with no store',
            'filters' => ['ss_period' => 'last_7_days'],
        ]);

        $this->assertSame(0, SavedView::withoutGlobalScopes()->where('screen', 'analytics')->count(),
            'An operator who has chosen no store saved a period. It would be filed under '
            . 'store_id 1 -- one particular merchant\'s partition -- by a principal the screen '
            . 'itself refuses.');
    }

    /**
     * APPLYING A SAVED ANALYTICS PERIOD LANDS ON THE ANALYTICS SCREEN.
     *
     * `SavedViewController::apply()` was `$view->screen === 'order' ? 'order.index' :
     * 'product.index'` -- correct while there were two screens, and silently wrong the moment
     * ANA-002 added a third. An analytics view applied there redirected to the PRODUCT list
     * carrying `ss_period`, and because that key is outside the product vocabulary
     * `TableFilterMemory::remember()` resolved to `[]` and FORGOT the merchant's remembered product
     * filters. Nothing in the UI reaches that route for this screen, which is exactly why it needed
     * a test: a default that quietly picks a screen breaks without anyone editing it.
     */
    public function test_applying_a_saved_analytics_period_lands_on_the_analytics_screen(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $saved = new SavedView();
        $saved->screen = 'analytics';
        $saved->name = 'Mine';
        $saved->filters = ['ss_period' => 'last_7_days'];
        $saved->store_id = $store->id;
        $saved->created_by = $merchant->id;
        $saved->save();

        $response = $this->actingAs($merchant->fresh())->post('/saved-views/'.$saved->id.'/apply');

        $response->assertRedirect();

        $target = $response->headers->get('Location');

        $this->assertStringContainsString('/analytics', (string) $target, sprintf(
            'Applying an analytics saved view redirected to "%s". Landing on the product list '
            . 'would also clear the merchant\'s remembered product filters, because ss_period is '
            . 'outside that screen\'s vocabulary.',
            (string) $target
        ));
    }

    /**
     * THE OPTION LIST AND THE ACCEPTED LIST ARE THE SAME LIST.
     *
     * An option offered but not accepted silently shows the default; one accepted but not offered
     * is a period only a URL can reach. Either is the kind of drift that looks broken to a merchant
     * and fine to a developer, so it is asserted in both directions.
     */
    public function test_every_offered_period_is_accepted_and_every_accepted_period_is_offered(): void
    {
        $offered = array_keys(AnalyticsPeriod::options());
        $accepted = AnalyticsPeriod::acceptedKeys();

        sort($offered);
        sort($accepted);

        $this->assertSame($accepted, $offered,
            'The periods the screen offers and the periods the resolver accepts have drifted.');

        foreach ($accepted as $key) {
            if ($key === AnalyticsPeriod::CUSTOM) {
                continue;
            }

            $this->assertSame($key, AnalyticsPeriod::fromRequest(['ss_period' => $key])->key(), sprintf(
                'The preset "%s" is offered in the UI but does not resolve to itself.',
                $key
            ));
        }
    }
}
