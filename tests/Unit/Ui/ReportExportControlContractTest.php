<?php

namespace Tests\Unit\Ui;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * The five report export controls must BE buttons and must SAY what they do.
 * ==========================================================================
 *
 * Two WCAG 2.1 4.1.2 failures lived in one element on five merchant screens.
 *
 * ROLE. Each was `<a href="javascript:;" class="btn … exportChartButton">`, while the only
 * behaviour bound to `.exportChartButton` is a jQuery `.click()` that builds a `data:text/csv`
 * URI and clicks a synthetic link. So Space did not activate it, and the `href` was a dead
 * target a screen reader announces as "javascript colon semicolon, link".
 *
 * NAME. The bundled Bootstrap is v5.1.3, whose `_fixTitle` guards the `title`→`aria-label` copy
 * on an UNTRIMMED `textContent`. The anchor's `textContent` was the newline-plus-indent between
 * its tags — a truthy string — so the copy was SKIPPED while `setAttribute("title", "")` in the
 * same comma expression ran unconditionally. `dash.js` constructs a Tooltip for every
 * `[data-bs-toggle="tooltip"]` on window load. Afterwards the control had no text node, no
 * `aria-label`, and an emptied `title`.
 *
 * TWO CORRECTIONS TO THE OBVIOUS READING, both worth keeping in mind:
 *   - The pre-repair name was not EMPTY. In Chromium it was the Tabler private-use glyph from
 *     the icon's `::before` — present, and gibberish.
 *   - The tooltip never broke. `_fixTitle` writes the title into `data-bs-original-title`
 *     before emptying `title`, so a sighted mouse user kept it. That is why
 *     `data-bs-toggle="tooltip"` must stay: it is the name a mouse user gets.
 *
 * SOURCE SHAPE ONLY, deliberately. A browser-driven assertion taken after Bootstrap init would
 * pass TODAY on the `title` attribute alone if the tooltip failed to initialise, and would pass
 * TOMORROW for the wrong reason.
 *
 * THIS IS A SEPARATE CLASS, NOT AN EXTENSION of PageActionRegionContractTest. Three of that
 * class's tests fail BY DESIGN on these views: it forbids Bootstrap button classes, requires
 * `ss-btn--primary`/`--secondary`, and reads a ROUTES key these views do not have. D-25 Step 2
 * deliberately left 49 action-button regions on Bootstrap classes, and the already-converted
 * siblings in this very directory (`index`, `orderStatusReport`, `order_country_Report`,
 * `top_5_reports`) all kept `btn btn-sm btn-primary btn-icon`. That is the house pattern here.
 */
class ReportExportControlContractTest extends TestCase
{
    use StripsSourceComments;

    /** The five screens whose export control was an anchor. */
    private const EXPORTS = [
        'resources/views/reports/sale_by_brand.blade.php',
        'resources/views/reports/sale_by_category.blade.php',
        'resources/views/reports/sale_by_product.blade.php',
        'resources/views/reports/order_report.blade.php',
        'resources/views/reports/bar_chart_order_report.blade.php',
    ];

    /** The two genuine navigation links that share the region, and must STAY anchors. */
    private const NAVIGATION = [
        'resources/views/reports/order_report.blade.php' => 'orders.barchart_order_report',
        'resources/views/reports/bar_chart_order_report.blade.php' => 'reports.order_report',
    ];

    /** The already-converted sibling that proves the pattern ships. */
    private const EXEMPLAR = 'resources/views/reports/index.blade.php';

    /*
     * ---------------------------------------------------------------------------------------
     * PREMISES -- the repair depends on each of these, so each is measured, not assumed
     * ---------------------------------------------------------------------------------------
     */

    public function test_premise_the_design_system_defines_the_visually_hidden_class(): void
    {
        $this->assertStringContainsString(
            '.ss-visually-hidden {',
            (string) file_get_contents(base_path('public/css/store-syria-ds.css')),
            'The hidden label this repair adds has no rule, so it would render as visible text.'
        );
    }

    public function test_premise_the_bundled_bootstrap_skips_the_aria_label_copy(): void
    {
        $bootstrap = (string) file_get_contents(base_path('public/assets/js/plugins/bootstrap.min.js'));

        $this->assertStringContainsString('getAttribute("aria-label")', $bootstrap);
        $this->assertStringContainsString('setAttribute("title","")', $bootstrap);
        $this->assertStringContainsString(
            'this._element.textContent',
            $bootstrap,
            'Bootstrap no longer guards the title->aria-label copy on textContent. If this '
            . 'version was upgraded, re-measure the NAME half of this finding before trusting it.'
        );
    }

