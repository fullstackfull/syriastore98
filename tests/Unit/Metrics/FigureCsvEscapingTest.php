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
