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

        for ($i = 0; $i < 500; $i++) {
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
