<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * WS A — THE DESIGN SYSTEM'S BASE LAYER REACHES THE SHARED MERCHANT SHELL.
 * ========================================================================
 *
 * A recount of the Master Execution Prompt against the code found this as Workstream A's single
 * biggest gap, and it was a gap of REACH rather than of content: the design system was built, and
 * on roughly 140 of ~150 first-party merchant screens the only part of it a merchant actually saw
 * was buttons and form controls.
 *
 * `.ss-scope` is the sole rule that sets the family, size, leading, base colour and the Arabic
 * leading adjustment (`public/css/store-syria-ds.css:403-423`). It was present in 10 views and in
 * **0 of the 25 components** — so a `<x-ss.card>` dropped onto a host-themed page inherited the
 * host's typography and the DS's tokens disagreed with what was around them.
 *
 * Four things are pinned here, each of which was wrong on every merchant screen:
 *
 * | | was | now |
 * |---|---|---|
 * | the base layer | `.ss-scope` on no component root | on the five that carry text |
 * | `dir` | `dir=""` for English | `dir="ltr"` |
 * | the page heading | `<h2 …>…</h4>` | closed with `</h2>` |
 * | a skip link | in the stylesheet, rendered nowhere | first focusable element |
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A SOURCE TEST AND NOT A RENDERED ONE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Stated plainly because it is the honest limit of this file: these are assertions about the
 * TEMPLATES, not about a rendered page. The rendered half of Workstream A — contrast ratios, focus
 * rings, tab order, RTL geometry, overflow at eight widths — is covered by **no CI gate at all**.
 * `.github/workflows/ci.yml:627` keeps the browser suite out of CI by decision, and
 * `tests/Feature/DesignSystem/PilotScreenContractTest.php:24-26` says so in terms.
 *
 * So the 100-check RTL × theme × viewport evidence in `docs/development/phase-2/` is a
 * point-in-time artifact rather than a gate, and a direction, theme or keyboard regression would
 * ship green. A source ratchet does not close that; it closes the narrower question of whether the
 * shell still carries the four things above, which is what actually regressed here.
 */
class MerchantShellDesignSystemTest extends TestCase
{
    private const LAYOUT = __DIR__ . '/../../../resources/views/layouts/app.blade.php';

    /** The components that render text and therefore need the base layer. */
    private const SCOPED_COMPONENTS = [
        'page-header.blade.php',
        'card.blade.php',
        'empty.blade.php',
        'breadcrumb.blade.php',
        'error-summary.blade.php',
    ];

    private function layout(): string
    {
        $source = file_get_contents(self::LAYOUT);

        $this->assertNotFalse($source, 'the merchant layout could not be read');
        $this->assertGreaterThan(
            200,
            substr_count($source, "\n"),
            'the layout came back far too short, so every assertion below would be vacuous'
        );

        return $source;
    }

    /**
     * THE BASE LAYER IS ON THE COMPONENT ROOTS.
     *
     * `breadcrumb` matters most of the five: the layout renders it on every screen except the
     * dashboard, so scoping it alone carries the base layer to ~107 screens that had none.
     */
    public function test_every_text_bearing_component_carries_the_base_layer(): void
    {
        foreach (self::SCOPED_COMPONENTS as $component) {
            $path = __DIR__ . '/../../../resources/views/components/ss/' . $component;
            $source = file_get_contents($path);

            $this->assertNotFalse($source, $component . ' could not be read');
            $this->assertStringContainsString(
                'ss-scope',
                $source,
                $component . ' no longer carries `ss-scope` on its root. That rule is the ONLY '
                . 'place the design system sets font-family, size, leading, base colour and the '
                . 'Arabic leading adjustment -- without it the component inherits the host '
                . 'theme\'s typography and its own tokens disagree with the text around them.'
            );
        }
    }

