<?php

namespace Tests\Feature\Analytics;

use App\Support\Metrics\MerchantAnalyticsFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shetabit\Visitor\Models\Visit;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-004 — the device and referrer breakdowns, and the four things they must not claim.
 * =================================================================================================
 *
 * A breakdown is a figure with more ways to mislead than a single number has, because every row is
 * a claim and the SET of rows is a further claim that it is complete. So this file is organised
 * around what these two must refuse to say:
 *
 *  1. IT MUST NOT CALL AN UNRECORDED DEVICE A DEVICE. The pre-existing `ThemeAnalyticController`
 *     labels that case `'Other'` — a hardcoded English word on a bilingual product, and one that
 *     reads like a KIND of device rather than like an absence.
 *  2. IT MUST NOT CALL A MISSING REFERER "DIRECT". That is the industry's habitual lie: a privacy
 *     setting, an in-app webview, a bookmark and an HTTPS-to-HTTP hop all strip the header, so
 *     "no referer" is not evidence of a direct visit.
 *  3. IT MUST NOT FILE A HOST UNDER A MARKETING CHANNEL. "Search"/"social" needs a curated list of
 *     domains; a list goes stale, and a visit filed under the wrong channel is worse than one shown
 *     under its own domain name. The host is what was measured, so the host is what is reported.
 *  4. IT MUST NOT TRUNCATE SILENTLY. The host fold happens in PHP because SQLite has no URL
 *     functions, so the referer read is bounded — and when the bound is REACHED the hint says so.
 *     That is driven here with real rows rather than asserted from the source.
 *
 * Plus the two properties every figure on this screen owes: a WINDOW (the pre-existing screen has
 * no date bound on these breakdowns at all, so it reports all time beside figures labelled thirty
 * days) and an explicit STORE predicate (`shetabit_visits` is not in `config/tenancy.php`'s
 * `scoped_models`, so no global scope adds one for anybody, operator included).
 */
