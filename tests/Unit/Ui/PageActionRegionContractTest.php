<?php

namespace Tests\Unit\Ui;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * D-25 STEP 2 — the shared `action-button` page-header region.
 * ===========================================================
 *
 * WHAT THE OWNER APPROVED. *"Step 2 — shared action buttons: convert the export button and its
 * three neighbours as one unit, changing no action behaviour and breaking no form submission or
 * Bootstrap/JavaScript contract."* That region is `@yield('action-button')` in
 * `layouts/app.blade.php`, filled per screen; the four controls are the order list's Create Order,
 * Export, Grid View and Board View. `order/grid.blade.php` carries the same export control and its
 * neighbours on the same screen in its other view mode, so it is converted with them — a merchant
 * toggling the view would otherwise cross between two visual languages. **Forty-nine more
 * first-party views have an `action-button` region with these classes and are NOT touched**: the
 * decision says the page shell follows *"only if that first step measures clean"*.
 *
 * WHAT WAS WRONG, measured by `capture-page-actions-contract.cjs` in 16 captures (en/ar ×
 * light/dark × desktop/phone × two screens) BEFORE the change:
 *
 *   - **56 of the controls' accessible names were an icon-font glyph.** Not empty — Chromium
 *     includes the icon font's generated `::before` content in the name computation, so
 *     `<i class="ti ti-file-export">` named the export control `"\uede9"`, a private-use
 *     codepoint from Tabler. Create Order read `"\ueb0b Create Order"`. A screen reader announces
 *     that as nothing useful, and no amount of source reading finds it: the name exists, it is
 *     just gibberish. `aria-hidden="true"` takes the glyph out of the computation and `aria-label`
 *     supplies the real name. After: `Create Order`, `Export`, `Grid View`, `Board View`.
 *   - **Every icon measured 2.59:1 in dark mode**, in all 8 dark captures, against the 3:1
 *     non-text minimum (WCAG 1.4.11) — white on the brand green, the same figure F9 recorded for
 *     the selected sidebar item. Light mode passed at 6.92:1, so the failure only existed in one
 *     theme. After: 8.92:1 on the order list and 11.1:1 on the grid.
 *
 * WHAT MAY NOT MOVE, and what this file therefore pins. Two of these controls' contracts are
 * invisible in the markup:
 *
 *   - **`.export-btn` and `filename`.** `public/js/loader.js:116` binds `.export-btn` with a
 *     delegated handler that preventDefaults the click, fetches the href as a blob and names the
 *     download `this.getAttribute('filename') + '.xlsx'`. Rename the class and the control still
 *     looks and navigates fine while the download stops working; drop the attribute and the file
 *     lands as `Export.xlsx`.
 *   - **`data-bs-toggle="tooltip"`.** The tooltip is the name a sighted mouse user gets, and
 *     removing the toggle silently removes it.
 *
 * The browser contract — the real click, the computed names, the contrast, the geometry in all
 * four configurations and both viewports — is `capture-page-actions-contract.cjs`. This file pins
 * the parts that are statements about the source.
 */
class PageActionRegionContractTest extends TestCase
{
    use StripsSourceComments;

    private const VIEWS = [
        'resources/views/order/index.blade.php',
        'resources/views/order/grid.blade.php',
    ];

    /** The routes the region's controls target. A conversion must not change where they go. */
    private const ROUTES = [
        'resources/views/order/index.blade.php' => ['order.create', 'order.export', 'order.grid', 'order.kanban'],
        'resources/views/order/grid.blade.php' => ['order.export', 'order.index', 'order.kanban'],
    ];

    private const BOOTSTRAP_BUTTON = [
        'btn', 'btn-sm', 'btn-lg', 'btn-icon', 'btn-badge',
        'btn-info', 'btn-warning', 'btn-danger', 'btn-primary', 'btn-secondary', 'btn-success',
        'btn-light', 'btn-dark',
    ];

    /**
     * The `@section('action-button')` body, and nothing else in the file.
     *
     * SCOPED DELIBERATELY. `order/index.blade.php` is 600-odd lines and its `@section('content')`
     * holds its own buttons; a whole-file assertion would report those as unconverted and would
     * also pass for free if the section were deleted. The premise below refuses an empty or
     * missing section for that reason.
     */
    private function region(string $relative): string
    {
        $source = $this->sourceWithoutComments($relative);

        $start = strpos($source, "@section('action-button')");
        $this->assertNotFalse($start, $relative . ": no @section('action-button')");

        $end = strpos($source, '@endsection', $start);
        $this->assertNotFalse($end, $relative . ': the action-button section is never closed');

        $region = substr($source, $start, $end - $start);

        $this->assertGreaterThan(
            200,
            strlen($region),
            $relative . ': the action-button section is too short to hold its controls — every'
            . ' assertion below would pass for free'
        );

        return $region;
    }

