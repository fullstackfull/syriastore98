<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-179 / P06-06 — THE PLATFORM OPERATOR'S PRIMARY ACTIONS, AND TWO SCREENS OF ITS OWN.
 * ================================================================================================
 *
 * Phase 2.5 raised the merchant surfaces to a premium bar. It was explicitly merchant-scoped, and
 * `P06-06` carries "premium Super Admin UX" separately. This file is the first slice of that: the
 * one defect on the operator's screens that was MEASURED rather than judged, and the two screens
 * where the operator's own content is illegible.
 *
 * THE DEFECT, AND IT IS THE HOST'S, NOT THIS PROJECT'S.
 *
 *   `public/assets/css/style-dark.css:16170` paints the primary button
 *       --bs-btn-color: var(--theme-color);   --bs-btn-bg: var(--third-color);
 *   which on the dark admin resolves to rgb(4,34,42) on rgb(51,93,168) — **2.58:1** against a
 *   4.5:1 requirement. Measured in the running application as the operator, in both writing
 *   directions, on six screens.
 *
 *   IT IS KNOWN AND PRE-EXISTING. `public/css/store-syria-ds.css:5640` records the SAME TWO
 *   COLOURS at 2.63:1 on the merchant order grid and solved it there by removing the fill, and
 *   `resources/views/platform/content/edit.blade.php:116-124` records 2.58:1 on that screen and
 *   solved it by moving to `.ss-btn--primary`. THE PRECEDENT IS FOLLOWED, NOT RE-ARGUED.
 *
 * WHY `.ss-btn--primary` IS THE FIX RATHER THAN A NEW COLOUR. It reads `--ss-brand` /
 * `--ss-on-brand`, which re-theme, so it is correct in both themes BY CONSTRUCTION rather than by
 * a measurement that has to be repeated. Measured live on `/users`, which already carries it:
 * rgb(4,34,42) on rgb(42,195,229) — **7.92:1**. Same ink, a different ground, and the ground is
 * the whole difference.
 *
 * THE OTHER TWO, both on screens the operator alone sees:
 *
 *   `/modules/list`   the Add-on Manager's per-module status chip, `<span class="badge bg-danger">`,
 *                     ONCE PER MODULE. 67 modules, so **134 findings per theme** — 69% of every
 *                     contrast failure on the whole operator surface comes from this one span.
 *                     4.26:1 in light; in dark the host's soft tint composites over the dark card
 *                     to rgb(129,96,108) and the red ink lands at **1.03:1**.
 *   /platform/resources  the storage paths in `<code>`, at Bootstrap's default #e83e8c:
 *                     3.65:1 light, 4.05:1 dark. Both fail. This is a Phase 06 screen with no
 *                     merchant counterpart.
 *
 * EVERY FIGURE IN THIS FILE IS PRODUCED TWICE — by this file's own arithmetic from the declared
 * token values, and by `tests/Support/bin/measure-superadmin-premium-live.cjs` against the running
 * admin as `operator@example.test` in all four theme/direction combinations. The live harness
 * asserts the dark premise (`data-ss-theme`, the dark host sheet, and a body background of exactly
 * rgb(34,36,44)) before it trusts a reading, because the admin theme is a SETTING and Playwright's
 * colour-scheme emulation produces two identical light captures — a failure mode already recorded
 * in this repository twice.
 *
 * NOT IN THIS SLICE, and each one is named with its count rather than left to be rediscovered —
 * see `docs/development/phase-6/PHASE-06-SUPER-ADMIN-UX.md` §4.
 */
class SuperAdminPremiumSurfaceTest extends TestCase
{
    use StripsSourceComments;

    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const HOST_DARK_SHEET = 'public/assets/css/style-dark.css';

    /** The host's dark primary button, measured in the running admin. */
    private const HOST_PRIMARY_FG = [4, 34, 42];    // var(--theme-color) resolved
    private const HOST_PRIMARY_BG = [51, 93, 168];  // var(--third-color) resolved

    /** The design system's primary button in a dark scope, measured on /users. */
    private const DS_PRIMARY_FG = [4, 34, 42];      // --ss-on-brand  #04222a
    private const DS_PRIMARY_BG = [42, 195, 229];   // --ss-brand = --ss-cyan-500  #2ac3e5

