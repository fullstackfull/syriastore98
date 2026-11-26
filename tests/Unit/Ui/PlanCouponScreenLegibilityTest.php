<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-179 / P06-06 slice 3 — THE PLATFORM COUPON SCREENS, AND HOW THEY STAYED UNMEASURED.
 * ================================================================================================
 *
 * Slice 1 enumerated 24 operator surfaces from the route table and slice 2 added a 25th
 * (`/plan/{id}/edit`) after noticing that a byte-similar sibling of a repaired view had never been
 * measured. **The same thing had happened twice more on the coupon screens, and for two different
 * reasons.** Both were found by adding the surface, not by looking harder:
 *
 *   `/plan-coupon/{id}/edit`   NOT a Tier C surface, because the Tier C list was written from the
 *                              `data-ajax-popup` triggers visible on an EMPTY grid and the edit
 *                              trigger lives in `plan-coupon/action.blade.php`, which renders only
 *                              once a coupon ROW exists. `seed-p2dev.php` created none. So the
 *                              screen was UNMEASURABLE, which reads exactly like clean.
 *                              It carried `input.btn.btn-badge.btn-primary` "Update" at
 *                              **2.58:1 dark** — the single named defect slice 1 existed to close,
 *                              surviving on a screen slice 1 never opened — plus the same
 *                              `btn-outline-primary` "Generate" at **3.07:1 dark** that slice 1
 *                              recorded on `plan-coupon/create.blade.php` and did not take.
 *
 *   `/plan-coupon/{id}`        NOT a Tier A surface, although it is a `PlatformOperatorOnly` GET
 *                              that renders a page, for the same missing-row reason.
 *                              It is **the only view in the repository that hand-rolls a
 *                              DataTable** instead of rendering `<x-datatable>`: 66 views use the
 *                              component, 1 did not. The component carries `ss-dt-card-body`, and
 *                              every repair in `store-syria-ds.css`'s grid-tool block is scoped
 *                              under that class — so the one view outside the component got the
 *                              host's raw `btn-light-*` tints, at **1.15:1 to 2.66:1** against a
 *                              3:1 icon floor, in both themes and both directions. Sixteen
 *                              findings, the identical three ratios slice 2 measured on
 *                              `/countries` before reusing the same class there.
 *
 * MEASURED, all four combinations, `APP_DEBUG=false`, by
 * `tests/Support/bin/measure-superadmin-premium-live.cjs`:
 *
 *     BEFORE  44 findings in 10 groups over 27 surfaces   (22 of them D-25, excluded)
 *     AFTER   22 findings in  1 group                     (D-25 only)
 *
 * ------------------------------------------------------------------------------------------------
 *  THE REPAIRS ARE REUSE. NO NEW CSS. `store-syria-ds.css` IS UNCHANGED BY THIS SLICE.
 * ------------------------------------------------------------------------------------------------
 *
 *  1. `plan-coupon/show.blade.php` renders `<x-datatable>`, like the other 66.
 *  2. Both dialog fragments carry `data-ss-theme="light"`, and the two controls become
 *     `.ss-btn--secondary` / `.ss-btn--primary`.
 *
 * `data-ss-theme="light"` IS NOT A STYLE PREFERENCE HERE, it is the recorded P2.5-A-01/A-02
 * decision: the host keeps `--bs-modal-bg: #ffffff` even in dark mode, so the dialog is a WHITE
 * SHEET, and a design-system component inside one otherwise resolves its DARK token values onto
 * white. `docs/development/phase-2.5/ACCOUNT-HANDOFF.md` states it as a rule — "Any design-system
 * component placed inside a modal must carry `data-ss-theme="light"`" — and the idiom already
 * exists at `users/create.blade.php`, `category/create.blade.php`, `product_brand/create.blade.php`
 * and `finance/index.blade.php`. Slice 1 left these two findings "needing that decision re-read
 * rather than assumed"; it is re-read, it exists, and it prescribes this.
 *
 * THE PAIR IS ASSERTED AS A PAIR. `create` and `edit` differ, comment-stripped, in `Form::open`
 * vs `Form::model` and `Create` vs `Update`. Fixing one of a byte-similar pair and measuring only
 * that one is how `plans/edit.blade.php` kept slice 1's own defect, so every count below is taken
 * on BOTH files and a one-sided repair fails.
 */
final class PlanCouponScreenLegibilityTest extends TestCase
{
    use StripsSourceComments;

