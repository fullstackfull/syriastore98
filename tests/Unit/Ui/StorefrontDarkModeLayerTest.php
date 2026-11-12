<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M2-B — THE INVARIANTS OF THE STOREFRONT DARK-MODE LAYER.
 *
 * THE FINDING THIS FILE PROTECTS. Tokenising a theme's `main-style.css` is necessary and NOT
 * SUFFICIENT. The storefront themes load `tailwind.js` — the runtime JIT CDN — and their Blade
 * markup carries colours as UTILITY CLASSES (`bg-white`, `text-black`, `text-gray-600`) which are
 * injected AFTER the theme stylesheet and win on source order. Measured on stylique with its
 * stylesheet fully tokenised: 18 dark-mode contrast failures, every one traced to a utility.
 *
 * So `public/css/storefront-appearance.css` maps those utilities to design-system tokens. The
 * rules below are the ways that layer could silently stop working — and "silently" is the point,
 * because every failure mode here still answers HTTP 200 and still looks correct in light mode.
 *
 * The BEHAVIOUR is measured in a browser by `verify-appearance.cjs` (the preference mechanism) and
 * by the four-combination capture matrix (the rendering). This file guards the structure those
 * depend on.
 */
class StorefrontDarkModeLayerTest extends TestCase
{
    private const LAYER = 'public/css/storefront-appearance.css';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function layer(): string
    {
        $p = self::root() . '/' . self::LAYER;
        self::assertFileExists($p, self::LAYER . ' is missing — the storefront has no dark mode.');

        return file_get_contents($p);
    }

    /** CSS with its comments removed. Prose has produced five false readings in this phase. */
    private static function css(string $body): string
    {
        return preg_replace('{/\*.*?\*/}s', '', $body);
    }

    /* ================================================================ both arms, always */

    /**
     * EVERY dark rule needs BOTH arms. `auto` is the DEFAULT state — a shopper who has never
     * touched the control is on `auto`, so a rule written only for `[data-ss-theme="dark"]` misses
     * most visitors. This is the exact mistake the stylique page-background fix made first: dark
     * surfaces and a white page.
     */
    #[Test]
    public function test_every_utility_override_has_an_auto_arm(): void
    {
        $css = self::css(self::layer());

        // The selectors overridden under the explicit dark attribute...
        preg_match_all('{\[data-ss-theme="dark"\]\s+([.a-z0-9_:()\[\]="-]+)}i', $css, $darkM);
        // ...and those under the auto arm.
        preg_match_all('{\[data-ss-theme="auto"\]\s+([.a-z0-9_:()\[\]="-]+)}i', $css, $autoM);

        $dark = array_unique($darkM[1]);
        $auto = array_unique($autoM[1]);

        $this->assertNotEmpty($dark, 'No dark overrides at all.');

        $missing = array_diff($dark, $auto);

        $this->assertSame([], array_values($missing),
            "These selectors are overridden for an explicit dark choice but NOT for `auto`, which "
            . "is the default state, so most shoppers would see them unthemed: \n  "
            . implode("\n  ", array_values($missing)));
    }

    #[Test]
    public function test_the_auto_arm_is_inside_a_prefers_color_scheme_query(): void
    {
        $css = self::css(self::layer());

        $firstAuto = strpos($css, '[data-ss-theme="auto"] .');

        if ($firstAuto === false) {
            $this->fail('No auto-scoped utility override at all.');
        }

        $query = strrpos(substr($css, 0, $firstAuto), '@media (prefers-color-scheme: dark)');

        $this->assertNotFalse($query,
            'The `auto` overrides are not inside `@media (prefers-color-scheme: dark)`, so they '
            . 'would apply on a LIGHT device too and every default visitor would get a dark page.');
    }

    /* ================================================================ light mode untouched */

    /**
     * The layer must not contain a rule that applies regardless of appearance — with ONE declared
     * exception. A rule outside both arms changes light mode, and the whole claim that light mode
     * is unchanged rests on there being none.
     */
    #[Test]
    public function test_no_rule_applies_outside_an_appearance_scope_except_the_declared_one(): void
    {
        $css = self::css(self::layer());

        // The ink-on-brand rule is the one deliberate exception: it repairs a measured 2.64:1 AA
        // failure that existed in LIGHT mode before any of this work.
        // `.text-primary` joins the exceptions in M2-C: a brand colour used as TEXT fails in BOTH
        // appearances on techzonix (4.33:1 light, 3.14:1 dark), so the rule has to apply in both.
        // It is theme-agnostic by indirection -- it reads `--brand-ink-on-surface`, which each
        // theme declares for its own palette, and a theme that declares nothing is unaffected.
        $allowed = ['.cart-badge.text-white', '.btn-primary.text-white', '.text-primary'];

        $unscoped = [];

        foreach (preg_split('{\}}', $css) as $chunk) {
            if (! str_contains($chunk, '{')) {
                continue;
            }

            $selector = trim(substr($chunk, 0, strpos($chunk, '{')));
            $selector = trim(preg_replace('~@media[^{]*\{~', '', $selector));

            if ($selector === '' || str_starts_with($selector, '@')) {
                continue;
            }

            if (str_contains($selector, 'data-ss-theme')) {
                continue;
            }

            foreach ($allowed as $ok) {
                if (str_contains($selector, $ok)) {
                    continue 2;
                }
            }

            $unscoped[] = $selector;
        }

        $this->assertSame([], $unscoped,
            "These rules apply in BOTH appearances, so they change light mode: \n  "
            . implode("\n  ", $unscoped)
            . "\nIf one is intended, add it to \$allowed here and say why in the stylesheet.");
    }

