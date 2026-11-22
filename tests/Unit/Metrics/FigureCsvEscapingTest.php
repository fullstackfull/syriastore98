<?php

namespace Tests\Unit\Metrics;

use App\Support\Metrics\FigureCsv;
use Tests\TestCase;

/**
 * P5-8 — THE EXPORT MUST SURVIVE A SHOPPER-SUPPLIED STRING, read back by a real parser.
 * =================================================================================================
 *
 * `FigureCsv` exists so a figure cannot be separated from its caveat. ANA-004 then started feeding
 * it a value NO ONE IN THIS SYSTEM TYPED: a referrer host, taken from `parse_url()` over a
 * `Referer` header that any site on the internet chooses. That is the first untrusted string this
 * writer has ever had to carry, and it broke the guarantee.
 *
 * MEASURED, not reasoned about:
 *
 *     parse_url('https://ev\\"il.test/x', PHP_URL_HOST)  ===  'ev\\"il.test'
 *
 * `parse_url` does not reject a backslash or a quote in a host, so that string really can reach
 * the label column. PHP's `fputcsv` defaults its `$escape` parameter to a BACKSLASH -- which is not
 * in RFC 4180 at all -- and the effect is not "a backslash appears in the output": the backslash
 * SUPPRESSES THE DOUBLING OF THE QUOTE AFTER IT. The field is written containing a single
 * unescaped `"`, so a reader ends the field early and EVERY LATER COLUMN SHIFTS LEFT: the count
 * lands under Period and the disclosure lands in a column of its own. A figure separated from its
 * caveat, produced by a header a stranger sent. `escape: ''` makes the writer RFC 4180.
 *
 * WHY THIS READS THE OUTPUT BACK RATHER THAN MATCHING A STRING. An assertion on expected bytes
 * proves what I think a spreadsheet does; `str_getcsv` IS a conforming reader, so a round trip
 * proves what a spreadsheet actually does. The first version of this test asserted bytes and would
 * have passed against output no program could parse.
 *
 * THE FORMULA CASE IS HERE TOO, as a guard rather than a fix. `parse_url` also accepts `=cmd.test`
 * as a host, and a leading `=` is a formula in Excel. Nothing in `FigureCsv` strips it; the only
 * reason it is harmless is that `breakdownRows()` prefixes every label with the breakdown's name,
 * so no cell can BEGIN with `=`. That is load-bearing and invisible, so it is pinned: a future
 * caller that writes an unprefixed value into the label column fails this test.
 */
final class FigureCsvEscapingTest extends TestCase
{
    /** @return list<list<string>> the file parsed back by a conforming reader */
    private function roundTrip(array $rows): array
    {
        $csv = FigureCsv::render($rows);

        // The BOM is for Excel's encoding sniffer and is not part of the first field.
        $csv = preg_replace('/^\x{FEFF}/u', '', $csv);

        $parsed = [];

        foreach (explode("\n", trim($csv, "\n")) as $line) {
            $parsed[] = str_getcsv($line, ',', '"', '');
        }

        return $parsed;
    }

    public function test_the_premise_that_a_backslash_quote_host_is_a_string_this_writer_can_receive(): void
    {
        $this->assertSame('ev\\"il.test', parse_url('https://ev\\"il.test/x', PHP_URL_HOST),
            'parse_url no longer returns a host containing a backslash and a quote. If it now '
            . 'rejects them, the escaping defect below is unreachable through the referrer path -- '
            . 'but the writer is shared, so keep the round trip.');

        $this->assertSame('=cmd.test', parse_url('https://=cmd.test/x', PHP_URL_HOST),
            'parse_url no longer returns a host beginning with "=".');
    }

    /**
     * THE DEFECT, driven end to end: four columns in, four columns out, with the value intact.
     *
     * Before `escape: ''` this produced FIVE fields on the data row, the count under the period
     * heading and the disclosure alone in a column of its own.
     */
    public function test_a_hostile_host_round_trips_without_shifting_the_columns(): void
    {
        $breakdown = [
            'rows' => [
                ['label' => 'ev\\"il.test', 'count' => '5'],
            ],
            'period' => 'last 30 days',
            'hint' => 'Page views, not people.',
        ];

        $rows = array_merge(
            [[__('Figure'), __('Value'), __('Period'), __('What this leaves out')]],
            FigureCsv::breakdownRows($breakdown, 'Referring pages')
        );

        $parsed = $this->roundTrip($rows);

        $this->assertCount(2, $parsed, 'One header row and one data row.');
        $this->assertCount(4, $parsed[1], sprintf(
            'The data row parsed as %d fields instead of 4, so a quote inside the host ended the '
            . 'field early and every later column shifted. Fields were: %s',
            count($parsed[1]),
            json_encode($parsed[1], JSON_UNESCAPED_SLASHES)
        ));

        $this->assertSame('Referring pages — ev\\"il.test', $parsed[1][0],
            'The host did not survive the round trip byte for byte.');
        $this->assertSame('5', $parsed[1][1],
            'The count is not in the Value column, which is the shift this test exists to catch.');
        $this->assertSame('last 30 days', $parsed[1][2]);
        $this->assertSame('Page views, not people.', $parsed[1][3],
            'The disclosure is not in the column its header names -- a figure has been separated '
            . 'from its caveat, which is the one thing FigureCsv exists to prevent.');
    }

