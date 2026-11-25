<?php

namespace Tests\Feature\Storefront;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-095 (P03-A-04) — KEYBOARD OPERATION OF THE STOREFRONT, MEASURED RATHER THAN REVIEWED.
 * =================================================================================================
 *
 * The gap's wording is exact: "Keyboard operation — an explicitly named original requirement — has
 * no evidence of any kind on the storefront." The human accessibility review that the Phase 03
 * final report lists as outstanding covers REVIEW; it does not discharge the engineering half, and
 * this file is the engineering half.
 *
 * WHAT WAS MEASURED AT HEAD, before anything in this change existed. All four copies of
 * `front_end/layouts/app.blade.php` were read for the three things a keyboard user needs first:
 *
 *   1. BYPASS BLOCKS (WCAG 2.4.1, A). No skip link in any of the four. Every storefront page puts
 *      the full header — logo, search, currency, language, appearance, account, cart, and the whole
 *      category menu — ahead of the content, so reaching the first product means tabbing through
 *      all of it on every page.
 *   2. A MAIN LANDMARK (WCAG 1.3.1 / 4.1.2). `@yield('content')` sits directly in `<body>` in all
 *      four. There is no `<main>`, so there is nothing for a skip link to target and nothing for a
 *      screen reader's landmark list to offer.
 *   3. FOCUS VISIBLE (WCAG 2.4.7, AA). The three theme stylesheets REMOVE the focus indicator and
 *      put nothing in its place:
 *
 *          themes/stylique/assets/css/main-style.css:124   a:focus-visible { outline: none; }
 *          themes/techzonix/assets/css/main-style.css:237  button { outline: none !important; }
 *          themes/{all three}                              .form-input { outline: none; }
 *          themes/{all three}                              .select2-search__field:focus
 *                                                              { outline: none !important; }
 *
 *      The stylique rule is the worst of them: a bare `a:focus-visible { outline: none }` with no
 *      replacement declaration of any kind, in the theme `config/theme.php` ships as `active`. On
 *      the default storefront a keyboard user could not see where they were on ANY link.
 *      `StorefrontKeyboardLayerTest` pins those four selectors against the theme sources so a
 *      fourth one cannot be added without failing.
 *
 * WHY THE FIX IS A STYLESHEET LOADED AFTER THE THEME'S, AND WHY IT USES `!important`. The
 * storefront loads Tailwind's runtime JIT CDN, which injects its utilities into the document after
 * the theme sheet; `outline-none` and `focus:outline-none` appear 30 times across 11 storefront
 * views, and Tailwind's `outline-none` is `outline: 2px solid transparent` — present but invisible,
 * which is a 2.4.7 failure with no replacement just as much as `none` is. A later single-class
 * utility beats an equal-specificity rule on source order, so the layer is linked after
 * `main-style.css` AND declares `!important`, exactly as `public/css/storefront-appearance.css`
 * does and for the same measured reason.
 *
 * BOTH DIRECTIONS, EVERY CASE. Each case runs against an Arabic store and an English one, because
 * a skip link positioned with `left` is off-screen in one direction and on top of the logo in the
 * other. The unit test asserts the logical properties; this one asserts the markup renders and the
 * document direction really did flip, so an RTL pass is not an LTR pass in disguise.
 *
 * THE THEME IS PART OF THE CASE. `Theme::set()` prepends the active theme's view root, so the
 * theme's own `front_end/layouts/app.blade.php` wins over the `resources/views` copy. Three themes
 * ship, so the same change had to be made in each; driving only one would leave two unproven.
 */
class StorefrontKeyboardOperabilityTest extends TestCase
{
    use RefreshDatabase;

    /** The id the skip link targets and the main landmark carries. */
    private const TARGET = 'ss-main';