    public function test_premise_the_exemplar_sibling_already_ships_the_pattern(): void
    {
        $region = $this->actionRegion(self::EXEMPLAR);

        $this->assertStringContainsString('<button type="button"', $region);
        $this->assertStringContainsString('exportChartButton', $region);
        $this->assertStringContainsString('aria-hidden="true"', $region);
        $this->assertStringContainsString('ss-visually-hidden', $region);
        $this->assertStringContainsString("__('Export to CSV')", $region);
    }

    /*
     * ---------------------------------------------------------------------------------------
     * THE REPAIRED BEHAVIOUR
     * ---------------------------------------------------------------------------------------
     */

    public function test_each_export_control_is_a_button_and_not_an_anchor(): void
    {
        foreach (self::EXPORTS as $relative) {
            $region = $this->actionRegion($relative);

            $this->assertSame(1, preg_match_all('/exportChartButton/', $region), sprintf(
                '%s action-button region does not hold exactly one export control.',
                $relative
            ));

            $this->assertMatchesRegularExpression(
                '/<button\s+type="button"\s+class="[^"]*\bexportChartButton\b/',
                $region,
                sprintf(
                    '%s export control is not a <button type="button">. A control that runs '
                    . 'script is a button: as an anchor, Space does not activate it and the '
                    . 'href="javascript:;" is announced as the name.',
                    $relative
                )
            );

            $this->assertStringNotContainsString('href="javascript:;"', $region, sprintf(
                '%s still carries a dead href="javascript:;" in its action-button region.',
                $relative
            ));
        }
    }

    public function test_every_control_in_the_region_has_an_accessible_name(): void
    {
        foreach (array_merge(self::EXPORTS, [self::EXEMPLAR]) as $relative) {
            $region = $this->actionRegion($relative);

            foreach ($this->controls($region) as $control) {
                $this->assertMatchesRegularExpression(
                    '/<span class="ss-visually-hidden">\{\{\s*__\(/',
                    $control,
                    sprintf(
                        "%s has a control with no text node and so no accessible name:\n%s\n"
                        . 'Bootstrap 5.1.3 empties the title on tooltip init and skips the '
                        . 'aria-label copy because the whitespace textContent is truthy, so the '
                        . 'title attribute is not a name. Add a .ss-visually-hidden label.',
                        $relative,
                        trim($control)
                    )
                );
            }
        }
    }

    public function test_every_icon_in_the_region_is_hidden_from_assistive_technology(): void
    {
        foreach (array_merge(self::EXPORTS, [self::EXEMPLAR]) as $relative) {
            $region = $this->actionRegion($relative);

            preg_match_all('/<i\s+class="ti [^"]*"[^>]*>/', $region, $icons);

            $this->assertNotEmpty($icons[0], $relative . ' action-button region has no icon at all.');

            foreach ($icons[0] as $icon) {
                $this->assertStringContainsString('aria-hidden="true"', $icon, sprintf(
                    '%s has an exposed icon: %s. Chromium reads the icon font\'s ::before '
                    . 'content, so an exposed icon BECOMES part of the name.',
                    $relative,
                    $icon
                ));
            }
        }
    }

    public function test_the_visible_tooltip_and_the_hidden_name_are_the_same_string(): void
    {
        /*
         * WCAG 2.5.3 label in name. Keeping __('Export') in the title while the name reads
         * "Export to CSV" is the one variant that creates a mismatch, so both must be the same
         * key. 'Export to CSV' is also the better key on its merits: ar.json maps 'Export' to
         * الصادرات, which is "exports" as in commerce exports, not the verb.
         */
        foreach (self::EXPORTS as $relative) {
            $control = $this->exportControl($relative);

            $this->assertSame(
                2,
                preg_match_all("/__\('Export to CSV'\)/", $control),
                sprintf(
                    "%s export control must use __('Export to CSV') in BOTH its title and its "
                    . "hidden label; a differing pair is a 2.5.3 label-in-name mismatch.\n%s",
                    $relative,
                    trim($control)
                )
            );

            $this->assertStringNotContainsString("__('Export')", $control, sprintf(
                '%s still titles the control __(\'Export\'), whose Arabic value is الصادرات '
                . '("exports", the commerce noun) rather than the verb.',
                $relative
            ));
        }
    }

    public function test_the_two_navigation_links_stay_anchors_with_their_routes(): void
    {
        foreach (self::NAVIGATION as $relative => $route) {
            $region = $this->actionRegion($relative);

            $this->assertMatchesRegularExpression(
                '/<a href="\{\{ route\(\x27' . preg_quote($route, '/') . '\x27\) \}\}"/',
                $region,
                sprintf(
                    '%s no longer links to %s as an anchor. These two ARE navigation and must '
                    . 'stay anchors; converting them would break the link while looking identical.',
                    $relative,
                    $route
                )
            );
        }
    }

