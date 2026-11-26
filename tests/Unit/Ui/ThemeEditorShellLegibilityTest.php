<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * GAP-098 / GAP-111 / GAP-113 — THE THEME EDITOR SHELL, MEASURED FOR THE FIRST TIME.
 * ================================================================================================
 *
 * `GAP-113` recorded that "the central new merchant surface of Phase 03 has zero rendered evidence,
 * authenticated or otherwise, in any language, direction or appearance", and named that as the
 * reason `GAP-098`'s light-only hard-coded colours went unnoticed. That is no longer true: the
 * editor was driven as an authenticated merchant in all four combinations with
 * `tests/Support/bin/measure-lynomia-merchant-live.cjs` (the merchant four-combination harness,
 * extended for this slice with the landed-path + `h1` premise GAP-113 asks for), 16 of 16 cells,
 * 0 premise failures, `APP_DEBUG=false`.
 *
 * WHAT THE FIRST CAPTURE FOUND -- 14 contrast findings in 5 groups:
 *
 *     DARK   button.btn.btn-sm.btn-primary            rgb(4,34,42) on rgb(51,93,168)   2.58:1  x4
 *     DARK   button.btn.btn-primary                   rgb(4,34,42) on rgb(51,93,168)   2.58:1  x4
 *     LIGHT  span.badge.bg-warning.text-dark          rgb(244,180,26) on rgb(253,244,221) 1.68:1 x2
 *     DARK   a.btn.btn-sm.btn-outline-secondary       rgb(108,117,125) on rgb(41,42,51) 3.04:1  x2
 *     DARK   span.dash-mtext                          rgb(4,34,42) on rgb(51,93,168)   2.58:1  x2
 *
 * TWELVE OF THE FOURTEEN ARE THIS SLICE'S, AND EVERY ONE IS A DEFECT GAP-179 HAS ALREADY
 * DIAGNOSED AND REPAIRED SOMEWHERE ELSE -- the `.btn-primary` fill slice 1 moved off six operator
 * surfaces, and the `badge bg-warning` chip measured at the SAME 1.68:1 light on
 * `packages/workdo/LynomiaChat`'s operator page. So this is not new design work; it is the same
 * remedy applied to the surface the census never reached. The remaining two are `span.dash-mtext`,
 * the shared sidebar's active item: `D-25`, the owner's scope decision, and
 * `test_the_shared_chrome_finding_is_left_for_the_owner` asserts that exclusion as a negative.
 *
 * ------------------------------------------------------------------------------------------------
 *  THE HARD-CODED COLOURS ARE REAL BUT A STATIC CAPTURE CANNOT SEE THEM, AND THAT IS SAID PLAINLY
 * ------------------------------------------------------------------------------------------------
 *
 * `customize.blade.php`'s pushed `<style>` block hard-codes `#f8f9fa`, `#e9ecef` twice and
 * `rgba(0,0,0,0.1)`. The admin's dark mode is server-side (`[data-ss-theme="dark"]` on `<html>`,
 * plus the host's `style-dark.css`), so each of those stays LIGHT in dark mode: a near-white hover
 * surface under dark-theme ink, and two light hairlines on a dark card.
 *
 * NONE OF THEM APPEARED IN THE CAPTURE, and the reason matters more than the fact: `.icon-item` is
 * inside a dropdown that is closed on first paint and `#f8f9fa` is its `:hover` colour, while
 * `[data-repeater-item]` exists only on a page whose theme JSON declares a repeater. A capture of
 * a first paint cannot reach either. This project has the matching precedent in both directions:
 * `setting/index.blade.php:283` was converted and produced BYTE-IDENTICAL captures because it sits
 * in a `display:none` block, and 23 of slice 1's 30 conversions closed no measured failure.
 *
 * So the literals are replaced with the tokens the design system already owns -- AND THE CLAIM MADE
 * FOR THAT CHANGE IS A MEASUREMENT AFTER ALL, which is better than the arithmetic this paragraph
 * first offered. Read through the real cascade on the real page, in all four combinations, with a
 * probe element carrying `.icon-item` and `[data-repeater-item]` appended to the live document:
 *
 *     --ss-surface-hover        #f6f7f8 light  ->  #2d323b dark   (was the fixed #f8f9fa)
 *     --ss-border               #dddfe3 light  ->  #363b44 dark   (was the fixed #e9ecef)
 *     --ss-shadow-md            rgba(11,31,28,0.06) light -> rgba(0,0,0,0.45) dark
 *     [data-repeater-item] computed border-color
 *                               rgb(221,223,227) light -> rgb(54,59,68) dark
 *
 * The LAST line is the one that matters: it is the view's own declaration, resolved by the browser,
 * really changing with the theme -- so the conversion is not inert, which three earlier repairs in
 * this project were. What is still NOT measured is the CONTRAST of the text on the hover surface,
 * because the dropdown is closed on first paint; that is stated here rather than implied.
 */