    /**
     * `dir` IS ALWAYS A REAL VALUE. It was the empty string for English.
     *
     * `dir=""` is not invalid, but it is also not `ltr`: it makes the element inherit, which on
     * `<html>` means the UA default. It happened to be right, and it meant the one attribute the
     * whole RTL/LTR requirement turns on was never actually asserted for English.
     */
    public function test_the_document_direction_is_never_empty(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString(
            "'rtl' : 'ltr'",
            $layout,
            'the `dir` attribute no longer resolves to a real value in both branches. It used to '
            . 'emit `dir=""` for English, so the attribute the entire RTL/LTR requirement turns on '
            . 'was unset on every English screen.'
        );

        $this->assertStringNotContainsString(
            "'rtl' : '' }}\"",
            $layout,
            'the empty-string branch is back'
        );
    }

    /**
     * THE PAGE HEADING IS WELL-FORMED. `<h2>` was closed with `</h4>`.
     *
     * Browsers recover, so nothing broke visibly -- which is why it survived. The DOM a screen
     * reader walks, and any heading-order audit, saw a malformed tree on every merchant screen.
     */
    public function test_the_page_heading_is_closed_with_its_own_tag(): void
    {
        $layout = $this->layout();

        /*
         * THE MISMATCHED PAIR, not the presence of `</h4>` anywhere -- which is what the first
         * version of this assertion tested, and it failed on two perfectly well-formed
         * `<h4 class="modal-title"></h4>` elements and on this file's own comment. A file-scoped
         * string search answers a different question from the one asked; the project has recorded
         * that same mistake four times, so it is worth not making a fifth.
         */
        $this->assertSame(
            0,
            preg_match_all('/<h2\\b[^>]*>(?:(?!<\\/h[1-6]>).)*<\\/h4>/s', $layout),
            'an `<h2>` is closed with `</h4>` in the merchant layout again. Browsers recover, so '
            . 'nothing breaks visibly -- which is exactly how it survived -- but the heading tree '
            . 'a screen reader and any heading-order audit walk is malformed on every screen.'
        );

        $this->assertMatchesRegularExpression(
            '/<h2[^>]*>@yield\(\'page-title\'\)<\/h2>/',
            $layout,
            'the page title is no longer inside a well-formed h2'
        );
    }