    private const CREATE = 'resources/views/plan-coupon/create.blade.php';
    private const EDIT = 'resources/views/plan-coupon/edit.blade.php';
    private const SHOW = 'resources/views/plan-coupon/show.blade.php';
    private const COMPONENT = 'resources/views/components/datatable.blade.php';
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const DATATABLE = 'app/DataTables/PlanCouponDetailDataTable.php';

    /** The two dialog fragments, which must stay in step. */
    private const FRAGMENTS = [self::CREATE, self::EDIT];

    /**
     * The ONE view that may call `$dataTable->table(` outside the component, with its reason.
     *
     * `country/tab.blade.php` is reachable only through `POST /country-tab`
     * (`routes/web.php:531` -> `CountryController::getAllCountry`), and **nothing in either
     * repository calls that route** — searched over every `.php`, `.js` and `.blade.php` outside
     * `vendor/`, the only two hits are the declaration and the controller's own `view()` call. So
     * it paints on no screen an operator can reach, its findings are not in the product, and
     * repairing it would change a count by zero. It is declared here rather than fixed, because a
     * change with no measurable effect cannot be verified and reads as a finished repair.
     * See §11 of `docs/development/phase-6/PHASE-06-SUPER-ADMIN-UX.md`.
     */
    private const DECLARED_HAND_ROLLED = [
        'resources/views/plan-coupon/show.blade.php'
            => 'kept hand-rolled on purpose: the component would switch on bulkDelete() -- and it '
             . 'carries ss-dt-card-body, so it is repaired',
        'resources/views/country/tab.blade.php'
            => 'unrouted: POST /country-tab has no caller, so its findings are not in the product',
    ];

    /**
     * The declared hand-rolled views that are REACHABLE, and therefore must carry the grid scope.
     *
     * `country/tab.blade.php` is not here because nothing routes to it — the reason is in
     * `DECLARED_HAND_ROLLED` above and in §17 of `PHASE-06-SUPER-ADMIN-UX.md`. Repairing an
     * unreachable view would move no measured count, and a change that cannot be verified reads
     * as a finished repair.
     */
    private const HAND_ROLLED_AND_REACHABLE = [
        'resources/views/plan-coupon/show.blade.php',
    ];

    /*
     * ------------------------------------------------------------------------------------------
     *  PREMISES. Each of these PASSES ON THE UNCHANGED TREE, and that is what makes the ratchets
     *  below worth anything: a ratchet whose premise is false is green by accident.
     * ------------------------------------------------------------------------------------------
     */

    public function test_premise_the_shared_datatable_component_is_what_carries_the_grid_repair(): void
    {
        $component = $this->sourceWithoutComments(self::COMPONENT);

        $this->assertStringContainsString(
            'card-body table-border-style ss-dt-card-body',
            $component,
            'the x-datatable component no longer carries ss-dt-card-body, so converting a view to '
            . 'it would repair nothing — re-measure before trusting this file'
        );

        /*
         * AND THE REPAIR REALLY IS SCOPED UNDER THAT CLASS. If these selectors were unscoped the
         * hand-rolled view would already be repaired and the 16 findings would have another cause.
         * Scoped to the grid-tool block, not searched over the whole 7,000-line sheet: an
         * assertion over the file answers "does this string exist anywhere".
         */
        $block = $this->designSystemGridToolBlock();

        foreach ([
            '.ss-dt-card-body .dt-buttons .buttons-reset',
            '.ss-dt-card-body .dt-buttons .buttons-reload',
            '.ss-dt-card-body .bulk-delete',
        ] as $selector) {
            $this->assertContains(
                $selector,
                $this->selectorsIn($block),
                'the grid-tool repair is no longer scoped under .ss-dt-card-body at ' . $selector
            );
        }
    }

    public function test_premise_the_white_dialog_decision_is_recorded_and_already_used_elsewhere(): void
    {
        /*
         * The decision's own trace: the design system carries a dark-scoped rule that only makes
         * sense because the modal sheet stays white in dark mode. If this rule is ever removed the
         * premise for `data-ss-theme="light"` has changed and these fragments must be re-measured.
         */
        $sheet = $this->sourceWithoutComments(self::DS_SHEET);

        $this->assertStringContainsString(
            '[data-ss-theme="dark"] .modal-content',
            $sheet,
            'store-syria-ds.css no longer scopes anything to a dark page with a white modal sheet; '
            . 'the P2.5-A-01/A-02 premise for the light wrapper has moved'
        );

        /* The idiom exists in the product already — this slice copies it, it does not invent it. */
        $this->assertStringContainsString(
            'data-ss-theme="light"',
            $this->sourceWithoutComments('resources/views/users/create.blade.php'),
            'users/create.blade.php no longer carries the light-scope wrapper this slice follows'
        );
    }