    /**
     * Each control as [opening tag, attributes, inner HTML].
     *
     * THE INNER HTML IS WHY THIS MATCHES THE WHOLE ELEMENT. A control whose own visible text names
     * it needs no `aria-label` — Create Order is one — and that cannot be decided from the opening
     * tag alone. The first version of this helper returned only the opening tag, so the
     * named-control count came out at 6 of 7 and the premise failed on a region that is correct.
     *
     * @return list<array{string, string, string}>
     */
    private function controls(string $region): array
    {
        preg_match_all(
            '/<(a|button)\b((?:[^<>"\']|"[^"]*"|\'[^\']*\')*?)>(.*?)<\/\1>/s',
            $region,
            $tags,
            PREG_SET_ORDER
        );

        return array_map(
            static fn (array $m): array => ['<' . $m[1] . $m[2] . '>', $m[2], $m[3]],
            $tags
        );
    }

    public function test_every_control_in_the_region_has_an_accessible_name(): void
    {
        $unnamed = [];
        $named = 0;

        foreach (self::VIEWS as $relative) {
            foreach ($this->controls($this->region($relative)) as [$tag, $attrs, $inner]) {
                if (str_contains($attrs, 'aria-label=') && ! preg_match('/\baria-label="\s*"/', $attrs)) {
                    $named++;
                    continue;
                }

                /*
                 * A control whose own VISIBLE TEXT names it needs no `aria-label`, and should not
                 * have one: a label that can drift from the visible text is a 2.5.3 problem of its
                 * own. Create Order is the only such control here. What counts as text is a
                 * translated echo OUTSIDE the icon element — the icon's glyph is not text, and
                 * treating it as one is precisely the defect Step 2 fixed.
                 */
                $withoutIcons = (string) preg_replace('/<i\b[^>]*>.*?<\/i>|<i\b[^>]*\/?>/s', '', $inner);
                if (preg_match('/\{\{\s*__\(/', $withoutIcons)) {
                    $named++;
                    continue;
                }

                $unnamed[] = $relative . ': ' . trim(preg_replace('/\s+/', ' ', substr($tag, 0, 120)));
            }
        }

        $this->assertGreaterThanOrEqual(7, $named, 'fewer named controls than the seven this slice converted');

        $this->assertSame(
            [],
            $unnamed,
            "a page-header action control has no accessible name.\n"
            . "This is the defect Step 2 fixed, and it did NOT read as a missing name: Chromium\n"
            . "included the icon font's generated content, so the name was a private-use glyph\n"
            . "like \"\\uede9\" — present, and gibberish.\n  " . implode("\n  ", $unnamed)
        );
    }

    public function test_every_icon_in_the_region_is_hidden_from_assistive_technology(): void
    {
        $exposed = [];
        $hidden = 0;

        foreach (self::VIEWS as $relative) {
            /* Any whitespace and any extra class token, because requiring `class` to come first
               and to end after the icon name is how three icons elsewhere in this cycle stayed
               announced through both a converter and its test. */
            preg_match_all('/<i\s+class="ti ti-[a-z0-9-]+[^"]*"([^>]*)>/', $this->region($relative), $icons, PREG_SET_ORDER);

            foreach ($icons as $icon) {
                if (str_contains($icon[1], 'aria-hidden="true"')) {
                    $hidden++;
                    continue;
                }

                $exposed[] = $relative . ': ' . $icon[0];
            }
        }

        $this->assertGreaterThanOrEqual(7, $hidden, 'fewer decorative icons than the region holds');

        $this->assertSame(
            [],
            $exposed,
            "an icon in the page-header region is still in the accessible-name computation.\n"
            . "Chromium reads the icon font's `::before` content, so an exposed icon does not\n"
            . "merely add noise — it BECOMES the control's name.\n  " . implode("\n  ", $exposed)
        );
    }