    /** `--ss-danger-*` / `--ss-success-*`, read from the sheet's own declarations. */
    private const DANGER_LIGHT = [[140, 29, 23], [253, 238, 238]];   // red-800 on red-50
    private const DANGER_DARK  = [[243, 169, 164], [44, 20, 19]];    // #f3a9a4 on #2c1413
    private const SUCCESS_LIGHT = [[22, 94, 52], [234, 247, 239]];   // green-800 on green-50
    private const SUCCESS_DARK  = [[127, 214, 160], [16, 42, 27]];   // #7fd6a0 on #102a1b

    /** `--ss-fg` on `--ss-surface-sunken`, the pair `.ss-code` takes. */
    private const CODE_LIGHT = [[26, 29, 35], [238, 239, 241]];      // grey-900 on grey-100
    private const CODE_DARK  = [[232, 234, 237], [31, 35, 42]];      // #e8eaed on #1f232a

    /** Bootstrap's default `<code>` ink, which is what the screen paints today. */
    private const BOOTSTRAP_CODE_FG = [232, 62, 140];                // #e83e8c

    private const AA = 4.5;

    /**
     * THE OPERATOR SURFACE, derived from `php artisan route:list --json` filtered on
     * `App\Http\Middleware\PlatformOperatorOnly` plus the screens production's `super admin` role
     * is granted (`App\Models\Utility::addNewData()`), then narrowed to the first-party views that
     * really carry a primary action. Not chosen by eye.
     */
    private const OPERATOR_VIEWS = [
        'resources/views/plans/index.blade.php',
        'resources/views/plans/create.blade.php',
        'resources/views/plan-coupon/index.blade.php',
        'resources/views/plan-coupon/create.blade.php',
        'resources/views/store/index.blade.php',
        'resources/views/store/create.blade.php',
        'resources/views/store/user-create.blade.php',
        'resources/views/country/index.blade.php',
        'resources/views/currency/index.blade.php',
        'resources/views/setting/index.blade.php',
        'resources/views/users/index.blade.php',
        'resources/views/module/index.blade.php',
    ];

    /**
     * THE THREE DECLARED EXCEPTIONS, each with an argument rather than an assertion.
     *
     * All three are in `plans/index.blade.php` and NONE of them is a control:
     *
     *   the ribbon      `<div class="... plan-active-status btn-primary">` carries NO `btn` token.
     *                   `btn-primary` is a colour hook for the active-plan ribbon that
     *                   `plan-active-status` positions. It wraps a `badges bg-success` chip which
     *                   paints its own ink, so the host's 2.58:1 pair never reaches any text.
     *
     *   two "Expired"   `<p class="server-plan ... btn btn-primary w-100 text-success">Expired</p>`
     *   notices         is a STATUS LABEL shaped like a button, on a `<p>`, and `text-success`
     *                   already overrides the ink — so it is not the 2.58:1 defect. Converting it
     *                   to `.ss-btn--primary` would dress an expiry notice as the screen's one
     *                   primary action, which is a worse answer than leaving it. It belongs on
     *                   `.ss-badge--danger`, which is a semantic change to a MERCHANT-visible
     *                   screen and therefore its own slice.
     *
     * The keys are the tag plus the exact class attribute, so an exception cannot silently widen
     * to a different element.
     */
    private const DECLARED_NON_CONTROL_EXCEPTIONS = [
        'div|product-content-top d-flex flex-row-reverse m-0 p-0 plan-active-status btn-primary',
        'p|server-plan font-bold text-center bg-primary mb-0 btn btn-primary w-100 text-success',
    ];

    // =============================================================================================
    //  PREMISES — the defect's mechanism and the fix's basis are both still real
    // =============================================================================================

    public function test_premise_the_host_dark_sheet_still_paints_the_primary_button_from_theme_colour_over_third_colour(): void
    {
        $sheet = (string) file_get_contents($this->path(self::HOST_DARK_SHEET));

        $this->assertStringContainsString(
            '--bs-btn-color: var(--theme-color)',
            $sheet,
            'The host dark sheet no longer paints .btn-primary from --theme-color. '
            . 'If the host has been fixed, re-measure before trusting this file.'
        );
        $this->assertStringContainsString(
            '--bs-btn-bg: var(--third-color)',
            $sheet,
            'The host dark sheet no longer fills .btn-primary from --third-color.'
        );
    }

