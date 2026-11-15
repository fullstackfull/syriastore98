<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M2-A — THE INVARIANTS OF THE STOREFRONT APPEARANCE FOUNDATION.
 *
 * The BEHAVIOUR is driven in a real browser by
 * `docs/development/phase-3/tools/verify-appearance.cjs` (40 checks: localStorage,
 * `prefers-color-scheme`, a live OS change, keyboard activation, cross-store isolation on one
 * origin, blocked storage). None of that is reachable from PHPUnit, and pretending otherwise would
 * be the vacuous kind of test this project has already been bitten by three times.
 *
 * What IS checkable here is the structure the browser evidence depends on, and every one of these
 * is a way the feature could silently stop working:
 *
 *   - a theme layout that stops including the partial loses dark mode entirely, and the only
 *     symptom is that the page looks normal;
 *   - the toggle moving INSIDE `@auth('customers')` would hide it from every guest — the shopper
 *     most likely to want it — while every signed-in test still passed;
 *   - a storage key that stops carrying the store id would let one shop's choice re-theme another,
 *     because all stores share one origin;
 *   - a hardcoded English string would put English words on an Arabic page.
 */
class StorefrontAppearanceFoundationTest extends TestCase
{
    private const THEMES = ['stylique', 'techzonix', 'greentic'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        $p = self::root() . '/' . $rel;
        self::assertFileExists($p, "{$rel} is missing.");

        return file_get_contents($p);
    }

    /**
     * The file with its EXPLANATORY PROSE REMOVED — Blade comments, HTML comments, and JS line
     * and block comments.
     *
     * This exists because the first version of this test failed three cases against its own
     * documentation: the appearance partial's docblock explains *why* `aria-pressed` is not used
     * and *why* nothing touches `cust_darklayout`, so a naive `assertStringNotContainsString`
     * matched the explanation and reported the opposite of the truth.
     *
     * FOURTH TIME IN THIS PHASE that a check matched prose instead of code — the same root cause
     * as two vacuous source pins in M0 and a miscounted asset sweep in M1. Every content assertion
     * below reads through here for that reason.
     */
    private static function code(string $rel): string
    {
        $body = self::read($rel);

        $body = preg_replace('{\{\{--.*?--\}\}}s', '', $body);   // Blade comments
        $body = preg_replace('{<!--.*?-->}s', '', $body);            // HTML comments
        $body = preg_replace('{/\*.*?\*/}s', '', $body);           // JS/CSS block comments
        $body = preg_replace('{^\s*//[^\n]*$}m', '', $body);       // JS line comments

        return $body;
    }

    /** @return array<string, array{0: string}> */
    public static function themes(): array
    {
        $out = [];
        foreach (self::THEMES as $t) {
            $out[$t] = [$t];
        }

        return $out;
    }

    /* ============================================================ the partial itself */

    #[Test]
    public function test_the_partial_loads_the_design_system_and_sets_the_attribute_before_paint(): void
    {
        $partial = self::code('resources/views/front_end/partials/appearance.blade.php');

        $this->assertStringContainsString("asset('css/store-syria-ds.css')", $partial,
            'The storefront no longer loads the design system, so its ~146 dark-mode rules are '
            . 'unavailable and nothing re-themes.');

        $dsLink = strpos($partial, 'store-syria-ds.css');
        $script = strpos($partial, "setAttribute('data-ss-theme'");

        $this->assertNotFalse($script, 'Nothing sets data-ss-theme, so no page has an appearance.');

        // The script must be INLINE and in the same partial as the link. A deferred or external
        // script would let the light paint land and then flip — the visible flash the directive
        // forbids.
        $this->assertStringNotContainsString('defer', substr($partial, $dsLink),
            'The appearance script is deferred, which guarantees a visible theme flash.');
        $this->assertStringNotContainsString('<script src=', $partial,
            'The appearance script became external, so it cannot run before first paint.');
    }

    /**
     * `auto` is the design system's own name for "follow prefers-color-scheme", and it must be
     * written explicitly: the DS applies it only where a page asks for it.
     */
    #[Test]
    public function test_the_default_is_auto_rather_than_an_absent_attribute(): void
    {
        $partial = self::read('resources/views/front_end/partials/appearance.blade.php');

        $this->assertStringContainsString("'auto'", $partial,
            'The default appearance is no longer `auto`, so a shopper with no stored choice gets '
            . "neither their device's preference nor a defined state.");
    }

