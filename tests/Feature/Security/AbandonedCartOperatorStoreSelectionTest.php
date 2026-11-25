<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-077 / GAP-223(b) — THE ABANDONED-CART SCREEN'S FOUR OTHER LEGS STILL SERVED STORE 1.
 * =============================================================================================
 *
 * `getCurrentStore()` ends in a HARD-CODED LITERAL 1 for a user whose `type` is `'super admin'`
 * and whose `current_store` is empty (`app/Helper/helper.php:888-889`). Store 1 is not a platform
 * scope — it is an ordinary merchant's tenant. The owner's rule, recorded at
 * `OperatorStoreSelection:17-19` on 2026-11-21, is that such a principal must NEVER default to
 * viewing store 1's tenant data.
 *
 * THE LIST LEG WAS FIXED AND ITS FOUR SIBLINGS WERE NOT, so the two halves of one screen
 * disagreed. `abandon_carts_handled` goes through `MarketingScreenAccess::refusal()`, which
 * returns `NO_STORE` for exactly this principal and is pinned by
 * `MarketingScreenOperatorAccessTest:87-101`. The other four legs each carry an `empty($storeId)`
 * guard instead — and the literal 1 is not empty, so the guard cannot fire for an unselected
 * operator and every one of them proceeded against store 1:
 *
 *     abandon_carts_show       READ     served store 1's cart lines
 *     abandon_carts_destroy    DELETE   deleted store 1's cart rows, and has no store guard at all
 *     abandon_carts_emailsend  SEND     emailed store 1's customer about store 1's products
 *     abandon_carts_messsend   SEND     texted them
 *
 * `ACCOUNT-HANDOFF.md:102` (open item 84) worried that fixing only the show handler would make the
 * two legs disagree. That has happened IN REVERSE: the list leg was fixed and the show leg was
 * not.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT THESE TESTS DELIBERATELY DO NOT ASSERT, because it is the owner's and not engineering's.
 *
 * NOT whether the operator should be ADMITTED to these legs. `MarketingScreenAccess` has an
 * operator PASS — `! $isOperator && ! $user->isAbleTo()` — and its own docblock says it is READ
 * ONLY and that "admitting the operator to a list is not admitting them to an edit". Using it on
 * a destructive or sending leg would hand the operator a write they do not hold today. So the fix
 * under test adds the REFUSAL half only, and every permission check is left exactly as it was:
 * every outcome is narrower than the literal 1 it replaces, which is what the owner's recorded
 * rule authorises.
 *
 * ---------------------------------------------------------------------------------------------
 * THE PERMISSION IS GRANTED IN EVERY CASE, SO THE ASSERTIONS ARE NOT VACUOUS.
 *
 * On a database built from source the operator holds NEITHER `Show Cart` nor `Delete Cart`:
 * `Utility::addNewData()` CREATES those permission rows (`app/Models/Utility.php:3071-3073`) and
 * `$superadminNewPermission` grants the operator neither, so the default answer is "Permission
 * denied." That refusal has nothing to do with the store, and a test that relied on it would
 * report this defect as fixed while it was wide open. `grantPermission()` does exactly what the
 * role editor does, which is how a real installation reaches the state under test.
 */
class AbandonedCartOperatorStoreSelectionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The customer id whose cart lives in store 1, and the store-1 product name it holds. */
    private const SECRET = 'StoreOneSecretItem';

    private int $storeOneCustomerId;

    /**
     * ONE CART LINE IN STORE 1, created the way the application does.
     *
     * The first store created takes `stores.id = 1`, which is the whole hazard: the literal the
     * helper returns is a real merchant's tenant.
     */
    private function storeOneHoldsAnAbandonedCart(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->assertSame(1, (int) $store->id, 'premise: the first store created is not store 1, so '
            . 'the literal getCurrentStore() returns for an unselected operator is not this store');

        /* `forceCreate` with the full column set, and `customers` has first_name/last_name and NOT
           `name` -- both read from the live schema after an insert failed on them, which is the
           pattern `MerchantSearchAuthorizationTest` already records for this database. */
        $customer = Customer::forceCreate([
            'first_name' => 'Store One',
            'last_name' => 'Shopper',
            'email' => 'shopper-' . uniqid() . '@store-one.test',
            'password' => bcrypt('secret-shopper'),
            'store_id' => $store->id,
        ]);

        $product = Product::forceCreate([
            'name' => self::SECRET,
            'slug' => 'store-one-secret-item-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 0,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 5,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            /* `qty`, not `quantity`, and no `created_by` -- the live `carts` schema
               (2024_01_18_103138) carries customer_id, product_id, variant_id, qty, price,
               store_id and timestamps, and nothing else. */
            'qty' => 2,
            'price' => '10.00',
            'store_id' => $store->id,
        ]);

        $this->storeOneCustomerId = (int) $customer->id;
    }

    /** How many cart rows store 1 holds, read past the global scope. */
    private function storeOneCartRows(): int
    {
        return (int) DB::table('carts')->where('store_id', 1)->count();
    }

    // ═════════════════════════════════════════════════════════════════════════════ THE READ LEG

    public function test_an_operator_with_no_store_selected_is_not_served_store_ones_abandoned_cart(): void
    {
        $this->storeOneHoldsAnAbandonedCart();

        $operator = $this->makePlatformAdmin(['current_store' => null]);
        $this->grantPermission($operator, 'Show Cart');

        $response = $this->actingAs($operator)->get('/abandon-carts-show/' . $this->storeOneCustomerId);

        $this->assertNotSame(200, $response->status(), 'an operator who chose no store was served a '
            . 'cart screen; getCurrentStore() gave them the literal 1, so this is store 1');

        $response->assertDontSee(self::SECRET, false);

        /* AND IT IS THE STORE GUARD THAT ANSWERED, not the permission check. `assertNotSame(200)`
           alone is satisfied by 'Permission denied.' just as well, which is the named trap in this
           codebase -- so the reason is pinned. The permission is granted above, so this cannot be
           the 403 branch today, but the assertion must not depend on that staying true. */
        $response->assertSessionHas('error', 'No store is selected.');
    }

    /** CONTROL: the same operator, having chosen store 1, is served it. The guard is about the CHOICE. */
    public function test_an_operator_who_has_chosen_the_store_is_still_served_it(): void
    {
        $this->storeOneHoldsAnAbandonedCart();

        $operator = $this->makePlatformAdmin(['current_store' => 1]);
        $this->grantPermission($operator, 'Show Cart');

        $response = $this->actingAs($operator)->get('/abandon-carts-show/' . $this->storeOneCustomerId);

        $this->assertSame(200, $response->status(), 'the refusal fired for an operator who HAD chosen '
            . 'a store, so it is refusing on something other than the unmade choice');
        $response->assertSee(self::SECRET, false);
    }

    // ══════════════════════════════════════════════════════════════════════ THE DESTRUCTIVE LEG

    /**
     * THE ONE THAT MATTERS MOST: this leg has no store guard of any kind, and it DELETES.
     *
     * `Store::find(getCurrentStore())` resolves store 1 and `$store->id` is then used as the
     * predicate, so an unselected operator holding `Delete Cart` destroyed a real merchant's
     * abandoned carts — measured as 1 row -> 0 before the fix.
     */
    public function test_an_operator_with_no_store_selected_cannot_delete_store_ones_carts(): void
    {
        $this->storeOneHoldsAnAbandonedCart();

        $operator = $this->makePlatformAdmin(['current_store' => null]);
        $this->grantPermission($operator, 'Delete Cart');

        $this->assertSame(1, $this->storeOneCartRows(), 'premise: store 1 holds no cart row to lose');

        $this->actingAs($operator)->delete('/abandon-carts-destroy/' . $this->storeOneCustomerId);

        /* Read past the global scope: `Cart` IS in `scoped_models`, so a plain `Cart::count()`
           answers against whichever tenant happens to be resolved and would pass either way. */
        $this->assertSame(1, $this->storeOneCartRows(),
            'an operator who chose no store deleted store 1\'s abandoned cart');

        /* The row count alone also stays at 1 under a permission refusal -- pin the reason. */
        $this->assertSame('No store is selected.', session('error'));
    }

    /** CONTROL: having chosen the store, the same operator may still clear it. */
    public function test_an_operator_who_has_chosen_the_store_may_still_clear_it(): void
    {
        $this->storeOneHoldsAnAbandonedCart();

        $operator = $this->makePlatformAdmin(['current_store' => 1]);
        $this->grantPermission($operator, 'Delete Cart');

        $this->actingAs($operator)->delete('/abandon-carts-destroy/' . $this->storeOneCustomerId);

        $this->assertSame(0, $this->storeOneCartRows(),
            'the refusal fired for an operator who HAD chosen the store, so the screen is now broken '
            . 'for its legitimate user');
    }

    /**
     * AND THE SAME LEG CRASHED FOR A STORELESS MERCHANT, which is the other half of having no store
     * guard at all. `getCurrentStore()` is NULL for a merchant who has created no store -- the
     * fallback keys on `created_by = $user->id` -- so `Store::find(null)` is null and `$store->id`
     * is a read on null: HTTP 500 where a refusal belongs.
     *
     * PRE-EXISTING AND NOT INTRODUCED BY THE OPERATOR GUARD, but this is the one leg of the four
     * whose siblings all carry an `empty($storeId)` check, so the parity is completed here rather
     * than left as the remaining invitation to the same mistake. A 500 is also the shape that lets
     * a regression test pass for the wrong reason, which `abandon_carts_emailsend`'s own docblock
     * already records about this family.
     */
    public function test_a_merchant_with_no_store_is_refused_rather_than_crashing(): void
    {
        $this->storeOneHoldsAnAbandonedCart();

        /*
         * AN ORPHANED NULL-STORE CART ROW, and it is the whole point of this assertion rather than
         * set dressing. `carts.store_id` is nullable, so the tempting one-character fix --
         * `Store::find(…)?->id` -- compiles the predicate to `store_id IS NULL` and DELETES every
         * orphaned row in the table for a caller who resolves no store. Without this row the test
         * passes against that fix, which is why the tenant-authz review asked for it by name.
         * `report-null-store-orders.php` exists because such rows are real.
         */
        DB::table('carts')->insert([
            'customer_id' => $this->storeOneCustomerId,
            'product_id' => 999,
            'qty' => 1,
            'price' => 5,
            'store_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storeless = $this->makeMerchant();
        $this->grantPermission($storeless, 'Delete Cart');

        $response = $this->actingAs($storeless)->delete('/abandon-carts-destroy/' . $this->storeOneCustomerId);

        $this->assertNotSame(500, $response->status(),
            'a merchant with no store crashed the destroy leg; Store::find(null)->id is a read on null');
        $this->assertSame(1, $this->storeOneCartRows(), 'store 1 lost a cart row to a storeless merchant');
        $this->assertSame(1, (int) DB::table('carts')->whereNull('store_id')->count(),
            'the orphaned null-store cart rows were deleted: the predicate became `store_id IS NULL`');
    }

    /**
     * THE PRINCIPAL THAT MAKES `! $store` LOAD-BEARING: an operator whose selected store is GONE.
     *
     * `current_store` is a non-empty id, so `empty($storeId)` passes AND `unchosen()` is false --
     * the operator did choose, the store just no longer exists. `Store::find()` then returns null.
     *
     * AND FOR THIS PRINCIPAL NOTHING ELSE CONTAINS IT. `StoreScope::apply()` returns with no
     * predicate at all for an operator (`MODE_SUPER_ADMIN`), so `$store?->id` really would compile
     * the delete to `store_id IS NULL` and destroy every orphaned cart row in the table, across all
     * tenants. For a storeless MERCHANT the scope does contain it, which is why that test cannot
     * measure this and this one can.
     */
    public function test_an_operator_whose_selected_store_no_longer_exists_deletes_nothing(): void
    {
        $this->storeOneHoldsAnAbandonedCart();

        DB::table('carts')->insert([
            'customer_id' => $this->storeOneCustomerId,
            'product_id' => 998,
            'qty' => 1,
            'price' => 5,
            'store_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $missingStoreId = ((int) DB::table('stores')->max('id')) + 500;
        $this->assertNull(DB::table('stores')->where('id', $missingStoreId)->first(), 'premise: that store exists');

        $operator = $this->makePlatformAdmin(['current_store' => $missingStoreId]);
        $this->grantPermission($operator, 'Delete Cart');

        $response = $this->actingAs($operator)->delete('/abandon-carts-destroy/' . $this->storeOneCustomerId);

        $this->assertNotSame(500, $response->status(), 'the destroy leg crashed on a dangling current_store');
        $this->assertSame(1, (int) DB::table('carts')->whereNull('store_id')->count(),
            'the orphaned null-store cart rows were deleted: the predicate became `store_id IS NULL`, '
            . 'which is every tenant\'s orphaned rows at once');
        $this->assertSame(1, $this->storeOneCartRows(), 'store 1 lost a cart row');
    }

    // ═══════════════════════════════════════════════════════════════════════════ THE SEND LEGS

    /**
     * Both send legs reach a real customer of a real merchant. The email leg 500s downstream on a
     * null store for a storeless MERCHANT, which is what hid this; for the operator the store
     * resolves to 1 and the send proceeds, so the refusal has to come first.
     */
    public function test_an_operator_with_no_store_selected_cannot_contact_store_ones_shopper(): void
    {
        $this->storeOneHoldsAnAbandonedCart();

        $cartId = (int) DB::table('carts')->where('store_id', 1)->value('id');

        $operator = $this->makePlatformAdmin(['current_store' => null]);
        $this->grantPermission($operator, 'Abandon Cart');

        foreach (['/abandon-carts-emailsend', '/abandon-carts-messagesend'] as $path) {
            $response = $this->actingAs($operator)->post($path, ['cart_id' => $cartId]);

            $this->assertNotSame(200, $response->status(), sprintf(
                '%s accepted a cart id from an operator who chose no store; the cart resolves in '
                . 'store 1, so this would contact a real merchant\'s shopper about their products',
                $path
            ));

            /* AND IT IS THE STORE GUARD, NOT THE PERMISSION CHECK. These two legs answer 403
               'Permission denied.' for an unpermitted caller, which satisfies assertNotSame(200)
               identically -- the exact trap this project names. The permission is granted above, so
               404 is the only correct refusal here. */
            $response->assertStatus(404);
            $this->assertSame('No store is selected.', $response->json('message'), $path . ' refused for the wrong reason');
        }
    }
}