    public function test_premise_the_hosts_primary_button_pair_is_the_measured_2_point_58_to_1(): void
    {
        $ratio = $this->ratio(self::HOST_PRIMARY_FG, self::HOST_PRIMARY_BG);

        $this->assertSame(2.58, round($ratio, 2), 'rgb(4,34,42) on rgb(51,93,168) is not 2.58:1.');
        $this->assertLessThan(self::AA, $ratio);
    }

    /**
     * THIS ASSERTION WAS WEAKER AND A MUTATION CAUGHT IT, which is the whole reason the revert
     * step exists in this project. It first read only `assertStringContainsString('var(--ss-brand)')`
     * — and `.ss-btn--primary` declares that token TWICE, on `background` and on `border-color`.
     * So replacing the FILL with a literal `#335da8`, which is exactly the host colour this slice
     * exists to get away from, left the border's token behind and the test passed. A ratchet that
     * cannot tell a fill from a border would have let the defect back in under its own fix.
     *
     * The declarations are now matched by property, and the block is refused a hex at all.
     */
    public function test_premise_the_design_systems_primary_button_reads_the_brand_tokens(): void
    {
        $block = $this->cssBlock('.ss-btn--primary');

        $this->assertMatchesRegularExpression(
            '/\bbackground:\s*var\(--ss-brand\)\s*;/',
            $block,
            '`.ss-btn--primary` does not take its FILL from `--ss-brand`.'
        );
        $this->assertMatchesRegularExpression(
            '/\bcolor:\s*var\(--ss-on-brand\)\s*;/',
            $block,
            '`.ss-btn--primary` does not take its INK from `--ss-on-brand`.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{3,8}/',
            $block,
            'The `.ss-btn--primary` rule hard-codes a colour. A literal cannot re-theme, and a '
            . 'literal is precisely what the host rule this slice replaces does wrong.'
        );
    }

    public function test_premise_the_design_systems_primary_pair_passes_where_the_hosts_fails(): void
    {
        $ratio = $this->ratio(self::DS_PRIMARY_FG, self::DS_PRIMARY_BG);

        $this->assertSame(7.92, round($ratio, 2), '--ss-on-brand on --ss-brand is not 7.92:1 in a dark scope.');
        $this->assertGreaterThanOrEqual(self::AA, $ratio);
    }

    public function test_premise_the_two_primary_pairs_share_an_ink_and_differ_only_in_ground(): void
    {
        /* The point of the fix, stated as an assertion: nothing about the TEXT changes. The host
           fill is simply too dark a blue to carry a near-black ink, and the brand token is not. */
        $this->assertSame(self::HOST_PRIMARY_FG, self::DS_PRIMARY_FG);
        $this->assertNotSame(self::HOST_PRIMARY_BG, self::DS_PRIMARY_BG);
    }

    public function test_premise_the_badge_tokens_pass_in_both_themes(): void
    {
        foreach ([
            'danger light' => self::DANGER_LIGHT,
            'danger dark' => self::DANGER_DARK,
            'success light' => self::SUCCESS_LIGHT,
            'success dark' => self::SUCCESS_DARK,
        ] as $label => [$fg, $bg]) {
            $this->assertGreaterThanOrEqual(
                self::AA,
                $this->ratio($fg, $bg),
                "The .ss-badge {$label} pair does not reach AA, so it cannot be the repair."
            );
        }
    }

    public function test_premise_the_declared_badge_token_values_are_the_sheets_own(): void
    {
        $sheet = (string) file_get_contents($this->path(self::DS_SHEET));

        /* The arithmetic above is only worth anything if these really are the sheet's values. */
        foreach (['--ss-red-800:   #8c1d17', '--ss-red-50:    #fdeeee',
                  '--ss-green-800: #165e34', '--ss-green-50:  #eaf7ef',
                  '--ss-danger-bg:  #2c1413', '--ss-danger-fg:  #f3a9a4',
                  '--ss-success-bg: #102a1b', '--ss-success-fg: #7fd6a0'] as $declaration) {
            $this->assertStringContainsString($declaration, $sheet,
                "store-syria-ds.css no longer declares `{$declaration}`; the figures in this file are stale.");
        }
    }

