<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE SUBDUED TEXT ON EVERY MERCHANT SCREEN FAILED AA, IN BOTH THEMES.  P2.5-DESIGN-F
 * =================================================================================================
 *
 * Census across /dashboard /finance /customer /order /product-list /setting, grouped by the colour
 * itself (docs/development/phase-2.5/tools/census-low-contrast-text.cjs) — because a per-screen
 * sweep that prints its first five findings hides how many distinct COLOURS are involved:
 *
 *     DARK   rgb(128,129,145) on rgb(41,42,51)    3.71:1   x51   /dashboard /product-list
 *     DARK   rgb(108,117,125) on rgb(41,42,51)    3.04:1   x15   /finance
 *     DARK   rgb(128,129,145) on rgb(34,36,44)    4.03:1   x5    /product-list
 *     DARK   rgb(108,117,125) on rgb(34,36,44)    3.30:1   x6    all six — the footer
 *     LIGHT  rgb(108,117,125) on rgb(249,250,251) 4.49:1   x7    all six
 *
 * IT IS TWO GREYS, NOT ONE, and aiming at either alone leaves the other while the five-line sweep
 * output looks addressed. Asked of Chromium rather than grepped:
 *
 *   `.text-muted { color: #6c757d !important }`   — Bootstrap's default, byte-identical in the
 *                                                   LIGHT and DARK sheets. It is theme-blind, and
 *                                                   that is why it fails in both.
 *   `.details-card, .dashboard-card { color: #808191 !important }`
 *                                                 — a CONTAINER rule, so every element inside a
 *                                                   dashboard card inherits it whatever its tag.
 *   `… .dataTable-info, p:not([class^="ss-"]):not([class*=" ss-"]) { color: #808191 !important }`
 *
 * WHAT IT CARRIES IS NOT DECORATION: "Available Balance", "Pending Withdrawals", "Total Income",
 * the `500.00` column on /finance, `$462.00` on the dashboard. Money, at 3.04:1.
 *
 * `<p>` IS FULL-CONTRAST BODY TEXT IN LIGHT AND SUBDUED IN DARK — rgb(41,50,64) at 12.37:1 against
 * rgb(128,129,145) at 3.71:1. That asymmetry is the defect, not a design: the fix gives dark the
 * same two levels light already has, body at `--ss-fg` and muted at `--ss-fg-muted`.
 */
class MutedTextContrastTest extends TestCase
{
    private const SHEETS = ['public/css/custom.css', 'public/css/rtl-custom.css'];

    /** The DS exclusion idiom the HOST'S OWN rule already uses; reused rather than reinvented. */
    private const SS_EXCLUSION = ':not([class^="ss-"]):not([class*=" ss-"])';

