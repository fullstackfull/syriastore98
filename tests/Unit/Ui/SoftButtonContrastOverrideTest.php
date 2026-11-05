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
 *     info          2.52  BAD     8.72        2.52  BAD    17.23
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

    /** Only the TEXT moves. A fill change would alter every vendored module's button shapes. */
    public function test_no_override_changes_a_fill(): void
    {
        $css = $this->css();
        $at = strpos($css, 'Host override defence, part 2');
        $this->assertNotFalse($at, 'premise: the block is where it is expected');

        $block = substr($css, $at, 4200);

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
