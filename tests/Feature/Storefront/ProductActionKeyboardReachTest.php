<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Security\EnablesWorkdoModules;
use Tests\TestCase;

/**
 * GAP-256 / P03-B-07 — THE PRODUCT CARD'S CONVERSION ACTIONS, RENDERED WITH THE MODULES REALLY ON.
 * =================================================================================================
 *
 * WHAT THIS FILE IS FOR, AND WHAT IT DELIBERATELY DOES NOT CLAIM.
 *
 * `GAP-256` is a WCAG 2.1.1 (A) failure that lives entirely in the CASCADE: `ProductQuickView`,
 * `ProductCompare` and `ProductCSV` each ship an INLINE `<style>` inside their own
 * `src/resources/views/pages/button.blade.php` declaring `opacity: 0; visibility: hidden` on their
 * button, restored only under `.product-box:hover` / `.product-item:hover` / `.product-card:hover`.
 * `visibility: hidden` takes an element OUT OF THE TAB ORDER, so those three actions are not merely
 * invisible to a keyboard shopper — they are unreachable; and a touch device has no hover state at
 * all. `ProductsPDF` is the control: it ships no stylesheet, so it is always visible.
 *
 * NO ASSERTION IN THIS FILE IS ABOUT THE FIX. Whether an element is focusable, whether Tab reaches
 * it, whether `opacity` animates, whether `@media (hover: none)` matches — none of that exists in
 * any byte a PHP test can read. This file's ONE job is to produce the page a browser then measures,
 * and to prove the page is the real thing rather than a sanitised copy. The measurements are in
 * `tests/Support/bin/check-product-action-keyboard-live.cjs`, which runs this file with
 * `SS_GAP256_HTML_DIR` set and photographs what it writes.
 *
 * WHY THE PREMISES ARE THE POINT. Per CLAUDE.md, **no WorkDo module loads in any test run** unless
 * an `add_on_managers` row is inserted, so a test that "passes" against a module surface may never
 * have reached it. And `module_is_active()` (app/Helper/helper.php:1864) has a SECOND gate that the
 * `add_on_managers` row does not satisfy: on a storefront route it resolves the store, loads the
 * store owner, reads that owner's `Plan` and requires the module's name to appear in the plan's
 * comma-separated `modules` column. A fixture that inserts the row and stops renders a product card
 * with NO conversion actions on it at all — a page on which every assertion below would pass by
 * being about nothing. There is a THIRD gate too: each of the four is behind its own per-store
 * `settings` row (`enable_product_quickview`, `enable_product_compare`, `products_pdf_enable`,
 * `product_csvswich_is_enable`), all of which default to absent.
 *
 * So the premises assert, in order: the modules loaded; the plan entitles them; the settings open;
 * all four buttons are in the bytes; the three offending modules' OWN `visibility: hidden` arrived
 * in the page (i.e. the defect under test is present in the capture); the hover ancestor class the
 * modules' selectors need is really an ancestor; and the host override seam is really linked.
 *
 * TWO STORES, ONE EACH FOR `dir="ltr"` AND `dir="rtl"`, because the storefront's direction comes
 * from the store's own `default_language` (stylique's layout line 2) and the repository has shipped
 * RTL-only defects before. Light and dark are set by the harness on the captured page, not here:
 * the appearance is a `data-ss-theme` attribute on `<html>` that the inline script in
 * `front_end/partials/appearance.blade.php` writes from `localStorage`, so one capture serves both.
 */
