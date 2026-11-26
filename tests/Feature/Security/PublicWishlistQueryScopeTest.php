<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * GAP-286 -- A PUBLIC, UNAUTHENTICATED ROUTE READS EVERY WISHLIST ROW ON THE PLATFORM.
 * =================================================================================================
 *
 * `HomeController::wishlist()` (`routes/customer.php:123`, `GET {storeSlug?}/wishlist`, no `auth`)
 * carries its guard AND its predicate commented out, and the live line is:
 *
 *     $wishlists = Wishlist::with('ProductData')->get();
 *
 * `Wishlist` is in neither `config/tenancy.php`'s `scoped_models` nor anything else -- CLAUDE.md's
 * table lists it among the models with NO scope at all -- so that is every wishlist row belonging
 * to every customer of every store, with each one's product eager-loaded, on a route anyone can
 * request without signing in.
 *
 * WHY AN HTTP RESPONSE-BODY TEST CANNOT SEE THIS, which is the whole reason this file exists and is
 * shaped the way it is. The data never reaches the response. Measured:
 *
 *   - `resources/views/front_end/pages/wishlist.blade.php` is the ONLY copy of that view -- there
 *     are no theme overrides, checked against all four theme roots, so `Theme::set()`'s
 *     `prependLocation()` falls through to it -- and it references `wishlists` ZERO times.
 *   - The page is a shell. Its `.wishlist-table-section` div is empty and filled by AJAX:
 *     `public/assets/js/front-theme.js:1108` does `$('.wishlist-table-section').html(response.html)`.
 *   - That AJAX path is `WishlistController`, and it IS correctly scoped --
 *     `where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)`, with an
 *     empty collection for an anonymous visitor.
 *
 * So the DISCLOSURE half is genuinely latent, and this file says so rather than overclaiming. What
 * is LIVE is the read itself: an unbounded, unauthenticated, platform-wide query with an eager load,
 * repeatable by anyone, growing with every wishlist any customer ever saves. That is a resource
 * exhaustion vector rather than a leak, and it is a different defect from the one the register row
 * records -- so the assertion below is on THE QUERY, not on the body. A body assertion would pass
 * today and would go on passing if the predicate were never restored.
 *
 * AND THE FIX THE ROW IMPLIES WOULD BREAK THE PAGE. Restoring the commented-out
 * `if (! auth('customers')->user()) { return redirect()->back(); }` would redirect every anonymous
 * visitor away from a public storefront page that is DESIGNED to work for them: the shell renders,
 * the AJAX answers an empty list, and that is the intended anonymous experience. `test_control_...`
 * below pins that, so a later "fix" that adds the guard fails instead of silently removing a page.
 * The dead query is removed instead -- which takes the vector away and changes nothing a visitor
 * sees, because nothing ever read the variable.
 */
class PublicWishlistQueryScopeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Two stores, each with a customer holding a wishlist row, so a platform-wide read has
     * something foreign to pick up and a scoped one has something to leave behind.
     *
     * @return array{0: \App\Models\Store, 1: int}
     */
    private function twoStoresWithWishlists(): array
    {
        $rows = 0;

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        foreach ([[$merchantA, $storeA], [$merchantB, $storeB]] as [$merchant, $store]) {
            $customer = Customer::forceCreate([
                'first_name' => 'W', 'last_name' => 'L',
                'email' => 'wl-' . $this->uniqueFixtureRef() . '@example.test',
                'mobile' => '555' . random_int(100000, 999999),
                'password' => bcrypt('not-a-real-password'),
                'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
            ]);

            $product = Product::forceCreate([
                'name' => 'wl-' . $this->uniqueFixtureRef(),
                'slug' => 'wl-' . $this->uniqueFixtureRef(),
                'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0,
                'price' => 10, 'sale_price' => 10,
                'store_id' => $store->id, 'created_by' => $store->created_by,
            ]);

            Wishlist::forceCreate([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'store_id' => $store->id,
            ]);

            $rows++;
        }

        /* THE PREMISE, asserted rather than assumed: the rows are really there and really in two
           different stores. Read through `withoutGlobalScopes()` because an "it is gone"/"it is
           there" assertion on a scoped model otherwise answers against whichever tenant happens to
           be resolved -- CLAUDE.md records a cart test that read a row as deleted while the raw
           table still held it. `Wishlist` has no scope, but asking the raw table costs nothing and
           cannot be wrong. */
        $this->assertSame(
            2,
            (int) DB::table('wishlists')->count(),
            'premise: two wishlist rows exist, one per store, so a platform-wide read has something '
            . 'foreign to collect'
        );

        return [$storeA, $rows];
    }

    /** Every statement the request issues, captured. */
    private function queriesDuring(callable $drive): array
    {
        $seen = [];

        DB::listen(static function ($query) use (&$seen) {
            $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $drive();

        return $seen;
    }

    /**
     * THE DEFECT, ON THE QUERY RATHER THAN ON THE BODY.
     *
     * An anonymous request to a public storefront route must not issue a `select` over `wishlists`
     * with no owner and no store predicate. Today it issues exactly that, plus the `ProductData`
     * eager load for every row it collected.
     */
    #[Test]
    public function test_the_public_wishlist_page_issues_no_unscoped_platform_wide_read(): void
    {
        [$store] = $this->twoStoresWithWishlists();

        $queries = $this->queriesDuring(function () use ($store) {
            $this->get('/' . $store->slug . '/wishlist');
        });

        $unscoped = [];

        foreach ($queries as $q) {
            $sql = strtolower($q['sql']);

            if (! str_contains($sql, 'from `wishlists`') && ! str_contains($sql, 'from "wishlists"')) {
                continue;
            }

            /* A read is scoped if it narrows by the owner or by the store. Anything else over this
               table is a platform-wide read, whatever it is for. */
            if (str_contains($sql, 'customer_id') || str_contains($sql, 'store_id')) {
                continue;
            }

            $unscoped[] = $q['sql'];
        }

        $this->assertSame(
            [],
            $unscoped,
            "An anonymous request to a PUBLIC route read the `wishlists` table with no owner and no "
            . "store predicate. `Wishlist` has no tenant scope, so this is every customer's "
            . "wishlist on the platform, with each row's product eager-loaded, on a URL anyone can "
            . "request repeatedly. Statements:\n  " . implode("\n  ", $unscoped)
        );
    }

    /**
     * AND NOT ONE ROW OF IT IS HYDRATED EITHER.
     *
     * The query assertion above is the one that bites, but a `select ... where customer_id is null`
     * would satisfy it while still loading the table. So this asks the other half: whatever the
     * handler hands the view, it must not be foreign rows.
     *
     * `$response->original` is the View, so this reads the handler's own bound data -- the only
     * place the rows are observable at all, since the template ignores them.
     */
    #[Test]
    public function test_the_handler_hands_the_view_no_foreign_wishlist_rows(): void
    {
        [$store] = $this->twoStoresWithWishlists();

        $response = $this->get('/' . $store->slug . '/wishlist');

        $this->assertSame(200, $response->getStatusCode(), 'premise: the page renders for a visitor');

        $data = $response->original instanceof \Illuminate\View\View
            ? $response->original->getData()
            : [];

        $handed = $data['wishlists'] ?? null;

        $count = is_countable($handed) ? count($handed) : ($handed === null ? 0 : 1);

        $this->assertSame(
            0,
            $count,
            'The handler loaded ' . $count . ' wishlist row(s) and handed them to a template that '
            . 'never reads them. They belong to customers of every store on the platform. Nothing '
            . 'is disclosed today because the view ignores the variable -- which is a property of '
            . 'the TEMPLATE, not a control, and one template edit away from being a leak.'
        );
    }

    /**
     * THE CONTROL, AND IT IS THE REASON THE ROW'S OWN SUGGESTED FIX IS WRONG.
     *
     * The commented-out code in the handler is a guard that redirects anyone not signed in as a
     * customer. Restoring it would take this page away from every anonymous visitor -- and the page
     * is MEANT for them: it renders a shell and `front-theme.js:1108` fills the table by AJAX from
     * `WishlistController`, which answers an anonymous caller an empty collection.
     *
     * So this case fails if somebody "fixes" GAP-286 by restoring the guard, and that is deliberate.
     */
    #[Test]
    public function test_control_an_anonymous_visitor_still_gets_the_page(): void
    {
        [$store] = $this->twoStoresWithWishlists();

        $response = $this->get('/' . $store->slug . '/wishlist');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'An anonymous visitor was refused the public wishlist page. The route carries no `auth` '
            . 'and the page is a shell filled by AJAX that serves an anonymous caller an empty list, '
            . 'so refusing them removes a working page rather than closing GAP-286. Remove the dead '
            . 'query instead of restoring the commented-out guard.'
        );
    }
}