    /*
     * ------------------------------------------------------------------------------------------
     *  RATCHETS. Each FAILS ON THE UNCHANGED TREE.
     * ------------------------------------------------------------------------------------------
     */

    public function test_the_coupon_detail_grid_carries_the_design_systems_grid_scope(): void
    {
        $show = $this->sourceWithoutComments(self::SHOW);

        $this->assertStringContainsString(
            'card-body table-border-style ss-dt-card-body',
            $show,
            'plan-coupon/show.blade.php has no ss-dt-card-body ancestor for its grid. It is the '
            . 'only view in the repository that builds its own DataTable wrapper, so it is the '
            . 'only grid that misses the tool repair, which is scoped under that class on '
            . 'purpose: measured 2.66 / 1.73 / 1.74:1 light and 1.39 / 1.39 / 1.30 / 1.15 / '
            . '1.16:1 dark against a 3:1 icon floor.'
        );
    }

    /**
     * THE REPAIR THAT WAS WRITTEN, MEASURED CLEAN, AND THEN WITHDRAWN.
     *
     * Rendering `<x-datatable>` like the other 66 views closes the same 16 findings and is tidier
     * by every reading a contrast harness can take. It is NOT taken, and this test is what stops
     * somebody taking it later as an obvious cleanup.
     *
     * The component also pushes `bulk-delete::pages.script`, which is where `bulkDelete()` is
     * DEFINED. Measured on the live screen with a `plan_user_coupons` row present:
     *
     *     committed   .select-row 1   a.bulk-delete 1   typeof window.bulkDelete "undefined"
     *     converted   .select-row 1   a.bulk-delete 1   typeof window.bulkDelete "function"
     *
     * `buildDataTable()` renders the Bulk Delete button and `buildDataTableColumn()` renders the
     * row checkbox for every grid, so the control is on screen either way -- but on this screen
     * its handler has never existed, so it throws and deletes nothing. The conversion would have
     * switched on bulk deletion of coupon-redemption history as a side effect of a colour change.
     * That is a business-rule change and a standing escalation, not a contrast repair.
     *
     * NO CONTRAST HARNESS CAN SEE THIS. A capability probe found it, and the figures above are
     * the only reason the narrower repair is the right one rather than the lazier one.
     */
    public function test_the_coupon_detail_screen_deliberately_does_not_render_the_shared_component(): void
    {
        $show = $this->sourceWithoutComments(self::SHOW);

        $this->assertStringNotContainsString(
            '<x-datatable',
            $show,
            'plan-coupon/show.blade.php now renders <x-datatable>. That closes the same 16 '
            . 'contrast findings AND pushes bulk-delete::pages.script, which defines the '
            . 'bulkDelete() handler this screen has never had -- so it switches on bulk deletion '
            . 'of coupon-redemption history. If that is wanted, it is an owner decision and a '
            . 'separate change; it is not a side effect of a colour repair.'
        );

        /*
         * The premise of the paragraph above, asserted so it cannot silently stop being true:
         * the component really is what pushes that script.
         */
        $this->assertStringContainsString(
            "@include('bulk-delete::pages.script')",
            $this->sourceWithoutComments(self::COMPONENT),
            'the x-datatable component no longer pushes the bulk-delete script, so the reason '
            . 'this view stays hand-rolled has changed and must be re-measured'
        );

        /* And the view still builds its own grid, which is the thing being kept. */
        $this->assertStringContainsString('$dataTable->table(', $show);
    }

