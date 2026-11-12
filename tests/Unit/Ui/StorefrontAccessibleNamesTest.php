<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M2-E — THE ACCESSIBILITY DEFECTS THE STOREFRONT SWEEP FOUND, AND WHY EACH IS INVISIBLE.
 *
 * HOW THESE WERE FOUND. An axe-core sweep over five fixture stores x two appearances x four routes
 * reported eleven rule families. Each was then re-run with the two stylesheets M2 adds served
 * EMPTY, which separates a failure M2 introduced from one that was already there:
 *
 *     rule                impact      with M2   without M2   verdict
 *     color-contrast      serious           0           47   M2 removed 47
 *     button-name         critical        107          107   pre-existing
 *     meta-viewport       critical         20           20   pre-existing
 *     link-name           serious          16           16   pre-existing
 *     select-name         critical         12           12   pre-existing
 *     image-alt           critical          8            8   pre-existing
 *     label               critical          5            5   pre-existing
 *     list                serious           4            4   pre-existing
 *     region              moderate        122          122   pre-existing, STILL OWED
 *     heading-order       moderate         17           17   pre-existing, STILL OWED
 *     landmark-unique     moderate         12           12   pre-existing, STILL OWED
 *     landmark-one-main   moderate          6            6   pre-existing, STILL OWED
 *
 * THE NODE COUNT IS NOT THE WORK. 107 unnamed buttons were eight markup sites, because a product
 * card is one Blade partial rendered per product. Every critical and serious family above is now
 * fixed; the four moderate ones need landmark and heading restructuring across three vendored
 * themes and are recorded in the M2-E report rather than attempted here.
 *
 * WHAT MAKES THESE WORTH A TEST. Not one of them changes a pixel, so no capture, no screenshot
 * review and no HTTP status can show a regression. They are also the kind of thing a later edit
 * re-breaks by copying an older line: three themes carry near-duplicate headers and product cards.
 *
 * SCOPE. Structure only. The behaviour is measured in a browser; `axe-triage` in the M2-E report
 * holds those numbers. This file guards the source so the numbers stay earned.
 */
class StorefrontAccessibleNamesTest extends TestCase
{
    /** The themes a store can be on, plus the fallback copy used when `theme_id` is empty. */
    private const THEMES = ['stylique', 'techzonix', 'greentic'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        $p = self::root() . '/' . $relative;
        self::assertFileExists($p, $relative . ' is missing.');

        return (string) file_get_contents($p);
    }

    /**
     * Blade/HTML/JS comments removed.
     *
     * THIS IS NOT TIDINESS. Five checks in this phase passed or failed on their own explanatory
     * prose: a regex matched the word it was looking for inside the comment describing it, and a
     * literal-colour scan counted a hex code written in a note about that hex code. Every scan
     * below reads code, never commentary.
     */
    private static function code(string $body): string
    {
        $body = preg_replace('{\{\{--.*?--\}\}}s', '', $body);     // Blade
        $body = preg_replace('{<!--.*?-->}s', '', $body);          // HTML
        $body = preg_replace('{/\*.*?\*/}s', '', $body);           // CSS and JS block
        return preg_replace('{^\s*//.*$}m', '', $body);            // JS line
    }

    /* ======================================================================================
     * 1. THE BRAND COLOUR IN TWO PLACES
     * ==================================================================================== */