    public function test_the_region_carries_no_bootstrap_button_class(): void
    {
        $left = [];

        foreach (self::VIEWS as $relative) {
            preg_match_all('/\bclass="([^"]*)"/', $this->region($relative), $attrs);

            foreach ($attrs[1] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) ?: [] as $class) {
                    if (in_array($class, self::BOOTSTRAP_BUTTON, true)) {
                        $left[] = $relative . ': class="' . $attr . '"';
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $left,
            "a bootstrap button class survived Step 2.\n"
            . "Bootstrap's `.btn` sets its own padding, radius and background, so a control\n"
            . "carrying both renders as neither.\n  " . implode("\n  ", $left)
        );
    }

    public function test_the_export_control_keeps_its_javascript_handler_contract(): void
    {
        /* MEASURED, not assumed: `public/js/loader.js` is where the binding lives, and this
           asserts the handler is still looking for what the view still provides. If the handler
           is rewritten to read something else, this fails and the view is updated with it. */
        $loader = (string) file_get_contents(base_path('public/js/loader.js'));

        $this->assertStringContainsString(
            ".export-btn",
            $loader,
            'public/js/loader.js no longer binds `.export-btn`, so the class in the views is dead'
        );
        $this->assertStringContainsString(
            "getAttribute('filename')",
            $loader,
            'the export handler no longer names the download from the `filename` attribute'
        );

        foreach (self::VIEWS as $relative) {
            $region = $this->region($relative);

            $exports = array_values(array_filter(
                $this->controls($region),
                static fn (array $c): bool => str_contains($c[1], 'export-btn')
            ));

            $this->assertCount(
                1,
                $exports,
                $relative . ': expected exactly one `.export-btn` in the page-header region'
            );

            [$tag, $attrs] = $exports[0];

            $this->assertMatchesRegularExpression(
                '/\bfilename="\{\{\s*__\(/',
                $attrs,
                $relative . ": the export control lost its translated `filename` attribute, so the\n"
                . "download lands as `Export.xlsx` — loader.js reads that attribute verbatim:\n"
                . trim($tag)
            );

            $this->assertStringContainsString(
                "route('order.export')",
                $attrs,
                $relative . ': the export control no longer points at `order.export`'
            );
        }
    }

    public function test_every_control_keeps_its_route_and_its_tooltip(): void
    {
        foreach (self::VIEWS as $relative) {
            $region = $this->region($relative);

            preg_match_all("/route\(\s*'([^']+)'/", $region, $routes);
            $found = array_values(array_unique($routes[1]));
            sort($found);

            $expected = self::ROUTES[$relative];
            sort($expected);

            $this->assertSame(
                $expected,
                $found,
                $relative . ': the set of routes the page-header controls target has changed.'
                . ' Step 2 restyles and names these controls; it must not move where they go.'
            );

            /* The tooltip is the name a sighted mouse user gets. Dropping the toggle removes it
               silently -- the markup still carries a `title`, which no longer shows. */
            $controls = $this->controls($region);
            $withTooltip = array_filter(
                $controls,
                static fn (array $c): bool => str_contains($c[1], 'data-bs-toggle="tooltip"')
            );

            $this->assertCount(
                count($controls) - ($relative === self::VIEWS[0] ? 1 : 0),
                $withTooltip,
                $relative . ': a control lost `data-bs-toggle="tooltip"`. On the order list the'
                . ' Create Order button has never had one (its label is visible text), which is'
                . ' why the expected count is one lower there.'
            );
        }
    }

    public function test_the_region_uses_only_design_system_classes_that_exist(): void
    {
        $css = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        $used = [];
        foreach (self::VIEWS as $relative) {
            preg_match_all('/\bclass="([^"]*)"/', $this->region($relative), $attrs);

            foreach ($attrs[1] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) ?: [] as $class) {
                    if (str_starts_with($class, 'ss-')) {
                        $used[$class] = true;
                    }
                }
            }
        }

        $this->assertNotSame([], $used, 'the region uses no DS class at all, so this proves nothing');

        /* The hierarchy Step 2 chose, asserted by name: ONE primary action and the utilities
           outlined. Four brand-filled buttons in a row means no primary action at all, and the
           outlined pair is also what fixed the dark-mode icon failure -- `--ss-fg` on
           `--ss-surface` is the DS's own AA-checked pair. */
        $this->assertArrayHasKey('ss-btn--primary', $used, 'the region has no primary action');
        $this->assertArrayHasKey('ss-btn--secondary', $used, 'the utility controls are not outlined');

        $undefined = [];
        foreach (array_keys($used) as $class) {
            if (! str_contains($css, '.' . $class)) {
                $undefined[] = $class;
            }
        }

        $this->assertSame(
            [],
            $undefined,
            'a design-system class is used in the page-header region and defined in no stylesheet,'
            . ' so the control renders unstyled: ' . implode(', ', $undefined)
        );
    }

    public function test_the_region_wrapper_spaces_its_controls(): void
    {
        /*
         * WHY THIS IS ASSERTED AND NOT LEFT TO LOOK RIGHT. `.page-header-icon` is
         * `d-flex flex-wrap gap-2`, but these two screens wrap their controls in their own div --
         * so that div is the region's ONLY flex child and the region's gap never reaches the
         * controls. Before Step 2 the spacing came from `me-2` on Create Order plus the ~3.5px of
         * HTML whitespace between inline-flex siblings; dropping `me-2` for the DS left the
         * primary action ~3.5px from Export, MEASURED as the region going 238 -> 235 wide.
         *
         * The repo's own prevailing pattern for this wrapper is already a flex row with a gap
         * (`product/index`, `coupon/index`, `tag/index`), so these two were the outliers.
         */
        foreach (self::VIEWS as $relative) {
            $region = $this->region($relative);

            $this->assertMatchesRegularExpression(
                '/<div class="[^"]*\bd-flex\b[^"]*\bgap-2\b[^"]*"/',
                $region,
                $relative . ': the action-button wrapper is not a flex row with a gap, so its'
                . ' controls are spaced only by the HTML whitespace between them'
            );

            $this->assertStringNotContainsString(
                'ss-btn--sm me-2',
                $region,
                $relative . ': a per-control margin is back alongside the wrapper gap, which gives'
                . ' one control twice the spacing of the others'
            );
        }
    }
}