    public function test_no_view_hand_rolls_a_datatable_except_the_ones_declared_with_a_reason(): void
    {
        /*
         * THE DETECTOR IS SELF-TESTED FIRST, against the one file that is KNOWN to carry the
         * pattern. Without this the census is unfalsifiable from the outside: a pattern that
         * matches nothing reports "no view hand-rolls a DataTable" and reads as a pass, which is
         * the same shape as the eight census failures CLAUDE.md records.
         */
        $this->assertTrue(
            $this->buildsItsOwnDataTable(self::COMPONENT),
            'the detector no longer matches the shared component, which definitely does call '
            . '$dataTable->table([ -- so a census using it proves nothing'
        );

        $found = [];

        foreach ($this->bladeViews() as $relative) {
            if ($relative === self::COMPONENT) {
                continue;
            }

            if ($this->buildsItsOwnDataTable($relative)) {
                $found[] = $relative;
            }
        }

        sort($found);
        $declared = array_keys(self::DECLARED_HAND_ROLLED);
        sort($declared);

        /*
         * BOTH DIRECTIONS. A new hand-rolled grid fails this, and so does a declared one that has
         * been repaired and left in the list — a stale exemption is how a census stops covering
         * the thing it names.
         */
        $this->assertSame(
            $declared,
            $found,
            'the set of views building their own DataTable markup has changed. A hand-rolled grid '
            . 'has no .ss-dt-card-body ancestor unless it says so itself, so it misses every '
            . 'repair scoped under that class. Add the class and declare the view here with its '
            . 'measured reason for staying hand-rolled, or render <x-datatable>.'
        );

        /*
         * AND A DECLARED VIEW THAT IS REACHABLE MUST STILL BE REPAIRED. Declaring a view here
         * exempts it from rendering the component; it does not exempt it from the design system.
         * Without this, "declared" would be a way of not fixing something.
         */
        foreach (self::HAND_ROLLED_AND_REACHABLE as $view) {
            $this->assertStringContainsString(
                'ss-dt-card-body',
                $this->sourceWithoutComments($view),
                $view . ' is declared hand-rolled AND is reachable, so it must carry '
                . 'ss-dt-card-body itself — otherwise its grid tools take the host tints'
            );
        }

        /*
         * THE TWO LISTS ARE TIED TOGETHER, BECAUSE A MUTATION FOUND THAT THEY WERE NOT.
         *
         * Emptying `HAND_ROLLED_AND_REACHABLE` made the loop above a no-op while the set
         * comparison still passed, so the repair assertion could be switched off in one line
         * without any test noticing. (The mutation that found this is #4; the first version of it
         * SURVIVED, because it added an unread constant instead — a mutation that changes nothing
         * proves nothing, which `mutate.py`'s own docblock says in its first paragraph.)
         *
         * The rule: every declared hand-rolled view is EITHER reachable, and then it must be in
         * the second list and carry the class, OR its declared reason says it is unrouted. There
         * is no third option, so the lists cannot drift apart silently.
         */
        foreach (self::DECLARED_HAND_ROLLED as $view => $reason) {
            $unrouted = str_contains($reason, 'unrouted');
            $reachable = in_array($view, self::HAND_ROLLED_AND_REACHABLE, true);

            $this->assertNotSame(
                $unrouted,
                $reachable,
                $view . ' must be EITHER in HAND_ROLLED_AND_REACHABLE (and then carry '
                . 'ss-dt-card-body) OR declared unrouted with that word in its reason. It is '
                . 'currently ' . ($reachable ? 'listed as reachable' : 'not listed as reachable')
                . ' and its reason ' . ($unrouted ? 'does' : 'does not') . ' say unrouted.'
            );
        }
    }

