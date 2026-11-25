<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * PHASE 04 P4-1 / GAP-128 — THE BILINGUAL CART + CHECKOUT HARNESS.
 * =================================================================================================
 *
 * THE ACCEPTANCE CLAUSE THIS FILE EXISTS FOR, verbatim, from
 * `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md:212-214`:
 *
 *     "Acceptance: a test that fails against the Arabic-on-English message and passes after the
 *      fix (done); a storefront harness driving cart and checkout in **both** languages,
 *      asserting the message language matches the page."
 *
 * The first half is `tests/Feature/Commerce/StorefrontLocaleFollowsTheStoreTest` — seven cases that
 * drive JSON routes and assert `app()->getLocale()`. It proves WHICH LOCALE THE MIDDLEWARE
 * RESOLVED. It never renders a page, never looks at `dir`, `lang` or a single user-visible string,
 * and never touches the cart or the checkout. That is the half GAP-128 is open for, and it is what
 * is below.
 *
 * -------------------------------------------------------------------------------------------------
 * WHICH SURFACES A SHOPPER ACTUALLY REACHES, AND WHY THESE.
 *
 * `routes/customer.php:114-115` are the two page routes — `GET {storeSlug}/cart` and
 * `ANY {storeSlug}/checkout` — and `HomeController::cart_page`/`checkout` return
 * `front_end.pages.cart` and `front_end.pages.checkout`. There is ONE copy of each of those page
 * views, in `resources/views`; the three themes ship layouts, partials and components only
 * (measured: a `find` over every theme's `views` directory lists no `pages/cart` and no
 * `pages/checkout` in any of the three).
 * So the page BODY is theme-independent and the LAYOUT is not — `front_end/layouts/app.blade.php`
 * exists four times, once per theme plus the `resources/views` fallback, and line 2 of each is
 * where `lang` and `dir` are printed. CLAUDE.md records that a layout-level storefront change must
 * be made four times.
 *
 * Therefore: the direction-and-language cases below drive ALL FOUR configurations — the three real
 * themes and a store whose `theme_id` is the empty string, which `ActiveTheme` resolves to
 * `config('theme.active')` (`stylique`) — and the content cases drive `stylique`, which is both the
 * configured default and what a themeless store renders.
 *
 * THE FOURTH LAYOUT IS THE ONE THAT MATTERS AND IT IS THE ONE A SHOPPER NEVER SEES.
 * `resources/views/front_end/layouts/app.blade.php:2` prints `lang` and NO `dir` at all. A premise
 * case below pins both halves: that the fallback really has no `dir`, and that no storefront
 * request reaches it, so the pages under test always carry a direction.
 *
 * THE CART PAGE'S BODY IS EMPTY, which is the single most important fact for a harness here.
 * `front_end/pages/cart.blade.php` is 27 lines and its `cart-page-section` is a bare `<section>`;
 * `public/assets/js/front-theme.js:223` fills it from `POST {storeSlug}/cart-list-sidebar`. So a
 * harness that only GETs `/cart` measures chrome and asserts nothing about a basket. Every cart
 * assertion below therefore drives that POST as well and reads the three fragments it renders —
 * `html` (the slide-out panel), `checkout_html` (the cart page's own table and totals) and
 * `checkout_html_2` (the checkout page's order summary) — which together are what a shopper reads.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE ESTABLISHED THAT WAS NOT KNOWN, AND IT IS A DEFECT.
 *
 * On an Arabic storefront the cart's table headers are Arabic and the totals and buttons beside
 * them are ENGLISH — measured, full basket, `stylique`:
 *
 *     المنتج كمية السعر مجموع الجزئي <product> $1,234.50 $2,469.00
 *     Continue Shopping  Clear Cart  Order Summary  Subtotal $2,469.00  Total $2,469.00
 *     Proceed to Checkout
 *
 * and the checkout's order summary ends `... إجمالي $2,469.00  Place Order`.
 *
 * THE MECHANISM IS A THEME SETTING SHADOWING THE TRANSLATION, not a missing key. Nine sites are
 * written `{{ $themeSettings['cart_summary'] ?? __('Order Summary') }}`, and every theme's
 * `default_data/settings.php` ships a value for the setting — so `??` never fires and `__()` is
 * unreachable for every real store. `resources/lang/ar.json` HAS all nine translations; nothing
 * prints them. The same labels in `cart-list-sidebar.blade.php`, which has no such shadow, DO come
 * out Arabic — so the contrast is inside one response.
 *
 * It is a defect and not merchant choice because the shipped default is English for every store in
 * every language, and the setting is ONE string on a storefront that serves two languages: an
 * Arabic merchant who edits it breaks the English view of the same shop.
 *
 * The fix is a Blade change and this agent was instructed not to make one, so
 * `test_the_chrome_a_theme_setting_shadows_is_shipped_english_in_both_languages` PINS THE DEFECT
 * with its nine keys named. **When the views are fixed that case must go red** — it is a
 * characterisation test, and its message says so. Do not weaken it; invert it.
 *
 * -------------------------------------------------------------------------------------------------
 * MONEY AND NUMBERS ARE NOT LOCALISED, AND THAT IS MEASURED RATHER THAN ASSUMED.
 *
 * The brief asked for locale-formatted money "if the product does that at all". It does not.
 * `currency_format_with_sym()` (`app/Helper/helper.php:1690`) and `SetNumber()`/`SetNumberFormat()`
 * (`:280`, `:290`) are `number_format()` with separators read from the store's own SETTINGS, and no
 * call site passes a locale; the only `toLocaleString()` in the storefront is an unrelated counter
 * on the about page. So `$1,234.50` is byte-identical in Arabic and English, in ASCII digits.
 * `test_money_renders_identically_in_both_languages_because_nothing_localises_it` pins exactly
 * that, in both directions, so a future locale-aware formatter reddens it deliberately instead of
 * arriving unnoticed.
 *
 * -------------------------------------------------------------------------------------------------
 * HOW THE "IS IT TRANSLATED" QUESTION IS ASKED, AND THE FOUR TRAPS IT WALKS PAST.
 *
 * Never by reading a language file. CLAUDE.md: a key present in `en.json` and absent from `ar.json`
 * falls back to English and no source census can see the difference. So every key is rendered
 * through `trans($key, [], $language)` and the two renderings are compared against the real
 * response body: the page must contain its OWN language's string and must not contain the other's.
 *
 *   1. HTML COMMENTS. `checkout.blade.php:42` carries `<!-- First Name -->`, so the literal
 *      "First Name" is on the Arabic page whatever the translator does. This is CLAUDE.md's
 *      "a census that reads comments" trap in its HTML form, and
 *      `test_the_readable_text_extraction_walks_past_an_html_comment` proves the extraction sees
 *      past it rather than asserting that it does.
 *   2. DEAD ATTRIBUTES. The cart row carries `data-label="Price"`, and no storefront stylesheet
 *      renders `attr(data-label)` (measured: the only `content: attr(data-label)` rules are the
 *      admin-side quill editor's). A raw-body search therefore reports English "Price" on a fully
 *      translated Arabic table. Readable text is text nodes plus the four attributes a shopper can
 *      actually read — `placeholder`, `title`, `alt`, `aria-label`.
 *   3. INLINE SCRIPTS. CLAUDE.md: `strip_tags()` on a merchant page ate 61KB because `i < n` reads
 *      as a tag. Scripts and styles are removed as whole elements BEFORE any tag stripping.
 *   4. BLADE COMMENTS IN THE VIEW SOURCE. `checkout-cart-list.blade.php` holds a commented-out
 *      English terms-and-conditions block and `cart.blade.php` a `{{-- GAP-095 --}}` note. The key
 *      census strips `{{-- --}}` before reading, so a key mentioned in prose is not counted.
 *
 * AND THE CENSUS IS A RATCHET IN BOTH DIRECTIONS. The declared key sets are compared with what the
 * views really contain, so adding a label to the checkout, or moving one behind a theme setting,
 * fails here and gets read. Every key the fixture reaches must also DISCRIMINATE — its Arabic and
 * English renderings must differ — which is how a key added to `en.json` and forgotten in `ar.json`
 * is caught at the only place it is visible.
 *
 * -------------------------------------------------------------------------------------------------
 * THE SHOPPER IS SIGNED IN WITH `asAShopper()`, NEVER `actingAs($c, 'customers')`.
 *
 * CLAUDE.md and `PlatformUploaderDoorsTest::asAShopper()`: `actingAs($c, 'customers')` also calls
 * `shouldUse('customers')`, so `auth()->user()` — the DEFAULT guard — becomes the Customer. That
 * matters here and not only in general: `ThemeLanguage::handle()` step 2 is
 * `$user = \Auth::user(); $currantLang = $user->language;` on the default guard, and
 * `ViewServiceProvider` and `getCurrentStore()` read it too. In production a shopper has nobody in
 * `web`, so step 2 is skipped and the store's own language decides. A test on the `customers`
 * default guard puts a Customer into step 2 instead, which is a locale resolved from the wrong
 * principal — the exact class of defect this file exists to measure.
 *
 * THE FIXTURE ALSO KEEPS THE §3 DEFECT REACHABLE. The lowest-id type-`admin` user is given a
 * language that is NEITHER store's (`fr`), exactly as `StorefrontLocaleFollowsTheStoreTest` does,
 * so reverting the `ThemeLanguage` fix reddens the message cases here as well as that file's.
 *
 * NO WORKDO MODULE IS LOADED, AND NONE IS NEEDED. CLAUDE.md: no module loads without an
 * `add_on_managers` row. Every module branch in `CartController` and `Cart::cart_list_cookie`
 * (`PreOrder`, `WholesaleProduct`, `SizeGuideline`, `SkipCart`) is guarded by `module_is_active()`
 * with a first-party `else`, so the paths driven here are the host application's own.
 * `test_no_workdo_module_is_loaded_so_these_are_the_first_party_cart_paths` asserts that premise
 * rather than leaving it implicit, because a module silently loading would change which code the
 * rest of the file measures.
 */
class BilingualCartCheckoutHarnessTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** `dir` for each language, as `front_end/layouts/app.blade.php:2` computes it. */
    private const DIRECTION = ['en' => 'ltr', 'ar' => 'rtl'];

    /**
     * The nine cart/checkout labels a theme setting shadows, key => the setting that wins.
     *
     * Derived from the views at runtime by `shadowedKeysIn()` and compared against this list, so a
     * tenth shadow — or a shadow removed — fails and is read rather than silently changing what the
     * translation cases cover.
     */
    private const SHADOWED_BY_A_THEME_SETTING = [
        'front_end/pages/cart.blade.php' => ['Cart Details' => 'cart_banner_title'],
        'front_end/pages/checkout.blade.php' => ['Checkout Page' => 'checkout_banner_title'],
        'front_end/pages/cart-list.blade.php' => [
            'Clear Cart' => 'cart_clear_button_text',
            'Continue Shopping' => 'cart_product_button_text',
            'Order Summary' => 'cart_summary',
            'Proceed to Checkout' => 'cart_checkout',
            'Subtotal' => 'cart_subtotal',
            'Tax' => 'cart_tax',
            'Total' => 'cart_total',
        ],
        'front_end/pages/cart-list-sidebar.blade.php' => [],
        'front_end/pages/checkout-cart-list.blade.php' => ['Complete Order' => 'checkout_button'],
    ];

    /**
     * Keys this fixture does not put on the page, each with the condition that keeps it off.
     *
     * Named rather than counted: a key that silently stops rendering is a label a shopper lost, and
     * it would otherwise be indistinguishable from one this fixture was never going to reach.
     */
    private const NOT_REACHED_BY_THIS_FIXTURE = [
        // `checkout.blade.php:195,208,211-212` — inside `@if($payment['name'] == 'whatsapp')` and
        // `@if($payment['name'] == 'Paiementpro')`. Neither gateway is configured on this fixture.
        'Enter Your Phone Number' => 'only inside the whatsapp and Paiementpro payment blocks',
        'Example' => 'only inside the Paiementpro channel hint',
        // `checkout.blade.php:235` — `@if (isset($settings['additional_notes']) && ... == 'on')`.
        'Additional Notes' => 'behind the additional_notes store setting',
    ];

    /** Deliberately empty. A label that reads the same in both languages must be declared here, with its reason. */
    private const IDENTICAL_IN_BOTH_LANGUAGES_BY_DESIGN = [];

    private const BASKET_UNIT_PRICE = 1234.50;

    private const BASKET_QTY = 2;

    // -------------------------------------------------------------------------------------------
    // FIXTURE
    // -------------------------------------------------------------------------------------------

    /**
     * THE TRAP THE §3 DEFECT NEEDS, built on purpose and before any store.
     *
     * `ThemeLanguage`'s old final fallback was `User::where('type','admin')->first()->language`,
     * cached as `admin_details` — the lowest-id type-`admin` row in the whole shared database. This
     * user is that row and speaks neither language under test, so a revert cannot accidentally
     * agree with either store and every message assertion below fails by name.
     */
    protected function setUp(): void
    {
        parent::setUp();

        User::forceCreate([
            'name' => 'Lowest Id Admin', 'email' => 'lowest-id-admin@gap128.test',
            'password' => bcrypt('not-a-real-password'), 'type' => 'admin', 'created_by' => 1,
            'email_verified_at' => now(), 'language' => 'fr',
        ]);

        Cache::forget('admin_details');
        Cache::forget('super_admin_details');
    }

    /**
     * A merchant, a plan with shipping ON, and a store whose `default_language` is the one asked
     * for. Shipping is on so that the checkout summary's `Select Shipping` and `Shipping` labels
     * render at all — they sit behind `@if($plan->shipping_method == 'on')`.
     */
    private function storeSpeaking(string $language, string $theme = 'stylique'): Store
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, [
            'default_language' => $language,
            'theme_id' => $theme,
        ]);

        $merchant->current_store = $store->id;
        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'gap128-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'on',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->save();

        /* CLAUDE.md: several handlers read the platform admin through a one-hour cache, so a
           fixture written after a request would otherwise be invisible to the next one. */
        Cache::forget('admin_details');
        Cache::forget('super_admin_details');

        return $store;
    }

    private function productIn(Store $store): Product
    {
        return Product::forceCreate([
            'name' => 'gap128-' . $this->uniqueFixtureRef(),
            'slug' => 'gap128-' . $this->uniqueFixtureRef(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => self::BASKET_UNIT_PRICE, 'sale_price' => self::BASKET_UNIT_PRICE,
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** A guest basket identifier of the shape every `carts.cookie_session_id` row has. */
    private function basketIdentifier(): string
    {
        return substr(str_pad('gap128' . $this->uniqueFixtureRef() . str_repeat('x', 40), 40, 'x'), 0, 40);
    }

    private function aGuestBasketIn(Store $store): string
    {
        $identifier = $this->basketIdentifier();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $this->productIn($store)->id, 'variant_id' => 0,
            'qty' => self::BASKET_QTY, 'price' => self::BASKET_UNIT_PRICE,
            'store_id' => $store->id, 'cookie_session_id' => $identifier,
        ]);

        return $identifier;
    }

    private function aCustomerOf(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Gap', 'last_name' => 'Shopper',
            'email' => 'gap128-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '0900' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * A signed-in shopper as production has one: the customer is in the `customers` guard and the
     * DEFAULT guard is `web` with nobody in it. See the file docblock for why this is not
     * `actingAs($customer, 'customers')`.
     */
    private function asAShopper(Customer $customer): self
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    // -------------------------------------------------------------------------------------------
    // READING A RESPONSE THE WAY A SHOPPER DOES
    // -------------------------------------------------------------------------------------------

    /**
     * The text a shopper can actually read: text nodes, plus the four attributes that are shown.
     *
     * Comments, scripts and styles are removed as whole elements first — see traps 1 to 3 in the
     * file docblock. Tag stripping happens only after the scripts are gone, so a `<` inside
     * JavaScript cannot eat the page the way CLAUDE.md records `strip_tags()` doing.
     */
    private function readableText(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', ' ', $html);
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html);

        $shown = [];
        foreach (['placeholder', 'title', 'alt', 'aria-label'] as $attribute) {
            if (preg_match_all('/\b' . $attribute . '\s*=\s*"([^"]*)"/i', $html, $matches)) {
                $shown = array_merge($shown, $matches[1]);
            }
        }

        $text = preg_replace('/<[^>]*>/', "\n", $html) . "\n" . implode("\n", $shown);

        return preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The markup inside the cart page's `.cart-page-section`, which `front-theme.js` replaces.
     *
     * The element is a `<section>` with no nested section in the shipped view, so the first
     * `</section>` after it closes it. Asserted rather than assumed: if the view gains a nested
     * section this returns too little and the premise case would pass for the wrong reason.
     */
    private function cartPageSectionOf(string $html): string
    {
        $start = strpos($html, 'cart-page-section');
        $this->assertNotFalse($start,
            'the rendered cart page has no .cart-page-section element, so front-theme.js has '
            . 'nowhere to paint the basket and the cart page cannot work at all');

        $open = strpos($html, '>', $start);
        $close = strpos($html, '</section>', $open);
        $this->assertNotFalse($close, 'the cart page section is never closed');

        $inner = substr($html, $open + 1, $close - $open - 1);

        $this->assertStringNotContainsString('<section', $inner,
            'the cart page section now nests another section, so this extraction stops at the '
            . 'inner one and measures less than the element');

        return $inner;
    }

    /** `POST {slug}/cart-list-sidebar`, the request `front-theme.js` makes to fill the cart page. */
    private function cartFragments(Store $store, ?string $basketCookie, ?Customer $customer = null): array
    {
        $request = $customer ? $this->asAShopper($customer) : $this;

        if ($basketCookie !== null) {
            $request = $request->withCookie('cart', $basketCookie);
        }

        $response = $request->post('/' . $store->slug . '/cart-list-sidebar', []);

        $this->assertSame(200, $response->status(),
            'the cart fragment endpoint did not answer, so nothing read from it means anything');

        $json = $response->json();

        $this->assertSame(1, (int) ($json['status'] ?? 0), sprintf(
            'PREMISE: cart-list-sidebar refused (%s), so it rendered no fragments and every '
            . 'assertion over them would hold vacuously.',
            substr((string) $response->getContent(), 0, 300)
        ));

        return $json;
    }

    /**
     * The readable text of every cart fragment, for a FULL and an EMPTY basket together.
     *
     * Both, because the two render different labels: a full basket shows the table and the totals,
     * an empty one shows "You have no items in your shopping cart." Measuring only one leaves a
     * real label uncovered and would push it into the not-reached list for the wrong reason.
     */
    private function cartFragmentText(Store $store, ?Customer $customer = null): array
    {
        $full = $this->cartFragments($store, $this->aGuestBasketIn($store), $customer);
        $empty = $this->cartFragments($store, $this->basketIdentifier(), $customer);

        $text = [];
        foreach (['html', 'checkout_html', 'checkout_html_2'] as $fragment) {
            $text[$fragment] = $this->readableText((string) ($full[$fragment] ?? ''))
                . ' ' . $this->readableText((string) ($empty[$fragment] ?? ''));
        }

        return $text;
    }

    // -------------------------------------------------------------------------------------------
    // THE KEY CENSUS OVER THE VIEW SOURCE
    // -------------------------------------------------------------------------------------------

    private function viewSourceWithoutBladeComments(string $relativePath): string
    {
        $path = resource_path('views/' . $relativePath);

        $this->assertFileExists($path, 'the view this census is scoped to has moved');

        return preg_replace('/\{\{--.*?--\}\}/s', ' ', file_get_contents($path));
    }

    /** `$themeSettings['x'] ?? __('y')` sites in one view, as key => setting. */
    private function shadowedKeysIn(string $relativePath): array
    {
        $source = $this->viewSourceWithoutBladeComments($relativePath);
        $found = [];

        preg_match_all(
            '/\$themeSettings\[\s*\'([a-z0-9_]+)\'\s*\]\s*\?\?\s*__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\)/',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $found[$match[2]] = $match[1];
        }

        ksort($found);

        return $found;
    }

    /** `__('x')` sites in one view that are NOT shadowed by a theme setting. */
    private function plainKeysIn(string $relativePath): array
    {
        /* The opening quote is part of the pattern. Without it `(?:[^'\\]|\\.)*` can never cross
           it, nothing matches, `preg_replace` returns the subject unchanged, and every shadowed
           key is counted as a plain one -- which is what the first run of this file did. */
        $source = preg_replace(
            '/\$themeSettings\[\s*\'[a-z0-9_]+\'\s*\]\s*\?\?\s*__\(\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*\)/',
            ' ',
            $this->viewSourceWithoutBladeComments($relativePath)
        );

        preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $source, $matches);

        $keys = array_values(array_unique($matches[1]));
        sort($keys);

        return $keys;
    }

    /**
     * THE WHOLE TRANSLATION QUESTION, asked once and reused by every surface.
     *
     * For each key the view prints: the response must contain the rendering for ITS OWN language
     * and must not contain the other language's. A key whose two renderings are equal is refused
     * outright — that is what a missing `ar.json` entry looks like, and it is the one failure mode
     * no source census can see.
     *
     * @param  array<string>  $keys
     * @return int  how many keys were really asserted, so a caller can refuse a vacuous run
     */
    private function assertEveryKeyIsInTheSurfacesOwnLanguage(
        array $keys,
        string $language,
        string $readableText,
        string $what
    ): int {
        $other = $language === 'ar' ? 'en' : 'ar';
        $asserted = 0;

        foreach ($keys as $key) {
            if (array_key_exists($key, self::NOT_REACHED_BY_THIS_FIXTURE)) {
                continue;
            }

            $own = trans($key, [], $language);
            $foreign = trans($key, [], $other);

            if (in_array($key, self::IDENTICAL_IN_BOTH_LANGUAGES_BY_DESIGN, true)) {
                continue;
            }

            $this->assertNotSame($foreign, $own, sprintf(
                '%s: `%s` renders identically in Arabic and English (%s). Either resources/lang/'
                . 'ar.json has no entry for it -- in which case the Arabic storefront prints '
                . 'English and no source census can tell -- or it is deliberate, in which case '
                . 'name it in IDENTICAL_IN_BOTH_LANGUAGES_BY_DESIGN with the reason.',
                $what, $key, var_export($own, true)
            ));

            $this->assertStringContainsString($own, $readableText, sprintf(
                '%s [%s]: the %s rendering of `%s` (%s) is not on the surface at all.',
                $what, $language, $language, $key, var_export($own, true)
            ));

            $this->assertStringNotContainsString($foreign, $readableText, sprintf(
                '%s [%s]: the %s rendering of `%s` (%s) is being shown to a %s shopper -- the '
                . 'surface is serving the wrong language for this label.',
                $what, $language, $other, $key, var_export($foreign, true), $language
            ));

            $asserted++;
        }

        return $asserted;
    }

    // -------------------------------------------------------------------------------------------
    // PREMISES. Each of these is a thing the rest of the file would hold vacuously without.
    // -------------------------------------------------------------------------------------------

    #[Test]
    public function the_views_this_file_censuses_contain_exactly_the_shadowed_and_plain_keys_it_declares(): void
    {
        foreach (self::SHADOWED_BY_A_THEME_SETTING as $view => $declared) {
            $this->assertSame($declared, $this->shadowedKeysIn($view), sprintf(
                '%s no longer carries exactly the `$themeSettings[...] ?? __()` sites this file '
                . 'declares. A new shadow is a label that stopped being translatable; a removed '
                . 'one means the characterisation case below should be inverted. Read the diff.',
                $view
            ));
        }

        /* And the plain sites, so a label added to the checkout cannot slip past the translation
           cases by simply not being in a list anybody updated. */
        $this->assertSame(['Cart Page'], $this->plainKeysIn('front_end/pages/cart.blade.php'));

        /* `Description` joined this list on 2026-11-25: it was `placeholder="Description"`, a bare
           literal, and is now `__('Description')`. The finding that recorded it is inverted below. */
        $this->assertSame([
            'Add Comments About Your Order', 'Additional Notes', 'Billing Information',
            'Checkout Page', 'Create an account for faster checkout and to track your orders',
            'Create an account?', 'Customer Information', 'Description', 'Email Address',
            'Enter Your Phone Number', 'Example', 'First Name', 'Last Name', 'Order Notes',
            'Payment Method', 'Phone Number', 'Ship To A Different Address?', 'Shipping Address',
        ], $this->plainKeysIn('front_end/pages/checkout.blade.php'));

        /* `Remove item` likewise -- it was `title="Remove item"`. */
        $this->assertSame([
            'Price', 'Product', 'Quantity', 'Remove item', 'Subtotal',
            'You have no items in your shopping cart.',
        ], $this->plainKeysIn('front_end/pages/cart-list.blade.php'));

        $this->assertSame([
            'Continue Shopping', 'Proceed to Checkout', 'Subtotal', 'Tax', 'Total', 'Your Cart',
        ], $this->plainKeysIn('front_end/pages/cart-list-sidebar.blade.php'));

        $this->assertSame([
            'Apply', 'Coupon', 'Enter coupon code', 'Order Summary', 'Select Shipping', 'Shipping',
            'Subtotal', 'Tax', 'Total', 'You have no items in your shopping cart.',
        ], $this->plainKeysIn('front_end/pages/checkout-cart-list.blade.php'));
    }

    #[Test]
    public function no_workdo_module_is_loaded_so_these_are_the_first_party_cart_paths(): void
    {
        $this->assertSame(0, (int) DB::table('add_on_managers')->count(),
            'a module row exists, so CartController and Cart::cart_list_cookie may be taking a '
            . 'vendored branch and this file is no longer measuring the host application');

        foreach (['PreOrder', 'WholesaleProduct', 'SizeGuideline', 'SkipCart'] as $module) {
            $this->assertFalse(module_is_active($module), sprintf(
                'module_is_active(%s) is true: the cart now runs that module\'s pricing and '
                . 'rendering branch instead of the first-party one measured here.',
                $module
            ));
        }
    }

    #[Test]
    public function the_cart_page_body_carries_no_basket_so_the_fragment_endpoint_must_be_driven(): void
    {
        $store = $this->storeSpeaking('en');
        $basket = $this->aGuestBasketIn($store);
        $product = Product::withoutGlobalScopes()->where('store_id', $store->id)->firstOrFail();

        $page = $this->withCookie('cart', $basket)->get('/' . $store->slug . '/cart');
        $page->assertOk();

        /* SCOPED TO THE ELEMENT, not the document. The product name really is on the cart page --
           `ViewServiceProvider` hands every storefront view `$search_products`, the whole
           catalogue, for the header's search box. A page-wide absence assertion therefore fails
           for a reason that has nothing to do with the basket, which is CLAUDE.md's "scope the
           search to the block too" in its positive form. */
        $section = $this->cartPageSectionOf((string) $page->getContent());

        $this->assertSame('', trim($this->readableText($section)), sprintf(
            'the cart PAGE now renders content inside .cart-page-section (%s). That is a change in '
            . 'where the cart is built -- front-theme.js:223 fills that element from '
            . 'cart-list-sidebar -- and the fragment-driven cases in this file should be '
            . 're-pointed at the page.',
            substr(trim($this->readableText($section)), 0, 200)
        ));

        $fragments = $this->cartFragments($store, $basket);

        $this->assertStringContainsString($product->name, (string) $fragments['checkout_html'],
            'the cart fragment does not contain the basket either, so this fixture never produced '
            . 'a cart and nothing below is about a cart');
    }

    #[Test]
    public function the_readable_text_extraction_walks_past_an_html_comment(): void
    {
        $arabic = $this->storeSpeaking('ar');
        $raw = (string) $this->get('/' . $arabic->slug . '/checkout')->getContent();

        /* The trap, asserted as present rather than assumed: if this markup comment ever goes, the
           self-check below stops proving anything and should be re-pointed at a live one. */
        $this->assertStringContainsString('<!-- First Name -->', $raw,
            'checkout.blade.php no longer carries the `<!-- First Name -->` comment, so this '
            . 'self-check no longer demonstrates that comments are stripped. Point it at another.');

        $readable = $this->readableText($raw);

        $this->assertStringContainsString(trans('First Name', [], 'ar'), $readable,
            'the extraction dropped the real label as well as the comment');
        $this->assertStringNotContainsString('First Name', $readable,
            'the English literal survived from the HTML comment, so every "the other language is '
            . 'absent" assertion in this file would fail for a reason that is not a defect');

        /* The other direction: the extraction can still see ordinary content. Without this the
           case above passes for an extraction that returns nothing at all. */
        $this->assertStringContainsString(trans('Customer Information', [], 'ar'), $readable);
        $this->assertStringContainsString(trans('Payment Method', [], 'ar'), $readable);
    }

    #[Test]
    public function the_fallback_layout_declares_no_direction_and_no_shopper_reaches_it(): void
    {
        $fallback = file_get_contents(resource_path('views/front_end/layouts/app.blade.php'));
        $openingTag = preg_match('/<html[^>]*>/', $fallback, $matches) ? $matches[0] : '';

        $this->assertNotSame('', $openingTag, 'the fallback layout has no <html> element');
        $this->assertStringNotContainsString('dir=', $openingTag, sprintf(
            'the `resources/views` copy of front_end/layouts/app.blade.php now declares a '
            . 'direction (%s). That is an improvement, and this premise should be rewritten -- but '
            . 'the four copies still have to agree.',
            $openingTag
        ));

        /* Every theme's own copy DOES declare one, and the theme's copy wins through
           Theme::set() -> prependLocation(). So a shopper always gets a direction. */
        foreach (['stylique', 'greentic', 'techzonix'] as $theme) {
            $this->assertStringContainsString('dir=', (string) file_get_contents(
                base_path('themes/' . $theme . '/views/front_end/layouts/app.blade.php')
            ), sprintf('theme %s lost the dir attribute from its storefront layout', $theme));
        }
    }

    // -------------------------------------------------------------------------------------------
    // THE PAGES RENDER, IN BOTH LANGUAGES, ON EVERY THEME
    // -------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function pageAndLanguage(): array
    {
        $cases = [];
        foreach (['cart', 'checkout'] as $page) {
            foreach (['en', 'ar'] as $language) {
                $cases[$page . ' / ' . $language] = [$page, $language];
            }
        }

        return $cases;
    }

    /**
     * A 200 asserted, not inferred — CLAUDE.md records Phase 03 cases that were vacuous on a
     * redirect — plus a floor on the body, because a 200 that renders an empty content area is
     * exactly the `$slot`-into-`@yield` failure this project has already shipped once.
     */
    #[Test]
    #[DataProvider('pageAndLanguage')]
    public function the_page_answers_a_guest_shopper_in_each_language(string $page, string $language): void
    {
        $store = $this->storeSpeaking($language);
        $basket = $this->aGuestBasketIn($store);

        $response = $this->withCookie('cart', $basket)->get('/' . $store->slug . '/' . $page);

        $this->assertSame(200, $response->status(), sprintf(
            'GET /%s/%s answered %d for a %s store. A redirect or a 404 satisfies every content '
            . 'assertion in this file silently.',
            $store->slug, $page, $response->status(), $language
        ));

        $body = (string) $response->getContent();
        $this->assertStringContainsString('</html>', $body, 'the document was truncated');
        $this->assertGreaterThan(20000, strlen($body), sprintf(
            'the %s %s page rendered only %d bytes: the layout is there but the content area is '
            . 'empty, which is how a storefront page fails invisibly.',
            $language, $page, strlen($body)
        ));
    }

    #[Test]
    #[DataProvider('pageAndLanguage')]
    public function the_page_answers_a_signed_in_shopper_in_each_language(string $page, string $language): void
    {
        $store = $this->storeSpeaking($language);
        $customer = $this->aCustomerOf($store);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $this->productIn($store)->id,
            'variant_id' => 0, 'qty' => self::BASKET_QTY, 'price' => self::BASKET_UNIT_PRICE,
            'store_id' => $store->id,
        ]);

        $response = $this->asAShopper($customer)->get('/' . $store->slug . '/' . $page);

        $this->assertSame(200, $response->status(), sprintf(
            'a signed-in shopper got %d from /%s/%s on a %s store',
            $response->status(), $store->slug, $page, $language
        ));

        /* The premise that makes this different from the guest case: the default guard really is
           empty, as in production, so ThemeLanguage step 2 and getCurrentStore() are not being
           answered by the Customer. */
        $this->assertNull(auth()->user(),
            'the DEFAULT guard holds a principal, so this is actingAs(..., \'customers\') '
            . 'behaviour and not a production shopper -- see asAShopper()');
        $this->assertSame($customer->id, auth('customers')->id());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function themePageAndLanguage(): array
    {
        $cases = [];
        foreach (['stylique', 'greentic', 'techzonix', ''] as $theme) {
            foreach (['cart', 'checkout'] as $page) {
                foreach (['en', 'ar'] as $language) {
                    $cases[($theme === '' ? 'no theme' : $theme) . ' / ' . $page . ' / ' . $language]
                        = [$theme, $page, $language];
                }
            }
        }

        return $cases;
    }

    /**
     * `dir` and `lang` on the document, for every theme and for a store with no theme at all.
     *
     * All four configurations, because the attribute is printed by the LAYOUT and the layout exists
     * four times. The themeless store is the case that reaches `config('theme.active')` through
     * `ActiveTheme`, and it is the one that would land on the direction-less fallback if that
     * resolution ever stopped happening.
     */
    #[Test]
    #[DataProvider('themePageAndLanguage')]
    public function the_document_direction_and_language_follow_the_store_language(
        string $theme,
        string $page,
        string $language
    ): void {
        $store = $this->storeSpeaking($language, $theme);

        $response = $this->get('/' . $store->slug . '/' . $page);
        $response->assertOk();

        $body = (string) $response->getContent();
        $this->assertSame(1, preg_match('/<html[^>]*>/', $body, $matches),
            'the response has no <html> element, so it is not a rendered storefront document');

        $opening = $matches[0];

        $this->assertStringContainsString('lang="' . $language . '"', $opening, sprintf(
            'theme %s, %s page: the document says %s for a store whose default_language is %s.',
            $theme === '' ? '(none)' : $theme, $page, $opening, $language
        ));

        $this->assertStringContainsString('dir="' . self::DIRECTION[$language] . '"', $opening, sprintf(
            'theme %s, %s page: the document is %s for a %s store, so the whole page is laid out '
            . 'in the wrong direction.',
            $theme === '' ? '(none)' : $theme, $page, $opening, $language
        ));

        $this->assertStringNotContainsString(
            'dir="' . self::DIRECTION[$language === 'ar' ? 'en' : 'ar'] . '"',
            $opening
        );
    }

    /**
     * A shopper's own language choice outranks the store's default, in both directions — the
     * storefront's language switcher writes the `LANGUAGE` cookie and `ThemeLanguage` reads it
     * first. Without this the cases above are satisfied by a product that ignores the shopper.
     */
    #[Test]
    #[DataProvider('pageAndLanguage')]
    public function a_shoppers_own_language_choice_turns_the_page_round(string $page, string $language): void
    {
        $chosen = $language === 'ar' ? 'en' : 'ar';
        $store = $this->storeSpeaking($language);

        $response = $this->withCookie('LANGUAGE', $chosen)->get('/' . $store->slug . '/' . $page);
        $response->assertOk();

        preg_match('/<html[^>]*>/', (string) $response->getContent(), $matches);

        $this->assertStringContainsString('lang="' . $chosen . '"', $matches[0] ?? '', sprintf(
            'a shopper who chose %s on a %s store was served %s',
            $chosen, $language, $matches[0] ?? '(no html element)'
        ));
        $this->assertStringContainsString('dir="' . self::DIRECTION[$chosen] . '"', $matches[0] ?? '');
    }

    // -------------------------------------------------------------------------------------------
    // THE STRINGS A SHOPPER READS
    // -------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function language(): array
    {
        return ['en' => ['en'], 'ar' => ['ar']];
    }

    #[Test]
    #[DataProvider('language')]
    public function every_translatable_string_the_cart_page_prints_is_in_the_pages_own_language(string $language): void
    {
        $store = $this->storeSpeaking($language);
        $basket = $this->aGuestBasketIn($store);

        $readable = $this->readableText((string) $this->withCookie('cart', $basket)
            ->get('/' . $store->slug . '/cart')->getContent());

        $asserted = $this->assertEveryKeyIsInTheSurfacesOwnLanguage(
            $this->plainKeysIn('front_end/pages/cart.blade.php'),
            $language,
            $readable,
            'the cart page document'
        );

        $this->assertGreaterThan(0, $asserted, 'no key was asserted, so this case proved nothing');
    }

    #[Test]
    #[DataProvider('language')]
    public function every_translatable_string_the_checkout_page_prints_is_in_the_pages_own_language(string $language): void
    {
        $store = $this->storeSpeaking($language);
        $basket = $this->aGuestBasketIn($store);

        $readable = $this->readableText((string) $this->withCookie('cart', $basket)
            ->get('/' . $store->slug . '/checkout')->getContent());

        /* `Checkout Page` is BOTH a plain site (the <title>) and a shadowed one (the banner), so
           its English rendering is legitimately on the Arabic page and the negative leg cannot
           apply to it. It is covered by the shadow characterisation case instead. */
        $keys = array_values(array_diff(
            $this->plainKeysIn('front_end/pages/checkout.blade.php'),
            array_keys(self::SHADOWED_BY_A_THEME_SETTING['front_end/pages/checkout.blade.php'])
        ));

        $asserted = $this->assertEveryKeyIsInTheSurfacesOwnLanguage(
            $keys,
            $language,
            $readable,
            'the checkout page document'
        );

        $this->assertGreaterThanOrEqual(13, $asserted, sprintf(
            'only %d checkout labels were asserted; the checkout form has fourteen reachable ones '
            . 'and a drop means labels stopped rendering.',
            $asserted
        ));
    }

    /**
     * THE CART AND CHECKOUT CONTENT ITSELF — the three fragments `front-theme.js` paints into the
     * two pages, which is where the basket, the totals and the coupon box actually live.
     */
    #[Test]
    #[DataProvider('language')]
    public function every_translatable_string_the_cart_fragments_print_is_in_the_surfaces_own_language(string $language): void
    {
        $store = $this->storeSpeaking($language);
        $text = $this->cartFragmentText($store);

        $views = [
            'html' => 'front_end/pages/cart-list-sidebar.blade.php',
            'checkout_html' => 'front_end/pages/cart-list.blade.php',
            'checkout_html_2' => 'front_end/pages/checkout-cart-list.blade.php',
        ];

        $asserted = 0;
        foreach ($views as $fragment => $view) {
            /* The shadowed keys are excluded here and pinned by the characterisation case: on the
               cart page `Subtotal` is printed twice, once translated as a table header and once in
               English through `cart_subtotal`, so "the English is absent" cannot hold for it. */
            $keys = array_values(array_diff(
                $this->plainKeysIn($view),
                array_keys(self::SHADOWED_BY_A_THEME_SETTING[$view])
            ));

            $asserted += $this->assertEveryKeyIsInTheSurfacesOwnLanguage(
                $keys,
                $language,
                $text[$fragment],
                'the ' . $fragment . ' fragment (' . $view . ')'
            );
        }

        $this->assertGreaterThanOrEqual(20, $asserted, sprintf(
            'only %d fragment labels were asserted across the three cart fragments; the three '
            . 'carry twenty unshadowed ones, so a drop means labels stopped rendering.',
            $asserted
        ));
    }

    /**
     * THE CLAUSE'S OWN WORDS: THE MESSAGE LANGUAGE MATCHES THE PAGE.
     *
     * One language, one store, two requests: the page GET, which composes a view and so runs
     * `ViewServiceProvider`'s resolver, and the add-to-cart JSON POST, which returns without
     * composing those views and so is answered by `ThemeLanguage` ALONE. §3's defect was precisely
     * that those two disagreed — an English page with Arabic messages — and the pair of them in one
     * case is the only shape that can see it.
     */
    #[Test]
    #[DataProvider('language')]
    public function the_add_to_cart_message_is_in_the_same_language_as_the_page_it_appears_on(string $language): void
    {
        $other = $language === 'ar' ? 'en' : 'ar';
        $store = $this->storeSpeaking($language);
        $product = $this->productIn($store);
        $basket = $this->basketIdentifier();

        $page = $this->withCookie('cart', $basket)->get('/' . $store->slug . '/cart');
        $page->assertOk();
        preg_match('/<html[^>]*>/', (string) $page->getContent(), $matches);

        $this->assertStringContainsString('lang="' . $language . '"', $matches[0] ?? '',
            'PREMISE: the page is not in the language under test, so "the message matches the '
            . 'page" cannot be measured');

        $added = $this->withCookie('cart', $basket)->post('/' . $store->slug . '/product_cart', [
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1,
        ]);

        $added->assertOk();
        $this->assertSame(1, (int) $added->json('status'), sprintf(
            'PREMISE: the add was refused (%s), so the success message was never produced.',
            substr((string) $added->getContent(), 0, 300)
        ));

        $message = (string) $added->json('data.message');

        $this->assertSame(
            trans(':product added successfully.', ['product' => $product->name], $language),
            $message,
            sprintf(
                'the page is lang="%s" and the add-to-cart message came back as %s. That is §3\'s '
                . 'defect: ThemeLanguage answers JSON requests on its own, and when it disagrees '
                . 'with the view composer a shopper reads one language and is spoken to in another.',
                $language, var_export($message, true)
            )
        );

        $this->assertNotSame(
            trans(':product added successfully.', ['product' => $product->name], $other),
            $message
        );

        /* The placeholder, which `ar` once dropped: the shopper must be told WHICH product. */
        $this->assertStringContainsString($product->name, $message,
            'the message lost its :product placeholder, so the shopper is not told what was added');
    }

    /**
     * The same question on a REFUSAL, because a shopper meets those more often than a success and
     * the §3 measurement included two of them.
     */
    #[Test]
    #[DataProvider('language')]
    public function a_cart_refusal_is_in_the_same_language_as_the_page_it_appears_on(string $language): void
    {
        $other = $language === 'ar' ? 'en' : 'ar';
        $store = $this->storeSpeaking($language);

        $variantProduct = Product::forceCreate([
            'name' => 'gap128-variant-' . $this->uniqueFixtureRef(),
            'slug' => 'gap128-variant-' . $this->uniqueFixtureRef(),
            'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 1,
            'price' => 10, 'sale_price' => 10, 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $refused = $this->withCookie('cart', $this->basketIdentifier())
            ->post('/' . $store->slug . '/product_cart', [
                'product_id' => $variantProduct->id, 'variant_id' => 0, 'qty' => 1,
            ]);

        $refused->assertOk();
        $this->assertSame(0, (int) $refused->json('status'),
            'PREMISE: a variant product with no variant chosen was accepted, so no refusal message '
            . 'was produced and this case measures nothing');

        $message = (string) $refused->json('data.message');
        $key = 'Please Select a variant in a product.';

        $this->assertSame(trans($key, [], $language), $message, sprintf(
            'the page is %s and the refusal came back as %s', $language, var_export($message, true)
        ));
        $this->assertNotSame(trans($key, [], $other), $message);
    }

    // -------------------------------------------------------------------------------------------
    // MONEY
    // -------------------------------------------------------------------------------------------

    /**
     * MEASURED, NOT ASSERTED FROM A WISH: the product does not localise money, so this pins what it
     * really does in both directions rather than a behaviour that does not exist.
     *
     * `currency_format_with_sym()` is `number_format()` with separators from the store's settings
     * and a stored symbol; nothing on the path reads a locale. If a locale-aware formatter is ever
     * added, this case goes red and the change is deliberate.
     */
    #[Test]
    public function money_renders_identically_in_both_languages_because_nothing_localises_it(): void
    {
        $expected = currency_format_with_sym(
            self::BASKET_UNIT_PRICE * self::BASKET_QTY,
            $this->storeSpeaking('en')->id
        );

        $rendered = [];
        foreach (['en', 'ar'] as $language) {
            $store = $this->storeSpeaking($language);
            $fragments = $this->cartFragments($store, $this->aGuestBasketIn($store));
            $rendered[$language] = $this->readableText((string) $fragments['checkout_html']);

            $this->assertStringContainsString($expected, $rendered[$language], sprintf(
                'the %s cart does not show the basket total as %s. If money has become '
                . 'locale-aware this case is the place to say so -- rewrite it, do not delete it.',
                $language, $expected
            ));

            $this->assertDoesNotMatchRegularExpression('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u',
                $rendered[$language],
                'Arabic-Indic digits appeared in the cart. Nothing in the money path produces '
                . 'them, so a locale-aware formatter has been introduced and the number format is '
                . 'now a product decision this case cannot settle.');
        }

        /* The figures, not the whole fragment: the product name and the store slug differ between
           the two fixtures by construction. */
        $this->assertSame(
            $this->moneyFiguresIn($rendered['en']),
            $this->moneyFiguresIn($rendered['ar']),
            'the Arabic and English carts printed different money strings for the same basket'
        );
    }

    /** @return array<string> every currency-symbol-prefixed figure on a surface, in order */
    private function moneyFiguresIn(string $readableText): array
    {
        preg_match_all('/\$[\d.,]+/', $readableText, $matches);

        return $matches[0];
    }

    // -------------------------------------------------------------------------------------------
    // THE DEFECT THIS HARNESS FOUND. Characterisation -- invert it when the views are fixed.
    // -------------------------------------------------------------------------------------------

    /**
     * GAP-128 FINDING: THE CART AND CHECKOUT CHROME IS SHIPPED ENGLISH ON AN ARABIC STOREFRONT.
     *
     * Nine labels are printed `{{ $themeSettings['x'] ?? __('y') }}` and every theme ships a value
     * for `x`, so `__()` is dead and the English default is what an Arabic shopper reads — beside
     * table headers that ARE Arabic, and beside the slide-out panel, which prints the same words
     * from an unshadowed `__()` and comes out Arabic. `resources/lang/ar.json` has all nine
     * translations.
     *
     * THIS CASE ASSERTS THE DEFECT, because the fix is a Blade edit and this agent was instructed
     * to report rather than make one. It is written so it CANNOT be satisfied by the correct
     * behaviour: fixing the views turns it red, by name, with the key that moved. Invert it then —
     * do not relax it.
     */
    #[Test]
    public function the_chrome_a_theme_setting_shadows_is_shipped_english_in_both_languages(): void
    {
        $arabic = $this->storeSpeaking('ar');
        $text = $this->cartFragmentText($arabic);

        /* Six of the cart page's seven shadows. The seventh, `cart_tax`, sits behind
           `@if(($response->data->tax_price ?? 0) > 0 || $is_inclusive_tax)` and this fixture
           configures no tax, so it is not on the page to be measured; the view census above is
           what pins that one. */
        $onTheCartPage = [
            'Clear Cart', 'Continue Shopping', 'Order Summary', 'Proceed to Checkout',
            'Subtotal', 'Total',
        ];

        foreach ($onTheCartPage as $key) {
            $this->assertStringContainsString(trans($key, [], 'en'), $text['checkout_html'], sprintf(
                'GAP-128 finding: `%s` is no longer shipped-English on the Arabic cart. If the '
                . 'theme-setting shadow has been fixed, INVERT this case -- assert the Arabic '
                . 'rendering and move the key out of SHADOWED_BY_A_THEME_SETTING.',
                $key
            ));
        }

        /* The checkout's submit button, whose shadow substitutes a DIFFERENT English string:
           `__('Complete Order')` is never printed because `checkout_button` defaults to
           'Place Order'. Both are English on an Arabic shop. */
        $this->assertStringContainsString('Place Order', $text['checkout_html_2'],
            'GAP-128 finding: the checkout submit button is no longer the shipped English '
            . '`Place Order` on an Arabic storefront -- invert this case.');

        /* THE CONTRAST THAT MAKES IT A DEFECT AND NOT A POLICY: the same four words, printed from
           an unshadowed `__()` in the slide-out panel, ARE Arabic in the same response. */
        foreach (['Subtotal', 'Tax', 'Total', 'Continue Shopping', 'Proceed to Checkout'] as $key) {
            $this->assertStringContainsString(trans($key, [], 'ar'), $text['html'], sprintf(
                'the slide-out cart panel no longer prints `%s` in Arabic, so the contrast this '
                . 'finding rests on has changed -- re-measure before trusting the row.',
                $key
            ));
            $this->assertStringNotContainsString(trans($key, [], 'en'), $text['html']);
        }

        /* And the banners, on the two page documents. */
        foreach (['cart' => 'Cart Details', 'checkout' => 'Checkout Page'] as $page => $shipped) {
            $readable = $this->readableText(
                (string) $this->get('/' . $arabic->slug . '/' . $page)->getContent()
            );

            $this->assertStringContainsString($shipped, $readable, sprintf(
                'GAP-128 finding: the %s banner on an Arabic storefront no longer reads the '
                . 'shipped English `%s` -- invert this case.',
                $page, $shipped
            ));
        }
    }

    /**
     * GAP-128 SECOND FINDING, NOW INVERTED (2026-11-25): THE THREE HARDCODED ATTRIBUTES ARE FIXED.
     *
     * This case used to ASSERT the defect, deliberately, and its own instruction was "invert the
     * line that reddens; do not relax it". All three reddened, so all three are inverted here:
     *
     *   `cart-list.blade.php`          title="Remove item"            -> `__('Remove item')`
     *   `checkout.blade.php`           placeholder="Description"      -> `__('Description')`
     *   `cart-list-sidebar.blade.php`  alt="Artisan Sourdough Bread"  -> `{{ $product->name }}`
     *
     * THE FIRST TWO NEEDED NOTHING BUT THE VIEW EDIT, because `ar.json` already shipped
     * `إزالة العنصر` and `وصف` -- nothing was ever asking for them. No `__()` call meant no key
     * census could see either, which is exactly how they survived both
     * `StorefrontMessagesAreDeclaredTest` and `TranslationFileIntegrityTest`: both are source
     * censuses over keys, and a bare literal has no key. The two sites are now declared in the
     * plain-key census above, so the translation cases cover them from here on.
     *
     * THE THIRD WAS NOT A TRANSLATION DEFECT AND WAS THE WORST OF THEM. `Artisan Sourdough Bread`
     * was a leftover from the HTML template this view was cut from, printed for EVERY line of the
     * slide-out basket -- so a screen reader announced a shopper's whole cart as one sourdough
     * loaf, in both languages, and a shopper who could not see the thumbnails had no way to tell
     * their items apart.
     *
     * ASSERTED ON THE RENDERED SURFACE AND NOT ON THE SOURCE, in both directions. A source
     * assertion would pass on a view whose `__()` call sits on a line no shopper reaches, and this
     * harness already had to learn that lesson once: the cart page's body is empty server-side.
     */
    #[Test]
    public function the_three_hardcoded_attributes_are_translated_and_the_alt_names_the_product(): void
    {
        $gone = [
            'front_end/pages/cart-list.blade.php' => 'title="Remove item"',
            'front_end/pages/checkout.blade.php' => 'placeholder="Description"',
            'front_end/pages/cart-list-sidebar.blade.php' => 'alt="Artisan Sourdough Bread"',
        ];

        foreach ($gone as $view => $literal) {
            $this->assertStringNotContainsString(
                $literal,
                $this->viewSourceWithoutBladeComments($view),
                sprintf(
                    '%s carries the bare `%s` again. Comments are stripped before this assertion, '
                    . 'so prose about the old defect cannot break it -- this is the literal itself.',
                    $view, $literal
                )
            );
        }

        foreach (['ar', 'en'] as $language) {
            $store = $this->storeSpeaking($language);
            $basket = $this->aGuestBasketIn($store);
            $fragments = $this->cartFragments($store, $basket);

            $cart = $this->readableText((string) $fragments['checkout_html']);
            $mini = $this->readableText((string) $fragments['html']);
            $checkout = $this->readableText(
                (string) $this->get('/' . $store->slug . '/checkout')->getContent()
            );

            /* The tooltip, in the surface's own language -- and not the other one's. */
            $this->assertStringContainsString(trans('Remove item', [], $language), $cart, sprintf(
                '[%s] the remove tooltip is not in the cart\'s own language', $language
            ));
            $this->assertStringContainsString(trans('Description', [], $language), $checkout, sprintf(
                '[%s] the order-notes placeholder is not in the checkout\'s own language', $language
            ));

            if ($language === 'ar') {
                $this->assertStringNotContainsString('Remove item', $cart,
                    'the English tooltip is on the Arabic cart');
                $this->assertStringNotContainsString('placeholder="Description"', $checkout,
                    'the English placeholder is on the Arabic checkout');
            }

            /* And the alt names the product that is actually in the basket. */
            $this->assertStringNotContainsString('Artisan Sourdough Bread', $mini, sprintf(
                '[%s] the mini-cart still announces the template\'s sourdough loaf', $language
            ));
            /*
             * THE PRODUCT THAT IS ACTUALLY IN THIS BASKET, read from the row rather than by
             * calling the fixture helper again -- `productIn()` force-creates a NEW product on
             * every call, so a second call names something the basket does not hold, and the
             * assertion failed against a correct fix. Read RAW, past both scope traps: `Cart` is
             * in `config/tenancy.php`'s `scoped_models` so an Eloquent read answers against
             * whichever tenant happens to be resolved, and `Product` carries a `StoreScope` that
             * is inert because it is NOT allowlisted -- neither is the question being asked here.
             */
            $inTheBasket = (string) DB::table('products')
                ->whereIn('id', DB::table('carts')
                    ->where('cookie_session_id', $basket)->pluck('product_id'))
                ->value('name');

            $this->assertNotSame('', $inTheBasket,
                'premise: the basket really holds a product whose name the alt could carry');

            $this->assertStringContainsString($inTheBasket, $mini, sprintf(
                '[%s] the mini-cart image does not name the product in the basket, so a screen '
                . 'reader cannot tell one line from another', $language
            ));
        }
    }

    /**
     * AND THE PREMISE THAT MADE THE FIRST TWO A ONE-LINE FIX, kept rather than deleted with the
     * finding: the translations really are shipped, so the view edit was the whole of it. The first
     * draft of the finding's docblock claimed the opposite and was wrong.
     */
    public function test_the_two_translated_attributes_really_have_arabic_to_serve(): void
    {
        foreach (['Remove item', 'Description'] as $key) {
            $this->assertNotSame(trans($key, [], 'en'), trans($key, [], 'ar'), sprintf(
                '`%s` is no longer translated in ar.json, so the case above is asserting that an '
                . 'English string is the Arabic one.',
                $key
            ));
        }
    }
}
