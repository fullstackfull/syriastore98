<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-G FOLLOW-UP — the STOREFRONT wishlist accepted any tenant's product.
 * ================================================================================================
 *
 * `WishlistController::product_wishlist()` (`routes/customer.php:178`) validated the posted
 * product with
 *
 *     Product::find($request->product_id)
 *
 * and no store predicate. `Product` carries StoreScope but is ABSENT from
 * `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and adds
 * NO predicate for any principal — the same fact behind the S19 cross-tenant stock drain. Its own
 * API twin, `ApiController:2071`, already carried `->where('store_id', $store->id)`; this web copy
 * did not, and both write the same two tables.
 *
 * TWO ROWS CROSSED THE BOUNDARY, and the second is the one that is read back:
 *
 *   * `Wishlist`   — store A, store B's `product_id`.
 *   * `ActivityLog` — `store_id` = A, `remark` = `{"product": <store B's id>}`.
 *
 * `ActivityLog::products()` then resolved that id to store B's product NAME on store A's
 * merchant's customer timeline. `CustomerTimelineTenancyTest` drives that sink; this file drives
 * the WRITER, so the two halves fail independently and neither fix hides the other's absence.
 *
 * THE ATTACKER IS A LOGGED-IN CUSTOMER OF THE VICTIM STORE, not an anonymous caller — a
 * correction made after reading the handler rather than the route table. The route's group
 * (`routes/customer.php:49`) carries only `[ActiveTheme, 'themelanguage']`, so it looks
 * unauthenticated, but the handler overwrites any posted `customer_id` with
 * `auth('customers')->user()->id` and the validator then refuses a null. The precondition is an
 * account on store A, which is self-service.
 */
class StorefrontWishlistProductTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeProductIn(int $storeId, int $createdBy, string $prefix): Product
    {
        $name = $prefix . strtoupper(bin2hex(random_bytes(4)));
        $catName = 'Wish Cat ' . uniqid();

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

    private function makeShopper(int $storeId): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Wish', 'last_name' => 'Shopper',
            'email' => 'wishsrc-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $storeId,
            'regiester_date' => now()->toDateString(),
        ]);
    }

    public function test_a_shopper_cannot_wishlist_another_stores_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $shopper = $this->makeShopper($storeA->id);
        $foreign = $this->makeProductIn($storeB->id, $merchantB->id, 'SRCFOREIGN');

        $response = $this->actingAs($shopper, 'customers')
            ->post('/' . $storeA->slug . '/product-wishlist', [
                'product_id' => $foreign->id,
                'wishlist_type' => 'add',
            ]);

        // 404 "Product not found." is the shape the handler already uses for an absent product,
        // and the shape its API twin uses for this exact refusal. A foreign product IS absent as
        // far as this store is concerned, so the refusal needs no new message or status.
        $response->assertStatus(404);

        // withoutGlobalScopes() -- Wishlist and Cart ARE scoped, so a plain count() answers
        // against whichever tenant resolved and an "it is gone" assertion passes either way.
        // That cost a real false reading on a cart test earlier in this phase.
        $this->assertSame(
            0,
            Wishlist::withoutGlobalScopes()->where('product_id', $foreign->id)->count(),
            'a foreign product was written into a wishlist row'
        );

        $this->assertSame(
            0,
            ActivityLog::withoutGlobalScopes()
                ->where('store_id', $storeA->id)
                ->where('log_type', 'add wishlist')
                ->count(),
            'an activity row was stamped with this store for a foreign product'
        );
    }

    /**
     * POSITIVE CONTROL. A predicate that refused EVERY product would satisfy the test above and
     * break the storefront, which is the failure mode this project keeps a record of.
     */
    public function test_a_shopper_can_still_wishlist_this_stores_own_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $shopper = $this->makeShopper($storeA->id);
        $own = $this->makeProductIn($storeA->id, $merchantA->id, 'SRCOWN');

        $response = $this->actingAs($shopper, 'customers')
            ->post('/' . $storeA->slug . '/product-wishlist', [
                'product_id' => $own->id,
                'wishlist_type' => 'add',
            ]);

        $response->assertStatus(200);

        $this->assertSame(
            1,
            Wishlist::withoutGlobalScopes()
                ->where('product_id', $own->id)
                ->where('store_id', $storeA->id)
                ->count()
        );
    }

    /**
     * THE SECOND HALF OF THE SAME HANDLER, and the half I did NOT find myself — an advisory
     * tenancy review did, on a file I had just finished changing.
     *
     * `product_wishlist()` picks its TENANT from the caller's URL segment (`getStore($storeSlug)`)
     * and its PRINCIPAL from the `customers` session, and never compared them. So a logged-in
     * customer of store A could post to store B's slug and write a `Wishlist` row and an
     * `ActivityLog` row INTO STORE B under their own store-A customer id — junk rows in another
     * merchant's data, written by someone with no relationship to that merchant.
     *
     * MEASURED BEFORE THE FIX, with a throwaway probe rather than from the review's say-so: a
     * store-1 shopper posting store 2's product to store 2's slug got **HTTP 200** and left
     * `wishlists(store_id = 2, customer_id = 1)` in the raw table.
     *
     * THAT PROBE ALSO REFUTED THE REVIEW'S OWN WARNING, which is why it was worth running. The
     * advice was that `actingAs($shopper, 'customers')` makes the Customer the default-guard
     * principal, so `StoreScope` would resolve the slug store, `Customer::find()` would return
     * null and the handler would answer 401 "because of the scope, not because of your fix" —
     * making the defect untestable this way. It did not happen: the probe printed
     * `Customer::find` → FOUND and the handler ran to completion. So this test does bite on the
     * check and not on the scope, and the row-count assertion below holds that independently of
     * any status.
     *
     * 401 AND NOT A NEW STATUS: it is the refusal this route already emits one line earlier and
     * the one the API twin emits for this case, so no client sees a status it did not already see
     * on this path. The shipped Flutter admin app does not call this route — it lives in
     * `routes/customer.php`, not the `api/admin` group — so the force-logout-on-401 hazard is not
     * in play here.
     */
    public function test_a_shopper_cannot_act_on_a_store_they_do_not_belong_to(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $shopperA = $this->makeShopper($storeA->id);
        $productB = $this->makeProductIn($storeB->id, $merchantB->id, 'OTHERSTORE');

        // Store B's OWN product, so the product predicate cannot be what refuses this.
        $response = $this->actingAs($shopperA, 'customers')
            ->post('/' . $storeB->slug . '/product-wishlist', [
                'product_id' => $productB->id,
                'wishlist_type' => 'add',
            ]);

        $response->assertStatus(401);

        // THE ROW COUNT IS THE LOAD-BEARING ASSERTION. It is what the probe measured going wrong,
        // and it holds whatever status the handler chooses.
        $this->assertSame(
            0,
            Wishlist::withoutGlobalScopes()->count(),
            'a shopper from another store wrote a wishlist row into this store'
        );

        $this->assertSame(
            0,
            ActivityLog::withoutGlobalScopes()->count(),
            'a shopper from another store wrote an activity row into this store'
        );
    }

    /*
     * THE NULL-`store_id` BRANCH HAS NO TEST HERE, AND THAT IS A DISCLOSURE, NOT AN OMISSION.
     * =============================================================================================
     *
     * The check tolerates a customer whose `store_id` is NULL, mirroring
     * `ApiController::resolveStoreCustomer()` (:7828), whose docblock records the legacy-row count
     * as a measurement still owed rather than a closed question.
     *
     * I WROTE A POSITIVE CONTROL FOR IT AND DELETED IT, because it failed for a reason that had
     * nothing to do with the branch — and the failure is the one this codebase already documents.
     *
     * `actingAs($legacy, 'customers')` calls `Auth::shouldUse('customers')`, so the Customer
     * becomes the DEFAULT-guard principal. `StoreScope::resolveTenant()` then takes a branch
     * production never takes: in production a shopper leaves the default guard EMPTY, so the
     * `! $user` arm returns MODE_UNRESOLVED under `storefront_tenant => 'additive'` and
     * `null_tenant => 'open'` adds NO predicate, so `Customer::find()` finds the legacy row and
     * the branch admits it. Under `actingAs` a tenant resolves, a predicate IS added, and a row
     * with a NULL `store_id` matches nothing — so `Customer::find()` returns null and the handler
     * answers 401 one line ABOVE this check.
     *
     * MEASURED, both ways: that 401 appears with the store-binding check REMOVED as well, so it
     * is pre-existing harness behaviour and not something the check introduced.
     *
     * `config/tenancy.php:338-350` states this distinction outright and calls it "the fifth way a
     * test passes for the wrong reason in this project's skill", naming `actingAs($x, '<guard>')`
     * as the cause and noting that a legacy NULL/0 row "becomes invisible to its own merchant".
     *
     * SO A TEST HERE WOULD ASSERT THE HARNESS, NOT THE PRODUCT. It is left out rather than
     * written to pass: a conditionally-skipped test would also break the declared outcome set
     * (`.github/known-test-outcomes.json`), which this project asserts in BOTH directions. The
     * row count for the real defect is covered above and does bite.
     */

    private function wishlistRow(int $customerId, int $storeId, int $productId): Wishlist
    {
        return Wishlist::forceCreate([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'variant_id' => 0,
            'status' => 1,
            'store_id' => $storeId,
        ]);
    }

    /**
     * `wishlistCount()` TOOK ITS CUSTOMER ID FROM REQUEST INPUT.
     *
     * `Wishlist::where('customer_id', $request->customer_id)->count()`, with no store predicate
     * and `Wishlist` in no allowlist, so any logged-in shopper could post anyone's customer id and
     * read that person's wishlist size. A count-only oracle, so the disclosure is small -- but the
     * SHAPE is the one CLAUDE.md names outright, and the handler's own fallback already reached
     * for `auth('customers')->id()`, so the correct identifier was present the whole time.
     *
     * THE SCOPE CANNOT BE WHAT MAKES THIS PASS: `Wishlist` carries no StoreScope and is absent
     * from `scoped_models`, so no predicate is added for any principal. The assertion is a number
     * the handler computed, not a row the scope hid.
     *
     * SEVERITY BOUNDED HONESTLY: this needed a CRAFTED request. The shipped storefront never posts
     * the field -- `get_wishlist()` (public/assets/js/front-theme.js:1090-1093) sends `_token` and
     * `wishlist` only -- so on every real call `$request->customer_id` was null, the count came
     * back 0, and the old fallback recounted with the authenticated id. So the UI's effective
     * behaviour is unchanged by this fix, and this test drives a request the UI does not make.
     * That is the point of writing it: nothing in the product exercised the vulnerable path, which
     * is exactly how it survived.
     */
    public function test_a_shopper_cannot_read_another_customers_wishlist_count(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $shopper = $this->makeShopper($storeA->id);
        $victim = $this->makeShopper($storeA->id);

        // Three rows that belong to the victim, none to the caller.
        foreach (['V1', 'V2', 'V3'] as $tag) {
            $this->wishlistRow(
                $victim->id,
                $storeA->id,
                $this->makeProductIn($storeA->id, $merchantA->id, $tag)->id
            );
        }

        $response = $this->actingAs($shopper, 'customers')
            ->post('/' . $storeA->slug . '/wish-list-count', [
                'customer_id' => $victim->id,
            ]);

        $response->assertStatus(200);

        // The caller's OWN count, which is zero -- not the victim's three.
        $this->assertSame(0, $response->json('count'));
    }

    /**
     * THE COUNT IS THIS STORE'S, and this test exists because the fix CHANGED a visible number.
     *
     * The handler previously returned a platform-wide total in every store's header, while its
     * sibling `product_wishlist()` already returned `customer_id + store_id` counts -- so adding
     * one item made the badge jump from the platform total to the store total. Pinning the new
     * behaviour is what stops that inconsistency coming back as a "fix".
     */
    public function test_the_wishlist_count_covers_only_the_store_being_viewed(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        // The shopper belongs to store A, so they can legitimately hold rows in store A. The row
        // in store B below is the kind a multi-store customer really accumulates, and it is the
        // wishlist rows' OWN store_id -- not the customer's -- that the count must key on.
        $shopper = $this->makeShopper($storeA->id);

        $this->wishlistRow($shopper->id, $storeA->id, $this->makeProductIn($storeA->id, $merchantA->id, 'SA1')->id);
        $this->wishlistRow($shopper->id, $storeA->id, $this->makeProductIn($storeA->id, $merchantA->id, 'SA2')->id);
        $this->wishlistRow($shopper->id, $storeB->id, $this->makeProductIn($storeB->id, $merchantB->id, 'SB1')->id);

        $response = $this->actingAs($shopper, 'customers')
            ->post('/' . $storeA->slug . '/wish-list-count', []);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('count'), 'the badge counted another store\'s rows');
    }

    /**
     * THE LIST AND THE BADGE MUST AGREE, which is why this test exists at all.
     *
     * `wishlist()` listed and counted the customer's rows PLATFORM-WIDE while `$store` sat
     * resolved and unused four lines above. On its own that is hardening, not a hole -- the
     * disclosure is to the data's own subject. It stopped being optional the moment the header
     * badge beside it was scoped: the badge would have read 2 and this page 3 for the same
     * customer, an inconsistency introduced by a fix rather than found.
     *
     * So the assertion is deliberately about BOTH numbers from BOTH endpoints, not just this one.
     */
    public function test_the_wishlist_page_and_the_badge_agree_and_both_cover_one_store(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $shopper = $this->makeShopper($storeA->id);

        $inA1 = $this->makeProductIn($storeA->id, $merchantA->id, 'LISTA1');
        $inA2 = $this->makeProductIn($storeA->id, $merchantA->id, 'LISTA2');
        $inB = $this->makeProductIn($storeB->id, $merchantB->id, 'LISTB1');

        $this->wishlistRow($shopper->id, $storeA->id, $inA1->id);
        $this->wishlistRow($shopper->id, $storeA->id, $inA2->id);
        $this->wishlistRow($shopper->id, $storeB->id, $inB->id);

        $list = $this->actingAs($shopper, 'customers')
            ->post('/' . $storeA->slug . '/wish-list', ['wishlist' => true]);
        $badge = $this->actingAs($shopper, 'customers')
            ->post('/' . $storeA->slug . '/wish-list-count', []);

        $list->assertStatus(200);
        $badge->assertStatus(200);

        $this->assertSame(2, $list->json('count'), 'the wishlist page counted another store\'s rows');
        $this->assertSame(2, $badge->json('count'), 'the badge counted another store\'s rows');
        $this->assertSame(
            $list->json('count'),
            $badge->json('count'),
            'the wishlist page and the header badge disagree'
        );

        // The rendered panel must not carry the other store's product either.
        $list->assertDontSee($inB->name, false);
        $list->assertSee($inA1->name, false);
    }
}
