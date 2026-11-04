<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 — THE MOBILE DRAWER WAS HIDDEN GEOMETRICALLY, NOT SEMANTICALLY.
 * =================================================================================================
 *
 * `public/assets/css/style.css` moves `.dash-sidebar` to `left: -255px` inside
 * `@media (max-width: 1024px)` — mirrored in `style-dark.css`, and `right: -255px` in
 * `style-rtl.css` and `rtl-style-dark.css`. `position: fixed` with a negative inline offset keeps
 * the element's layout box, so the whole subtree stays in the accessibility tree and in the tab
 * order: 32 controls (brand link, 8 top-level nav links, 20 submenu links, the sidebar search
 * input, the workspace trigger and the rail toggle) that a merchant on a phone or tablet cannot
 * see. WCAG 2.4.7 — focus lands somewhere invisible.
 *
 * A rule-block scan of every stylesheet the admin loads finds NO `visibility`, `display`,
 * `pointer-events`, `inert` or `content-visibility` declaration whose SUBJECT is `.dash-sidebar`
 * itself, and no script hides it: `dash.js` and `side-dash.js` only toggle the
 * `mob-sidebar-active` class. So nothing but the offset separates the two states.
 *
 * THE REPAIR IS ONE RULE IN public/css/store-syria-a11y.css, and the file is forced rather than
 * preferred: the layout links it ONCE, after the four-branch theme `@if`, so one
 * direction-agnostic `visibility` declaration covers `left: -255px` and `right: -255px`, light and
 * dark, Arabic and English. It cannot live in store-syria-ds.css (DesignSystemStylesheetScopeTest
 * allows only three unconfined selectors and `.dash-sidebar` carries no `.ss-` token) and must not
 * live in custom.css/rtl-custom.css, which would be two copies of one fact.
 *
 * WHAT THIS TEST CANNOT DO, stated rather than implied: it reads stylesheets, scripts and Blade
 * source. It cannot prove a browser removes the subtree from the tab order, because that needs a
 * rendered page and a real Tab press. What it CAN hold is every condition the behaviour depends
 * on — the enclosing media query (an unscoped rule would hide the navigation on every desktop
 * screen), the class literal matching the one the JS toggles (a typo would hide the navigation
 * permanently), the theme premise that makes the rule necessary, and the absence of the
 * over-reaching mechanisms that look identical in a screenshot.
 *
 * ORDERING IS SOMEBODY ELSE'S ASSERTION, deliberately not duplicated here:
 * FocusIndicatorRestorationTest::test_the_layout_loads_it_after_every_theme_branch already pins
 * that store-syria-a11y.css is linked exactly once and after all four theme branches. This rule
 * now depends on that single post-`@if` link too, so if that test is ever relaxed, this rule stops
 * covering RTL and dark.
 */
class OffCanvasSidebarFocusTest extends TestCase
{
    private const RESTORATION = 'public/css/store-syria-a11y.css';
    private const SIDEBAR = 'resources/views/partision/sidebar.blade.php';
    private const FOOTERLINK = 'resources/views/partision/footerlink.blade.php';

    /** style.css / style-dark.css use `left`; the two RTL sheets mirror to `right`. */
    private const THEME_OFFSETS = [
        'public/assets/css/style.css' => 'left',
        'public/assets/css/style-dark.css' => 'left',
        'public/assets/css/style-rtl.css' => 'right',
        'public/assets/css/rtl-style-dark.css' => 'right',
    ];

    private const DRAWER_SELECTOR = '.dash-sidebar:not(.mob-sidebar-active)';

    private function read(string $relative): string
    {
        $path = __DIR__ . '/../../../' . $relative;

        $this->assertFileExists($path, $relative . ' is missing');

        return (string) file_get_contents($path);
    }

    /**
     * Comments BLANKED, not deleted, so line numbers survive — and it is not a nicety here: this
     * file's own explanatory comment quotes `{ visibility: visible !important }` and a brace-depth
     * walker that read prose would mis-count its nesting outright.
     */
    private function codeOf(string $relative): string
    {
        return (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            fn (array $m) => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            $this->read($relative)
        );
    }

