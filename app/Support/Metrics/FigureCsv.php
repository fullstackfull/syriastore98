<?php

namespace App\Support\Metrics;

/**
 * P5-8 — ONE CSV WRITER FOR THE HONEST FIGURES, so a disclosure cannot be lost on the way out.
 * =================================================================================================
 *
 * The Phase 05 rule for this slice is that an export carries the same caveats as the screen, and
 * that an export which drops one is a defect. The only way to guarantee that is to make the
 * disclosure a COLUMN rather than a footnote: every row carries its own figure, the period that
 * figure covers and what it leaves out, so no amount of sorting, filtering or copying in a
 * spreadsheet can separate a number from its limits. A caveat in a header row, or in a trailing
 * comment line, survives none of those.
 *
 * WHAT THIS DOES NOT DO, deliberately:
 *
 *  - It does NOT compute, re-read or re-aggregate anything. It is handed the same array the Blade
 *    is handed, so the screen and the file cannot disagree about a number. A second query here
 *    would be a second definition of every figure.
 *  - It does NOT translate. `__()` has already run inside the figures class, in the request's own
 *    locale, so an Arabic session exports Arabic labels and disclosures.
 *  - It does NOT turn a null into a zero or a blank. `value === null` means "we cannot say", and it
 *    is written as the same words the screen shows. A blank cell in a spreadsheet reads as zero,
 *    which is the single most dangerous thing this whole workstream exists to prevent.
 *
 * WHY THE BIDI ISOLATES ARE STRIPPED. The figures classes wrap numbers in U+2066 / U+2069 so a
 * Western-digit number does not reorder inside an Arabic sentence. That is correct for HTML and
 * wrong for a CSV cell: Excel and LibreOffice treat the isolate characters as part of the text, so
 * `=SUM()` over the column fails and the cell displays a stray glyph. They are removed from the
 * VALUE column only -- the prose columns keep theirs, because there the isolate is doing its job.
 */