    /*
     * ---------------------------------------------------------------------------------------
     * NEGATIVE CONTROLS
     * ---------------------------------------------------------------------------------------
     */

    public function test_the_jquery_selector_token_survives_the_tag_swap(): void
    {
        /*
         * THE CONTROL THAT MATTERS MOST. jQuery binds on the CLASS, not the tag, so the literal
         * `exportChartButton` IS the contract between the markup and the handler. Renaming or
         * dropping it leaves a button that looks right and exports nothing.
         */
        foreach (self::EXPORTS as $relative) {
            $code = $this->sourceWithoutComments($relative);

            $this->assertStringContainsString('exportChartButton', $this->actionRegion($relative));
            $this->assertStringContainsString(
                "\$('.exportChartButton').click(",
                $code,
                $relative . ' no longer binds a handler to .exportChartButton.'
            );
        }
    }

    public function test_the_tooltip_attributes_are_kept(): void
    {
        foreach (self::EXPORTS as $relative) {
            $control = $this->exportControl($relative);

            $this->assertStringContainsString('data-bs-toggle="tooltip"', $control, sprintf(
                '%s dropped the tooltip. It is the name a sighted mouse user gets, and it does '
                . 'NOT break on init -- Bootstrap moves the title into data-bs-original-title.',
                $relative
            ));
            $this->assertStringContainsString('data-bs-placement="top"', $control);
        }
    }

    public function test_the_bootstrap_button_classes_are_kept_and_not_upgraded(): void
    {
        /*
         * OVER-REACH IS THE MAIN RISK HERE. D-25 Step 2 left 49 action-button regions on
         * Bootstrap classes on purpose, and all four already-converted report siblings kept
         * them. A repair that reached for .ss-btn would contradict the converted screens.
         */
        foreach (self::EXPORTS as $relative) {
            $control = $this->exportControl($relative);

            foreach (['btn', 'btn-sm', 'btn-primary', 'btn-icon'] as $class) {
                $this->assertMatchesRegularExpression(
                    '/class="[^"]*\b' . preg_quote($class, '/') . '\b/',
                    $control,
                    $relative . ' dropped the Bootstrap class ' . $class . '.'
                );
            }

            $this->assertStringNotContainsString('ss-btn', $control, sprintf(
                '%s was upgraded to the design-system button. That is a different, deferred '
                . 'decision (D-25) covering 49 regions, not this accessibility repair.',
                $relative
            ));
        }
    }