    /**
     * A brace-depth walker over CSS source. Returns, for every rule whose prelude contains
     * $needle, the list of enclosing at-rule preludes (outermost first) and the rule's own body.
     *
     * This exists because a substring grep cannot answer the question that matters. A rule emitted
     * at TOP LEVEL in a file that merely contains the string `max-width: 1024px` somewhere else
     * would satisfy `str_contains` and hide the navigation on every desktop merchant screen.
     *
     * @return array<int, array{ancestors: array<int, string>, selector: string, body: string}>
     */
    private function rulesMatching(string $code, string $needle): array
    {
        $found = [];
        $stack = [];      // enclosing at-rule preludes
        $prelude = '';
        $length = strlen($code);

        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];

            if ($char === '{') {
                $header = trim(preg_replace('/\s+/', ' ', $prelude) ?? '');
                $prelude = '';

                if (str_starts_with($header, '@')) {
                    $stack[] = $header;
                    continue;
                }

                // A declaration block: read to its matching close brace.
                $depth = 1;
                $body = '';
                for ($j = $i + 1; $j < $length && $depth > 0; $j++) {
                    if ($code[$j] === '{') {
                        $depth++;
                    } elseif ($code[$j] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $body .= $code[$j];
                }

                if (str_contains($header, $needle)) {
                    $found[] = ['ancestors' => $stack, 'selector' => $header, 'body' => $body];
                }

                $i = $j;
                continue;
            }

            if ($char === '}') {
                array_pop($stack);
                $prelude = '';
                continue;
            }

            if ($char === ';' && $prelude !== '') {
                // An at-statement such as `@charset` or `@import`; it opens no block.
                $prelude = '';
                continue;
            }

            $prelude .= $char;
        }