    /**
     * THE DEFECT THAT COST THE MOST TIME IN M2. greentic declares its brand colour TWICE: as
     * `--primary-color` in `main-style.css`, and again as Tailwind's `primary` in
     * `assets/js/custom.js`. Tailwind's runtime JIT generates its own `.bg-primary` rule and
     * injects it AFTER the stylesheet, so at equal single-class specificity the JS value wins and
     * the CSS token is dead for every `bg-primary` element.
     *
     * MEASURED. M2-D deepened `--primary-color` to #39843c precisely so white ink would clear AA
     * on it. axe still reported 18 `color-contrast` nodes on greentic -- at `#ffffff on #4caf50`,
     * the OLD green, served from the JS config. Four of five stores were clean and only greentic
     * was not, which is what led to the file.
     *
     * A SINGLE SOURCE WOULD BE BETTER and belongs to the theme editor (M3/M4), which has to write
     * both. Until then the two values are duplicated deliberately and this test is the thing that
     * notices when they drift.
     */
    #[Test]
    #[DataProvider('themeProvider')]
    public function test_no_theme_declares_a_brand_colour_tailwind_can_override(string $theme): void
    {
        $css = self::code(self::read("themes/{$theme}/assets/css/main-style.css"));

        $this->assertTrue(
            (bool) preg_match('~--primary-color\s*:\s*(#[0-9a-fA-F]{3,8})~', $css, $c),
            "themes/{$theme}/assets/css/main-style.css declares no literal --primary-color, so "
            . 'nothing below can be compared against it.');

        $token = strtolower($c[1]);

        // Every place this theme hands Tailwind a configuration. The vendored `tailwind.js` is the
        // runtime itself and is excluded -- it CONSUMES the config, it does not declare one.
        $sites = [];
        foreach ([
            "themes/{$theme}/assets/js",
            "themes/{$theme}/views",
        ] as $dir) {
            $base = self::root() . '/' . $dir;
            if (! is_dir($base)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                if (! $file->isFile() || $file->getFilename() === 'tailwind.js') {
                    continue;
                }
                if (! preg_match('{\.(js|blade\.php)$}', $file->getFilename())) {
                    continue;
                }

                $body = self::code((string) file_get_contents($file->getPathname()));

                if (str_contains($body, 'tailwind.config')) {
                    $sites[str_replace(self::root() . '/', '', $file->getPathname())] = $body;
                }
            }
        }

        $this->assertNotSame([], $sites,
            "themes/{$theme} hands Tailwind no configuration at all. Every shipped theme loads the "
            . 'Tailwind runtime CDN and configures it, so finding none means the scan is looking in '
            . 'the wrong place and this test proves nothing.');

        $drifted = [];
        $tokenised = [];

        foreach ($sites as $relative => $body) {
            // A colour given as `hsl(var(--x))` or `var(--x)` cannot drift: the CSS token IS the
            // value. techzonix does this and it is the pattern the other two should move to.
            // Collected for the failure message's benefit: when a theme DOES drift, knowing that
            // a sibling theme already reads the token is the fix.
            if (preg_match_all('~(?:primary|accent)[^,;}]*?var\(\s*--~', $body)) {
                $tokenised[] = $relative;
            }

            // A hex literal CAN drift, because Tailwind generates `.bg-primary` at runtime and
            // injects it AFTER the stylesheet, so at equal specificity the literal wins.
            if (preg_match_all('~primary\s*:\s*[\'"](#[0-9a-fA-F]{3,8})[\'"]~', $body, $m)) {
                foreach ($m[1] as $literal) {
                    if (strtolower($literal) !== $token) {
                        $drifted[] = $relative . ': primary: ' . $literal;
                    }
                }
            }
        }

        $this->assertSame([], $drifted,
            "themes/{$theme} declares its brand colour twice and the two have drifted.\n"
            . "  assets/css/main-style.css  --primary-color: {$token}\n  "
            . implode("\n  ", $drifted)
            . "\nTailwind generates `.bg-primary` at runtime and injects it AFTER the stylesheet, so "
            . 'the literal above is the one that paints and the CSS token is ignored. This is how 18 '
            . 'contrast failures survived a stylesheet that had already been fixed: M2-D deepened '
            . 'greentic\'s --primary-color so white ink would clear AA, and axe still reported '
            . '`#ffffff on #4caf50` -- the old value, served from the JS config.');
    }

    public static function themeProvider(): array
    {
        return array_map(static fn ($t) => [$t], self::THEMES);
    }

    /* ======================================================================================
     * 2. THE STRUCK-THROUGH PRICE
     * ==================================================================================== */

