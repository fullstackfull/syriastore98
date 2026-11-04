<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 ds-switch-knob-dark-16 — the switch knob was a hard-coded white that no theme could move.
 * =================================================================================================
 *
 * MEASURED DEFECT. `.ss-switch__track::after` painted the knob `background: var(--ss-grey-0)` —
 * a layer-1 primitive declared exactly once (`--ss-grey-0: #ffffff`) and re-themed nowhere in the
 * repository — so the knob stayed pure white in every theme. The knob's position inside the track
 * is the ONLY channel that carries the control's visual state (the track is 2.5rem x 1.5rem, the
 * knob 1.125rem at a 0.1875rem inset, and the checked offset is 1.1875rem, so 0.1875 + 1.125 +
 * 0.1875 = 1.5rem and 1.1875 + 1.125 + 0.1875 = 2.5rem exactly: the knob is fully enclosed in both
 * states and never breaks the track's silhouette). The ON track is `var(--ss-brand)`, which in dark
 * mode is `--ss-cyan-500` #2ac3e5. White on #2ac3e5 measures **2.09:1**, under WCAG 2.1 1.4.11's
 * 3:1 for the visual indicator of a component's state. The track recolour is not a second channel:
 * OFF-vs-ON track is 1.90:1 light and 1.61:1 dark.
 *
 * WHY A DARK KNOB IS FORCED BY ARITHMETIC, NOT TASTE. #2ac3e5 has a relative luminance of 0.4518,
 * so clearing 3:1 against it requires either L <= 0.0837 (a dark knob) or L >= 1.4059 — and white,
 * the lightest colour that exists, is only 2.09:1. Darkening the ON track instead is forbidden:
 * that is the brand fill on 29 controls across 16 settings views and is itself contrast-solved.
 *
 * THE REPAIR IS ONE TOKEN. The knob now reads `var(--ss-fg-onfill)` — semantically "the thing that
 * sits on a fill", which is exactly what a knob on a track is. In light mode it resolves to
 * `var(--ss-grey-0)` #ffffff, so light mode is byte-identical to the shipped sheet and cannot
 * regress by construction. Only dark moves, to `var(--ss-grey-950)` #0b121f.
 *
 * RATIOS, COMPUTED BY THIS FILE WITH THE WCAG 2.1 FORMULA (no rgba to composite — every colour on
 * these three boundaries is opaque):
 *
 *   light  knob #ffffff on ON  #335da8  6.42:1   (unchanged by the repair)
 *   light  knob #ffffff on OFF #888c93  3.38:1   (unchanged by the repair)
 *   dark   knob #0b121f on ON  #2ac3e5  8.96:1   (was 2.09:1 — the defect)
 *   dark   knob #0b121f on OFF #888c93  5.55:1   (was 3.38:1)
 *
 * TWO THINGS DELIBERATELY NOT CHANGED, both asserted below so a later "tidy-up" cannot take them.
 *
 * (1) THE OFF TRACK STAYS `var(--ss-grey-400)`. Swapping it to `--ss-border-strong` (which does
 * re-theme, to #7d828a) looks tidier and is a measurable REGRESSION: the OFF-track-against-card
 * boundary that the shipped gate already prints would fall from 4.21:1 to 3.68:1, in exchange for
 * nothing the defect required. The OFF state was never below threshold in either theme.
 *
 * (2) `--ss-surface` IS THE WRONG TOKEN FOR THE KNOB, even though it also keeps light identical.
 * In dark it is #262b33 — the card's own hex — so the knob would measure 1.00:1 against the card
 * behind it and cease to be a shape once `:disabled` drops `opacity: 0.45` on the track and
 * composites track and knob as one group over that card. `--ss-fg-onfill` leaves it at 1.32:1 and,
 * decisively, is NOT one of the eight tokens that `public/css/custom.css` and its RTL twin re-point
 * on `.dash-sidebar` inside `@media (max-width: 1024px)` — a block that is width-gated, NOT
 * theme-gated ("the sidebar is dark below 1025px even in light mode"), and that omits `--ss-brand`.
 * A knob reading `--ss-surface` would there be #263331 on the LIGHT brand #335da8 = 2.04:1, worse
 * than the defect being repaired. The last test in this file is what keeps that door shut.
 *
 * EXEMPT AND NOT ASSERTED AT 3:1: the `:disabled` group opacity (1.4.11 exempts "part of an
 * inactive user interface component") and the `@media (forced-colors: active)` block, where the OS
 * palette owns the knob. Both are asserted PRESENT and unchanged instead.
 */
class SwitchKnobContrastTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    /** WCAG 2.1 1.4.11 — non-text contrast for the visual indicator of a component's state. */
    private const MIN_RATIO = 3.0;

    private function sheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::SHEET);
    }

    /* ----------------------------------------------------------------------
       A small CSS reader. It resolves tokens rather than pinning hexes, so a
       later recolour of a primitive is measured instead of silently believed.
       ---------------------------------------------------------------------- */

    /** The brace-matched body of the first block whose selector text is $needle. */
    private function block(string $css, string $needle): string
    {
        $at = strpos($css, $needle);
        $this->assertNotFalse($at, "Block {$needle} is gone from " . self::SHEET);

        $open = strpos($css, '{', $at);
        $depth = 0;
        for ($i = $open; $i < strlen($css); $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $open + 1, $i - $open - 1);
                }
            }
        }

        $this->fail("Unbalanced braces after {$needle}");
    }

    /** Custom-property declarations in a block body, last-wins as the cascade does. */
    private function declarations(string $body): array
    {
        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;}]+)/i', $body, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $d) {
            $out[$d[1]] = trim($d[2]);
        }

        return $out;
    }

    /**
     * The three token maps a switch can actually be painted with. `:root` (the primitives) applies
     * under every theme, the light semantics are on `:root` too, and the dark blocks override a
     * subset on top of both — exactly the cascade the browser resolves.
     */
    private function themes(string $css): array
    {
        $primitives = $this->declarations($this->block($css, ":root {"));
        $light      = $this->declarations($this->block($css, ":root,\n[data-ss-theme=\"light\"] {"));
        $dark       = $this->declarations($this->block($css, "[data-ss-theme=\"dark\"] {"));
        $auto       = $this->declarations($this->block($css, "[data-ss-theme=\"auto\"] {"));

        return [
            'light' => array_merge($primitives, $light),
            'dark'  => array_merge($primitives, $light, $dark),
            'auto'  => array_merge($primitives, $light, $auto),
        ];
    }

    /** Follow a var() chain to a literal hex. */
    private function resolve(array $tokens, string $value): string
    {
        for ($hop = 0; $hop < 10; $hop++) {
            $value = trim($value);
            if (preg_match('/^#[0-9a-f]{6}$/i', $value)) {
                return strtolower($value);
            }
            if (! preg_match('/^var\(\s*(--[a-z0-9-]+)\s*\)$/i', $value, $m)) {
                $this->fail("Not a hex and not a plain var(): {$value}");
            }
            $this->assertArrayHasKey($m[1], $tokens, "Token {$m[1]} is declared nowhere");
            $value = $tokens[$m[1]];
        }

        $this->fail("var() chain did not terminate: {$value}");
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channel = function (int $byte): float {
            $c = $byte / 255;

            return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * $channel(hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel(hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel(hexdec(substr($hex, 4, 2)));
    }

    private function ratio(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** The `background` value of a rule, by its exact selector. */
    private function background(string $css, string $selector): string
    {
        $body = $this->block($css, $selector);
        $this->assertSame(
            1,
            preg_match('/(?<![a-z-])background\s*:\s*([^;}]+)/i', $body, $m),
            "No single `background` declaration in {$selector}"
        );

        return trim($m[1]);
    }

    /* ----------------------------------------------------------------------
       THE REPAIRED BEHAVIOUR
       ---------------------------------------------------------------------- */

    public function test_the_switch_knob_clears_three_to_one_against_both_tracks_in_every_theme(): void
    {
        $css    = $this->sheet();
        $themes = $this->themes($css);

        $knob    = $this->background($css, '.ss-switch__track::after {');
        $offRaw  = $this->background($css, '.ss-switch__track {');
        $onRaw   = $this->background($css, '.ss-switch__input:checked + .ss-switch__track {');

        foreach ($themes as $theme => $tokens) {
            $knobHex = $this->resolve($tokens, $knob);
            $offHex  = $this->resolve($tokens, $offRaw);
            $onHex   = $this->resolve($tokens, $onRaw);

            foreach (['ON' => $onHex, 'OFF' => $offHex] as $state => $trackHex) {
                $ratio = $this->ratio($knobHex, $trackHex);
                $this->assertGreaterThanOrEqual(
                    self::MIN_RATIO,
                    round($ratio, 2),
                    sprintf(
                        'WCAG 2.1 1.4.11: %s knob %s on the %s track %s measures %.2f:1, under 3:1',
                        $theme,
                        $knobHex,
                        $state,
                        $trackHex,
                        $ratio
                    )
                );
            }
        }

        // 2.09:1 in dark was the whole defect: the knob never re-themed, so it could not be fixed
        // anywhere but here.
        $this->assertSame('var(--ss-fg-onfill)', $knob, 'The knob must read a token that re-themes');
    }

    /**
     * The exact numbers, so a recolour that still clears 3:1 but moves these has to come back here
     * and restate them. Every figure is computed above, not copied from a report.
     */
    public function test_the_measured_ratios_are_the_ones_recorded_in_the_stylesheet(): void
    {
        $css    = $this->sheet();
        $themes = $this->themes($css);

        $knob = $this->background($css, '.ss-switch__track::after {');
        $off  = $this->background($css, '.ss-switch__track {');
        $on   = $this->background($css, '.ss-switch__input:checked + .ss-switch__track {');

        $measured = [];
        foreach (['light', 'dark'] as $theme) {
            $t = $themes[$theme];
            $measured["{$theme}.on"]  = round($this->ratio($this->resolve($t, $knob), $this->resolve($t, $on)), 2);
            $measured["{$theme}.off"] = round($this->ratio($this->resolve($t, $knob), $this->resolve($t, $off)), 2);
        }

        $this->assertSame([
            'light.on'  => 6.42,   // #ffffff on --ss-blue-600  #335da8 — identical to the shipped sheet
            'light.off' => 3.38,   // #ffffff on --ss-grey-400   #888c93 — identical to the shipped sheet
            'dark.on'   => 8.96,   // #0b121f on --ss-cyan-500   #2ac3e5 — was 2.09:1
            'dark.off'  => 5.55,   // #0b121f on --ss-grey-400   #888c93 — was 3.38:1
        ], $measured);
    }

    /** The auto mirror must carry the same dark values, or a merchant on `auto` keeps the defect. */
    public function test_the_auto_mirror_resolves_the_knob_to_the_same_hex_as_the_dark_block(): void
    {
        $css    = $this->sheet();
        $themes = $this->themes($css);
        $knob   = $this->background($css, '.ss-switch__track::after {');

        $this->assertSame(
            $this->resolve($themes['dark'], $knob),
            $this->resolve($themes['auto'], $knob),
            'prefers-color-scheme mirror has drifted from [data-ss-theme="dark"]'
        );
    }

    /* ----------------------------------------------------------------------
       NEGATIVE CONTROLS
       ---------------------------------------------------------------------- */

    /**
     * CONTROL: the detector can still see the defect. The shipped value is re-measured here from
     * the live token table, and it must still be under threshold — if a future recolour of
     * `--ss-cyan-500` made white acceptable again, this test fails and tells us the repair's
     * premise has changed rather than quietly standing on a dead argument.
     */
    public function test_the_value_this_repair_replaced_would_still_fail_the_same_assertion(): void
    {
        $themes = $this->themes($this->sheet());

        $white = $this->resolve($themes['dark'], 'var(--ss-grey-0)');
        $onDark = $this->resolve($themes['dark'], 'var(--ss-brand)');

        $this->assertSame('#ffffff', $white);
        $this->assertSame('#2ac3e5', $onDark);
        $this->assertSame(2.09, round($this->ratio($white, $onDark), 2), 'The original defect ratio');
        $this->assertLessThan(self::MIN_RATIO, $this->ratio($white, $onDark));

        // And no light knob of any kind can clear it: L(#2ac3e5) = 0.4518, so 3:1 demands
        // L <= 0.0837. White is the lightest colour there is.
        $this->assertSame(0.4518, round($this->luminance($onDark), 4));
        $this->assertLessThan(self::MIN_RATIO, $this->ratio('#ffffff', $onDark));
    }

    /**
     * CONTROL: light mode is pixel-identical, and provably so rather than merely "still passing".
     * This is what makes the repair safe; if someone re-points `--ss-fg-onfill` in the light block,
     * light mode moves silently and a test that only checked "clears 3:1" would not notice.
     */
    public function test_light_mode_is_pixel_identical_to_the_shipped_sheet(): void
    {
        $css    = $this->sheet();
        $themes = $this->themes($css);
        $light  = $themes['light'];

        $knob = $this->background($css, '.ss-switch__track::after {');

        $this->assertSame(
            $this->resolve($light, 'var(--ss-grey-0)'),
            $this->resolve($light, $knob),
            'Light knob must still resolve to the #ffffff it has always been'
        );
        $this->assertSame('#ffffff', $this->resolve($light, $knob));

        // And the OFF track is untouched in both themes — deliberately not swapped to
        // --ss-border-strong, which would drop dark OFF-track-against-card 4.21:1 -> 3.68:1.
        $this->assertSame('var(--ss-grey-400)', $this->background($css, '.ss-switch__track {'));
        $this->assertSame('#888c93', $this->resolve($light, 'var(--ss-grey-400)'));
        $this->assertSame('#888c93', $this->resolve($themes['dark'], 'var(--ss-grey-400)'));
    }

    /**
     * CONTROL: the ON track stays the brand fill. The over-reaching repair is to darken it to buy
     * knob contrast — that recolours the brand on 29 controls and undoes the brand work that
     * shipped this week.
     */
    public function test_the_on_track_still_reads_the_brand_token(): void
    {
        $css = $this->sheet();

        $this->assertStringContainsString(
            '.ss-switch__input:checked + .ss-switch__track { background: var(--ss-brand); }',
            $css
        );
        $this->assertSame('#335da8', $this->resolve($this->themes($css)['light'], 'var(--ss-brand)'));
        $this->assertSame('#2ac3e5', $this->resolve($this->themes($css)['dark'], 'var(--ss-brand)'));
    }

    /**
     * CONTROL: geometry untouched, and no ring. A repair that bought contrast with a border or an
     * outline on the knob would shift these numbers and could let the knob escape the track —
     * destroying the only state channel it was supposed to protect.
     */
    public function test_the_switch_geometry_is_untouched_and_the_knob_carries_no_ring(): void
    {
        $css = $this->sheet();

        $track = $this->block($css, '.ss-switch__track {');
        $knob  = $this->block($css, '.ss-switch__track::after {');

        $this->assertStringContainsString('width: 2.5rem; height: 1.5rem;', $track);
        $this->assertStringContainsString('inset-block-start: 0.1875rem;', $knob);
        $this->assertStringContainsString('inset-inline-start: 0.1875rem;', $knob);
        $this->assertStringContainsString('width: 1.125rem; height: 1.125rem;', $knob);
        $this->assertStringContainsString(
            '.ss-switch__input:checked + .ss-switch__track::after { inset-inline-start: 1.1875rem; }',
            $css
        );

        // 0.1875 + 1.125 + 0.1875 = 1.5 and 1.1875 + 1.125 + 0.1875 = 2.5: enclosed in both states.
        $this->assertSame(1.5, 0.1875 + 1.125 + 0.1875);
        $this->assertSame(2.5, 1.1875 + 1.125 + 0.1875);

        foreach (['border', 'outline', 'box-shadow'] as $property) {
            $this->assertSame(
                0,
                preg_match('/(?<![a-z-])' . $property . '\s*:/i', $knob),
                "The knob must not buy contrast with a {$property}"
            );
        }
    }

    /**
     * CONTROL: direction-neutral. The knob is positioned with logical properties, so the colour
     * change cannot affect RTL — but assert no physical left/right crept into the switch rules.
     */
    public function test_the_switch_rules_use_logical_properties_only(): void
    {
        $css = $this->sheet();

        foreach (['.ss-switch__track {', '.ss-switch__track::after {'] as $selector) {
            $body = $this->block($css, $selector);
            $this->assertSame(
                0,
                preg_match('/(?<![a-z-])(left|right)\s*:/i', $body),
                "Physical offset introduced in {$selector}"
            );
        }

        // No rule in either host sheet names the switch, so the LTR/RTL mirrored pair needs no
        // matching edit — asserted, not assumed.
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $host) {
            $this->assertStringNotContainsString(
                'ss-switch',
                (string) file_get_contents(dirname(__DIR__, 3) . '/' . $host),
                "{$host} has started styling the switch; the knob repair would need mirroring"
            );
        }
    }

    /**
     * CONTROL: the two exempt states survive. `:disabled` composites the track AND the knob as one
     * group at 0.45 opacity — 1.4.11 exempts an inactive component, and raising that opacity to
     * "fix" the ratio would delete the only signal that the control is disabled. Forced colors
     * hands the knob to the OS palette on purpose. Neither is asserted at 3:1.
     */
    public function test_the_disabled_and_forced_colors_exemptions_are_intact_and_unmeasured(): void
    {
        $css = $this->sheet();

        $this->assertStringContainsString(
            '.ss-switch__input:disabled + .ss-switch__track { opacity: 0.45; cursor: not-allowed; }',
            $css
        );
        $this->assertStringContainsString(
            '.ss-panel, .ss-chip, .ss-switch__track, .ss-switch__track::after { transition: none; }',
            $css
        );

        $forced = $this->block($css, '@media (forced-colors: active) {');
        $this->assertStringContainsString(
            '.ss-switch__input:checked + .ss-switch__track { background: Highlight; }',
            $forced
        );
        // No author colour on the knob inside forced colors: the OS owns it there.
        $this->assertSame(0, preg_match('/\.ss-switch__track::after\s*\{[^}]*background/i', $forced));
    }

    /**
     * CONTROL, AND THE ONE THAT CLOSES THE REAL TRAP. `.dash-sidebar` re-points eight DS tokens
     * inside `@media (max-width: 1024px)` with NO theme selector — it is dark below 1025px even in
     * light mode — and `--ss-brand` is not among them. Any knob token that appears in that set
     * would render #263331 on the LIGHT brand #335da8 = 2.04:1, worse than the defect repaired
     * here, in a place no theme-scoped checker looks. `--ss-fg-onfill` and `--ss-grey-400` must
     * therefore stay out of it.
     */
    public function test_neither_switch_token_is_repointed_by_the_host_sidebar_overrides(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $host) {
            $body = (string) file_get_contents($root . '/' . $host);

            foreach (['--ss-fg-onfill', '--ss-grey-0', '--ss-grey-950', '--ss-grey-400'] as $token) {
                $this->assertSame(
                    0,
                    preg_match('/' . preg_quote($token, '/') . '\s*:/', $body),
                    "{$host} re-points {$token}, which the switch knob or track now reads"
                );
            }

            // The sidebar block still exists and still omits --ss-brand, which is why a knob token
            // landing in it would be invisible to the theme-scoped contrast gate.
            $this->assertStringContainsString('--ss-border-strong:  #7d8a88;', $body);
            $this->assertSame(0, preg_match('/\.dash-sidebar\s*\{[^}]*--ss-brand\s*:/s', $body));
        }

        // Both switch tokens are declared in the design system and nowhere else.
        $sheet = $this->sheet();
        $this->assertSame(1, preg_match_all('/--ss-fg-onfill:\s*var\(--ss-grey-0\)/', $sheet));
        $this->assertSame(2, preg_match_all('/--ss-fg-onfill:\s*var\(--ss-grey-950\)/', $sheet));
        $this->assertSame(1, preg_match_all('/--ss-grey-400:\s*#888c93/', $sheet));
    }
}
