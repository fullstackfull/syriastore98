<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-ORD-3 / ORD-4 / ORD-6 — three confirmed accessibility and contrast findings on the order
 * list, each of which reaches far past that one screen.
 * =================================================================================================
 *
 *  #3  THE BULK-SELECT CHECKBOXES HAVE NO ACCESSIBLE NAME. `app/Helper/helper.php` builds the
 *      select-all column heading as the bare markup `<input type="checkbox" id="select-all">` and
 *      each row cell as `'<input type="checkbox" class="select-row" value="' + this.id + '">'`. No
 *      `<label>`, no `aria-label`, no `title`, and the heading cell holds nothing but the input, so
 *      the column has no header text to fall back on either — `public/js/store-syria-ds.js` records
 *      that as a known fact ("An empty heading -- the select-all column -- gets an empty label").
 *      A screen-reader user hears "checkbox, not checked" once per row with nothing saying which
 *      order it selects, and the control these feed is BULK DELETE. `buildDataTableColumn()` puts
 *      this column on every admin table, so the fix is 67 tables wide.
 *
 *  #4  THE `.ss-select` CHEVRON IS A HARD-CODED HEX INSIDE A `data:` URI, so no theme can move it:
 *      `stroke='%235b6665'`, which is 2.39:1 on the dark surface `#262b33` — below the 3:1
 *      non-text minimum. Measured off the committed captures at all four order filters:
 *      rgb(86,98,97) on rgb(38,43,51) = 2.25:1 composited. `appearance: none` removes the native
 *      arrow, so in dark mode the filter bar reads as four flat text boxes with nothing marking
 *      them as dropdowns. Light mode measured 5.18–5.42:1 and is left alone.
 *
 *      WHY A TOKEN AND NOT A `mask-image`. The audit offered `mask-image` with
 *      `background-color: currentColor`. A mask applies to the WHOLE element, and `.ss-select` is
 *      the field itself — masking it to the chevron's shape would clip away the field's own
 *      background. CSS cannot interpolate a `var()` inside a `url()`, so the URI is the token:
 *      `--ss-select-chevron`, redefined in each of the three theme blocks the file already keeps.
 *
 *  #6  THE ORDER ID CELL IS VENDORED BOOTSTRAP INSIDE THE DESIGN SYSTEM'S IDENT COLUMN.
 *      `OrderDataTable` built it as `<a class="btn btn-primary btn-sm text-sm">` while
 *      `getColumns()` declares the same column `'addClass' => 'ss-dt-ident'`, and
 *      `store-syria-ds.css` says `td.ss-dt-ident a { color: var(--ss-dt-ident-fg) !important }`.
 *      The `!important` beats Bootstrap's un-flagged `.btn-primary { color: #fff }`, so the cell
 *      painted `#1A1D23` ink on a `#335DA8` fill: **2.63:1**, below even the 3:1 floor, with ZERO
 *      white pixels in the measured region. In dark mode the fill does not move, so ten
 *      light-theme navy pills sat on a dark page whose real primary is `#2AC3E5`.
 *      The contradiction was inside one file: the status column of the same table carries a
 *      20-line comment headed "THE DESIGN SYSTEM'S BADGE, NOT THE VENDORED THEME'S BUTTONS", and
 *      the customer area had already settled this exact column on a quiet text link
 *      (`CustomerDataTable:66-70`).
 *
 * EVERY CONTRAST FIGURE BELOW IS COMPUTED, not asserted as a remembered number: `ratio()` is
 * WCAG 2.x relative luminance, so a future colour change is measured rather than trusted.
 */
class OrderListControlNamesAndContrastTest extends TestCase
{
    /** `--ss-surface` in the dark theme, read from the stylesheet by the premise test below. */
    private const DARK_SURFACE = '#262b33';

    /** WCAG 1.4.11: non-text contrast. A chevron is the only thing marking the field a dropdown. */
    private const NON_TEXT_MINIMUM = 3.0;

    /**
     * The same source with every PHP comment removed.
     *
     * THIS IS NOT TIDYING. The repairs below carry long comments that QUOTE the markup they
     * replaced -- `btn btn-primary btn-sm` appears in the explanation of why it is gone. A scan
     * that cannot tell code from prose would report the explanation as the defect, which is the
     * mistake this repository has already paid for ("the raw-expression ratchet reported my
     * docblocks as a tenancy regression"). Every assertion about what the SOURCE does runs
     * against this.
     */
    private function code(string $relative): string
    {
        $source = $this->read($relative);

        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                /* A newline per comment, so line-based assertions and `substr` windows keep
                   roughly the shape they had. */
                $stripped .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }

    public function test_premise_the_comment_stripper_removes_comments_and_keeps_code(): void
    {
        $code = $this->code('app/DataTables/OrderDataTable.php');

        $this->assertStringNotContainsString('THE DESIGN SYSTEM\'S BADGE', $code,
            'the stripper left a comment behind, so every assertion below can be satisfied or '
            . 'broken by prose');
        $this->assertStringContainsString("->editColumn('product_order_id'", $code,
            'the stripper removed code as well as comments');
        $this->assertStringContainsString('ss-dt-ident', $code);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;

        $this->assertFileExists($path, 'premise: ' . $relative);

        return (string) file_get_contents($path);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim(strtolower($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $channel = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
    }

    private static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public function test_premise_the_contrast_maths_agrees_with_a_known_pair(): void
    {
        /* Black on white is 21:1 and the defect this file is about was measured at 2.39:1. If the
         * arithmetic were wrong, every threshold below would be meaningless. */
        $this->assertSame(21.0, round(self::ratio('#000000', '#ffffff'), 2));
        $this->assertSame(2.39, round(self::ratio('#5b6665', self::DARK_SURFACE), 2),
            'the defect figure from the audit does not reproduce, so the maths or the surface is '
            . 'wrong');
    }

    public function test_premise_the_dark_surface_is_still_what_this_test_measures_against(): void
    {
        $css = $this->read('public/css/store-syria-ds.css');

        $this->assertMatchesRegularExpression(
            '/--ss-surface:\s*' . preg_quote(self::DARK_SURFACE, '/') . '\s*;/i',
            $css,
            'the dark --ss-surface is no longer ' . self::DARK_SURFACE
            . ', so the chevron threshold below is computed against the wrong background'
        );
    }

    // =============================================================================================
    //  #3 — the bulk-select checkboxes
    // =============================================================================================

    public function test_the_select_all_checkbox_has_an_accessible_name(): void
    {
        $helper = $this->code('app/Helper/helper.php');

        $this->assertMatchesRegularExpression(
            '/->title\(\'<input type="checkbox"[^\']*aria-label="/',
            $helper,
            'the select-all column heading is still a bare checkbox with no name. It controls bulk '
            . 'delete on every admin table.'
        );
    }

    public function test_the_row_checkbox_has_an_accessible_name(): void
    {
        $helper = $this->code('app/Helper/helper.php');

        if (preg_match('/->render\(\'function\(\) \{(.*?)\}\'\)/s', $helper, $m) !== 1) {
            $this->fail('premise: the checkbox column still renders through a JS function');
        }

        $this->assertStringContainsString('aria-label', $m[1],
            'the per-row checkbox is still unnamed: ' . trim($m[1]));
    }

    public function test_both_checkbox_names_are_translated(): void
    {
        /* The admin is bilingual. A hard-coded English name is the same defect in Arabic. */
        $helper = $this->code('app/Helper/helper.php');

        $start = strpos($helper, 'function bulkDeleteCloneCheckboxColumn()');
        $this->assertNotFalse($start, 'premise: the checkbox column builder');

        $end = strpos($helper, 'function bulkDeleteForm', $start);
        $this->assertNotFalse($end, 'premise: the next function follows it');

        $block = substr($helper, $start, $end - $start);

        preg_match_all("/__\('Select [^']+'\)/", $block, $found);

        $this->assertCount(2, $found[0],
            'expected a translated name for the select-all and for the row checkbox; found: '
            . implode(', ', $found[0]));
    }

    public function test_the_row_checkbox_name_is_not_injectable(): void
    {
        /* The name is built inside a JS string that is concatenated into an HTML attribute. The
         * only row value used must be one the server controls — `this.id` is the integer primary
         * key. A shopper-written field (a customer's name) interpolated here would be an attribute
         * injection on the merchant's own origin, which is the defect class this repository has
         * already paid for twice on this very table's `rawColumns()`. */
        $helper = $this->code('app/Helper/helper.php');

        if (preg_match('/->render\(\'function\(\) \{(.*?)\}\'\)/s', $helper, $m) !== 1) {
            $this->fail('premise: the checkbox column still renders through a JS function');
        }

        $body = $m[1];

        preg_match_all('/this\.([A-Za-z_][A-Za-z0-9_]*)/', $body, $used);

        $this->assertSame(['id'], array_values(array_unique($used[1])),
            'the row checkbox renderer reads row fields other than the integer id: '
            . implode(', ', array_unique($used[1])));
    }

    // =============================================================================================
    //  #4 — the select chevron follows the theme
    // =============================================================================================

    public function test_the_select_chevron_is_a_token_and_not_a_baked_hex(): void
    {
        $css = $this->read('public/css/store-syria-ds.css');

        $start = strpos($css, '.ss-select {');
        $this->assertNotFalse($start, 'premise: the .ss-select rule');

        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);

        $this->assertStringContainsString('var(--ss-select-chevron)', $rule,
            'the chevron is still painted by a literal inside the rule, so no theme can move it: '
            . $rule);
        $this->assertStringNotContainsString("stroke='%23", $rule,
            'the rule still carries a baked stroke colour: ' . $rule);
    }

    public function test_every_theme_defines_the_chevron(): void
    {
        $css = $this->read('public/css/store-syria-ds.css');

        $this->assertSame(3, preg_match_all('/--ss-select-chevron:/', $css),
            'the chevron token must be defined once per theme block — light, explicit dark, and '
            . 'the `prefers-color-scheme` arm of `auto`. A missing one leaves that theme with no '
            . 'chevron at all, which is worse than a faint one.');
    }

    public function test_the_dark_chevron_reaches_the_non_text_minimum(): void
    {
        $css = $this->read('public/css/store-syria-ds.css');

        preg_match_all(
            "/--ss-select-chevron:[^;]*stroke='%23([0-9a-fA-F]{6})'/",
            $css,
            $strokes
        );

        $this->assertCount(3, $strokes[1],
            'could not read a stroke colour out of each of the three chevron definitions');

        /* The light one is first in the file and is already 5.18–5.42:1 on a light surface; the two
         * dark ones are what this finding is about. Both must clear 3:1 on #262b33. */
        $dark = array_slice($strokes[1], 1);

        foreach ($dark as $index => $hex) {
            $ratio = self::ratio('#' . $hex, self::DARK_SURFACE);

            $this->assertGreaterThanOrEqual(
                self::NON_TEXT_MINIMUM,
                round($ratio, 2),
                sprintf(
                    'dark chevron %d is #%s on %s = %.2f:1, below the %.1f:1 non-text minimum. '
                    . 'The chevron is the only cue that a .ss-select opens, because '
                    . '`appearance: none` removes the native one.',
                    $index + 1,
                    $hex,
                    self::DARK_SURFACE,
                    $ratio,
                    self::NON_TEXT_MINIMUM
                )
            );
        }
    }

    public function test_the_two_dark_definitions_agree(): void
    {
        /* `[data-ss-theme="dark"]` and the `prefers-color-scheme` arm of `auto` are the same theme
         * reached two ways. A merchant who switches the toggle must not see the chevron change. */
        $css = $this->read('public/css/store-syria-ds.css');

        preg_match_all(
            "/--ss-select-chevron:[^;]*stroke='%23([0-9a-fA-F]{6})'/",
            $css,
            $strokes
        );

        $this->assertCount(3, $strokes[1], 'premise: three definitions');
        $this->assertSame(strtolower($strokes[1][1]), strtolower($strokes[1][2]),
            'the explicit dark theme and the automatic dark theme paint different chevrons');
    }

    // =============================================================================================
    //  #6 — the order id cell
    // =============================================================================================

    public function test_the_order_id_cell_is_not_a_vendored_bootstrap_button(): void
    {
        $table = $this->code('app/DataTables/OrderDataTable.php');

        $start = strpos($table, "->editColumn('product_order_id'");
        $this->assertNotFalse($start, 'premise: the order id column is still edited here');

        $end = strpos($table, "->editColumn('order_date'", $start);
        $this->assertNotFalse($end, 'premise: the next column follows it');

        $cell = substr($table, $start, $end - $start);

        $this->assertStringNotContainsString('btn btn-primary', $cell,
            'the order id is still a filled vendored primary button inside the design system\'s '
            . 'ident column. The ident rule carries `!important`, so it wins and paints '
            . sprintf('%.2f:1', self::ratio('#1a1d23', '#335da8')) . ' in light mode.');
        $this->assertStringNotContainsString('btn-sm', $cell,
            'the cell still carries Bootstrap button sizing');
    }

    public function test_the_order_id_cell_still_links_to_the_same_order(): void
    {
        /* The repair is a presentation change. The destination, the encryption of the id and the
         * `#` prefix are the contract the merchant and four other tests depend on. */
        $table = $this->code('app/DataTables/OrderDataTable.php');

        $start = (int) strpos($table, "->editColumn('product_order_id'");
        $end = (int) strpos($table, "->editColumn('order_date'", $start);
        $cell = substr($table, $start, $end - $start);

        $this->assertStringContainsString("route('order.view'", $cell,
            'the order id no longer links to the order');
        $this->assertStringContainsString('Crypt::encrypt($item->id)', $cell,
            'the link no longer encrypts the id the way every other caller does');
        $this->assertStringContainsString('e($item->product_order_id)', $cell,
            'the value is no longer escaped — this column is in rawColumns()');
    }

    public function test_the_order_id_column_is_still_declared_as_the_row_identifier(): void
    {
        $table = $this->code('app/DataTables/OrderDataTable.php');

        $this->assertStringContainsString("'addClass' => 'ss-dt-ident'", $table,
            'the column lost its ident class, so the design system no longer paints it and the '
            . 'repair has simply removed the styling rather than handing it over');
    }

    public function test_the_ident_colour_now_has_no_fill_to_fight(): void
    {
        /* The 2.63:1 reading was ident ink on a Bootstrap FILL. With the fill gone the ink sits on
         * the table surface, and that is the pair worth asserting. Both themes, computed. */
        $css = $this->read('public/css/store-syria-ds.css');

        foreach ([['#1a1d23', '#ffffff', 'light'], ['#f0f2f6', self::DARK_SURFACE, 'dark']] as [$ink, $bg, $theme]) {
            $this->assertStringContainsString('--ss-dt-ident-fg: ' . $ink, strtolower($css),
                'the ' . $theme . ' ident token is no longer ' . $ink);

            $this->assertGreaterThanOrEqual(4.5, round(self::ratio($ink, $bg), 2),
                sprintf('the %s ident ink is %s on %s = %.2f:1', $theme, $ink, $bg,
                    self::ratio($ink, $bg)));
        }
    }
}