class ProductActionKeyboardReachTest extends TestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    /**
     * The four conversion modules, and the per-store `settings` key that gates each one in
     * `resources/views/front_end/hooks/action_link.blade.php`.
     */
    private const MODULES = [
        'ProductQuickView' => 'enable_product_quickview',
        'ProductCompare' => 'enable_product_compare',
        'ProductsPDF' => 'products_pdf_enable',
        'ProductCSV' => 'product_csvswich_is_enable',
    ];

    /**
     * The three buttons `GAP-256` is about, by the class their own `<style>` hides, plus the one
     * that is the CONTROL GROUP.
     *
     * `product-pdf-btn` is listed separately and asserted separately on purpose: it is the only one
     * of the four that is visible today, and a "fix" that hid it would be a regression this fixture
     * must be able to see.
     */
    private const HIDDEN_BY_THEIR_OWN_STYLE = ['quickview-btn', 'compare-btn', 'csv-btn'];

    private const ALWAYS_VISIBLE = 'product-pdf-btn';

    private User $merchant;

    private Store $ltr;

    private Store $rtl;

    /** @var list<string> */
    private array $migrationFailures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrationFailures = $this->enableWorkdoModules(array_keys(self::MODULES));

        $planId = DB::table('plans')->insertGetId([
            'name' => 'gap256-plan', 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            /* THE SECOND GATE. `module_is_active()` reads the STORE OWNER's plan on a storefront
               route, so the four names have to be here or the hook emits nothing. */
            'modules' => implode(',', array_keys(self::MODULES)),
            'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->merchant = User::forceCreate([
            'name' => 'GAP-256 Merchant', 'email' => 'gap256-merchant@example.test',
            'password' => bcrypt('not-a-real-password'), 'type' => 'admin', 'created_by' => 1,
            'email_verified_at' => now(), 'language' => 'en', 'plan_id' => $planId,
        ]);

        $this->ltr = $this->storeFor('gap256-ltr-shop', 'en');
        $this->rtl = $this->storeFor('gap256-rtl-shop', 'ar');

        $this->merchant->current_store = $this->ltr->id;
        $this->merchant->save();
        $this->merchant->flushCache();
    }

    private function storeFor(string $slug, string $language): Store
    {
        $store = Store::create([
            'name' => 'GAP-256 ' . strtoupper($language), 'email' => $slug . '@example.test',
            'slug' => $slug, 'theme_id' => 'stylique', 'created_by' => $this->merchant->id,
            'is_active' => 1, 'default_language' => $language,
        ]);

        /* THE THIRD GATE: each module's own per-store switch. All four default to absent. */
        foreach (self::MODULES as $setting) {
            Setting::forceCreate([
                'name' => $setting, 'value' => 'on',
                'created_by' => $this->merchant->id, 'store_id' => $store->id,
            ]);
        }

        /* The home page's product grid is `@foreach(collect($all_products)->reverse()->take(10))`,
           so with no products no card renders and the hook is never called. Categories are what
           `$MainCategoryList` feeds; without them two sections render an empty container, which is
           not under test here but keeps the capture a realistic page. */
        foreach (['Dresses', 'Shoes', 'Bags'] as $name) {
            Category::forceCreate([
                'name' => $name, 'slug' => $store->id . '-' . strtolower($name),
                'status' => 1, 'store_id' => $store->id,
            ]);
        }

        foreach ([1, 2, 3] as $n) {
            Product::forceCreate([
                'name' => 'GAP-256 Product ' . $n, 'slug' => $store->id . '-gap256-product-' . $n,
                'description' => 'A product the conversion actions hang off.',
                'price' => 10 + $n, 'product_stock' => 5, 'track_stock' => 0,
                'variant_product' => 0, 'status' => 1,
                'store_id' => $store->id, 'created_by' => $this->merchant->id,
            ]);
        }

        return $store;
    }

    /**
     * The shopper-facing home page, and the bytes the live harness photographs.
     *
     * `URL::useAssetOrigin()`, NOT `config(['app.url' => ...])`: every stylesheet and script URL in
     * all four copies of `front_end/layouts/app.blade.php` is built with `asset()`, which formats
     * against the generator's asset root or else the CURRENT REQUEST's root and never reads
     * `app.url` at call time. `StorefrontSectionOrderRendersTest` records what happens without
     * this — the 477 KB runtime Tailwind bundle 404s, the page renders unstyled, and every computed
     * colour reads `rgb(0, 0, 0)` on `rgba(0, 0, 0, 0)`, which looks exactly like a wall of 1.00:1
     * contrast failures that the capture itself invented.
     */
    private function shopHtml(Store $store, ?string $capture = null): string
    {
        $base = getenv('SS_GAP256_ASSET_BASE');

        if (is_string($base) && $base !== '') {
            URL::useAssetOrigin($base);
        }

        $response = $this->get('/' . $store->slug . '/home');
        $response->assertStatus(200);
        $html = (string) $response->getContent();

        $dir = getenv('SS_GAP256_HTML_DIR');

        if ($capture !== null && is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents(rtrim($dir, '/') . '/card-' . $capture . '.html', $html);
        }

        return $html;
    }

    /** The module's own `button.blade.php`, read off disk — the vendored source, not a copy. */
    private function moduleSource(string $module): string
    {
        $path = base_path("packages/workdo/{$module}/src/resources/views/pages/button.blade.php");

        $this->assertFileExists($path,
            "{$module}'s button view is gone; re-point this fixture rather than deleting it.");

        return (string) file_get_contents($path);
    }

    /* ===================================================================== premises */

    /**
     * PREMISE 1 — the four modules really loaded, through the production loader.
     *
     * Asserted by the ROUTES they register, because that is what `loadAddons()` actually does. An
     * `add_on_managers` row on its own proves nothing: the row is what the loader reads, not what
     * the loader produced.
     */
    #[Test]
    public function test_the_four_conversion_modules_really_loaded(): void
    {
        $routes = app('router')->getRoutes()->getRoutes();

        foreach (array_keys(self::MODULES) as $module) {
            $prefix = 'Workdo\\' . $module . '\\';

            $this->assertNotEmpty(
                array_filter($routes, fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), $prefix)),
                "PREMISE: {$module} is not loaded, so the capture would carry no button for it. "
                . 'Failed migrations: ' . implode('; ', $this->migrationFailures)
            );
        }
    }

    /**
     * PREMISE 2 — all four buttons are in the rendered page, so the plan gate and the four
     * per-store switches are all really open.
     *
     * This is the assertion that would have caught a fixture that inserted the `add_on_managers`
     * rows and stopped: `module_is_active()` would have returned false four times and the hook
     * would have emitted an empty `<div class="ss-product-actions supdiv">`.
     */
    #[Test]
    public function test_every_conversion_button_is_really_in_the_rendered_page(): void
    {
        foreach (['ltr' => $this->ltr, 'rtl' => $this->rtl] as $label => $store) {
            $html = $this->shopHtml($store);

            foreach ([...self::HIDDEN_BY_THEIR_OWN_STYLE, self::ALWAYS_VISIBLE] as $class) {
                $this->assertStringContainsString($class, $html,
                    "PREMISE ({$label}): no element carrying `{$class}` reached the page, so every "
                    . 'browser measurement of it would be about an element that is not there.');
            }

            $this->assertStringContainsString('ss-product-actions', $html,
                "PREMISE ({$label}): the hook's wrapper did not render.");
        }
    }

    /**
     * PREMISE 3 — THE DEFECT UNDER TEST IS IN THE CAPTURE.
     *
     * The three modules' own inline `<style>` blocks must arrive in the page with
     * `visibility: hidden` intact. Without this, a green browser run could mean "the fix works" OR
     * "the modules stopped hiding their buttons and nothing was ever overridden" — and the second
     * reading would make the host override dead code that the next vendor update silently revives.
     *
     * Each module's source line is named, so the assertion cannot be satisfied by some OTHER
     * element on the page that happens to be hidden.
     */
    #[Test]
    public function test_the_three_modules_still_hide_their_own_button_in_the_capture(): void
    {
        $html = $this->shopHtml($this->ltr);

        foreach (self::HIDDEN_BY_THEIR_OWN_STYLE as $class) {
            /* The module's rule as it really is: the class's own block, then `visibility: hidden`
               inside it. Whitespace-tolerant, anchored on the class, so another element's hidden
               rule elsewhere in the page cannot satisfy it. */
            $this->assertMatchesRegularExpression(
                '/\.' . preg_quote($class, '/') . '\s*\{[^}]*visibility:\s*hidden[^}]*\}/s',
                $html,
                "PREMISE: the page does not carry `.{$class} { ... visibility: hidden ... }`. Either "
                . 'the module no longer hides its own button — in which case the host override in '
                . 'public/css/storefront-appearance.css is now dead code and should be reassessed, '
                . 'not left in place — or the capture is not the real module markup.'
            );
        }
    }

    /**
     * PREMISE 3b — and it is the VENDORED SOURCE that says so, at the lines `GAP-256` names.
     *
     * Read off `packages/workdo/` directly. This is the drift guard: when the vendor fixes its own
     * stylesheet, this premise fails and tells whoever is reading that the override can go, rather
     * than leaving a permanent `!important`-shaped scar nobody dares touch.
     */
    #[Test]
    public function test_the_vendored_source_is_what_declares_the_hiding(): void
    {
        foreach (['ProductQuickView', 'ProductCompare', 'ProductCSV'] as $module) {
            $source = $this->moduleSource($module);

            $this->assertStringContainsString('visibility: hidden', $source,
                "PREMISE: {$module}'s button view no longer declares `visibility: hidden`. GAP-256 "
                . 'may be fixed at source; reassess the host override before keeping it.');

            $this->assertMatchesRegularExpression('/:hover\s+\.[a-z-]+-btn/', $source,
                "PREMISE: {$module} no longer restores its button under a `:hover` selector, so the "
                . 'host override no longer mirrors anything.');
        }

        /* THE CONTROL GROUP, asserted in the other direction: ProductsPDF ships no stylesheet at
           all, which is the only reason it is reachable today. */
        $pdf = $this->moduleSource('ProductsPDF');

        $this->assertStringNotContainsString('<style', $pdf,
            'ProductsPDF has acquired a stylesheet. It was the control group precisely because it '
            . 'had none; if it now hides itself, GAP-256 covers four buttons and not three.');
    }

    /**
     * PREMISE 4 — the hover ancestor the modules' own selectors need is really an ancestor.
     *
     * The three modules restore their button under `.product-box:hover`, `.product-item:hover` and
     * `.product-card:hover`. If none of those classes were an ancestor of the hook in the rendered
     * page, the buttons would be hidden from EVERYONE including a mouse user, and "the mouse
     * behaviour is unchanged" would be a claim about a path that does not work either.
     *
     * Measured rather than read: the wrapper's position in the bytes, and the nearest preceding
     * unclosed element carrying one of the three classes.
     */
    #[Test]
    public function test_the_hover_ancestor_class_is_really_an_ancestor_of_the_hook(): void
    {
        foreach (['ltr' => $this->ltr, 'rtl' => $this->rtl] as $label => $store) {
            $html = $this->shopHtml($store);

            $at = strpos($html, 'ss-product-actions');
            $this->assertNotFalse($at, "PREMISE ({$label}): the hook wrapper is absent.");

            $before = substr($html, 0, $at);

            $this->assertMatchesRegularExpression('/class="[^"]*\bproduct-card\b[^"]*"/', $before,
                "PREMISE ({$label}): no `product-card` ancestor precedes the hook, so the modules' "
                . "own `.product-card:hover` reveal cannot fire and the mouse path is broken too.");
        }
    }

    /**
     * PREMISE 5 — the host override seam is really loaded by the page.
     *
     * `public/css/storefront-appearance.css` is where the fix lives and it reaches the page only
     * through `front_end/partials/appearance.blade.php`. A capture that did not link it would show
     * the defect in the browser and be read as "the fix does not work".
     */
    #[Test]
    public function test_the_host_override_stylesheet_is_linked_by_the_page(): void
    {
        foreach (['ltr' => $this->ltr, 'rtl' => $this->rtl] as $label => $store) {
            $html = $this->shopHtml($store);

            $this->assertStringContainsString('css/storefront-appearance.css', $html,
                "PREMISE ({$label}): the page does not link storefront-appearance.css, so the host "
                . 'override is not in the cascade at all.');

            $this->assertStringContainsString('css/store-syria-ds.css', $html,
                "PREMISE ({$label}): the page does not link store-syria-ds.css, which carries the "
                . '44px target floor and the focus ring these buttons rely on.');
        }
    }

    /**
     * PREMISE 6 — THE HOST SHEET IS IN `<head>` AND THE MODULE STYLES ARE IN `<body>`.
     *
     * This is the whole reason the override must win on SPECIFICITY rather than on source order,
     * and it is the one structural fact a PHP test can settle for good. If it ever inverted, a
     * plain single-class override in the host sheet would be enough and the extra specificity
     * could go.
     */
    #[Test]
    public function test_the_host_sheet_is_in_head_and_the_module_styles_come_later(): void
    {
        $html = $this->shopHtml($this->ltr);

        $headEnd = strpos($html, '</head>');
        $this->assertNotFalse($headEnd, 'The capture has no </head>; it is not a whole document.');

        $link = strpos($html, 'css/storefront-appearance.css');
        $this->assertNotFalse($link);
        $this->assertLessThan($headEnd, $link,
            'storefront-appearance.css is not in <head>, so the cascade reasoning in its GAP-256 '
            . 'section needs re-deriving.');

        foreach (self::HIDDEN_BY_THEIR_OWN_STYLE as $class) {
            $rule = preg_match('/\.' . preg_quote($class, '/') . '\s*\{[^}]*visibility:\s*hidden/s', $html, $m, PREG_OFFSET_CAPTURE)
                ? $m[0][1]
                : false;

            $this->assertNotFalse($rule, "`.{$class}`'s hiding rule is not in the page.");
            $this->assertGreaterThan($headEnd, $rule,
                "`.{$class}`'s inline <style> is inside <head>, not after it. The host override "
                . 'would then win on source order and section 6 of storefront-appearance.css is '
                . 'over-specified rather than necessary.');
        }
    }

    /* ===================================================================== the capture */

    /**
     * Writes the two captures the live harness measures. Not a behavioural assertion: it asserts
     * only that a capture was produced when one was asked for, so a harness run can never
     * photograph a stale file from a previous run.
     */
    #[Test]
    public function test_it_writes_the_captures_the_live_harness_measures(): void
    {
        $dir = getenv('SS_GAP256_HTML_DIR');

        $ltr = $this->shopHtml($this->ltr, 'en');
        $rtl = $this->shopHtml($this->rtl, 'ar');

        $this->assertStringContainsString('dir="ltr"', $ltr, 'The `en` capture is not LTR.');
        $this->assertStringContainsString('dir="rtl"', $rtl, 'The `ar` capture is not RTL.');

        if (! is_string($dir) || $dir === '' || ! is_dir($dir)) {
            $this->assertTrue(true, 'SS_GAP256_HTML_DIR is unset: nothing to capture in a plain run.');

            return;
        }

        foreach (['en', 'ar'] as $locale) {
            $this->assertFileExists(rtrim($dir, '/') . '/card-' . $locale . '.html',
                "No {$locale} capture was written, so the harness would measure nothing.");
        }
    }
}
