<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * F152 — the dark theme's blanket paragraph colour, and the one selector that releases the
 * design system from it.
 * =================================================================================================
 *
 * `public/assets/css/style-dark.css` and `public/assets/css/rtl-style-dark.css` both carried,
 * identically:
 *
 *     .dash-sidebar .dash-link, .dash-sidebar.light-sidebar .dash-link i, .dataTable-info, p
 *     { color: #808191 !important; }
 *
 * The bare `p` hits EVERY paragraph in the dark admin, and `!important` means no component
 * stylesheet can win it back. That was established the hard way on P2-04-7's board card: changing
 * the design-system token did nothing, and RAISING THE SELECTOR'S SPECIFICITY also did nothing,
 * because specificity does not beat `!important`.
 *
 * MEASURED IMPACT, in a real browser in dark mode over ten merchant pages
 * (`docs/development/phase-2/tools/check-dark-paragraphs-live.cjs`):
 *
 *   before : 14 design-system paragraphs,  7 below 4.5:1   | 3 other paragraphs, 3 below 4.5:1
 *   after  : 14 design-system paragraphs,  0 below 4.5:1   | 3 other paragraphs, 3 below 4.5:1
 *
 * and the three "other" rows are byte-identical across the two runs -- same computed colour, same
 * ratio. That second column is the point: the fix had to release the design system WITHOUT moving
 * the host theme's own paragraphs, because changing those would restyle every screen in the admin.
 *
 * THE SEVEN THAT WERE FAILING were `ss-board__none` x2 at 3.85:1, `ss-section__note` at 3.42:1 and
 * `ss-stat__period` x4 at 3.85:1, every one of them computing to `rgb(128, 129, 145)` -- #808191,
 * the forced grey itself. One of them is a defect in my own earlier work: P2-04-7 reported "0
 * contrast failures" for the board, but the board it measured had CARDS, so the empty-column
 * message never rendered and was never measured.
 *
 * WHY `:not([class^="ss-"]):not([class*=" ss-"])` AND NOT `:not([class*="ss-"])`. The shorter form
 * matches any class attribute CONTAINING the substring, and `address-line` contains `ss-`. The two
 * selectors here match a real class TOKEN -- one at the start of the attribute, or one preceded by
 * a space -- and nothing else.
 *
 * THE THREE REMAINING FAILURES ARE NOT FIXED AND NOT HIDDEN. The host theme's own paragraphs on
 * the dashboard read 3.71:1. Releasing them too would mean changing what every paragraph in the
 * dark admin looks like, which is not "the narrowest safe change" and is not this row's to make.
 * Recorded as F158.
 */
class DarkParagraphContrastRuleTest extends TestCase
{
    /** @return string[] */
    private static function stylesheets(): array
    {
        return [
            'public/assets/css/style-dark.css',
            'public/assets/css/rtl-style-dark.css',
        ];
    }

    private function css(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_neither_dark_stylesheet_forces_a_colour_on_every_paragraph(): void
    {
        foreach (self::stylesheets() as $sheet) {
            $css = $this->css($sheet);

            // A bare `p` in a selector list that forces a colour is the defect. Matching the
            // SELECTOR rather than the whole rule, because the rule's other selectors are
            // legitimate and must stay.
            $this->assertDoesNotMatchRegularExpression(
                // A BARE `p`: one that is a whole selector, so preceded by a comma or a line
                // start -- never by a space. `.auth-footer p { color: #808191 !important }` is a
                // legitimate SCOPED rule and matching it was this assertion's first bug: it
                // failed against the correctly patched stylesheet.
                '/(^|,)\s*p\s*\{\s*color:\s*#808191\s*!important/mi',
                $css,
                "{$sheet} forces #808191 on every paragraph in the dark admin again. Any design-"
                . 'system component built on a <p> then loses its colour and cannot win it back, '
                . 'because !important beats specificity -- which is F152.'
            );
        }
    }

    public function test_both_dark_stylesheets_exempt_design_system_paragraphs(): void
    {
        foreach (self::stylesheets() as $sheet) {
            $this->assertStringContainsString(
                'p:not([class^="ss-"]):not([class*=" ss-"])',
                $this->css($sheet),
                "{$sheet} no longer exempts design-system paragraphs from the forced grey"
            );
        }
    }

    public function test_the_exemption_matches_a_class_token_rather_than_a_substring(): void
    {
        // `:not([class*="ss-"])` alone would also exempt `address-line`, which contains "ss-".
        foreach (self::stylesheets() as $sheet) {
            $css = $this->css($sheet);

            $this->assertDoesNotMatchRegularExpression(
                '/p:not\(\[class\*="ss-"\]\)(?!\))/',
                $css,
                "{$sheet} exempts paragraphs by SUBSTRING, so a class like `address-line` is "
                . 'exempted too; the exemption must match a class token'
            );
        }
    }

    public function test_the_rules_other_selectors_are_untouched(): void
    {
        // The change is one selector token. The sidebar link, its icon and the DataTables info
        // line still take the host theme's grey, and a diff that lost them would be a regression
        // in the host theme rather than a fix.
        foreach (self::stylesheets() as $sheet) {
            $css = $this->css($sheet);

            foreach (['.dash-sidebar .dash-link', '.dataTable-info'] as $selector) {
                $this->assertStringContainsString($selector, $css,
                    "{$sheet} lost `{$selector}` from the forced-colour rule");
            }
        }
    }
}
