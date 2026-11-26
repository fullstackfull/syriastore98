<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-179 / P06-06 slice 2 — THE SUBSCRIPTION PLAN SCREENS AND THE COUNTRY GRID'S TOOLS.
 * ================================================================================================
 *
 * Slice 1 established the operator surface and closed the host primary button, the Add-on
 * Manager's status chip and `/platform/resources`' path ink. This is the next 90 findings, and
 * every repair below REUSES A CLASS THE DESIGN SYSTEM ALREADY OWNS. Nothing here is a new colour
 * decision; where the design system had nothing, the gap is recorded rather than invented.
 *
 * ------------------------------------------------------------------------------------------------
 *  WHY `.text-danger` CANNOT BE REPAIRED FROM THE STYLESHEET — the decision is already recorded
 * ------------------------------------------------------------------------------------------------
 *
 * `store-syria-ds.css` (P2.5-MONEY and P2.5-WAVE-33) measured this and concluded it twice:
 *
 *   - `style.css` / `style-dark.css` declare `.text-danger { color: ... !important }`;
 *   - `public/css/custom.css` and `rtl-custom.css` declare `.text-danger { color: red !important }`;
 *   - `layouts/app.blade.php` loads custom.css AFTER `#main-style-link`.
 *
 * Two author `!important` declarations of equal specificity: the later wins, so plain `red`
 * renders. An `!important` of our own on a selector the host owns would repaint `.text-danger` in
 * all 70 vendored WorkDo modules and fail `DesignSystemStylesheetScopeTest`'s confinement
 * property. **So the markup changes.** `FinanceMoneyFigureContrastTest` and
 * `FinanceStatusBadgeContrastTest` reached the same conclusion for the money figures and the
 * status pills; this file applies it to the plan screens.
 *
 * ------------------------------------------------------------------------------------------------
 *  THE SIX REPAIRS, each with the measurement that forced it
 * ------------------------------------------------------------------------------------------------
 *
 *  1. `.text-danger` -> `.ss-text-danger`, 56 findings on three surfaces. `rgb(255,0,0)` is
 *     **4.00:1** on a white row and **3.56:1** on the host dark card — it fails in BOTH themes,
 *     so this is not a dark-mode repair. `--ss-danger-fg` is 9.12:1 and 7.48:1 on those grounds.
 *
 *  2. The plan card's three figures, `<span class="h5">` -> `.ss-text-strong`, and their labels,
 *     `<span class="d-block text-sm">` -> `.ss-muted`: 12 findings. Both compute
 *     `rgb(128,129,145)` on the dark card = **3.71:1**. `.ss-text-strong` was authored for
 *     literally this number on the order detail page ("a VALUE, not a heading, measured at 3.71:1
 *     in dark mode where `.h6 .text-sm` leaves it"). THE VALUE AND THE LABEL TAKE DIFFERENT
 *     CLASSES ON PURPOSE: giving both `--ss-fg` would make "Unlimited" and "Products" the same
 *     weight and tone and flatten the one hierarchy the card has.
 *
 *  3. The INCLUDED feature rows, `<li>` with no colour class: 2 findings. They inherit
 *     `rgb(51,93,168)` — the brand blue — which is **2.22:1** on the dark card. `.ss-text-strong`
 *     gives them the body ink. **Their icons are NOT touched and that is measured, not assumed:**
 *     `.text-primary` computes `rgb(120,148,198)` there, which is **4.65:1** on the same card and
 *     passes both the 3:1 non-text floor and 4.5:1.
 *
 *  4. The storage unit suffix, `<span class="input-group-text bg-transparent">MB</span>` ->
 *     `.ss-input-group__suffix`: 4 findings on two surfaces. `bg-transparent` leaves the box with
 *     no surface of its own, so the body's default ink lands on the card: `rgb(41,50,64)` on
 *     `rgb(41,42,51)` = **1.10:1**. The component already exists (`setting/shopify_setting` uses
 *     it) and pairs `--ss-fg-muted` with `--ss-surface-sunken`.
 *
 *  5. The plan card's Edit control, `a.btn.btn-sm.btn-info` ->
 *     `.ss-btn .ss-btn--sm .ss-btn--icon .ss-btn--ghost`: 2 findings. The button's own ink is
 *     legible, but the `<i>` inside is painted WHITE on the host's pale `rgb(232,248,255)` fill =
 *     **1.09:1** against the 3:1 non-text floor — an invisible glyph on the only edit affordance
 *     on the card. `plans/action.blade.php` already uses the ghost shape for this screen's own
 *     row actions, so the repair is the file next door's.
 *
 *  6. `/countries`' three DataTables tools: 12 findings. `.ss-dt-card-body` already repairs
 *     exactly `buttons-reset`, `buttons-reload` and `bulk-delete` on the merchant grids, with
 *     `!important` because `.btn-light-*` sets those properties. The wrapper is added to the three
 *     `card-body table-border-style` divs, which is the merchant precedent verbatim.
 *
 * ------------------------------------------------------------------------------------------------
 *  AND ONE DEFECT THIS SLICE FOUND BY ADDING A SURFACE RATHER THAN BY LOOKING HARDER
 * ------------------------------------------------------------------------------------------------
 *
 * `/plan/{id}/edit` was not one of slice 1's 24 surfaces, so `plans/edit.blade.php` was never
 * measured — and it still carried an `input.btn.btn-primary.ms-2` "Update" at 2.58:1, the very
 * defect slice 1 existed to close, plus an unconverted AI button. Comment-stripped it is
 * `create.blade.php` with different indentation. Both the live harness and
 * `SuperAdminPremiumSurfaceTest::OPERATOR_VIEWS` carry it now, and adding it to that list made
 * slice 1's own ratchet fail and name both controls.
 *
 * Every ratio here is computed twice: by this file from the token values, and by
 * `tests/Support/bin/measure-superadmin-premium-live.cjs` against the running admin as
 * `operator@example.test` in all four theme/direction combinations with the dark premise asserted.
 */
class PlanScreenLegibilityTest extends TestCase
{
    use StripsSourceComments;

    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const CUSTOM_SHEET = 'public/css/custom.css';
    private const RTL_CUSTOM_SHEET = 'public/css/rtl-custom.css';

    private const COUNTRY_VIEW = 'resources/views/country/index.blade.php';

    private const PLAN_VIEWS = [
        'resources/views/plans/index.blade.php',
        'resources/views/plans/create.blade.php',
        'resources/views/plans/edit.blade.php',
    ];

    /** Grounds, measured in the running admin rather than taken from a palette. */
    private const WHITE_ROW = [255, 255, 255];
    private const DARK_CARD = [41, 42, 51];   // #292a33, the host's dark card
    private const DARK_PAGE = [34, 36, 44];

    /** What renders today. */
    private const HOST_RED = [255, 0, 0];           // custom.css's `red`
    private const HOST_MUTED = [128, 129, 145];     // the dark theme's own grey on .h5 / .text-sm
    private const HOST_BRAND = [51, 93, 168];       // inherited on an included feature row
    private const BODY_DEFAULT_DARK = [41, 50, 64]; // the body ink `bg-transparent` exposes
    private const BTN_INFO_FILL = [232, 248, 255];  // the host's pale info fill in dark
    private const GLYPH_WHITE = [255, 255, 255];
    private const INCLUDED_ICON = [120, 148, 198];  // .text-primary on the dark card — PASSES

    /** The design system's values, read from the sheet by the premise tests below. */
    private const DANGER_FG_LIGHT = [140, 29, 23];   // --ss-red-800   #8c1d17
    private const DANGER_FG_DARK = [243, 169, 164];  // #f3a9a4
    private const FG_LIGHT = [26, 29, 35];           // --ss-grey-900  #1a1d23
    private const FG_DARK = [232, 234, 237];         // #e8eaed
    private const FG_MUTED_LIGHT = [91, 95, 102];    // --ss-grey-600  #5b5f66
    private const FG_MUTED_DARK = [167, 171, 178];   // #a7abb2
    private const SURFACE_SUNKEN_LIGHT = [238, 239, 241]; // --ss-grey-100 #eeeff1
    private const SURFACE_SUNKEN_DARK = [31, 35, 42];     // #1f232a

    /** `.ss-dt-card-body`'s tool tokens. */
    private const DT_TOOL_FG_LIGHT = [71, 80, 105];    // #475069
    private const DT_TOOL_FG_DARK = [198, 204, 219];   // #c6ccdb
    private const DT_DANGER_LIGHT = [180, 35, 24];     // #b42318
    private const DT_DANGER_DARK = [255, 155, 147];    // #ff9b93

    private const AA = 4.5;
    private const NON_TEXT = 3.0;

    // =============================================================================================
    //  PREMISES — the mechanism, and the fact that a stylesheet repair is impossible
    // =============================================================================================

    public function test_premise_two_host_sheets_declare_text_danger_as_plain_red_with_important(): void
    {
        /* PROPERTY-LEVEL, NOT TOKEN-PRESENCE. A mutation in slice 1 survived because an assertion
           looked for a token anywhere in a block while the same token sat on a second property.
           Here the declaration itself is matched: the property, the value AND the !important. */
        foreach ([self::CUSTOM_SHEET, self::RTL_CUSTOM_SHEET] as $sheet) {
            $this->assertMatchesRegularExpression(
                '/\.text-danger\s*\{[^}]*\bcolor:\s*red\s*!important/',
                (string) file_get_contents($this->path($sheet)),
                "{$sheet} no longer declares `.text-danger { color: red !important }`. If the host "
                . 'has been repaired, re-measure before trusting this file — the whole argument for '
                . 'changing the markup instead of the stylesheet rests on this declaration.'
            );
        }
    }

    public function test_premise_the_layout_loads_custom_css_after_the_main_style_link(): void
    {
        /* The second half of the mechanism: equal specificity, equal !important, later wins. */
        $layout = (string) file_get_contents($this->path('resources/views/layouts/app.blade.php'));

        $mainStyle = strpos($layout, 'id="main-style-link"');
        $custom = strpos($layout, 'css/custom.css');

        $this->assertIsInt($mainStyle);
        $this->assertIsInt($custom);
        $this->assertGreaterThan(
            $mainStyle,
            $custom,
            'custom.css is no longer loaded after #main-style-link, so `red` may no longer be what '
            . 'renders. Re-measure.'
        );
    }

    public function test_premise_the_host_red_fails_in_both_themes(): void
    {
        $light = $this->ratio(self::HOST_RED, self::WHITE_ROW);
        $dark = $this->ratio(self::HOST_RED, self::DARK_CARD);

        $this->assertSame(4.0, round($light, 2), 'red on #ffffff is not 4.00:1.');
        $this->assertSame(3.56, round($dark, 2), 'red on #292a33 is not 3.56:1.');
        $this->assertLessThan(self::AA, $light, 'The light theme passes, so this is a dark-only fix after all.');
        $this->assertLessThan(self::AA, $dark);
    }

    public function test_premise_every_other_colour_this_slice_replaces_really_fails(): void
    {
        foreach ([
            'the plan card figures and labels' => [self::HOST_MUTED, self::DARK_CARD, 3.71, self::AA],
            'an included feature row' => [self::HOST_BRAND, self::DARK_CARD, 2.22, self::AA],
            'the storage unit suffix' => [self::BODY_DEFAULT_DARK, self::DARK_CARD, 1.1, self::AA],
            'the Edit glyph' => [self::GLYPH_WHITE, self::BTN_INFO_FILL, 1.09, self::NON_TEXT],
            'the grid delete tool glyph' => [[255, 58, 110], [255, 216, 226], 2.66, self::NON_TEXT],
            'the grid reset tool glyph' => [[62, 201, 214], [216, 244, 247], 1.73, self::NON_TEXT],
            'the grid reload tool glyph' => [[255, 162, 29], [255, 236, 210], 1.74, self::NON_TEXT],
        ] as $what => [$fg, $bg, $expected, $floor]) {
            $measured = $this->ratio($fg, $bg);

            $this->assertSame($expected, round($measured, 2), "{$what} is not {$expected}:1.");
            $this->assertLessThan($floor, $measured, "{$what} does not actually fail its floor.");
        }
    }

    public function test_premise_the_included_feature_icon_passes_and_is_therefore_not_touched(): void
    {
        /* A NEGATIVE RESULT, asserted so it cannot quietly become a "fix" somebody takes credit
           for. `.text-primary` on an included row computes rgb(120,148,198) on the dark card. */
        $measured = $this->ratio(self::INCLUDED_ICON, self::DARK_CARD);

        $this->assertSame(4.65, round($measured, 2));
        $this->assertGreaterThanOrEqual(self::NON_TEXT, $measured);
        $this->assertGreaterThanOrEqual(self::AA, $measured);
    }

    // =============================================================================================
    //  PREMISES — the replacements are declared, per property, in tokens
    // =============================================================================================

    /**
     * @dataProvider designSystemDeclarations
     */
    public function test_premise_the_design_system_declares_each_replacement_per_property(
        string $selector,
        array $declarations
    ): void {
        $block = $this->cssBlock($selector);

        foreach ($declarations as $property => $value) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($property, '/') . ':\s*' . preg_quote($value, '/') . '\s*;/',
                $block,
                "`{$selector}` does not declare `{$property}: {$value}`. A token present on some "
                . 'OTHER property in the same block is not this assertion — that is exactly how a '
                . 'mutation survived in slice 1.'
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{3,8}/',
            $block,
            "`{$selector}` hard-codes a colour. A literal cannot re-theme, and the rule it replaces "
            . 'fails in exactly one theme for exactly that reason.'
        );
    }

    public static function designSystemDeclarations(): array
    {
        return [
            '.ss-text-danger' => ['.ss-text-danger', ['color' => 'var(--ss-danger-fg)']],
            '.ss-text-strong' => ['.ss-text-strong', ['color' => 'var(--ss-fg)']],
            '.ss-muted' => ['.ss-muted', ['color' => 'var(--ss-fg-muted)']],
            '.ss-input-group__suffix' => ['.ss-input-group__suffix', [
                'color' => 'var(--ss-fg-muted)',
                'background' => 'var(--ss-surface-sunken)',
            ]],
            '.ss-btn--ghost' => ['.ss-btn--ghost', ['color' => 'var(--ss-fg-muted)']],
        ];
    }

    public function test_premise_every_replacement_pair_passes_its_floor_in_both_themes(): void
    {
        foreach ([
            'ss-text-danger light' => [self::DANGER_FG_LIGHT, self::WHITE_ROW, self::AA],
            'ss-text-danger dark' => [self::DANGER_FG_DARK, self::DARK_CARD, self::AA],
            'ss-text-strong light' => [self::FG_LIGHT, self::WHITE_ROW, self::AA],
            'ss-text-strong dark' => [self::FG_DARK, self::DARK_CARD, self::AA],
            'ss-muted light' => [self::FG_MUTED_LIGHT, self::WHITE_ROW, self::AA],
            'ss-muted dark' => [self::FG_MUTED_DARK, self::DARK_CARD, self::AA],
            'suffix light' => [self::FG_MUTED_LIGHT, self::SURFACE_SUNKEN_LIGHT, self::AA],
            'suffix dark' => [self::FG_MUTED_DARK, self::SURFACE_SUNKEN_DARK, self::AA],
            'ghost glyph light' => [self::FG_MUTED_LIGHT, self::WHITE_ROW, self::NON_TEXT],
            'ghost glyph dark' => [self::FG_MUTED_DARK, self::DARK_CARD, self::NON_TEXT],
            'grid tool light' => [self::DT_TOOL_FG_LIGHT, self::WHITE_ROW, self::NON_TEXT],
            'grid tool dark' => [self::DT_TOOL_FG_DARK, self::DARK_CARD, self::NON_TEXT],
            'grid delete tool light' => [self::DT_DANGER_LIGHT, self::WHITE_ROW, self::NON_TEXT],
            'grid delete tool dark' => [self::DT_DANGER_DARK, self::DARK_CARD, self::NON_TEXT],
        ] as $what => [$fg, $bg, $floor]) {
            $this->assertGreaterThanOrEqual(
                $floor,
                $this->ratio($fg, $bg),
                "The replacement for {$what} does not reach its own floor, so it cannot be the repair."
            );
        }
    }

    public function test_premise_the_declared_token_values_are_the_sheets_own(): void
    {
        $sheet = (string) file_get_contents($this->path(self::DS_SHEET));

        foreach ([
            '--ss-red-800:   #8c1d17', '--ss-danger-fg:  #f3a9a4',
            '--ss-grey-900: #1a1d23', '--ss-fg:        #e8eaed',
            '--ss-grey-600: #5b5f66', '--ss-fg-muted:  #a7abb2',
            '--ss-grey-100: #eeeff1', '--ss-surface-sunken: #1f232a',
            '--ss-dt-tool-fg: #475069', '--ss-dt-tool-fg: #c6ccdb',
            '--ss-dt-tool-danger: #b42318', '--ss-dt-tool-danger: #ff9b93',
        ] as $declaration) {
            $this->assertStringContainsString(
                $declaration,
                $sheet,
                "store-syria-ds.css no longer declares `{$declaration}`; every figure in this file "
                . 'computed from it is stale.'
            );
        }
    }

    public function test_premise_the_grid_block_still_repairs_the_three_tools_with_important(): void
    {
        /* Reuse is only reuse if the block really still does the work. `.btn-light-*` sets these
           properties, so without !important the wrapper would change nothing. */
        $sheet = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($this->path(self::DS_SHEET)));

        foreach (['buttons-reset', 'buttons-reload'] as $tool) {
            $this->assertMatchesRegularExpression(
                '/\.ss-dt-card-body \.dt-buttons \.' . $tool . '[^{]*\{[^}]*color:\s*var\(--ss-dt-tool-fg\)\s*!important/',
                $sheet,
                "`.ss-dt-card-body` no longer repaints `.{$tool}` with !important, so adding the "
                . 'wrapper to /countries would change nothing.'
            );
        }

        $this->assertMatchesRegularExpression(
            '/\.ss-dt-card-body \.bulk-delete\s*\{[^}]*color:\s*var\(--ss-dt-tool-danger\)\s*!important/',
            $sheet,
            '`.ss-dt-card-body .bulk-delete` no longer repaints the delete tool with !important.'
        );
    }

    // =============================================================================================
    //  THE RATCHETS
    // =============================================================================================

    public function test_no_plan_screen_paints_text_in_the_hosts_unrepairable_danger_utility(): void
    {
        $offenders = [];

        foreach (self::PLAN_VIEWS as $view) {
            foreach ($this->elementsWithClass($view, 'text-danger') as $element) {
                $offenders[] = sprintf('%s:%d <%s class="%s">',
                    $view, $element['line'], $element['tag'], $element['class']);
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "%d element(s) on the plan screens still carry `.text-danger`, which renders plain "
            . "`red` — 4.00:1 on a white row and 3.56:1 on the dark card, failing in BOTH themes. "
            . "It cannot be repaired from a stylesheet (see this file's docblock); use "
            . "`.ss-text-danger`:\n  %s",
            count($offenders), implode("\n  ", $offenders)
        ));
    }

    /**
     * The companion to the ratchet above: deleting the class satisfies "no `.text-danger`" too,
     * and would leave an excluded plan feature indistinguishable from an included one.
     *
     * COUNTED PER VIEW, AND A MUTATION IS WHY. The first version asserted only that the string
     * `ss-text-danger` appeared somewhere in each file — so removing ONE of `plans/edit`'s four
     * notes left three behind, the assertion still matched, and the mutation SURVIVED. The same
     * fault as the label assertion two tests below, found the same way.
     *
     * The numbers are the elements each screen really draws: `plans/index` has seven excluded
     * feature rows, each with a label span AND an icon, so fourteen; the two forms carry four
     * notes each.
     */
    public function test_the_plan_screens_name_the_design_systems_danger_tone_on_every_element(): void
    {
        foreach ([
            'resources/views/plans/index.blade.php' => 14,
            'resources/views/plans/create.blade.php' => 4,
            'resources/views/plans/edit.blade.php' => 4,
        ] as $view => $expected) {
            $this->assertSame(
                $expected,
                preg_match_all('/class="ss-text-danger[ "]/', $this->sourceWithoutComments($view)),
                "{$view} does not carry {$expected} `.ss-text-danger` elements. Removing the host "
                . 'class without putting the design system tone in its place removes the meaning '
                . 'rather than making it legible — an excluded feature then looks included.'
            );
        }
    }

    /**
     * PREMISE, AND IT IS THE MOST IMPORTANT THING THIS SLICE LEARNED.
     *
     * The first repair put the tone classes straight onto the elements that were failing, and TWO
     * OF THE THREE WERE INERT. The re-measurement came back with the same colours and the same
     * ratios, now reported under `.ss-text-danger` and `.ss-muted` selectors — which is the
     * clearest way a run can tell you a class lost the cascade. Measured in the browser, rule by
     * rule, rather than reasoned:
     *
     *   `.plan-card-detail li`                                (0,1,1) `color: var(--theme-color)`
     *       outranks `.ss-text-danger` / `.ss-text-strong` (0,1,0), so a tone ON THE <li> never
     *       renders and the row keeps the brand blue at 2.22:1.
     *
     *   `.plan_card .card.price-card .card-body span.text-sm` (0,4,1) AND `!important`
     *       outranks `.ss-muted` (0,1,0) twice over, so the label keeps rgb(128,129,145), 3.71:1.
     *
     * `<span class="h5">` is the one that worked unaided: `.h5` is (0,1,0) with no `!important`,
     * so `.ss-text-strong` wins on source order.
     *
     * The remedy is the one `store-syria-ds.css` already prescribes wherever a host rule cannot be
     * beaten — wrap the text in a `<span>` the host selector cannot match. It needs no new CSS and
     * no `!important` of ours. This premise exists so that the next person does not put the class
     * back on the `<li>`, see a tidier diff, and watch nothing happen.
     */
    public function test_premise_two_host_rules_outrank_a_tone_class_on_the_element_itself(): void
    {
        $dark = (string) preg_replace(
            '#/\*.*?\*/#s',
            '',
            (string) file_get_contents($this->path('public/assets/css/style-dark.css'))
        );

        $this->assertMatchesRegularExpression(
            '/\.plan-card-detail li\s*\{[^}]*\bcolor:\s*var\(--theme-color\)/',
            $dark,
            'The host no longer paints `.plan-card-detail li` from --theme-color. If that rule has '
            . 'gone, a tone class on the <li> would work and the span wrapper is redundant — '
            . 'measure before simplifying the markup.'
        );

        /* `[^{]*` because this selector is the FIRST of a comma group — it shares its block with
           `.faq .accordion .accordion-item .accordion-button.collapsed`. A pattern expecting `{`
           straight after the selector failed here, which is a reminder that a grouped selector is
           the normal case in a vendored sheet rather than the exception. */
        $this->assertMatchesRegularExpression(
            '/\.card-body span\.text-sm[^{]*\{[^}]*\bcolor:\s*#808191\s*!important/i',
            $dark,
            'The host no longer declares the plan card label colour as #808191 !important. '
            . 'rgb(128,129,145) is that hex, and it is the figure this slice measured at 3.71:1 — '
            . 're-measure before trusting the inner-span remedy.'
        );
    }

    public function test_the_plan_card_separates_its_figures_from_their_labels(): void
    {
        $source = $this->sourceWithoutComments('resources/views/plans/index.blade.php');

        /*
         * COUNTED, NOT MERELY FOUND — AND A MUTATION IS WHY. The first version asserted that the
         * repaired shape appears SOMEWHERE in the file, so reverting ONE of the three labels left
         * the other two matching and the test passed. "Does this string exist anywhere" is the
         * census trap CLAUDE.md records, in its per-element form: an assertion over a file answers
         * a question about the file, not about every element in it.
         *
         * So both halves are counted against the number of `.h5` figure spans the card actually
         * draws, and against the number of labels beside them. Three columns: Products, Store,
         * Users; eight `.h5` spans, because each column's value is wrapped again inside its own
         * `@if`/`@else`.
         */
        $this->assertSame(
            8,
            preg_match_all('/class="h5 mb-0 ss-text-strong"/', $source),
            'Not every plan card figure carries `.ss-text-strong`. Any that does not computes '
            . 'rgb(128,129,145) on the dark card = 3.71:1. This is the one of the three repairs '
            . 'that needed no wrapper, because `.h5` carries no !important and loses on source order.'
        );
        $this->assertSame(
            0,
            preg_match_all('/class="h5 mb-0"/', $source),
            'A plan card figure span is still unclassed.'
        );

        /* ...and the LABEL takes the muted one through an INNER SPAN, because the host's own
           `span.text-sm` rule is !important and cannot be outranked on that element. */
        $this->assertSame(
            3,
            preg_match_all('/<span class="d-block text-sm"><span class="ss-muted">/', $source),
            'Not all three plan card labels wrap their text in an `.ss-muted` span. Putting '
            . '`.ss-muted` on the `.text-sm` span itself is INERT — measured, the colour did not '
            . 'change at all.'
        );
        $this->assertSame(
            0,
            preg_match_all('/<span class="d-block text-sm[^"]*">(?!<span)/', $source),
            'A `.text-sm` label holds its text directly, where the host`s !important rule wins.'
        );

        /* The two classes must stay on different elements, or the card has no hierarchy left. */
        $this->assertDoesNotMatchRegularExpression(
            '/class="d-block text-sm[^"]*ss-text-strong/',
            $source,
            'A label took the figure class. The value and the label are deliberately different.'
        );
    }

    public function test_every_feature_row_carries_its_tone_on_a_span_and_not_on_the_list_item(): void
    {
        $view = 'resources/views/plans/index.blade.php';
        $source = $this->sourceWithoutComments($view);

        /* No tone on an <li>: it would be inert, and an inert class reads as a finished repair. */
        foreach (['ss-text-danger', 'ss-text-strong'] as $tone) {
            $onListItems = array_values(array_filter(
                $this->elementsWithClass($view, $tone),
                fn (array $e) => $e['tag'] === 'li'
            ));

            $this->assertSame([], $onListItems, sprintf(
                "`.%s` sits on %d <li> element(s), where `.plan-card-detail li` (0,1,1) outranks "
                . "it and it renders nothing. Wrap the row's text in a <span> instead:\n  %s",
                $tone,
                count($onListItems),
                implode("\n  ", array_map(
                    fn (array $e) => $view . ':' . $e['line'] . ' <li class="' . $e['class'] . '">',
                    $onListItems
                ))
            ));
        }

        /* Both tones are present, on spans, so the two states stay distinguishable. */
        $this->assertSame(
            7,
            preg_match_all('/<span class="ss-text-danger">/', $source),
            'There are not seven excluded-feature labels on `.ss-text-danger` spans.'
        );
        $this->assertSame(
            7,
            preg_match_all('/<span class="ss-text-strong">/', $source),
            'There are not seven included-feature labels on `.ss-text-strong` spans.'
        );

        /* And the icons stay as they are — measured at 4.65:1, so repainting them would be churn
           dressed as a fix. */
        $this->assertStringContainsString(
            'text-primary ti ti-circle-plus',
            $source,
            'The included-feature icon was changed. It measures 4.65:1 on the dark card and passes '
            . 'both floors; this slice deliberately does not touch it.'
        );
    }

    public function test_the_storage_unit_suffix_is_a_design_system_component(): void
    {
        foreach (['resources/views/plans/create.blade.php', 'resources/views/plans/edit.blade.php'] as $view) {
            $source = $this->sourceWithoutComments($view);

            $this->assertStringContainsString(
                'ss-input-group__suffix',
                $source,
                "{$view}'s unit suffix is not an `.ss-input-group__suffix`."
            );

            /* `bg-transparent` IS THE DEFECT, not an innocent bystander: it is what leaves the box
               with no surface, so the body's default ink lands on the card at 1.10:1. */
            $this->assertDoesNotMatchRegularExpression(
                '/class="[^"]*\binput-group-text\b[^"]*\bbg-transparent\b[^"]*"/',
                $source,
                "{$view} still has an `input-group-text bg-transparent`."
            );
        }
    }

    public function test_the_plan_cards_edit_control_is_a_design_system_button(): void
    {
        $source = $this->sourceWithoutComments('resources/views/plans/index.blade.php');

        $this->assertStringContainsString(
            'ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost',
            $source,
            'The plan card Edit control is not on the ghost icon button that '
            . '`plans/action.blade.php` already uses for this screen.'
        );

        $this->assertSame(
            [],
            $this->elementsWithClass('resources/views/plans/index.blade.php', 'btn-info'),
            'A `.btn-info` control remains; its glyph renders white on rgb(232,248,255) = 1.09:1.'
        );
    }

    public function test_every_country_grid_is_inside_the_shared_datatable_scope(): void
    {
        $source = $this->sourceWithoutComments(self::COUNTRY_VIEW);

        /* THREE TABS, THREE GRIDS. Wrapping only the one that happens to be open would leave the
           state and city tools exactly as they were and the run would still read clean, because
           a hidden tab is not measured. */
        $bodies = $this->elementsWithClass(self::COUNTRY_VIEW, 'card-body');

        $this->assertCount(3, $bodies, 'country/index.blade.php no longer has exactly three '
            . 'card bodies; re-read it before trusting the count below.');

        foreach ($bodies as $body) {
            $this->assertStringContainsString(
                'ss-dt-card-body',
                $body['class'],
                sprintf('%s:%d <%s class="%s"> is a grid card body outside the shared DataTable '
                    . 'scope, so its tools keep the host`s `.btn-light-*` fills.',
                    self::COUNTRY_VIEW, $body['line'], $body['tag'], $body['class'])
            );
        }

        $this->assertStringContainsString('table-border-style ss-dt-card-body', $source,
            'The wrapper is not in the merchant precedent`s order '
            . '(`card-body table-border-style ss-dt-card-body`).');
    }

    /**
     * THE INLINE-STYLE COLLISION, PINNED RATHER THAN HOPED ABOUT.
     *
     * `country/index.blade.php` carries 92 lines of its own `<style>` in two direction-specific
     * blocks. Measured selector by selector, it declares nine selectors, and exactly ONE of them
     * targets an element `.ss-dt-card-body` also styles: `.ecom-data-table div.dt-buttons`, which
     * declares exactly one property, `width: 100%`, INSIDE `@media (max-width: 767px)`.
     *
     * `.ss-dt-card-body .dt-buttons` declares border, border-radius, padding, gap, display and
     * align-items, and NO width. So the two rules do not fight over any property at all, and
     * above 767px the inline rule does not apply. At phone width the segmented control stretches
     * to the full row — which is the inline rule's existing intent for the un-bordered group, is
     * not a contrast failure, and is left alone rather than out-specificity'd from the design
     * system (`.ecom-data-table div.dt-buttons` is (0,2,1) and would need the DS to name a vendor
     * class to beat it).
     *
     * This test is the ratchet on that reasoning: it fails if anyone adds a declaration to that
     * inline rule, moves it out of its media query, or gives the inline style a new selector that
     * the DS block also owns.
     *
     * IT IS A FORWARD RATCHET, NOT A FAIL-FIRST ONE, and it passes before this slice's change as
     * well as after — it describes the inline style and the design system, neither of which the
     * change touches. That is deliberate: it is the record of WHY the inline style was left
     * alone, in a form that stops being true if somebody edits either side.
     */
    public function test_the_country_views_inline_style_still_collides_on_nothing(): void
    {
        $source = $this->sourceWithoutComments(self::COUNTRY_VIEW);

        preg_match_all('#<style[^>]*>(.*?)</style>#is', $source, $blocks);
        $this->assertCount(2, $blocks[1], 'country/index.blade.php no longer has two <style> '
            . 'blocks (one per writing direction); re-measure the collision.');

        $inlineSelectors = $this->selectorsIn(implode("\n", $blocks[1]));
        $dsSelectors = $this->selectorsIn(
            (string) file_get_contents($this->path(self::DS_SHEET)),
            fn (string $s) => str_contains($s, 'ss-dt-card-body')
        );

        /*
         * COMPARED BY SUBJECT, NOT BY ANY SHARED TOKEN — AND THE FIRST VERSION OF THIS TEST GOT
         * THAT WRONG, which is worth recording because it reported a collision that does not
         * exist. Intersecting every class token in both selectors flagged
         * `.card .card-header .state-set` against
         * `.card:not(.table-card) .ss-dt-card-body table.dataTable > tbody > tr > td`, because
         * both mention `.card`. One styles a card header, the other styles a table cell; sharing
         * an ANCESTOR cannot make two rules fight. Two rules can only collide where they can
         * match the same element, which is their SUBJECT — the last compound in the selector.
         */
        $dsTargets = [];
        foreach ($dsSelectors as $selector) {
            foreach ($this->subjectTokens($selector) as $token) {
                if ($token !== 'ss-dt-card-body') {
                    $dsTargets[$token] = true;
                }
            }
        }

        $shared = [];
        foreach ($inlineSelectors as $selector) {
            foreach ($this->subjectTokens($selector) as $token) {
                if (isset($dsTargets[$token])) {
                    $shared[$selector] = $token;
                }
            }
        }

        $this->assertSame(
            ['.ecom-data-table div.dt-buttons' => 'dt-buttons'],
            $shared,
            "The inline <style> in country/index.blade.php now overlaps `.ss-dt-card-body`'s "
            . "targets somewhere new. The documented overlap is ONE selector on ONE property "
            . "(`width: 100%`) inside `@media (max-width: 767px)`, which the DS block never "
            . 'declares. Re-measure before widening this expectation.'
        );

        /* ...and the one shared selector still declares only `width`, still only on phones. */
        foreach ($blocks[1] as $block) {
            if (! str_contains($block, 'dt-buttons')) {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/@media[^{]*max-width:\s*767px[^{]*\{(?:[^{}]|\{[^{}]*\})*\.ecom-data-table div\.dt-buttons\s*\{\s*width:\s*100%;\s*\}/s',
                $block,
                'The `.dt-buttons` rule in the inline style is no longer a single `width: 100%` '
                . 'inside the 767px media query. That was the whole basis for leaving it alone.'
            );
        }
    }

    // =============================================================================================
    //  helpers
    // =============================================================================================

    private function path(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return $path;
    }

    /**
     * @return list<array{tag: string, class: string, line: int}>
     */
    private function elementsWithClass(string $view, string $token): array
    {
        $source = $this->sourceWithoutComments($view);

        preg_match_all(
            '/<([a-zA-Z][a-zA-Z0-9]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        $found = [];

        foreach ($matches as $match) {
            [, $offset] = $match[0];

            if (! preg_match('/class\s*=\s*"([^"]*)"/', $match[2][0], $class)) {
                continue;
            }

            $tokens = preg_split('/\s+/', trim($class[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (! in_array($token, $tokens, true)) {
                continue;
            }

            $found[] = [
                'tag' => strtolower($match[1][0]),
                'class' => $class[1],
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
            ];
        }

        return $found;
    }

    /**
     * Every selector in a CSS string, at-rule preludes excluded and at-rule BODIES included.
     *
     * The bodies matter here: the one collision in `country/index.blade.php` lives inside
     * `@media (max-width: 767px)`, so a parser that skipped at-rules would report no collision at
     * all and this file would be asserting nothing.
     *
     * @param null|callable(string): bool $keep
     * @return list<string>
     */
    private function selectorsIn(string $css, ?callable $keep = null): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $selectors = [];
        $buffer = '';

        foreach (str_split($css) as $char) {
            if ($char === '{') {
                $prelude = trim($buffer);
                $buffer = '';

                if ($prelude === '' || str_starts_with($prelude, '@')) {
                    continue;
                }

                foreach (explode(',', $prelude) as $one) {
                    $one = trim(preg_replace('/\s+/', ' ', $one) ?? '');

                    if ($one !== '' && ($keep === null || $keep($one))) {
                        $selectors[$one] = true;
                    }
                }

                continue;
            }

            if ($char === '}') {
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        return array_keys($selectors);
    }

    /**
     * The class and element tokens of a selector's SUBJECT — the last compound, i.e. the element
     * the rule actually styles. `.a .b > div.c:hover` yields `div` and `c`.
     *
     * Pseudo-classes and attribute selectors are dropped: `:hover` and `[dir="rtl"]` narrow WHEN a
     * rule applies, never WHAT it applies to, so keeping them would make two rules on the same
     * element look like rules on different ones.
     *
     * @return list<string>
     */
    private function subjectTokens(string $selector): array
    {
        $parts = preg_split('/\s*[>+~]\s*|\s+/', trim($selector), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $subject = (string) end($parts);

        /* `:not(.table-card)` carries a class inside a pseudo — strip the whole pseudo first, or
           `table-card` would be read as part of the subject. */
        $subject = (string) preg_replace('/::?[a-zA-Z-]+(\([^)]*\))?/', '', $subject);
        $subject = (string) preg_replace('/\[[^\]]*\]/', '', $subject);

        $tokens = [];

        foreach (preg_split('/\./', $subject, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $token = trim($token);

            if ($token !== '' && $token !== '*') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    private function cssBlock(string $selector): string
    {
        $sheet = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($this->path(self::DS_SHEET)));

        $pattern = '/(?:^|[\s,}])' . preg_quote($selector, '/') . '\s*(?:,[^{]*)?\{([^}]*)\}/m';

        $this->assertMatchesRegularExpression(
            $pattern,
            $sheet,
            "store-syria-ds.css declares no rule for `{$selector}`."
        );

        preg_match_all($pattern, $sheet, $matches);

        return implode("\n", $matches[1]);
    }

    private function luminance(array $rgb): float
    {
        $channel = static function (float $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    private function ratio(array $a, array $b): float
    {
        $x = $this->luminance($a);
        $y = $this->luminance($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }
}