    /**
     * THE SKIP LINK EXISTS, PRECEDES BOTH CHROME INCLUDES, AND HAS SOMEWHERE TO GO.
     *
     * THIS DOCBLOCK AND THE LAST ASSERTION USED TO DISAGREE, and the disagreement is worth
     * recording rather than quietly fixing. The docblock said "a skip link after the sidebar skips
     * nothing" — correctly — while the only ordering assertion compared the link against
     * `class="dash-container"`, which is true whichever side of the chrome the link is emitted on.
     * So this test was GREEN for weeks while the link was emitted at app.blade.php:484, after
     * `@include('partision.sidebar')` (:467) and `@include('partision.header')` (:469): a keyboard
     * user tabbed the brand link, the workspace chip, the sidebar search, the rail toggle, every
     * entitlement-gated nav anchor and the header's 25 focusables before reaching the link whose
     * job was to skip them. A test whose NAME certifies the property it does not assert is this
     * project's documented failure mode, so the assertion below now reads the chrome includes.
     *
     * The `dash-container` comparison is KEPT, not replaced — it is still true and still cheap —
     * but it is no longer what guards the ordering.
     *
     * Four things are pinned, because each is separately easy to get wrong: the link must exist, it
     * must precede BOTH chrome includes (the minimum of the two offsets, so the guard survives the
     * includes being reordered), it must NOT be hoisted above the two inline first-in-body scripts,
     * and it must point at an element carrying `tabindex="-1"` — without which following the link
     * scrolls without moving focus and the next Tab returns to the top of the page.
     */
    public function test_the_skip_link_is_rendered_first_and_has_a_focusable_target(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString(
            'class="ss-skip-link"',
            $layout,
            '`.ss-skip-link` has existed in the stylesheet since the beginning and was rendered by '
            . 'nothing. Without it a keyboard user tabs the entire sidebar before reaching the '
            . 'content, on every merchant screen.'
        );

        $this->assertStringContainsString('href="#ss-main"', $layout, 'the skip link points nowhere');
        $this->assertStringContainsString(
            'id="ss-main" tabindex="-1"',
            $layout,
            'the skip target has no `tabindex="-1"`, so following the link scrolls the page '
            . 'without moving focus -- and the next Tab returns to the top'
        );

        $linkAt = strpos($layout, 'class="ss-skip-link"');

        /* It precedes the content container. True on both sides of the chrome, so this is a
           sanity check and NOT the ordering guard -- see the docblock. */
        $this->assertLessThan(
            strpos($layout, 'class="dash-container"'),
            $linkAt,
            'the skip link is rendered after the content container'
        );

        /* THE ORDERING GUARD. There is no positive `tabindex` anywhere in the layout or the three
           chrome partials, so tab order IS dom order: a link emitted after either include is
           reached only after everything that include renders. The MINIMUM of the two offsets, so
           the guard still holds if the sidebar and header includes are ever swapped. */
        $sidebarAt = strpos($layout, "@include('partision.sidebar')");
        $headerAt = strpos($layout, "@include('partision.header')");

        $this->assertNotFalse($sidebarAt, 'premise: the layout includes the sidebar partial');
        $this->assertNotFalse($headerAt, 'premise: the layout includes the header partial');

        $this->assertLessThan(
            min($sidebarAt, $headerAt),
            $linkAt,
            'the skip link is emitted AFTER the sidebar and/or header include, so a keyboard user '
            . 'tabs the whole of the chrome it exists to skip before they can reach it. Tab order '
            . 'is dom order here (no positive tabindex in the layout or the chrome partials), so '
            . 'the markup position is the whole of the behaviour.'
        );

        /* NEGATIVE CONTROL -- AND NOT HOISTED TOO FAR. The two inline scripts at the top of
           <body> must stay first-in-body: the rail-restore script adds `.minimenu` BEFORE paint,
           and that is the documented reason it is inline rather than in store-syria-nav.js. A
           deferred version makes the merchant watch the full-width sidebar snap to the collapsed
           rail on every page load. So the requirement is "before the chrome", not "first child of
           <body>", and this asserts the weaker, correct thing. */
        $this->assertGreaterThan(
            strpos($layout, "classList.add('minimenu')"),
            $linkAt,
            'the skip link was hoisted above the inline rail-restore script. That script must stay '
            . 'first-in-body or the sidebar paints full-width and snaps to the rail on every load.'
        );
    }

