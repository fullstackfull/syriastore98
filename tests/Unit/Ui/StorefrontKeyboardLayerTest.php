<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-095 (P03-A-04) — THE SOURCE SIDE OF STOREFRONT KEYBOARD OPERABILITY.
 * =================================================================================================
 *
 * `StorefrontKeyboardOperabilityTest` drives rendered pages, which proves the control reaches the
 * shopper. It cannot see three things, and all three are how this fix would rot:
 *
 *   1. THE FOUR-COPY PROBLEM. `front_end/layouts/app.blade.php` exists FOUR times — once per theme
 *      plus the `resources/views` fallback — and the theme's own wins via `Theme::set()` ->
 *      `prependLocation()`. A rendered-page test only ever sees the copy that rendered, so three
 *      copies can silently lose the skip link. `config('theme.active')` is `stylique`, so the
 *      `resources/views` copy is unreachable in practice and a rendered test can never cover it at
 *      all; it is asserted here or nowhere.
 *
 *   2. WHAT THE CSS ACTUALLY DECLARES. A page can link the stylesheet and still have no visible
 *      ring: `outline: 2px solid transparent` is what Tailwind's own `outline-none` utility emits,
 *      and it would satisfy any "the file is loaded" assertion.
 *
 *   3. A FOURTH FOCUS-KILLER ARRIVING IN A THEME. The three theme stylesheets remove the focus
 *      indicator in 14 places between them (4 + 5 + 5, comments stripped). The layer covers the
 *      subjects of those; a new `a:focus-visible { outline: none }` added to a theme tomorrow
 *      would be invisible to every rendered test, because a test cannot see a ring. The counts are
 *      ratcheted instead, so adding one fails here and somebody reads this docblock.
 *
 * COMMENTS ARE STRIPPED EVERYWHERE, with a specific reason rather than a general one: this change
 * added an explanatory Blade comment to 28 storefront content views and a CSS header to the layer
 * that quotes the exact declarations it is looking for. Both would satisfy these assertions on
 * their own. CLAUDE.md records four such false readings in one day, in three syntaxes.
 *
 * AND THE SEARCH IS SCOPED TO THE RULE BLOCK, not to the file. `public/css/storefront-keyboard.css`
 * contains `outline: none` legitimately — on `main[tabindex="-1"]:focus:not(:focus-visible)`, where
 * suppressing the ring is correct, because focus arrived there programmatically from the skip link
 * and the user navigated PAST something rather than TO it. A file-wide
 * `assertStringNotContainsString('outline: none')` would fail on the correct code, and a file-wide
 * positive would pass on the wrong block.
 */
class StorefrontKeyboardLayerTest extends TestCase
{
    use StripsSourceComments;

    private const LAYER = 'public/css/storefront-keyboard.css';

    /** All four copies. The theme's own wins at runtime; the fallback is covered nowhere else. */
    private const LAYOUTS = [
        'stylique' => 'themes/stylique/views/front_end/layouts/app.blade.php',
        'greentic' => 'themes/greentic/views/front_end/layouts/app.blade.php',
        'techzonix' => 'themes/techzonix/views/front_end/layouts/app.blade.php',
        'fallback' => 'resources/views/front_end/layouts/app.blade.php',
    ];

    /**
     * Every place a theme stylesheet removes the focus indicator, measured on 2026-11-25 with
     * comments stripped.
     *
     * A COUNT RATHER THAN A SELECTOR LIST, deliberately: the list below records what they were, but
     * the assertion is on the number, because a selector list would pass unchanged when a theme
     * adds a fifth rule to one it already has. Both directions fail — removing one upstream also
     * fails, which is the signal to come and delete it from here.
     *
     *   stylique  (4)  a:focus-visible · select · .form-input · .select2-search__field:focus
     *   greentic  (5)  .btn-* · .form-input · select · a floating button · .select2-search__field
     *   techzonix (5)  button!important · .btn-primary · .form-input · .search-bar input:focus
     *                  · .select2-search__field
     */
    private const THEME_FOCUS_KILLERS = [
        'stylique' => 4,
        'greentic' => 5,
        'techzonix' => 5,
    ];

