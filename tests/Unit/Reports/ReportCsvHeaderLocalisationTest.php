<?php

namespace Tests\Unit\Reports;

use Illuminate\Support\Facades\Blade;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * The five report CSV exports must hand a merchant a header row in the merchant's own language,
 * with as many columns as the data rows have fields, and with a byte-order mark.
 * ============================================================================================
 *
 * Three things were wrong at once and each needs its own instrument.
 *
 * 1. THE HEADER WAS A PHP-SIDE ENGLISH STRING LITERAL. `sale_by_brand.blade.php` wrote
 *    `"data:text/csv;charset=utf-8,Duration,Brand Name,Total Net Sale\n"`, so no `__()` call was
 *    ever made and the column names could not vary with the locale. The reports hub
 *    (`reports/index.blade.php`) already did it correctly, so the five were the outliers.
 *
 * 2. NO BYTE-ORDER MARK, while column 1 is already Arabic. `ReportController::yearMonth()`
 *    builds `monthList` from `__('January')`…`__('December')` and `ar.json` translates them, so
 *    the Arabic export already contained Arabic bytes that Excel misread. The BOM is a
 *    zero-width character: a human reading the diff cannot see it, so it is asserted AS BYTES
 *    here. `encodeURI('\u{FEFF}') === '%EF%BB%BF'`, which is why the existing `encodeURI` call
 *    must stay.
 *
 * 3. QUOTING IS LOAD-BEARING, AND ONLY IN ENGLISH. `encodeURI(',')` is `,` — a comma is NOT
 *    escaped. The settled label for the gross figure is `Order value, before discounts`, which
 *    `en.json` stores WITH an ASCII comma (and 14 of the 16 locale files lack the key, so they
 *    fall back to it); `ar.json` alone translates it comma-free. So an unquoted `.join(',')`
 *    yields 10 header columns against 9 data fields in `order_report`, and 8 against 7 in
 *    `bar_chart_order_report` — in English and 13 other locales, but NOT in Arabic. A test that
 *    only drove Arabic would pass while English shipped misaligned columns. That is why
 *    `test_the_english_header_without_quoting_would_misalign` exists as a control: it proves
 *    this test can still see the defect it was written for.
 *
 * Everything here is measured from the REAL view source, rendered through the REAL catalogue:
 * the header terms come out of `Blade::render()` on the file's own `var header = [...]` array,
 * and the data-row field count is counted from the file's own template literal. Neither side is
 * hardcoded, so a later edit to either one is caught.
 */
class ReportCsvHeaderLocalisationTest extends TestCase
{
    use StripsSourceComments;

    /**
     * The five export screens, and the number of fields their data rows carry.
     *
     * The count is ALSO measured from the source (see `dataRowFieldCount()`); it is written out
     * here so that a change to the data row cannot silently drag the expectation along with it.
     */
    private const SCREENS = [
        'resources/views/reports/sale_by_brand.blade.php' => 3,
        'resources/views/reports/sale_by_category.blade.php' => 3,
        'resources/views/reports/sale_by_product.blade.php' => 3,
        'resources/views/reports/order_report.blade.php' => 9,
        'resources/views/reports/bar_chart_order_report.blade.php' => 7,
    ];

    /** The reports hub, which was already correct and is the model, not a target. */
    private const EXEMPLAR = 'resources/views/reports/index.blade.php';

    /*
     * ---------------------------------------------------------------------------------------
     * PREMISE
     * ---------------------------------------------------------------------------------------
     */

    public function test_premise_the_month_names_the_export_writes_are_translated_into_arabic(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertIsArray($ar);

        foreach (['January', 'June', 'December'] as $month) {
            $this->assertArrayHasKey($month, $ar, sprintf(
                'ar.json no longer translates %s. Column 1 of every one of these exports is '
                . "ReportController::yearMonth()'s __('%s')-built list, so if that is no longer "
                . 'translated the byte-order-mark half of this finding has lost its premise.',
                $month,
                $month
            ));
            $this->assertNotSame($month, $ar[$month]);
        }
    }

