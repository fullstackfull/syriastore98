<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-179 / P06-06 — THE FOUR LynomiaChat FINDINGS FROM THE SLICE-1 CENSUS.
 * ================================================================================================
 *
 * `/super-admin/lynomia-integration` was one of slice 1's 24 operator surfaces and kept two
 * defects that slice could not take, because `packages/workdo/` is off limits — except that
 * `LynomiaChat` is FIRST-PARTY and is the one package `CLAUDE.md` permits editing. Both live in
 * `src/resources/views/superadmin/integration.blade.php`:
 *
 *     LIGHT  span.badge.bg-warning   rgb(244,180,26) on rgb(253,244,221)   1.68:1   x2
 *     DARK   a.nav-link.active       rgb(51,93,168)  on rgb(34,36,44)      2.41:1   x2
 *
 * Each fails in ONE theme and passes in the other, which is why both survived: whoever looked at
 * the screen was looking at the theme where it was fine. Re-measured here before any change, in
 * all four combinations.
 *
 * ------------------------------------------------------------------------------------------------
 *  THE TAB FIX TOOK THREE ATTEMPTS AND THE FIRST TWO WERE INERT — the slice-2 lesson again
 * ------------------------------------------------------------------------------------------------
 *
 * The design system has a tab component, `.ss-tab`, whose `[aria-selected="true"]` rule takes
 * `--ss-brand` — a token whose dark value is the cyan measured at 7.40:1 on the dark page. Adding
 * `.ss-tab` and `aria-selected` to the anchors therefore LOOKED like the whole fix.
 *
 * It changed nothing. Re-measured: still 2.41:1, now reported on `a.nav-link.active.ss-tab`.
 * Walked rule by rule in the browser, the winner is
 *
 *     .nav-link { color: var(--theme-color) !important; }      style-dark.css
 *
 * — an `!important` on a (0,1,0) selector, which beats `.ss-tab[aria-selected="true"]` (0,2,0)
 * outright. This is exactly the `.text-danger` situation slice 2 met: the host owns the selector
 * with `!important`, so NOTHING declared against that element can win, and out-importanting a
 * selector the host owns would repaint `.nav-link` across all 70 vendored modules.
 *
 * So the markup changes, which is this project's recorded remedy: the LABEL moves into a `<span>`
 * the host selector cannot match, and the tone goes on the span.
 *
 * EVERY label is wrapped, not just the active one, and that is a correctness point rather than
 * thoroughness. `data-bs-toggle="tab"` moves `.active` CLIENT-SIDE, so whichever tab the operator
 * clicks becomes the one the host paints at 2.41:1. A fix applied only to the initially-active tab
 * would be correct until the first click.
 *
 * WHAT IS LOST, AND WHY IT IS NOT A LOSS — measured, not assumed. With every label on `--ss-fg`,
 * the active tab no longer differs from its neighbours in COLOUR. Selection is still conveyed,
 * because the host gives the active tab borders the others do not have: measured
 * `border-top: 1px rgb(222,226,230)` and `border-bottom: 1px rgb(255,255,255)` on the active tab
 * against fully transparent borders on the rest, in both themes. And the colour cue being removed
 * was BACKWARDS in dark: the active tab sat at 2.41:1 while its inactive neighbours read 5.05:1, so
 * the selected tab was the hardest one to read.
 */
final class LynomiaIntegrationLegibilityTest extends TestCase
{
    use StripsSourceComments;

    private const VIEW = 'packages/workdo/LynomiaChat/src/resources/views/superadmin/integration.blade.php';
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const HOST_DARK_SHEET = 'public/assets/css/style-dark.css';

    /** Measured in the running admin as the platform operator. */
    private const HOST_WARNING_FG = [244, 180, 26];
    private const HOST_WARNING_BG_LIGHT = [253, 244, 221]; // the 15% tint composited on white
    private const HOST_ACTIVE_TAB_FG = [51, 93, 168];      // var(--theme-color) in dark
    private const DARK_PAGE = [34, 36, 44];
    private const INACTIVE_TAB_FG_DARK = [120, 148, 198];  // passes at 5.05:1 — left alone

    /** The design system's values, asserted against the sheet by a premise below. */
    private const WARNING_FG_LIGHT = [122, 70, 6];    // --ss-amber-900
    private const WARNING_BG_LIGHT = [254, 247, 224]; // --ss-amber-50
    private const WARNING_FG_DARK = [240, 198, 116];  // #f0c674
    private const WARNING_BG_DARK = [44, 33, 9];      // #2c2109
    private const FG_LIGHT = [26, 29, 35];            // --ss-grey-900
    private const FG_DARK = [232, 234, 237];          // #e8eaed
    private const WHITE = [255, 255, 255];