    public function test_no_stylesheet_was_touched_because_the_repair_adds_no_css(): void
    {
        /*
         * `.btn-icon` has NO rule in store-syria-ds.css, custom.css, rtl-custom.css, or any of
         * the four theme sheets -- the only `btn-icon` hits there are Bootstrap's
         * `--bs-accordion-btn-icon*` custom properties. The class is inert, so the tag swap is
         * visually inert and there is no LTR/RTL twin to mirror. This test pins that premise:
         * if a rule appears, the swap is no longer visually inert and needs a render check.
         */
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css', 'public/css/store-syria-ds.css'] as $sheet) {
            $css = $this->sheetWithoutComments($sheet);

            $this->assertDoesNotMatchRegularExpression(
                '/(^|[\s,>+~{])(a|button)?\.btn-icon\b/m',
                $css,
                sprintf(
                    '%s now has a .btn-icon rule. The anchor-to-button swap was safe because the '
                    . 'class was inert; re-check it in both directions and both themes, and '
                    . 'mirror any change into the other sheet.',
                    $sheet
                )
            );
        }
    }

    /**
     * THE CLAIM THE REVIEW GOT WRONG, so it is measured here rather than taken on trust.
     *
     * The review concluded the tag swap was visually inert because `.btn-icon` has no rule
     * anywhere. True, but incomplete: `a.btn` DOES have rules, in `public/assets/css/style.css`,
     * `public/css/custom.css` AND `public/css/rtl-custom.css`. An element-qualified `a.btn` rule
     * stops applying the moment the anchor becomes a button, which is exactly the class of
     * regression a tag swap causes.
     *
     * MEASURED: every one of them is scoped under an ancestor the action-button region is not
     * inside -- `.header-quick-add`, `.quick-add-btn`, `.according-delete-input`. So nothing is
     * dropped. This test pins that: a BARE `a.btn`, or one scoped under the page-header region,
     * would mean the swap now changes padding, border-radius or display, and needs a real render
     * check in both directions and both themes.
     */
    public function test_no_element_qualified_anchor_button_rule_reaches_the_page_header_region(): void
    {
        $permitted = ['.header-quick-add', '.quick-add-btn', '.according-delete-input'];

        foreach ([
            'public/assets/css/style.css',
            'public/css/custom.css',
            'public/css/rtl-custom.css',
        ] as $sheet) {
            $css = $this->sheetWithoutComments($sheet);

            preg_match_all('/[^{}();\n]*\ba\.btn[a-z-]*\b[^{}]*(?=\{)/', $css, $matches);

            foreach ($matches[0] as $selector) {
                $selector = trim(preg_replace('/\s+/', ' ', $selector) ?? '');

                $scoped = false;

                foreach ($permitted as $ancestor) {
                    if (str_contains($selector, $ancestor . ' ')) {
                        $scoped = true;
                        break;
                    }
                }

                $this->assertTrue($scoped, sprintf(
                    "%s carries an element-qualified anchor-button rule that is not scoped to a "
                    . "region the report action bar sits outside of:\n    %s\n"
                    . 'An `a.btn` rule stops applying when the anchor becomes a <button>, which '
                    . 'is what the five report export controls now are. Confirm it does not '
                    . 'reach div.page-header-icon, and if it does, render /order-reports in both '
                    . 'directions and both themes before shipping -- and mirror any CSS change '
                    . 'into the other of custom.css / rtl-custom.css, which are twins.',
                    $sheet,
                    $selector
                ));
            }
        }
    }

    public function test_the_two_mirrored_stylesheets_agree_about_anchor_button_rules(): void
    {
        /*
         * custom.css serves LTR and rtl-custom.css serves Arabic, and they are mirrored
         * duplicates -- a change in only one reaches only half the merchants. This repair
         * touches neither, so their `a.btn` selector sets must still match.
         */
        $selectors = [];

        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            preg_match_all(
                '/[^{}();\n]*\ba\.btn[a-z-]*\b[^{}]*(?=\{)/',
                (string) file_get_contents(base_path($sheet)),
                $matches
            );

            $found = array_map(
                static fn (string $s): string => trim(preg_replace('/\s+/', ' ', $s) ?? ''),
                $matches[0]
            );

            sort($found);
            $selectors[$sheet] = $found;
        }

        $this->assertSame(
            $selectors['public/css/custom.css'],
            $selectors['public/css/rtl-custom.css'],
            'custom.css (LTR) and rtl-custom.css (Arabic) disagree about which anchor-button '
            . 'rules exist. They are mirrored duplicates, so a rule in one only reaches half the '
            . 'merchants.'
        );
    }

    public function test_the_region_holds_no_element_qualified_tag_selector_dependency(): void
    {
        /*
         * A control proving the swap took nothing away: every attribute the anchor carried,
         * except href, is still on the button.
         */
        foreach (self::EXPORTS as $relative) {
            $control = $this->exportControl($relative);

            $this->assertStringContainsString('ti-file-export', $control);
            $this->assertStringContainsString('title="', $control);
            $this->assertStringEndsWith('</button>', trim($control), sprintf(
                '%s export control is not closed as a button; a mismatched </a> would still '
                . 'parse as HTML and ship a broken region.',
                $relative
            ));
        }
    }

    /*
     * ---------------------------------------------------------------------------------------
     * HELPERS
     * ---------------------------------------------------------------------------------------
     */

    /**
     * A stylesheet WITH ITS COMMENTS STRIPPED, because the two selector scans below read CSS as
     * text and a comment is not a rule.
     *
     * MEASURED: `custom.css` acquired a block comment containing the words "an `a.btn` that DOES
     * need the lift" — prose explaining why one selector is written as it is — and the anchor
     * scan matched it, ran on to the next `{`, and reported a 900-character blob of English as an
     * unscoped rule. The guard was right to exist and was reading the wrong text. Stripping
     * comments is also what makes the scans HONEST in the other direction: a rule that is
     * commented out is not a rule, and should not satisfy or trip either check.
     */
    private function sheetWithoutComments(string $relative): string
    {
        $css = (string) file_get_contents(base_path($relative));

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** The `@section('action-button')` body, comments stripped. */
    private function actionRegion(string $relative): string
    {
        $code = $this->sourceWithoutComments($relative);

        $matched = preg_match("/@section\('action-button'\)(.*?)@endsection/s", $code, $m);

        $this->assertSame(1, $matched, $relative . ' has no action-button section.');

        return (string) $m[1];
    }

    /** Every `<a>`/`<button>` in the region, whole element. */
    private function controls(string $region): array
    {
        preg_match_all('#<(a|button)\b.*?</\1>#s', $region, $m);

        $this->assertNotEmpty($m[0], 'the action-button region holds no control at all');

        return $m[0];
    }

    /** The one control carrying the export class. */
    private function exportControl(string $relative): string
    {
        foreach ($this->controls($this->actionRegion($relative)) as $control) {
            if (str_contains($control, 'exportChartButton')) {
                return $control;
            }
        }

        $this->fail($relative . ' has no control carrying exportChartButton.');
    }
}