    public function test_premise_bootstraps_default_code_ink_fails_in_both_themes(): void
    {
        $light = $this->ratio(self::BOOTSTRAP_CODE_FG, [249, 250, 251]);
        $dark = $this->ratio(self::BOOTSTRAP_CODE_FG, [34, 36, 44]);

        $this->assertSame(3.65, round($light, 2));
        $this->assertSame(4.05, round($dark, 2));
        $this->assertLessThan(self::AA, $light);
        $this->assertLessThan(self::AA, $dark);
    }

    // =============================================================================================
    //  THE RATCHETS
    // =============================================================================================

    public function test_no_operator_screen_dresses_a_control_in_the_hosts_primary_fill(): void
    {
        $offenders = [];

        foreach (self::OPERATOR_VIEWS as $view) {
            foreach ($this->elementsWithClass($view, 'btn-primary') as $element) {
                $key = $element['tag'] . '|' . $element['class'];

                if (in_array($key, self::DECLARED_NON_CONTROL_EXCEPTIONS, true)) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d <%s class="%s">',
                    $view, $element['line'], $element['tag'], $element['class']);
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "%d control(s) on the platform operator's screens still carry the host's `.btn-primary` "
            . "fill, which measures 2.58:1 in the dark admin theme. Move each to "
            . "`.ss-btn .ss-btn--primary` (the precedent is platform/content/edit.blade.php:116), "
            . "or declare it in DECLARED_NON_CONTROL_EXCEPTIONS with an argument:\n  %s",
            count($offenders), implode("\n  ", $offenders)
        ));
    }

    public function test_every_operator_screen_that_had_a_primary_action_now_declares_the_design_systems(): void
    {
        /* The companion to the ratchet above, and the reason it is needed: deleting the class
           would also satisfy "no .btn-primary". Each of these screens has a primary action, so
           each must still name one. */
        $without = [];

        foreach (self::OPERATOR_VIEWS as $view) {
            if (! str_contains($this->sourceWithoutComments($view), 'ss-btn--primary')) {
                $without[] = $view;
            }
        }

        $this->assertSame([], $without, sprintf(
            "These operator screens have a primary action and name no primary button; removing "
            . "`.btn-primary` without putting `.ss-btn--primary` in its place makes the screen's "
            . "main action unrecognisable rather than legible:\n  %s",
            implode("\n  ", $without)
        ));
    }

    public function test_the_add_on_managers_module_status_chip_is_a_design_system_badge(): void
    {
        $source = $this->sourceWithoutComments('resources/views/module/index.blade.php');

        /* The chip is inside `<small class="text-muted">` and reads Enable / Disable. It is
           rendered once per module -- 67 on this instance -- so this one span is 69% of every
           contrast failure measured anywhere on the operator surface. */
        $this->assertMatchesRegularExpression(
            '/class="ss-badge ss-badge--success"[^>]*>\s*\{\{ __\(\'Enable\'\) \}\}/',
            $source,
            "The Add-on Manager's enabled chip is not an `.ss-badge--success`."
        );
        $this->assertMatchesRegularExpression(
            '/class="ss-badge ss-badge--danger"[^>]*>\s*\{\{ __\(\'Disable\'\) \}\}/',
            $source,
            "The Add-on Manager's disabled chip is not an `.ss-badge--danger`."
        );

        /* AND THE HOST UTILITY MUST BE GONE FROM THAT SPAN, not merely joined by a DS class.
           `.bg-danger` is a pure colour utility carrying `!important`, so adding `.ss-badge--danger`
           beside it changes nothing at all -- the measured 1.03:1 would survive a change that
           looked complete. This is the one place where the project's "add, never substitute" rule
           does not apply, and store-syria-ds.css:5640 names the same exception for the same
           reason. */
        $this->assertDoesNotMatchRegularExpression(
            '/class="badge bg-(danger|success)"/',
            $source,
            'A `badge bg-danger` / `badge bg-success` chip remains in the Add-on Manager. The host '
            . 'utility paints with !important, so a DS class beside it does not take effect.'
        );
    }

    public function test_the_platform_resources_screen_paints_its_paths_in_a_token(): void
    {
        $source = $this->sourceWithoutComments('resources/views/superadmin/platform_resources.blade.php');

        $this->assertStringContainsString(
            '<code class="ss-code">',
            $source,
            "The storage paths on /platform/resources are still Bootstrap's default `<code>` ink, "
            . 'which measures 3.65:1 in light and 4.05:1 in dark. Both fail.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<code(?!\s+class="ss-code")/',
            $source,
            'An unclassed `<code>` remains on /platform/resources.'
        );
    }

    public function test_the_design_system_declares_the_code_rule_in_tokens_and_not_in_hexes(): void
    {
        $block = $this->cssBlock('.ss-code');

        $this->assertStringContainsString('var(--ss-fg)', $block);
        $this->assertStringContainsString('var(--ss-surface-sunken)', $block);
        $this->assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{3,8}/',
            $block,
            'The `.ss-code` rule hard-codes a colour. A literal cannot re-theme, which is the '
            . 'whole reason the host rule it replaces fails in one theme and not the other.'
        );
    }

    public function test_the_code_pair_passes_in_both_themes(): void
    {
        $light = $this->ratio(self::CODE_LIGHT[0], self::CODE_LIGHT[1]);
        $dark = $this->ratio(self::CODE_DARK[0], self::CODE_DARK[1]);

        $this->assertGreaterThanOrEqual(self::AA, $light, '--ss-fg on --ss-surface-sunken fails AA in light.');
        $this->assertGreaterThanOrEqual(self::AA, $dark, '--ss-fg on --ss-surface-sunken fails AA in dark.');
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
     * Every element in a Blade view whose class attribute carries $token, with its tag and line.
     *
     * COMMENTS ARE STRIPPED FIRST, and that is not a precaution — it is the fault that has been
     * recorded seven times in this repository, in Blade, in PHP and in CSS. The docblock of this
     * very file quotes `class="... btn btn-primary ..."` four times.
     *
     * A CLASS-ATTRIBUTE TEST, NOT A SUBSTRING TEST. `btn-primary` appears inside `plan-active-
     * status btn-primary` on a decorative `<div>` and inside `.btn-primary` in prose; matching the
     * raw string would conflate a positioning hook with a control. Tokens are split on whitespace
     * so `btn-primary-outline` could never match either.
     *
     * @return list<array{tag: string, class: string, line: int}>
     */
    private function elementsWithClass(string $view, string $token): array
    {
        $source = $this->sourceWithoutComments($view);

        /* Attribute values may contain `>` (a Blade expression, an inline style), so quoted runs
           are consumed as units rather than stopping at the first `>`. */
        preg_match_all(
            '/<([a-zA-Z][a-zA-Z0-9]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        $found = [];

        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];
            $tag = strtolower($match[1][0]);
            $attributes = $match[2][0];

            if (! preg_match('/class\s*=\s*"([^"]*)"/', $attributes, $class)) {
                continue;
            }

            if (! in_array($token, preg_split('/\s+/', trim($class[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [], true)) {
                continue;
            }

            $found[] = [
                'tag' => $tag,
                'class' => $class[1],
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
            ];
        }

        return $found;
    }

    /**
     * One rule's declaration block, by exact selector.
     *
     * SCOPED TO THE BLOCK, not searched over the whole sheet. `store-syria-ds.css` is some 7,000
     * lines, and an assertion over all of it answers "does this string exist anywhere" — which is
     * how a mutation that dropped one component's rule survived, because another component
     * happened to declare the same thing.
     */
    private function cssBlock(string $selector): string
    {
        $sheet = (string) file_get_contents($this->path(self::DS_SHEET));
        $sheet = (string) preg_replace('#/\*.*?\*/#s', '', $sheet);

        $pattern = '/(?:^|[\s,}])' . preg_quote($selector, '/') . '\s*(?:,[^{]*)?\{([^}]*)\}/m';

        $this->assertMatchesRegularExpression(
            $pattern,
            $sheet,
            "store-syria-ds.css declares no rule for `{$selector}`."
        );

        preg_match_all($pattern, $sheet, $matches);

        return implode("\n", $matches[1]);
    }

    /** WCAG 2.1 relative luminance. */
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