    private const AA = 4.5;

    // =============================================================================================
    //  PREMISES
    // =============================================================================================

    public function test_premise_the_host_declares_the_nav_link_colour_with_important(): void
    {
        /* THE WHOLE REASON THE MARKUP CHANGES INSTEAD OF THE STYLESHEET. Asserted per declaration,
           including the !important, because that flag is the fact — a `.nav-link` colour without
           it could simply be outranked and the span wrapper would be unnecessary. */
        $dark = (string) preg_replace(
            '#/\*.*?\*/#s',
            '',
            (string) file_get_contents($this->path(self::HOST_DARK_SHEET))
        );

        $this->assertMatchesRegularExpression(
            '/(^|[\s,}])\.nav-link\s*\{[^}]*\bcolor:\s*var\(--theme-color\)\s*!important/m',
            $dark,
            'style-dark.css no longer paints `.nav-link` from --theme-color with !important. If '
            . 'that declaration has gone, `.ss-tab[aria-selected="true"]` would win on its own and '
            . 'the span wrapper is redundant — re-measure before simplifying the markup.'
        );
    }

    public function test_premise_both_measured_colours_really_fail_their_own_theme(): void
    {
        $badge = $this->ratio(self::HOST_WARNING_FG, self::HOST_WARNING_BG_LIGHT);
        $tab = $this->ratio(self::HOST_ACTIVE_TAB_FG, self::DARK_PAGE);

        $this->assertSame(1.68, round($badge, 2), 'the warning badge pair is not 1.68:1');
        $this->assertSame(2.41, round($tab, 2), 'the active tab pair is not 2.41:1');
        $this->assertLessThan(self::AA, $badge);
        $this->assertLessThan(self::AA, $tab);
    }

    public function test_premise_the_inactive_tabs_pass_and_are_therefore_not_the_defect(): void
    {
        /* A NEGATIVE RESULT, asserted so that wrapping every label cannot be mistaken for having
           fixed five things. Only the ACTIVE tab was ever failing; the others are wrapped because
           `.active` moves client-side, not because they were broken. */
        $this->assertGreaterThanOrEqual(
            self::AA,
            $this->ratio(self::INACTIVE_TAB_FG_DARK, self::DARK_PAGE),
            'an inactive tab does not pass on the dark page, so this slice measured the wrong thing'
        );
    }

    public function test_premise_the_replacement_pairs_pass_in_both_themes(): void
    {
        foreach ([
            'warning badge light' => [self::WARNING_FG_LIGHT, self::WARNING_BG_LIGHT],
            'warning badge dark' => [self::WARNING_FG_DARK, self::WARNING_BG_DARK],
            'tab label light' => [self::FG_LIGHT, self::WHITE],
            'tab label dark' => [self::FG_DARK, self::DARK_PAGE],
        ] as $what => [$fg, $bg]) {
            $this->assertGreaterThanOrEqual(
                self::AA,
                $this->ratio($fg, $bg),
                "the replacement for {$what} does not reach AA, so it cannot be the repair"
            );
        }
    }

    public function test_premise_the_declared_token_values_are_the_sheets_own(): void
    {
        $sheet = (string) file_get_contents($this->path(self::DS_SHEET));

        foreach ([
            '--ss-warning-bg: #2c2109', '--ss-warning-fg: #f0c674',
            '--ss-warning-bg: var(--ss-amber-50)', '--ss-warning-fg: var(--ss-amber-900)',
            '--ss-grey-900: #1a1d23', '--ss-fg:        #e8eaed',
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $sheet,
                "store-syria-ds.css no longer declares `{$declaration}`; the figures here are stale");
        }
    }

    public function test_premise_the_design_system_declares_the_two_classes_this_slice_uses(): void
    {
        foreach ([
            '.ss-badge--warning' => ['background' => 'var(--ss-warning-bg)', 'color' => 'var(--ss-warning-fg)'],
            '.ss-text-strong' => ['color' => 'var(--ss-fg)'],
        ] as $selector => $declarations) {
            $block = $this->cssBlock($selector);

            foreach ($declarations as $property => $value) {
                $this->assertMatchesRegularExpression(
                    '/\b' . preg_quote($property, '/') . ':\s*' . preg_quote($value, '/') . '\s*;/',
                    $block,
                    "`{$selector}` does not declare `{$property}: {$value}`. A token present on some "
                    . 'other property in the same block is not this assertion.'
                );
            }
        }
    }

