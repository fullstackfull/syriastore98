<?php

namespace Tests\Unit\Ui;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * D-25 STEP 1 — the shared header's design-system scope.
 * ======================================================
 *
 * The owner approved a PHASED conversion whose conditions are to preserve every element id, event
 * handler, link and permission, not to delete WorkDo's elements, and to stop expanding a
 * modification the moment anything breaks. This file asserts the parts of that which are
 * statements about the source; the browser parts are measured by
 * `check-header-contrast-live.cjs`, `check-keyboard-traversal-live.cjs`,
 * `check-appbar-viewports-live.cjs` and the 24-capture before/after contract from
 * `capture-header-contract.cjs`.
 *
 * WHAT STEP 1 ACTUALLY DID, stated plainly because it is narrower than "converted the header":
 * it added the scope class and moved ONE declaration — the header's inherited text colour — onto
 * a design-system token.
 *
 * MEASURED: the header's own `color` was `rgb(82, 91, 105)` in BOTH themes while its background
 * is white in light and `rgb(41, 42, 51)` in dark, so the inherited colour was **2.07:1** on the
 * dark surface. It is now `#5b6665` / `#a7b2b1` from `--ss-fg-muted`, measuring 5.94:1 and
 * 6.55:1 on desktop and 7.11:1 on a 390px phone.
 *
 * AND IT FIXED NOTHING VISIBLE, which is the honest part. All 30 visible text nodes in the header
 * measured 0 below AA before the change, because F158 patched each selector by hand. The
 * inherited value only reaches text with no rule of its own — the next thing anybody adds to this
 * header. The 24-capture contract diff confirms it: of eight recorded fields, only `style.color`
 * changed (plus `tabOrder`, which went from `[]` to seven controls because F177 was fixed in the
 * same stretch). `ids`, `links`, `forms`, `toggles`, geometry and the real clicked-open state of
 * every dropdown are byte-identical in all 24.
 */
class SharedHeaderDesignSystemScopeTest extends TestCase
{
    use StripsSourceComments;

    private const HEADER = 'resources/views/partision/header.blade.php';
    private const DS = 'public/css/store-syria-ds.css';

    public function test_the_header_carries_the_scope_class_in_every_branch(): void
    {
        $source = $this->sourceWithoutComments(self::HEADER);

        preg_match_all('/<header\b[^>]*>/', $source, $tags);

        $this->assertNotSame([], $tags[0], 'the header partial renders no <header> element');

        /*
         * BOTH BRANCHES. The partial renders one of two `<header>` tags depending on the
         * merchant's `cust_theme_bg` setting, and a scope class added to only one of them would
         * work for most merchants and silently not for the rest — the kind of half-applied change
         * that is invisible until somebody turns the setting on.
         */
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString(
                'ss-appbar',
                $tag,
                'a <header> branch is missing the ss-appbar scope: ' . $tag
            );
        }

        $this->assertGreaterThanOrEqual(2, count($tags[0]),
            'premise: the partial still has both theme-background branches, so "every branch" means something');
    }

    public function test_the_themes_load_bearing_names_are_preserved(): void
    {
        $source = $this->sourceWithoutComments(self::HEADER);

        /*
         * ADDITIVE, NOT A REPLACEMENT — the owner's condition, and the reason is mechanical:
         * `public/assets/js/dash.js` and `side-dash.js` select `#mobile-collapse`, the theme
         * carries ~210 `.dash-header` rules including the header's layout and background, and
         * `style.css` targets `.dash-header .dash-head-link#mobile-collapse` by id.
         */
        /*
         * `dash-header` IS ASSERTED ON EVERY <header> TAG, not merely somewhere in the file — and
         * that correction came from a bite that failed to bite. Removing the class from ONE of the
         * two branches left this test green, because the other branch still contained the string.
         * A whole-file `assertStringContainsString` cannot see a half-applied change, which is the
         * exact failure mode the branch test above exists for.
         */
        preg_match_all('/<header\b[^>]*>/', $source, $tags);

        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString(
                'dash-header',
                $tag,
                'a <header> branch has lost the theme\'s own class, which its JavaScript and ~210 '
                . 'CSS rules depend on: ' . $tag
            );
        }

        /* These two are referenced by id and by descendant selector, so file-wide is right. */
        foreach (['mobile-collapse', 'dash-head-link'] as $name) {
            $this->assertStringContainsString(
                $name,
                $source,
                'the theme name "' . $name . '" is gone from the header, and its JavaScript or CSS '
                . 'depends on it'
            );
        }
    }

    public function test_the_scope_takes_its_colour_from_a_token(): void
    {
        $css = $this->sourceWithoutComments(self::DS);

        $this->assertSame(
            1,
            preg_match('/\.ss-appbar\s*\{[^}]*\}/s', $css, $block),
            'the design system declares no .ss-appbar rule, so the scope class styles nothing'
        );

        /*
         * A TOKEN, NOT A HEX. The whole point of the step is to move the source of truth: the
         * header's colours were four hardcoded values across four stylesheets, and a token tracks
         * the theme through `data-ss-theme` instead. A literal colour here would reinstate the
         * problem in a new file.
         */
        $this->assertSame(
            1,
            preg_match('/color:\s*var\(--ss-[a-z0-9-]+\)/', $block[0]),
            'the .ss-appbar rule does not take its colour from a design-system token: ' . $block[0]
        );

        $this->assertSame(
            0,
            preg_match('/#[0-9a-fA-F]{3,8}\b/', $block[0]),
            'the .ss-appbar rule hardcodes a colour: ' . $block[0]
        );
    }

    /**
     * STEP 1 STOPS AT COLOUR. Its own note says so, and the reason is a measurement: the theme
     * paints both the header and the sidebar beside it, so moving one to a DS surface token and
     * not the other leaves two greys that nearly match — worse than either alone. This asserts
     * the restraint, so a later edit that quietly extends Step 1 into the surface has to change a
     * named test and say why.
     */
    public function test_step_one_does_not_touch_the_surface_or_the_geometry(): void
    {
        $css = $this->sourceWithoutComments(self::DS);

        $this->assertSame(1, preg_match('/\.ss-appbar\s*\{([^}]*)\}/s', $css, $block));

        foreach (['background', 'border', 'height', 'padding', 'margin', 'position'] as $property) {
            $this->assertSame(
                0,
                preg_match('/(^|[;\s])' . $property . '[a-z-]*\s*:/i', $block[1]),
                'the .ss-appbar rule sets "' . $property . '", which is beyond D-25 Step 1 — the '
                . 'theme owns the header\'s surface and layout until a step moves them with their '
                . 'own before/after measurement'
            );
        }
    }
}