    public function test_premise_the_english_catalogue_still_stores_a_header_term_containing_a_comma(): void
    {
        $en = json_decode((string) file_get_contents(base_path('resources/lang/en.json')), true);

        $this->assertIsArray($en);
        $this->assertArrayHasKey('Order value, before discounts', $en);
        $this->assertStringContainsString(',', (string) $en['Order value, before discounts'], sprintf(
            'The English value for "Order value, before discounts" no longer contains a comma, '
            . 'so the quoting this test insists on is no longer load-bearing and '
            . 'test_the_english_header_without_quoting_would_misalign has lost its premise.'
        ));
    }

    /*
     * ---------------------------------------------------------------------------------------
     * THE REPAIRED BEHAVIOUR
     * ---------------------------------------------------------------------------------------
     */

    public function test_every_export_header_is_built_from_translation_calls(): void
    {
        foreach (array_keys(self::SCREENS) as $relative) {
            $array = $this->headerArraySource($relative);

            $calls = preg_match_all('/@json\(\s*__\(/', $array);

            $this->assertSame(self::SCREENS[$relative], $calls, sprintf(
                "%s builds its CSV header from %d @json(__()) call(s); it needs %d, one per "
                . "column.\nHeader array found:\n%s",
                $relative,
                $calls,
                self::SCREENS[$relative],
                $array
            ));
        }
    }

    public function test_no_export_still_carries_an_english_header_string_literal(): void
    {
        /*
         * SCOPED TO THE CSV STATEMENT, deliberately. A first draft of this test scanned the
         * whole file for "Total Net Sale" and failed on three CHART LEGEND series names
         * (bar_chart_order_report:82, order_report:70, sale_by_product:96). Those are a
         * different surface with a different fix, and folding them in here would be the
         * over-reach this finding was warned about -- see
         * test_the_chart_legend_series_names_are_out_of_scope_and_still_present below, which
         * pins them so the next reader knows they were seen and left.
         */
        $literals = [
            'Duration,Brand Name',
            'Duration,Category Name',
            'Total Net Sale',
            'Total Average Net Sale',
            'Total Gross Sale',
            'Total Average Gross Sale',
            'Total Purchased items',
            'Total Shipping Charge',
            'Total Worth of coupon',
            'Total orders',
        ];

        foreach (array_keys(self::SCREENS) as $relative) {
            $statement = $this->csvContentStatement($relative);

            foreach ($literals as $literal) {
                $this->assertStringNotContainsString($literal, $statement, sprintf(
                    "%s still carries the untranslated header literal \"%s\" in its CSV "
                    . "statement:\n%s",
                    $relative,
                    $literal,
                    $statement
                ));
            }
        }
    }

    public function test_the_csv_statement_is_only_the_mark_and_the_translated_header(): void
    {
        foreach (array_keys(self::SCREENS) as $relative) {
            $this->assertSame(
                'var csvContent = "data:text/csv;charset=utf-8,' . "\u{FEFF}" . '" + header + "\n";',
                trim($this->csvContentStatement($relative)),
                $relative . ' no longer builds csvContent from the mark plus the translated header alone.'
            );
        }
    }

    /**
     * NOT A DEFECT THIS FINDING REPAIRS, pinned so it is not mistaken for one later.
     *
     * The chart legend still names the series in English. That is a rendered-chart concern on a
     * different surface, and renaming it here would change what the picture says on the basis of
     * a CSV finding. If a later change translates these, delete this test in the same commit.
     */
    public function test_the_chart_legend_series_names_are_out_of_scope_and_still_present(): void
    {
        $legends = [
            'resources/views/reports/order_report.blade.php',
            'resources/views/reports/bar_chart_order_report.blade.php',
            'resources/views/reports/sale_by_product.blade.php',
        ];

        foreach ($legends as $relative) {
            $this->assertStringContainsString(
                "name: 'Total Net Sale'",
                $this->sourceWithoutComments($relative),
                $relative . ' chart legend was changed; that was out of scope for the CSV header '
                . 'finding, so either revert it or delete this test deliberately.'
            );
        }
    }

    public function test_the_byte_order_mark_is_present_as_bytes(): void
    {
        foreach (array_keys(self::SCREENS) as $relative) {
            $code = $this->sourceWithoutComments($relative);

            $this->assertStringContainsString("charset=utf-8,\u{FEFF}", $code, sprintf(
                '%s does not prefix its CSV with U+FEFF. Column 1 is an Arabic month name under '
                . 'the Arabic locale, and without the mark Excel misreads the whole file. The '
                . 'character is zero-width, so this can only be checked as bytes.',
                $relative
            ));
        }
    }