final class VisitBreakdownTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-20 12:00:00');
    }

    /** The row's own date and agent fields are the fixture, so Eloquent must not stamp them. */
    private function visit(int $storeId, int $daysAgo, ?string $device = null, ?string $referer = null): void
    {
        $visit = new Visit();
        $visit->timestamps = false;
        $visit->store_id = $storeId;
        $visit->device = $device;
        $visit->referer = $referer;
        $visit->created_at = $this->now()->subDays($daysAgo);
        $visit->updated_at = $this->now()->subDays($daysAgo);
        $visit->save();
    }

    private function figures($store): array
    {
        return MerchantAnalyticsFigures::forStore($store->id, 'USD', $this->now());
    }

    /** @return list<string> the row labels, in the order the figure returned them */
    private function labels(array $breakdown): array
    {
        return array_map(static fn (array $row): string => $row['label'], $breakdown['rows']);
    }

    /**
     * The count WITHOUT the bidi isolates, for an assertion that has to be exact.
     *
     * `countFor()` returns what the figure returns, isolates included, and the looser
     * `assertStringContainsString('5', ...)` assertions above are happy either way. An exact one is
     * not: `assertSame('500', ...)` against `"\u{2066}500\u{2069}"` fails on invisible characters,
     * and exactness is the point when the difference between 500 and 5,000 is the defect.
     */
    private function plainCountFor(array $breakdown, string $label): string
    {
        return (string) preg_replace('/[\x{2066}-\x{2069}]/u', '', $this->countFor($breakdown, $label));
    }

    private function countFor(array $breakdown, string $label): string
    {
        foreach ($breakdown['rows'] as $row) {
            if ($row['label'] === $label) {
                return $row['count'];
            }
        }

        return '(no such row)';
    }

    public function test_the_premise_that_both_breakdowns_exist_on_the_figure_set(): void
    {
        [, $store] = $this->makeTenant();

        $figures = $this->figures($store);

        foreach (['devices', 'channels'] as $key) {
            $this->assertArrayHasKey($key, $figures, "The {$key} breakdown is missing.");
            foreach (['rows', 'period', 'hint'] as $field) {
                $this->assertArrayHasKey($field, $figures[$key],
                    "The {$key} breakdown has no `{$field}`; it does not match the breakdown contract "
                    . 'PlatformAnalyticsFigures established.');
            }
        }
    }

    public function test_devices_are_ordered_by_count_and_not_by_label(): void
    {
        [, $store] = $this->makeTenant();

        // 'Android' is alphabetically first and least common on purpose.
        $this->visit($store->id, 1, 'Android');
        foreach ([1, 2, 3] as $d) {
            $this->visit($store->id, $d, 'Windows');
        }
        foreach ([1, 2] as $d) {
            $this->visit($store->id, $d, 'iPhone');
        }

        $labels = $this->labels($this->figures($store)['devices']);

        $this->assertSame(['Windows', 'iPhone', 'Android'], $labels,
            'The device rows are not ordered by count descending. The pre-existing screen orders by '
            . "the LABEL (orderBy('device','DESC')), which puts the alphabet before the magnitude "
            . 'and makes the biggest share arbitrary to find.');
    }

    /** CLAIM 1: an unrecorded device is an absence, named and translated — never "Other". */
    public function test_an_unrecorded_device_is_named_an_absence_and_not_a_device(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store->id, 1, 'Windows');
        $this->visit($store->id, 2, null);
        $this->visit($store->id, 3, '');

        $devices = $this->figures($store)['devices'];

        $this->assertContains('Device not recorded', $this->labels($devices),
            'A visit whose device the library could not determine must be shown as not recorded.');

        $this->assertNotContains('Other', $this->labels($devices),
            'The unrecorded bucket is labelled "Other" — a hardcoded English word on a bilingual '
            . 'product, and one that reads like a KIND of device rather than an absence. That is '
            . "the pre-existing ThemeAnalyticController's label and the reason this figure exists.");

        $this->assertStringContainsString('2', $this->countFor($devices, 'Device not recorded'),
            'Both the NULL and the empty-string device should fall in the same bucket.');
    }

    public function test_the_device_breakdown_is_windowed_and_store_scoped(): void
    {
        [, $store] = $this->makeTenant();
        [, $other] = $this->makeTenant();

        $this->visit($store->id, 1, 'Windows');
        $this->visit($store->id, 40, 'Windows');          // outside the 30-day window
        $this->visit($other->id, 1, 'Windows');           // another tenant

        $devices = $this->figures($store)['devices'];

        $this->assertStringContainsString('1', $this->countFor($devices, 'Windows'), sprintf(
            'Windows reported %s. One visit is in this store and in the window; the other two are '
            . 'outside the window and inside another store. shetabit_visits is NOT in '
            . "config/tenancy.php's scoped_models, so the store predicate has to be explicit.",
            $this->countFor($devices, 'Windows')
        ));
    }

    public function test_an_empty_period_says_so_and_estimates_nothing(): void
    {
        [, $store] = $this->makeTenant();

        $devices = $this->figures($store)['devices'];

        $this->assertSame([], $devices['rows']);
        $this->assertStringContainsString('Nothing is estimated', $devices['hint']);
    }

    /* --------------------------------------------------------------------------------------------
     * The referrer half
     * -------------------------------------------------------------------------------------------- */

    public function test_referers_are_folded_by_host(): void
    {
        [, $store] = $this->makeTenant();

        $this->visit($store->id, 1, null, 'https://example.test/one');
        $this->visit($store->id, 2, null, 'https://example.test/two?utm=x');
        $this->visit($store->id, 3, null, 'https://EXAMPLE.test/three');
        $this->visit($store->id, 4, null, 'https://other.test/a');

        $channels = $this->figures($store)['channels'];

        $this->assertSame(['example.test', 'other.test'], $this->labels($channels),
            'Three different URLs on one host must fold into one row, case-insensitively, ordered '
            . 'by count.');

        $this->assertStringContainsString('3', $this->countFor($channels, 'example.test'));
    }

    /** CLAIM 2: a missing referer is not a direct visit. */
    public function test_a_missing_referer_is_not_called_direct(): void
    {
        [, $store] = $this->makeTenant();

        $this->visit($store->id, 1, null, null);
        $this->visit($store->id, 2, null, '');
        $this->visit($store->id, 3, null, 'https://example.test/x');

        $channels = $this->figures($store)['channels'];
        $labels = $this->labels($channels);

        $this->assertContains('No referring page recorded', $labels);

        foreach ($labels as $label) {
            $this->assertStringNotContainsStringIgnoringCase('direct', $label, sprintf(
                'A row is labelled "%s". A missing referer is NOT evidence of a direct visit — a '
                . 'privacy setting, an in-app browser, a bookmark and an HTTPS-to-HTTP hop all '
                . 'remove the header. Calling it direct invents a traffic source.',
                $label
            ));
        }

        $this->assertStringContainsString('NOT as direct', $channels['hint'],
            'The hint must say what the empty bucket is not, because the reader will otherwise '
            . 'assume it.');
    }

    public function test_an_unparseable_referer_is_not_printed_as_a_host(): void
    {
        [, $store] = $this->makeTenant();

        $this->visit($store->id, 1, null, 'not a url at all');

        $labels = $this->labels($this->figures($store)['channels']);

        $this->assertSame(['Referring page could not be read'], $labels,
            'A referer with no host must not be printed as though it were one.');
    }

    /** CLAIM 3: no host is filed under a marketing channel. */
    public function test_no_host_is_filed_under_a_marketing_channel(): void
    {
        [, $store] = $this->makeTenant();

        $this->visit($store->id, 1, null, 'https://www.google.com/search?q=shoes');
        $this->visit($store->id, 2, null, 'https://m.facebook.com/x');

        $channels = $this->figures($store)['channels'];

        $this->assertSame(['www.google.com', 'm.facebook.com'], $this->labels($channels),
            'The hosts have been classified into channels. That needs a curated list of search and '
            . 'social domains, a list goes stale, and a visit filed under the wrong channel is '
            . 'worse than one shown under its own domain name.');

        foreach (['Search', 'Social', 'Paid', 'Organic'] as $invented) {
            $this->assertStringNotContainsString($invented, implode(' ', $this->labels($channels)));
        }

        $this->assertStringContainsString('No host is filed under a marketing channel', $channels['hint'],
            'The hint must say that the classification was deliberately not made, or a merchant '
            . 'will read the host list as a channel list.');
    }

    public function test_the_referrer_breakdown_is_windowed_and_store_scoped(): void
    {
        [, $store] = $this->makeTenant();
        [, $other] = $this->makeTenant();

        $this->visit($store->id, 1, null, 'https://example.test/a');
        $this->visit($store->id, 40, null, 'https://example.test/b');
        $this->visit($other->id, 1, null, 'https://example.test/c');

        $this->assertStringContainsString('1',
            $this->countFor($this->figures($store)['channels'], 'example.test'),
            'The referrer breakdown is reading outside its window or outside its store.');
    }

    /**
     * CLAIM 4: THE CAP IS DISCLOSED WHEN IT IS REACHED, driven with real rows.
     *
     * The host fold happens in PHP, so the referer read is bounded. A bound that is not disclosed
     * reads as completeness, which is the one thing a breakdown must never imply falsely. 500
     * distinct referring URLs are inserted so the bound is genuinely hit rather than asserted from
     * the source.
     */
    public function test_reaching_the_referer_bound_is_disclosed(): void
    {
        [, $store] = $this->makeTenant();

        $rows = [];
        $when = $this->now()->subDays(1)->toDateTimeString();

        /*
         * 501, NOT 500, AND THAT CHANGE IS THE POINT. This test was written against a read that
         * took 500 rows and concluded "capped" on `count >= 500`, so 500 distinct referrers -- a
         * list that fits exactly -- was reported as truncated. The read now takes 501 and tests
         * `> 500`, so the fixture has to actually EXCEED the bound to reach the disclosure. The
         * other side of the boundary has its own test, `test_exactly_the_bound_is_not_reported_as_truncated`.
         */
        for ($i = 0; $i < 501; $i++) {
            $rows[] = [
                'store_id' => $store->id,
                'referer' => 'https://host' . $i . '.test/page',
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }

        Visit::insert($rows);

        $channels = $this->figures($store)['channels'];

        $this->assertStringContainsString('most common referring pages were read', $channels['hint'], sprintf(
            'The read hit its bound and the hint did not say so. A silently truncated breakdown '
            . 'reads as a complete one. Hint was: %s',
            $channels['hint']
        ));
    }

    /**
     * THE DEVICE HINT NAMES THE DETECTOR'S BLIND SPOT, because the big bucket is not a failure and
     * a merchant has no way to know that.
     *
     * "Device not recorded" is honest about what the row IS, and it was not honest about WHY. The
     * detector's entire desktop rule set is `['Macintosh' => 'Macintosh']` -- there is no Windows
     * rule, no Linux rule and no ChromeOS rule anywhere in the library -- so on a normal store the
     * largest bucket by far is "not recorded", consisting almost entirely of ordinary Windows
     * visitors. Shown without that explanation, a merchant reasonably concludes their analytics is
     * broken, or worse, that their traffic is unidentifiable and the figure is worthless.
     *
     * `VisitorDetectorPremiseTest` measures the detector itself and fails if the library ever
     * learns Windows, which is what would make this sentence stale.
     */
    public function test_the_device_hint_explains_why_the_unrecorded_bucket_is_large(): void
    {
        [, $store] = $this->makeTenant();

        $this->visit($store->id, 1, null);
        $this->visit($store->id, 1, 'iPhone');

        $hint = $this->figures($store)['devices']['hint'];

        $this->assertStringContainsString('does not identify a Windows, Linux or ChromeOS computer', $hint, sprintf(
            'The device hint does not say that the detector cannot see a Windows, Linux or '
            . 'ChromeOS machine at all, so the large "not recorded" bucket reads as a malfunction '
            . 'rather than as a known limit of the measurement. Hint was: %s',
            $hint
        ));
    }

    /**
     * THE CAP IS DETECTED EXACTLY, AND THE UNREPRESENTED VISITS ARE COUNTED -- both halves of what
     * an independent review found wrong with the first version of this bound.
     *
     * Two defects, driven here rather than argued:
     *
     *  1. OFF BY ONE. The read took `MAX` rows and concluded "capped" on `count >= MAX`, so a store
     *     with PRECISELY 500 distinct referring pages was told its list was truncated when it was
     *     not. Over-disclosure is not the safe direction: a sentence that cries wolf is a sentence
     *     a merchant learns to skip, and this screen's whole value is that its sentences are true.
     *  2. AN UNSTATED DISAGREEMENT. The cap bounds distinct referring PAGES, but a row is a HOST,
     *     so when the read is capped a host's printed count is the sum of only the page-groups that
     *     survived. The column then does not add up to the page-views tile three tiles above it,
     *     and "less frequent ones are not represented" does not tell the reader by HOW MUCH.
     *
     * THE FIXTURE IS 501 PAGES ON ONE HOST, which is not a contrived shape -- it is the ordinary
     * one. A shopper who browses eight products sends eight distinct `Referer` URLs, all on the
     * store's own domain, so an internally-navigated storefront reaches 500 distinct pages long
     * before it reaches 500 distinct hosts. The host folds to a single row whose count is 500 while
     * 501 visits were recorded, and the hint must name the missing one.
     */
    public function test_the_remainder_is_stated_when_the_referer_read_is_capped(): void
    {
        [, $store] = $this->makeTenant();

        $rows = [];
        $when = $this->now()->subDays(1)->toDateTimeString();

        for ($i = 0; $i < 501; $i++) {
            $rows[] = [
                'store_id' => $store->id,
                'referer' => 'https://shop.test/product/' . $i,
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }

        Visit::insert($rows);

        $figures = $this->figures($store);
        $channels = $figures['channels'];

        $this->assertSame(['shop.test'], $this->labels($channels),
            'The 501 pages are all on one host, so there is exactly one row.');

        $this->assertSame('500', $this->plainCountFor($channels, 'shop.test'),
            'The row count is the sum of the page-groups that survived the cap, which is 500.');

        $this->assertSame('501', (string) preg_replace('/[\x{2066}-\x{2069}]/u', '', (string) $figures['visits']['value']),
            'The page-views tile counts visits, not referring pages, so it reads 501 -- and that '
            . 'is precisely the disagreement the hint has to explain.');

        /*
         * THE ISOLATES ARE STRIPPED FOR THE MATCH, NOT FROM THE PRODUCT. The figure wraps every
         * number in U+2066/U+2069 so a Western-digit count does not reorder inside an Arabic
         * sentence, so the hint really holds `\u{2066}1\u{2069} visit(s)`. Asserting the bare form
         * would fail on invisible characters that are correct, and asserting the wrapped form would
         * make this test about bidi rather than about the number -- which has its own coverage.
         */
        $plainHint = (string) preg_replace('/[\x{2066}-\x{2069}]/u', '', $channels['hint']);

        $this->assertStringContainsString('1 visit(s) are not represented', $plainHint, sprintf(
            'The read was capped and the hint did not say HOW MANY visits are missing from the '
            . 'rows, so a merchant comparing this column with the page-views tile sees two '
            . 'figures disagree for no stated reason. Hint was: %s',
            $channels['hint']
        ));
    }

    /**
     * EXACTLY AT THE BOUND IS NOT OVER THE BOUND. 500 distinct pages fit, so nothing is missing and
     * the sentence must be absent -- the off-by-one above, driven from the other side.
     */
    public function test_exactly_the_bound_is_not_reported_as_truncated(): void
    {
        [, $store] = $this->makeTenant();

        $rows = [];
        $when = $this->now()->subDays(1)->toDateTimeString();

        for ($i = 0; $i < 500; $i++) {
            $rows[] = [
                'store_id' => $store->id,
                'referer' => 'https://shop.test/product/' . $i,
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }

        Visit::insert($rows);

        $channels = $this->figures($store)['channels'];

        $this->assertSame('500', $this->plainCountFor($channels, 'shop.test'));
        $this->assertStringNotContainsString('most common referring pages were read', $channels['hint'],
            'Exactly 500 distinct pages all fit, so nothing was truncated and the truncation '
            . 'sentence is a false claim. A disclosure that fires when it should not teaches a '
            . 'merchant to ignore every disclosure on the screen.');
    }

    /**
     * THE CAP KEEPS THE MOST FREQUENT PAGES, which is the only reason a bounded read is acceptable
     * at all -- and it was asserted nowhere.
     *
     * `groupedVisits()` orders by `total` descending and THEN limits, so the rows that survive are
     * the ones that matter. Nothing pinned that: with the `orderByDesc` removed, SQLite returns
     * groups in whatever order it likes and the busiest referrer can fall off the list entirely,
     * while every other assertion in this file still passes. The fixture makes the busiest host
     * unambiguous -- five visits against one each -- so the first row is deterministic.
     */
    public function test_the_capped_read_keeps_the_busiest_referrers(): void
    {
        [, $store] = $this->makeTenant();

        $rows = [];
        $when = $this->now()->subDays(1)->toDateTimeString();

        /*
         * THE BUSY HOST SORTS LAST ALPHABETICALLY, AND THAT IS THE WHOLE FIXTURE DESIGN.
         *
         * The first version of this test named the busy host `busy.test` against 500 `quiet{N}.test`
         * -- and a mutation that replaced `orderByDesc('total')` with `orderBy('bucket')` PASSED it,
         * because "busy" sorts before "quiet" and the row survived the limit by alphabetical luck.
         * The test proved nothing it claimed to prove. With `zzz-` against `aaa-`, frequency order
         * and alphabetical order disagree: ordered by count the busy host is first and kept;
         * ordered any other way the 500 single-visit hosts fill the bound and it is DROPPED.
         */
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'store_id' => $store->id,
                'referer' => 'https://zzz-busy.test/landing',
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }

        for ($i = 0; $i < 500; $i++) {
            $rows[] = [
                'store_id' => $store->id,
                'referer' => 'https://aaa-quiet' . $i . '.test/p',
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }

        Visit::insert($rows);

        $channels = $this->figures($store)['channels'];
        $labels = $this->labels($channels);

        $this->assertContains('zzz-busy.test', $labels,
            'The busiest referrer was dropped by the cap entirely. The read is not ordered by '
            . 'frequency, so the bound is discarding rows at random rather than keeping the ones '
            . 'that matter -- which makes a bounded read indefensible.');

        $this->assertSame('zzz-busy.test', $labels[0],
            'The busiest referrer is not first, so the rows are not ordered by frequency.');
        $this->assertSame('5', $this->plainCountFor($channels, 'zzz-busy.test'));

        $this->assertStringContainsString('most common referring pages were read', $channels['hint'],
            '501 distinct pages exceed the 500 bound, so the truncation sentence belongs here.');
    }

    /** And it is NOT disclosed when the bound was not reached, or the sentence is noise. */
    public function test_the_bound_is_not_mentioned_when_it_was_not_reached(): void
    {
        [, $store] = $this->makeTenant();

        $this->visit($store->id, 1, null, 'https://example.test/a');

        $this->assertStringNotContainsString('most common referring pages were read',
            $this->figures($store)['channels']['hint'],
            'The cap disclosure appears for a store with one referring page, so it says nothing '
            . 'and trains the reader to ignore it.');
    }

    /**
     * THE EXPORT CARRIES BOTH BREAKDOWNS, each row with its own period and disclosure.
     *
     * P05-15's rule applies to these as much as to the money figures: an export that drops a
     * caveat is a defect. A breakdown is rows rather than one figure, so it goes through
     * `FigureCsv::breakdownRows()` -- same four columns, the disclosure repeated on every row,
     * because sorting or copying a range in a spreadsheet separates a number from a footnote.
     */
    public function test_the_export_carries_both_breakdowns_with_their_disclosures(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store->id, 1, 'Windows', 'https://example.test/a');

        $figures = $this->figures($store);

        $rows = \App\Support\Metrics\FigureCsv::rows($figures, ['orders' => __('Orders')]);

        foreach (['devices' => __('Devices'), 'channels' => __('Referring pages')] as $key => $label) {
            $rows = array_merge($rows, \App\Support\Metrics\FigureCsv::breakdownRows($figures[$key], $label));
        }

        $csv = \App\Support\Metrics\FigureCsv::render($rows);

        $this->assertStringContainsString('Devices — Windows', $csv,
            'The device breakdown did not reach the export, or its rows are not prefixed with the '
            . "breakdown's own name -- two lists in one download are unreadable without it.");
        $this->assertStringContainsString('Referring pages — example.test', $csv);

        $this->assertStringContainsString('request', strtolower($csv),
            'The exported rows carry no visits caveat. P05-15: an export that drops a caveat is a '
            . 'defect, and a breakdown is no exception.');
        $this->assertStringContainsString('NOT as direct', $csv,
            'The exported referrer rows do not say that a missing referring page is not a direct '
            . 'visit -- the one reading the reader will otherwise make, in the one place they can '
            . 'sort and filter the rows away from any footnote.');
    }

    /** An empty breakdown still writes a row, so it cannot vanish from the file. */
    public function test_an_empty_breakdown_exports_a_row_saying_so(): void
    {
        [, $store] = $this->makeTenant();

        $csv = \App\Support\Metrics\FigureCsv::render(
            \App\Support\Metrics\FigureCsv::breakdownRows($this->figures($store)['devices'], __('Devices'))
        );

        $this->assertStringContainsString(__('Not available'), $csv,
            'An empty breakdown wrote nothing. A breakdown that vanishes from a file reads as a '
            . 'breakdown with no data, which is a different claim from "no visits were recorded".');
        $this->assertStringContainsString('Nothing is estimated', $csv);
    }

    /** Both breakdowns inherit the visits caveat: a visit is a REQUEST, not a person. */
    public function test_both_breakdowns_carry_the_visits_caveat(): void
    {
        [, $store] = $this->makeTenant();

        $this->visit($store->id, 1, 'Windows', 'https://example.test/a');

        $figures = $this->figures($store);

        foreach (['devices', 'channels'] as $key) {
            $this->assertStringContainsString('request', strtolower($figures[$key]['hint']), sprintf(
                'The %s breakdown does not carry the visits caveat. A visit is a REQUEST and not a '
                . 'person — no cookie-level de-duplication, and bots and prefetchers are counted — '
                . 'and every figure built on visits inherits that.',
                $key
            ));
        }
    }
}
