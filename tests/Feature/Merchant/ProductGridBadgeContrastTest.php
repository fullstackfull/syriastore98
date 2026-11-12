<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * grid-view-badge-contrast — the product GRID painted its five stock states with the vendored
 * theme's `badge rounded-pill bg-*` classes, which resolve to low-contrast pairs.
 * =================================================================================================
 *
 * WHAT IT WAS. resources/views/product/list.blade.php carried six raw Bootstrap/theme badges:
 * :87 `bg-success` (the CATEGORY name), :117/:125 `bg-danger` ("Out of stock"), :119 `bg-warning`
 * ("On Backorder") and :121/:127 `bg-primary` ("In stock"). The vendored theme redefines those
 * classes with ALPHA backgrounds and fixed-hex text:
 *
 *     public/assets/css/style.css:9504   .bg-warning { background:#f4b41a26 !important; color:#f4b41a !important }
 *     public/assets/css/style.css:9514   .bg-danger  { background:rgba(255,174,189,.41) !important; color:#d80027 !important }
 *
 * and public/assets/css/style-dark.css:9508/:9518 repeat those DECLARATIONS byte-identically. Because
 * both backgrounds are alpha they composite over whatever card sits behind them, so the same
 * declaration yields a different OUTCOME per mode. Every ratio below was computed by this test file's
 * own {@see self::contrastRatio()} (WCAG 2.x relative luminance), not copied from a report:
 *
 *     bg-danger  on the light card (#ffffff)  -> #d80027 on rgb(255,222,228)  =  4.26:1   FAILS AA (4.5)
 *     bg-danger  on the dark card  (#292A33)  -> #d80027 on rgb(129,96,108)   =  1.03:1   INVISIBLE
 *     bg-warning on the light card            -> #f4b41a on rgb(253,244,221)  =  1.68:1   FAILS AA
 *
 * 1.03:1 is "Out of stock" on the highest-consequence field on the screen, and it is theme
 * INDEPENDENT: .bg-danger's colours are fixed hex, not brand-derived, so it fails on all ten shipped
 * themes and on the custom-colour default alike.
 *
 * THE REPAIR. View-only, in the one file. The five STOCK spans become the design system's
 * <x-ss.status-badge>, whose tones are OPAQUE token pairs (no card composite to get wrong) and
 * theme-independent; measured below at 7.10–9.81:1, all AAA, in both modes. The CATEGORY span
 * becomes <x-ss.chip :interactive="false"> — NOT a status badge: <x-ss.status-badge> always emits
 * `<span class="ss-badge__dot" aria-hidden="true">` as the second meaning channel for a STATUS
 * (resources/views/components/ss/status-badge.blade.php:2-4,:15), and a category is taxonomy, not a
 * status. The sibling table prints the category as plain text (app/DataTables/ProductDataTable.php:34-36).
 * `.ss-chip--static` uses the same neutral pair (8.12:1), has no dot, and does not promise a click.
 *
 * NO STYLESHEET WAS TOUCHED, which is why there is no public/css/rtl-custom.css twin to write:
 * `.ss-badge`/`.ss-chip` are logical-property-only (`padding-inline`, `gap`, `border-radius`) and
 * public/css/store-syria-ds.css is linked unconditionally at resources/views/layouts/app.blade.php:242,
 * outside the LTR/RTL branch. Asserted in both directions below.
 *
 * NOT CLAIMED: parity with the DataTable. The two screens really do disagree about stock
 * (ProductDataTable::trackedStockState() has a "Low stock" state on a per-product threshold; the grid
 * has two states on the global one and no variant_product guard). That divergence is a separate
 * finding; this test asserts only that the grid's stock RULE is byte-unchanged by the contrast fix.
 */
class ProductGridBadgeContrastTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    /** The view under repair. */
    private const VIEW = 'resources/views/product/list.blade.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Products');
        $this->merchant = User::find($this->merchant->id);
    }

    // =============================================================================================
    //  FIXTURES
    // =============================================================================================

    private function viewSource(): string
    {
        return (string) file_get_contents(base_path(self::VIEW));
    }

    private function categoryIn(Store $store): int
    {
        return DB::table('categories')->insertGetId([
            'name' => 'Grid Category ' . uniqid(), 'slug' => 'gc-' . uniqid(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function productIn(Store $store, string $name, array $overrides = []): Product
    {
        return Product::forceCreate(array_merge([
            'name' => $name, 'slug' => 'p-' . uniqid(),
            'product_stock' => 7, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_type' => null,
            'category_id' => $this->categoryIn($store),
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ], $overrides));
    }

    /**
     * A store-scoped setting. Written through Eloquent on purpose: Setting is Cachable
     * (app/Models/Setting.php:13), so a DB::table() insert would leave the reader serving the
     * pre-write row for the rest of the test.
     */
    private function setting(string $name, string $value): void
    {
        Setting::create([
            'name' => $name, 'value' => $value,
            'created_by' => $this->merchant->id, 'store_id' => $this->store->id,
        ]);
        Cache::forget('admin_details');
    }

    private function grid(?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->merchant)->get('/product-list');
    }

    // =============================================================================================
    //  THE WCAG MATHS. Computed here so the numbers in this file are measured, not quoted.
    // =============================================================================================

    /** @return array{0:float,1:float,2:float} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** sRGB -> relative luminance, WCAG 2.x. */
    private function luminance(array $rgb): float
    {
        $f = static function (float $c): float {
            $c /= 255.0;
            return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * $f($rgb[0]) + 0.7152 * $f($rgb[1]) + 0.0722 * $f($rgb[2]);
    }

    private function contrastRatio(string $fg, string $bg): float
    {
        $a = $this->luminance($this->rgb($fg));
        $b = $this->luminance($this->rgb($bg));
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** Composite an alpha colour over an opaque one, as a browser does. */
    private function over(array $fg, float $alpha, string $bgHex): string
    {
        $bg = $this->rgb($bgHex);
        return sprintf('#%02x%02x%02x',
            (int) round($fg[0] * $alpha + $bg[0] * (1 - $alpha)),
            (int) round($fg[1] * $alpha + $bg[1] * (1 - $alpha)),
            (int) round($fg[2] * $alpha + $bg[2] * (1 - $alpha)));
    }

    // =============================================================================================
    //  THE DEFECT, MEASURED — and the NEGATIVE CONTROL on the maths itself
    // =============================================================================================

    /**
     * NEGATIVE CONTROL ON THE INSTRUMENT. If contrastRatio() could not see the old pairs failing,
     * every "passes" below would be worthless. These three numbers are the defect.
     */
    public function test_the_theme_badge_pairs_the_grid_used_to_carry_really_do_fail(): void
    {
        // .bg-danger — style.css:9514 and style-dark.css:9518, byte-identical declarations.
        $dangerOnLightCard = $this->over([255, 174, 189], 0.41, '#ffffff');
        $dangerOnDarkCard = $this->over([255, 174, 189], 0.41, '#292A33');   // --bs-card-bg, style-dark.css:5006

        $this->assertSame(4.26, round($this->contrastRatio('#d80027', $dangerOnLightCard), 2),
            '.bg-danger "Out of stock" over the white card.');
        $this->assertSame(1.03, round($this->contrastRatio('#d80027', $dangerOnDarkCard), 2),
            '.bg-danger "Out of stock" over the dark card #292A33 — effectively invisible.');

        // .bg-warning — style.css:9504. #f4b41a26 is #f4b41a at alpha 0x26/255.
        $warningOnLightCard = $this->over([0xf4, 0xb4, 0x1a], 0x26 / 255, '#ffffff');
        $this->assertSame(1.68, round($this->contrastRatio('#f4b41a', $warningOnLightCard), 2),
            '.bg-warning "On Backorder" over the white card.');

        $this->assertLessThan(4.5, $this->contrastRatio('#d80027', $dangerOnLightCard));
        $this->assertLessThan(4.5, $this->contrastRatio('#f4b41a', $warningOnLightCard));
    }

    /**
     * And the design system's tones, which replace them, all clear AAA (7:1) in BOTH modes.
     * Values read out of public/css/store-syria-ds.css: light tokens at :187-195 / :288-297,
     * dark at :423-427.
     */
    public function test_the_design_system_tones_that_replace_them_are_aaa_in_both_modes(): void
    {
        $measured = [
            // label                fg         bg          expected (computed by this file)
            'light danger'  => ['#8c1d17', '#fdeeee', 8.09],
            'light warning' => ['#5d3f04', '#fdf4e3', 8.83],
            'light success' => ['#165e34', '#eaf7ef', 7.10],
            'light neutral' => ['#43474d', '#eeeff1', 8.12],
            'dark danger'   => ['#f3a9a4', '#2c1413', 9.06],
            'dark warning'  => ['#f0c674', '#2c2109', 9.81],
            'dark success'  => ['#7fd6a0', '#102a1b', 8.79],
            'dark neutral'  => ['#c3c6cc', '#21252c', 8.98],
        ];

        $css = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        foreach ($measured as $label => [$fg, $bg, $expected]) {
            $this->assertSame($expected, round($this->contrastRatio($fg, $bg), 2),
                $label . ' tone pair changed value — re-measure before changing the number.');
            $this->assertGreaterThanOrEqual(7.0, $this->contrastRatio($fg, $bg),
                $label . ' no longer clears AAA.');
            // PREMISE: the stylesheet really still declares these two colours, so the ratio above
            // is a statement about the shipped CSS and not about eight literals in a test.
            $this->assertStringContainsString($fg, $css, $label . ' foreground is no longer in the stylesheet.');
            $this->assertStringContainsString($bg, $css, $label . ' background is no longer in the stylesheet.');
        }
    }

    // =============================================================================================
    //  THE REPAIR — the view no longer carries a theme badge
    // =============================================================================================

    /** Not one `badge rounded-pill bg-*` is left in the grid. This is the defect's fingerprint. */
    public function test_the_view_carries_no_theme_badge_classes_at_all(): void
    {
        $source = $this->viewSource();

        $this->assertStringNotContainsString('badge rounded-pill', $source,
            'A low-contrast theme badge is still in the product grid.');

        foreach (['bg-success', 'bg-warning', 'bg-danger'] as $class) {
            $this->assertStringNotContainsString($class, $source,
                $class . ' is still used in the product grid.');
        }

        // Keeping `bg-*` ALONGSIDE `ss-badge` would hand the background back: body.custom-color
        // .bg-primary (public/css/custom-color.css:5) is higher-specificity and carries !important.
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bss-(?:badge|chip)\b[^"]*\bbg-/', $source,
            'A DS badge/chip is still carrying a theme bg-* class, which would win the background back.');
    }

    /** The five stock spans are the design system's status badge, dot and all. */
    public function test_the_five_stock_states_render_as_design_system_status_badges(): void
    {
        $source = $this->viewSource();

        $this->assertSame(2, substr_count($source, '<x-ss.status-badge tone="danger" :label="__(\'Out of stock\')" />'),
            'Both "Out of stock" branches (the untracked stock_status one and the threshold one) must convert.');
        $this->assertSame(1, substr_count($source, '<x-ss.status-badge tone="warning" :label="__(\'On Backorder\')" />'));
        $this->assertSame(2, substr_count($source, '<x-ss.status-badge tone="success" :label="__(\'In stock\')" />'));
    }

    /** Rendered, for a product that is genuinely out of stock. */
    public function test_an_out_of_stock_product_renders_the_danger_tone_in_the_response(): void
    {
        $this->setting('out_of_stock_threshold', '0');
        $this->productIn($this->store, 'Sold Out Scarf', ['product_stock' => 0, 'track_stock' => 1]);

        $html = $this->grid()->assertOk()->getContent();

        $this->assertStringContainsString('ss-badge ss-badge--danger', $html,
            'The out-of-stock product did not get the DS danger tone (8.09:1 light / 9.06:1 dark).');
        $this->assertStringContainsString('ss-badge__dot', $html,
            'The status dot is the second meaning channel (WCAG 1.4.1); colour alone must not carry it.');
        $this->assertStringContainsString('Out of stock', $html);
        $this->assertStringNotContainsString('badge rounded-pill bg-danger', $html);
    }

    /** And the in-stock and backorder tones, driven from the two separate branches. */
    public function test_the_in_stock_and_backorder_tones_render(): void
    {
        $this->setting('out_of_stock_threshold', '0');
        $this->productIn($this->store, 'Plenty In Stock', ['product_stock' => 9, 'track_stock' => 1]);

        $html = $this->grid()->assertOk()->getContent();
        $this->assertStringContainsString('ss-badge ss-badge--success', $html);
        $this->assertStringContainsString('In stock', $html);

        // The untracked branch, which reads stock_status instead of the threshold.
        Product::where('store_id', $this->store->id)->delete();
        $this->productIn($this->store, 'Awaiting Restock', [
            'product_stock' => 0, 'track_stock' => 0, 'stock_status' => 'on_backorder',
        ]);

        $html = $this->grid()->assertOk()->getContent();
        $this->assertStringContainsString('ss-badge ss-badge--warning', $html,
            'The backorder state did not get the DS warning tone (8.83:1 light / 9.81:1 dark).');
        $this->assertStringContainsString('On Backorder', $html);
    }

    /**
     * THE CATEGORY IS NOT A STATUS. It becomes a static chip — same neutral pair (8.12:1), pill
     * radius, no aria-hidden status dot in front of a taxonomy value or in front of the bare `-`
     * placeholder a product with no category prints.
     */
    public function test_the_category_renders_as_a_static_chip_and_not_as_a_status_badge(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString('<x-ss.chip :interactive="false"', $source,
            'The category pill must be the static chip, not the status badge.');
        $this->assertDoesNotMatchRegularExpression(
            '/<x-ss\.status-badge[^>]*ProductData/s', $source,
            'The category name must not be rendered through the status badge — it would gain a status dot.');

        $this->productIn($this->store, 'Chip Bearing Product');
        $html = $this->grid()->assertOk()->getContent();

        $this->assertStringContainsString('ss-chip ss-chip--static', $html,
            'The rendered category is not the static chip.');
        $this->assertStringContainsString('Grid Category', $html, 'The category name itself disappeared.');
    }

    // =============================================================================================
    //  NEGATIVE CONTROL 1 — THE STOCK RULE MUST NOT HAVE MOVED
    // =============================================================================================

    /**
     * The cross-repo contract. /home/user/StoreSyria_app98/lib/core/stock/stock_state.dart:59
     * implements `<=` citing this exact view line, and test/ui/stock_state_parity_test.dart pins it
     * in ten cases. A contrast fix that "tidied" the nesting into `<` is a product the merchant
     * thinks they can still sell.
     */
    public function test_the_stock_rule_in_the_view_is_byte_identical(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString("@if (\$product->track_stock == 0)", $source);
        $this->assertStringContainsString("@if (\$product->stock_status == 'out_of_stock')", $source);
        $this->assertStringContainsString("@elseif(\$product->stock_status == 'on_backorder')", $source);
        $this->assertStringContainsString(
            "@if (\$product->product_stock <= (isset(\$settings['out_of_stock_threshold']) ? \$settings['out_of_stock_threshold'] : 0))",
            $source,
            'The threshold comparison must stay `<=` against out_of_stock_threshold.');
        $this->assertStringNotContainsString(
            "\$product->product_stock < (isset(\$settings['out_of_stock_threshold'])",
            $source,
            'The comparison was flipped to `<` — an off-by-one on the out-of-stock boundary.');
    }

    /** And behaviourally: stock EQUAL to the threshold is out of stock; one above it is not. */
    public function test_the_threshold_boundary_still_counts_equal_as_out_of_stock(): void
    {
        $this->setting('out_of_stock_threshold', '5');

        $this->productIn($this->store, 'Exactly At Threshold', ['product_stock' => 5, 'track_stock' => 1]);
        $html = $this->grid()->assertOk()->getContent();
        $this->assertStringContainsString('ss-badge ss-badge--danger', $html,
            'product_stock == out_of_stock_threshold must still read "Out of stock" (the `<=` contract).');

        Product::where('store_id', $this->store->id)->delete();
        $this->productIn($this->store, 'One Above Threshold', ['product_stock' => 6, 'track_stock' => 1]);
        $html = $this->grid()->assertOk()->getContent();
        $this->assertStringContainsString('ss-badge ss-badge--success', $html,
            'One above the threshold must still read "In stock".');
        $this->assertStringNotContainsString('ss-badge ss-badge--danger', $html);
    }

    // =============================================================================================
    //  NEGATIVE CONTROL 2 — DARK MODE STILL RESOLVES THE TOKENS
    // =============================================================================================

    /**
     * `.ss-badge`'s tones come from `[data-ss-theme="dark"]` (store-syria-ds.css:392, tones :423-427),
     * set server-side on <html> at resources/views/layouts/app.blade.php:86 from
     * $setting['cust_darklayout']. The repair is safe by construction — the badge sets BOTH
     * background and colour opaquely, so even a missing attribute yields a legible light badge rather
     * than today's 1.03:1 — but this is the control proving the failure did not simply move.
     */
    public function test_in_host_dark_mode_the_attribute_is_present_and_the_tone_still_renders(): void
    {
        $this->setting('cust_darklayout', 'on');
        $this->setting('out_of_stock_threshold', '0');
        $this->productIn($this->store, 'Dark Mode Sold Out', ['product_stock' => 0, 'track_stock' => 1]);

        $html = $this->grid()->assertOk()->getContent();

        $this->assertStringContainsString('data-ss-theme="dark"', $html,
            'Host dark mode did not reach <html>, so the dark tone tokens would not resolve.');
        $this->assertStringContainsString('ss-badge ss-badge--danger', $html,
            'The DS danger tone must render in dark mode too — 9.06:1, against 1.03:1 before.');
    }

    // =============================================================================================
    //  NEGATIVE CONTROL 3 — ARABIC / RTL NEEDS NO SECOND EDIT
    // =============================================================================================

    /**
     * public/css/custom.css serves LTR and public/css/rtl-custom.css serves Arabic, so a repair that
     * added a CSS rule would have to be written twice or reach half the merchants. This repair adds
     * NO rule: `.ss-badge`/`.ss-chip` are logical-property-only and store-syria-ds.css is linked
     * unconditionally at layouts/app.blade.php:242, outside the dir branch.
     */
    public function test_the_arabic_rtl_render_carries_the_same_badge_with_no_stylesheet_change(): void
    {
        $this->setting('out_of_stock_threshold', '0');
        $this->productIn($this->store, 'Arabic Sold Out', ['product_stock' => 0, 'track_stock' => 1]);

        $arabic = User::find($this->merchant->id);
        $arabic->language = 'ar';
        $arabic->save();

        $html = $this->grid(User::find($arabic->id))->assertOk()->getContent();

        $this->assertStringContainsString('dir="rtl"', $html, 'The Arabic render is not RTL.');
        $this->assertStringContainsString('ss-badge ss-badge--danger', $html,
            'The badge must be identical in Arabic — no RTL-specific markup, no rtl-custom.css twin.');
        $this->assertStringContainsString('css/store-syria-ds.css', $html,
            'The design system stylesheet must be linked in the RTL render too.');
    }

    /**
     * And the proof that no stylesheet was edited at all, which is what keeps
     * DesignSystemStylesheetScopeTest.php:161/:244 and the custom.css/rtl-custom.css duplication
     * hazard out of this repair entirely.
     */
    public function test_the_repair_added_no_rule_to_either_custom_stylesheet(): void
    {
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $css = (string) file_get_contents(base_path($sheet));
            $this->assertStringNotContainsString('.ss-badge', $css,
                $sheet . ' now redefines .ss-badge — the design system owns that class.');
            $this->assertStringNotContainsString('.ss-chip--static', $css,
                $sheet . ' now redefines .ss-chip--static — the design system owns that class.');
        }
    }

    // =============================================================================================
    //  NEGATIVE CONTROL 4 — THE GATE AND THE TENANT PREDICATE ARE UNTOUCHED
    // =============================================================================================

    /** A view-only change cannot widen the gate, and this fails loudly if a later edit does. */
    public function test_a_principal_without_manage_products_is_still_refused(): void
    {
        $outsider = $this->makeStaff($this->merchant, ['name' => 'No Products Grid']);
        $outsider->current_store = $this->store->id;
        $outsider->save();

        $this->grid(User::find($outsider->id))->assertRedirect();
    }

    /**
     * Product is NOT in config/tenancy.php's scoped_models, so the global scope adds no predicate —
     * the only isolation is the explicit ->where('store_id', getCurrentStore()) in the handler.
     */
    public function test_another_tenants_product_is_still_absent_from_the_grid(): void
    {
        $other = $this->makeMerchant(['email' => 'grid-other-' . uniqid() . '@example.test']);
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);
        $this->productIn($otherStore, 'Aleppo Soap Of Another Tenant');
        $this->productIn($this->store, 'My Own Grid Product');

        $this->grid()->assertOk()
            ->assertSee('My Own Grid Product', false)
            ->assertDontSee('Aleppo Soap Of Another Tenant', false);
    }

    /** paginate(11) — a twelfth product must not be on page one. */
    public function test_the_grid_is_still_paginated_at_eleven(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->productIn($this->store, sprintf('Grid Product %02d', $i));
        }

        $response = $this->grid()->assertOk();
        $products = $response->original->getData()['products'];

        $this->assertSame(11, $products->perPage());
        $this->assertCount(11, $products->items());
    }

    /**
     * SCOPE CONTROL. The decorative icon chip on the Add-Product tile carries no text, so it is not
     * a status badge and must NOT have been converted — that would have turned a contrast fix into a
     * layout change.
     */
    public function test_the_decorative_add_product_icon_chip_was_left_alone(): void
    {
        $this->assertStringContainsString('<div class="bg-primary proj-add-icon">', $this->viewSource(),
            'The Add-Product tile\'s decorative icon chip was converted; it carries no text and is out of scope.');
    }
}
