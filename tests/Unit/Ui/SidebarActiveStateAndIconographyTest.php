<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-PKG-A-02 — the sidebar's active state, the zone caption's rank, and the shell's last
 * feather icons.
 * ================================================================================================
 *
 * THE DEFECT, MEASURED on the running /product page with CSS.getMatchedStylesForNode, against the
 * active sub-item's OWN inactive siblings rather than against a surface:
 *
 *                       active             inactive           ratio
 *     light             #335da8            #333333            1.97:1
 *     dark              #9da3b4            #9da3b4            1.00:1   <- THE SAME COLOUR
 *
 * In dark mode the selected item was not faint, it was INDISTINGUISHABLE. The cause is a cascade
 * collision: the host's dark arm
 *
 *     html[data-ss-theme="dark"] body .dash-sidebar .dash-link { color: #9DA3B4 !important }
 *
 * carries `!important`, and the host's own active rule
 *
 *     .dash-sidebar .dash-item.active > .dash-link { color: #fff }
 *
 * does not, so the theme cancelled the state. That is why the repair's colour declaration carries
 * `!important` and why this test pins it: without it the rule loses and the screen silently
 * returns to 1.00:1, which no contrast sweep over a page would flag, because every individual
 * pairing against the surface still passes.
 *
 * WHY THE INDICATOR IS A SHAPE AND NOT A COLOUR. The brightest colour available in dark is
 * --ss-fg (#e8eaed), which against the inactive grey measures 2.09:1 -- under the 3:1 SC 1.4.11
 * asks of a non-text indicator. In light the brand against the inactive ink is the 1.97:1 above.
 * No colour pair reachable here clears 3:1 against its own siblings, and a colour-only indicator
 * would be SC 1.4.1 regardless. So the state is carried by a 3px inline-start marker bar, which
 * measures 6.42:1 (light, #335da8 on #ffffff) and 6.81:1 (dark, #2ac3e5 on #292a33) against the
 * surface it is drawn on. Cyan was rejected for light: it is 2.09:1 on white.
 *
 * THE TWO RADIUS DECLARATIONS REPAIR THE BAR, they are not decoration. The link has
 * `border-radius: 6px` and is 26px tall, so a border down its inline-start edge curves over 6 of
 * every 26px at each end and renders as a CRESCENT. The first version of this rule shipped that
 * crescent; the captured screenshot is what caught it, not review. At 2px only 4 of 26 curve.
 *
 * THE ZONE CAPTION OUT-RANKED THE ITEMS IT LABELLED: pure #ffffff, 14.25:1, over items at 5.66:1.
 * --ss-fg-subtle, not --ss-fg-muted, and that is the `.ss-navgroup` comment's own reasoning
 * applied to a surface it did not cover: that comment rejects --ss-fg-subtle because in LIGHT it
 * measures 4.46:1 on #f8f9fd, under AA by 0.04. On the DARK sidebar #292a33 it measures 4.98:1 --
 * it passes AA for a 12px label AND sits below the 5.66:1 items, which is the point. --ss-fg-muted
 * would be 6.18:1 there, brighter than the items, so it would not restore the rank. The light arm
 * is untouched.
 *
 * THE MAGENTA GLOW. The active nav item cast `0 5px 7px -1px rgba(146, 44, 136, 0.3)` -- a
 * hard-coded magenta belonging to the WorkDo demo theme, nothing in Store Syria's identity. It was
 * found by scanning the captured screenshot pixel by pixel, not by reading CSS. It was NOT
 * conditional: `layouts/app.blade.php:43-50` sets `$themeColor` to `custom-color` in every branch,
 * and the rendered body class measured `custom-color`, so every merchant saw it on every page in
 * both themes. Replaced with --ss-shadow-md, which is theme-aware.
 *
 * `settingPopup.blade.php` WAS DELETED. 62 lines of Bootstrap modal rendered into every merchant
 * page, carrying `id="notification-modal"`, which nothing anywhere opened -- a repository-wide
 * search returns only CSS rules for the class. Its five switches had no `name`, no `<form>` and no
 * action; its "Save changes" button had no handler. Its copy was untranslated and broken in
 * English as shipped. `OrderDetailControlNamesTest` had to scope its census around it and said so.
 * No function, data field, permission or workflow was lost, because it had none.
 *
 * WHAT THIS TEST CANNOT SEE, stated as a limit. It reads files. It cannot know that the browser
 * resolves these selectors, that the marker lands on the right edge in Arabic, or that the glyph
 * has a codepoint. Those were measured in Chromium in all four combinations -- 64 checks, 0
 * failed -- and each rule below was mutated one at a time and seen to redden a named check in that
 * harness. This test exists so a later edit that drops one of them reddens CI, where no browser
 * runs.
 */
class SidebarActiveStateAndIconographyTest extends TestCase
{
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const MENU_TREE = 'resources/views/partision/menu-tree.blade.php';
    private const SHELL = 'resources/views/layouts/app.blade.php';
    private const DEAD_MODAL = 'resources/views/partision/settingPopup.blade.php';

    /** The measured host padding the marker is subtracted from: 32 + 3 = 35. */
    private const HOST_SUBMENU_PADDING_PX = 35;
    private const MARKER_PX = 3;

    public function test_the_active_sub_item_carries_a_shape_cue_not_only_a_colour(): void
    {
        $block = $this->declarations();

        $this->assertStringContainsString(
            '[data-ss-admin] .dash-submenu > li.dash-item.active > a.dash-link',
            $block,
            'The active sub-item rule is gone. In dark mode that returns the screen to 1.00:1 — '
            . 'the selected item becoming the same colour as every item beside it.'
        );

        $this->assertStringContainsString(
            'border-inline-start: ' . self::MARKER_PX . 'px solid var(--ss-brand)',
            $block,
            'The marker bar is the only cue that clears SC 1.4.11 here: colour alone measures '
            . '1.97:1 (light) and 2.09:1 (dark) against the sibling items.'
        );

        $this->assertStringContainsString('font-weight: var(--ss-weight-semibold)', $block);

        // The padding arithmetic, asserted rather than trusted: the bar is carved OUT of the
        // host's 35px, so the label does not move between states. Measured shift: 0.0px.
        $this->assertStringContainsString(
            'padding-inline-start: ' . (self::HOST_SUBMENU_PADDING_PX - self::MARKER_PX) . 'px',
            $block,
            'The padding must absorb the marker width, or every active label jumps sideways.'
        );

        // Both inline-start corners, or the 6px link radius bends the bar into a crescent.
        $this->assertStringContainsString('border-start-start-radius', $block);
        $this->assertStringContainsString('border-end-start-radius', $block);
    }

    public function test_the_dark_colour_override_keeps_the_important_that_answers_the_host(): void
    {
        $block = $this->declarations();

        $this->assertMatchesRegularExpression(
            '/\[data-ss-theme="dark"\]\s*\[data-ss-admin\]\s*\.dash-submenu\s*>\s*li\.dash-item\.active'
            . '\s*>\s*a\.dash-link\s*\{[^}]*color:\s*var\(--ss-fg\)\s*!important/s',
            $block,
            'Without `!important` the host\'s `color: #9DA3B4 !important` wins and the active '
            . 'sub-item is the same colour as its siblings again. Mutating this exact token '
            . 'reddened "active text is not identical to inactive" in the browser harness.'
        );
    }

    public function test_the_dark_zone_caption_stops_out_ranking_the_items_it_labels(): void
    {
        $block = $this->declarations();

        $this->assertMatchesRegularExpression(
            '/\[data-ss-theme="dark"\]\s*\.ss-navgroup\s*\{[^}]*color:\s*var\(--ss-fg-subtle\)\s*!important/s',
            $block,
            'The caption must be --ss-fg-subtle (4.98:1 on the dark sidebar, below the items\' '
            . '5.66:1), and must carry `!important` to answer `h1..h6 { color: var(--bs-white) '
            . '!important }`. --ss-fg-muted would be 6.18:1 — brighter than the items, so it '
            . 'would not restore the rank.'
        );

        $this->assertStringNotContainsString(
            '[data-ss-theme="dark"] .ss-navgroup {' . PHP_EOL . '    color: var(--ss-fg-muted)',
            $block,
            'The dark caption must not fall back to --ss-fg-muted.'
        );
    }

    public function test_the_active_nav_item_carries_an_ink_shadow_not_the_demo_magenta(): void
    {
        $block = $this->declarations();

        $this->assertMatchesRegularExpression(
            '/\[data-ss-admin\]\s*\.dash-sidebar\.light-sidebar\s*\.dash-navbar\s*>\s*li\.dash-item\.active'
            . '\s*>\s*a\.dash-link\s*\{[^}]*box-shadow:\s*var\(--ss-shadow-md\)/s',
            $block,
            '`.light-sidebar` is load-bearing: it carries the selector to (0,7,2) against the '
            . 'host\'s (0,7,1), which is how this wins without `!important`. Dropping it lets '
            . '`rgba(146, 44, 136, 0.3)` back under the one item the merchant is on.'
        );

        $this->assertStringNotContainsString('146, 44, 136', $block);
    }

    public function test_the_light_mobile_drawer_is_legible(): void
    {
        $block = $this->declarations();

        $this->assertMatchesRegularExpression(
            '/html:not\(\[data-ss-theme="dark"\]\)\s*\[data-ss-admin\]\s*\.dash-sidebar\s*'
            . '\.dash-item:not\(\.active\)\s*>\s*a\.dash-link\s*\{[^}]*color:\s*var\(--ss-fg\)/s',
            $block,
            'The host paints every nav link #c7ccd4 below 1025px — a grey written for a dark '
            . 'drawer — and the drawer is white at every width, so the whole navigation list '
            . 'measured 1.61:1. Without this rule a merchant on a phone cannot read their menu.'
        );

        // `:not(.active)` is load-bearing: without it the rule is specific enough to beat the
        // host's `.dash-item.active > .dash-link { color: #fff }` and would put near-black text
        // on the active item's blue fill.
        $this->assertStringContainsString('.dash-item:not(.active)', $block);
    }

    public function test_the_stranded_dark_tokens_are_gone_from_both_direction_stylesheets(): void
    {
        // BOTH, and that is the whole point of this test. The first attempt removed the block
        // from custom.css only; the Arabic page loads rtl-custom.css instead, so the English
        // phone drawer measured 16.88:1 while the Arabic one still measured 1.18:1 — #e8eded
        // on white. The pair has to be asserted together or a half-fix reads as a fix.
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $raw = $this->read($sheet);

            // DECLARATIONS ONLY. These files explain, at length, the defect they used to carry,
            // quoting the hexes verbatim — so a search over the raw text matches the commentary
            // describing the problem and reports the problem as present. That is the third time
            // in this phase a detector has matched its own author's prose; stripping comments
            // first is the fix, rewording the prose is not.
            $css = (string) preg_replace('#/\*.*?\*/#s', '', $raw);

            $this->assertDoesNotMatchRegularExpression(
                '/@media\s*\(max-width:\s*1024px\)\s*\{\s*\.dash-sidebar\s*\{[^}]*--ss-fg:\s*#e8eded/s',
                $css,
                $sheet . ' still gives the sidebar dark token values below 1025px. The drawer is '
                . 'WHITE there — `.dash-sidebar.light-sidebar { background: #fff }` beats the '
                . 'host\'s `#1c232f`, and sidebar.blade.php writes both classes unconditionally — '
                . 'so those tokens strand dark text on a white surface.'
            );

            // THE SECOND BLOCK, and the one that actually painted the words. Removing only the
            // token block left `a.dash-link` legible while `<span class="dash-mtext">` — which a
            // separate rule coloured DIRECTLY — stayed at #c7ccd4. The browser reported the fix
            // as working and the screenshot showed grey text, so both are asserted here.
            $this->assertStringNotContainsString(
                'color: #c7ccd4',
                $css,
                $sheet . ' still paints the mobile nav #c7ccd4. That grey was chosen to sit on '
                . "the host's dark drawer #1c232f; the drawer is white, so it measures 1.61:1."
            );

            $this->assertStringNotContainsString(
                'color: #e9ecf1',
                $css,
                $sheet . ' still paints the sidebar search field near-white, which is about '
                . '1.1:1 on the white drawer a merchant actually sees.'
            );

            // Anti-vacuity: prove the file was read, is the real one, and that stripping
            // comments did not leave an empty string to search.
            $this->assertStringContainsString('.dash-sidebar', $css);
            $this->assertGreaterThan(100_000, strlen($css));
            $this->assertGreaterThan(50_000, strlen($raw) - strlen($css), 'the comments did strip');
        }
    }

    public function test_every_rule_in_the_block_is_scoped_and_direction_neutral(): void
    {
        $block = $this->declarations();
        $selectors = $this->selectors($block);

        $this->assertNotEmpty($selectors, 'The block has no rules at all — the region markers moved.');

        foreach ($selectors as $selector) {
            $this->assertMatchesRegularExpression(
                '/\[data-ss-(admin|theme)/',
                $selector,
                "Every rule must hang on a `data-ss-` attribute, both for "
                . "DesignSystemStylesheetScopeTest and so it cannot leak onto the storefront: "
                . "{$selector}"
            );
        }

        // `data-ss-theme` is ABSENT in light mode — measured `null` on the rendered page — so a
        // rule hung on it alone never applies to half the states this slice repairs. The two
        // theme-scoped rules here are deliberately dark-only; everything else must reach both.
        $bothThemes = array_values(array_filter(
            $selectors,
            static fn (string $s): bool => ! str_contains($s, 'data-ss-theme')
        ));

        $this->assertNotEmpty(
            $bothThemes,
            'Every rule became dark-only. Light mode fails too (1.97:1), and `data-ss-theme` '
            . 'reads null there, so a dark-only block silently repairs nothing in light.'
        );

        foreach (['border-left', 'border-right', 'padding-left', 'padding-right', 'margin-left', 'margin-right'] as $physical) {
            $this->assertStringNotContainsString(
                $physical . ':',
                $block,
                "`{$physical}` would pin the marker to one side and break Arabic, where the "
                . 'marker must sit on the right. The logical property flips on its own; this was '
                . 'verified rendered in rtl, not assumed from the property name.'
            );
        }
    }

    public function test_the_sidebar_chevron_is_tabler_and_no_feather_remains_in_the_shell(): void
    {
        $menu = $this->withoutBladeComments($this->read(self::MENU_TREE));

        $this->assertStringContainsString(
            '<span class="dash-arrow"><i class="ti ti-chevron-right"></i></span>',
            $menu,
            'The chevron must be a Tabler glyph, like every other icon in this sidebar.'
        );

        $this->assertStringNotContainsString('data-feather', $menu);

        // The span itself is untouched on purpose: the host rotates the SPAN, not the icon inside
        // it, to show an open menu. Renaming or wrapping it would lose that affordance silently.
        $this->assertStringContainsString('class="dash-arrow"', $menu);

        foreach ($this->shellPartials() as $partial) {
            $this->assertStringNotContainsString(
                'data-feather',
                $this->withoutBladeComments((string) file_get_contents($partial)),
                'A feather icon came back into the shell at ' . $partial . '. The sidebar is '
                . 'Tabler; two icon families in one chrome is the inconsistency Wave 4 closed.'
            );
        }
    }

    public function test_the_dead_settings_modal_is_gone_from_every_merchant_page(): void
    {
        $this->assertFileDoesNotExist(
            $this->path(self::DEAD_MODAL),
            'The unreachable settings modal is back. Nothing opens `#notification-modal`; its '
            . 'switches have no name, no form and no action; its copy is untranslated English.'
        );

        $shell = $this->withoutBladeComments($this->read(self::SHELL));

        $this->assertStringNotContainsString('partision.settingPopup', $shell);
        $this->assertStringNotContainsString('settingPopup', $shell);

        // Anti-vacuity: prove we are reading the real shell and not an empty string.
        $this->assertStringContainsString('data-ss-admin', $shell);
        $this->assertStringContainsString("@include('partision.sidebar')", $shell);
    }

    public function test_the_block_is_actually_present_and_this_test_is_not_vacuous(): void
    {
        $css = $this->read(self::DS_SHEET);

        $this->assertGreaterThan(
            200_000,
            strlen($css),
            'The design system stylesheet is far smaller than measured — every assertion above '
            . 'would pass vacuously against a truncated file.'
        );

        // Twice, and both are load-bearing: the header the region is located by, and the close
        // marker it is bounded by. A block without its own close marker falls inside whichever
        // region was opened before it, which is how a previous wave's rules ended up inside an
        // earlier wave's span and were asserted by the wrong test.
        $this->assertSame(1, substr_count($css, 'P2.5-PKG-A-02 — THE SIDEBAR'), 'The region opener must appear once.');
        $this->assertSame(1, substr_count($css, '/* end P2.5-PKG-A-02 */'));

        $block = $this->declarations();

        $this->assertGreaterThan(
            300,
            strlen($block),
            'The block has shrunk to almost nothing once its comments are stripped.'
        );

        // Six rules: the glyph, the marker, the dark colour, the dark caption, the shadow, and
        // the light nav colour. A count rather than a list, so a rule deleted wholesale is
        // caught even if no individual assertion above happened to name it.
        $this->assertCount(6, $this->selectors($block));
    }

    /**
     * The block's DECLARATIONS, with every CSS comment removed first.
     *
     * The stripping is not tidiness. This block's own prose quotes the host rules it answers,
     * `rgba(146, 44, 136, 0.3)` among them, so an assertion run over the raw text would match the
     * commentary describing a defect and report the defect as present. That exact mistake — a
     * detector matching its author's own comment — has already cost this phase one false reading.
     */
    private function declarations(): string
    {
        $css = $this->read(self::DS_SHEET);

        $open = strpos($css, 'P2.5-PKG-A-02 — THE SIDEBAR');
        $this->assertNotFalse($open, 'The P2.5-PKG-A-02 region opener is missing from the stylesheet.');

        $end = strpos($css, '/* end P2.5-PKG-A-02 */', (int) $open);
        $this->assertNotFalse($end, 'The P2.5-PKG-A-02 close marker is missing.');

        $block = substr($css, (int) $open, (int) $end - (int) $open);

        // The region opener sits INSIDE the header comment, so the substring begins mid-comment
        // and its first `*/` closes something that was never opened here. Drop that leading
        // fragment before stripping the rest, or the header's prose — which quotes the host rules
        // this block answers, braces and all — survives and is read as though it were CSS.
        $firstClose = strpos($block, '*/');
        $firstOpen = strpos($block, '/*');

        if ($firstClose !== false && ($firstOpen === false || $firstOpen > $firstClose)) {
            $block = substr($block, $firstClose + 2);
        }

        return trim((string) preg_replace('#/\*.*?\*/#s', '', $block));
    }

    /** @return list<string> every selector list in the block, one per rule. */
    private function selectors(string $block): array
    {
        preg_match_all('/([^{}]+)\{[^}]*\}/s', $block, $m);

        return array_values(array_filter(array_map(
            static fn (string $s): string => trim(preg_replace('/\s+/', ' ', $s) ?? ''),
            $m[1] ?? []
        )));
    }

    /** @return list<string> absolute paths of the shell partials. */
    private function shellPartials(): array
    {
        $dir = $this->path('resources/views/partision');
        $out = [];

        foreach ((array) glob($dir . '/*.blade.php') as $file) {
            if (is_string($file)) {
                $out[] = $file;
            }
        }

        sort($out);

        $this->assertNotEmpty($out, 'No shell partials found — the feather sweep would be vacuous.');

        return $out;
    }

    private function withoutBladeComments(string $blade): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . ($relative === '' ? '' : '/' . $relative);
    }

    private function read(string $relative): string
    {
        $path = $this->path($relative);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
