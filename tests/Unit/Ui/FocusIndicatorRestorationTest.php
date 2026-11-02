<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2-01-20 / WS A7 / F5 — the focus ring the host theme removes from every element.
 * =================================================================================================
 *
 * THE DEFECT WAS ONE DECLARATION, AND IT REACHED EVERYTHING. `public/assets/css/style.css:15685`
 * is `*:focus { outline: none; }` — and it is in ALL FOUR stylesheets the layout swaps between
 * (`style.css`, `style-dark.css`, `style-rtl.css`, `rtl-style-dark.css`). So a keyboard user on any
 * admin screen, in either language and either mode, across all 70 vendored WorkDo modules, could
 * not see where they were. Five design-system surfaces already manage focus correctly; every
 * control that is not a design-system component had no indicator at all.
 *
 * The owner's instruction for this row was explicit: treat the cause at the system level, not each
 * button. So the fix is one unconfined rule in one file, loaded once.
 *
 * THE FIX IS A RESTORATION, NOT A DELETION, and the distinction is what makes it safe. Removing the
 * theme's rule would bring the ring back for POINTER users too — a visible change to every control
 * on every screen in the product. `:focus-visible` is the mechanism that separates them: the
 * browser paints it for keyboard navigation and withholds it for a mouse click.
 *
 * WHAT THIS TEST CANNOT DO, stated rather than implied: it reads stylesheets and the layout. It
 * cannot prove a browser paints the ring, because that needs a rendered page and a real tab press —
 * the same constraint recorded against P2-07-8's reading order. What it CAN do is hold every
 * condition the paint depends on: the suppression still exists (so this file is still needed), the
 * restoration exists and uses `:focus-visible` (not `:focus`), it loads on every branch, it loads
 * AFTER the theme (so it wins), and it is NOT in the design system's stylesheet (so that file's
 * confinement guarantee survives).
 */
class FocusIndicatorRestorationTest extends TestCase
{
    private const THEMES = [
        'public/assets/css/style.css',
        'public/assets/css/style-dark.css',
        'public/assets/css/style-rtl.css',
        'public/assets/css/rtl-style-dark.css',
    ];

    private const RESTORATION = 'public/css/store-syria-a11y.css';
    private const LAYOUT = 'resources/views/layouts/app.blade.php';
    private const DESIGN_SYSTEM = 'public/css/store-syria-ds.css';

    private function read(string $relative): string
    {
        $path = __DIR__ . '/../../../' . $relative;

        $this->assertFileExists($path, $relative . ' is missing');

        return (string) file_get_contents($path);
    }

    /**
     * THE PREMISE, and it is the reason this file exists rather than a nicety. If a theme ever
     * stops suppressing `*:focus`, the restoration is redundant there and somebody should decide
     * whether to keep it — rather than it sitting unexamined forever.
     */
    public function test_every_theme_still_suppresses_the_focus_outline(): void
    {
        foreach (self::THEMES as $theme) {
            $this->assertMatchesRegularExpression(
                '/\*:focus\s*\{[^}]*outline:\s*none/i',
                $this->read($theme),
                $theme . ' no longer carries `*:focus { outline: none }`. If the theme has been '
                . 'fixed, store-syria-a11y.css may be redundant for this theme -- that is a '
                . 'decision to take deliberately, not a test to delete.'
            );
        }
    }

    /**
     * The restoration exists and paints a real outline.
     *
     * COMMENTS BLANKED, and this test is the third time in one day this repository's own lesson had
     * to be relearned: the first version asserted `outline: none` was absent from the FILE, and the
     * file's header quotes `*:focus { outline: none; }` to explain what it is fixing. The test
     * failed against correct code, on its own documentation. A source-text scanner must read code,
     * not prose.
     */
    public function test_the_restoration_paints_a_visible_outline(): void
    {
        $code = $this->codeOf(self::RESTORATION);

        $this->assertMatchesRegularExpression(
            '/\*:focus-visible\s*\{[^}]*outline:/i',
            $code,
            'store-syria-a11y.css does not restore an outline for `*:focus-visible`'
        );

        $this->assertStringNotContainsString('outline: none', $code,
            'the restoration suppresses an outline somewhere, which is the defect it exists to fix');
    }

