<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F005 -- `quantity_type=remove` ON THE GUEST BASKET ANSWERED "DONE" AND DELETED NOTHING.
 * =============================================================================================
 *
 * `POST {storeSlug}/change-cart` takes three quantity types, and the validator in
 * `Cart::cart_qty_cookie()` advertises all three: `required|in:increase,decrease,remove`. Its
 * guest arm implemented two of them. The third read:
 *
 *     if ($request->quantity_type == 'remove') {
 *         unset($cart_array->$cart_id);      // $cart_array is an Eloquent Cart MODEL
 *     }
 *     $cart_array->save();
 *
 * `$cart_array` is the basket ROW, not an array of rows, and `$cart_id` is the request's own
 * `cart_id` value -- so this unsets a DYNAMIC PROPERTY named, say, `7` on that model. The row
 * holds no such attribute, nothing is marked dirty, and `Model::save()` is gated on `isDirty()`
 * (`Illuminate/Database/Eloquent/Model.php:1155`), so it never reaches `performUpdate`. The
 * handler then answers `status: 1` with `data.message = 'Cart successfully updated.'` -- which
 * `public/assets/js/front-theme.js:872-878` toasts as a Success -- and returns a basket count that
 * still counts the line, while the row sits in `carts` untouched.
 *
 * THE SIGNED-IN TWIN IS CORRECT, which is what makes this an omission rather than a design: the
 * logged-in arm of `CartController::change_cart` (:189) hands the request to
 * `ApiController::cart_qty()`, whose remove branch deletes the row and returns from inside the
 * branch so the trailing `save()` cannot resurrect it (ApiController.php:2023-2029 in the shape
 * this file's fix copies).
 *
 * SEVERITY IS LOW AND THE REGISTER SAYS SO: no shipped client emits `remove` on this route
 * (`resources/views/front_end/pages/cart-list.blade.php:61,65`,
 * `cart-list-sidebar.blade.php:34,43` and `public/assets/js/front-theme.js:846` only ever send
 * `increase` / `decrease`), and the real guest removal route `POST {storeSlug}/cart-remove`
 * (`CartController::cart_remove`, :129) works and is covered by
 * `tests/Feature/Security/NullCookieCartTest.php`. What was broken is a value the validator
 * accepts: any caller -- or any future client, or a theme that copies the API's vocabulary -- that
 * sends it is told the line was removed when it was not.
 *
 * EVERY CART ASSERTION HERE READS THE RAW TABLE. `Cart` is in `config/tenancy.php`'s
 * `scoped_models`, so a plain `Cart::find()` answers against whichever tenant happened to resolve
 * rather than against the table -- an "it is gone" assertion then passes whether or not the fix
 * works, which has already cost this project a false reading on a cart test.
 */
class GuestCartRemoveTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A 40-character alphanumeric value: the shape `session()->getId()` produces and every row in `carts` carries. */
    private function identifier(string $tag): string
    {
        return substr(str_pad($tag . str_repeat('x', 40), 40, 'x'), 0, 40);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'gcr-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
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
            'name' => 'gcr-' . $this->uniqueFixtureRef(), 'slug' => 'gcr-' . $this->uniqueFixtureRef(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function guestRow($store, Product $product, string $identifier, int $qty = 2): Cart
    {
        return Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => 10.00, 'store_id' => $store->id, 'cookie_session_id' => $identifier,
        ]);
    }

    private function changeCart($store, string $identifier, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withCookie('cart', $identifier)
            ->post('/' . $store->slug . '/change-cart', $payload);
    }

    /** The row, by id, through the raw table: no global scope and no model cache. */
    private static function rowExists(int $id): bool
    {
        return DB::table('carts')->where('id', $id)->exists();
    }

    private static function qty(int $id): ?int
    {
        $qty = DB::table('carts')->where('id', $id)->value('qty');

        return $qty === null ? null : (int) $qty;
    }

    /**
     * THE DEFECT ITSELF, through the real route.
     *
     * The `status === 1` assertion is load-bearing twice over. It is the PREMISE -- without it a
     * silent early return ('Product not found.', status 0) would masquerade as a pass, which is how
     * a test in this exact function passed for the wrong reason once before
     * (`StockManagementSettingTest.php:563-585`: a `variant_id` that routed control past the line
     * the test targeted). And it is half the finding: the handler does not merely fail to remove
     * the line, it reports that it removed it.
     */
    public function test_a_guest_removing_a_line_through_change_cart_really_removes_it(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $basket = $this->identifier('gcr-remove-');
        $row = $this->guestRow($store, $product, $basket);

        $response = $this->changeCart($store, $basket, [
            'cart_id' => $row->id,
            'product_id' => $product->id,
            'variant_id' => 0,
            'quantity_type' => 'remove',
        ]);

        $response->assertOk();
        $this->assertSame(1, (int) $response->json('status'),
            'Premise: the handler refused before the remove branch (' . substr((string) $response->getContent(), 0, 300) . '), so nothing below is about removal.');

        $this->assertFalse(self::rowExists($row->id),
            'change-cart accepted quantity_type=remove, answered status 1 with "Cart successfully updated.", and left the row in carts.');
        $this->assertSame(0, (int) $response->json('count'),
            'The basket count returned to the shopper still counts the line that was supposedly removed.');
    }

    /**
     * CONTROL AGAINST OVER-DELETING -- a fix that empties the basket, or every basket, passes the
     * test above. Two more lines exist: one of this guest's, for a different product, and one
     * belonging to a DIFFERENT guest cookie in the same store.
     */
    public function test_the_remove_takes_only_the_line_it_was_asked_for(): void
    {
        [, $store] = $this->tenant();
        $target = $this->productIn($store);
        $keep = $this->productIn($store);

        $basket = $this->identifier('gcr-scope-');
        $other = $this->identifier('gcr-other-');

        $row = $this->guestRow($store, $target, $basket);
        $mine = $this->guestRow($store, $keep, $basket);
        $theirs = $this->guestRow($store, $target, $other);

        $response = $this->changeCart($store, $basket, [
            'cart_id' => $row->id,
            'product_id' => $target->id,
            'variant_id' => 0,
            'quantity_type' => 'remove',
        ]);

        $this->assertSame(1, (int) $response->json('status'), 'Premise: the remove was refused.');
        $this->assertFalse(self::rowExists($row->id), 'Premise: the named line was not removed, so the controls below prove nothing.');

        $this->assertTrue(self::rowExists($mine->id), 'The remove also took this basket\'s other line.');
        $this->assertTrue(self::rowExists($theirs->id), 'The remove reached ANOTHER guest\'s basket in the same store.');
        $this->assertSame(1, (int) $response->json('count'),
            'The count after removing one of two lines is not the one line that is left.');
    }

    /**
     * CONTROL: the two quantity types that DID work still work, and the row is still there
     * afterwards. A fix that deletes on every quantity type would pass both tests above.
     */
    public function test_increase_and_decrease_still_change_the_quantity_without_removing_the_line(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $basket = $this->identifier('gcr-qty-');
        $row = $this->guestRow($store, $product, $basket, 2);

        $increase = $this->changeCart($store, $basket, [
            'cart_id' => $row->id, 'product_id' => $product->id, 'variant_id' => 0, 'quantity_type' => 'increase',
        ]);

        $this->assertSame(1, (int) $increase->json('status'), 'Premise: the increase was refused.');
        $this->assertTrue(self::rowExists($row->id), 'An increase deleted the basket line.');
        $this->assertSame(3, self::qty($row->id), 'The increase did not raise the stored quantity.');

        $decrease = $this->changeCart($store, $basket, [
            'cart_id' => $row->id, 'product_id' => $product->id, 'variant_id' => 0, 'quantity_type' => 'decrease',
        ]);

        $this->assertSame(1, (int) $decrease->json('status'), 'Premise: the decrease was refused.');
        $this->assertTrue(self::rowExists($row->id), 'A decrease deleted the basket line.');
        $this->assertSame(2, self::qty($row->id), 'The decrease did not lower the stored quantity.');
    }

    /**
     * THE REFUSAL KEEPS ITS REASON. A `remove` naming a line that is not in THIS cookie's basket
     * must answer "Product not found." with status 0 and delete nothing -- the shape P1-SEC-24
     * wrote for a cookie that names no basket. A fix that deletes by `cart_id` rather than through
     * the guest's own basket would turn this into a cross-basket delete by sequential id.
     */
    public function test_a_remove_naming_another_guests_line_is_refused_and_deletes_nothing(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);

        $mine = $this->identifier('gcr-mine-');
        $other = $this->identifier('gcr-victim-');

        $theirs = $this->guestRow($store, $product, $other);

        $response = $this->changeCart($store, $mine, [
            'cart_id' => $theirs->id,
            'product_id' => $product->id,
            'variant_id' => 0,
            'quantity_type' => 'remove',
        ]);

        $response->assertOk();
        $this->assertSame(0, (int) $response->json('status'), 'A caller whose cookie names no basket was answered "removed".');
        $this->assertSame('Product not found.', (string) $response->json('data.message'),
            'The refusal lost its reason, so this case no longer distinguishes "not yours" from any other failure.');
        $this->assertTrue(self::rowExists($theirs->id), 'Another guest\'s line was deleted by its id.');
    }
}