    private function sheet(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function block(string $rel): string
    {
        $css = $this->sheet($rel);
        $heading = strpos($css, 'P2.5-DESIGN-F');
        $this->assertNotFalse($heading, "premise: the block exists in {$rel}");

        // Anchored on the comment OPENER so the stripper below has a `/*` to open on. Slicing from
        // the heading leaves the slice starting INSIDE the comment and the prose survives — a
        // mistake already made twice in this package's sibling tests.
        $at = strrpos(substr($css, 0, $heading), '/*');
        $this->assertNotFalse($at, 'premise: the block is a comment followed by its rules');

        return (string) preg_replace('#/\*.*?\*/#s', '', substr($css, $at));
    }

    public static function sheets(): array
    {
        return ['custom.css' => ['public/css/custom.css'], 'rtl-custom.css' => ['public/css/rtl-custom.css']];
    }

    /**
     * `.text-muted` IS FIXED IN BOTH THEMES BY ONE RULE, because `--ss-fg-muted` is the token that
     * flips and `.text-muted` is the host rule that does not. A dark-only override would leave
     * light at 4.49:1 — under AA by a hair, on every screen's footer, which is exactly the kind of
     * near-miss that survives a sweep.
     *
     * @dataProvider sheets
     */
    public function test_the_muted_class_is_repaired_in_both_themes_by_a_theme_agnostic_rule(string $rel): void
    {
        $rules = $this->block($rel);

        preg_match('/([^{}]*\.text-muted[^{}]*)\{([^}]*)\}/', $rules, $m);
        $this->assertCount(3, $m, "{$rel}: premise: the block overrides `.text-muted`");

        $this->assertStringNotContainsString(
            'data-ss-theme',
            $m[1],
            "{$rel}: the `.text-muted` override must NOT be scoped to a theme. `--ss-fg-muted` "
            . 'already flips; scoping the rule as well leaves the other theme at its failing '
            . 'value — light measured 4.49:1, under AA on every screen.'
        );
        $this->assertStringContainsString('var(--ss-fg-muted)', $m[2], "{$rel}: `.text-muted` takes the muted token.");
        $this->assertStringContainsString('!important', $m[2], "{$rel}: the host's rule is `#6c757d !important`.");

        // THE EXCLUSION ON THIS ARM IS LOAD-BEARING FOR SPECIFICITY, not only for consistency.
        // The host's `p:not([class^="ss-"]):not([class*=" ss-"])` is (0,2,1); a plain
        // `html body .text-muted` is (0,1,2) -- FEWER CLASSES, so it loses. MEASURED: with the
        // plain selector the census fell from 84 failing nodes to 47 and every survivor was a
        // `p.text-muted` still at #808191. The same idiom here makes this arm (0,3,2), which wins.
        $this->assertStringContainsString(
            self::SS_EXCLUSION,
            $m[1],
            "{$rel}: the `.text-muted` arm `{$m[1]}` must carry the design system exclusion. "
            . 'Without it the arm is (0,2) against the host\'s (0,2,1) and loses on every '
            . '`p.text-muted` -- 47 nodes stayed at their failing colour when it was missing.'
        );
    }

    /**
     * THE DARK BODY RULE MUST NOT SWALLOW `.text-muted`. `p:not(…ss-…)` is more specific than a
     * plain `.text-muted` override, so without `:not(.text-muted)` a `<p class="text-muted">` —
     * which is most of /finance — comes out at FULL contrast and the two levels collapse into one.
     *
     * @dataProvider sheets
     */
    public function test_the_dark_body_rule_leaves_muted_text_muted(string $rel): void
    {
        $rules = $this->block($rel);

        preg_match('/(html\[data-ss-theme="dark"\][^{},]*\bp:not\([^{}]*)[,{]/', $rules, $m);
        $this->assertCount(2, $m, "{$rel}: premise: the block has a dark `p` arm");

        $this->assertStringContainsString(
            ':not(.text-muted)',
            $m[1],
            "{$rel}: the dark `p` arm `{$m[1]}` must exclude `.text-muted`, or it wins on "
            . 'specificity and every muted paragraph becomes body text.'
        );
        $this->assertStringContainsString(
            self::SS_EXCLUSION,
            $m[1],
            "{$rel}: the dark `p` arm must carry the design system exclusion the HOST'S OWN rule "
            . 'uses, or it repaints `.ss-section__note` and `.ss-page-header__subtitle`, which '
            . 'already declare `var(--ss-fg-muted)` and already pass.'
        );
    }

    /**
     * AND THE CONTAINER RULE, because `.details-card` / `.dashboard-card` set `#808191 !important`
     * on the CONTAINER: 51 of the 84 failing nodes are elements with no colour of their own
     * inheriting it — `<b>`, `<span>`, bare `<p>`. Overriding only `p` would leave them.
     *
     * @dataProvider sheets
     */
    public function test_the_dashboard_card_container_is_repaired(string $rel): void
    {
        $rules = $this->block($rel);

        foreach (['.details-card', '.dashboard-card'] as $container) {
            $this->assertMatchesRegularExpression(
                '/html\[data-ss-theme="dark"\][^{},]*\\' . $container . '\b/',
                $rules,
                "{$rel}: `{$container}` sets `#808191 !important` on the container, so every "
                . 'element inside inherits it — 51 of the 84 failing nodes had no colour of their own.'
            );
        }
    }

    /** Both delivery paths. `rtl-custom.css` is served to Arabic merchants and is a separate file. */
    public function test_the_two_sheets_carry_the_same_block(): void
    {
        $blocks = array_map(fn ($s) => preg_replace('/\s+/', ' ', $this->block($s)), self::SHEETS);

        $this->assertSame(
            $blocks[0],
            $blocks[1],
            'custom.css and rtl-custom.css must carry the same rules. A fix written only into the '
            . 'LTR sheet reaches no Arabic merchant, and the English screenshots would show it working.'
        );
    }
}