    /**
     * All three themes hard-coded Tailwind grey-400 for the pre-discount price. On the white
     * product card that is 2.54:1 for 14px text, which needs 4.5:1. It is ONE rule per theme and
     * the markup is a SHARED hook, so all three carried the same failure.
     *
     * It surfaced on only two of the five fixture stores, because the other three have no
     * discounted products and never render the element -- a reminder that an axe count is bounded
     * by what the fixture happens to show.
     */
    #[Test]
    #[DataProvider('themeProvider')]
    public function test_the_original_price_uses_a_token_that_follows_the_appearance(string $theme): void
    {
        $css = self::code(self::read("themes/{$theme}/assets/css/main-style.css"));

        $this->assertTrue(
            (bool) preg_match('~\.product_orignal_price\s*\{([^}]*)\}~s', $css, $m),
            "themes/{$theme} no longer styles .product_orignal_price, so this assertion is vacuous. "
            . 'The class comes from resources/views/front_end/hooks/product_price.blade.php.');

        $block = $m[1];

        $this->assertMatchesRegularExpression('{color\s*:\s*var\(\s*--}', $block,
            "themes/{$theme}: .product_orignal_price sets its colour to a literal. It must read a "
            . "token that flips with the appearance, because the same value cannot clear 4.5:1 on "
            . "both a white card and a dark one.\n  block: " . trim($block));

        $this->assertDoesNotMatchRegularExpression('{rgb\(\s*156\s+163\s+175|#9ca3af}i', $block,
            "themes/{$theme}: grey-400 is back on the original price. It measures 2.54:1 on the "
            . 'white product card, which fails WCAG AA for 14px text.');
    }

    /* ======================================================================================
     * 3. ICON-ONLY CONTROLS
     * ==================================================================================== */

    /**
     * Each entry is a control axe reported with no accessible name, named by the file it lives in
     * and an anchor unique within that file. A screen reader announced these as bare "button" or
     * "link".
     *
     * The anchor is the control's own markup rather than a line number, so the test survives the
     * file moving around it.
     */
    public static function unnamedControlProvider(): array
    {
        return [
            // rule            file                                                                anchor
            'search submit, stylique header' => [
                'themes/stylique/views/front_end/partison/header.blade.php',
                'btn-primary px-4 py-2 rounded-l-none',
            ],
            'newsletter submit, stylique footer' => [
                'themes/stylique/views/front_end/partison/footer.blade.php',
                'bg-[var(--primary-color)] px-4 py-2 rounded-r-lg',
            ],
            'search submit, techzonix header' => [
                'themes/techzonix/views/front_end/partison/header.blade.php',
                'absolute ltr:right-3 rtl:left-3 top-1/2 transform',
            ],
            'mobile menu close' => [
                'resources/views/front_end/jQueryRoute.blade.php',
                'id="close-mobile-menu"',
            ],
            'grid layout toggle' => [
                'resources/views/front_end/pages/product-list.blade.php',
                'id="grid-view-btn"',
            ],
            'list layout toggle' => [
                'resources/views/front_end/pages/product-list.blade.php',
                'id="list-view-btn"',
            ],
            'category filter select, stylique header' => [
                'themes/stylique/views/front_end/partison/header.blade.php',
                'border border-gray-300 rounded-l-lg rtl:rounded-r-lg',
            ],
            'account link, stylique header' => [
                'themes/stylique/views/front_end/partison/header.blade.php',
                "route('customer.login',\$slug)",
            ],
            'account link, techzonix header' => [
                'themes/techzonix/views/front_end/partison/header.blade.php',
                "route('customer.login', \$slug)",
            ],
            'price range slider' => [
                'resources/views/front_end/pages/product-list.blade.php',
                'id="priceRangeSlider"',
            ],
            'wishlist, stylique card' => [
                'themes/stylique/views/components/product-card.blade.php',
                'class="wishlist-btn',
            ],
            'add to cart, stylique card' => [
                'themes/stylique/views/components/product-card.blade.php',
                'class="addtocart-btn',
            ],
            'wishlist, greentic card' => [
                'themes/greentic/views/components/product-card.blade.php',
                'class="wishlist-btn',
            ],
            'add to cart, greentic card' => [
                'themes/greentic/views/components/product-card.blade.php',
                'class="addtocart-btn',
            ],
            'add to cart, techzonix card' => [
                'themes/techzonix/views/components/product-card.blade.php',
                'class="w-full btn-primary addtocart-btn',
            ],
            // techzonix's wishlist control is an <a>, not a <button> like the other two themes,
            // so it fell through a sweep that was looking for buttons. It only became visible
            // once the fixture's product grid actually rendered -- see §4.5 of the M2-E report.
            'wishlist, techzonix card' => [
                'themes/techzonix/views/components/product-card.blade.php',
                'class="wishlist-btn wishbtn-globaly wishlist-btn',
            ],
        ];
    }