    public function test_the_existing_encode_uri_call_is_left_in_place(): void
    {
        /*
         * encodeURI is what turns the mark into %EF%BB%BF inside the data: URI. Dropping it
         * while "simplifying" the repair would silently un-fix half of it.
         */
        foreach (array_keys(self::SCREENS) as $relative) {
            $this->assertStringContainsString(
                'encodeURI(csvContent)',
                $this->sourceWithoutComments($relative),
                $relative . ' no longer calls encodeURI, which is what encodes the byte-order mark.'
            );
        }

        $this->assertSame(
            '%EF%BB%BF',
            rawurlencode("\u{FEFF}"),
            'Premise: the mark survives percent-encoding.'
        );
    }

    public function test_each_header_quotes_its_fields(): void
    {
        foreach (array_keys(self::SCREENS) as $relative) {
            $code = $this->sourceWithoutComments($relative);

            $this->assertMatchesRegularExpression(
                '/\.map\(\s*function\s*\([^)]*\)\s*\{\s*return\s*\x27"\x27/',
                $code,
                sprintf(
                    '%s joins its header terms without CSV-quoting them. encodeURI does not '
                    . 'escape a comma, and the English label "Order value, before discounts" '
                    . 'contains one, so an unquoted join adds a column.',
                    $relative
                )
            );

            $this->assertStringContainsString(
                'replace(/"/g, \'""\')',
                $code,
                $relative . ' quotes its header fields without doubling an embedded quote.'
            );
        }
    }

    /**
     * THE CONTROL THE WHOLE FINDING TURNS ON: the column count in ENGLISH, not just Arabic.
     */
    public function test_the_header_column_count_matches_the_data_row_in_english_and_in_arabic(): void
    {
        foreach (self::SCREENS as $relative => $expected) {
            $measuredDataFields = $this->dataRowFieldCount($relative);

            $this->assertSame($expected, $measuredDataFields, sprintf(
                '%s writes %d field(s) per data row, not the %d this test expects. The data row '
                . 'changed; re-derive the header term list before touching this expectation.',
                $relative,
                $measuredDataFields,
                $expected
            ));

            foreach (['en', 'ar'] as $locale) {
                $columns = str_getcsv($this->renderedHeaderLine($relative, $locale));

                $this->assertCount($expected, $columns, sprintf(
                    "%s under locale '%s': a CSV reader sees %d header column(s) against %d "
                    . "data field(s).\nHeader line:\n%s",
                    $relative,
                    $locale,
                    count($columns),
                    $expected,
                    $this->renderedHeaderLine($relative, $locale)
                ));
            }
        }
    }

    public function test_the_arabic_header_is_arabic_in_every_column(): void
    {
        foreach (array_keys(self::SCREENS) as $relative) {
            foreach ($this->renderedHeaderTerms($relative, 'ar') as $index => $term) {
                $this->assertNotSame('', trim($term), sprintf(
                    '%s header column %d is empty under Arabic.',
                    $relative,
                    $index + 1
                ));

                /*
                 * __() returns the KEY when a translation is absent, so a half-translated
                 * header does not error -- it ships English words in an Arabic file. This is
                 * the silent no-op the finding predicted for the two Average columns.
                 */
                $this->assertMatchesRegularExpression(
                    '/\p{Arabic}/u',
                    $term,
                    sprintf(
                        '%s header column %d reads "%s" under the Arabic locale -- it fell back '
                        . 'to its English key. Add the key to resources/lang/ar.json WITH an '
                        . 'Arabic value; a key mapped to the English string passes a key-existence '
                        . 'check and still ships a half-English header.',
                        $relative,
                        $index + 1,
                        $term
                    )
                );
            }
        }
    }

