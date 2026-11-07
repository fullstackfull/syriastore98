<?php

namespace Tests\Unit\Ui;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * PHASE 2.5 / primary-action-named-only-by-title — the page-header primary action on seven
 * catalogue and marketing list screens.
 * ===================================================================================
 *
 * WHAT WAS WRONG, and why it is not visible in the source. Eight anchors across seven views
 * authored their ONLY name as `title`, beside `data-bs-toggle="tooltip"`, wrapping an
 * `<i class="ti ti-plus">` with no `aria-hidden`. `public/assets/js/dash.js` (and
 * `side-dash.js`, branch-selected by `partision/footerlink.blade.php`) constructs a Bootstrap
 * Tooltip for every such element on `load`. Bootstrap v5.1.3's constructor reaches `_fixTitle()`,
 * which MOVES `title` into `data-bs-original-title` and sets `title=""`. Its `aria-label`
 * fallback does not fire, because v5.1.3 tests the UNTRIMMED `this._element.textContent`, which
 * on these multi-line anchors is whitespace and therefore truthy. So after page load the control
 * has no `title`, no `aria-label` and no text — and the name computation falls back to the icon
 * font's generated content.
 *
 * WHAT A SCREEN READER ANNOUNCED IS NOT SILENCE. `public/assets/fonts/tabler-icons.min.css` sets
 * `.ti-plus:before { content: "\eb0b" }`, so in Chromium the accessible name was the Tabler
 * PRIVATE-USE codepoint U+EB0B (U+EDE9 for coupon's `ti-file-export`). This repository measured
 * exactly that shape on an identically built control — see
 * `PageActionRegionContractTest`'s docblock, which records the order list's export control
 * naming itself `""` and Create Order naming itself `" Create Order"`. A test that
 * asserted the broken name was the EMPTY STRING would therefore have failed today for the wrong
 * reason; this file is a source-level test and asserts the shape instead, exactly as
 * `PageActionRegionContractTest::test_every_control_in_the_region_has_an_accessible_name` does.
 *
 * WHAT MAY NOT MOVE. Four of these controls' contracts are invisible in the markup, and each one
 * fails SILENTLY — no 500, no visual change:
 *
 *   - `data-ajax-popup="true"` must stay on the SAME TAG as its `data-url`. Move it to a wrapper
 *     and the button simply stops opening its dialog. Pinned here and, for two of the seven, by
 *     `ModalFormRefusalFeedbackTest`'s same-tag regex and by `AjaxPopupContractRatchetTest`'s
 *     128-occurrence / 83-file floor.
 *   - `.export-btn` and `filename` on coupon's export anchor. `public/js/loader.js` binds
 *     `.export-btn` with a delegated handler that preventDefaults, fetches the href as a blob and
 *     names the download `getAttribute('filename') + '.xlsx'`. Rename the class and the control
 *     still looks and navigates fine while the download stops.
 *   - `.add_attribute` on product_brand and product_label. MEASURED: nothing in JavaScript binds
 *     it — `grep -rn add_attribute public/ --include=*.js` and the same over `packages/` are both
 *     EMPTY. Its only live consumer is a cosmetic rule carried in BOTH mirrored stylesheets,
 *     `public/css/custom.css:1518` and `public/css/rtl-custom.css:1530`,
 *     `.add_attribute { font-family: "Open Sans", sans-serif; }`. It is kept because of that rule
 *     and to match the shipped `attributes/index.blade.php`, NOT because of a JS binding — the
 *     finding's draft recorded a binding that does not exist, and a later slice must not protect
 *     it. The rule is overridden only by link order: both selectors are 0,1,0 and
 *     `store-syria-ds.css` links AFTER `custom.css`/`rtl-custom.css` in `layouts/app.blade.php`,
 *     with `.ss-btn` resetting `font-family: inherit`. That link order is load-bearing.
 *   - the `@permission` gates. Each of the seven primaries sits inside one; coupon's export sits
 *     deliberately OUTSIDE any, because its gate is server-side on `fileExport()` and owned by
 *     `tests/Feature/Security/CouponExportPermissionTest.php`. Hoisting an anchor out of its
 *     block to flatten the markup would expose a create action to staff without the permission;
 *     wrapping the export in one would be a behaviour change dressed as consistency.
 *
 * WHAT IS DELIBERATELY *NOT* ASSERTED, because asserting it would fail code that already ships:
 *
 *   - `aria-label` is NOT required on an `ss-btn` in an index view. A control named by its own
 *     visible text must not also carry one — the two can drift (WCAG 2.5.3). All three shipped
 *     precedents (`category/index`, `attributes/index`, `attribute_option/index`) carry `ss-btn`
 *     with no `aria-label`. `ActionPartialContractTest` enforces the OPPOSITE rule, but only over
 *     the per-row `action.blade.php` partials, and that rule must not be generalised here.
 *   - `data-bs-toggle="tooltip"` is NOT required. `attribute_option/index` ships this exact
 *     pattern WITHOUT one on its empty-state call to action, so requiring it would fail a
 *     precedent. It is nonetheless KEPT on all eight anchors here, because the tooltip is the name
 *     a sighted mouse user gets and removing the toggle removes it silently — the over-reach that
 *     most looks like cleanup.
 *
 * RESIDUAL, DISCLOSED. On product_brand and product_label, when `module_is_active('ImportExport')`
 * is true, two icon-only controls per screen REMAIN named only by `title` with an unhidden `<i>`:
 * `packages/workdo/ImportExport/src/resources/views/{import/brand_import,export/brand_export}`
 * and the label twins. They are vendored and out of bounds. Those two screens are PARTIALLY
 * closed, not clean. They are `@include`s rather than inline tags, so they are outside this
 * file's regex by construction rather than by exclusion — said here so the next reader is not
 * misled by a green run. Same host-`btn-primary`-beside-`ss-btn--primary` mix that the shipped
 * `category/index` already carries.
 */
class PageHeaderPrimaryActionNameTest extends TestCase
{
    use StripsSourceComments;

    /** The seven views this slice converts. Eight anchors in total — coupon has two. */
    private const VIEWS = [
        'resources/views/product_brand/index.blade.php',
        'resources/views/product_label/index.blade.php',
        'resources/views/tag/index.blade.php',
        'resources/views/coupon/index.blade.php',
        'resources/views/flash-sale/index.blade.php',
        'resources/views/testimonial/index.blade.php',
        'resources/views/faq/index.blade.php',
    ];

    /**
     * Already shipped with the pattern, and included so this file cannot assert a rule that
     * contradicts them. If a future tightening of the rule breaks a precedent, it breaks here
     * rather than in review.
     */
    private const PRECEDENTS = [
        'resources/views/category/index.blade.php',
        'resources/views/attributes/index.blade.php',
        'resources/views/attribute_option/index.blade.php',
    ];

    /** Where each screen's controls must still go. A conversion may not change the target. */
    private const ROUTES = [
        'resources/views/product_brand/index.blade.php' => ['product-brand.create'],
        'resources/views/product_label/index.blade.php' => ['product-label.create'],
        'resources/views/tag/index.blade.php' => ['tag.create'],
        'resources/views/coupon/index.blade.php' => ['coupon.create', 'coupon.export'],
        'resources/views/flash-sale/index.blade.php' => ['flash-sale.create'],
        'resources/views/testimonial/index.blade.php' => ['testimonial.create'],
        'resources/views/faq/index.blade.php' => ['faqs.create'],
    ];

    /** The seven modal triggers: the route each must keep on the SAME tag as `data-ajax-popup`. */
    private const MODAL_TRIGGERS = [
        'resources/views/product_brand/index.blade.php' => 'product-brand.create',
        'resources/views/product_label/index.blade.php' => 'product-label.create',
        'resources/views/tag/index.blade.php' => 'tag.create',
        'resources/views/coupon/index.blade.php' => 'coupon.create',
        'resources/views/flash-sale/index.blade.php' => 'flash-sale.create',
        'resources/views/testimonial/index.blade.php' => 'testimonial.create',
        'resources/views/faq/index.blade.php' => 'faqs.create',
    ];

    /**
     * Bootstrap's own button classes. `store-syria-ds.css`'s `.ss-btn` comment states it is an
     * explicit metrics reset "because both are loaded on admin pages" — an element carrying both
     * renders as neither, which is why `PageActionRegionContractTest` has the same rule.
     */
    private const BOOTSTRAP_BUTTON = [
        'btn', 'btn-sm', 'btn-lg', 'btn-icon', 'btn-badge',
        'btn-info', 'btn-warning', 'btn-danger', 'btn-primary', 'btn-secondary', 'btn-success',
        'btn-light', 'btn-dark',
    ];

    // =============================================================================================
    //  helpers
    // =============================================================================================

    /** The `@section('action-button')` body with comments stripped, and nothing else. */
    private function region(string $relative): string
    {
        $source = $this->sourceWithoutComments($relative);

        $start = strpos($source, "@section('action-button')");
        $this->assertNotFalse($start, $relative . ": no @section('action-button')");

        $end = strpos($source, '@endsection', $start);
        $this->assertNotFalse($end, $relative . ': the action-button section is never closed');

        $region = substr($source, $start, $end - $start);

        /* Shortest region measured today is faq/index at 460 characters. A region that has shrunk
           below this has lost its controls, and every assertion below would pass for free. */
        $this->assertGreaterThan(
            300,
            strlen($region),
            $relative . ': the action-button section is too short to hold its controls'
        );

        return $region;
    }

    /**
     * Each control in a fragment as [opening tag, attributes, inner HTML].
     *
     * THE INNER HTML IS WHY THE WHOLE ELEMENT IS MATCHED. Whether a control is named by its own
     * visible text cannot be decided from the opening tag alone.
     *
     * @return list<array{string, string, string}>
     */
    private function controls(string $fragment): array
    {
        preg_match_all(
            '/<(a|button)\b((?:[^<>"\']|"[^"]*"|\'[^\']*\')*?)>(.*?)<\/\1>/s',
            $fragment,
            $tags,
            PREG_SET_ORDER
        );

        return array_map(
            static fn (array $m): array => ['<' . $m[1] . $m[2] . '>', $m[2], $m[3]],
            $tags
        );
    }

    /**
     * The `@endpermission` that closes the `@permission` beginning at `$gate`, honouring nesting.
     *
     * NOT `strpos(..., '@endpermission', $gate)`. On product_brand and product_label the outer
     * `@permission('Create Product Brand')` block CONTAINS the ImportExport module's own
     * `@permission('brand import')` / `@permission('brand export')` blocks, so the naive search
     * returns the inner close — which sits BEFORE the primary action and made this test report a
     * correctly gated button as ungated. Measured: it failed on both of those two views while the
     * markup was right.
     *
     * @return int|false
     */
    private function matchingEndpermission(string $region, int $gate)
    {
        $depth = 0;
        $offset = $gate;
        $length = strlen($region);

        while ($offset < $length) {
            $open = strpos($region, '@permission(', $offset);
            $end = strpos($region, '@endpermission', $offset);

            if ($end === false) {
                return false;
            }

            if ($open !== false && $open < $end) {
                $depth++;
                $offset = $open + strlen('@permission(');

                continue;
            }

            $depth--;
            if ($depth === 0) {
                return $end;
            }

            $offset = $end + strlen('@endpermission');
        }

        return false;
    }

    /**
     * Does this control carry an accessible name a screen reader can use?
     *
     * Two ways, and only two: a non-empty `aria-label`, or a translated echo OUTSIDE the icon
     * element. The icon's glyph is NOT text — treating it as one is the entire defect.
     */
    private function isNamed(string $attrs, string $inner): bool
    {
        if (str_contains($attrs, 'aria-label=') && ! preg_match('/\baria-label="\s*"/', $attrs)) {
            return true;
        }

        $withoutIcons = (string) preg_replace('/<i\b[^>]*>.*?<\/i>|<i\b[^>]*\/?>/s', '', $inner);

        return (bool) preg_match('/\{\{\s*__\(/', $withoutIcons);
    }

    // =============================================================================================
    //  1. THE DEFECT
    // =============================================================================================

    public function test_every_page_header_control_has_an_accessible_name(): void
    {
        $unnamed = [];
        $named = 0;

        foreach (array_merge(self::VIEWS, self::PRECEDENTS) as $relative) {
            foreach ($this->controls($this->region($relative)) as [$tag, $attrs, $inner]) {
                if ($this->isNamed($attrs, $inner)) {
                    $named++;
                    continue;
                }

                $unnamed[] = $relative . ': ' . trim((string) preg_replace('/\s+/', ' ', substr($tag, 0, 110)));
            }
        }

        /* PREMISE. Eight anchors across the seven converted views plus three in the precedents.
           A regex that matched nothing would otherwise report an empty `$unnamed` and pass. */
        $this->assertGreaterThanOrEqual(
            11,
            $named,
            'fewer named controls than the eleven these ten views hold, so the scan is not'
            . ' reaching the markup it claims to check'
        );

        $this->assertSame(
            [],
            $unnamed,
            "a page-header control has no accessible name: after Bootstrap moves `title` into\n"
            . "`data-bs-original-title` and blanks it, the name computation falls through to the\n"
            . "Tabler private-use glyph. Give it a visible `<span>{{ __('…') }}</span>`, or an\n"
            . "`aria-label` if it must stay icon-only:\n  " . implode("\n  ", $unnamed)
        );
    }

    public function test_every_icon_in_the_page_header_is_hidden_from_assistive_technology(): void
    {
        $exposed = [];
        $hidden = 0;

        foreach (array_merge(self::VIEWS, self::PRECEDENTS) as $relative) {
            preg_match_all('/<i\b[^>]*>/', $this->region($relative), $icons);

            foreach ($icons[0] as $icon) {
                if (preg_match('/aria-hidden="true"/', $icon)) {
                    $hidden++;
                    continue;
                }

                $exposed[] = $relative . ': ' . $icon;
            }
        }

        $this->assertGreaterThanOrEqual(11, $hidden, 'fewer icons found than these ten views carry');

        $this->assertSame(
            [],
            $exposed,
            "an icon font element is still in the accessible name computation. Chromium includes\n"
            . "the `::before` content, so this contributes a private-use codepoint (U+EB0B for\n"
            . "`ti-plus`, U+EDE9 for `ti-file-export`) to the control's name:\n  "
            . implode("\n  ", $exposed)
        );
    }

    public function test_the_converted_controls_carry_no_bootstrap_button_class(): void
    {
        foreach (self::VIEWS as $relative) {
            $region = $this->region($relative);

            foreach ($this->controls($region) as [$tag, $attrs]) {
                preg_match('/\bclass="([^"]*)"/', $attrs, $m);
                $classes = preg_split('/\s+/', trim($m[1] ?? '')) ?: [];

                $clash = array_values(array_intersect($classes, self::BOOTSTRAP_BUTTON));

                $this->assertSame(
                    [],
                    $clash,
                    $relative . ': this control carries both a Bootstrap button class ('
                    . implode(', ', $clash) . ') and the design system. `.ss-btn` is an explicit'
                    . ' metrics reset precisely because both stylesheets load on admin pages, so'
                    . ' an element carrying both renders as neither: '
                    . trim((string) preg_replace('/\s+/', ' ', substr($tag, 0, 110)))
                );
            }
        }
    }

    public function test_no_physical_edge_utility_is_used_in_the_converted_regions(): void
    {
        /*
         * `mr-*`/`ml-*` are BOOTSTRAP 4 and match no rule in any stylesheet under `public/`, so
         * they are dead markup that reads as intentional spacing. The logical `me-*`/`ms-*` are
         * fine and are MIRRORED — `me-1` becomes `margin-left: 0.25rem !important` in
         * `public/assets/css/style-rtl.css` and `rtl-style-dark.css`, both selected by
         * `layouts/app.blade.php`, which is why coupon's export keeps it as the only separation
         * between its two controls.
         */
        foreach (self::VIEWS as $relative) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bclass="[^"]*\b(?:mr|ml)-\d\b/',
                $this->region($relative),
                $relative . ': a Bootstrap 4 physical-edge utility (mr-*/ml-*) is used here. It'
                . ' matches no rule in any stylesheet under public/, so it is dead markup, and if'
                . ' it ever gained a rule it would mirror wrong in Arabic.'
            );
        }
    }

    public function test_a_control_named_by_its_own_visible_text_carries_no_aria_label(): void
    {
        /*
         * WCAG 2.5.3. The two can drift, and `PageActionRegionContractTest` records the same rule.
         * Stated as a biconditional so it also catches the opposite mistake: coupon's export is
         * deliberately icon-only, so it MUST have one.
         */
        foreach (array_merge(self::VIEWS, self::PRECEDENTS) as $relative) {
            foreach ($this->controls($this->region($relative)) as [$tag, $attrs, $inner]) {
                $withoutIcons = (string) preg_replace('/<i\b[^>]*>.*?<\/i>|<i\b[^>]*\/?>/s', '', $inner);
                $hasVisibleText = (bool) preg_match('/\{\{\s*__\(/', $withoutIcons);
                $hasAriaLabel = str_contains($attrs, 'aria-label=');

                $short = trim((string) preg_replace('/\s+/', ' ', substr($tag, 0, 110)));

                if ($hasVisibleText) {
                    $this->assertFalse(
                        $hasAriaLabel,
                        $relative . ': this control is named by its own visible text AND carries an'
                        . ' aria-label. The two can drift, which is a 2.5.3 problem of its own: ' . $short
                    );

                    continue;
                }

                $this->assertTrue(
                    $hasAriaLabel,
                    $relative . ': this control has no visible text, so it needs an aria-label: ' . $short
                );
            }
        }
    }

    // =============================================================================================
    //  2. NEGATIVE CONTROLS — the contracts that fail silently
    // =============================================================================================

    /**
     * THE CONTROL ON THE CHECKER ITSELF. Both tests above are "assert this list is empty", and
     * such a test passes for free the moment its matcher stops matching. These two literals are
     * the real pre-fix and post-fix markup of `product_brand/index.blade.php`, so if `isNamed()`
     * is ever loosened into something that cannot see the defect, this fails and the two tests
     * above do not.
     */
    public function test_the_name_check_still_recognises_the_pre_fix_shape(): void
    {
        $preFix = <<<'BLADE'
        <a href="#" class="btn btn-sm btn-primary add_attribute" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Brand') }}" data-url="{{ route('product-brand.create') }}" data-bs-toggle="tooltip"
            title="{{ __('Add Brand') }}">
            <i class="ti ti-plus"></i>
        </a>
        BLADE;

        $controls = $this->controls($preFix);
        $this->assertCount(1, $controls, 'the control matcher no longer matches a multi-line anchor');
        $this->assertFalse(
            $this->isNamed($controls[0][1], $controls[0][2]),
            'the name check now calls the PRE-FIX markup named. `title` and `data-title` are not'
            . ' accessible names: Bootstrap blanks `title` on tooltip init, and `data-title` is the'
            . " modal's heading. Every assertion in this file would now pass for free."
        );

        $postFix = <<<'BLADE'
        <a href="#" class="ss-btn ss-btn--primary ss-btn--sm add_attribute" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Brand') }}" data-url="{{ route('product-brand.create') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add Brand') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span>{{ __('Add Brand') }}</span>
        </a>
        BLADE;

        $controls = $this->controls($postFix);
        $this->assertCount(1, $controls);
        $this->assertTrue(
            $this->isNamed($controls[0][1], $controls[0][2]),
            'the name check no longer accepts a control named by a visible translated span'
        );
    }

    /**
     * AND the checker must NOT require a tooltip. `attribute_option/index`'s empty-state call to
     * action ships the pattern with no `data-bs-toggle` and no `title` at all, so a rule that
     * demanded one would fail already-shipped code. Read from the real file rather than quoted,
     * so it cannot go stale silently.
     */
    public function test_the_name_check_does_not_require_a_tooltip(): void
    {
        $source = $this->sourceWithoutComments('resources/views/attribute_option/index.blade.php');

        $tooltipless = array_values(array_filter(
            $this->controls($source),
            static fn (array $c): bool => str_contains($c[1], 'ss-btn')
                && ! str_contains($c[1], 'data-bs-toggle')
        ));

        $this->assertNotEmpty(
            $tooltipless,
            'attribute_option/index no longer ships an ss-btn without a tooltip, so this control'
            . ' has lost its subject — re-point it or delete it, do not relax it'
        );

        foreach ($tooltipless as [$tag, $attrs, $inner]) {
            $this->assertTrue(
                $this->isNamed($attrs, $inner),
                'the name check treats a tooltip as necessary, which fails a shipped precedent: '
                . trim((string) preg_replace('/\s+/', ' ', substr($tag, 0, 110)))
            );
        }
    }

    public function test_the_modal_triggers_stay_on_the_same_tag_as_their_route(): void
    {
        /*
         * THE ONE FAILURE THAT IS SILENT: no 500, no visual change, the button just stops opening
         * its dialog. `[^>]*` is the whole point — both attributes must be inside the SAME tag.
         * Either order passes. `ModalFormRefusalFeedbackTest` holds the identical regex for two of
         * these seven, and `AjaxPopupContractRatchetTest` holds the repo-wide floor.
         */
        foreach (self::MODAL_TRIGGERS as $relative => $route) {
            $this->assertMatchesRegularExpression(
                '/data-ajax-popup="true"[^>]*' . preg_quote($route, '/') . '|'
                . preg_quote($route, '/') . '[^>]*data-ajax-popup="true"/s',
                $this->region($relative),
                $relative . ": the modal trigger and {$route} are no longer in the same tag, so"
                . ' this button has silently stopped opening its dialog'
            );
        }
    }

    public function test_the_invisible_javascript_and_stylesheet_contracts_survive(): void
    {
        $coupon = $this->region('resources/views/coupon/index.blade.php');

        /* `public/js/loader.js` binds `.export-btn` and names the download from `filename`. */
        $this->assertStringContainsString('export-btn', $coupon,
            'coupon/index lost `.export-btn`, so loader.js no longer intercepts the click and the'
            . ' blob download stops');
        $this->assertStringContainsString("filename=\"{{ __('Coupon') }}\"", $coupon,
            'coupon/index lost its `filename`, so the export lands as Export.xlsx');
        $this->assertStringContainsString('me-1', $coupon,
            "coupon/index's wrapper carries no gap-* class, so `me-1` on the export anchor is the"
            . ' only separation between its two controls');
        $this->assertStringContainsString('add_coupon', $coupon,
            'coupon/index lost `.add_coupon`');

        /* `.add_attribute` — kept for the mirrored cosmetic rule and the shipped precedent, NOT
           for a JS binding: nothing binds it. See this file's docblock. */
        foreach ([
            'resources/views/product_brand/index.blade.php',
            'resources/views/product_label/index.blade.php',
        ] as $relative) {
            $this->assertStringContainsString('add_attribute', $this->region($relative),
                $relative . ' lost `.add_attribute`, which `public/css/custom.css:1518` and its'
                . ' mirror `public/css/rtl-custom.css:1530` both style');
        }

        /* The mirrored pair must stay mirrored. This repair touches neither, and that is the
           assertion: a repair that reached for CSS has misdiagnosed the defect, because no CSS
           rule can supply an accessible name. */
        $ltr = (string) file_get_contents(base_path('public/css/custom.css'));
        $rtl = (string) file_get_contents(base_path('public/css/rtl-custom.css'));
        $rule = ".add_attribute {\n    font-family: \"Open Sans\", sans-serif;\n}";
        $this->assertStringContainsString($rule, $ltr, 'public/css/custom.css lost the rule');
        $this->assertStringContainsString($rule, $rtl,
            'public/css/rtl-custom.css no longer mirrors custom.css for `.add_attribute`, so the'
            . ' change reached only the LTR half of the merchants');
    }

    public function test_the_route_targets_do_not_move(): void
    {
        foreach (self::ROUTES as $relative => $routes) {
            $region = $this->region($relative);

            foreach ($routes as $route) {
                $this->assertStringContainsString(
                    "route('" . $route . "')",
                    $region,
                    $relative . ": the page-header region no longer targets {$route}"
                );
            }
        }
    }

    public function test_the_permission_gates_do_not_move(): void
    {
        /*
         * A rewrite that hoists an anchor out of its `@permission` block to flatten the markup
         * exposes a create action to staff without the permission. The check is positional: the
         * anchor's offset must lie between the gate and its `@endpermission`.
         */
        foreach ([
            'resources/views/product_brand/index.blade.php' => ['Create Product Brand', 'product-brand.create'],
            'resources/views/product_label/index.blade.php' => ['Create Product Label', 'product-label.create'],
            'resources/views/tag/index.blade.php' => ['Create Tag', 'tag.create'],
            'resources/views/coupon/index.blade.php' => ['Create Coupon', 'coupon.create'],
            'resources/views/flash-sale/index.blade.php' => ['Create Flash Sale', 'flash-sale.create'],
            'resources/views/testimonial/index.blade.php' => ['Create Testimonial', 'testimonial.create'],
            'resources/views/faq/index.blade.php' => ['Create Faqs', 'faqs.create'],
        ] as $relative => [$permission, $route]) {
            $region = $this->region($relative);

            $gate = strpos($region, "@permission('" . $permission . "')");
            $this->assertNotFalse($gate, $relative . ": the @permission('{$permission}') gate is gone");

            $close = $this->matchingEndpermission($region, $gate);
            $this->assertNotFalse($close, $relative . ': the permission block is never closed');

            $action = strpos($region, "route('" . $route . "')");
            $this->assertNotFalse($action, $relative . ": {$route} is gone");

            $this->assertTrue(
                $action > $gate && $action < $close,
                $relative . ": the {$route} action is no longer inside its"
                . " @permission('{$permission}') block, so staff without the permission can see it"
            );
        }

        /* THE OPPOSITE RULE, and it is just as load-bearing. Coupon's export is deliberately
           ungated in the view; its gate is server-side on `fileExport()` and owned by
           `tests/Feature/Security/CouponExportPermissionTest.php`. Wrapping it in a view-level
           @permission would be a behaviour change dressed as consistency. */
        $coupon = $this->region('resources/views/coupon/index.blade.php');
        $export = strpos($coupon, "route('coupon.export')");
        $gate = strpos($coupon, "@permission('Create Coupon')");
        $this->assertNotFalse($export);
        $this->assertNotFalse($gate);
        $this->assertTrue(
            $export < $gate,
            "coupon/index's export control has moved inside a @permission block. Its gate is"
            . ' server-side on fileExport(); a view-level gate changes who sees the control'
            . ' without changing who may use it.'
        );
    }

    /**
     * THE LABEL IS NEWLY VISIBLE TEXT, so WCAG 1.4.3 now applies to it where it did not before.
     *
     * MEASURED HERE, with the WCAG 2.x relative-luminance formula, from the hexes the stylesheet
     * actually declares — no rgba to composite, because `.ss-btn--primary` paints an opaque
     * `--ss-brand` and the label takes the opaque `--ss-on-brand`:
     *
     *   - LIGHT: #ffffff on #335da8 (`--ss-blue-600`)  = **6.42:1**
     *   - DARK:  #04222a on #2ac3e5 (`--ss-cyan-500`)  = **7.92:1**
     *
     * Both clear the 4.5:1 normal-text minimum, so making the label visible introduces no 1.4.3
     * failure in either theme. The tokens are READ rather than hard-coded, so this stays a real
     * guard on the ratio if a later slice retunes the brand instead of failing on the retune.
     */
    public function test_the_newly_visible_label_clears_the_text_contrast_minimum(): void
    {
        $css = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        $token = function (string $name) use ($css): string {
            $this->assertMatchesRegularExpression(
                '/--' . preg_quote($name, '/') . ':\s*(#[0-9a-fA-F]{6})/',
                $css,
                '--' . $name . ' is no longer declared as a six-digit hex, so this ratio cannot be'
                . ' measured — re-measure it rather than relaxing this test'
            );
            preg_match_all('/--' . preg_quote($name, '/') . ':\s*(#[0-9a-fA-F]{6})/', $css, $m);

            return strtolower($m[1][0]);
        };

        /* Light pairs #ffffff with --ss-blue-600; dark pairs #04222a with --ss-cyan-500. */
        foreach ([
            'light' => ['#ffffff', $token('ss-blue-600')],
            'dark' => ['#04222a', $token('ss-cyan-500')],
        ] as $theme => [$fg, $bg]) {
            $ratio = $this->contrast($fg, $bg);

            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                "the primary action's now-visible label measures " . round($ratio, 2) . ':1 in '
                . $theme . " mode ({$fg} on {$bg}), below the 4.5:1 normal-text minimum"
            );
        }

        /* And the figures above are really these, so a token change surfaces as a diff here. */
        $this->assertSame(6.42, round($this->contrast('#ffffff', $token('ss-blue-600')), 2));
        $this->assertSame(7.92, round($this->contrast('#04222a', $token('ss-cyan-500')), 2));
    }

    /** WCAG 2.x relative luminance and contrast ratio, computed rather than looked up. */
    private function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channel = static function (int $v): float {
                $c = $v / 255;

                return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
                + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
                + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
        };

        $la = $luminance($a);
        $lb = $luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public function test_every_newly_visible_label_resolves_in_arabic(): void
    {
        /*
         * `__()` falls back to the KEY, so a missing translation would render readable English
         * rather than an identifier — but on an RTL merchant's screen a sudden English word in a
         * brand-filled primary button is a visible defect, and a visible label is the part of this
         * repair that Arabic sees. VERIFIED BEFORE USE, as the project rule requires.
         *
         * `Export` is NOT in this list, and that is the reason coupon's export stays icon-only:
         * `__('Export')` in Arabic is `الصادرات` — "exports" in the economic sense, a pre-existing
         * mistranslation that is tolerable in a hover tooltip but would become a wrong visible
         * word on every Arabic merchant's screen. Retranslating strings is outside this finding.
         */
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);
        $this->assertIsArray($ar);

        foreach ([
            'Add Brand', 'Add Label', 'Add Tag', 'Add Flash Sale',
            'Add Testimonial', 'Add FAQs', 'Create Coupon',
        ] as $key) {
            $this->assertArrayHasKey($key, $ar, "resources/lang/ar.json has no '{$key}'");
            $this->assertNotSame('', trim((string) $ar[$key]), "'{$key}' is empty in ar.json");
            $this->assertNotSame($key, $ar[$key], "'{$key}' in ar.json is still the English key");
        }

        /* And each of those strings is really the one the markup makes visible. */
        foreach ([
            'resources/views/product_brand/index.blade.php' => 'Add Brand',
            'resources/views/product_label/index.blade.php' => 'Add Label',
            'resources/views/tag/index.blade.php' => 'Add Tag',
            'resources/views/coupon/index.blade.php' => 'Create Coupon',
            'resources/views/flash-sale/index.blade.php' => 'Add Flash Sale',
            'resources/views/testimonial/index.blade.php' => 'Add Testimonial',
            'resources/views/faq/index.blade.php' => 'Add FAQs',
        ] as $relative => $label) {
            $this->assertStringContainsString(
                "<span>{{ __('" . $label . "') }}</span>",
                $this->region($relative),
                $relative . ": the primary action does not make '{$label}' visible"
            );
        }
    }
}