    public function test_the_order_reference_cell_is_an_identifier_and_not_a_host_primary_fill(): void
    {
        /*
         * A `.php` FILE, SO THE TRAIT USES PHP'S OWN TOKENIZER. That matters here more than
         * usual: the repair's docblock quotes `btn btn-primary btn-sm` verbatim while explaining
         * that it was removed, so a search over the raw file reports the defect as present. That
         * is the census trap in the form `CLAUDE.md` records eight times.
         */
        $source = $this->sourceWithoutComments(self::DATATABLE);

        $this->assertStringNotContainsString(
            'btn-primary',
            $source,
            'PlanCouponDetailDataTable still builds a btn-primary fill in PHP. Measured on '
            . '/plan-coupon/{id} as the operator: span.btn-inner--text at 2.58:1 in dark, '
            . "rgb(4,34,42) on rgb(51,93,168) — the host's primary, which style-dark.css paints "
            . '--bs-btn-color: var(--theme-color) on --bs-btn-bg: var(--third-color). A raw-HTML '
            . 'string in a DataTable closure is invisible to any census that reads Blade files, '
            . "GAP-296's included."
        );

        $this->assertStringNotContainsString(
            'btn-inner--text',
            $source,
            'the fake-button wrapper is still there'
        );

        $this->assertSame(
            1,
            substr_count($source, 'class="ss-code"'),
            'the order reference must print through exactly one .ss-code span. It takes --ss-fg '
            . 'on --ss-surface-sunken, so it re-themes by construction, and its '
            . 'unicode-bidi: isolate keeps an ASCII reference from reordering inside the Arabic '
            . 'page — which the fill never did.'
        );

        /*
         * THE COLUMN IS RAW, SO THE ESCAPE IS LOAD-BEARING. `rawColumns(['order_id'])` means this
         * string reaches the browser unescaped and `orders.order` is stored text. A repair that
         * dropped `e()` while fixing a colour would be a stored-XSS sink for a colour's sake.
         */
        $this->assertStringContainsString(
            'e($row->order)',
            $source,
            'the order reference is no longer escaped, and order_id is in rawColumns'
        );
        $this->assertStringContainsString(
            "rawColumns(['order_id'])",
            $source,
            'the premise for the escape assertion above has changed: order_id is no longer raw'
        );

        /* And `.ss-code` is really a design-system component, not a class invented here. */
        $sheet = $this->sourceWithoutComments(self::DS_SHEET);
        $this->assertContains('.ss-code', $this->selectorsIn($sheet));
    }

    public function test_both_coupon_dialog_fragments_scope_their_tokens_to_the_white_sheet(): void
    {
        foreach (self::FRAGMENTS as $view) {
            $source = $this->sourceWithoutComments($view);

            $this->assertSame(
                1,
                substr_count($source, 'data-ss-theme="light"'),
                $view . ' must carry exactly one data-ss-theme="light" scope. The host keeps '
                . '--bs-modal-bg #ffffff in dark mode (P2.5-A-01/A-02), so a design-system '
                . 'component inside this dialog resolves its DARK tokens onto a white sheet.'
            );
        }
    }

    public function test_neither_coupon_fragment_keeps_the_hosts_outline_primary_generate_control(): void
    {
        foreach (self::FRAGMENTS as $view) {
            $this->assertSame(
                [],
                $this->elementsWithClass($view, 'btn-outline-primary'),
                $view . ' still carries btn-outline-primary. Measured rgb(120,148,198) on the '
                . "dialog's white sheet = 3.07:1 against 4.5:1, in dark, in both directions."
            );

            /*
             * AN EXACT COUNT, NOT A CONTAINS. `assertStringContainsString` on a repaired class is
             * satisfied by one repaired element while its twin stays broken — that is the weakness
             * three of slice 2's ten mutations exposed. There is exactly one Generate control per
             * fragment.
             */
            $secondary = $this->elementsWithClass($view, 'ss-btn--secondary');

            $this->assertCount(
                1,
                $secondary,
                $view . ' must carry exactly one .ss-btn--secondary — the Generate control. '
                . '.ss-btn--secondary takes --ss-surface / --ss-fg, which in the light scope above '
                . 'is #1a1d23 on #ffffff.'
            );
            $this->assertSame('button', $secondary[0]['tag'], 'the Generate control is a <button>');
        }
    }

    public function test_the_coupon_edit_submit_no_longer_takes_the_hosts_dark_primary_fill(): void
    {
        $this->assertSame(
            [],
            $this->elementsWithClass(self::EDIT, 'btn-primary'),
            'plan-coupon/edit.blade.php still carries the host btn-primary. '
            . 'style-dark.css paints it --bs-btn-color: var(--theme-color) on '
            . '--bs-btn-bg: var(--third-color) = rgb(4,34,42) on rgb(51,93,168) = 2.58:1. '
            . 'That is the one defect slice 1 existed to close, and it survived here because this '
            . 'screen was never a measured surface.'
        );

        $primary = $this->elementsWithClass(self::EDIT, 'ss-btn--primary');

        $this->assertCount(
            1,
            $primary,
            "plan-coupon/edit.blade.php must carry exactly one .ss-btn--primary — the form's own "
            . 'submit. In the light scope that is #ffffff on #335da8.'
        );
        $this->assertSame('input', $primary[0]['tag'], 'the submit is an <input type=submit>');
    }