    // =============================================================================================
    //  THE RATCHETS
    // =============================================================================================

    public function test_no_status_chip_on_the_integration_screen_wears_the_host_warning_fill(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $this->assertSame(
            0,
            preg_match_all('/class="badge bg-warning"/', $source),
            'a `badge bg-warning` chip remains on the integration screen. The host paints it at '
            . '1.68:1 in the light admin. `bg-warning` must be REMOVED rather than joined by a DS '
            . 'class: it is a pure colour utility carrying !important, so an `.ss-badge--warning` '
            . 'beside it would change nothing at all.'
        );

        $this->assertSame(
            2,
            preg_match_all('/class="ss-badge ss-badge--warning"/', $source),
            'the two warning chips — the pending-approval count on the Merchant Accounts tab and '
            . 'the "No" on Credentials present — are not both on `.ss-badge--warning`.'
        );
    }

    public function test_the_two_yes_no_rows_are_one_badge_system_rather_than_two(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        /* The overview table answers the same Yes/No question on two consecutive rows and wore
           THREE different host badge classes to do it (`bg-success`, `bg-secondary`, `bg-warning`).
           Repairing only the one that failed would have left one table mixing two badge systems,
           which is the premium-bar failure this requirement is about. */
        $this->assertSame(
            0,
            preg_match_all('/class="badge bg-(success|secondary)"/', $source),
            'the Yes/No chips in the overview table still use host badge classes, so that one '
            . 'table mixes the design system with the host.'
        );

        $this->assertGreaterThanOrEqual(
            2,
            preg_match_all('/class="ss-badge ss-badge--success"/', $source),
            'the "Yes" chips are not on `.ss-badge--success`'
        );
        $this->assertGreaterThanOrEqual(
            1,
            preg_match_all('/class="ss-badge"/', $source),
            'the neutral "No" chip is not on a plain `.ss-badge` — it was `bg-secondary`, a neutral '
            . 'rather than a warning, and that distinction is kept deliberately'
        );
    }

    public function test_every_tab_label_is_wrapped_so_the_hosts_important_rule_cannot_reach_it(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        /* FIVE TABS, FIVE WRAPPED LABELS. `.active` moves client-side, so a fix applied only to the
           initially-active tab is correct until the first click. The count is exact for that
           reason: four of five would read as done. */
        $this->assertSame(
            5,
            preg_match_all('/<span class="ss-text-strong">\{\{ __\(/', $source),
            'the five tab labels are not all wrapped in an `.ss-text-strong` span. `.nav-link` is '
            . 'painted `var(--theme-color) !important` by the dark host sheet, so a tone on the '
            . 'anchor itself is INERT — measured: adding `.ss-tab` and `aria-selected` changed the '
            . 'ratio not at all.'
        );
    }

    public function test_no_tone_class_is_left_on_a_nav_link_where_it_would_be_inert(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        /* The first two attempts at this fix put classes on the anchor. Both were inert, and an
           inert class reads as a finished repair — so the anchor is asserted clean. */
        preg_match_all('/<a class="([^"]*nav-link[^"]*)"/', $source, $anchors);

        $this->assertNotEmpty($anchors[1], 'no nav-link anchors found at all');

        foreach ($anchors[1] as $class) {
            foreach (['ss-text-strong', 'ss-tab', 'ss-muted', 'ss-text-danger'] as $tone) {
                $this->assertStringNotContainsString($tone, $class, sprintf(
                    'the anchor `%s` carries `%s`, which cannot win against '
                    . '`.nav-link { color: var(--theme-color) !important }`. Put the tone on a '
                    . 'span inside the anchor instead.',
                    $class, $tone
                ));
            }
        }
    }

    public function test_the_tabs_keep_every_hook_bootstrap_needs_to_switch_them(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $this->assertSame(5, preg_match_all('/data-bs-toggle="tab"/', $source),
            'a tab lost its `data-bs-toggle`, so it no longer switches');
        $this->assertSame(5, preg_match_all('/role="tab"/', $source),
            'a tab lost `role="tab"`');
        $this->assertSame(1, preg_match_all('/class="nav-link active"/', $source),
            'the initially-active tab lost `.active`, or a second tab gained it');
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

    private function cssBlock(string $selector): string
    {
        $sheet = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($this->path(self::DS_SHEET)));

        $pattern = '/(?:^|[\s,}])' . preg_quote($selector, '/') . '\s*(?:,[^{]*)?\{([^}]*)\}/m';

        $this->assertMatchesRegularExpression($pattern, $sheet,
            "store-syria-ds.css declares no rule for `{$selector}`");

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