    #[Test]
    #[DataProvider('unnamedControlProvider')]
    public function test_each_icon_only_control_carries_an_accessible_name(string $file, string $anchor): void
    {
        $code = self::code(self::read($file));

        $at = strpos($code, $anchor);
        $this->assertNotFalse($at,
            $file . ' no longer contains ' . $anchor . ', so this assertion is vacuous. If the '
            . 'control was removed, delete its row from unnamedControlProvider().');

        // The opening tag that owns the anchor: back to the previous '<', forward to the next '>'
        // that is not inside an attribute value.
        $open = strrpos(substr($code, 0, $at), '<');
        $this->assertNotFalse($open, $file . ': could not find the tag that owns ' . $anchor);

        $tag = substr($code, $open, (strpos($code, '>', $at) ?: $at) - $open + 1);

        $this->assertMatchesRegularExpression('{aria-label\s*=|aria-labelledby\s*=}', $tag,
            $file . ': the control at ' . $anchor . " has no accessible name. It is an icon with no "
            . "text, so assistive technology announces only its role.\n  tag: "
            . preg_replace('/\s+/', ' ', trim($tag)));
    }

    /* ======================================================================================
     * 4. A LABEL THAT IS NOT TRANSLATED IS NOT A LABEL
     * ==================================================================================== */

    /**
     * greentic shipped `aria-label="Account"` -- a bare English literal, repeated verbatim on
     * THREE different controls: the account link, a SIGN-OUT link, and the sign-in link. An Arabic
     * shopper heard English, and two of the three were announced as the wrong thing entirely.
     *
     * This asserts the general rule rather than that one instance: every `aria-label` in a
     * storefront view goes through the translator.
     */
    #[Test]
    public function test_every_storefront_aria_label_goes_through_the_translator(): void
    {
        $offenders = [];

        foreach ($this->storefrontViews() as $relative => $body) {
            if (! preg_match_all('{aria-label\s*=\s*"([^"]*)"}', self::code($body), $m)) {
                continue;
            }

            foreach ($m[1] as $value) {
                $value = trim($value);

                // A value is acceptable when every piece of it is produced by the translator, or
                // when it holds no letters at all (e.g. a symbol).
                $stripped = preg_replace('{\{\{.*?\}\}}s', '', $value);

                if (preg_match('{[A-Za-z]}', $stripped)) {
                    $offenders[] = $relative . ': aria-label="' . $value . '"';
                }
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders,
            "A storefront control is labelled with an untranslated literal. The storefront ships "
            . "Arabic and English, so a hard-coded English label is read out to Arabic shoppers as "
            . "English.\n  " . implode("\n  ", $offenders));
    }

    /** Every Blade view a storefront page can render: the three themes plus the shared fallback. */
    private function storefrontViews(): array
    {
        $out  = [];
        $root = self::root();

        $dirs = array_merge(
            array_map(static fn ($t) => "themes/{$t}/views", self::THEMES),
            ['resources/views/front_end', 'resources/views/components']
        );

        foreach ($dirs as $dir) {
            $base = $root . '/' . $dir;
            if (! is_dir($base)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $out[str_replace($root . '/', '', $file->getPathname())]
                        = (string) file_get_contents($file->getPathname());
                }
            }
        }

        $this->assertGreaterThan(40, count($out),
            'Only ' . count($out) . ' storefront views were found, so the scan above covers almost '
            . 'nothing. The theme view directories have moved.');

        return $out;
    }

