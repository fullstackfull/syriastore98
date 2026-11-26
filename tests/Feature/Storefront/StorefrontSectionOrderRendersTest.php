<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\ThemeCustomizeOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-099 / P03-B-06 — THE MERCHANT'S SAVED SECTION ORDER IS WHAT A SHOPPER SEES.
 * =================================================================================================
 *
 * WHICH HALF WAS MISSING, MEASURED BEFORE A LINE WAS WRITTEN. A census over `app/`, `themes/`,
 * `resources/`, `config/` and `routes/` with PHP and Blade comments STRIPPED found four files
 * naming `ThemeCustomizeOrder`, `resolveFor` or `theme_customize_orders`: `config/tenancy.php`, the
 * model, `ThemeCustomizeController`, and the editor view. `resolveFor()` had exactly one caller —
 * `changeOrder()`, the editor screen redisplaying what it had just saved. So the WRITE half worked
 * and the READ half did not exist, which is what `PHASE-03-FINAL-REPORT.md` §9 deferred as *"the M4
 * reorder UI | waits on the storefront consuming saved ordering (Phase 04)"* — an ENGINEERING
 * deferral into a phase that is closed and never received it.
 *
 * THIS FILE DRIVES BOTH HALVES THROUGH THEIR REAL SURFACES. The ordering is saved by POSTing the
 * merchant's own form to `theme.customize.update.order`, not by inserting a row, so a change that
 * broke the validator would fail here too. It is then read back by GETting the shopper-facing
 * storefront home page and looking at the order of the rendered markup.
 *
 * EVERY DATABASE ASSERTION GOES THROUGH `withoutGlobalScopes()`. `ThemeCustomizeOrder` is in
 * `config/tenancy.php`'s `scoped_models` and `StoreScope` really filters it, so a plain
 * `ThemeCustomizeOrder::find()` here would answer against whichever tenant happens to be resolved
 * rather than against the table — and an "it saved" assertion would pass whether or not the write
 * worked. That has already produced a false reading in this project.
 *
 * THE SECTION MARKERS ARE THE THEME'S OWN SETTINGS, not markup this change added. `product_title`,
 * `category_title`, `bestseller_title`, `testimonial_title` and `blog_title` are printed by
 * stylique's `main_file.blade.php` and stored per store in `theme_customizes`, so overriding them
 * with unique strings makes the rendered order observable without adding a single attribute to the
 * page. `hero-slider`, `partnerSwiper` and the two grid container classes are the stable class
 * names of the four sections that carry no title setting.
 *
 * EACH OF THE NINE MARKERS OCCURS EXACTLY ONCE in `themes/stylique/views/main_file.blade.php` —
 * measured — and the four class names appear elsewhere only in linked `.css` and `.js` files, which
 * are never inlined into the page. The instrument re-asserts that once-ness on every rendered page
 * rather than trusting it, because a marker appearing twice would silently fix its own position.
 *
 * TWO FIRST CHOICES WERE WRONG, BOTH CAUGHT BY THE INSTRUMENT AND NOT BY READING. `Shop
 * Collection` sits inside `@foreach (collect($MainCategoryList)->reverse()->take(2) ...)`, so on a
 * store with no categories the section renders and the marker does not — measured absent.
 * `service-card` sits inside the `service_repeater` loop and rendered FOUR times, which would have
 * pinned its own position. Both are now the section's own grid container class, outside every loop
 * and emitted once.
 */
class StorefrontSectionOrderRendersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * stylique's top-level blocks, in the order `themes/stylique/views/main_file.blade.php` renders
     * them, each with a string that appears ONLY inside that block.
     *
     * `product_list` is the module hook anchor at `main_file.blade.php:269`. It is not a theme
     * section, carries no `_status` gate and renders nothing in this fixture, so it has no marker —
     * it is named here only to document that a block sits in that slot and must keep it.
     */
    private const MARKERS = [
        'slider' => 'hero-slider',
        'logo' => 'partnerSwiper',
        'collection' => 'grid md:grid-cols-2 gap-6',
        'product' => 'SSMARK-PRODUCT',
        'category' => 'SSMARK-CATEGORY',
        'bestseller' => 'SSMARK-BESTSELLER',
        'testimonial' => 'SSMARK-TESTIMONIAL',
        'blog' => 'SSMARK-BLOG',
        'service' => 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 md:gap-6 gap-4',
    ];

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::forceCreate([
            'name' => 'Order Merchant', 'email' => 'sec-order-m@example.test',
            'password' => bcrypt('x'), 'type' => 'admin', 'created_by' => 1,
            'email_verified_at' => now(), 'language' => 'en',
        ]);

        $this->store = Store::create([
            'name' => 'Order Shop', 'email' => 'sec-order-s@example.test',
            'slug' => 'sec-order-shop', 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $permission = Permission::firstOrCreate(
            ['name' => 'Manage Themes'],
            ['display_name' => 'Manage Themes']
        );
        $role = Role::forceCreate([
            'name' => 'sec-order-role', 'display_name' => 'Ord', 'guard_name' => 'web',
            'created_by' => $this->merchant->creatorId(), 'store_id' => $this->store->id,
        ]);
        $role->givePermission($permission);
        $this->merchant->addRole($role);
        $this->merchant->flushCache();
        $this->merchant = $this->merchant->fresh();

        $this->markSections($this->store);
    }

    /**
     * Unique titles for the five sections stylique drives from a theme setting, plus the minimum
     * catalogue the other sections need to paint.
     *
     * THE CATEGORIES ARE NOT DECORATION. `collection` and `category` build their grids from
     * `$MainCategoryList`, so with none the sections render a container of zero height — measured
     * in a real Chromium as `collection has a painted height (0px)`, which the live harness reports
     * as a failure and which would otherwise be read as a layout defect this change caused.
     */
    private function markSections(Store $store): void
    {
        foreach ([
            'product_title' => 'SSMARK-PRODUCT',
            'category_title' => 'SSMARK-CATEGORY',
            'bestseller_title' => 'SSMARK-BESTSELLER',
            'testimonial_title' => 'SSMARK-TESTIMONIAL',
            'blog_title' => 'SSMARK-BLOG',
        ] as $name => $value) {
            ThemeCustomize::create([
                'store_id' => $store->id,
                'theme_id' => 'stylique',
                'name' => $name,
                'value' => $value,
            ]);
        }

        foreach (['Dresses', 'Shoes', 'Bags', 'Coats'] as $i => $name) {
            Category::forceCreate([
                'name' => $name,
                'slug' => $store->id . '-' . strtolower($name),
                'status' => 1,
                'store_id' => $store->id,
            ]);
        }
    }

    private function saveOrder(string $orders)
    {
        return $this->actingAs($this->merchant)
            ->post('/theme-customize/stylique/order/update', [
                'page_slug' => 'home',
                'orders' => $orders,
            ]);
    }

    private function storedOrdering(?int $storeId = null): ?string
    {
        return ThemeCustomizeOrder::withoutGlobalScopes()
            ->where('store_id', $storeId ?? $this->store->id)
            ->where('page_slug', 'home')
            ->value('orders');
    }

    /** The home page HTML a shopper gets. */
    private function shopHtml(?Store $store = null, ?string $capture = null): string
    {
        $store ??= $this->store;

        /*
         * THE BYTES THE LIVE HARNESS PHOTOGRAPHS, off unless asked for.
         *
         * `tests/Support/bin/check-storefront-section-order-live.cjs` runs this file with
         * `SS_HOME_HTML_DIR` set and opens what it writes in a real Chromium, because the
         * storefront loads Tailwind's RUNTIME JIT CDN (`themes/<theme>/assets/js/tailwind.js`) and
         * injects its rules at render time — so a section's spacing, its background band and its
         * contrast do not exist in any file a static test can read. The asset base is overridden
         * in the same breath because every stylesheet and script URL in all four copies of
         * `front_end/layouts/app.blade.php` is built with `asset()`, and the harness serves
         * `public/` and `themes/` from a local port.
         *
         * `URL::useAssetOrigin()`, NOT `config(['app.url' => ...])`, AND THE DIFFERENCE WAS MEASURED.
         * `UrlGenerator::asset()` formats against `$this->assetRoot` or else the CURRENT REQUEST's
         * root — it does not read `app.url` at call time — and `app.asset_url` is consumed once at
         * boot. Setting the config produced `src="http://localhost/themes/..."` in the dumped
         * bytes, so the 477 KB runtime Tailwind bundle 404'd, the captured page rendered unstyled,
         * and the harness reported sixteen headings at 1.00:1 against `rgba(0, 0, 0, 0)` — a wall
         * of contrast failures that were entirely an artefact of the capture.
         */
        $base = getenv('SS_HOME_ASSET_BASE');

        if (is_string($base) && $base !== '') {
            /* `useAssetOrigin`, not `useAssetRoot`: the latter does not exist on this Laravel's
               UrlGenerator and answers `BadMethodCallException` through Macroable. */
            \Illuminate\Support\Facades\URL::useAssetOrigin($base);
        }

        $response = $this->get('/' . $store->slug . '/home');
        $response->assertStatus(200);
        $html = $response->getContent();

        $dir = getenv('SS_HOME_HTML_DIR');

        if ($capture !== null && is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents(rtrim($dir, '/') . '/home-' . $capture . '.html', $html);
        }

        return $html;
    }

    /**
     * The blocks of MARKERS, in the order their marker appears in the HTML.
     *
     * A marker that does not appear at all is reported rather than skipped: a section that stopped
     * rendering would otherwise shorten the list and let an order assertion pass on a subset.
     *
     * @return list<string>
     */
    private function renderedOrder(string $html): array
    {
        $positions = [];

        foreach (self::MARKERS as $block => $marker) {
            $at = strpos($html, $marker);

            $this->assertNotFalse($at,
                "stylique's `{$block}` section did not render at all (marker `{$marker}` absent), "
                . 'so every order assertion in this file would be about a subset of the page.');

            $this->assertSame(1, substr_count($html, $marker),
                "the marker for stylique's `{$block}` section (`{$marker}`) appears "
                . substr_count($html, $marker) . ' times in the rendered page. A marker that is '
                . 'not unique pins its own position and the order assertions stop meaning anything.');

            $positions[$block] = $at;
        }

        asort($positions);

        return array_keys($positions);
    }

    /* ===================================================================== premises */

    /**
     * PREMISE 1 — the fixture really renders stylique.
     *
     * `config('theme.active')` is `stylique`, so a store with an empty `theme_id` renders
     * stylique's views anyway and a test that assumed it was exercising the store's own choice
     * would prove nothing. This asserts the stored choice AND a marker only stylique emits.
     */
    #[Test]
    public function test_the_fixture_store_really_renders_stylique(): void
    {
        $row = Store::withoutGlobalScopes()->find($this->store->id);

        $this->assertSame('stylique', (string) $row->theme_id,
            'The fixture store does not carry stylique, so the markers below name another theme.');

        $this->assertStringContainsString('themes/stylique/assets', $this->shopHtml(),
            'The rendered page does not load stylique assets, so it is not stylique that rendered.');
    }

    /**
     * PREMISE 2 — THE WRITE HALF, through the merchant's real form.
     *
     * This is the half the census found working, and it is asserted here so that a later failure
     * cannot be read as "the save broke" when it is the render.
     */
    #[Test]
    public function test_the_merchant_form_really_stores_an_ordering(): void
    {
        $this->assertNull($this->storedOrdering(), 'PREMISE: the fixture starts with no ordering.');

        $this->saveOrder('category,slider,product,bestseller,testimonial');

        $this->assertSame('category,slider,product,bestseller,testimonial', $this->storedOrdering(),
            'The merchant form did not store the ordering, so nothing below is about rendering.');
    }

    /**
     * PREMISE 3 — the default page order, with nothing saved.
     *
     * If this list ever changes, `themes/stylique/views/main_file.blade.php` was reordered and the
     * expectations below need re-deriving rather than patching.
     */
    #[Test]
    public function test_with_nothing_saved_the_page_renders_the_themes_own_order(): void
    {
        $this->assertSame(
            ['slider', 'logo', 'collection', 'product', 'category', 'bestseller', 'testimonial', 'blog', 'service'],
            $this->renderedOrder($this->shopHtml()),
            'stylique no longer renders its documented default section order.'
        );
    }

    /* ============================================================= the requirement */

    /**
     * THE REQUIREMENT. A merchant reorders the home page's sections and a shopper sees that order.
     *
     * The saved string is the five tokens stylique declares that name a real top-level block, in
     * reverse, PLUS the four it declares that name no block (`top_category`, `more_offer`,
     * `article`, `logos`) — because that is what the editor's hidden input really posts, and a
     * renderer that choked on an inert token would be useless.
     *
     * The expectation is derived from the slot rule, not guessed: the five named blocks occupy
     * slots 0, 3, 4, 5 and 7 of the ten-block default sequence (slider, logo, collection, product,
     * category, bestseller, product_list, testimonial, blog, service), so those five slots take
     * testimonial, bestseller, category, product, slider in that order and the four blocks the
     * ordering does not name — logo, collection, blog, service — do not move at all.
     */
    #[Test]
    public function test_a_saved_ordering_changes_what_a_shopper_sees(): void
    {
        $this->renderedOrder($this->shopHtml(null, 'en-default'));

        $this->saveOrder('testimonial,bestseller,category,product,slider,top_category,more_offer,article,logos');

        $this->assertSame(
            'testimonial,bestseller,category,product,slider,top_category,more_offer,article,logos',
            $this->storedOrdering(),
            'PREMISE: the ordering under test was not stored.');

        $this->assertSame(
            ['testimonial', 'logo', 'collection', 'bestseller', 'category', 'product', 'slider', 'blog', 'service'],
            $this->renderedOrder($this->shopHtml(null, 'en-reordered')),
            'The storefront ignored the merchant\'s saved section ordering. This is GAP-099: the '
            . 'order saves, the editor redisplays it, and the shop renders its hard-coded sequence.'
        );
    }

    /**
     * THE SAME ORDERING IN ARABIC, ON AN RTL PAGE.
     *
     * Required because a storefront change made in one direction only is how this project has
     * shipped RTL defects before, and because the mechanism is a DOM reordering: the reading order
     * and the visual order must both follow the merchant's choice, and in Arabic the inline axis is
     * mirrored while the block axis is not. Sections stack down the block axis, so the expected
     * sequence is IDENTICAL to the English one — and that is the assertion, not an assumption.
     *
     * The page is driven by the store's own `default_language`, which is what
     * `ViewServiceProvider` reads when no `LANGUAGE` cookie is set, so this is the Arabic page an
     * Arabic merchant's shoppers actually get rather than one forced by a test helper.
     */
    #[Test]
    public function test_the_saved_ordering_applies_on_the_arabic_page_too(): void
    {
        $merchant = User::forceCreate([
            'name' => 'Ar Merchant', 'email' => 'sec-order-ar-m@example.test',
            'password' => bcrypt('x'), 'type' => 'admin', 'created_by' => 1,
            'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'name' => 'Ar Shop', 'email' => 'sec-order-ar-s@example.test',
            'slug' => 'sec-order-ar-shop', 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'ar',
        ]);
        $merchant->current_store = $store->id;
        $merchant->save();

        $permission = Permission::firstOrCreate(
            ['name' => 'Manage Themes'],
            ['display_name' => 'Manage Themes']
        );
        $role = Role::forceCreate([
            'name' => 'sec-order-ar-role', 'display_name' => 'Ar', 'guard_name' => 'web',
            'created_by' => $merchant->creatorId(), 'store_id' => $store->id,
        ]);
        $role->givePermission($permission);
        $merchant->addRole($role);
        $merchant->flushCache();
        $merchant = $merchant->fresh();

        $this->markSections($store);

        $before = $this->shopHtml($store, 'ar-default');

        $this->assertStringContainsString('dir="rtl"', $before,
            'PREMISE: the Arabic fixture did not render an RTL document, so nothing below is about '
            . 'the Arabic page.');
        $this->assertSame(
            ['slider', 'logo', 'collection', 'product', 'category', 'bestseller', 'testimonial', 'blog', 'service'],
            $this->renderedOrder($before),
            'the Arabic page does not render stylique\'s default order.');

        $this->actingAs($merchant)->post('/theme-customize/stylique/order/update', [
            'page_slug' => 'home',
            'orders' => 'testimonial,bestseller,category,product,slider,top_category,more_offer,article,logos',
        ]);

        $after = $this->shopHtml($store, 'ar-reordered');

        $this->assertStringContainsString('dir="rtl"', $after,
            'the reordered Arabic page is no longer an RTL document.');
        $this->assertSame(
            ['testimonial', 'logo', 'collection', 'bestseller', 'category', 'product', 'slider', 'blog', 'service'],
            $this->renderedOrder($after),
            'the Arabic page ignored the merchant\'s saved section ordering, or ordered it '
            . 'differently from the English page. Sections stack on the block axis, which RTL does '
            . 'not mirror, so the two must agree.');
    }

    /**
     * SAVING THE DEFAULT MUST CHANGE NOTHING. The permutation has to be the identity for the order
     * a page already renders in, or switching this on relayouts every store that has ever touched
     * the screen.
     */
    #[Test]
    public function test_saving_the_pages_current_order_leaves_the_page_identical(): void
    {
        $before = $this->renderedOrder($this->shopHtml());

        $this->saveOrder('slider,product,category,bestseller,testimonial');

        $this->assertSame($before, $this->renderedOrder($this->shopHtml()),
            'Saving the order the page already renders in moved something.');
    }

    /* ======================================================== isolation and keying */

    /**
     * ANOTHER STORE'S ORDERING MUST NOT REACH THIS SHOP. The lookup drops the global scope — which
     * is necessary, because a storefront must answer for the route's store and not for `auth()` —
     * so its own `store_id` predicate is the whole tenant boundary and is worth driving.
     */
    #[Test]
    public function test_a_second_stores_ordering_does_not_reach_this_shop(): void
    {
        $other = Store::create([
            'name' => 'Other Shop', 'email' => 'sec-order-o@example.test',
            'slug' => 'sec-order-other', 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->markSections($other);

        ThemeCustomizeOrder::withoutGlobalScopes()->create([
            'store_id' => $other->id, 'theme_id' => 'stylique', 'page_slug' => 'home',
            'orders' => 'testimonial,bestseller,category,product,slider',
        ]);

        $this->assertSame('testimonial,bestseller,category,product,slider',
            $this->storedOrdering($other->id), 'PREMISE: the other store has no ordering row.');
        $this->assertNull($this->storedOrdering(),
            'PREMISE: the store under test must have no ordering of its own.');

        $this->assertSame(
            ['slider', 'logo', 'collection', 'product', 'category', 'bestseller', 'testimonial', 'blog', 'service'],
            $this->renderedOrder($this->shopHtml()),
            'A second store\'s saved ordering reordered this shop\'s home page.');

        $this->assertSame(
            ['testimonial', 'logo', 'collection', 'bestseller', 'category', 'product', 'slider', 'blog', 'service'],
            $this->renderedOrder($this->shopHtml($other)),
            'The other store did not get its own ordering, so the predicate is filtering too much.');
    }

    /**
     * AN ORDERING SAVED UNDER A DIFFERENT THEME MUST NOT APPLY. The column exists for this: a
     * merchant who reordered greentic's home page must not have reordered stylique's.
     */
    #[Test]
    public function test_an_ordering_saved_for_another_theme_does_not_apply(): void
    {
        ThemeCustomizeOrder::withoutGlobalScopes()->create([
            'store_id' => $this->store->id, 'theme_id' => 'techzonix', 'page_slug' => 'home',
            'orders' => 'testimonial,bestseller,category,product,slider',
        ]);

        $this->assertSame(
            ['slider', 'logo', 'collection', 'product', 'category', 'bestseller', 'testimonial', 'blog', 'service'],
            $this->renderedOrder($this->shopHtml()),
            'A techzonix ordering reordered a stylique page.');
    }

    /**
     * A LEGACY ROW APPLIES. `theme_id` is nullable and NULL means *"applies to whichever theme has
     * no row of its own"* — rows written before the column existed. Dropping them would silently
     * discard an ordering a merchant really chose.
     */
    #[Test]
    public function test_a_legacy_row_with_no_theme_still_applies(): void
    {
        ThemeCustomizeOrder::withoutGlobalScopes()->create([
            'store_id' => $this->store->id, 'theme_id' => null, 'page_slug' => 'home',
            'orders' => 'testimonial,bestseller,category,product,slider',
        ]);

        $this->assertSame(
            ['testimonial', 'logo', 'collection', 'bestseller', 'category', 'product', 'slider', 'blog', 'service'],
            $this->renderedOrder($this->shopHtml()),
            'A legacy `theme_id IS NULL` ordering was ignored.');
    }

    /**
     * AN ORDERING FOR ANOTHER PAGE MUST NOT TOUCH THE HOME PAGE. The row is keyed on `page_slug`
     * and the home renderer asks for `home`.
     */
    #[Test]
    public function test_an_ordering_for_another_page_does_not_apply(): void
    {
        ThemeCustomizeOrder::withoutGlobalScopes()->create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique', 'page_slug' => 'blog',
            'orders' => 'testimonial,bestseller,category,product,slider',
        ]);

        $this->assertSame(
            ['slider', 'logo', 'collection', 'product', 'category', 'bestseller', 'testimonial', 'blog', 'service'],
            $this->renderedOrder($this->shopHtml()),
            'An ordering stored against the blog page reordered the home page.');
    }

    /* ============================================ techzonix, the second orderable theme */

    /**
     * THE SECOND THEME THAT DECLARES AN ORDERABLE PAGE, END TO END.
     *
     * `is_order` is set on `home` in stylique's and techzonix's `pages.json` and on nothing in
     * greentic's — measured — so these two are the whole of the shipped surface, and a mechanism
     * proved on one theme only would be proved on half of it. A layout-level storefront change made
     * in fewer than all four copies silently does nothing for the other themes, and this file is
     * the guard against the same mistake one level down.
     *
     * The markers are techzonix's own section container classes, each occurring exactly once in
     * `themes/techzonix/views/main_file.blade.php` and in the two gated includes
     * `front_end/common/{sale,offer}.blade.php`. None is a title setting, because techzonix prints
     * four of its titles TWICE — once as text and once inside `id="{{ ... }}_preview"` — which
     * would have pinned those sections' positions.
     *
     * THE EXPECTATION IS DERIVED, NOT GUESSED. techzonix's twelve blocks are slider, category,
     * collections, logo, product, more_offer, offer, service, product_list, testimonial, article,
     * subscribe. Of the nine tokens its `pages.json` declares, SIX name one of those blocks —
     * slider, category, product, more_offer, article, testimonial — and they sit at slots 0, 1, 4,
     * 5, 9 and 10. `top_category`, `bestseller` and `logos` name no block of this theme and are
     * inert; `collections` and `logo` are real blocks the declaration never mentions, so they
     * cannot move at all. That is GAP-099's remaining half and it is recorded in the evidence
     * document, not papered over here.
     */
    #[Test]
    public function test_a_saved_ordering_changes_what_a_shopper_sees_on_techzonix(): void
    {
        $merchant = User::forceCreate([
            'name' => 'Tz Merchant', 'email' => 'sec-order-tz-m@example.test',
            'password' => bcrypt('x'), 'type' => 'admin', 'created_by' => 1,
            'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'name' => 'Tz Shop', 'email' => 'sec-order-tz-s@example.test',
            'slug' => 'sec-order-tz-shop', 'theme_id' => 'techzonix',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $merchant->current_store = $store->id;
        $merchant->save();

        $permission = Permission::firstOrCreate(
            ['name' => 'Manage Themes'],
            ['display_name' => 'Manage Themes']
        );
        $role = Role::forceCreate([
            'name' => 'sec-order-tz-role', 'display_name' => 'Tz', 'guard_name' => 'web',
            'created_by' => $merchant->creatorId(), 'store_id' => $store->id,
        ]);
        $role->givePermission($permission);
        $merchant->addRole($role);
        $merchant->flushCache();
        $merchant = $merchant->fresh();

        $markers = [
            'slider' => 'home-banner-sec',
            'category' => 'grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4',
            'collections' => 'grid lg:grid-cols-3 sm:grid-cols-2 grid-cols-1 gap-4',
            'logo' => 'swiper logo-swiper',
            'product' => 'swiper product-swiper',
            'more_offer' => 'relative overflow-hidden rounded-2xl lg:py-20 lg:px-10 sm:p-6 px-4 py-8',
            'offer' => 'swiper offer-swiper',
            'testimonial' => 'swiper testimonial-swiper',
            'article' => 'swiper blog-swiper',
        ];

        $order = function () use ($store, $markers): array {
            $response = $this->get('/' . $store->slug . '/home');
            $response->assertStatus(200);
            $html = $response->getContent();
            $positions = [];

            foreach ($markers as $block => $marker) {
                $this->assertSame(1, substr_count($html, $marker),
                    "techzonix's `{$block}` marker appears " . substr_count($html, $marker)
                    . ' times; it must appear exactly once for the order to mean anything.');
                $positions[$block] = strpos($html, $marker);
            }

            asort($positions);

            return array_keys($positions);
        };

        $this->assertSame(
            ['slider', 'category', 'collections', 'logo', 'product', 'more_offer', 'offer', 'testimonial', 'article'],
            $order(),
            'techzonix no longer renders its documented default section order.');

        $this->actingAs($merchant)->post('/theme-customize/techzonix/order/update', [
            'page_slug' => 'home',
            'orders' => 'article,testimonial,more_offer,product,category,slider,top_category,bestseller,logos',
        ]);

        $this->assertSame(
            'article,testimonial,more_offer,product,category,slider,top_category,bestseller,logos',
            ThemeCustomizeOrder::withoutGlobalScopes()
                ->where('store_id', $store->id)->where('page_slug', 'home')->value('orders'),
            'PREMISE: the techzonix ordering was not stored.');

        $this->assertSame(
            ['article', 'testimonial', 'collections', 'logo', 'more_offer', 'product', 'offer', 'category', 'slider'],
            $order(),
            'techzonix ignored the merchant\'s saved section ordering.');
    }

    /**
     * A VISITOR WHO ALSO HOLDS A MERCHANT PANEL SESSION SEES THE SHOP'S OWN ORDER.
     *
     * WHAT THIS DOES AND DOES NOT PROVE, stated because the first version of the note above it
     * claimed more. It asserts that a populated `web`-guard principal — a merchant with a live
     * panel session loading ANOTHER merchant's shop, which the session cookie path '/' makes an
     * ordinary occurrence — gets the rendered store's own ordering. That is a real regression
     * guard: `getCurrentStore()` and `auth()->user()` both answer for that other tenant here, so a
     * future rewrite that took the store from either would fail this test.
     *
     * IT DOES NOT PROVE THAT DROPPING THE GLOBAL SCOPE IS NECESSARY, and a mutation says so:
     * putting `StoreScope` back left this test green. `StoreScope::resolveTenant()` has a
     * storefront branch that admits the slug-resolved store alongside the owned ones — measured
     * from inside this very request as `store_ids=[1,2]` — so the scope already answers correctly
     * on a storefront route. `StorefrontSectionOrder`'s docblock carries the measurement and says
     * plainly that the explicit predicate is defence in depth rather than a fix.
     *
     * Driven with a second merchant who owns nothing here, so `getCurrentStore()` and the owned-
     * store set both point away from the store being rendered.
     */
    #[Test]
    public function test_a_visitor_with_a_panel_session_for_another_tenant_sees_this_shops_order(): void
    {
        $this->saveOrder('testimonial,bestseller,category,product,slider');

        $intruder = User::forceCreate([
            'name' => 'Other Merchant', 'email' => 'sec-order-x@example.test',
            'password' => bcrypt('x'), 'type' => 'admin', 'created_by' => 1,
            'email_verified_at' => now(),
        ]);
        $foreign = Store::create([
            'name' => 'Foreign Shop', 'email' => 'sec-order-f@example.test',
            'slug' => 'sec-order-foreign', 'theme_id' => 'stylique',
            'created_by' => $intruder->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $intruder->current_store = $foreign->id;
        $intruder->save();

        /*
         * THE INTRUDER'S OWN STORE GETS A DISTINCT ORDERING, AND WITHOUT IT THIS TEST COULD NOT
         * DISCRIMINATE. An independent review found that `sec-order-foreign` had no
         * `theme_customize_orders` row at all, so a lookup that wrongly read the SESSION store
         * would have found nothing, fallen through to the theme's own order — and failed the
         * assertion below for the right reason by luck, while being unable to tell that case
         * apart from "reads the route store, but falls back to the session store when the route
         * store has no row".
         *
         * With a row here, the two are distinguishable: a session-reading implementation renders
         * THIS order, a route-reading one renders the victim's. The ordering is deliberately a
         * different permutation from both the victim's and the theme default.
         */
        ThemeCustomizeOrder::withoutGlobalScopes()->create([
            'store_id' => $foreign->id,
            'theme_id' => 'stylique',
            'page_slug' => 'home',
            'orders' => 'product,slider,category,bestseller,testimonial',
        ]);

        $response = $this->actingAs($intruder)->get('/' . $this->store->slug . '/home');
        $response->assertStatus(200);

        $this->assertSame(
            ['testimonial', 'logo', 'collection', 'bestseller', 'category', 'product', 'slider', 'blog', 'service'],
            $this->renderedOrder($response->getContent()),
            'A visitor holding a panel session for a DIFFERENT tenant did not see THIS shop\'s '
            . 'order. The ordering lookup is answering for the `web` guard instead of for the '
            . 'store in the route — and the intruder\'s own store has its own distinct ordering, '
            . 'so a session-reading implementation renders that one and is caught here rather '
            . 'than falling through to the theme default and passing by accident.');
    }
}