    public function test_the_english_header_still_reads_as_english_prose(): void
    {
        foreach (array_keys(self::SCREENS) as $relative) {
            foreach ($this->renderedHeaderTerms($relative, 'en') as $index => $term) {
                $this->assertNotSame('', trim($term), sprintf(
                    '%s header column %d is empty under English.',
                    $relative,
                    $index + 1
                ));

                $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $term, sprintf(
                    '%s header column %d is "%s" under English, which is not plain ASCII prose.',
                    $relative,
                    $index + 1,
                    $term
                ));

                $this->assertDoesNotMatchRegularExpression(
                    '/[._]|[a-z]+[A-Z]/',
                    $term,
                    sprintf(
                        '%s header column %d is "%s", which reads as a translation identifier '
                        . 'rather than a column name.',
                        $relative,
                        $index + 1,
                        $term
                    )
                );
            }
        }
    }

    /*
     * ---------------------------------------------------------------------------------------
     * NEGATIVE CONTROLS
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Proves this test can still SEE the defect: strip the quoting back out and English
     * misaligns, while Arabic does not.
     */
    public function test_the_english_header_without_quoting_would_misalign(): void
    {
        $expectedToBreak = [
            'resources/views/reports/order_report.blade.php' => 9,
            'resources/views/reports/bar_chart_order_report.blade.php' => 7,
        ];

        foreach ($expectedToBreak as $relative => $fields) {
            $unquotedEnglish = str_getcsv(implode(',', $this->renderedHeaderTerms($relative, 'en')));
            $unquotedArabic = str_getcsv(implode(',', $this->renderedHeaderTerms($relative, 'ar')));

            $this->assertGreaterThan($fields, count($unquotedEnglish), sprintf(
                'An UNQUOTED English join of %s now yields %d column(s) for %d data field(s). '
                . 'If that is no longer more than %d, the comma-bearing English label is gone '
                . 'and this control no longer proves the quoting is load-bearing.',
                $relative,
                count($unquotedEnglish),
                $fields,
                $fields
            ));

            $this->assertCount($fields, $unquotedArabic, sprintf(
                'An unquoted ARABIC join of %s was expected to be fine -- ar.json translates the '
                . 'gross label comma-free. It is not, which means an Arabic-only test of this '
                . 'repair would now fail too and the asymmetry this control documents is gone.',
                $relative
            ));
        }
    }

    public function test_the_exemplar_reports_hub_is_left_alone(): void
    {
        $code = $this->sourceWithoutComments(self::EXEMPLAR);

        $this->assertStringContainsString("charset=utf-8,\u{FEFF}", $code);
        $this->assertStringContainsString('@json(__(\'Period\'))', $code);

        /*
         * It joins WITHOUT quoting, and that is correct for it: none of its five terms carries a
         * comma in any locale file. Widening the diff to "fix" it would be unevidenced.
         */
        $this->assertStringNotContainsString('replace(/"/g', $code, sprintf(
            '%s has been given the quoting helper. It was already correct -- its five terms are '
            . 'comma-free in all 16 locale files -- so this widens the diff without evidence.',
            self::EXEMPLAR
        ));

        foreach ($this->renderedHeaderTerms(self::EXEMPLAR, 'en') as $term) {
            $this->assertStringNotContainsString(',', $term);
        }
    }

    public function test_the_json_payload_guards_are_untouched(): void
    {
        /*
         * The CSV is assembled client-side from JSON the five endpoints already return. The key
         * set must not move: a shipped client could be reading it.
         */
        $guards = [
            'resources/views/reports/sale_by_brand.blade.php' => ['monthList', 'NetSaleTotal'],
            'resources/views/reports/sale_by_category.blade.php' => ['monthList', 'NetSaleTotal'],
            'resources/views/reports/sale_by_product.blade.php' => ['monthList', 'NetSaleTotal', 'PurchasedItemTotal'],
            'resources/views/reports/order_report.blade.php' => [
                'monthList', 'TotalOrderCount', 'NetSaleTotal', 'AverageNetSales',
                'GrossSaleTotal', 'AverageGrossSales', 'PurchasedItemTotal', 'ShippingTotal',
                'CouponTotal',
            ],
            'resources/views/reports/bar_chart_order_report.blade.php' => [
                'monthList', 'NetSaleTotal', 'AverageNetSales', 'GrossSaleTotal',
                'AverageGrossSales', 'ShippingTotal', 'CouponTotal',
            ],
        ];

        foreach ($guards as $relative => $keys) {
            $code = $this->sourceWithoutComments($relative);

            foreach ($keys as $key) {
                /*
                 * THE KEY SET IS THE CONTRACT, NOT ITS SPELLING IN THE GUARD. A first draft
                 * looked for the quoted literal `"monthList"`, which only existed because the
                 * guard was `hasOwnProperty("monthList")`. The failure-feedback repair replaced
                 * that with `Array.isArray(data.monthList)` -- the same key, read the same way,
                 * checked better -- and this assertion failed on the spelling while the contract
                 * was intact. `data.<Key>` is what the screen actually reads.
                 */
                $this->assertStringContainsString('data.' . $key, $code, sprintf(
                    '%s no longer reads the JSON key %s. The header repair must not change which '
                    . 'payload keys the screen reads.',
                    $relative,
                    $key
                ));
            }

            $this->assertStringContainsString('"exported_data.csv"', $code);
        }
    }

    public function test_no_route_permission_or_controller_predicate_moved(): void
    {
        $routes = [
            'resources/views/reports/sale_by_brand.blade.php' => "route('brand.order.export')",
            'resources/views/reports/sale_by_category.blade.php' => "route('category.order.export')",
            'resources/views/reports/sale_by_product.blade.php' => "route('product.order.export')",
            'resources/views/reports/order_report.blade.php' => "route('order.reports.export')",
            'resources/views/reports/bar_chart_order_report.blade.php' => "route('order.barchart.reports.export')",
        ];

        foreach ($routes as $relative => $route) {
            $this->assertStringContainsString(
                $route,
                $this->sourceWithoutComments($relative),
                $relative . ' no longer calls ' . $route . '; this repair is view plus locale only.'
            );
        }
    }

    /*
     * ---------------------------------------------------------------------------------------
     * MEASUREMENT HELPERS -- everything is read out of the real source and real catalogue
     * ---------------------------------------------------------------------------------------
     */

    /** The `var header = [ ... ]` array literal, comments stripped, Blade still uncompiled. */
    private function headerArraySource(string $relative): string
    {
        $code = $this->sourceWithoutComments($relative);

        $matched = preg_match('/var header = \[(.*?)\]/s', $code, $m);

        $this->assertSame(1, $matched, sprintf(
            '%s does not build its CSV header from a `var header = [ ... ]` array of translated '
            . 'terms. It is still a PHP-side English string literal, so no __() call is made and '
            . 'the header cannot vary with the locale.',
            $relative
        ));

        return (string) $m[1];
    }

    /** The single `var csvContent = ...;` statement, comments stripped. */
    private function csvContentStatement(string $relative): string
    {
        $code = $this->sourceWithoutComments($relative);

        /*
         * UP TO THE CLOSING `";`, not the first semicolon -- `data:text/csv;` contains one, and
         * a first draft of this helper stopped there and compared half a statement.
         */
        $matched = preg_match('/var csvContent\s*=.*?";/s', $code, $m);

        $this->assertSame(1, $matched, $relative . ' has no csvContent assignment.');

        return (string) $m[0];
    }

    /** @return list<string> the header terms as the real catalogue renders them. */
    private function renderedHeaderTerms(string $relative, string $locale): array
    {
        $array = $this->headerArraySource($relative);

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $rendered = Blade::render('[' . $array . ']');
        } finally {
            app()->setLocale($previous);
        }

        $terms = json_decode(preg_replace('/,\s*\]\s*$/', ']', trim($rendered)) ?? '', true);

        $this->assertIsArray($terms, sprintf(
            "%s header array did not render to a JSON array under locale '%s'.\nRendered:\n%s",
            $relative,
            $locale,
            $rendered
        ));

        return array_values($terms);
    }

    /** The header line as a CSV reader receives it: quoted the way the view quotes it. */
    private function renderedHeaderLine(string $relative, string $locale): string
    {
        return implode(',', array_map(
            static fn (string $term): string => '"' . str_replace('"', '""', $term) . '"',
            $this->renderedHeaderTerms($relative, $locale)
        ));
    }

    /** How many fields the view's own data-row template literal writes. */
    private function dataRowFieldCount(string $relative): int
    {
        $code = $this->sourceWithoutComments($relative);

        $matched = preg_match('/`\$\{data\.monthList\[i\]\}(.*?)`/s', $code, $m);

        $this->assertSame(1, $matched, sprintf(
            '%s no longer writes exactly one data-row template literal beginning with '
            . 'data.monthList[i]; the header/row comparison has lost its other side.',
            $relative
        ));

        return 1 + preg_match_all('/\$\{/', (string) $m[1]);
    }
}