final class ThemeEditorShellLegibilityTest extends TestCase
{
    private const VERSION_PANEL = 'resources/views/theme_customize/_version-panel.blade.php';
    private const CUSTOMIZE = 'resources/views/theme_customize/customize.blade.php';
    private const ORDER = 'resources/views/theme_customize/order.blade.php';
    private const INDEX = 'resources/views/theme_customize/index.blade.php';
    private const PAGES = 'resources/views/theme_customize/pages.blade.php';
    private const DS_SHEET = 'public/css/store-syria-ds.css';

    /** Every view of the shell, so a new offender in any of them fails this file. */
    private const SHELL = [self::INDEX, self::PAGES, self::CUSTOMIZE, self::ORDER, self::VERSION_PANEL];

    /** The `D-25` files, which this slice must not touch. Measured, not quoted -- see the note. */
    private const D25_FILES = [
        'resources/views/layouts/app.blade.php',
        'resources/views/partision/menu-tree.blade.php',
        'resources/views/partision/menu-zones.blade.php',
    ];

    /** Measured in the running admin as the merchant, both languages. */
    private const HOST_PRIMARY_FG = [4, 34, 42];
    private const HOST_PRIMARY_BG = [51, 93, 168];
    private const HOST_WARNING_FG = [244, 180, 26];
    private const HOST_WARNING_BG_LIGHT = [253, 244, 221];
    private const HOST_OUTLINE_FG_DARK = [108, 117, 125];
    private const DARK_CARD = [41, 42, 51];

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
     * Blade comments stripped before any source assertion.
     *
     * A census that reads comments can be satisfied -- or broken -- by PROSE ABOUT THE THING IT
     * MEASURES, which this project has hit eight times. This file's own header names
     * `btn-primary`, `bg-warning` and `#f8f9fa` repeatedly, and so do the comments the repair
     * leaves in the views, so without stripping "no btn-primary remains" would be satisfiable by
     * the comment explaining its removal.
     */
    private function blade(string $relative): string
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
    //  PREMISES
    // =============================================================================================

    public function test_premise_the_three_measured_colour_pairs_really_fail_aa(): void
    {
        // The numbers this file is built on, recomputed rather than quoted.
        $primary = $this->ratio(self::HOST_PRIMARY_FG, self::HOST_PRIMARY_BG);
        $warning = $this->ratio(self::HOST_WARNING_FG, self::HOST_WARNING_BG_LIGHT);
        $outline = $this->ratio(self::HOST_OUTLINE_FG_DARK, self::DARK_CARD);

        $this->assertSame(2.58, round($primary, 2));
        $this->assertSame(1.68, round($warning, 2));
        $this->assertSame(3.04, round($outline, 2));

        foreach ([$primary, $warning, $outline] as $measured) {
            $this->assertLessThan(self::AA, $measured);
        }
    }

