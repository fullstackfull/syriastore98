<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F004 -- A GUEST'S BASKET HAD TWO IDENTITIES, AND ADDING TO IT THREW THE OLDER ONE AWAY.
 * =============================================================================================
 *
 * THE DEFECT, measured. Every READER of a guest basket keys it on the `cart` COOKIE:
 * `CartController::cart_remove` (:135), `cartClear` (:170), `change_cart` (:189),
 * `Cart::cart_list_cookie` (:243), `PaymentService`, the gateway return handlers and
 * `CustomerLoginController`'s sign-in migration. The one live WRITER keyed it on
 * `session()->getId()` instead -- `CartController::product_cartlist` read the existing line with
 * `Cart::forGuestCookie(session()->getId())`, stamped `carts.cookie_session_id` with it, and then
 * OVERWROTE the `cart` cookie with it.
 *
 * Those two agree only while the session id holds still. `config/session.php` gives a session
 * **120 minutes**; the cart cookie is queued for **1440**. So for twenty-two hours of every day
 * the cookie outlives the session that minted its value, and the shopper who comes back the next
 * morning sees this:
 *
 *   1. the basket page reads the COOKIE, finds the old rows, and shows the basket -- correctly;
 *   2. they add one more product;
 *   3. the writer asks for `session()->getId()`, which is now a NEW id, finds no line under it,
 *      creates a row under the new id and queues the new id as the `cart` cookie;
 *   4. every earlier line is now orphaned: no reader will ever name that identifier again, the
 *      rows are invisible to the shopper and cannot be bought, and nothing deletes them.
 *
 * To the shopper, adding an item emptied their basket. To the merchant, it is an abandoned basket
 * that the shopper swears they did not abandon.
 *
 * WHAT THE FIX IS. One resolver, `App\Support\Commerce\GuestBasketIdentity::forRequest()`, used by
 * the writer: REUSE the identifier the request's own `cart` cookie carries, and fall back to
 * `session()->getId()` only when there is no usable cookie. That is not a new convention -- it is
 * the one the four readers have always used; the writer was the odd one out.
 *
 * WHAT THIS DOES NOT CHANGE, stated carefully because the first draft of this paragraph said it
 * too loosely. The guest-basket identifier is a BEARER VALUE in a cookie -- but `cart` is
 * ENCRYPTED (`bootstrap/app.php` declares no `encryptCookies(except:)`) and the plaintext is
 * name-bound, so a party can REPLAY a cookie the server issued, not MINT one naming a value they
 * chose. The identifier space is unchanged by this fix. What a replayed cookie already reaches,
 * on routes with no auth middleware, is the entire basket: `cartClear` (:171) runs
 * `forGuestCookie($cookie)->delete()` with no store predicate at all, `cart_remove` (:136)
 * deletes a line, `Cart::cart_qty_cookie` (:809) sets any line's quantity, and `PaymentController`
 * (:2134) charges for it. Reading the same cookie on the way IN adds one thing -- "and append a
 * line that was not there" -- beside "and empty all of it". Making the identifier unguessable,
 * and separate from the session id it is borrowed from, is the real answer and a SEPARATE change,
 * because it has to migrate live rows; `GuestBasketIdentity` is where it would go.
 *
 * THE TWO VENDORED TWINS ARE NOT FIXED HERE. `packages/workdo/FrequentlyProduct` (:100, :117) and
 * `packages/workdo/BoostSales` (:67-98) both repeat the defect in vendored code, which this
 * project does not edit -- and both carry the same typo, `if (empty($session_id) || $session_id
 * != 'null')`, whose `!=` is true for every real cookie, so the cookie read inside them is dead
 * and they ALWAYS mint a session id. With either module active, one add through its route renames
 * the basket again. Recorded on the P4-F004 row rather than patched; `ModuleCartWriteGuard`
 * already intercepts both actions and is where a host-side guard would go, which is the owner's
 * call and not part of this fix. The plural, and BoostSales, came from the independent review --
 * this file said "THE VENDORED TWIN", singular.
 */
class GuestBasketIdentityTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A 40-character alphanumeric value: the shape `session()->getId()` has produced for every row in `carts`. */
    private function identifier(string $tag): string
    {
        return substr(str_pad($tag . str_repeat('x', 40), 40, 'x'), 0, 40);
    }

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'gbi-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$merchant, $store];
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'gbi-' . $this->uniqueFixtureRef(), 'slug' => 'gbi-' . $this->uniqueFixtureRef(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function guestRow($store, Product $product, string $identifier): void
    {
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id, 'cookie_session_id' => $identifier,
        ]);
    }

    /**
     * Rows under one identifier, read WITHOUT the global scope and WITHOUT the model cache.
     *
     * `Cart` is in `config/tenancy.php`'s `scoped_models`, so a plain `Cart::where(...)` answers
     * against whichever tenant happens to be resolved rather than against the table -- which is
     * how an "it is gone" assertion passes whether or not the fix works. `disableCache()` because
     * `Cart` is `Cachable` and the request above wrote through a different connection path.
     */
    private function rowsUnder(string $identifier): int
    {
        return (int) Cart::withoutGlobalScopes()->disableCache()
            ->where('cookie_session_id', $identifier)->count();
    }

    /**
     * The `cart` cookie the response queues, DECRYPTED.
     *
     * Cookies leave this application encrypted -- `bootstrap/app.php` declares no `except` list, so
     * Laravel 11's default `EncryptCookies` covers every cookie including this one. Reading
     * `$cookie->getValue()` off the response therefore yields the ciphertext, which equals no
     * identifier and would make an assertion here fail for a reason that has nothing to do with
     * the basket. `TestResponse::getCookie()` decrypts and strips the value prefix.
     */
    private function queuedBasketCookie(\Illuminate\Testing\TestResponse $response): ?string
    {
        $cookie = $response->getCookie('cart');

        return $cookie === null ? null : (string) $cookie->getValue();
    }

    /**
     * The same rows as `rowsUnder()`, but reached through the SHIPPED READER SCOPE.
     *
     * `Cart::forGuestCookie()` compiles to `whereRaw('1 = 0')` for anything that is not a
     * non-empty string, so this answers 0 for an identifier a reader would refuse even when the
     * column literally holds that value.
     */
    private function readableUnder(string $identifier): int
    {
        return (int) Cart::withoutGlobalScopes()->disableCache()
            ->forGuestCookie($identifier)->count();
    }

    private function addToCart($store, Product $product, array $cookies = []): \Illuminate\Testing\TestResponse
    {
        $request = $this;

        foreach ($cookies as $name => $value) {
            $request = $request->withCookie($name, $value);
        }

        return $request->post('/' . $store->slug . '/product_cart', [
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => 1,
        ]);
    }

    /**
     * THE DEFECT ITSELF. A basket that already holds one line, a cookie that names it, and a
     * session id that does not -- exactly the state of every guest who comes back after two hours.
     */
    public function test_adding_a_product_does_not_orphan_the_lines_already_in_the_basket(): void
    {
        [, $store] = $this->tenant();
        $first = $this->productIn($store);
        $second = $this->productIn($store);

        $basket = $this->identifier('gbi-basket-');
        $this->guestRow($store, $first, $basket);

        $response = $this->addToCart($store, $second, ['cart' => $basket]);

        $response->assertOk();
        $this->assertSame(1, (int) $response->json('status'),
            'Premise: the add was refused (' . substr((string) $response->getContent(), 0, 300) . '), so nothing below is about the identity.');

        $this->assertSame(2, $this->rowsUnder($basket),
            'The product just added was filed under a different identifier from the basket it was added to, so the earlier line is orphaned: the shopper sees their basket emptied.');
    }

    /** The response must also COUNT both lines -- the number the storefront badge shows. */
    public function test_the_basket_count_returned_to_the_shopper_includes_the_lines_already_there(): void
    {
        [, $store] = $this->tenant();
        $first = $this->productIn($store);
        $second = $this->productIn($store);

        $basket = $this->identifier('gbi-count-');
        $this->guestRow($store, $first, $basket);

        $response = $this->addToCart($store, $second, ['cart' => $basket]);

        $this->assertSame(2, (int) $response->json('data.count'),
            'The badge reported only the line just added, which is what the shopper reads as "my basket was emptied".');
    }

    /**
     * ADDING MUST NOT RE-NAME THE BASKET. The writer queues the `cart` cookie on every add; if it
     * queues a different value, the next reader looks somewhere else and the orphaning is back
     * one request later even when the row above was written correctly.
     */
    public function test_adding_a_product_does_not_change_the_baskets_identity(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);

        $basket = $this->identifier('gbi-keep-');
        $this->guestRow($store, $this->productIn($store), $basket);

        $response = $this->addToCart($store, $product, ['cart' => $basket]);

        $queued = $this->queuedBasketCookie($response);

        $this->assertNotNull($queued, 'The add queued no `cart` cookie at all.');
        $this->assertSame($basket, $queued,
            'The add renamed the basket, so the next request reads an identifier that names only the newest line.');
    }

    /**
     * THE FALLBACK IS PRESERVED. A first-time visitor has no `cart` cookie; the identifier then
     * comes from the session, exactly as it did before, and one row is written under it.
     *
     * TWO ASSERTIONS, NOT ONE, AND THE SECOND IS THE POINT. "The row carries the value the shopper
     * was handed" is satisfied by an EMPTY identifier -- the row's column is empty and the cookie
     * is empty, so they match and the test passes while every basket in the store collapses into
     * one unreadable bucket. Measured: a mutation that removed the session fallback and returned
     * `''` survived exactly that assertion. So the second assertion reads the row back through
     * `forGuestCookie()`, the scope every shipped reader uses, which compiles to `1 = 0` for an
     * unusable identifier -- the identifier has to be one a READER will accept, not merely one the
     * writer happened to echo.
     */
    public function test_a_guest_with_no_basket_cookie_is_given_one_a_reader_can_use(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);

        $response = $this->addToCart($store, $product);

        $queued = $this->queuedBasketCookie($response);

        $this->assertNotNull($queued, 'A first-time visitor was given no basket identifier, so their basket is unreadable on the next request.');
        $this->assertSame(1, $this->rowsUnder($queued),
            'The row was not filed under the identifier the shopper was handed.');
        $this->assertSame(1, $this->readableUnder($queued),
            'The identifier the shopper was handed names no basket when a reader uses it, so their next request starts an empty one and this line is orphaned.');
    }

    /**
     * A COOKIE IS CLIENT DATA. `carts.cookie_session_id` is a `string` column and the value is
     * written into it, so a value that is not a plausible identifier must be refused rather than
     * stored -- otherwise any caller decides what goes in that column, including something far
     * longer than it holds. Refusal here means "fall back to the session", never "fail the add":
     * a shopper with a mangled cookie still gets their product.
     *
     * ONE DATA ROW IS SOUND ONLY ON SQLITE, disclosed rather than left to be found: on production
     * MySQL in non-strict mode a 600-character value WOULD be truncated to the column's 255, so
     * `rowsUnder($cookie)` answers 0 whether or not the cookie was written and that row's second
     * assertion passes vacuously. It still bites under the suite (SQLite stores it whole) and the
     * `status === 1` assertion is live on every engine. Raised by the independent review.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('implausibleIdentifiers')]
    public function test_an_implausible_basket_cookie_is_not_written_into_the_column(string $label, string $cookie): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);

        $response = $this->addToCart($store, $product, ['cart' => $cookie]);

        $this->assertSame(1, (int) $response->json('status'),
            $label . ': the add was refused rather than falling back to the session.');
        $this->assertSame(0, $this->rowsUnder($cookie),
            $label . ': the cookie was written into carts.cookie_session_id verbatim.');
    }

    /**
     * THE BADGE IS THIS STORE'S, AND THE IDENTIFIER IS NOT.
     *
     * One `cart` cookie spans every storefront on the host (`config/session.php`'s path is `/`,
     * and stores are path-prefixed), so one identifier legitimately names lines in more than one
     * store. The row lookup and the write were always store-scoped; the COUNT returned to the
     * shopper was not, so a shopper who had visited store B saw B's lines in store A's badge.
     *
     * The other tests in this file cannot see it -- they put every line in one store -- which is
     * why this one exists and why the defect survived them. Pre-existing, but the fix above makes
     * the identifier last the cookie's 24 hours instead of the session's two, so it is closed in
     * the same change. Found by the independent review of this fix.
     */
    public function test_the_basket_badge_counts_only_the_store_the_shopper_is_in(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();

        $here = $this->productIn($storeA);
        $elsewhere = $this->productIn($storeB);

        $basket = $this->identifier('gbi-two-stores-');
        $this->guestRow($storeB, $elsewhere, $basket);

        $response = $this->addToCart($storeA, $here, ['cart' => $basket]);

        $this->assertSame(1, (int) $response->json('status'),
            'Premise: the add was refused, so the count below is not about store scoping.');
        $this->assertSame(2, $this->rowsUnder($basket),
            'Premise: both lines really do share one identifier across the two stores.');

        $this->assertSame(1, (int) $response->json('data.count'),
            'The badge counted a line the shopper left in a DIFFERENT store, so this store\'s basket reports more items than it holds.');
    }

    /**
     * THE PREMISE THE FALLBACK RESTS ON, pinned so a framework change cannot undo the fix quietly.
     *
     * `forRequest()` falls back to `session()->getId()` when there is no usable cookie, and the
     * value it returns is then queued AS the cookie. If the shipped session id ever stopped
     * matching the shape `isUsable()` accepts, the fallback would hand out an identifier that the
     * NEXT request rejects -- and the writer would be back to minting a new identity per request,
     * which is exactly the orphaning this class exists to stop, with every test above still green.
     * Laravel's id is `Str::random(40)` today; this fails loudly if that changes.
     */
    public function test_the_shipped_session_id_is_itself_a_usable_basket_identifier(): void
    {
        $id = session()->getId();

        $this->assertNotSame('', $id, 'Premise: the test session has no id, so nothing below is measured.');
        $this->assertTrue(\App\Support\Commerce\GuestBasketIdentity::isUsable($id),
            'The session id (' . strlen($id) . ' characters) no longer fits the shape a basket identifier may have, so the fallback hands out a value the next request refuses.');
    }

    public static function implausibleIdentifiers(): array
    {
        return [
            'far longer than the column' => ['an over-long cookie', str_repeat('a', 600)],
            'too short to be an identifier' => ['a three-character cookie', 'abc'],
            'punctuation an id never carries' => ['a cookie carrying a quote', str_repeat('a', 20) . "'" . str_repeat('b', 19)],
            /*
             * A TRAILING NEWLINE, because PHP's `$` matches BEFORE a final `\n` unless the `D`
             * modifier is set. The first version of `SHAPE` ended in `$`, so a 20-character value
             * with a newline on the end was "usable" and would have been stored verbatim. Found by
             * the independent review; the bound now ends in `\z`.
             */
            'a trailing newline the anchor used to admit' => ['a cookie ending in a newline', str_repeat('a', 30) . "\n"],
        ];
    }
}
