<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * GAP-179 — THE SAME TWO DEFECTS, ON THE MODULE'S MERCHANT PAGE, FOUND BY ADDING A SURFACE.
 * ================================================================================================
 *
 * `LynomiaIntegrationLegibilityTest` closed two findings on `superadmin/integration.blade.php`.
 * `packages/workdo/LynomiaChat` has NINE other views, and GAP-179's census never reached them:
 * its surface list is derived from `PlatformOperatorOnly`, and every merchant route in this module
 * carries `PlanModuleCheck` + `EnsureStoreOwnsLink` instead, so the operator harness is REFUSED by
 * them and photographs the dashboard. THIS IS SLICE 1'S OMISSION REPEATING ITS OWN SHAPE: that
 * slice missed `plans/edit.blade.php` because it was not in the list, and adding the surface — not
 * looking harder at the ones already there — is what found it.
 *
 * MEASURED on `/lynomia/automation/commerce` as the merchant, in all four combinations, with
 * `tests/Support/bin/measure-lynomia-merchant-live.cjs`, 0 premise failures:
 *
 *     DARK   button.nav-link.active          rgb(51,93,168) on rgb(34,36,44)   2.41:1   x2
 *     DARK   button.btn.btn-sm.btn-primary   rgb(4,34,42) on rgb(51,93,168)    2.58:1   x2
 *     DARK   span.dash-mtext                 rgb(4,34,42) on rgb(51,93,168)    2.58:1   x2  <- D-25
 *
 * Both of the first two are byte-for-byte the defects GAP-179 has already diagnosed and repaired
 * elsewhere — 2.41:1 is the operator page's tab, 2.58:1 is the `.btn-primary` fill slice 1 moved
 * off six surfaces — and both fail in DARK ONLY and pass in light, which is the asymmetry that let
 * them survive every earlier pass. The third is the shared sidebar's active item: `D-25`, the
 * owner's scope decision, `partision/sidebar.blade.php`, reaching every merchant screen and all 70
 * modules. It is deliberately NOT touched, and `test_the_sidebar_finding_is_left_for_the_owner`
 * asserts that negative so nobody can later claim credit for it.
 *
 * ------------------------------------------------------------------------------------------------
 *  WHY THE MARKUP CHANGES AND THE STYLESHEET DOES NOT
 * ------------------------------------------------------------------------------------------------
 *
 * `style-dark.css` declares `.nav-link { color: var(--theme-color) !important; }` — an `!important`
 * on a (0,1,0) selector. NOTHING declared against that element can win, so adding `.ss-tab` or any
 * tone class to the button is INERT; re-measuring after such a change returns the same ratio under
 * a longer selector name, which reads as a finished repair and is worse than no repair at all.
 * That has now happened three times in this project. The remedy is this project's recorded one:
 * the LABEL moves into a `<span>` the host selector cannot match.
 *
 * BOTH LABELS ARE WRAPPED, NOT ONLY THE ACTIVE ONE, and that is correctness rather than
 * thoroughness: `data-bs-toggle="tab"` moves `.active` CLIENT-SIDE, so whichever tab the merchant
 * clicks becomes the one the host paints at 2.41:1. A fix applied to the initially-active tab
 * alone is correct until the first click. Here the second tab is additionally behind
 * `@if ($canManageConsent)`, so a harness run by a person without that permission renders one tab
 * and cannot see the other at all — a second reason a count, not a presence check, is the
 * assertion.
 *
 * THE BUTTON IS A CLASS SWAP, NOT A WRAP, because `.btn-primary`'s fill is not `!important`:
 * `platform/content/edit.blade.php:124-128` is the pattern, and `.ss-btn--primary` takes
 * `--ss-brand` / `--ss-on-brand`, so it is correct in both themes by construction.
 *
 * SCOPE STATED HONESTLY. Three `btn-primary` sites are converted and ONLY ONE closed a measured
 * failure. `:187` sits in the Consent tab pane and `:257` in a modal, neither of which the
 * first-load capture paints, so no measured proof is claimed for them — they are the same control
 * class on branches the fixture does not render. This is the same accounting slice 1 gave for its
 * own 30 conversions and 7 measured closures, and it is here because an unqualified "converted
 * three" reads as "closed three".
 */
final class LynomiaMerchantAutomationLegibilityTest extends TestCase
{
    private const VIEW = 'packages/workdo/LynomiaChat/src/resources/views/merchant/commerce-automation.blade.php';
    private const HOST_DARK_SHEET = 'public/assets/css/style-dark.css';
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    /**
     * WHERE `span.dash-mtext` REALLY LIVES, and the register's own citation is off by one file.
     *
     * GAP-179 records the 22 `D-25` findings as living in `layouts/app.blade.php` and
     * `partision/sidebar.blade.php`. Measured by grep: `partision/sidebar.blade.php` contains
     * `dash-mtext` ZERO times. The class is in `layouts/app.blade.php` (1) and in
     * `partision/menu-tree.blade.php` and `partision/menu-zones.blade.php`, which the sidebar
     * includes. That matters practically rather than pedantically: an agent told "do not touch
     * those two files" could edit `menu-tree.blade.php` believing it to be outside the named set,
     * and would be taking the owner's decision without noticing. All three are listed here.
     */
    private const D25_FILES = [
        'resources/views/layouts/app.blade.php',
        'resources/views/partision/menu-tree.blade.php',
        'resources/views/partision/menu-zones.blade.php',
    ];