    /** @var array<string, Store> theme name => store */
    private array $stores = [];

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = User::forceCreate([
            'name' => 'Keyboard Merchant', 'email' => 'keyboard-merchant@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        foreach (['stylique', 'greentic', 'techzonix'] as $theme) {
            foreach (['en', 'ar'] as $lang) {
                $slug = $theme . '-' . $lang;

                $this->stores[$slug] = Store::create([
                    'name' => ucfirst($theme) . ' ' . strtoupper($lang),
                    'email' => $slug . '@example.test',
                    'slug' => $slug,
                    'theme_id' => $theme,
                    'created_by' => $merchant->id,
                    'is_active' => 1,
                    'default_language' => $lang,
                ]);
            }
        }
    }

    /** @return array<string, array{0: string, 1: string}> theme + language, as the store slug. */
    public static function storefronts(): array
    {
        $out = [];
        foreach (['stylique', 'greentic', 'techzonix'] as $theme) {
            foreach (['en', 'ar'] as $lang) {
                $out["{$theme} {$lang}"] = [$theme, $lang];
            }
        }

        return $out;
    }

    private function homepage(string $theme, string $lang): string
    {
        $slug = $theme . '-' . $lang;

        $response = $this->get("/{$slug}/home");

        $this->assertSame(200, $response->getStatusCode(),
            "/{$slug}/home did not render, so nothing below is a measurement of this theme.");

        return $response->getContent();
    }

    /** Everything from `<body` onwards: tab order is a property of the body, not the head. */
    private function body(string $html): string
    {
        $at = strpos($html, '<body');

        $this->assertNotFalse($at, 'The rendered page has no <body>.');

        return substr($html, $at);
    }

    /**
     * THE PREMISE. If the theme under test is not the theme that rendered, every assertion below
     * is about some other file.
     */
    #[Test]
    #[DataProvider('storefronts')]
    public function test_the_requested_theme_is_the_theme_that_rendered(string $theme, string $lang): void
    {
        $html = $this->homepage($theme, $lang);

        $this->assertStringContainsString("themes/{$theme}/assets/css/main-style.css", $html,
            "The page did not load {$theme}'s own stylesheet, so this case is not driving that theme.");

        $this->assertStringContainsString(
            $lang === 'ar' ? 'dir="rtl"' : 'dir="ltr"',
            $html,
            "A {$lang} store did not render the expected direction, so the RTL/LTR split is not real."
        );
    }

    /** WCAG 2.4.1 — there is a way past the header, and it is the FIRST thing focus reaches. */
    #[Test]
    #[DataProvider('storefronts')]
    public function test_a_skip_link_is_the_first_focusable_element_in_the_body(string $theme, string $lang): void
    {
        $body = $this->body($this->homepage($theme, $lang));

        preg_match('/<(?:a|button|input|select|textarea|summary)\b[^>]*>/i', $body, $m, PREG_OFFSET_CAPTURE);

        $this->assertNotEmpty($m, 'No focusable element at all in the body — the fixture is wrong.');

        $this->assertStringContainsString('ss-skip-link', $m[0][0],
            "The first focusable element of a {$theme}/{$lang} page is not the skip link, so a "
            . 'keyboard user still tabs the whole header before reaching the content (WCAG 2.4.1). '
            . 'First focusable was: ' . $m[0][0]);

        $this->assertStringContainsString('href="#' . self::TARGET . '"', $m[0][0],
            'The skip link does not point at the main landmark, so activating it goes nowhere.');
    }

    /** WCAG 1.3.1 / 4.1.2 — the target exists, is unique, and can actually take focus. */
    #[Test]
    #[DataProvider('storefronts')]
    public function test_one_main_landmark_wraps_the_content_and_can_receive_focus(string $theme, string $lang): void
    {
        $body = $this->body($this->homepage($theme, $lang));

        $this->assertSame(1, preg_match_all('/<main\b/i', $body),
            "A {$theme}/{$lang} page does not have exactly one <main>. Zero means the skip link has "
            . 'no target and the landmark list is empty; more than one means neither is the main.');

        preg_match('/<main\b[^>]*>/i', $body, $main);

        $this->assertStringContainsString('id="' . self::TARGET . '"', $main[0],
            'The main landmark does not carry the id the skip link names.');

        /* `<main>` is not focusable by itself: without this, Safari and older Chrome move the
           document fragment but leave focus on the link, so the next Tab returns to the header. */
        $this->assertStringContainsString('tabindex="-1"', $main[0],
            'The main landmark cannot receive focus, so the skip link moves the viewport but not '
            . 'the keyboard position.');

        $this->assertMatchesRegularExpression('~</main>~i', $body,
            'The main landmark is never closed, so it swallows the footer.');
    }