    public function test_the_two_fragments_carry_the_same_control_classes_so_neither_can_be_repaired_alone(): void
    {
        $classes = [];

        foreach (self::FRAGMENTS as $view) {
            $source = $this->sourceWithoutComments($view);

            preg_match_all('/class\s*=\s*"([^"]*(?:ss-btn|btn)[^"]*)"/', $source, $m);

            $tokens = [];

            foreach ($m[1] as $one) {
                foreach (preg_split('/\s+/', trim($one), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                    /* `mx-1` is spacing, not tone, and the two files space the submit identically
                       anyway; it is kept so a drift in either is still visible. */
                    $tokens[$token] = true;
                }
            }

            ksort($tokens);
            $classes[$view] = array_keys($tokens);
        }

        $this->assertSame(
            $classes[self::CREATE],
            $classes[self::EDIT],
            'plan-coupon/create and plan-coupon/edit have drifted apart on their control classes. '
            . 'They are the same form; a repair applied to one of a byte-similar pair is how '
            . "plans/edit.blade.php kept slice 1's own 2.58:1 button."
        );

        /* Anti-vacuity: an empty set would make the comparison above trivially true. */
        $this->assertGreaterThanOrEqual(3, count($classes[self::CREATE]));
    }

    public function test_the_repaired_pairs_clear_their_floors_and_the_originals_did_not(): void
    {
        /*
         * Arithmetic from the sheet's own DECLARED token values, so this figure is produced twice:
         * here, and by the live harness in the browser.
         */
        $white = [255, 255, 255];

        /* BEFORE: the host's outline-primary ink on the dialog's white sheet. */
        $this->assertSame(3.07, $this->ratio([120, 148, 198], $white), 'the measured before-value moved');

        /* BEFORE: the host's dark primary fill. */
        $this->assertSame(2.58, $this->ratio([4, 34, 42], [51, 93, 168]), 'the measured before-value moved');

        /* AFTER: .ss-btn--secondary in a light scope — --ss-grey-900 on --ss-grey-0. */
        $this->assertGreaterThan(4.5, $this->ratio([0x1a, 0x1d, 0x23], $white));

        /* AFTER: .ss-btn--primary in a light scope — --ss-on-brand on --ss-blue-600. */
        $this->assertGreaterThan(4.5, $this->ratio($white, [0x33, 0x5d, 0xa8]));

        /* AFTER: `.ss-code` — --ss-fg on --ss-surface-sunken, the pair the order reference takes. */
        $this->assertGreaterThan(4.5, $this->ratio([0x1a, 0x1d, 0x23], [0xee, 0xef, 0xf1]));

        /* AFTER: the grid tools, light and dark, against the 3:1 non-text floor. */
        $this->assertGreaterThan(3.0, $this->ratio([0x47, 0x50, 0x69], $white));            // #475069 light tool
        $this->assertGreaterThan(3.0, $this->ratio([0xc6, 0xcc, 0xdb], [0x29, 0x2a, 0x33])); // #c6ccdb dark tool
        $this->assertGreaterThan(3.0, $this->ratio([0xb4, 0x23, 0x18], [0xfe, 0xf3, 0xf2])); // #b42318 light delete
        $this->assertGreaterThan(3.0, $this->ratio([0xff, 0x9b, 0x93], [0x3a, 0x23, 0x21])); // #ff9b93 dark delete

        /* And those eight hexes are really the declared ones, scoped to the block that declares
           them rather than searched over the whole sheet. */
        $block = $this->designSystemGridToolBlock();

        foreach (['#475069', '#c6ccdb', '#b42318', '#ff9b93', '#fef3f2', '#3a2321'] as $hex) {
            $this->assertStringContainsString(
                $hex,
                $block,
                'the grid-tool block no longer declares ' . $hex . '; the arithmetic above is stale'
            );
        }
    }

    /* ---------------------------------------------------------------------------------------- */

    /**
     * The grid-tool block of `store-syria-ds.css`, from its `--ss-dt-tool-bg` declaration to the
     * end of the `.bulk-delete` rule.
     *
     * SCOPED ON PURPOSE. An assertion over the whole 7,000-line sheet answers "does this string
     * exist anywhere in the design system", and a mutation that dropped one component's rule
     * survived in this repository precisely because another component had the same one.
     */
    private function designSystemGridToolBlock(): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(
            dirname(__DIR__, 3) . '/' . self::DS_SHEET
        ));

        $start = strpos($css, '--ss-dt-tool-bg');
        $this->assertNotFalse($start, 'the grid-tool block has moved or gone');