    /** The selectors the layer must restore a ring on, derived from the killers above. */
    private const MUST_RESTORE = [
        'a:focus-visible',
        'button:focus-visible',
        'input:focus-visible',
        'select:focus-visible',
        'textarea:focus-visible',
        'summary:focus-visible',
        '[tabindex]:focus-visible',
        '.form-input:focus-visible',
        '.select2-search__field:focus',
    ];

    /**
     * One declaration block of a stylesheet, found by a string its selector list contains.
     *
     * Returns everything from the start of the selector list to the closing brace, so an assertion
     * made against it cannot be satisfied by an unrelated rule elsewhere in the file. `@media` arms
     * are skipped by taking the FIRST block whose selector list contains the needle and whose
     * opening brace is the block's own.
     */
    private function ruleBlock(string $css, string $needle): string
    {
        $at = strpos($css, $needle);
        $this->assertNotFalse($at, "No rule in the layer mentions `{$needle}`.");

        /* Walk back to the end of the previous block (or the file start) to capture the whole
           selector list, then forward to this block's closing brace. */
        $start = max(
            strrpos(substr($css, 0, $at), '}') === false ? 0 : (int) strrpos(substr($css, 0, $at), '}') + 1,
            0
        );
        $open = strpos($css, '{', $at);
        $this->assertNotFalse($open, "The rule mentioning `{$needle}` has no declaration block.");
        $close = strpos($css, '}', $open);
        $this->assertNotFalse($close, "The rule mentioning `{$needle}` is never closed.");

        return substr($css, $start, $close - $start + 1);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function layouts(): array
    {
        $out = [];
        foreach (self::LAYOUTS as $name => $path) {
            $out[$name] = [$name, $path];
        }

        return $out;
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function themes(): array
    {
        $out = [];
        foreach (self::THEME_FOCUS_KILLERS as $theme => $count) {
            $out[$theme] = [$theme, $count];
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------
    // 1. THE FOUR COPIES
    // ---------------------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('layouts')]
    public function test_every_layout_copy_includes_the_skip_link(string $name, string $path): void
    {
        $source = $this->sourceWithoutComments($path);

        $this->assertStringContainsString("@include('front_end.partials.skip-link')", $source,
            "The {$name} storefront layout does not include the skip link, so a keyboard user on "
            . 'that theme still tabs the whole header before the content (WCAG 2.4.1). All four '
            . 'copies need the change; the theme\'s own wins at runtime.');
    }

    #[Test]
    #[DataProvider('layouts')]
    public function test_every_layout_copy_has_exactly_one_closed_main_landmark(string $name, string $path): void
    {
        $source = $this->sourceWithoutComments($path);

        $this->assertSame(1, preg_match_all('/<main\b/i', $source),
            "The {$name} storefront layout does not declare exactly one main landmark.");

        $this->assertMatchesRegularExpression('/<main\s+id="ss-main"\s+tabindex="-1"\s*>/i', $source,
            "The {$name} layout's main landmark is missing the id the skip link targets or the "
            . 'tabindex that lets it take focus. Without tabindex="-1" the link scrolls the page '
            . 'but leaves the keyboard position in the header.');

        $this->assertSame(1, preg_match_all('~</main>~i', $source),
            "The {$name} layout does not close its main landmark exactly once, so it swallows the "
            . 'footer or closes a tag it never opened.');

        $this->assertMatchesRegularExpression('/<main\b[^>]*>\s*@yield\(\'content\'\)\s*<\/main>/',
            $source,
            "The {$name} layout's main landmark does not wrap @yield('content'), so the skip link "
            . 'lands somewhere that is not the page content.');
    }

    #[Test]
    #[DataProvider('layouts')]
    public function test_every_layout_copy_loads_the_focus_layer_after_the_theme_stylesheet(string $name, string $path): void
    {
        $source = $this->sourceWithoutComments($path);

        $layer = strpos($source, "asset('css/storefront-keyboard.css')");
        $this->assertNotFalse($layer,
            "The {$name} layout does not load the keyboard layer, so every focus indicator its "
            . 'theme removes stays removed.');

        $sheet = strrpos($source, "main-style.css') }}");
        $this->assertNotFalse($sheet, "The {$name} layout does not load a theme stylesheet at all.");

        $this->assertGreaterThan($sheet, $layer,
            "The {$name} layout loads the keyboard layer BEFORE the theme stylesheet. The theme's "
            . '`outline: none` is the same specificity, so the later rule wins and the ring is '
            . 'still invisible. Source order IS the fix here.');
    }

    // ---------------------------------------------------------------------------------------------
    // 2. WHAT THE LAYER DECLARES
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_the_focus_block_restores_a_visible_ring_on_every_killed_selector(): void
    {
        $css = $this->sourceWithoutComments(self::LAYER);

        $block = $this->ruleBlock($css, 'a:focus-visible');

        foreach (self::MUST_RESTORE as $selector) {
            $this->assertStringContainsString($selector, $block,
                "The focus block does not name `{$selector}`, which a theme stylesheet strips the "
                . 'ring from. Scope matters: it must be in THIS block, not elsewhere in the file.');
        }

        $this->assertMatchesRegularExpression('/outline:\s*[^;]*solid[^;]*!important\s*;/', $block,
            'The focus block does not set a solid outline with !important. Tailwind\'s runtime CDN '
            . 'injects `outline-none` after this sheet and techzonix declares '
            . '`button { outline: none !important }`, so neither source order nor matching '
            . 'specificity is enough on its own.');

        $this->assertStringContainsString('--ss-focus-ring', $block,
            'The ring colour is not the design system token, so it cannot re-theme: '
            . '--ss-focus-ring is --ss-blue-500 (4.44:1 on white) in light and --ss-cyan-400 '
            . '(8.27:1 on the dark page) in dark, and both clear WCAG 1.4.11\'s 3:1.');

        $this->assertMatchesRegularExpression('/outline-offset:\s*[^;]+;/', $block,
            'The ring has no offset, so it is drawn on the element\'s own border and disappears '
            . 'against a same-coloured edge.');

        /* The two values that look like a focus indicator and are not one. */
        $this->assertStringNotContainsString('transparent', $block,
            'The focus block paints a TRANSPARENT outline, which is exactly what Tailwind\'s '
            . '`outline-none` emits: present in the box model, invisible to the user, and a flat '
            . 'WCAG 2.4.7 failure.');

        $this->assertDoesNotMatchRegularExpression('/outline:\s*none/', $block,
            'The focus block removes the outline it exists to restore.');
    }

    #[Test]
    public function test_the_skip_link_is_positioned_with_logical_properties_only(): void
    {
        $css = $this->sourceWithoutComments(self::LAYER);

        $block = $this->ruleBlock($css, '.ss-skip-link {');

        $this->assertStringContainsString('inset-inline-start', $block,
            'The skip link is not placed with a logical inset, so it cannot land in the leading '
            . 'corner of both an English and an Arabic page.');

        foreach (['left:', 'right:'] as $physical) {
            $this->assertStringNotContainsString($physical, $block,
                "The skip link uses the physical property `{$physical}`. In one of the two "
                . 'directions that puts it over the logo instead of in the leading corner.');
        }

        /* A HORIZONTAL hide is the direction bug that looks fine in the language you tested in:
           `translateX(-200%)` is off-screen in LTR and ON-SCREEN, over the content, in RTL. */
        $this->assertStringNotContainsString('translateX', $block,
            'The skip link is hidden with a horizontal transform, which moves it off-screen in one '
            . 'direction and on-screen in the other.');

        $this->assertStringContainsString('translateY', $block,
            'The skip link is not hidden with a vertical transform, so the hide is either '
            . 'direction-dependent or removes it from the tab order.');

        /* All three of these take the control out of the tab order, which would make it
           decorative — the thing a bypass block must never be. */
        foreach (['display: none', 'display:none', 'visibility: hidden', 'visibility:hidden'] as $killer) {
            $this->assertStringNotContainsString($killer, $block,
                "The skip link is hidden with `{$killer}`, which removes it from the tab order.");
        }
    }

    #[Test]
    public function test_the_skip_link_becomes_visible_on_plain_focus_not_only_focus_visible(): void
    {
        $css = $this->sourceWithoutComments(self::LAYER);

        $this->assertMatchesRegularExpression('/\.ss-skip-link:focus\s*\{/', $css,
            'The skip link has no `:focus` rule, so it never appears.');

        $this->assertDoesNotMatchRegularExpression('/\.ss-skip-link:focus-visible\s*\{/', $css,
            '`:focus-visible` is a browser heuristic about pointer use. A switch device or an '
            . 'on-screen keyboard can focus the skip link without matching it, and the control '
            . 'would then stay off-screen for exactly the users who need it.');

        $block = $this->ruleBlock($css, '.ss-skip-link:focus');

        $this->assertStringContainsString('translateY(0)', $block,
            'Focusing the skip link does not bring it on-screen.');
    }

    // ---------------------------------------------------------------------------------------------
    // 3. THE THEMES CANNOT QUIETLY ADD A FIFTH
    // ---------------------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('themes')]
    public function test_the_number_of_focus_indicators_a_theme_removes_has_not_changed(string $theme, int $expected): void
    {
        $css = $this->sourceWithoutComments("themes/{$theme}/assets/css/main-style.css");

        $found = preg_match_all('/outline:\s*none/', $css);

        $this->assertSame($expected, $found,
            "themes/{$theme}/assets/css/main-style.css removes the focus indicator {$found} times, "
            . "not the {$expected} this file was written against.\n"
            . "MORE: a new rule strips a ring the keyboard layer may not cover — check its subject "
            . "appears in StorefrontKeyboardLayerTest::MUST_RESTORE, then update the count.\n"
            . 'FEWER: the theme was fixed upstream, which is good — update the count and consider '
            . 'whether the layer still needs that selector.');
    }

    /** The premise for the count above: if the stripper eats the file, the count is meaningless. */
    #[Test]
    #[DataProvider('themes')]
    public function test_the_theme_stylesheet_really_was_read(string $theme, int $expected): void
    {
        $css = $this->sourceWithoutComments("themes/{$theme}/assets/css/main-style.css");

        $this->assertGreaterThan(10000, strlen($css),
            "themes/{$theme}'s stylesheet came back too short to be the real file, so the count "
            . 'above is measuring a stripping bug rather than the theme.');

        $this->assertStringContainsString('--primary-color', $css,
            "themes/{$theme}'s stylesheet does not look like a storefront theme sheet.");
    }

    // ---------------------------------------------------------------------------------------------
    // 4. NO SECOND LANDMARK COMES BACK
    // ---------------------------------------------------------------------------------------------

    /**
     * 28 storefront content views carried their own bare main element, so wrapping
     * `@yield('content')` nested one inside another: invalid HTML, and two landmarks where ARIA
     * allows one. They are divs now. Nothing selects the element — measured zero `main` selectors
     * across all three theme stylesheets and their scripts — so the demotion is visually inert.
     */
    #[Test]
    public function test_no_storefront_content_view_declares_a_second_main_landmark(): void
    {
        $roots = [
            'resources/views/front_end',
            'themes/stylique/views',
            'themes/greentic/views',
            'themes/techzonix/views',
        ];

        $seen = 0;
        $offenders = [];

        foreach ($roots as $root) {
            $dir = dirname(__DIR__, 3) . '/' . $root;
            $this->assertDirectoryExists($dir);

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $relative = $root . '/' . substr($file->getPathname(), strlen($dir) + 1);
                $seen++;

                /* RAW FIRST, AND COMMENTS STRIPPED ONLY WHEN THERE IS SOMETHING TO FIND. A file
                   whose raw text does not contain the element cannot declare it, whatever its
                   comments say, so stripping would change no answer — and the shared stripper
                   REFUSES a file that is nothing but a comment
                   (`front_end/hooks/cart_sidebar.blade.php` is exactly that), which would abort
                   the census on a file that is not an offender. Stripping still happens for every
                   candidate, which is where a comment could lie. */
                $raw = (string) file_get_contents($file->getPathname());

                if (preg_match('/<main\b/i', $raw) !== 1) {
                    continue;
                }

                if (preg_match_all('/<main\b/i', $this->sourceWithoutComments($relative)) === 0) {
                    continue;
                }

                /* The layout copies are the ONE legitimate landmark; everything else is a second. */
                if (str_ends_with($relative, 'front_end/layouts/app.blade.php')) {
                    continue;
                }

                $offenders[] = $relative;
            }
        }

        $this->assertGreaterThan(100, $seen,
            'The census read too few views to be scanning the storefront tree at all.');

        $this->assertSame([], $offenders,
            "These storefront views declare a main landmark of their own. The layout already "
            . "provides the document's one landmark and wraps the content in it, so these nest a "
            . "second one inside the first: invalid HTML, and a landmark list in which neither is "
            . "the main. Use a div.\n  " . implode("\n  ", $offenders));
    }
}
