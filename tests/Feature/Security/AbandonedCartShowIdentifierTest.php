<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-C -- THE ABANDONED-CART "SHOW PRODUCTS" MODAL ADDRESSES A ROW ITS HANDLER CANNOT READ.
 * ================================================================================================
 *
 * THE TWO HALVES OF THIS FEATURE DISAGREE ABOUT WHAT THE IDENTIFIER IS, and the disagreement is
 * written into the names. The route USED TO declare `abandon-carts-show/{cartId}`
 * and the handler signature `abandon_carts_show($cartId)` -- while the handler's first statement
 * was `Cart::where('customer_id', $cartId)`, so what it actually wanted was a CUSTOMER id. Both
 * halves are named `customerId` now; no line numbers are cited here, because this paragraph
 * describes the PRE-FIX state and a number would point at the fixed code. The view
 * believed the name and sent `$cart->id`, and `AbandonCartDataTable::query()` sets that column to
 * `MIN(carts.id)` over a `groupBy('carts.customer_id')` -- a cart ROW id.
 *
 * SO THE LOOKUP MISSES, `redirect()->back()` fires, and jQuery's `data-ajax-popup` handler drops
 * the REDIRECTED LIST PAGE into the modal body. MEASURED on the running app before this test
 * existed: `GET /abandon-carts-show/14` answered 200 after a redirect to `/abandon-carts-handled`
 * with 89,443 bytes of full page, and the modal rendered the sidebar -- "لوحة التحكم / البيع /
 * الطلبات" -- where the product list belongs. Two console errors came with it, both from re-running
 * the layout's scripts inside the modal ("Identifier 'slideUp' has already been declared").
 *
 * THIS FEATURE HAS THEREFORE NEVER WORKED, and nothing in the suite touched it: before this file,
 * no test in the repository drove the merchant-side abandoned-cart surface at all. It renders 200,
 * so a census keyed on status could not see it; the defect only appears if you CLICK.
 *
 * THE SIBLING WORKS, which is what told me the shape was wrong rather than the data. The wishlist
 * list uses the same cell, the same count and the same modal, but `WishlistController::show`
 * route-model-binds a real `Wishlist` row -- and its `WishlistDataTable::query()` has no
 * `selectRaw`, so `$wishlist->id` IS a row id and the binding resolves. Only the cart half
 * rewrites `id` to an aggregate.
 *
 * WHAT THE ROUND-TRIP TEST BUYS over asserting the view's markup: it is stated in the merchant's
 * terms -- take the identifier the list's own button emits, feed it back to the route, require the
 * products -- so it stays true whichever half gets fixed, and it cannot pass by my changing the
 * view and the controller to agree on something that is still wrong.
 *
 * THE SCOPING TESTS ARE SEPARATE, and deliberately so. Correcting the identifier makes the handler
 * reachable for the FIRST time, which is exactly when its store predicates start to matter: the
 * existence probe had none (`Cart` is in `scoped_models`, so a resolved tenant gets one
 * from the global scope -- but `null_tenant => 'open'` means a principal that resolves no tenant
 * gets no predicate at all), while the query that selects the rows already named
 * `getCurrentStore()` explicitly. A fix for the identifier does not imply either, so they are
 * asserted on their own, and the owner positive control is present because a cross-tenant
 * assertion that passes because NOTHING rendered has already cost this project four false
 * readings.
 */
class AbandonedCartShowIdentifierTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The disclosure URL inside the yajra payload.
     *
     * The one-or-two non-digit characters before the id are not cosmetic. The rows arrive as
     * ENCODED JSON, so the separator is `\\/` and not `/` -- a pattern written with a bare slash
     * matched nothing and the assertions keyed on it failed as premise errors instead of reading
     * an id. Measured the first two times this file ran, which is also why it is a named constant:
     * four assertions share it and they must all read the same thing.
     */
    private const URL = '#abandon-carts-show[^0-9]{1,2}(\\d+)#';

    private function makeProduct(int $storeId, int $createdBy, string $name): Product
    {
        $catName = 'Cart Cat ' . uniqid();
        $category = Category::forceCreate([
            'name' => $catName,
            'slug' => \Illuminate\Support\Str::slug($catName) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);

        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $category->id,
            'store_id' => $storeId, 'created_by' => $createdBy,
        ]);
    }

    private function makeCartRow(int $storeId, int $createdBy, int $customerId, string $productName): Cart
    {
        return Cart::forceCreate([
            'customer_id' => $customerId,
            'product_id' => $this->makeProduct($storeId, $createdBy, $productName)->id,
            'variant_id' => 0, 'qty' => 2, 'price' => 10, 'store_id' => $storeId,
        ]);
    }

    private function makeCustomer(int $storeId): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'C', 'last_name' => 'Shopper',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('x'),
            'store_id' => $storeId,
        ]);
    }

    /**
     * The rendered abandoned-cart rows out of the real yajra AJAX leg.
     *
     * Both headers, because `render()` branches on `ajax() && wantsJson()` TOGETHER -- with only
     * one of them the drive silently takes the HTML branch and returns a scaffold with no rows, so
     * every assertion below would be reading an empty string.
     */
    private function listRows($user): string
    {
        $query = ['draw' => 1, 'start' => 0, 'length' => 50];

        foreach (['customer_id', 'email', 'product', 'action'] as $i => $name) {
            $query["columns[{$i}][data]"] = $name;
            $query["columns[{$i}][name]"] = $name;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'false';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        $response = $this->actingAs($user)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->getJson('/abandon-carts-handled?' . http_build_query($query));

        $this->assertNotSame(404, $response->getStatusCode(), 'premise: the list route exists');
        $this->assertNotSame(500, $response->getStatusCode(), 'premise: the list does not 500');

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('draw', $payload, 'premise: yajra served the AJAX leg, not the HTML one');

        return (string) json_encode($payload['data'] ?? []);
    }

    // =============================================================================================
    // THE DEFECT, stated as the merchant's round trip.
    // =============================================================================================

    public function test_the_identifier_the_list_emits_resolves_to_the_products(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Cart');
        // The LIST leg gates on a different name (`CartController::abandon_carts_handled`), so a
        // fixture holding only `Show Cart` is redirected before yajra runs and the round trip
        // would measure nothing.
        $this->grantPermission($merchant, 'Manage Cart');

        // THE FIXTURE MUST SEPARATE THE TWO IDENTIFIERS, or this test passes while the defect
        // stands. In a from-zero database `customers.id` and `carts.id` both begin at 1, so
        // MIN(carts.id) for the first customer IS that customer's id -- the broken view and the
        // correct one would emit the same number and the round trip would be green either way.
        // Three throwaway customers push the subject's id clear of the cart id space; the premise
        // below asserts the separation actually happened rather than trusting the arithmetic.
        $this->makeCustomer($store->id);
        $this->makeCustomer($store->id);
        $this->makeCustomer($store->id);

        $customer = $this->makeCustomer($store->id);
        $this->makeCartRow($store->id, $merchant->id, $customer->id, 'AbandonedScarf');
        $this->makeCartRow($store->id, $merchant->id, $customer->id, 'AbandonedRunner');

        $minCartId = (int) Cart::withoutGlobalScopes()
            ->where('store_id', $store->id)->where('customer_id', $customer->id)->min('id');

        $this->assertNotSame(
            $customer->id,
            $minCartId,
            'premise: MIN(carts.id) (' . $minCartId . ') and the customer id (' . $customer->id
            . ') must DIFFER, or the broken identifier and the correct one are the same number '
            . 'and this test cannot tell them apart.'
        );

        $rows = $this->listRows($merchant);

        // The premise: the cell really rendered the disclosure button.
        $this->assertMatchesRegularExpression(
            self::URL,
            $rows,
            'premise: the product cell renders a `carts.show` URL at all'
        );
        preg_match(self::URL, $rows, $m);
        $emitted = (int) $m[1];

        $response = $this->actingAs($merchant)->get('/abandon-carts-show/' . $emitted);

        // A redirect is the live symptom: the handler could not resolve the row, so
        // `redirect()->back()` sends the whole list page into the modal.
        $this->assertSame(
            200,
            $response->getStatusCode(),
            "GET /abandon-carts-show/{$emitted} -- the identifier the list's OWN button emits, "
            . "where the customer id is {$customer->id} and MIN(carts.id) is {$minCartId} -- "
            . 'did not resolve. Status ' . $response->getStatusCode() . '. The route placeholder '
            . 'and the handler parameter are both named `cartId`, but the handler keys on '
            . '`customer_id`, and the view sends MIN(carts.id). A 302 here is what puts the '
            . 'sidebar in the modal.'
        );

        $response->assertSee('AbandonedScarf');
        $response->assertSee('AbandonedRunner');
        $response->assertDontSee('<!DOCTYPE html>', false);
    }

    // =============================================================================================
    // THE SCOPING, which only begins to matter once the handler is reachable.
    // =============================================================================================

    public function test_another_stores_cart_lines_do_not_render_on_this_merchants_modal(): void
    {
        [$owner, $ownStore] = $this->makeTenant();
        [$other, $otherStore] = $this->makeTenant();
        $this->grantPermission($owner, 'Show Cart');
        $this->grantPermission($owner, 'Manage Cart');

        // The realistic shape: `customers` is store-scoped precisely because the id space is
        // reused, so the same customer id exists in both stores.
        $sharedCustomerId = 4242;
        $this->makeCartRow($ownStore->id, $owner->id, $sharedCustomerId, 'MineOnlyCartItem');
        $this->makeCartRow($otherStore->id, $other->id, $sharedCustomerId, 'OtherStoreLeakedCartItem');

        $rows = $this->listRows($owner);
        $this->assertMatchesRegularExpression(self::URL, $rows, 'premise: a row rendered');
        preg_match(self::URL, $rows, $m);

        $response = $this->actingAs($owner)->get('/abandon-carts-show/' . (int) $m[1]);

        $response->assertStatus(200);
        $response->assertSee('MineOnlyCartItem');
        $response->assertDontSee('OtherStoreLeakedCartItem');
    }

    public function test_a_merchant_cannot_read_a_foreign_stores_abandoned_cart(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Show Cart');

        $customer = $this->makeCustomer($victimStore->id);
        $this->makeCartRow($victimStore->id, $victim->id, $customer->id, 'VictimSecretCartItem');

        // The attacker names the victim's customer id directly -- the identifier is the only thing
        // standing between them and the row, so it is the thing to drive.
        $response = $this->actingAs($attacker)->get('/abandon-carts-show/' . $customer->id);

        $this->assertNotSame(
            'rendered',
            $response->getStatusCode() === 200 && str_contains($response->getContent(), 'VictimSecretCartItem')
                ? 'rendered' : 'refused',
            'a merchant read another store\'s abandoned cart contents'
        );
        $response->assertDontSee('VictimSecretCartItem');
    }

    /**
     * THE PREDICATE THAT THE GLOBAL SCOPE DOES NOT COVER -- and the only test in this file that
     * the existence probe's store predicate is load-bearing for.
     *
     * MEASURED BY MUTATION, which is why this test exists at all. With only the three tests above,
     * deleting the store predicate from EITHER query in `abandon_carts_show()` left all of them
     * green: `Cart` is in `config/tenancy.php`'s `scoped_models`, a merchant with a store resolves
     * a tenant, and the global scope was doing the work the predicates were being credited for.
     * Both mutants survived, so the three cross-tenant assertions above prove that the SCOPE works
     * -- which is worth knowing, but it is not what they appeared to be claiming.
     *
     * WHAT THIS TEST FIXES, EXACTLY. With it, deleting the probe's predicate turns this test red.
     * It is the predicate P2.5-PKG-C added; the line query's predicate was already in the original
     * handler.
     *
     * AND WHAT IT STILL DOES NOT COVER, stated rather than left to be discovered: deleting the
     * LINE query's predicate keeps every test here green, because the probe refuses first and the
     * line query is then never reached. For the one principal whose scope is inert -- a caller with
     * no store -- `getCurrentStore()` is NULL, so a probe that fails closed makes the second
     * predicate unreachable by construction. Covering it would need a caller for whom the probe
     * PASSES while the scope is still inert, which is the implicit-store-1 super-admin shape held
     * under D-42.7 rather than anything this screen can reach. The predicate stays because it is
     * correct and because that shape exists; this file does not claim to drive it.
     *
     * THE PRINCIPAL THE SCOPE CANNOT SEE is a merchant with no store. Driven, inside this harness,
     * for a storeless `type=admin` user holding `Show Cart`:
     *
     *     getCurrentStore()                                        -> NULL
     *     Cart::where('customer_id', $victim)->count()             -> 1   (scope applied)
     *     Cart::withoutGlobalScopes()->where(...)->count()         -> 1   (no scope)
     *
     * The two counts are EQUAL: `null_tenant => 'open'` means that when no tenant resolves the
     * scope adds no predicate, so the scope is not a weaker guard here, it is no guard. Without an
     * explicit predicate the probe finds the victim's row and the unscoped line query returns the
     * victim's cart contents at 200.
     *
     * THE HANDLER REFUSES THIS CALLER BEFORE EITHER QUERY RUNS, and the first version of this
     * docblock got the reason wrong. It said `where('store_id', null)` "matches nothing". It does
     * not: it compiles to `store_id IS NULL`, which this codebase already records at
     * `ShippingMethodController:138`. So the predicate refused only because no `carts` row happens
     * to carry a NULL `store_id` -- and that column IS nullable with no default. An explicit
     * `empty($storeId)` refusal does not depend on the table's contents, so that is what the
     * handler now does, and it is what this test pins, in both directions: refused, and the
     * victim's product name absent from the body. The wrong reason was caught by the tenant-authz
     * review of the very change this file was written for.
     */
    public function test_a_storeless_merchant_is_refused_rather_than_served_any_stores_cart(): void
    {
        [$victim, $victimStore] = $this->makeTenant();

        // type=admin with no store of their own. `getCurrentStore()` falls back to
        // `Store::where('created_by', $user->id)` for an admin with an empty `current_store`, so
        // this user must own no store for the fallback to come back empty; `makeMerchant()`
        // creates no store, unlike `makeTenant()`.
        $storeless = $this->makeMerchant(['current_store' => null]);
        $this->grantPermission($storeless, 'Show Cart');

        $customer = $this->makeCustomer($victimStore->id);
        $this->makeCartRow($victimStore->id, $victim->id, $customer->id, 'StorelessReachableItem');

        // The premise, asserted rather than assumed: for THIS caller the global scope really is
        // inert, so the explicit predicate is the only thing being tested below.
        $this->actingAs($storeless);
        $this->assertSame(
            Cart::withoutGlobalScopes()->where('customer_id', $customer->id)->count(),
            Cart::where('customer_id', $customer->id)->count(),
            'premise: with no tenant resolved the global scope adds NO predicate, so the scoped '
            . 'and unscoped counts must agree. If they diverge, the scope is guarding this caller '
            . 'and the assertion below would pass without the explicit predicate.'
        );

        $response = $this->actingAs($storeless)->get('/abandon-carts-show/' . $customer->id);

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'a merchant with no store read a cart belonging to a store that is not theirs. '
            . 'getCurrentStore() is NULL for this caller, so the explicit `store_id` predicate '
            . 'must match nothing and refuse.'
        );
        $response->assertDontSee('StorelessReachableItem');
    }

    /**
     * AND THE `empty($storeId)` REFUSAL IS LOAD-BEARING, which the test above does NOT show.
     *
     * MUTATION FOUND THIS, after the same gap was found and closed on both send handlers and on
     * the wishlist pair. Deleting the `empty($storeId)` guard leaves the storeless test above
     * green, because without it the probe compiles to `store_id IS NULL` and no fixture row
     * carries a null store, so the probe misses and the handler refuses for the predicate's
     * reason rather than the guard's. Three handlers, the same illusion in all three, and in each
     * case the first test I wrote measured the predicate while claiming to measure the guard.
     *
     * `carts.store_id` is nullable with no default (2024_01_18_103138), so the row below is the
     * legacy or imported line `config/tenancy.php` already names as the operator's precondition --
     * and for a caller whose store does not resolve, `store_id IS NULL` MATCHES it.
     */
    public function test_a_storeless_merchant_cannot_read_a_null_store_cart(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        $this->grantPermission($victim, 'Show Cart');

        $customer = $this->makeCustomer($victimStore->id);
        $orphan = $this->makeCartRow($victimStore->id, $victim->id, $customer->id, 'NullStoreOrphanItem');
        \DB::table('carts')->where('id', $orphan->id)->update(['store_id' => null]);

        $storeless = $this->makeMerchant(['current_store' => null]);
        $this->grantPermission($storeless, 'Show Cart');

        $this->assertNull(
            \DB::table('carts')->where('id', $orphan->id)->value('store_id'),
            'premise: the row must carry a NULL store_id, or `store_id IS NULL` would not match '
            . 'it and this test would pass without the guard'
        );

        $response = $this->actingAs($storeless)->get('/abandon-carts-show/' . $customer->id);

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'a storeless caller read a NULL-store cart line. Only the explicit refusal stops this; '
            . 'the `store_id` predicate alone MATCHES a null-store row for this caller.'
        );
        $response->assertDontSee('NullStoreOrphanItem');
    }

    /**
     * A PRODUCT DELETED WHILE SITTING IN AN ABANDONED CART NO LONGER TAKES THE MODAL DOWN.
     *
     * `cart/show.blade.php` read `$product->product_data->name` with no null check, and
     * `product_data` is `hasOne(Product::class)` -- null once the catalogue row is gone. `Product`
     * does NOT use SoftDeletes, so a delete really removes the row. An abandoned cart is exactly
     * where this happens: the lines sit for weeks while the merchant edits the catalogue
     * underneath them, so one tidy-up turned the whole modal into "Attempt to read property on
     * null" and the merchant lost sight of the OTHER lines too.
     *
     * THE ASYMMETRY WITH THE WISHLIST IS THE SCHEMA, measured rather than assumed. With foreign
     * keys enforced, deleting the product left BOTH cart lines (no FK on `carts.product_id`) and
     * CASCADE-DELETED the wishlist row (`wishlists.product_id` has `onDelete('cascade')`,
     * 2024_01_22_040531). So the null is live on the cart side and unreachable on the wishlist
     * side; the wishlist view keeps the same guard as defence, and says so rather than claiming a
     * repair it did not make.
     *
     * The surviving line is asserted too. A guard that rendered the fallback and dropped the rest
     * would satisfy a test that only looked for the fallback.
     */
    public function test_a_deleted_product_does_not_break_the_modal_or_hide_the_other_lines(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Cart');

        $customer = $this->makeCustomer($store->id);
        $doomed = $this->makeCartRow($store->id, $merchant->id, $customer->id, 'DoomedProduct');
        $this->makeCartRow($store->id, $merchant->id, $customer->id, 'SurvivingProduct');

        // Through Eloquent, so the model cache flushes -- a raw DB delete would leave the
        // relation serving the pre-delete row under CACHE_STORE=array.
        $doomed->product_data->delete();

        $response = $this->actingAs($merchant)->get('/abandon-carts-show/' . $customer->id);

        $response->assertStatus(200);
        $response->assertSee(__('Product no longer available'));
        $response->assertSee('SurvivingProduct');
        $response->assertDontSee('DoomedProduct');
    }

    /**
     * THE POSITIVE CONTROL for the two tests above. Without it, a handler that refused EVERYTHING
     * would satisfy both -- and `makeMerchant()` does not run `Utility::addNewData()`, which is
     * what grants `Show Cart` in production on every login, so "refused" is the default state of
     * this fixture rather than evidence of a working guard.
     */
    public function test_the_owner_can_read_their_own_abandoned_cart(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Show Cart');

        $customer = $this->makeCustomer($store->id);
        $this->makeCartRow($store->id, $owner->id, $customer->id, 'OwnVisibleCartItem');

        $this->actingAs($owner)->get('/abandon-carts-show/' . $customer->id)
            ->assertStatus(200)
            ->assertSee('OwnVisibleCartItem');
    }
}