        $end = strpos($css, '.ss-dt-card-body table.dataTable > thead', $start);
        $this->assertNotFalse($end, 'the grid-tool block no longer ends at the header rule');

        $block = substr($css, $start, $end - $start);
        $this->assertGreaterThan(1500, strlen($block), 'the grid-tool block read as suspiciously short');

        return $block;
    }

    /**
     * Does this view build its own DataTable markup rather than render the shared component?
     *
     * COMMENTS ARE STRIPPED FIRST, for the reason `CLAUDE.md` records eight times over: a census
     * keyed on a spelling is satisfied -- or broken -- by PROSE ABOUT the thing it measures, and
     * `plan-coupon/show.blade.php`'s own repair comment now quotes `$dataTable->table()` while
     * explaining that the call was removed.
     *
     * IT USES ITS OWN STRIPPER RATHER THAN THE SHARED TRAIT, and that is not a shortcut.
     * `StripsSourceComments` asserts that stripping did not empty the file, which is correct for
     * a test that reads ONE named file and wrong for a corpus: measured, FOUR views under
     * `front_end/hooks/` strip to nothing -- `cart_sidebar` is 0 bytes and `product_rating`,
     * `header_button` and `header_nav_link` are entirely `{{-- --}}` comment. The guard's job
     * (catching a stripper that eats its subject) is done here by the detector's self-test against
     * the component instead, which is a stronger check than a non-empty string.
     */
    private function buildsItsOwnDataTable(string $relative): bool
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);

        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);
        $code = (string) preg_replace('#(?<![\w"\'/])/\*.*?\*/#s', '', $code);
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        return (bool) preg_match('/\$\w+(\[[^\]]*\])?\s*->\s*table\(\s*\[/', $code);
    }

    /** Every non-empty Blade view under `resources/views`. */
    private function bladeViews(): array
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $out = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            /*
             * ZERO-BYTE VIEWS EXIST AND THEY BREAK THE SHARED STRIPPER, which is correct of it
             * rather than a fault: `StripsSourceComments` refuses to return an empty string
             * because a stripper that eats a file turns every `assertDoesNot…` into a free pass.
             * Measured: `front_end/hooks/cart_sidebar.blade.php` is 0 bytes. An empty file can
             * carry no hand-rolled table, so it is skipped HERE -- at the census, where the fact
             * is known -- rather than by weakening the guard for every other consumer.
             */
            if ($file->getSize() === 0) {
                continue;
            }

            $out[] = 'resources/views' . substr($file->getPathname(), strlen($root));
        }

        sort($out);

        /* Anti-vacuity: a census over an empty set passes. */
        $this->assertGreaterThan(400, count($out), 'the view census found almost nothing');

        return $out;
    }

    /**
     * @return list<array{tag: string, class: string}>
     */
    private function elementsWithClass(string $view, string $token): array
    {
        $source = $this->sourceWithoutComments($view);

        preg_match_all(
            '/<([a-zA-Z][a-zA-Z0-9]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $found = [];

        foreach ($matches as $match) {
            if (! preg_match('/class\s*=\s*"([^"]*)"/', $match[2], $class)) {
                continue;
            }

            $tokens = preg_split('/\s+/', trim($class[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (in_array($token, $tokens, true)) {
                $found[] = ['tag' => strtolower($match[1]), 'class' => $class[1]];
            }
        }

        return $found;
    }

    /** Every selector in a CSS string, at-rule preludes excluded and at-rule bodies included. */
    private function selectorsIn(string $css): array
    {
        $selectors = [];
        $buffer = '';

        foreach (str_split($css) as $char) {
            if ($char === '{') {
                $prelude = trim(preg_replace('/\s+/', ' ', $buffer) ?? '');
                $buffer = '';

                if ($prelude === '' || str_starts_with($prelude, '@')) {
                    continue;
                }

                foreach (explode(',', $prelude) as $one) {
                    $one = trim($one);

                    if ($one !== '') {
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

    /** WCAG 2.1 relative contrast, to two decimal places. */
    private function ratio(array $a, array $b): float
    {
        $lum = static function (array $c): float {
            $f = static function (float $v): float {
                $v /= 255;

                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
        };

        $x = $lum($a);
        $y = $lum($b);

        return round((max($x, $y) + 0.05) / (min($x, $y) + 0.05), 2);
    }
}