    /**
     * AND IT IS PAINTED IN FRONT OF THE CHROME, not behind it.
     *
     * THE SECOND, INDEPENDENT HALF OF THE SAME DEFECT, and moving the markup alone would have made
     * it worse. `.ss-skip-link` is `position: absolute` in the root stacking context (`body` has
     * `margin: 0` and is not a stacking context), and the merchant chrome is `position: fixed` in
     * the same context at z-index 1026 (`.dash-sidebar`, `.dash-mob-header`) and 1025
     * (`.dash-header`). At `--ss-z-sticky` (1020) the focused link landed INSIDE the sidebar's
     * opaque #1c232f column -- in LTR, and in RTL too, because `inset-inline-start` mirrors exactly
     * as the fixed sidebar does. So before this repair the first Tab focused a visible brand link;
     * a markup-only move would have made the first Tab focus an element painted behind an opaque
     * panel, turning a WCAG 2.4.1 failure into a 2.4.1 pass plus a 2.4.7/2.4.11 failure.
     *
     * READ THIS NEXT SENTENCE BEFORE TRUSTING THIS TEST. What it asserts is an ARITHMETIC RELATION
     * standing in for a visual fact. It proves 1030 > 1026, not that a keyboard user can see a
     * focus ring. Only a rendered page and a real Tab press can prove the latter, and nothing in
     * tests/Unit/Ui/ renders anything. The relation is still worth ratcheting, because it is the
     * relation a vendored theme bump would silently break.
     *
     * THE MAXIMUM OF THE CHROME, read from each sheet, not `.dash-sidebar` alone: below 1025px the
     * sidebar is parked at -255px and is not the occluder at all -- `.dash-mob-header` is, full
     * width at the same 1026 in both directions. A ratchet reading only the sidebar would stay
     * green while a theme bump raised the mobile bar.
     */
    public function test_the_skip_link_is_painted_above_the_merchant_chrome(): void
    {
        $ds = $this->designSystem();

        $skipLink = $this->tokenValue($ds, '--ss-z-skip-link');

        $this->assertNotNull(
            $skipLink,
            'store-syria-ds.css has no `--ss-z-skip-link` token in its `:root` block. The skip '
            . 'link cannot ride on `--ss-z-sticky` (1020): the chrome it must clear is at 1026, so '
            . 'the focused link paints behind an opaque panel and a keyboard user sees no focus '
            . 'indicator at all.'
        );

        $this->assertMatchesRegularExpression(
            '/\.ss-skip-link\s*\{[^}]*z-index:\s*var\(--ss-z-skip-link\)/s',
            $ds,
            'the `.ss-skip-link` rule does not consume `--ss-z-skip-link`'
        );

        foreach (self::THEMES as $theme) {
            $css = (string) file_get_contents(__DIR__ . '/../../../' . $theme);
            $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

            $highest = 0;
            $highestSelector = '';

            foreach (['.dash-sidebar', '.dash-mob-header', '.dash-header'] as $selector) {
                preg_match_all(
                    '/(?m)^\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/',
                    $css,
                    $blocks
                );

                foreach ($blocks[1] as $body) {
                    if (preg_match('/z-index:\s*(\d+)/', $body, $z) === 1 && (int) $z[1] > $highest) {
                        $highest = (int) $z[1];
                        $highestSelector = $selector;
                    }
                }
            }

            $this->assertGreaterThan(
                0,
                $highest,
                'premise: ' . $theme . ' stacks the merchant chrome with a z-index'
            );

            $this->assertGreaterThan(
                $highest,
                $skipLink,
                'the skip link (--ss-z-skip-link: ' . $skipLink . ') does not clear `'
                . $highestSelector . '` (' . $highest . ') in ' . $theme . ', so the focused link '
                . 'is painted behind opaque chrome. Both are in the root stacking context, so dom '
                . 'order cannot rescue it.'
            );
        }

        /* AND IT STAYS UNDER AN OPEN OVERLAY'S DIM, which is the relationship the repair had to
           preserve. `--ss-z-dialog` (1050) would have cleared the chrome too -- and would have
           flipped the link from below `.ss-dialog-backdrop`/`.ss-panel-backdrop` (1040) and
           `.ss-command-backdrop` (calc(dialog - 1) = 1049) to above them. store-syria-ds.js
           deliberately suspends Bootstrap's focus trap while a side panel is open, so panel
           containment is first-party rather than the browser's; any gap in it would then surface a
           focused link painted over a live dim instead of behind it. */
        $backdrop = $this->tokenValue($ds, '--ss-z-backdrop');

        $this->assertNotNull($backdrop, 'premise: --ss-z-backdrop is defined');
        $this->assertGreaterThan(
            $skipLink,
            $backdrop,
            'the skip link now sits above the dialog and panel backdrops, so a focused skip link '
            . 'would paint over an open overlay\'s dim'
        );

        /* NEGATIVE CONTROL -- `--ss-z-sticky` MUST NOT HAVE BEEN RAISED instead. `.ss-actionbar`
           is its only other consumer; raising 1020 past 1040 would float the sticky action bar
           over the modal backdrop, and past 1000 over every dropdown. */
        $this->assertSame(
            1020,
            $this->tokenValue($ds, '--ss-z-sticky'),
            '--ss-z-sticky was raised. `.ss-actionbar` is its only other consumer and would float '
            . 'over dropdowns and the modal backdrop. The skip link needed its own token.'
        );
    }

