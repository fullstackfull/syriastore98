<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * THE SHOPPER MUST BE TOLD. A line dropped silently is worse than the 500 it replaced.
 * =============================================================================================
 *
 * GAP-241 / GAP-245 and their guest twin stopped `cart-list` answering HTTP 500 for a basket
 * holding a delisted product, and both arms now report `unavailable_products` and
 * `unavailable_message` in their payload. NOTHING RENDERED THEM. So the line simply vanished from
 * the basket with no explanation — the shopper believes they are buying something they are not,
 * which is a trust failure rather than a bug they can see.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT WAS MEASURED BEFORE TOUCHING A VIEW, because CLAUDE.md §6 names three traps here and two
 * of them turned out NOT to apply to this file:
 *
 *   1. THE FOUR-COPY RULE DOES NOT APPLY. `front_end/layouts/app.blade.php` exists four times —
 *      once per theme plus the `resources/views` fallback — and a theme's own wins through
 *      `Theme::set()` → `prependLocation()`. `pages/cart-list.blade.php` is NOT multiplied:
 *      `find` over the tree returns exactly ONE copy, and of the three themes only `stylique` has
 *      a `front_end/pages/` directory at all, holding only `bestseller.blade.php`. So one edit
 *      covers greentic, stylique and techzonix. Measured, not assumed.
 *
 *   2. THE TAILWIND DRIFT TRAP DOES APPLY, and it decides the colours. The storefront loads the
 *      Tailwind runtime JIT CDN, so a utility beats the theme stylesheet on source order, and
 *      dark mode is delivered by `public/css/storefront-appearance.css` mapping a FIXED SET of
 *      utilities to tokens — measured from that file: `bg-white`, `bg-gray-50/100/200`,
 *      `bg-primary`, `text-black`, `text-white`, `text-primary`, `text-gray-400…900`,
 *      `border-gray-100/200/300`, and nothing else. An amber panel built from `bg-amber-50` would
 *      therefore have stayed LIGHT on a dark page, looking right in every light-mode check — the
 *      shape that let 18 contrast failures survive an already-fixed stylesheet.
 *
 *      So the notice uses the DESIGN SYSTEM's own component, `.ss-alert ss-alert--warning`, whose
 *      pair is `--ss-warning-bg` / `--ss-warning-fg` — tokens that carry explicit dark values in
 *      the DS (`#2c2109` / `#f0c674`) alongside the light pair (`#fdf4e3` / `#5d3f04`), and whose
 *      box uses logical properties (`border-inline-start`, `padding-inline`) so RTL needs no
 *      second rule. `store-syria-ds.css` IS loaded on the storefront, through
 *      `front_end/partials/appearance.blade.php`, which all three theme layouts include — checked,
 *      because M1 had measured it loaded NOWHERE and that has since changed.
 *
 *   3. `--ss-warning-subtle` and `--ss-warning-border` MUST NOT BE USED. The DS records at
 *      `store-syria-ds.css:1366-1370` that an earlier rule used them with fallbacks, that neither
 *      token exists in any theme block, and that the rule therefore "read as if it used the
 *      warning palette while silently painting the neutral one". Only `--ss-warning-bg` and
 *      `--ss-warning-fg` are real.
 *
 * The measured contrast ratios of the rendered element, in a real Chromium, in all four
 * combinations, are recorded by `tests/Support/bin/measure-cart-notice-contrast.cjs`. This file
 * guards the structure that measurement depends on.
 */
class CartStaleLineNoticeRendersTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const VIEWS = [
        'resources/views/front_end/pages/cart-list.blade.php',
        'resources/views/front_end/pages/checkout-cart-list.blade.php',
    ];

    private const PARTIAL = 'resources/views/front_end/partials/cart-unavailable-notice.blade.php';

    // ──────────────────────────────────────────────────────────────────────────────── fixtures

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        \Illuminate\Support\Facades\Cache::forget('admin_details');

        return [$merchant, $store];
    }

    private function productIn($store, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name, 'slug' => 'notice-' . uniqid(), 'description' => 'x',
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1, 'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function deleteProduct(Product $product): void
    {
        DB::table('products')->where('id', $product->id)->delete();
        (new Product())->flushCache();

        $this->assertNull(DB::table('products')->where('id', $product->id)->first(),
            'premise: the product was not actually deleted, so this case is vacuous');
    }

    /** A guest basket holding one healthy line and, optionally, one whose product is deleted. */
    private function guestBasket($store, bool $withStaleLine): string
    {
        $sid = 'notice-' . uniqid();

        $healthy = $this->productIn($store, 'NOTICE-HEALTHY-ITEM');
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $healthy->id, 'variant_id' => 0, 'qty' => 1,
            'price' => 100.00, 'store_id' => $store->id, 'cookie_session_id' => $sid,
        ]);

        if ($withStaleLine) {
            $gone = $this->productIn($store, 'NOTICE-DELISTED-ITEM');
            Cart::forceCreate([
                'customer_id' => 0, 'product_id' => $gone->id, 'variant_id' => 0, 'qty' => 1,
                'price' => 100.00, 'store_id' => $store->id, 'cookie_session_id' => $sid,
            ]);
            $this->deleteProduct($gone);
        }

        return $sid;
    }

    private function sidebar($store, string $sid, array $cookies = [])
    {
        $test = $this->withCookie('cart', $sid);

        foreach ($cookies as $name => $value) {
            $test = $test->withCookie($name, $value);
        }

        return $test->post('/' . $store->slug . '/cart-list-sidebar', ['slug' => $store->slug]);
    }

    /** The rendered cart page and the rendered checkout summary, from the route's own payload. */
    private function renderedViews($response): array
    {
        $body = json_decode($response->getContent(), true) ?: [];

        return [
            'cart page' => (string) ($body['checkout_html'] ?? ''),
            'checkout summary' => (string) ($body['checkout_html_2'] ?? ''),
        ];
    }

    // ───────────────────────────────────────────────────────────────────────── the notice itself

    /**
     * A DELETED PRODUCT CANNOT BE NAMED, AND THAT IS THE COMMON CASE — measured here, not assumed.
     *
     * This case originally asserted that the dropped product's NAME appeared, and it failed. The
     * reason is a real limitation of the chosen fix rather than a bug in the view: `carts` holds
     * `customer_id`, `product_id`, `variant_id`, `qty`, `price`, `store_id` and later `size_data`
     * / `cookie_session_id` — and NO product name (2024_01_18_103138_create_carts_table.php). So
     * when the PRODUCT row is gone there is nowhere left to read a name from, and
     * `unavailable_message` correctly falls back to its nameless form.
     *
     * THAT IS THE ORDINARY CASE. "A merchant delisted a product" is precisely the event GAP-241
     * describes, so the half of its recommendation that says "tell the shopper WHICH product went
     * away" is only fully achievable for the variant case below. Reported rather than papered
     * over: closing it means a product name on `carts`, which is a migration on a live table and
     * so the owner's call, not this slice's.
     */
    public function test_the_cart_page_tells_a_guest_that_something_was_removed(): void
    {
        [, $store] = $this->tenant();
        $sid = $this->guestBasket($store, true);

        $response = $this->sidebar($store, $sid);
        $this->assertSame(200, $response->status());

        foreach ($this->renderedViews($response) as $where => $html) {
            $this->assertNotSame('', $html, "the {$where} rendered nothing, so this case is vacuous");

            $this->assertStringContainsString('no longer available', $html,
                "the {$where} never tells the shopper anything was removed, so the line vanished "
                . 'in silence - which is worse than the 500 it replaced');
            $this->assertStringContainsString('ss-alert--warning', $html,
                "the {$where} does not use the design system's alert component, so its colours do "
                . 'not come from --ss-warning-bg/--ss-warning-fg and dark mode is not covered');
            $this->assertStringContainsString('NOTICE-HEALTHY-ITEM', $html,
                "the {$where} lost the surviving line as well");

            /* PINNED, so the limitation is visible rather than discovered again: the nameless
               wording is what a deleted PRODUCT produces. If a product name ever reaches `carts`,
               this assertion is the one that will say so. */
            $this->assertStringNotContainsString('NOTICE-DELISTED-ITEM', $html,
                "the {$where} named a product whose row is deleted, which `carts` cannot support - "
                . 'so either a name column was added or the name came from somewhere unintended');
        }
    }

    /** AND WHEN ONLY THE VARIANT WENT, the product row survives and the shopper IS told which. */
    public function test_the_cart_page_names_the_product_when_only_its_variant_went(): void
    {
        [, $store] = $this->tenant();
        $sid = 'notice-' . uniqid();

        $product = Product::forceCreate([
            'name' => 'NOTICE-NAMEABLE-ITEM', 'slug' => 'notice-' . uniqid(), 'description' => 'x',
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 1,
            'stock_status' => 'in_stock', 'status' => 1, 'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $product->id, 'variant' => 'red/large', 'sku' => 'SKU-' . uniqid(),
            'price' => 100.00, 'stock' => 50, 'stock_status' => 'in_stock',
            'low_stock_threshold' => 0, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => $variantId,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id, 'cookie_session_id' => $sid,
        ]);

        DB::table('product_variants')->where('id', $variantId)->delete();
        (new \App\Models\ProductVariant())->flushCache();
        $this->assertNull(DB::table('product_variants')->where('id', $variantId)->first(),
            'premise: the variant was not actually deleted, so this case is vacuous');

        $response = $this->sidebar($store, $sid);

        foreach ($this->renderedViews($response) as $where => $html) {
            $this->assertStringContainsString('no longer available', $html,
                "the {$where} said nothing about the dropped variant line");
            $this->assertStringContainsString('NOTICE-NAMEABLE-ITEM', $html,
                "the {$where} did not name the product even though its row still exists, so the "
                . 'nameable half of the message is not reaching the view');
        }
    }

    public function test_the_cart_page_tells_a_signed_in_shopper_too(): void
    {
        [, $store] = $this->tenant();

        $shopper = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'notice-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        $healthy = $this->productIn($store, 'NOTICE-HEALTHY-ITEM');
        $gone = $this->productIn($store, 'NOTICE-DELISTED-ITEM');

        foreach ([$healthy, $gone] as $p) {
            Cart::forceCreate([
                'customer_id' => $shopper->id, 'product_id' => $p->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            ]);
        }

        $this->deleteProduct($gone);

        $response = $this->actingAs($shopper, 'customers')
            ->post('/' . $store->slug . '/cart-list-sidebar', ['slug' => $store->slug]);

        $this->assertSame(200, $response->status());

        foreach ($this->renderedViews($response) as $where => $html) {
            $this->assertStringContainsString('no longer available', $html,
                "the {$where} shows the notice for a guest but not for a signed-in shopper, so "
                . 'only one of the two arms reaches the view');
            $this->assertStringContainsString('ss-alert--warning', $html);
            $this->assertStringContainsString('NOTICE-HEALTHY-ITEM', $html,
                "the {$where} lost the surviving line, so this is measuring an empty basket");
        }
    }

    /** CONTROL: a healthy basket must not draw an empty panel. */
    public function test_a_healthy_basket_draws_no_notice_at_all(): void
    {
        [, $store] = $this->tenant();
        $sid = $this->guestBasket($store, false);

        $response = $this->sidebar($store, $sid);

        foreach ($this->renderedViews($response) as $where => $html) {
            $this->assertStringContainsString('NOTICE-HEALTHY-ITEM', $html,
                "the {$where} did not render the basket, so this control proves nothing");
            $this->assertStringNotContainsString('ss-alert--warning', $html,
                "the {$where} draws the notice panel for a basket with nothing wrong with it");
            $this->assertStringNotContainsString('no longer available', $html);
        }
    }

    /** ARABIC. The storefront is bilingual and the message must not be an English island. */
    public function test_the_notice_renders_in_arabic_for_an_arabic_visitor(): void
    {
        [, $store] = $this->tenant();
        $sid = $this->guestBasket($store, true);

        $response = $this->sidebar($store, $sid, ['LANGUAGE' => 'ar']);
        $this->assertSame(200, $response->status());

        foreach ($this->renderedViews($response) as $where => $html) {
            $this->assertStringContainsString('لم تعد متوفرة', $html,
                "the {$where} printed the English message to an Arabic visitor");
            $this->assertStringNotContainsString('no longer available', $html,
                "the {$where} printed BOTH languages, so the key is resolving twice or the "
                . 'literal is hard-coded beside the translation');
        }
    }

    // ───────────────────────────────────────────────────── the structural guards behind the above

    /**
     * THE NOTICE MUST NOT CARRY A COLOUR UTILITY THE APPEARANCE LAYER DOES NOT MAP.
     *
     * This is the measured failure mode, not a style preference: dark mode on the storefront is
     * `storefront-appearance.css` re-pointing a FIXED set of Tailwind utilities at tokens, so a
     * colour class outside that set paints the same in both themes and is invisible to every
     * light-mode check. The set is read from that file rather than hard-coded here, so the
     * assertion follows the layer if the layer grows.
     *
     * COMMENTS ARE STRIPPED, and that is load-bearing: the partial's own prose explains why
     * `bg-amber-50` was rejected and names it, so a plain grep over the file would fail on the
     * explanation of the thing it forbids. CLAUDE.md records eight occasions of exactly that.
     */
    public function test_the_notice_uses_no_colour_utility_the_dark_layer_cannot_reach(): void
    {
        $layer = (string) file_get_contents(base_path('public/css/storefront-appearance.css'));
        preg_match_all('/\.((?:bg|text|border)-[a-z0-9-]+)/', $layer, $m);
        $mapped = array_unique($m[1]);

        $this->assertContains('bg-gray-50', $mapped,
            'premise: the appearance layer no longer maps the utilities this test assumes it does');

        $source = $this->sourceWithoutComments(self::PARTIAL);

        preg_match_all('/\b((?:bg|text|border)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|white|black)(?:-\d{1,3})?)\b/', $source, $used);

        $unmapped = array_values(array_diff(array_unique($used[1]), $mapped));

        $this->assertSame([], $unmapped,
            'The cart notice uses colour utilities that public/css/storefront-appearance.css does '
            . 'not map, so they paint identically in light and dark mode: '
            . implode(', ', $unmapped));
    }

    /**
     * ONE COPY OF THE MARKUP, AND IT IS INCLUDED BY BOTH VIEWS.
     *
     * A second copy is how the cart page and the checkout summary drift apart, and the checkout
     * summary is the one that matters most — it is the last thing a shopper reads before paying.
     */
    public function test_both_cart_views_include_the_one_shared_partial(): void
    {
        $this->assertFileExists(base_path(self::PARTIAL));

        foreach (self::VIEWS as $view) {
            $source = $this->sourceWithoutComments($view);

            $this->assertStringContainsString('front_end.partials.cart-unavailable-notice', $source,
                $view . ' does not include the shared notice partial');
            $this->assertStringNotContainsString('ss-alert--warning', $source,
                $view . ' carries its own copy of the notice markup, which is how the two views '
                . 'drift apart');
        }
    }

    /**
     * RTL COMES FROM LOGICAL PROPERTIES, NOT FROM A SECOND RULE.
     *
     * The DS alert box is built on `border-inline-start` and `padding-inline`, so it mirrors
     * itself under `dir="rtl"`. A physical-direction utility in the partial would break that in
     * Arabic only — the direction nobody checks first.
     */
    public function test_the_notice_uses_no_physical_direction_utility(): void
    {
        $source = $this->sourceWithoutComments(self::PARTIAL);

        foreach (['border-l', 'border-r', 'pl-', 'pr-', 'ml-', 'mr-', 'text-left', 'text-right'] as $physical) {
            $this->assertStringNotContainsString($physical, $source,
                'The notice uses the physical-direction utility `' . $physical . '`, which does '
                . 'not mirror under dir="rtl" and so is wrong in Arabic only.');
        }
    }
}