    /**
     * WCAG 2.4.7 — the focus layer is loaded, and loaded LATE.
     *
     * Order is the whole point: the themes' `outline: none` rules and Tailwind's injected
     * `outline-none` both win on source order against an earlier sheet of equal specificity.
     */
    #[Test]
    #[DataProvider('storefronts')]
    public function test_the_focus_layer_is_loaded_after_the_theme_stylesheet(string $theme, string $lang): void
    {
        $html = $this->homepage($theme, $lang);

        $layer = strpos($html, 'css/storefront-keyboard.css');
        $sheet = strpos($html, "themes/{$theme}/assets/css/main-style.css");

        $this->assertNotFalse($layer,
            "A {$theme}/{$lang} page does not load storefront-keyboard.css, so every focus "
            . 'indicator the theme removes stays removed.');

        $this->assertGreaterThan($sheet, $layer,
            'storefront-keyboard.css is loaded BEFORE the theme stylesheet, so the theme\'s '
            . '`outline: none` wins on source order and the focus ring is still invisible.');
    }

    /** The skip link must be reachable but not visible until it is focused. */
    #[Test]
    #[DataProvider('storefronts')]
    public function test_the_skip_link_is_a_real_link_and_not_hidden_from_the_tab_order(string $theme, string $lang): void
    {
        $body = $this->body($this->homepage($theme, $lang));

        preg_match('/<a\b[^>]*ss-skip-link[^>]*>(.*?)<\/a>/is', $body, $m);

        $this->assertNotEmpty($m, 'The skip link is not an anchor with text.');

        $this->assertNotSame('', trim(strip_tags($m[1])),
            'The skip link has no text, so it is an unlabelled stop in the tab order.');

        /* `display:none`, `visibility:hidden` and `hidden` all remove an element from the tab
           order, which would make the control decorative. The off-screen technique is the only
           one that keeps it focusable. */
        foreach (['display:none', 'display: none', 'visibility:hidden', 'visibility: hidden'] as $killer) {
            $this->assertStringNotContainsString($killer, $m[0],
                "The skip link carries `{$killer}`, which takes it out of the tab order entirely.");
        }

        $this->assertDoesNotMatchRegularExpression('/\bhidden\b(?!-)/i', $m[0],
            'The skip link is `hidden`, so it is not focusable.');

        $this->assertStringNotContainsString('tabindex="-1"', $m[0],
            'The skip link is removed from the tab order by tabindex="-1".');
    }

    /**
     * The label is in the reader's own language.
     *
     * A bypass block a sighted keyboard user can read is the whole point of making it VISIBLE on
     * focus rather than screen-reader-only, and an English string on an Arabic page is the usual
     * way that is lost. No language-file change was needed — "Skip to main content" was already
     * declared in both `resources/lang/en.json` and `resources/lang/ar.json` — which is exactly why
     * it is worth asserting: a key that resolves today could be hard-coded tomorrow by somebody who
     * did not know the translation existed.
     */
    #[Test]
    #[DataProvider('storefronts')]
    public function test_the_skip_link_is_labelled_in_the_readers_language(string $theme, string $lang): void
    {
        $body = $this->body($this->homepage($theme, $lang));

        preg_match('/<a\b[^>]*ss-skip-link[^>]*>(.*?)<\/a>/is', $body, $m);
        $this->assertNotEmpty($m, 'No skip link to read.');

        $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $expected = $lang === 'ar' ? 'الانتقال إلى المحتوى الرئيسي' : 'Skip to main content';

        $this->assertSame($expected, $text,
            "The {$lang} skip link reads \"{$text}\" rather than \"{$expected}\". A visible bypass "
            . 'block in the wrong language is a control the reader cannot use.');
    }
}