final class FigureCsv
{
    /**
     * @param  array<string, array{value: ?string, period: string, hint: string}>  $figures
     * @param  array<string, string>  $labels  figure key => the label the screen gives it, in order
     * @return list<list<string>>  header row first
     */
    public static function rows(array $figures, array $labels): array
    {
        $rows = [[
            __('Figure'),
            __('Value'),
            __('Period'),
            __('What this leaves out'),
        ]];

        foreach ($labels as $key => $label) {
            if (! isset($figures[$key]) || ! array_key_exists('value', $figures[$key])) {
                continue;
            }

            $figure = $figures[$key];

            /*
             * EVERY CELL IS PLAINED, not just the value. Until 2026-11-22 only `value` was, and
             * the asymmetry was invisible because no hint had ever contained an isolate.
             *
             * That changed when the visit-coverage disclosure began wrapping its DATE in `ltr()`
             * -- correct on the rendered page, where an unwrapped Western-digit date reorders
             * itself inside an Arabic sentence, and wrong in a spreadsheet cell, where it is an
             * invisible character that defeats an exact-match filter or a lookup against the same
             * text typed by hand.
             *
             * The invariant is now total and therefore testable in one assertion: NO isolate ever
             * reaches the file. `plain()` is a no-op on text that never had one, so no existing
             * output changes.
             */
            $rows[] = [
                self::plain($label),
                $figure['value'] === null ? __('Not available') : self::plain((string) $figure['value']),
                self::plain((string) ($figure['period'] ?? '')),
                self::plain((string) ($figure['hint'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * The per-currency money block of the platform screen, which is a set of amounts and not one
     * figure. Each currency becomes its own row and EVERY row repeats the period and the
     * disclosure, including the sentence saying the rows are not to be added together -- which is
     * precisely the thing a spreadsheet invites its reader to do.
     *
     * @param  array{rows: list<array{currency: string, amount: string}>, period: string, hint: string}  $block
     * @return list<list<string>>
     */
    public static function currencyRows(array $block, string $label): array
    {
        $rows = [];

        /*
         * EVERY CELL IS PLAINED HERE TOO, AND UNTIL NOW ONLY THE AMOUNT WAS -- which made this
         * class's own claim above ("The invariant is now total ... NO isolate ever reaches the
         * file") FALSE for this one builder. Measured by an independent review on the real
         * download: the platform file's disclosure column carried U+2066/U+2069 around the counts
         * in "At least 1 paid orders ..." and "Stores left out ...: 1", for the payments block as
         * well as the new profit one. `rows()` and `breakdownRows()` had both been corrected on
         * 2026-11-22 and this builder was missed, so the asymmetry the two notes above describe
         * survived in the third place it could occur.
         *
         * `plain()` is a no-op on text that never had an isolate, so no cell that was already
         * clean changes.
         */
        foreach ($block['rows'] as $row) {
            $rows[] = [
                self::plain($label . ' — ' . (string) $row['currency']),
                self::plain((string) $row['amount']),
                self::plain((string) ($block['period'] ?? '')),
                self::plain((string) ($block['hint'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[] = [
                self::plain($label),
                __('Not available'),
                self::plain((string) ($block['period'] ?? '')),
                self::plain((string) ($block['hint'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * A BREAKDOWN — one row per bucket — in the same four columns as every other figure.
     *
     * ANA-004's device and referrer lists are not single figures, so they cannot go through
     * `rows()`; they are shaped like `currencyRows()` and are written the same way, with the label
     * prefixed by the breakdown's own name so the file stays readable when two breakdowns sit in
     * one download. EVERY row repeats the period and the disclosure, for the reason this class
     * exists: a spreadsheet lets a reader sort, filter or copy a range, and each of those separates
     * a number from a footnote.
     *
     * An EMPTY breakdown still writes one row, saying `Not available` rather than nothing at all.
     * A breakdown that vanishes from a file reads as a breakdown with no data, which is a different
     * claim from "we recorded no visits in this period".
     *
     * @param  array{rows: list<array{label: string, count: string}>, period: string, hint: string}  $breakdown
     * @return list<list<string>>
     */
    public static function breakdownRows(array $breakdown, string $label): array
    {
        $rows = [];

        foreach ($breakdown['rows'] as $row) {
            $rows[] = [
                /*
                 * THE LABEL IS PLAINED TOO, and until 2026-11-22 only the count was.
                 *
                 * That asymmetry was invisible while every breakdown label was platform-derived
                 * -- a device name, a parsed host -- and so never carried an isolate. It began to
                 * matter when a VISITOR-authored campaign label was wrapped in U+2066..U+2069 to
                 * contain a possible bidi override on the rendered page.
                 *
                 * Isolates are right on screen and wrong in a data file: they are invisible, and
                 * they defeat an exact-match filter or a lookup against the same name typed by
                 * hand. `plain()` is a no-op on every label that was already clean, so this
                 * changes no existing output.
                 */
                self::plain($label . ' — ' . (string) ($row['label'] ?? '')),
                self::plain((string) ($row['count'] ?? '')),
                self::plain((string) ($breakdown['period'] ?? '')),
                self::plain((string) ($breakdown['hint'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[] = [
                self::plain($label),
                __('Not available'),
                self::plain((string) ($breakdown['period'] ?? '')),
                self::plain((string) ($breakdown['hint'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * RFC 4180 through PHP's own writer rather than `implode(',')`.
     *
     * Hand-rolled joins are how a merchant-typed name containing a comma or a quote splits a row,
     * and `category_order_export` carried exactly that class of bug until P5-3 (it is now
     * CSV-quoted there too). `fputcsv` quotes and doubles correctly; the UTF-8 BOM is what makes
     * Excel on Windows read Arabic as Arabic instead of mojibake.
     *
     * `escape: ''` IS NOT COSMETIC AND IS NOT THE PHP 8.4 DEPRECATION. PHP's default escape is a
     * BACKSLASH, which is not in RFC 4180 at all: with it, `fputcsv` writes a backslash through
     * verbatim AND SUPPRESSES THE DOUBLING OF THE QUOTE THAT FOLLOWS IT. Measured here, against a
     * host this class really can be handed:
     *
     *     parse_url('https://ev\\"il.test/x', PHP_URL_HOST)  ===  'ev\\"il.test'
     *     fputcsv(h, ['Referring pages — ev\\"il.test', '5', 'last 30 days', 'hint'])
     *         writes  "Referring pages — ev\\"il.test",5,...
     *
     * -- one unescaped `"` inside a quoted field. Excel and LibreOffice end the field there, so the
     * REST OF THE ROW SHIFTS LEFT: the count lands in the period column and the disclosure lands in
     * a column of its own. That is this class's own failure mode, a figure separated from its
     * caveat, produced by a shopper's `Referer` header. `escape: ''` makes the writer RFC 4180:
     * quotes are doubled, backslashes are data, nothing is special.
     *
     * It changes the bytes ONLY for a field containing a backslash, and in that case the old bytes
     * were unparseable, so there is no reader this breaks. Pinned by FigureCsvEscapingTest.
     *
     * NOT FIXED HERE, because it is not this class's to fix and must not be left implicit:
     * `parse_url` also accepts `=cmd.test` as a host, and a leading `=` is a FORMULA in Excel. The
     * only reason that is harmless today is that every breakdown cell is prefixed with its label
     * (`'Referring pages — ' . $host`), so no cell this class writes can BEGIN with `=`. Any future
     * caller that writes an unprefixed value into the label column reopens it.
     */
    public static function render(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }

        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}" . $body;
    }

    /** Strip the bidi isolates the figures classes add for HTML; see the class docblock. */
    private static function plain(string $value): string
    {
        return (string) preg_replace('/[\x{2066}-\x{2069}]/u', '', $value);
    }
}
