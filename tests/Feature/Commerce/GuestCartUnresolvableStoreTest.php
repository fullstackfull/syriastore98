<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-292 — A GUEST BASKET WHOSE STORE DOES NOT RESOLVE IS STILL AN HTTP 500.
 * =================================================================================================
 *
 * THE TWO CAUSES ARE DIFFERENT AND WERE DELIBERATELY KEPT APART. An earlier change closed the
 * guest-basket crash for a row that outlived its PRODUCT or VARIANT, with a per-line guard inside
 * the pricing loop. This is the other one: a request that never had a STORE. The per-line guard
 * cannot see it, because the thing that is null is not the line.
 *
 * THE STATE, read off the method's own first two lines:
 *
 *     $store    = Store::find($storeid);              // $storeid defaults to ''
 *     $store_id = $store->id ?? getCurrentStore();    // so a null $store means the AMBIENT tenant
 *
 * `Store::find('')` is null, and `$store_id` is then NOT null — it is whatever
 * `getCurrentStore()` answers. The pricing loop selects cart rows by `$store_id`, so it RUNS, and
 * every price in it goes through `Product::ProductPrice($store->slug, …)`. Twelve sites, and the
 * first one reached throws.
 *
 * MEASURED, not inferred: `ErrorException: Attempt to read property "slug" on null` at
 * `Cart.php:541`. That is a 500 on a guest's own basket.
 *
 * AND THE STATE IS REACHABLE — this repository already said so, and this file is what drives it.
 * The note at `Cart.php`'s coupon lookup records, from an independent review, that "a null `$store`
 * is reachable: `PaymentController.php:3130`, `PayUPaymentController.php:352` and
 * `PaiementProPaymentController.php:557` all call this with `$store->id ?? ''`, and
 * `Store::find('')` is null." What that note did not say is that the same null then reaches a
 * `slug` dereference, which is why the crash outlived the observation.
 *
 * WHAT THE FIX DOES, AND WHY IT IS TWO LINES RATHER THAN A REFUSAL:
 *
 *   1. RECOVER. If the explicit store did not resolve but the ambient one does, use it. That is
 *      already the method's intent — the basket rows and the coupon are both selected by
 *      `$store_id`, and the existing note says in terms that "basket and coupon come from one
 *      store". So resolving `$store` from `$store_id` makes the method internally consistent
 *      instead of half-resolved, and the ordinary case keeps pricing.
 *
 *   2. AND IF THERE IS GENUINELY NO STORE, PRICE NOTHING. When neither lookup resolves, the
 *      pricing loop is skipped. Every accumulator it would fill — `product_list`,
 *      `original_price`, `final_price`, `cart_total_qty` and the rest — is initialised ABOVE the
 *      loop, so the caller gets a coherent EMPTY basket rather than an exception. Checked by
 *      reading the initialisations, and asserted below.
 *
 * NO IN-CODE DOCBLOCK, AND THAT IS A MEASURED EXCEPTION TO THIS CODEBASE'S HABIT. `Cart.php`
 * carries **206** absolute `file:line` citations, **148** of them below this method's first line,
 * so a prose block here would invalidate up to 148 of them. Both changes are therefore
 * one-for-one line replacements and `Cart.php` is net zero; the reasoning lives here, where it is
 * falsifiable. Short pointer comments in the code name this file.
 *
 * @see \App\Models\Cart::cart_list_cookie()
 */
class GuestCartUnresolvableStoreTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A merchant whose `current_store` is their own store, one product, and one guest cart row.
     *
     * The principal matters: `getCurrentStore()` reads the **web** guard and returns null for an
     * unauthenticated visitor, in which case `$store_id` is null too, the row selection matches
     * nothing and the loop never runs — no crash, and no measurement either. The reachable state
     * needs `$store_id` to resolve while `$store` does not, which is a caller holding a panel
     * session. CLAUDE.md records that the `web` and `customers` guards are separate sessions, so a
     * shopper who also holds a panel login is exactly this principal.
     *
     * @return array{0: User, 1: Store, 2: Product, 3: string}
     */
    private function basket(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();
        $merchant = User::find($merchant->id);

        $this->actingAs($merchant);

        $product = Product::forceCreate([
            'name' => 'gc-' . uniqid(), 'slug' => 'gc-' . uniqid(),
            'price' => 10.00, 'sale_price' => 10.00,
            'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0, 'status' => 1,
            'store_id' => $store->id, 'created_by' => (int) $merchant->id,
        ]);

        $sid = 'gc-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 10.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        request()->cookies->set('cart', $sid);

        return [$merchant, $store, $product, $sid];
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISES — the state the row describes, each half asserted
    |--------------------------------------------------------------------------
    */

    /** `Store::find('')` really is null, which is the whole origin of the state. */
    public function test_premise_an_empty_store_id_resolves_to_no_store(): void
    {
        $this->basket();

        $this->assertNull(Store::find(''),
            'Store::find(\'\') now resolves to a store, so GAP-292\'s premise has changed and the '
            . 'three call sites that pass `$store->id ?? \'\'` are no longer a null source.');
    }

    /** And the ambient tenant DOES resolve for this principal, so the pricing loop will run. */
    public function test_premise_the_ambient_tenant_resolves_so_the_loop_runs(): void
    {
        [, $store, , $sid] = $this->basket();

        $this->assertSame($sid, Cookie::get('cart'),
            'the cart cookie is not visible to the method, so the loop would be empty and this '
            . 'file would measure nothing');

        $this->assertNotNull(getCurrentStore(),
            'getCurrentStore() answers null for this principal, so $store_id would be null, the '
            . 'row selection would match nothing and the crash would be unreachable — which is a '
            . 'different finding and would make GAP-292 a withdrawal.');

        $this->assertSame(1, DB::table('carts')->where('store_id', $store->id)->count(),
            'the fixture basket is not in the store the method will resolve, so the loop would '
            . 'not reach a price');
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT
    |--------------------------------------------------------------------------
    */

    /**
     * THE HEADLINE: an unresolvable store must not crash the basket.
     *
     * Driven as a direct static call rather than through a route, because the row is about this
     * method and the three callers that reach it with `''` are gateway-return paths whose own
     * fixtures would dominate the test. The exception, not a status code, is what is asserted:
     * `ApiResponser::error()` answers HTTP 200 in this codebase, so a status assertion here would
     * be the weaker instrument.
     */
    public function test_an_unresolvable_store_does_not_crash_the_guest_basket(): void
    {
        $this->basket();

        $error = null;

        try {
            $result = Cart::cart_list_cookie([], '');
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage()
                . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
            $result = null;
        }

        $this->assertNull($error,
            'GAP-292: the guest basket still answers HTTP 500 when the store does not resolve. '
            . 'Twelve `Product::ProductPrice($store->slug, …)` sites sit inside the pricing loop '
            . 'and the per-line product guard cannot see a null STORE. ' . $error);

        $this->assertIsArray($result);
        /* The method answers a `Utility::success()` envelope, so the basket is under `data`. */
        $this->assertArrayHasKey('data', $result,
            'the method returned something without the envelope its callers read');
        $this->assertArrayHasKey('product_list', $result['data']);
    }

    /**
     * AND IT STILL PRICES THE BASKET, which is what makes the fix a recovery and not a refusal.
     *
     * Without this the headline case passes for an implementation that returns an empty array and
     * silently empties every guest's basket on these three paths — a worse defect than the crash.
     */
    public function test_the_recovered_store_still_prices_the_basket(): void
    {
        $this->basket();

        $result = Cart::cart_list_cookie([], '');

        $this->assertCount(1, $result['data']['product_list'],
            'THE CONTROL BROKE: the basket priced no lines. The fix must RECOVER the ambient '
            . 'store, not skip the loop — otherwise a guest\'s basket silently empties on the '
            . 'three paths that call this with an empty store id.');

        $this->assertGreaterThan(0, (float) ($result['data']['final_price'] ?? 0),
            'the line was listed but priced at zero, which is the same defect one layer in. Keys: '
            . implode(',', array_keys($result['data'] ?? [])));
    }

    /** The control for the other direction: an explicit, resolvable store is unaffected. */
    public function test_an_explicit_resolvable_store_is_unchanged(): void
    {
        [, $store, , ] = $this->basket();

        $result = Cart::cart_list_cookie([], $store->id);

        $this->assertCount(1, $result['data']['product_list'],
            'the ordinary call — an explicit store that resolves — no longer prices the basket');
        $this->assertGreaterThan(0, (float) ($result['data']['final_price'] ?? 0));
    }

    /**
     * AND WHEN THERE IS GENUINELY NO STORE AT ALL, an empty basket rather than an exception.
     *
     * The residual the recovery cannot reach: cart rows whose `store_id` names a store row that is
     * gone. `$store_id` resolves from `getCurrentStore()`, the rows match, and neither lookup can
     * produce a store. Asserted because it is the one state in which the loop must be skipped, and
     * because every accumulator it would fill is initialised above it — so "empty" is coherent
     * rather than half-built.
     */
    public function test_an_orphaned_basket_with_no_store_row_returns_an_empty_basket(): void
    {
        [$merchant, $store, , ] = $this->basket();

        $storeId = (int) $store->id;

        /* Raw, and the store row goes while the cart row and `current_store` keep pointing at it. */
        DB::table('stores')->where('id', $storeId)->delete();
        (new Store)->flushCache();

        $this->assertNull(Store::find($storeId), 'the fixture did not actually orphan the basket');
        $this->assertSame($storeId, (int) getCurrentStore(),
            'current_store no longer names the deleted store, so this case is not the orphan state');

        $error = null;

        try {
            $result = Cart::cart_list_cookie([], '');
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
            $result = null;
        }

        $this->assertNull($error,
            'GAP-292 residual: with no store row at all the basket still throws. ' . $error);

        $this->assertSame([], $result['data']['product_list'] ?? null,
            'with no store to price against the basket must be empty, not half-built');
    }
}