    public function test_premise_the_design_system_owns_every_class_and_token_this_repair_uses(): void
    {
        /*
         * NO NEW CSS. `store-syria-ds.css` is shared by every admin screen and all 70 modules, so a
         * repair that needed a new rule would be a far larger change than this one claims to be.
         */
        $sheet = (string) preg_replace('#/\*.*?\*/#s', '', $this->read(self::DS_SHEET));

        foreach ([
            '.ss-btn ', '.ss-btn--primary ', '.ss-btn--secondary ', '.ss-btn--sm ',
            '.ss-badge ', '.ss-badge--success ', '.ss-badge--warning ',
        ] as $class) {
            $this->assertStringContainsString($class, $sheet, $class . ' is not declared');
        }

        foreach (['--ss-surface-hover', '--ss-border'] as $token) {
            $this->assertStringContainsString($token . ':', $sheet, $token . ' is not declared');

            /*
             * A TOKEN WITH NO DARK VALUE WOULD MAKE THIS CONVERSION INERT in exactly the way the
             * hex literal is: it would simply be a light colour under another name. Asserted per
             * token rather than assumed from the sheet having a dark block somewhere.
             */
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($sheet, $token . ':'),
                $token . ' is declared once, so it has no dark-mode value and swapping a light hex '
                . 'for it would change nothing in the theme that is broken.'
            );
        }
    }

    public function test_premise_the_dark_theme_is_an_attribute_the_server_sets(): void
    {
        /*
         * WHY A `var()` IN THE VIEW'S OWN PUSHED BLOCK IS ENOUGH. If the admin's dark mode were
         * `prefers-color-scheme` only, the tokens would still resolve -- but the capture harness
         * could not drive it, and this slice's measurements would be of one theme twice. The admin
         * sets the attribute server-side, which is also why `style-dark.css` is in play at all.
         */
        $layout = $this->blade('resources/views/layouts/app.blade.php');

        $this->assertStringContainsString('data-ss-theme', $layout);
    }

    // =============================================================================================
    //  THE REPAIR
    // =============================================================================================

    public function test_no_host_primary_fill_survives_anywhere_in_the_shell(): void
    {
        /*
         * ACROSS ALL FIVE VIEWS, not only the three that had findings. The capture reaches a first
         * paint; `btn-primary` inside a collapsed panel or an absent repeater would be just as
         * broken and invisible, which is how this defect reached a surface nobody had measured.
         */
        foreach (self::SHELL as $view) {
            $this->assertSame(
                0,
                substr_count($this->blade($view), 'btn-primary'),
                $view . ' still carries the host primary fill, measured at 2.58:1 '
                . '(rgb(4,34,42) on rgb(51,93,168)) in the dark admin theme. Use '
                . '`ss-btn ss-btn--primary` (plus `ss-btn--sm` where the host had `btn-sm`).'
            );
        }
    }

    public function test_every_primary_button_in_the_shell_is_converted(): void
    {
        /*
         * AN EXACT COUNT PER FILE: an assertion that merely finds the repaired shape SOMEWHERE
         * passes while a reverted sibling sits beside it -- three of GAP-179 slice 2's mutations
         * survived exactly that way.
         *
         * FIVE, NOT FOUR, AND THE FIFTH WAS FOUND BY THE ACROSS-ALL-VIEWS ASSERTION RATHER THAN BY
         * THE CAPTURE. The capture grouped `button.btn.btn-primary` x4 and
         * `button.btn.btn-sm.btn-primary` x4 across the four screens it drove;
         * `index.blade.php:32` carries one more on an `<a>`, and this test's sibling --
         * "no host primary fill survives ANYWHERE in the shell" -- failed at 1 after every
         * measured finding was closed. That is the census doing its job: a defect inside a
         * collapsed panel, an absent repeater or a per-theme card is just as broken and invisible,
         * which is how this surface reached GAP-113 with zero rendered evidence at all.
         */
        foreach ([
            self::INDEX => 1,
            self::CUSTOMIZE => 2,
            self::ORDER => 1,
            self::VERSION_PANEL => 1,
        ] as $view => $expected) {
            $this->assertSame(
                $expected,
                substr_count($this->blade($view), 'ss-btn--primary'),
                $view . ' should carry exactly ' . $expected . ' design-system primary buttons.'
            );
        }
    }

    public function test_the_version_badges_moved_as_one_set(): void
    {
        /*
         * THE SET MOVES TOGETHER, KEEPING THE DISTINCTION -- the same decision GAP-179 took for the
         * Yes/No rows on the LynomiaChat operator page. Only `bg-warning` FAILED (1.68:1 light);
         * `bg-success` and `bg-secondary` passed. Converting one of three would leave two host
         * badges beside one design-system badge in the same row, which is a worse screen than the
         * one that had a contrast defect. The host utilities are REMOVED rather than joined,
         * because `bg-warning` carries `!important` and a design-system class beside it would
         * change nothing on screen while reading as a repair.
         */
        $panel = $this->blade(self::VERSION_PANEL);

        foreach (['bg-warning', 'bg-success', 'bg-secondary'] as $hostUtility) {
            $this->assertSame(
                0,
                substr_count($panel, $hostUtility),
                self::VERSION_PANEL . ' still carries the host utility `' . $hostUtility . '`.'
            );
        }

        $this->assertSame(1, substr_count($panel, 'ss-badge--warning'));
        $this->assertSame(1, substr_count($panel, 'ss-badge--success'));

        /*
         * `text-dark` WENT WITH THE BADGE AND NOT ON ITS OWN. It existed to make the amber chip
         * legible and is what produced rgb(244,180,26) on rgb(253,244,221); `.ss-badge--warning`
         * sets both halves from `--ss-warning-bg` / `--ss-warning-fg`, so leaving `text-dark`
         * behind would pin a light-theme ink onto a chip that now has a dark-theme surface.
         */
        $this->assertSame(
            0,
            substr_count($panel, 'text-dark'),
            'the host `text-dark` ink is still on the badge whose surface has changed.'
        );
    }

    public function test_the_preview_draft_control_is_converted(): void
    {
        $panel = $this->blade(self::VERSION_PANEL);

        $this->assertSame(
            0,
            substr_count($panel, 'btn-outline-secondary'),
            'the Preview draft link still carries the host outline button, measured at 3.04:1 '
            . '(rgb(108,117,125) on rgb(41,42,51)) in dark.'
        );
        $this->assertSame(1, substr_count($panel, 'ss-btn--secondary'));
    }

    public function test_the_save_as_draft_button_is_deliberately_left_on_the_host(): void
    {
        /*
         * A NEGATIVE RESULT, ASSERTED SO IT CANNOT QUIETLY BECOME A REPAIR SOMEBODY TAKES CREDIT
         * FOR -- and it sits one element away from one that WAS repaired, which is exactly when a
         * later reader "tidies" it.
         *
         * `btn-sm btn-outline-primary` MEASURES 6.42:1 light and 4.65:1 dark against a 4.5:1
         * floor, so it passes in both and was not among the 14 findings. It is left alone for the
         * same reason GAP-179 left the included-feature icons at 4.65:1: converting an element no
         * run has measured as failing is how 23 of that slice's 30 conversions ended up with no
         * measured proof.
         *
         * THE DARK MARGIN IS 0.15 AND THAT IS WORTH SAYING OUT LOUD rather than recording a pass.
         * It is the host's `--theme-color` on the dark card; anything that darkens that card or
         * re-points the brand token takes it below AA, and nothing here would notice. Recorded for
         * the same palette conversation as GAP-269.
         */
        $panel = $this->blade(self::VERSION_PANEL);

        $this->assertSame(
            1,
            substr_count($panel, 'btn-outline-primary'),
            'the Save as draft button has been converted. It measured 6.42:1 light / 4.65:1 dark '
            . '-- a PASS in both -- so a conversion here closes no measured failure and this '
            . 'exclusion must be re-read rather than deleted.'
        );
    }

    public function test_the_pushed_style_block_carries_no_light_only_literal(): void
    {
        /*
         * THE PART A CAPTURE CANNOT SEE. `.icon-item:hover` is a hover state inside a dropdown that
         * is closed on first paint, and `[data-repeater-item]` needs a theme page that declares a
         * repeater -- so neither appeared in the 16-cell run and no measured proof is claimed for
         * either. What IS claimed is that a hard-coded light hex in a server-side dark theme is a
         * light surface in dark mode by construction, and the design system already owns the token
         * that answers in both.
         */
        $source = $this->blade(self::CUSTOMIZE);

        if (! preg_match('/@push\(\'css\'\)(.*?)@endpush/s', $source, $m)) {
            $this->fail(self::CUSTOMIZE . ' no longer pushes a css block; re-read this test.');
        }

        // SCOPED TO THE BLOCK. An assertion over the whole 42KB view would answer "does this
        // string exist anywhere in the file", which is the census trap in its other form.
        $block = (string) preg_replace('#/\*.*?\*/#s', '', $m[1]);

        $this->assertSame(
            [],
            array_values(array_unique(preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $block, $hex) ? $hex[0] : [])),
            'the editor\'s pushed style block still hard-codes a colour. The admin theme is an '
            . 'attribute the server sets, so a literal stays light in dark mode.'
        );

        $this->assertSame(
            0,
            preg_match_all('/rgba?\(\s*\d/', $block),
            'the editor\'s pushed style block still hard-codes an rgb()/rgba() colour.'
        );

        foreach (['--ss-surface-hover', '--ss-border'] as $token) {
            $this->assertStringContainsString(
                'var(' . $token,
                $block,
                $token . ' is not used by the block, so the conversion is incomplete.'
            );
        }
    }

    public function test_the_shared_chrome_finding_is_left_for_the_owner(): void
    {
        /*
         * A NEGATIVE RESULT, ASSERTED SO IT CANNOT QUIETLY BECOME A REPAIR SOMEBODY TAKES CREDIT
         * FOR. `span.dash-mtext` at 2.58:1 dark is 2 of this surface's 14 measured findings and it
         * is `D-25`: shared chrome on every merchant screen and all 70 modules, reserved to the
         * owner's scope decision.
         *
         * ALL THREE FILES ARE NAMED, because the register's own citation is off by one:
         * `partision/sidebar.blade.php` contains `dash-mtext` ZERO times, while
         * `partision/menu-tree.blade.php` and `partision/menu-zones.blade.php` do. An agent told
         * to avoid the two named files could edit a third and take the decision without noticing.
         */
        $found = 0;

        foreach (self::D25_FILES as $relative) {
            $source = $this->blade($relative);
            $found += substr_count($source, 'dash-mtext');

            $this->assertSame(
                0,
                substr_count($source, 'ss-text-strong'),
                $relative . ' now carries a design-system tone class. That is D-25 and it is the '
                . 'owner\'s decision, not a slice\'s.'
            );
        }

        $this->assertGreaterThan(0, $found, 'dash-mtext is gone from the shared chrome.');
    }
}