    /* ======================================================================================
     * 5. DECORATIVE IMAGES AND LIST SEMANTICS
     * ==================================================================================== */

    /**
     * techzonix's four slider backdrops had no `alt` at all, so a screen reader read out a file
     * name. They are decoration behind a product shot that carries its own alt, so the correct
     * value is the empty string -- which is a deliberate statement, not a missing attribute.
     */
    #[Test]
    public function test_every_techzonix_slider_backdrop_declares_an_empty_alt(): void
    {
        $code = self::code(self::read('themes/techzonix/views/main_file.blade.php'));

        foreach (['slider_left_image', 'slider_center_image', 'slider_right_image'] as $setting) {
            $this->assertTrue((bool) preg_match(
                '{<img[^>]*' . preg_quote($setting, '{}') . '[^>]*>}s', $code, $m),
                'themes/techzonix/views/main_file.blade.php no longer renders ' . $setting . '.');

            $this->assertMatchesRegularExpression('{\salt\s*=\s*""}', $m[0],
                $setting . ' is a decorative backdrop and must declare alt="". Without any alt the '
                . "screen reader falls back to the file name.\n  tag: "
                . preg_replace('/\s+/', ' ', $m[0]));
        }

        $this->assertTrue((bool) preg_match(
            '{<img[^>]*background_image[^>]*>}s', $code, $bg),
            'the per-slide background image is gone from themes/techzonix/views/main_file.blade.php.');

        $this->assertMatchesRegularExpression('{\salt\s*=\s*""}', $bg[0],
            'the per-slide backdrop must declare alt="" — the slide\'s own product image beside it '
            . 'is the one that carries a description.');
    }

    /**
     * techzonix's header actions are a `<ul>`, and a `<button>` plus the M2 appearance control sat
     * as DIRECT children. `<ul>` permits only `<li>`, `<script>` and `<template>`.
     *
     * `display: contents` is what makes the wrapper free: the `<li>` contributes no box, so the
     * flex layout of the row is byte-identical. Dropping it would re-flow the header.
     */
    #[Test]
    public function test_the_techzonix_header_list_wraps_its_non_list_children(): void
    {
        $code = self::code(self::read('themes/techzonix/views/front_end/partison/header.blade.php'));

        $this->assertTrue((bool) preg_match(
            '{<ul class="flex items-center sm:gap-3 gap-2">(.*?)</ul>}s', $code, $m),
            'the techzonix header actions list has moved, so this assertion is vacuous.');

        $inside = $m[1];

        foreach (['<button id="search-toggle"', "@include('front_end.partials.appearance-toggle')"] as $child) {
            $at = strpos($inside, $child);
            $this->assertNotFalse($at, 'the techzonix header no longer contains ' . $child);

            $before = substr($inside, 0, $at);
            $this->assertMatchesRegularExpression('{<li[^>]*>\s*$}s', $before,
                $child . ' is a direct child of the header <ul>, which only permits <li>. Wrap it '
                . 'in <li style="display: contents;"> so the layout is unchanged.');
        }

        // An opening <li> with no closing one works only by the parser's auto-close, which made
        // the toggle a child of the SEARCH button's list item. Count them.
        $this->assertSame(
            substr_count($inside, '<li'), substr_count($inside, '</li>'),
            'the techzonix header list has ' . substr_count($inside, '<li') . ' opening <li> and '
            . substr_count($inside, '</li>') . ' closing. HTML auto-close hides this: the browser '
            . 'produces valid markup by absorbing later siblings into the unclosed item, so axe '
            . 'goes quiet while the structure is wrong.');
    }
}
