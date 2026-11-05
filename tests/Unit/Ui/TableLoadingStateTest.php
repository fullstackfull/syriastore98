<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE LOADING STATE IS INVISIBLE IN DARK MODE, AND IT SPEAKS ENGLISH.  P2.5-WAVE-8
 * =================================================================================================
 *
 * The directive names loading states explicitly. MEASURED in the running application: they already
 * EXIST -- `serverSide: true`, `ajax: true`, `processing: true` on every merchant DataTable, and
 * the `.dataTables_processing` element really is inserted on every draw. I nearly reported the
 * opposite: grepping helper.php for `'processing'` finds nothing, because DataTables turns it on
 * BY DEFAULT whenever serverSide is set. The config being absent did not mean the feature was.
 *
 * So the defect is not absence. It is two things:
 *
 * 1. IN DARK MODE IT CANNOT BE SEEN. Measured on /product with the element forced visible:
 *
 *        light   #293240 on #FFFFFF   12.93:1   fine
 *        dark    #293240 on #292A33    1.10:1   *** invisible ***
 *
 *    The BACKGROUND follows the theme (white -> #292A33) and the FOREGROUND does not: it stays
 *    #293240, the light theme's body colour. So every sort, filter, search and page change on
 *    every server-side merchant table shows a loading panel a merchant in dark mode cannot see --
 *    and a server-side draw is exactly when they most need to know something is happening.
 *
 * 2. IT SAYS "Processing..." IN ARABIC TOO. The string IS translated -- `ar.json:931` carries
 *    "Processing": "المعالجة" -- but the DataTables `language` block never sets `processing`, so
 *    the library falls back to its own built-in English. The translation existed and was not
 *    being asked for. Same shape as the untranslated "Showing 0 to 0 of 0 entries".
 */
class TableLoadingStateTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';
    private const HELPER = 'app/Helper/helper.php';
    private const OPENER = '/* ---- P2.5-WAVE-8: the loading state ----';

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function decls(): string
    {
        $css = $this->read(self::SHEET);
        $at = strpos($css, self::OPENER);
        $this->assertNotFalse($at, 'the P2.5-WAVE-8 section opener is missing');
        $next = strpos($css, '/* ---- ', $at + strlen(self::OPENER));
        $block = $next === false ? substr($css, $at) : substr($css, $at, $next - $at);

        return (string) preg_replace('#/\*.*?\*/#s', '', $block);
    }

    private function lum(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $c = [];
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function ratio(string $a, string $b): float
    {
        $x = $this->lum($a);
        $y = $this->lum($b);
        [$hi, $lo] = $x > $y ? [$x, $y] : [$y, $x];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public function test_the_section_carries_declarations_not_only_prose(): void
    {
        $decls = $this->decls();

        // ONE DECLARATION IS THE RIGHT NUMBER HERE, and the first version of this premise
        // demanded more than four -- which would have pushed the fix to grow for the test's
        // sake. The whole argument of this section is that the blast radius is ONE property on
        // ONE library-generated element. The premise still does its job: it fails if the
        // section is prose with no CSS at all.
        $this->assertGreaterThan(
            0,
            substr_count($decls, ';'),
            'the WAVE-8 section is all comment and no CSS, so every assertion below is vacuous'
        );
        $this->assertStringContainsString('{', $decls);
    }

    /** The foreground follows the theme, like the background already did. */
    public function test_the_loading_panel_takes_a_theme_aware_foreground(): void
    {
        $decls = $this->decls();

        $this->assertStringContainsString(
            '.dataTables_processing',
            $decls,
            'the processing panel is still unstyled by the design system'
        );
        $this->assertMatchesRegularExpression(
            '/\.dataTables_processing[^{]*\{[^}]*color:\s*var\(--ss-fg\)/s',
            $decls,
            'the panel does not take the theme foreground, so dark mode keeps the light value'
        );
    }

    /**
     * PREMISE plus the arithmetic. `--ss-fg` is #1a1d23 light / #e8eaed dark; the panel sits on
     * white and on #292A33. Both must clear AA, or "theme-aware" is just a different wrong value.
     */
    public function test_the_chosen_foreground_clears_aa_on_both_panel_surfaces(): void
    {
        // The value that was there, which must still be the failure it is documented as.
        $this->assertLessThan(
            3.0,
            $this->ratio('#293240', '#292a33'),
            'the old hard-coded foreground is no longer invisible on the dark panel, so the '
                . 'premise of this file has changed and needs re-measuring'
        );

        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#1a1d23', '#ffffff'));
        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#e8eaed', '#292a33'));
    }

    /** The translation already existed. It just was not being asked for. */
    public function test_the_datatables_language_block_asks_for_the_processing_string(): void
    {
        $helper = $this->read(self::HELPER);

        $this->assertMatchesRegularExpression(
            '/[\'"]processing[\'"]\s*=>/',
            $helper,
            'the DataTables language block never sets `processing`, so the library falls back to '
                . 'its own built-in English on every Arabic page'
        );
        $this->assertMatchesRegularExpression(
            '/[\'"]processing[\'"]\s*=>[^,]*__\(\s*[\'"]Processing[\'"]/',
            $helper,
            'the processing string is hard-coded rather than translated'
        );
    }

    /** The key it asks for must exist in BOTH language files, or one of them renders the key. */
    public function test_the_processing_key_is_present_in_both_language_files(): void
    {
        foreach (['resources/lang/ar.json', 'resources/lang/en.json'] as $file) {
            $map = json_decode($this->read($file), true);
            $this->assertIsArray($map, "{$file} is not valid JSON");
            $this->assertArrayHasKey(
                'Processing',
                $map,
                "{$file} has no `Processing` key, so that language renders the key itself"
            );
        }
    }
}
