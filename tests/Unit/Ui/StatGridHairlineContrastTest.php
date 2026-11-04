<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE DASHBOARD'S STAT GRID HAS NO BOX, SO ITS HAIRLINES ARE THE WHOLE STRUCTURE.  P2.5-DESIGN-D
 * =================================================================================================
 *
 * The component's own comment states the design: *"Measures get no card. A number does not need a
 * box, and if every region on the dashboard is a card then nothing on it has emphasis. Cells are
 * divided by hairlines instead."* That is a good decision and it is not what was being delivered.
 *
 * MEASURED IN CHROMIUM, against the REAL PAINTED background rather than a computed style:
 *
 *                            before            after
 *     stat-grid hairline     1.28:1 light      3.23:1
 *                            1.38:1 dark       4.00:1
 *
 * At 1.28:1 the rules are not a quiet structure, they are nothing, and the four figures read as
 * text floating on the page. WCAG 1.4.11 asks 3:1 of *"parts of graphics required to understand the
 * content"* -- and when a component deliberately has no box, its dividers are exactly that.
 *
 * THE TEXT WAS NEVER THE PROBLEM, and the first pass nearly sent me to fix it. `.dash-content`'s
 * computed `background-color` is `rgba(0, 0, 0, 0)`, so a probe that reads it compares every colour
 * against BLACK: that run reported the light-mode VALUE at **1.24:1** and the label at 3.27:1, both
 * alarming and both false. Composited up to the first opaque ancestor the same colours measure
 * 16.15:1 and 6.14:1 in light, 12.84:1 and 6.72:1 in dark. The finding survived the correction; the
 * first diagnosis did not.
 *
 * `--ss-border-strong` IS AN EXISTING TOKEN, and the change is SCOPED to this component rather than
 * raised globally. `--ss-border` is right for a line on a card -- at this strength a card's own
 * border would be loud. The stat grid is the one component whose rules carry all of its structure.
 *
 * WHAT THIS FILE CANNOT SEE. It resolves tokens out of the stylesheet, so it computes the ratio
 * against `--ss-bg`, the design system's OWN page surface. In dark mode the painted page is
 * `#22242c`, which comes from the vendored host sheet and not from this token -- measured. The
 * token is the stricter of the two (`#1b1f25` is darker), so a pass here implies a pass there, and
 * the browser figures above are in `docs/design-system/evidence/design-d-2026-11-04/`.
 */
class StatGridHairlineContrastTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    /** WCAG 1.4.11 non-text contrast. */
    private const FLOOR = 3.0;

    private function css(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::SHEET;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

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

        $this->fail("Block {$needle} is never closed");
    }

    /**
     * @return array<string, string>
     *
     * COMMENTS ARE STRIPPED FIRST, and that is not tidiness. This file's token blocks carry long
     * prose between declarations, and `([^;}]+)` runs to the next `;` -- so a declaration followed
     * by a comment captures the comment too. Measured: `--ss-bg` in the dark block resolved to
     * "a card must sit above that page, a touch lighter, or it reads as a hole. … then the comment
     * close, then --ss-bg: #1b1f25" and the resolver failed with "Not a hex and not a plain
     * var()". (That ellipsis is deliberate: writing the literal comment-close INSIDE a docblock
     * ends the docblock, which is the SECOND time tonight I have done exactly that -- the first
     * was in DesignSystemStylesheetScopeTest, describing the very same class of defect in CSS.)
     * The same helper is
     * copied in `SwitchKnobContrastTest` WITHOUT this strip; it passes today because the tokens it
     * resolves happen to have no comment after them, which is luck rather than correctness.
     */
    private function declarations(string $body): array
    {
        $body = (string) preg_replace('#/\*.*?\*/#s', '', $body);

        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;}]+)/i', $body, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $d) {
            $out[$d[1]] = trim($d[2]);
        }

        return $out;
    }

    /** The two token maps the grid can actually be painted with, as the browser resolves them. */
    private function themes(string $css): array
    {
        $primitives = $this->declarations($this->block($css, ':root {'));
        $light = $this->declarations($this->block($css, ":root,\n[data-ss-theme=\"light\"] {"));
        $dark = $this->declarations($this->block($css, '[data-ss-theme="dark"] {'));

        return [
            'light' => array_merge($primitives, $light),
            'dark' => array_merge($primitives, $light, $dark),
        ];
    }

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
        $channel = static function (int $byte): float {
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

    /**
     * The border token the grid REALLY declares, read out of its own rule.
     *
     * THE FIRST DRAFT HARD-CODED `var(--ss-border-strong)` HERE, and the bite exposed it: reverting
     * the component to `--ss-border` reddened only `test_the_stat_grid_declares_the_strong_border`
     * while both contrast cases stayed GREEN, because they were measuring a token the component no
     * longer used. A contrast test that cannot notice the component changing is measuring an
     * aspiration. Reading the declaration makes the ratio the component's own.
     */
    private function declaredBorderToken(string $css): string
    {
        $stats = $this->block($css, '.ss-stats {');

        $this->assertSame(
            1,
            preg_match('/border-block-start:\s*1px\s+solid\s+(var\(--[a-z0-9-]+\))/i', $stats, $m),
            'the stat grid must declare exactly one leading hairline, as `1px solid var(--token)`'
        );

        return $m[1];
    }

    /** The instrument answers 21:1 for black on white, or every number below is decoration. */
    public function test_the_contrast_instrument_is_calibrated(): void
    {
        $this->assertEqualsWithDelta(21.0, $this->ratio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, $this->ratio('#767676', '#767676'), 0.01);
    }

    /** @dataProvider themeNames */
    public function test_the_grid_hairline_is_visible_against_the_page(string $theme): void
    {
        $css = $this->css();
        $tokens = $this->themes($css)[$theme];

        $declared = $this->declaredBorderToken($css);
        $border = $this->resolve($tokens, $declared);
        $page = $this->resolve($tokens, $tokens['--ss-bg']);

        $ratio = $this->ratio($border, $page);

        $this->assertGreaterThanOrEqual(
            self::FLOOR,
            $ratio,
            "In {$theme}, the stat grid declares {$declared}, which resolves to {$border} and "
            . 'measures ' . sprintf('%.2f', $ratio)
            . ":1 on the page ({$page}). The grid has NO BOX, so these "
            . 'rules are its entire structure: below 3:1 they are not a quiet divider, they are '
            . 'nothing, and the figures read as text floating on the page. `--ss-border` measured '
            . '1.28:1 in light and 1.38:1 in dark there.'
        );
    }

    /**
     * AND THE WEAKER TOKEN REALLY IS TOO WEAK HERE, which is what makes the case above a finding
     * rather than a preference. Without this, raising the floor to a value `--ss-border` already
     * met would look like the same test.
     *
     * @dataProvider themeNames
     */
    public function test_the_card_border_token_would_not_have_cleared_it(string $theme): void
    {
        $tokens = $this->themes($this->css())[$theme];

        $ratio = $this->ratio(
            $this->resolve($tokens, 'var(--ss-border)'),
            $this->resolve($tokens, $tokens['--ss-bg'])
        );

        $this->assertLessThan(
            self::FLOOR,
            $ratio,
            "In {$theme}, `--ss-border` measures " . sprintf('%.2f', $ratio) . ':1 on the page. If '
            . 'it now clears 3:1, the premise of this slice has changed and the stat grid should '
            . 'go back to the ordinary border token rather than keeping a stronger one for no '
            . 'reason.'
        );
    }

    /** The component really uses the strong token -- the ratio above is otherwise hypothetical. */
    public function test_the_stat_grid_declares_the_strong_border(): void
    {
        $css = $this->css();

        $stats = $this->block($css, '.ss-stats {');
        $stat = $this->block($css, '.ss-stat {');

        $this->assertStringContainsString(
            'border-block-start: 1px solid var(--ss-border-strong)',
            $stats,
            'the grid\'s leading rule must use the strong token'
        );
        foreach (['border-block-end', 'border-inline-end'] as $side) {
            $this->assertStringContainsString(
                "{$side}: 1px solid var(--ss-border-strong)",
                $stat,
                "the cell's {$side} must use the strong token too, or the grid is divided on "
                . 'one axis and not the other'
            );
        }
    }

    /**
     * AND THE CHANGE STAYS SCOPED. A card's own border at this strength would be loud, and
     * `--ss-border` is correct there; this slice must not have raised it globally.
     */
    public function test_the_card_keeps_the_ordinary_border(): void
    {
        $card = $this->block($this->css(), '.ss-card {');

        $this->assertStringContainsString(
            'var(--ss-border)',
            $card,
            'the card must keep the ordinary border token'
        );
        $this->assertStringNotContainsString(
            'var(--ss-border-strong)',
            $card,
            'the strong token is for a component with no box. A card has one.'
        );
    }

    public static function themeNames(): array
    {
        return ['light' => ['light'], 'dark' => ['dark']];
    }
}