    /** A comma and a quote, the ordinary cases, still round trip -- the fix must not break RFC 4180. */
    public function test_commas_and_quotes_still_round_trip(): void
    {
        $parsed = $this->roundTrip([
            ['a,b', 'he said "hi"', 'x', 'y'],
        ]);

        $this->assertSame(['a,b', 'he said "hi"', 'x', 'y'], $parsed[0]);
    }

    /**
     * NO BIDI ISOLATE REACHES THE FILE, FROM ANY COLUMN.
     *
     * `plain()` strips U+2066..U+2069 and existed from the start, but until 2026-11-22 it was
     * applied only to the VALUE and the COUNT. The asymmetry was invisible while no label, period
     * or hint had ever contained one -- every breakdown label was platform-derived and every hint
     * was plain prose.
     *
     * Two changes on that day ended it: a visitor-authored campaign label was wrapped in an
     * isolate so a bidi override could not reorder the rendered table, and the visit-coverage
     * disclosure began wrapping its DATE in one for the same reason. Both are correct ON SCREEN
     * and wrong in a spreadsheet cell, where an isolate is an invisible character that defeats an
     * exact-match filter or a lookup against the same text typed by hand.
     *
     * THIS IS A UNIT TEST OF THE HELPER AND THAT IS DELIBERATE. A feature-level test can only
     * catch a column whose content happens to carry an isolate in its fixture, and a mutation
     * proved the gap: dropping `plain()` from the breakdown HINT was caught by nothing, because
     * the only breakdown hints that carry one are cap disclosures needing 500+ rows to fire.
     * Driving `breakdownRows()` directly asserts the invariant for every column at once.
     */
    public function test_no_exported_cell_carries_a_bidi_isolate(): void
    {
        $lri = "\u{2066}";
        $pdi = "\u{2069}";

        $rows = FigureCsv::breakdownRows([
            'rows' => [
                ['label' => $lri.'spring — newsletter'.$pdi, 'count' => $lri.'12'.$pdi],
            ],
            'period' => $lri.'last 30 days'.$pdi,
            'hint' => 'Page views are recorded from '.$lri.'10 Feb 2026'.$pdi.'.',
        ], 'Campaign traffic');

        // The empty case takes an ISOLATED breakdown NAME as well. That name comes from
        // `AnalyticsExportRows::breakdownLabels()` and is a translated constant, so today it
        // never carries one -- a mutation proved that dropping `plain()` from this row was
        // caught by nothing while the fixture passed a clean name. Passing a dirty one makes the
        // invariant hold for every cell rather than for the cells that happen to be dirty.
        $empty = FigureCsv::breakdownRows([
            'rows' => [],
            'period' => $lri.'last 30 days'.$pdi,
            'hint' => 'Nothing arrived tagged since '.$lri.'10 Feb 2026'.$pdi.'.',
        ], $lri.'Campaign traffic'.$pdi);

        $single = FigureCsv::rows(
            ['visits' => [
                'value' => $lri.'1,240'.$pdi,
                'period' => $lri.'last 30 days'.$pdi,
                'hint' => 'Page views are recorded from '.$lri.'10 Feb 2026'.$pdi.'.',
            ]],
            ['visits' => 'Page views']
        );

        foreach (['breakdown' => $rows, 'empty breakdown' => $empty, 'single figure' => $single] as $what => $set) {
            foreach ($set as $row) {
                foreach ($row as $column => $cell) {
                    foreach (["\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"] as $control) {
                        $this->assertStringNotContainsString($control, (string) $cell, sprintf(
                            'A %s cell (column %d) carries a bidi isolate. Isolates belong on the '
                            . 'rendered page and never in a data file.',
                            $what,
                            $column
                        ));
                    }
                }
            }
        }

        // The premise: the isolates were really there to begin with, so a scan that found none
        // proves the stripping rather than an empty fixture.
        $this->assertStringContainsString('spring', $rows[0][0]);
        $this->assertStringContainsString('10 Feb 2026', $rows[0][3]);
        $this->assertStringContainsString('1,240', $single[1][1]);
    }

    /**
     * THE LABEL PREFIX IS THE FORMULA GUARD, and it is the only one. This asserts the property
     * rather than the implementation: no cell in the label column may begin with a character Excel
     * treats as the start of a formula.
     */
    public function test_no_exported_label_can_begin_with_a_formula_character(): void
    {
        $rows = FigureCsv::breakdownRows([
            'rows' => [
                ['label' => '=cmd.test', 'count' => '1'],
                ['label' => '+1.test', 'count' => '1'],
                ['label' => '-x.test', 'count' => '1'],
                ['label' => '@y.test', 'count' => '1'],
            ],
            'period' => 'last 30 days',
            'hint' => 'Page views, not people.',
        ], 'Referring pages');

        foreach ($rows as $row) {
            $this->assertNotSame('', $row[0], 'An empty label column would expose the raw value.');

            $this->assertStringStartsNotWith('=', $row[0], sprintf(
                'The exported label "%s" begins with a formula character. Excel and LibreOffice '
                . 'evaluate a cell starting with = + - or @, so a referrer host taken from a '
                . 'Referer header would execute as a formula in a merchant\'s spreadsheet. Today '
                . 'the breakdown name prefix prevents this; if a caller stops prefixing, it needs '
                . 'its own guard.',
                $row[0]
            ));
            $this->assertStringStartsNotWith('+', $row[0]);
            $this->assertStringStartsNotWith('-', $row[0]);
            $this->assertStringStartsNotWith('@', $row[0]);
        }
    }
}