    /**
     * NEGATIVE CONTROL -- THE LINK MUST STAY INVISIBLE WHEN UNFOCUSED, AND DIRECTION-AGNOSTIC.
     *
     * `inset-block-start: -100px` is the whole of what hides it; the z-index bump must not have
     * been "made sure of" by parking the link on screen, which would put a permanently visible
     * link over the sidebar brand on all 110 views that extend this layout. And the logical
     * properties are why ONE edit covers Arabic and English: a repair reaching for `left`/`top`
     * would strand the link under the RTL sidebar, which mirrors to the right edge.
     */
    public function test_the_skip_link_rest_state_and_logical_properties_survive(): void
    {
        $ds = $this->designSystem();

        $this->assertSame(
            1,
            preg_match('/\.ss-skip-link\s*\{([^}]*)\}/s', $ds, $rule),
            'the `.ss-skip-link` rule is gone'
        );

        $this->assertStringContainsString('position: absolute', $rule[1],
            'the skip link is no longer absolutely positioned');
        $this->assertStringContainsString('inset-block-start: -100px', $rule[1],
            'the skip link no longer rests off-screen, so it is permanently visible over the '
            . 'sidebar brand on every merchant screen');
        $this->assertStringContainsString('inset-inline-start', $rule[1],
            'the skip link uses a physical inline offset, so it strands under the RTL sidebar');
        $this->assertStringNotContainsString('left:', $rule[1],
            'the skip link uses `left`, which does not mirror for Arabic');

        $this->assertMatchesRegularExpression(
            '/\.ss-skip-link:focus\s*\{[^}]*inset-block-start:/s',
            $ds,
            'the focused state no longer moves the link into view with a logical offset'
        );

        /* AND THE STYLESHEET IS STILL LINKED OUTSIDE THE DIRECTION BRANCH, which is why one edit
           reaches all four light/dark x LTR/RTL builds. If it were moved inside the @if, the
           z-index would apply to one combination. */
        $layout = $this->layout();

        $this->assertGreaterThan(
            strpos($layout, 'assets/css/style.css'),
            strpos($layout, 'css/store-syria-ds.css'),
            'store-syria-ds.css is linked before the theme @if, i.e. possibly inside a direction '
            . 'branch; it must stay after it so one rule covers Arabic and English, light and dark'
        );
    }

    private const THEMES = [
        'public/assets/css/style.css',
        'public/assets/css/style-dark.css',
        'public/assets/css/style-rtl.css',
        'public/assets/css/rtl-style-dark.css',
    ];

    private function designSystem(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/css/store-syria-ds.css');
    }

    /** The integer value of a custom property declared in store-syria-ds.css, or null. */
    private function tokenValue(string $css, string $token): ?int
    {
        return preg_match('/' . preg_quote($token, '/') . ':\s*(\d+)\s*;/', $css, $m) === 1
            ? (int) $m[1]
            : null;
    }

    /**
     * AND THE STRING IS TRANSLATED, in both languages.
     *
     * A skip link is read aloud; an untranslated one is read aloud in English to an Arabic user.
     * The project's own translation ratchet covers `resources/lang/*.json` as files, not this
     * string in particular, so it is named here.
     */
    public function test_the_skip_link_string_is_translated_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $strings = json_decode(file_get_contents(__DIR__ . '/../../../resources/lang/' . $locale . '.json'), true);

            $this->assertIsArray($strings, $locale . '.json did not decode');
            $this->assertArrayHasKey(
                'Skip to main content',
                $strings,
                'the skip-link string is missing from ' . $locale . '.json, so it renders as the '
                . 'English source text'
            );
            $this->assertNotSame('', trim((string) $strings['Skip to main content']));
        }

        $ar = json_decode(file_get_contents(__DIR__ . '/../../../resources/lang/ar.json'), true);

        $this->assertNotSame(
            'Skip to main content',
            $ar['Skip to main content'],
            'the Arabic entry is the English string, which is how an untranslated key hides from a '
            . 'key-presence check'
        );
    }
}