    /* ================================================================ color-scheme */

    /**
     * `color-scheme` is the one declaration no class mapping can replace. After every utility was
     * mapped, two `<input>` elements were still painted #ffffff on a dark page — not by any
     * utility, but by the browser's own stylesheet, which paints form controls from the `Field`
     * system colour until the document says which scheme it is in. It also re-themes scrollbars,
     * the canvas behind a short page, and the autofill tint.
     */
    #[Test]
    public function test_color_scheme_is_declared_for_all_three_states(): void
    {
        $css = self::css(self::layer());

        foreach (['light' => 'light', 'dark' => 'dark', 'auto' => 'light dark'] as $state => $value) {
            $this->assertMatchesRegularExpression(
                '{\[data-ss-theme="' . $state . '"\][^{]*\{[^}]*color-scheme:\s*' . preg_quote($value, '}') . '}s',
                $css,
                "`color-scheme: {$value}` is not declared for data-ss-theme=\"{$state}\". Without "
                . 'it the browser paints form controls, scrollbars and the canvas for the wrong '
                . 'scheme, and no class-based override can reach them.'
            );
        }
    }

    /* ================================================================ the token mapping */

    /**
     * Tailwind's grey ramp runs dark-to-light, so it must be INVERTED, not mapped across:
     * `text-gray-900` is the strongest ink in light mode and must stay the strongest in dark,
     * which is the LIGHTEST value. Mapping it to `--ss-fg-subtle` would make the most important
     * text the faintest.
     */
    #[Test]
    public function test_the_grey_ramp_is_inverted_rather_than_mapped_across(): void
    {
        $css = self::css(self::layer());

        $strong = '{\.text-(black|gray-900)[^{]*\{[^}]*var\(--ss-fg\)}s';
        $muted = '{\.text-gray-(600|500)[^{]*\{[^}]*var\(--ss-fg-muted\)}s';
        $subtle = '{\.text-gray-400[^{]*\{[^}]*var\(--ss-fg-subtle\)}s';

        $this->assertMatchesRegularExpression($strong, $css,
            'text-black / text-gray-900 no longer map to --ss-fg, the strongest ink.');
        $this->assertMatchesRegularExpression($muted, $css,
            'text-gray-600 / 500 no longer map to --ss-fg-muted.');
        $this->assertMatchesRegularExpression($subtle, $css,
            'text-gray-400 no longer maps to --ss-fg-subtle.');

        // And the inversion must not have been flattened: the strongest and the faintest utility
        // must not resolve to the same token.
        $this->assertDoesNotMatchRegularExpression('{\.text-gray-900[^{]*\{[^}]*var\(--ss-fg-subtle\)}s', $css,
            'text-gray-900 maps to the FAINTEST token, so the most important text is the least '
            . 'readable — the grey ramp was mapped across instead of inverted.');
    }

    /**
     * EVERY THEME MUST DECLARE `--brand-ink-on-surface`, AND THIS TEST EXISTS BECAUSE I BROKE
     * STYLIQUE BY FORGETTING IT.
     *
     * The shared layer's `.text-primary` rule reads that variable with `currentColor` as its
     * fallback. `currentColor` is not a no-op: a theme that does not declare the variable loses
     * its brand link colour and inherits body text instead. Measured the moment the rule landed —
     * stylique's eight `.text-primary` nodes went from coral `rgb(248, 121, 108)` to
     * `rgb(26, 29, 35)`, on a page that otherwise looked completely normal.
     *
     * That is the failure mode of a shared layer: a change made for one theme silently alters
     * another. A new theme that forgets the declaration now fails here instead of shipping.
     *
     * @return array<string, array{0: string}>
     */
    public static function themesNeedingBrandInk(): array
    {
        return ['stylique' => ['stylique'], 'techzonix' => ['techzonix'], 'greentic' => ['greentic']];
    }

