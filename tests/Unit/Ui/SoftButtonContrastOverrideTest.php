<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * SEVEN OF THE HOST'S EIGHT BUTTON VARIANTS FAILED AA SOMEWHERE.  P2.5-DESIGN-E
 * =================================================================================================
 *
 * `style.css` / `style-dark.css` build their soft buttons as `--bs-btn-color: X` over
 * `--bs-btn-bg: rgba(X, 0.15)` — a 15% tint of the button's OWN text colour. That is a light-page
 * idea, and it fails in both themes for opposite reasons: over white the tint leaves a pale chip
 * and the host puts the UNDILUTED hue on it; over a dark page the tint composites TOWARDS the page
 * until the text and its background converge.
 *
 * MEASURED in Chromium with all eight variants injected into the live admin so the cascade and the
 * page are real, and every translucent layer composited rather than skipped:
 *
 *                 LIGHT before   after      DARK before   after
 *     primary       6.42  ok      6.42        6.42  ok     6.42
 *     secondary     3.31  <AA    11.91        2.50  BAD     5.09
 *     success       2.39  BAD     7.48        6.18  ok      6.18
 *     danger        4.14  <AA     7.09        1.09  BAD     4.80
 *     warning       1.62  BAD     8.47        6.09  ok      6.09
 *     info          2.52  BAD     8.04        2.52  BAD     8.04
 *     light        14.63  ok     14.63       14.63  ok     14.63
 *     dark         11.26  ok     11.26        1.02  BAD    12.87
 *
 * `.btn-dark` in dark was `rgb(28,35,47)` on `rgb(33,36,44)` — the same colour twice. The label on
 * the control that opens a merchant's withdrawal requests could not be read at all.
 *
 * I FOUND DARK FIRST AND ASSUMED THAT WAS THE PROBLEM. It was not. The screen sweep that found it
 * only sees the variants a screen happens to use, and `/finance` uses four; injecting all eight
 * showed LIGHT is the worse theme, with `warning` at 1.62:1 the worst figure in either. A
 * dark-only fix would have left the worse half untouched and pointed the record at the wrong
 * theme.
 *
 * WHAT THIS FILE ASSERTS, AND WHAT IT CANNOT. The ratios above cannot be computed from this
 * stylesheet: the fills are `--bs-btn-bg` values in the VENDORED host sheets, composited against a
 * page background that the host also paints. So this is a source ratchet over the overrides, and
 * the ratios are measured out of suite by
 * `docs/development/phase-2.5/tools/measure-button-contrast.cjs`, which injects every variant into
 * the running admin and prints each composited figure. Run it after touching any of this.
 */
class SoftButtonContrastOverrideTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    /** The variants whose TEXT the host leaves unreadable, per theme. */
    private const DARK = ['dark', 'secondary', 'danger', 'info'];

    private const LIGHT = ['warning', 'success', 'info', 'danger', 'secondary'];

    private function css(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::SHEET;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The override block, BOUNDED BY ITS OWN SECTION HEADING AND WITH COMMENTS STRIPPED.
     *
     * BOTH HALVES OF THAT ARE REPAIRS. The two cases below used `substr($css, $at, 4200)`, a
     * magic window that covered the whole block when it was written and covered 63% of it one
     * comment later. That silently weakened `test_no_override_changes_a_fill` -- a `background:`
     * added in the uncovered tail would have passed -- and it made the info case match a
     * `.btn-info` written in PROSE, which ran on to the `.btn-dark` rule and reported that rule`s
     * colour as the info colour. A declaration counted over commented CSS counts the sentences
     * that mention a class.
     */
    private function block(): string
    {
        $css = $this->css();
        // ANCHORED ON THE COMMENT OPENER, not on the heading text. Starting at the heading
        // began the slice INSIDE the block comment, so the stripper below found no `/*` to
        // open on and left the whole prose in -- and the prose names `.btn-info`.
        $at = strpos($css, '/* ---- Host override defence, part 2');
        $this->assertNotFalse($at, 'premise: the soft-button override block exists');

        $end = strpos($css, '/* ---- Utilities ---- */', $at);
        $this->assertNotFalse($end, 'premise: the block is bounded by the next section heading');

        $block = substr($css, $at, $end - $at);
        $this->assertGreaterThan(1500, strlen($block), 'premise: the block is not empty');

        return (string) preg_replace('#/\*.*?\*/#s', '', $block);
    }

    /** @dataProvider darkVariants */
    public function test_a_failing_variant_is_overridden_in_dark(string $variant): void
    {
        $this->assertMatchesRegularExpression(
            '/\[data-ss-theme="dark"\]\s+\.btn-' . $variant . '\b/',
            $this->css(),
            "`.btn-{$variant}` has no dark-theme text override. The host paints it as a tint of "
            . 'its own colour, which on a dark page converges with the text.'
        );
    }

    /** @dataProvider lightVariants */
    public function test_a_failing_variant_is_overridden_in_light(string $variant): void
    {
        $this->assertMatchesRegularExpression(
            '/:root:not\(\[data-ss-theme="dark"\]\)\s+\.btn-' . $variant . '\b/',
            $this->css(),
            "`.btn-{$variant}` has no light-theme text override. LIGHT is the worse theme here — "
            . '`warning` measured 1.62:1 — and a dark-only fix leaves it.'
        );
    }

    /**
     * THE LIGHT `.btn-secondary` MUST NOT TAKE `--ss-fg-muted`, and this is the one case where the
     * obvious token is the wrong one: `--ss-fg-muted` resolves to grey-600, which IS the host's
     * failing colour (`rgb(108,117,125)`). Using it would change nothing and the test above would
     * still pass.
     */
    public function test_the_light_secondary_does_not_take_the_token_that_would_change_nothing(): void
    {
        $css = $this->css();
        $at = strpos($css, ':root:not([data-ss-theme="dark"]) .btn-secondary');

        $this->assertNotFalse($at, 'premise: the light secondary override exists');

        $rule = substr($css, $at, 220);

        $this->assertStringNotContainsString(
            '--ss-fg-muted',
            $rule,
            '`--ss-fg-muted` is grey-600, which is exactly the colour the host already uses and '
            . 'which measures 3.31:1 on the secondary tint. The override has to be darker.'
        );
        $this->assertStringContainsString('var(--ss-fg)', $rule);
    }

    /**
     * Every override is `!important`, because the host sets these through `--bs-btn-color`.
     *
     * THE PREMISE BELOW IS NOT CEREMONY. Without it this case asserts "no override lacks
     * !important", which is trivially true when there are NO OVERRIDES AT ALL — and the bite
     * proved it: removing the whole block reddened 11 of the 12 cases here and left this one
     * GREEN. A guard that passes when the thing it guards is gone is the failure mode this
     * project keeps recording.
     */
    public function test_every_override_carries_important(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(
            'Host override defence, part 2',
            $css,
            'premise: the soft-button override block exists at all'
        );

        foreach (array_unique(array_merge(self::DARK, self::LIGHT)) as $variant) {
            $this->assertMatchesRegularExpression(
                '/\.btn-' . $variant . '[^{]*\{[^}]*color:[^;]*!important/',
                $css,
                "premise: `.btn-{$variant}` is overridden somewhere"
            );
            $this->assertSame(
                0,
                preg_match(
                    '/\.btn-' . $variant . '[^{]*\{\s*color:\s*var\(--ss-[a-z-]+\);\s*\}/',
                    $css
                ),
                "the `.btn-{$variant}` override must carry !important: the host sets this through "
                . '`--bs-btn-color`, and a plain declaration does not reach it.'
            );
        }
    }

    /**
     * `.btn-info` IS THE ONE CHIP THE HOST DOES NOT CHANGE WITH THE THEME — it is `#e8f8ff` in
     * both sheets — so it must take ONE colour across both, and that colour must come from a
     * token that does not itself flip.
     *
     * TWO TOKENS LOOK RIGHT AND ARE NOT. `--ss-info-fg` is the obvious one and it flips: indigo
     * in light, a light lavender in dark, and the lavender on that pale chip IS the 2.52:1
     * failure this block exists to remove — so a rule written once with it would reintroduce the
     * defect in exactly one theme. It is also the wrong hue: the design system's info family is
     * INDIGO and the host's chip is CYAN, which the first version of this block paired at 8.72:1
     * and which still read as two different "info" colours on one button. `--ss-fg-onfill` flips
     * the same way (white in light, grey-950 in dark).
     *
     * The cyan ramp is a PRIMITIVE, defined once on `:root`, so it is the same in both themes.
     */
    public function test_the_info_override_is_one_theme_independent_colour(): void
    {
        $block = $this->block();

        preg_match_all('/\.btn-info[^{]*\{([^}]*)\}/', $block, $m);

        $this->assertNotEmpty($m[1], 'premise: `.btn-info` is overridden in this block at all');
        $this->assertCount(
            1,
            $m[1],
            'the info chip is the same colour in both themes, so it takes ONE rule. Two rules is '
            . 'how it ended up with indigo in light and grey-950 in dark for one identical chip.'
        );

        $this->assertStringNotContainsString(
            '--ss-info-fg',
            $m[1][0],
            '`--ss-info-fg` flips per theme (indigo-800 / #b6b1ef) and the chip does not. In dark '
            . 'it would put a light lavender on a pale cyan chip — the original 2.52:1 defect.'
        );
        $this->assertStringNotContainsString('--ss-fg-onfill', $m[1][0], '`--ss-fg-onfill` flips too.');
        $this->assertStringContainsString(
            '--ss-cyan-',
            $m[1][0],
            'the info text takes the LOGO\'S OWN cyan ramp, which is the chip\'s own hue and is '
            . 'a primitive, so it does not change with the theme.'
        );
    }

    /** Only the TEXT moves. A fill change would alter every vendored module's button shapes. */
    public function test_no_override_changes_a_fill(): void
    {
        $block = $this->block();

        foreach (['background', 'border-width', 'padding'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden . ':',
                $block,
                "the soft-button block must not set `{$forbidden}`. It changes text colour only — "
                . 'these classes are used across 70 vendored modules and a fill change would move '
                . "every one of their buttons."
            );
        }
    }

    public static function darkVariants(): array
    {
        return array_combine(self::DARK, array_map(static fn ($v) => [$v], self::DARK));
    }

    public static function lightVariants(): array
    {
        return array_combine(self::LIGHT, array_map(static fn ($v) => [$v], self::LIGHT));
    }
}
