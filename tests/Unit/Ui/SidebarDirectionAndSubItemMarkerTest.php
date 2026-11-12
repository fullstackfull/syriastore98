<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-PKG-A-09 — the Arabic sidebar pointed the wrong way, and marked its children with a spinner.
 * ================================================================================================
 *
 * THREE DEFECTS, ALL ON THE SAME NAVIGATION, ALL MEASURED ON /product IN FOUR COMBINATIONS.
 *
 * 1. THE DISCLOSURE ARROW POINTED THE WRONG WAY IN ARABIC — BOTH STATES.
 *
 *        state        EN / ltr                     AR / rtl
 *        collapsed    `>`  right, at the right     `>`  right, at the LEFT
 *        expanded     `v`  down                    `^`  UP
 *        transform    none / rotate(90deg)         none / rotate(-90deg)
 *
 *    ONE ROOT CAUSE: a half-applied mirror. The glyph is `ti-chevron-right`, and a font glyph
 *    never mirrors itself. Neither did the feather `<svg>` that preceded it — `style-rtl.css:16409`
 *    gives that svg a width and a height and nothing else, which is also the evidence that
 *    P2.5-PKG-A-02's swap from feather to Tabler did NOT introduce this. It was always so.
 *    The vendor mirrors the ROTATION instead (`style.css:16541` rotate(90deg) becomes
 *    `style-rtl.css:16498` rotate(-90deg)), which is only correct if the glyph was already
 *    flipped. So collapsed pointed INTO the label it sits beside, and an open menu in Arabic
 *    showed the glyph that everywhere else means "collapse".
 *
 *    THE RULE: collapsed follows the reading direction, expanded points DOWN in both. Only the
 *    RTL arm is restated, so the LTR rendering is untouched BY CONSTRUCTION — and the mutation
 *    run proves it: removing the fix produced 10 failures, all of them `ar_*`, none `en_*`.
 *
 * 2. THE SUB-ITEM MARKER WAS A FROZEN LOADING SPINNER. `custom.css:4030` draws an 8x8 circle with
 *    `border: 2px solid #ced4dc; border-right-color: var(--theme-color); border-radius: 50%` —
 *    literally the CSS spinner construction, standing still. The host theme's own copy
 *    (`style.css:16555`) is COMMENTED OUT, so the vendor had already withdrawn it.
 *
 * 3. AND IT RENDERED DIFFERENTLY IN THE TWO THEMES, which is how it was found. `style-dark.css:29471`
 *    sets `border-color: #808191 !important` — a shorthand, so it overrode all four sides including
 *    the accented one. Light showed a grey ring with a brand quarter; dark showed a flat grey ring.
 *    In dark mode the ACTIVE child's mark was therefore identical to its four inactive siblings,
 *    so the loudest decoration on the row carried no information at all — while P2.5-PKG-A-02's
 *    3px brand bar and brand text, right beside it, carried all of it.
 *
 * WHY `border-width` AND NOT `border-color`. The dark sheet's `!important` cannot be outranked on
 * colour without another `!important`. It does not need to be: at `border-width: 0` nothing paints
 * whatever the colour says, and the dot is drawn with `background`, which carries no `!important`
 * anywhere in the cascade.
 *
 * WHY `transform: scale()` AND NOT A NEW WIDTH. The marker is positioned `top: 7px; left: 10px`,
 * mirrored to `right` in RTL. Changing its size would move it off its own centre and need a
 * physical offset restated per direction — which this sheet forbids, and which is exactly how the
 * mobile-drawer defect of P2.5-PKG-A-02 happened (the LTR sheet was fixed and the Arabic page,
 * which loads `rtl-custom.css`, stayed broken). `scale()` is direction-free, and the rule already
 * carries `transition: 0.35s cubic-bezier(0.54, 1.6, 0.5, 1)`, so the active dot grows into place.
 *
 * THE DOT TAKES `--ss-brand` RATHER THAN A HUE. That token resolves to cyan in dark mode, which is
 * open owner decision #35. Naming the token rather than a colour means this marker follows whatever
 * the owner decides, instead of becoming a second place that has to be found and changed.
 *
 * MEASURED AFTER: 152 checks, 0 failed, across en/ar x light/dark. Each fix was then reverted on
 * its own and re-measured — 10, 2 and 44 failures respectively — so none of these assertions is
 * vacuous.
 */
class SidebarDirectionAndSubItemMarkerTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    public function test_the_rtl_collapsed_arrow_points_along_the_reading_direction(): void
    {
        $block = $this->declarations('P2.5-PKG-A-09 — THE NAV DISCLOSURE ARROW', 'P2.5-PKG-A-09');

        $this->assertMatchesRegularExpression(
            '/\[dir="rtl"\]\s*\[data-ss-admin\][^{]*\.dash-hasmenu\s*>\s*\.dash-link\s*>\s*'
            . '\.dash-arrow\s*\{\s*transform:\s*rotate\(180deg\)/s',
            $block,
            'A chevron-right with no transform points right in BOTH directions. In Arabic that '
            . 'points back into the label it sits beside. 180 degrees is the mirror.'
        );
    }

    public function test_the_expanded_arrow_points_down_in_arabic_too(): void
    {
        $block = $this->declarations('P2.5-PKG-A-09 — THE NAV DISCLOSURE ARROW', 'P2.5-PKG-A-09');

        $this->assertMatchesRegularExpression(
            '/\[dir="rtl"\]\s*\[data-ss-admin\][^{]*\.dash-hasmenu\.dash-trigger\s*>\s*\.dash-link'
            . '\s*>\s*\.dash-arrow\s*\{\s*transform:\s*rotate\(90deg\)/s',
            $block,
            "The vendor's rotate(-90deg) takes a right-pointing glyph to UP, not down, so an open "
            . 'menu in Arabic showed the glyph that elsewhere means "collapse".'
        );

        $this->assertSame(
            0,
            preg_match('/rotate\(-90deg\)/', $block),
            'rotate(-90deg) is the defect itself; it must not appear in the fix.'
        );
    }

    public function test_the_ltr_arrow_is_not_restated_because_it_was_already_right(): void
    {
        $block = $this->declarations('P2.5-PKG-A-09 — THE NAV DISCLOSURE ARROW', 'P2.5-PKG-A-09');

        // Every selector in this region is RTL-scoped. An unscoped one would change the English
        // rendering, which measured correct, and would need re-verifying on every merchant screen.
        foreach ($this->selectorsIn($block) as $selector) {
            $this->assertStringContainsString(
                '[dir="rtl"]',
                $selector,
                'This region answers an RTL-only defect. `' . $selector . '` is not RTL-scoped, so '
                . 'it would reach the English layout as well.'
            );
        }
    }

    public function test_the_sub_item_marker_paints_no_ring_and_is_a_filled_dot(): void
    {
        $block = $this->declarations('P2.5-PKG-A-09b — THE SUB-ITEM MARKER', 'P2.5-PKG-A-09b');

        $this->assertMatchesRegularExpression(
            '/\.dash-submenu\s+\.dash-item::before\s*\{[^}]*border-width:\s*0\s*;/s',
            $block,
            'The spinner read came from a 2px ring with one side in the accent colour. Any border '
            . 'width at all brings it back.'
        );

        $this->assertMatchesRegularExpression(
            '/\.dash-submenu\s+\.dash-item::before\s*\{[^}]*background:\s*var\(--ss-fg-subtle\)/s',
            $block
        );

        // border-COLOR must not be the lever: `style-dark.css:29471` carries an !important there,
        // so a colour fix would silently lose in dark mode -- which is the original defect.
        $this->assertSame(
            0,
            preg_match('/border-color\s*:/', $block),
            'border-color cannot win against the dark sheet\'s !important. border-width: 0 makes '
            . 'the colour irrelevant instead of fighting it.'
        );
    }

    public function test_the_active_child_is_marked_differently_from_its_siblings(): void
    {
        $block = $this->declarations('P2.5-PKG-A-09b — THE SUB-ITEM MARKER', 'P2.5-PKG-A-09b');

        $this->assertMatchesRegularExpression(
            '/li\.dash-item\.active::before\s*\{[^}]*background:\s*var\(--ss-brand\)/s',
            $block,
            'In dark mode the active child\'s mark was the same flat grey as its four siblings, '
            . 'because the dark sheet\'s !important flattened the accented side.'
        );

        // A hue here would become a second place to change when owner decision #35 is settled.
        $this->assertSame(
            0,
            preg_match('/#[0-9a-fA-F]{3,8}\b/', $block),
            'This region must name tokens, not colours, so the marker follows --ss-brand wherever '
            . 'the owner takes it.'
        );

        // Size differs too, so the state does not rest on colour alone (WCAG SC 1.4.1).
        preg_match_all('/transform:\s*scale\(([0-9.]+)\)/', $block, $scales);
        $this->assertCount(2, $scales[1], 'One scale for the quiet dot, one for the active dot.');
        $this->assertNotSame(
            $scales[1][0],
            $scales[1][1],
            'Colour alone would fail SC 1.4.1. The active dot is also larger.'
        );
    }

    public function test_neither_region_restates_a_physical_offset(): void
    {
        foreach ([
            ['P2.5-PKG-A-09 — THE NAV DISCLOSURE ARROW', 'P2.5-PKG-A-09'],
            ['P2.5-PKG-A-09b — THE SUB-ITEM MARKER', 'P2.5-PKG-A-09b'],
        ] as [$open, $close]) {
            $block = $this->declarations($open, $close);

            // DECLARATIONS ONLY. Both regions quote the host's `left: 10px` / `right: 10px` while
            // explaining why they do not touch it, and a search over the raw text would match that
            // explanation and report the thing it forbids. That has happened four times in this
            // phase, so `declarations()` strips comments before any of this runs.
            foreach (['left', 'right', 'inset-inline', 'margin-inline', 'padding-inline'] as $prop) {
                $this->assertSame(
                    0,
                    preg_match('/(?<![\w-])' . preg_quote($prop, '/') . '[\w-]*\s*:/', $block),
                    "`{$prop}` is mirrored per direction by the host. Restating it here is how the "
                    . 'mobile-drawer defect of P2.5-PKG-A-02 happened: one sheet was fixed and the '
                    . 'Arabic page, which loads rtl-custom.css, stayed broken.'
                );
            }
        }
    }

    public function test_the_arabic_breadcrumb_root_is_not_the_word_for_a_house(): void
    {
        $ar = json_decode($this->read('resources/lang/ar.json'), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode($this->read('resources/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);

        // `البيت` is a dwelling -- a house, or a line of verse. It is not the navigational root,
        // and this repository already knew the right register: it carries
        // "Home Page" => "الصفحة الرئيسية". The breadcrumb component supplies this item for ALL
        // 107 merchant screens (layouts/app.blade.php:660), so one word reached all of them.
        $this->assertSame('الرئيسية', $ar['Home'] ?? null);
        $this->assertNotSame('البيت', $ar['Home'] ?? null);
        $this->assertSame('Home', $en['Home'] ?? null, 'The English key is unchanged.');

        // The register the file already used elsewhere, left alone as the cross-check.
        $this->assertSame('الصفحة الرئيسية', $ar['Home Page'] ?? null);

        // Anti-vacuity: these are the real dictionaries, not empty arrays.
        $this->assertGreaterThan(3_000, count($ar));
        $this->assertGreaterThan(3_000, count($en));
    }

    public function test_both_regions_are_present_and_this_test_is_not_vacuous(): void
    {
        $css = $this->read(self::SHEET);
        $this->assertGreaterThan(200_000, strlen($css));

        foreach (['P2.5-PKG-A-09 — THE NAV DISCLOSURE ARROW', 'P2.5-PKG-A-09b — THE SUB-ITEM MARKER'] as $opener) {
            $this->assertSame(1, substr_count($css, $opener), $opener . ' must appear exactly once.');
        }

        // EACH REGION NEEDS ITS OWN CLOSE MARKER or it falls inside the previous wave's span and
        // `declarations()` returns the wrong bytes -- a mistake this phase has already made.
        $this->assertSame(1, substr_count($css, '/* end P2.5-PKG-A-09 */'));
        $this->assertSame(1, substr_count($css, '/* end P2.5-PKG-A-09b */'));

        $arrows = $this->declarations('P2.5-PKG-A-09 — THE NAV DISCLOSURE ARROW', 'P2.5-PKG-A-09');
        $marker = $this->declarations('P2.5-PKG-A-09b — THE SUB-ITEM MARKER', 'P2.5-PKG-A-09b');
        $this->assertSame(2, substr_count($arrows, '{'), 'Two arrow rules: collapsed and expanded.');
        $this->assertSame(2, substr_count($marker, '{'), 'Two marker rules: quiet and active.');

        // Every selector carries the design system's own hook, which is the sheet's standing rule.
        foreach (array_merge($this->selectorsIn($arrows), $this->selectorsIn($marker)) as $selector) {
            $this->assertStringContainsString('data-ss-admin', $selector, $selector);
        }
    }

    /** @return list<string> */
    private function selectorsIn(string $declarations): array
    {
        preg_match_all('/(^|\})\s*([^{}@]+)\{/s', $declarations, $m);

        return array_values(array_filter(array_map(
            static fn (string $s): string => trim(preg_replace('/\s+/', ' ', $s)),
            $m[2]
        )));
    }

    /** The region's declarations, with comments stripped: its prose quotes the rules it answers. */
    private function declarations(string $opener, string $closeTag): string
    {
        $css = $this->read(self::SHEET);

        $open = strpos($css, $opener);
        $this->assertNotFalse($open, "The {$opener} region opener is missing.");

        $end = strpos($css, "/* end {$closeTag} */", (int) $open);
        $this->assertNotFalse($end, "The {$closeTag} close marker is missing.");

        $block = substr($css, (int) $open, (int) $end - (int) $open);

        // drop the opening banner comment, whose `*/` closes before any rule begins
        $firstClose = strpos($block, '*/');
        $firstOpen = strpos($block, '/*');
        if ($firstClose !== false && ($firstOpen === false || $firstOpen > $firstClose)) {
            $block = substr($block, $firstClose + 2);
        }

        return trim((string) preg_replace('#/\*.*?\*/#s', '', $block));
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