    #[Test]
    #[DataProvider('themesNeedingBrandInk')]
    public function test_every_theme_declares_its_brand_ink_for_both_appearances(string $theme): void
    {
        $path = self::root() . "/themes/{$theme}/assets/css/main-style.css";

        if (! is_file($path)) {
            $this->fail("themes/{$theme} has no main-style.css.");
        }

        $css = self::css(file_get_contents($path));

        $this->assertStringContainsString('--brand-ink-on-surface', $css,
            "themes/{$theme} does not declare --brand-ink-on-surface, so the shared layer's "
            . '`.text-primary` rule falls back to `currentColor` and that theme LOSES its brand '
            . 'link colour — it inherits body text instead. Measured on stylique: 8 nodes went '
            . 'from coral to grey on a page that looked entirely normal.');

        // And it must be declared for dark as well, or brand text keeps the light value on a dark
        // surface — which is where it fails hardest (techzonix measured 3.14:1 on the dark card).
        $declarations = preg_match_all('{--brand-ink-on-surface\s*:}', $css);

        $this->assertGreaterThanOrEqual(3, $declarations,
            "themes/{$theme} declares --brand-ink-on-surface {$declarations} time(s). It needs "
            . 'three: the light value in `:root`, and the dark value in BOTH the explicit '
            . '`[data-ss-theme="dark"]` arm and the `prefers-color-scheme` + `auto` arm.');
    }

    #[Test]
    public function test_the_layer_is_loaded_after_the_design_system(): void
    {
        $partial = file_get_contents(
            self::root() . '/resources/views/front_end/partials/appearance.blade.php');

        $ds = strpos($partial, 'store-syria-ds.css');
        $layer = strpos($partial, 'storefront-appearance.css');

        $this->assertNotFalse($layer, 'The storefront no longer loads the dark-mode layer.');
        $this->assertNotFalse($ds, 'The storefront no longer loads the design system.');
        $this->assertLessThan($layer, $ds,
            'The utility layer loads BEFORE the design system, so the `--ss-*` tokens it consumes '
            . 'are not declared yet.');
    }

    /* ================================================================ stylique's own contract */

    /** @return array<string, array{0: string}> */
    public static function semanticTokens(): array
    {
        return [
            '--surface-color' => ['--surface-color'],
            '--text-color' => ['--text-color'],
            '--border-color' => ['--border-color'],
            '--page-color' => ['--page-color'],
        ];
    }

    #[Test]
    #[DataProvider('semanticTokens')]
    public function test_styliques_semantic_tokens_keep_a_literal_fallback(string $token): void
    {
        $css = self::css(file_get_contents(
            self::root() . '/themes/stylique/assets/css/main-style.css'));

        $this->assertMatchesRegularExpression('{' . preg_quote($token, '}') . '\s*:}', $css,
            "stylique no longer declares {$token}.");

        // A token whose fallback is another var() was a real bug in the first draft: the literal
        // swap hit the token block's OWN fallback values and produced three self-referential
        // declarations, e.g. `--danger-color: var(--ss-danger, var(--danger-color))`.
        $this->assertDoesNotMatchRegularExpression(
            '{' . preg_quote($token, '}') . '\s*:\s*var\([^)]*,\s*var\(}',
            $css,
            "{$token}'s fallback is itself another var(), which is the self-referential bug the "
            . 'first tokenisation pass produced.'
        );
    }

    /**
     * The brand colours must NOT follow the appearance. A storefront that changes brand colour
     * when a shopper flips appearance has lost the identity the directive requires preserving.
     */
    #[Test]
    public function test_styliques_brand_colours_do_not_follow_the_appearance(): void
    {
        $css = self::css(file_get_contents(
            self::root() . '/themes/stylique/assets/css/main-style.css'));

        foreach (['--primary-color' => '#f8796c', '--accent-color' => '#6c63ff'] as $token => $value) {
            $this->assertMatchesRegularExpression(
                '{' . preg_quote($token, '}') . '\s*:\s*' . preg_quote($value, '}') . '\s*;}i',
                $css,
                "{$token} is no longer the literal {$value}. The brand colour must not be a "
                . 'theme-following token: stylique would change identity in dark mode.'
            );
        }

        // And the dark block must not redefine them.
        $darkBlocks = [];
        preg_match_all('~\[data-ss-theme="dark"\]\s*\{([^}]*)\}~s', $css, $darkBlocks);

        foreach ($darkBlocks[1] as $block) {
            $this->assertStringNotContainsString('--primary-color:', $block,
                'The dark block redefines --primary-color, so the brand changes in dark mode.');
            $this->assertStringNotContainsString('--accent-color:', $block,
                'The dark block redefines --accent-color.');
        }
    }

    /** `--primary-color-rgb` was used twice and never defined, so two box-shadows never rendered. */
    #[Test]
    public function test_every_custom_property_stylique_uses_is_also_declared(): void
    {
        $css = self::css(file_get_contents(
            self::root() . '/themes/stylique/assets/css/main-style.css'));

        preg_match_all('{var\((--[a-z0-9-]+)}i', $css, $used);
        preg_match_all('{^\s*(--[a-z0-9-]+)\s*:}mi', $css, $declared);

        $undeclared = array_values(array_unique(array_diff(
            $used[1],
            $declared[1],
            // The design system declares these, and FontAwesome declares its own `--fa-*`.
            array_filter($used[1], fn ($v) => str_starts_with($v, '--ss-') || str_starts_with($v, '--fa')),
        )));

        $this->assertSame([], $undeclared,
            "stylique uses these custom properties without declaring them, so every declaration "
            . "that reads one silently does not render: \n  " . implode("\n  ", $undeclared));
    }
}