        return $found;
    }

    /**
     * THE ENCLOSING AT-RULE, NOT A SUBSTRING. At >= 1025px the sidebar is permanent chrome: an
     * unscoped `visibility: hidden` here would hide the entire navigation on every desktop
     * merchant screen, on all ~110 admin views. The media query is the repair, not a preference.
     */
    public function test_the_drawer_is_hidden_only_inside_the_mobile_media_query(): void
    {
        $rules = $this->rulesMatching($this->codeOf(self::RESTORATION), self::DRAWER_SELECTOR);

        $this->assertNotEmpty(
            $rules,
            'store-syria-a11y.css carries no `' . self::DRAWER_SELECTOR . '` rule, so the '
            . 'off-canvas drawer keeps its 32 controls in the tab order at <= 1024px'
        );

        foreach ($rules as $rule) {
            $this->assertMatchesRegularExpression(
                '/visibility:\s*hidden/i',
                $rule['body'],
                'the `' . self::DRAWER_SELECTOR . '` rule does not hide the drawer'
            );

            $this->assertCount(
                1,
                $rule['ancestors'],
                'the `' . self::DRAWER_SELECTOR . '` rule is nested in '
                . count($rule['ancestors']) . ' at-rules (' . implode(' > ', $rule['ancestors'])
                . '); it must sit inside exactly one `@media (max-width: 1024px)`. A rule at TOP '
                . 'LEVEL would hide the navigation on every desktop screen in the product, and a '
                . 'naive substring grep for `max-width: 1024px` would not notice.'
            );

            $at = $rule['ancestors'][0];

            $this->assertMatchesRegularExpression(
                '/^@media\s*\(\s*max-width:\s*1024px\s*\)$/i',
                $at,
                'the drawer rule is enclosed by `' . $at . '`, not `@media (max-width: 1024px)`. '
                . 'That is the exact range in which the theme parks the sidebar at -255px.'
            );
        }
    }

    /**
     * THE CSS CLASS LITERAL IS THE JS CLASS LITERAL. `:not(.mob-sidebar-active)` is the only thing
     * separating hidden from open. A typo in that token hides the navigation PERMANENTLY at
     * <= 1024px — a total loss of navigation, strictly worse than the defect being repaired — and
     * no string test of the CSS alone would catch it.
     */
    public function test_the_open_state_class_matches_the_literal_the_scripts_toggle(): void
    {
        $rules = $this->rulesMatching($this->codeOf(self::RESTORATION), '.dash-sidebar:not(');

        $this->assertNotEmpty($rules, 'no `.dash-sidebar:not(...)` rule to read a class token from');

        foreach ($rules as $rule) {
            $this->assertSame(
                1,
                preg_match('/\.dash-sidebar:not\(\.([A-Za-z0-9_-]+)\)/', $rule['selector'], $m),
                'cannot extract the open-state class from `' . $rule['selector'] . '`'
            );

            $token = $m[1];

            foreach (['public/assets/js/dash.js', 'public/assets/js/side-dash.js'] as $script) {
                $this->assertStringContainsString(
                    '"' . $token . '"',
                    $this->read($script),
                    $script . ' never adds the class `' . $token . '` that the CSS keys the open '
                    . 'drawer on. If the names disagree the navigation is hidden permanently at '
                    . '<= 1024px and the hamburger cannot bring it back.'
                );
            }
        }

        // Premise: one of those two scripts is loaded on every branch, so the class is really
        // toggled on every tenant.
        $footer = $this->read(self::FOOTERLINK);

        $this->assertStringContainsString("module_is_active('SidebarCustomization')", $footer,
            'premise: footerlink.blade.php branches on SidebarCustomization to choose the script');
        $this->assertStringContainsString('assets/js/side-dash.js', $footer,
            'premise: the SidebarCustomization branch loads side-dash.js');
        $this->assertStringContainsString('assets/js/dash.js', $footer,
            'premise: the default branch loads dash.js');
    }

    /**
     * THE PREMISE, the way FocusIndicatorRestorationTest does for the focus ring. If the vendored
     * theme is ever updated to hide the drawer properly, this rule becomes redundant and somebody
     * should decide that deliberately rather than leave it unexamined forever.
     */
    public function test_every_theme_still_parks_the_drawer_off_screen_at_mobile_widths(): void
    {
        foreach (self::THEME_OFFSETS as $theme => $property) {
            $rules = $this->rulesMatching($this->codeOf($theme), '.dash-sidebar');

            $offScreen = array_values(array_filter(
                $rules,
                fn (array $rule) => trim($rule['selector']) === '.dash-sidebar'
                    && count($rule['ancestors']) === 1
                    && preg_match('/^@media\s*\(\s*max-width:\s*1024px\s*\)$/i', $rule['ancestors'][0]) === 1
                    && preg_match('/' . $property . ':\s*-255px/i', $rule['body']) === 1
            ));

            $this->assertNotEmpty(
                $offScreen,
                $theme . ' no longer parks `.dash-sidebar` at `' . $property . ': -255px` inside '
                . '`@media (max-width: 1024px)`. If the theme now hides the drawer properly, the '
                . 'rule in store-syria-a11y.css may be redundant for this sheet -- that is a '
                . 'decision to take deliberately, not a test to delete.'
            );
        }
    }

    /**
     * THE PREMISE FOR THE RULE THAT IS DELIBERATELY ABSENT.
     *
     * `style.css` carries `.dash-sidebar .collapse-sidebar .dash-item.dash-trigger>.dash-submenu
     * { visibility: visible !important }` inside `@media screen and (min-width: 1024px)`, which
     * overlaps the repair's block at EXACTLY 1024px — and `visibility: hidden` is inherited, not
     * absolute, so a descendant re-declaring `visible` would escape it. A neutraliser for it was
     * drafted and then dropped, because sidebar.blade.php's own inline script strips
     * `collapse-sidebar` from `.dash-navbar` whenever `window.innerWidth <= 1024`, so that rule
     * cannot match anywhere in the repair's range, on any tenant, module active or not.
     *
     * This test pins that reasoning to the source. If the script goes, the neutraliser must
     * return, and a reader should be told so by a failure rather than by a bug report.
     */
    public function test_the_host_strips_the_collapse_class_below_the_mobile_breakpoint(): void
    {
        $sidebar = $this->read(self::SIDEBAR);

        $this->assertStringContainsString('function removeCollapseClass()', $sidebar,
            'sidebar.blade.php no longer strips `collapse-sidebar`. style.css\'s '
            . '`visibility: visible !important` escape hatch (@media screen and (min-width:1024px)) '
            . 'can now match at exactly 1024px, so a submenu would escape the inherited '
            . '`visibility: hidden` and stay focusable. Restore the neutraliser in '
            . 'store-syria-a11y.css.');

        $this->assertStringContainsString("classList.remove('collapse-sidebar')", $sidebar,
            'premise: removeCollapseClass() really removes the class the escape hatch requires');

        $this->assertMatchesRegularExpression(
            '/window\.innerWidth\s*<=\s*1024/',
            $sidebar,
            'premise: the strip is guarded at <= 1024px, the same range as the repair\'s media query'
        );
    }

    /**
     * NO OVER-REACH. `display: none`, `inert`, `pointer-events: none` and `content-visibility` all
     * look identical in a screenshot and are all wrong here.
     *
     * `display: none` kills the theme's `transition: all 0.15s ease-in-out` on this element — and
     * `visibility` is transitionable, with `visible` extending across the duration, so the drawer
     * appears instantly on open and stays visible through the 150ms slide-out with no
     * `transition-delay` — and it destroys simplebar's scroll measurements on `.navbar-content`
     * (assets/js/plugins/simplebar.min.js, loaded from footerlink.blade.php). An `inert` or
     * `aria-hidden` ATTRIBUTE on the `<nav>` is worse still: the drawer is opened by JS adding a
     * CLASS, and no script would ever remove such an attribute, so the drawer would stay hidden
     * from assistive technology even while open. CSS keyed to the class the JS already toggles is
     * the only mechanism that stays in sync.
     */
    public function test_the_repair_does_not_reach_past_visibility(): void
    {
        $code = $this->codeOf(self::RESTORATION);

        foreach ($this->rulesMatching($code, '.dash-sidebar') as $rule) {
            foreach (['display:\s*none', 'pointer-events:\s*none', 'content-visibility'] as $banned) {
                $this->assertDoesNotMatchRegularExpression(
                    '/' . $banned . '/i',
                    $rule['body'],
                    'the `' . $rule['selector'] . '` rule uses a mechanism stronger than '
                    . '`visibility`. That kills the 150ms slide transition and simplebar\'s '
                    . 'measurements on `.navbar-content`.'
                );
            }
        }

        // And the markup route, which cannot stay in sync with a class-toggling script.
        $this->assertStringNotContainsString('inert', $this->read(self::SIDEBAR),
            'the sidebar markup carries `inert`; the drawer is opened by JS adding a class and no '
            . 'script removes that attribute, so the drawer would stay hidden from assistive '
            . 'technology even when open');

        // The drawer's own premise: the theme still transitions this element, which is WHY
        // `visibility` and not `display` is the right mechanism.
        $this->assertMatchesRegularExpression(
            '/\.dash-sidebar\s*\{[^}]*transition:\s*all\s*0\.15s/i',
            $this->codeOf('public/assets/css/style.css'),
            'premise: the theme transitions `.dash-sidebar`, which is why the repair uses the '
            . 'transitionable `visibility` rather than `display: none`'
        );
    }

    /**
     * NEGATIVE CONTROL — NO PERMISSION OR TENANT SURFACE MOVED. This repair is CSS only: no
     * controller, no `isAbleTo()` gate, no operator-first check, no `store_id` predicate, no AJAX
     * contract. The sidebar's items come from `getSideMenu()`/`getMenu()`, gated per module
     * entitlement and per Laratrust permission; a merchant with fewer permissions must still get
     * fewer nav items, hidden or not. Nothing here may turn a hide into an authorization change.
     */
    public function test_the_per_permission_menu_still_renders_from_the_same_calls(): void
    {
        $sidebar = $this->read(self::SIDEBAR);

        $this->assertStringContainsString("module_is_active('SidebarCustomization')", $sidebar,
            'the SidebarCustomization branch on `.dash-navbar` has changed');
        $this->assertStringContainsString('getSideMenu()', $sidebar,
            'the SidebarCustomization menu call has changed');
        $this->assertStringContainsString('getMenu()', $sidebar,
            'the default per-permission menu call has changed');

        // The repair must not have reached into the partial at all: no visibility handling here.
        $this->assertStringNotContainsString('visibility', $sidebar,
            'the repair leaked into the sidebar partial; it belongs in store-syria-a11y.css, which '
            . 'the layout links once after the four-branch theme @if so one rule covers both '
            . 'directions');
    }
}