    /** Measured in the running admin as the merchant, dark theme, both languages. */
    private const ACTIVE_TAB_FG_DARK = [51, 93, 168];  // var(--theme-color)
    private const DARK_PAGE = [34, 36, 44];
    private const HOST_PRIMARY_FG = [4, 34, 42];
    private const HOST_PRIMARY_BG = [51, 93, 168];

    private const AA = 4.5;

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        $path = $this->repoRoot() . '/' . $relative;
        $this->assertFileExists($path, $relative . ' is gone, so this file describes nothing.');

        return (string) file_get_contents($path);
    }

    /**
     * Blade comments are stripped before any source assertion.
     *
     * A census that reads comments can be satisfied — or broken — by PROSE ABOUT THE THING IT
     * MEASURES, which this project has now hit eight times in three syntaxes. This file's own
     * header names `.btn-primary` and `.nav-link` repeatedly; without stripping, the assertion
     * "no btn-primary remains in the view" would be satisfiable by a comment explaining that it
     * was removed.
     */
    private function bladeWithoutComments(string $relative): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $this->read($relative));
    }

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

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    // =============================================================================================
    //  PREMISES — the facts the repair depends on, asserted rather than assumed
    // =============================================================================================

    public function test_premise_the_host_declares_the_nav_link_colour_with_important(): void
    {
        /*
         * THE WHOLE REASON THE MARKUP CHANGES INSTEAD OF THE STYLESHEET. If this ever stops being
         * `!important`, a tone class on the button would work and the wrap could be reconsidered —
         * so the premise is pinned rather than described.
         */
        $sheet = (string) preg_replace('#/\*.*?\*/#s', '', $this->read(self::HOST_DARK_SHEET));

        $this->assertMatchesRegularExpression(
            '/\.nav-link\s*\{[^}]*color\s*:[^;]*!important/s',
            $sheet,
            'style-dark.css no longer declares .nav-link { color: ... !important }, which is the '
            . 'rule that makes a tone class on the tab button inert. Re-measure before changing '
            . 'the markup back.'
        );
    }

    public function test_premise_both_measured_colour_pairs_really_fail_aa(): void
    {
        // The numbers this file is built on, recomputed rather than quoted.
        $tab = $this->ratio(self::ACTIVE_TAB_FG_DARK, self::DARK_PAGE);
        $button = $this->ratio(self::HOST_PRIMARY_FG, self::HOST_PRIMARY_BG);

        $this->assertSame(2.41, round($tab, 2), 'the active tab pair no longer measures 2.41:1');
        $this->assertSame(2.58, round($button, 2), 'the host primary pair no longer measures 2.58:1');
        $this->assertLessThan(self::AA, $tab);
        $this->assertLessThan(self::AA, $button);
    }

    public function test_premise_the_design_system_owns_every_class_this_repair_uses(): void
    {
        /*
         * NO NEW CSS. `store-syria-ds.css` is shared by every Phase 02 screen and all 70 modules,
         * so a repair that needed a new rule would be a far larger change than this one claims to
         * be. All three classes already exist.
         */
        $sheet = (string) preg_replace('#/\*.*?\*/#s', '', $this->read(self::DS_SHEET));

        foreach (['.ss-text-strong', '.ss-btn--primary', '.ss-btn--sm'] as $class) {
            $this->assertStringContainsString(
                $class . ' ',
                $sheet,
                $class . ' is not declared in the design system, so this repair would be inert.'
            );
        }
    }

    // =============================================================================================
    //  THE REPAIR
    // =============================================================================================

    public function test_every_tab_label_is_wrapped_so_the_hosts_important_rule_cannot_reach_it(): void
    {
        $source = $this->bladeWithoutComments(self::VIEW);

        preg_match_all('/class="nav-link[^"]*"/', $source, $all);
        $this->assertCount(
            2,
            $all[0],
            'the tab rail no longer has exactly two nav-link buttons; this file measured two.'
        );

        /*
         * AN EXACT COUNT, NOT A PRESENCE CHECK. Three of slice 2's mutations survived first time
         * because an assertion matched the repaired shape SOMEWHERE in the file while a reverted
         * element sat beside it. Two nav-links, so two wrapped labels.
         */
        preg_match_all(
            '/class="nav-link[^"]*"[^>]*>\s*<span class="ss-text-strong">\s*\{\{\s*__\(/s',
            $source,
            $wrapped
        );

        $this->assertCount(
            2,
            $wrapped[0],
            'Both tab labels must sit inside <span class="ss-text-strong">, including the one that '
            . 'is not active on first paint: data-bs-toggle moves .active client-side, so the '
            . 'inactive tab becomes the 2.41:1 one as soon as the merchant clicks it.'
        );
    }

    public function test_no_tone_class_is_left_on_a_nav_link_where_it_would_be_inert(): void
    {
        /*
         * A RATCHET AGAINST THE REPAIR THAT LOOKS RIGHT AND DOES NOTHING. `.ss-tab`,
         * `.ss-text-strong` or any `text-*` tone on the BUTTON itself cannot beat
         * `.nav-link { ... !important }`, so putting one there reads as a finished repair while
         * the measured ratio is unchanged. It belongs on the span.
         */
        $source = $this->bladeWithoutComments(self::VIEW);

        preg_match_all('/class="(nav-link[^"]*)"/', $source, $m);

        foreach ($m[1] as $classList) {
            foreach (['ss-tab', 'ss-text-strong', 'ss-muted', 'text-primary', 'text-dark', 'text-white'] as $inert) {
                $this->assertStringNotContainsString(
                    $inert,
                    $classList,
                    'class="' . $classList . '" puts a tone class on the nav-link itself, where the '
                    . 'host\'s !important rule beats it. Wrap the label instead.'
                );
            }
        }
    }

    public function test_the_tabs_keep_every_hook_bootstrap_needs_to_switch_them(): void
    {
        /*
         * Wrapping the label must not disturb the tab mechanism. If it did, the "fix" would trade
         * a contrast defect for a broken control — and the second tab would never be reachable to
         * be measured at all.
         */
        $source = $this->bladeWithoutComments(self::VIEW);

        foreach ([
            'data-bs-toggle="tab"' => 2,
            'role="tab"' => 2,
            'data-bs-target="#lync-pane-rules"' => 1,
            'data-bs-target="#lync-pane-consent"' => 1,
            'aria-selected="true"' => 1,
            'aria-selected="false"' => 1,
        ] as $needle => $expected) {
            $this->assertSame(
                $expected,
                substr_count($source, $needle),
                $needle . ' appears ' . substr_count($source, $needle) . ' times, expected ' . $expected
            );
        }
    }

    public function test_the_host_primary_fill_is_gone_from_this_view(): void
    {
        /*
         * `.btn-primary` is converted rather than joined. Unlike `.nav-link`, the host's primary
         * fill is NOT `!important`, so `.ss-btn--primary` beside it would win on source order --
         * but leaving both means the next reader cannot tell which one paints, and a utility that
         * does nothing is the kind of thing that gets "tidied" back into effect later.
         */
        $source = $this->bladeWithoutComments(self::VIEW);

        $this->assertSame(
            0,
            substr_count($source, 'btn-primary'),
            'btn-primary still appears in ' . self::VIEW . '. Its fill measures 2.58:1 in the dark '
            . 'admin theme. Use `ss-btn ss-btn--primary` (plus ss-btn--sm where the host had '
            . 'btn-sm), following platform/content/edit.blade.php:124-128.'
        );

        $this->assertSame(
            3,
            substr_count($source, 'ss-btn--primary'),
            'this view had three btn-primary sites: the Rules toolbar button (the one MEASURED at '
            . '2.58:1), the opt-in Save inside the Consent pane, and the rule-modal Save. All '
            . 'three are converted; only the first closed a measured failure.'
        );
    }

    public function test_the_sidebar_finding_is_left_for_the_owner(): void
    {
        /*
         * A NEGATIVE RESULT, ASSERTED SO IT CANNOT QUIETLY BECOME A REPAIR SOMEBODY TAKES CREDIT
         * FOR. `span.dash-mtext` at 2.58:1 is two of this surface's six measured findings and it
         * is `D-25` -- the shared sidebar, every merchant screen, all 70 modules, reserved to the
         * owner's scope decision. A Phase 06 slice must not take it, and this test fails if one
         * does, which is the only way the exclusion survives a later reader who sees a quick win.
         */
        $found = 0;

        foreach (self::D25_FILES as $relative) {
            $source = $this->bladeWithoutComments($relative);
            $found += substr_count($source, 'dash-mtext');

            $this->assertSame(
                0,
                substr_count($source, 'ss-text-strong'),
                $relative . ' now carries a design-system tone class. That is D-25 -- the shared '
                . 'chrome reaches every merchant screen and all 70 vendored modules -- and it is '
                . 'the owner\'s decision, not a slice\'s.'
            );
        }

        $this->assertGreaterThan(
            0,
            $found,
            'dash-mtext is gone from the shared chrome. If that was a deliberate D-25 repair, it '
            . 'is an owner-scope change and this exclusion must be re-read, not deleted.'
        );
    }
}