    /** A stylesheet with its comments blanked -- blanked, not deleted, so line numbers survive. */
    private function codeOf(string $relative): string
    {
        return (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]),
            $this->read($relative)
        );
    }

    /**
     * `:focus-visible`, NOT `:focus`. This is the whole safety argument: a bare `*:focus` rule
     * would show a ring to pointer users on every control in the product, which is a visual change
     * nobody asked for and the reason the theme suppressed it in the first place.
     */
    public function test_the_restoration_is_keyboard_only(): void
    {
        // Comments blanked: this file's own header discusses `*:focus { outline: none; }`, and a
        // scanner that reads its prose would flag the thing it is explaining.
        $code = $this->codeOf(self::RESTORATION);

        $this->assertStringNotContainsString(':focus {', $code,
            'the restoration uses a bare `:focus`, so a pointer user would see a ring on every '
            . 'control. `:focus-visible` is what limits it to keyboard navigation.');

        $this->assertStringContainsString(':focus-visible', $code);
    }

    /**
     * IT USES THE DESIGN SYSTEM'S OWN TOKENS, so the ring a merchant sees on a Bootstrap button is
     * the same colour and weight as the one on an `.ss-btn`, and it follows the dark-mode override
     * the design system already defines. With concrete fallbacks, so it still paints if the design
     * system is not loaded on some page.
     */
    public function test_the_ring_reuses_the_design_system_tokens_with_fallbacks(): void
    {
        $css = $this->read(self::RESTORATION);

        foreach (['--ss-focus-width', '--ss-focus-ring', '--ss-focus-offset'] as $token) {
            $this->assertStringContainsString($token, $css,
                'the ring does not use ' . $token . ', so it can drift from the design system');
        }

        $this->assertMatchesRegularExpression(
            '/var\(--ss-focus-ring,\s*#[0-9a-f]{3,8}\s*\)/i',
            $css,
            'the ring colour has no concrete fallback, so a page without the design system would '
            . 'paint `outline: 2px solid` with no colour'
        );

        // And the tokens it reuses really are defined, including a dark-mode value.
        $ds = $this->read(self::DESIGN_SYSTEM);

        $this->assertStringContainsString('--ss-focus-ring:', $ds,
            'premise: the design system defines the token this file reads');
        $this->assertGreaterThan(
            1,
            preg_match_all('/--ss-focus-ring:/', $ds),
            'premise: the design system redefines --ss-focus-ring for dark mode, which is how this '
            . 'ring gets correct contrast in both modes without a second rule here'
        );
    }

    /**
     * LOADED ONCE, AFTER THE THEME, ON EVERY BRANCH. The layout chooses between four stylesheets in
     * an `@if`; a restoration inside one branch would cover one combination of language and mode.
     * And loaded after, because `*:focus-visible` and `*:focus` have the same specificity — source
     * order is the only thing that decides which wins.
     */
    public function test_the_layout_loads_it_after_every_theme_branch(): void
    {
        $layout = $this->read(self::LAYOUT);

        $linkAt = strpos($layout, 'store-syria-a11y.css');

        $this->assertNotFalse($linkAt, 'the layout does not load store-syria-a11y.css at all');

        foreach (['style.css', 'style-dark.css', 'style-rtl.css', 'rtl-style-dark.css'] as $theme) {
            $themeAt = strpos($layout, "assets/css/{$theme}");

            $this->assertNotFalse($themeAt, 'premise: the layout loads ' . $theme);
            $this->assertGreaterThan(
                $themeAt,
                $linkAt,
                'store-syria-a11y.css is loaded BEFORE ' . $theme . ', so the theme\'s '
                . '`*:focus { outline: none }` wins on source order and the ring stays invisible.'
            );
        }

        // One link, not one per branch -- which is what would drift.
        $this->assertSame(1, substr_count($layout, 'store-syria-a11y.css'),
            'store-syria-a11y.css is linked more than once; it belongs after the @if so one line '
            . 'covers all four combinations');
    }

    /**
     * AND NOT IN THE DESIGN SYSTEM'S STYLESHEET. That file carries an enforced property --
     * `DesignSystemStylesheetScopeTest` asserts every selector in it is confined by an `.ss-` token
     * so that loading it across 70 modules cannot reach an element the design system does not own.
     * An unconfined `*:focus-visible` there would destroy the guarantee, which is exactly why this
     * rule needed a file of its own.
     */
    public function test_the_unconfined_rule_is_not_in_the_design_system(): void
    {
        $this->assertStringNotContainsString('*:focus-visible', $this->read(self::DESIGN_SYSTEM),
            'an unconfined selector was added to store-syria-ds.css, which breaks the confinement '
            . 'property DesignSystemStylesheetScopeTest enforces');
    }
}