    /** Every localStorage access must be guarded: it THROWS, it does not return null. */
    #[Test]
    public function test_every_storage_access_is_inside_a_try(): void
    {
        $partial = self::read('resources/views/front_end/partials/appearance.blade.php');

        // One `try` may legitimately guard several accesses, so counting blocks against calls was
        // the wrong test. What matters is that NO access sits outside a try at all: the partial is
        // split on `try {` ... `}` regions and every call must fall inside one.
        $accesses = preg_match_all('{localStorage\.(getItem|setItem|removeItem)}', $partial, $m,
            PREG_OFFSET_CAPTURE);

        $this->assertGreaterThan(0, $accesses, 'No storage access at all — re-read this test.');

        preg_match_all('{\btry\s*\{}', $partial, $tryM, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($tryM[0], 'No try block guards the storage at all.');

        foreach ($m[0] as [$call, $at]) {
            // The nearest preceding `try {` must be closer than the nearest preceding `catch`,
            // which is a cheap but sufficient proxy for "inside a try" in a file this small.
            $lastTry = -1;
            foreach ($tryM[0] as [, $tryAt]) {
                if ($tryAt < $at) { $lastTry = $tryAt; }
            }
            $lastCatch = strrpos(substr($partial, 0, $at), 'catch');

            $this->assertGreaterThan(-1, $lastTry,
                "`{$call}` at offset {$at} has no preceding try. localStorage THROWS in a private "
                . 'window; an unguarded call aborts the script and leaves the page with no '
                . 'appearance attribute at all.');
            $this->assertTrue($lastCatch === false || $lastTry > $lastCatch,
                "`{$call}` at offset {$at} sits after a catch rather than inside a try.");
        }
    }

    /* ============================================================ store scoping */

    #[Test]
    public function test_the_storage_key_is_scoped_to_the_store(): void
    {
        $partial = self::read('resources/views/front_end/partials/appearance.blade.php');

        $this->assertStringContainsString('store-syria:storefront:appearance:', $partial,
            'The agreed key prefix is gone.');

        // THE STORE DISCRIMINATOR IS THE POINT. All stores share one origin, so a key without it
        // lets a shopper's choice on one shop re-theme every other shop on the platform.
        $this->assertMatchesRegularExpression(
            '{store-syria:storefront:appearance:s.{0,40}\$store->id}s',
            $partial,
            'The storage key no longer carries the store id. Every store is served from ONE '
            . "origin, so an unscoped key is cross-store preference contamination — which "
            . 'verify-appearance.cjs section 5 exists to catch.'
        );
    }

    /* ============================================================ the themes */

    #[Test]
    #[DataProvider('themes')]
    public function test_each_theme_layout_includes_the_partial(string $theme): void
    {
        $layout = self::read("themes/{$theme}/views/front_end/layouts/app.blade.php");

        $this->assertStringContainsString("@include('front_end.partials.appearance')", $layout,
            "{$theme}'s layout no longer includes the appearance partial, so that theme has no "
            . 'dark mode — and the page still looks completely normal, which is why this is a '
            . 'test and not a thing anyone would notice.');

        // Before the theme sheet, so the DS declares its tokens first and a theme can override.
        $ds = strpos($layout, "front_end.partials.appearance");
        $main = strpos($layout, "assets/css/main-style.css");

        $this->assertNotFalse($main, "{$theme} no longer loads main-style.css.");
        $this->assertLessThan($main, $ds,
            "{$theme} includes the partial AFTER its own stylesheet, so the design system's "
            . 'tokens now override the theme instead of being available to it.');
    }

    #[Test]
    #[DataProvider('themes')]
    public function test_the_toggle_is_outside_the_customer_auth_gate(string $theme): void
    {
        // `code()`, not `read()`. The containment scan counted the `@auth` inside MY OWN Blade
        // comment -- "OUTSIDE the @auth gate below" -- as an opening directive, and reported depth
        // 1 on markup where the real nesting before the toggle is balanced (@auth/@endauth then
        // @guest/@endguest). FIFTH instance of a check matching prose in this phase, and the first
        // where the prose was in the file being read rather than in the assertion.
        $header = self::code("themes/{$theme}/views/front_end/partison/header.blade.php");

        $this->assertStringContainsString("@include('front_end.partials.appearance-toggle')", $header,
            "{$theme}'s header has no appearance control.");

        // CONTAINMENT, not position. The first version compared the include's offset against the
        // FIRST `@auth('customers')` in the file — and these headers have several, the earliest
        // being the account link far above. The toggle is legitimately after that one while still
        // being outside it, so the positional test failed on correct markup.
        //
        // A guest browsing a shop is the shopper most likely to want this control. If the include
        // ever drifts inside a gate, every signed-in test still passes and guests silently lose it.
        $toggle = strpos($header, 'front_end.partials.appearance-toggle');

        $depth = 0;
        $insideAuth = false;

        foreach (preg_split('{(@auth\b[^\n]*|@endauth|@guest\b[^\n]*|@endguest)}', substr($header, 0, $toggle),
            -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            $piece = trim($piece);
            if (str_starts_with($piece, '@auth') || str_starts_with($piece, '@guest')) {
                $depth++;
            } elseif ($piece === '@endauth' || $piece === '@endguest') {
                $depth--;
            }
        }

        $insideAuth = $depth > 0;

        $this->assertFalse($insideAuth,
            "{$theme}'s appearance control sits inside an @auth/@guest block (nesting depth "
            . "{$depth}), so a GUEST cannot reach it. Signed-in evidence would not show this.");
    }

    /* ============================================================ no English on an Arabic page */

    #[Test]
    public function test_the_toggle_takes_every_string_through_the_translator(): void
    {
        $toggle = self::read('resources/views/front_end/partials/appearance-toggle.blade.php');

        foreach (['Appearance', 'Follow device', 'Light', 'Dark'] as $label) {
            $this->assertStringContainsString("__('{$label}')", $toggle,
                "'{$label}' is no longer translated, so it appears in English on Arabic pages.");
        }

        // The accessible name is composed in JS from those attributes; a literal in the markup
        // would bypass the translator entirely.
        $this->assertStringNotContainsString('aria-label="Appearance:', $toggle,
            'The accessible name is hardcoded in English rather than composed from the '
            . 'translated data attributes.');
    }

    #[Test]
    public function test_both_languages_carry_the_four_appearance_keys(): void
    {
        foreach (['en', 'ar'] as $lang) {
            $json = json_decode(self::read("resources/lang/{$lang}.json"), true);

            $this->assertIsArray($json, "{$lang}.json is not valid JSON.");

            foreach (['Appearance', 'Follow device', 'Light', 'Dark'] as $key) {
                $this->assertArrayHasKey($key, $json, "{$lang}.json has no '{$key}'.");
                $this->assertNotSame('', trim((string) $json[$key]));
            }
        }

        $ar = json_decode(self::read('resources/lang/ar.json'), true);

        // The failure this catches is a key present in ar.json whose VALUE is still English —
        // which `ArabicDoesNotStutter`-style checks have found in this project before.
        foreach (['Appearance', 'Follow device', 'Light', 'Dark'] as $key) {
            $this->assertDoesNotMatchRegularExpression('{[A-Za-z]}', (string) $ar[$key],
                "ar.json['{$key}'] is \"{$ar[$key]}\", which contains Latin letters — so the "
                . 'Arabic storefront shows an English word.');
        }
    }

    /* ============================================================ accessibility + motion */

    #[Test]
    public function test_the_control_is_a_real_button_and_not_a_binary_aria_pressed(): void
    {
        $toggle = self::code('resources/views/front_end/partials/appearance-toggle.blade.php');

        $this->assertStringContainsString('<button type="button"', $toggle,
            'The control is no longer a <button>, so keyboard and touch no longer work for free.');

        // THREE states. `aria-pressed` is binary; announcing a ternary control that way tells a
        // screen-reader user something false.
        $this->assertStringNotContainsString('aria-pressed', $toggle,
            'aria-pressed is binary and this control has three states (auto/light/dark). The '
            . 'state belongs in the accessible name, which the script rebuilds on every change.');

        $this->assertStringContainsString('aria-live="polite"', $toggle,
            'The state change is no longer announced.');
        $this->assertStringContainsString('aria-hidden="true"', $toggle,
            'The decorative icons are no longer hidden from assistive technology.');
    }

    #[Test]
    public function test_the_control_respects_reduced_motion_and_shows_a_focus_ring(): void
    {
        $partial = self::read('resources/views/front_end/partials/appearance.blade.php');

        $this->assertStringContainsString('prefers-reduced-motion: reduce', $partial,
            'The control animates regardless of the reduced-motion preference.');
        $this->assertStringContainsString(':focus-visible', $partial,
            'The control has no visible focus ring, so keyboard users cannot see where they are.');
    }

    /**
     * Logical properties only. The control sits in a header that mirrors in RTL, and a physical
     * `margin-left` would put it on the wrong side of an Arabic page.
     */
    #[Test]
    public function test_the_control_uses_logical_properties_only(): void
    {
        $partial = self::read('resources/views/front_end/partials/appearance.blade.php');

        $block = substr($partial, strpos($partial, '.ss-appearance-toggle'));
        $block = substr($block, 0, strpos($block, '</style>'));

        foreach (['margin-left', 'margin-right', 'padding-left', 'padding-right',
                  'left:', 'right:', 'width:', 'height:'] as $physical) {
            $this->assertStringNotContainsString($physical, $block,
                "The control uses the physical `{$physical}`. This sits in a header that mirrors "
                . 'in RTL, so it must use logical properties (margin-inline-start, inline-size).');
        }
    }

    /** It must not touch the merchant's server-side appearance setting. */
    #[Test]
    public function test_nothing_in_the_storefront_appearance_reads_or_writes_the_admin_setting(): void
    {
        foreach (['appearance.blade.php', 'appearance-toggle.blade.php'] as $file) {
            $body = self::code("resources/views/front_end/partials/{$file}");

            $this->assertStringNotContainsString('cust_darklayout', $body,
                "{$file} references the merchant admin's own dark-layout setting. The shopper's "
                . "preference is per-viewer browser storage and must not reach a merchant's "
                . 'server-side setting in either direction.');
        }
    }
}
