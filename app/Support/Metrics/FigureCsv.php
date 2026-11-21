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

            $rows[] = [
                $label,
                $figure['value'] === null ? __('Not available') : self::plain((string) $figure['value']),
                (string) ($figure['period'] ?? ''),
                (string) ($figure['hint'] ?? ''),
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

        foreach ($block['rows'] as $row) {
            $rows[] = [
                $label . ' — ' . $row['currency'],
                self::plain((string) $row['amount']),
                (string) ($block['period'] ?? ''),
                (string) ($block['hint'] ?? ''),
            ];
        }

        if ($rows === []) {
            $rows[] = [
                $label,
                __('Not available'),
                (string) ($block['period'] ?? ''),
                (string) ($block['hint'] ?? ''),
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
     */
    public static function render(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
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
