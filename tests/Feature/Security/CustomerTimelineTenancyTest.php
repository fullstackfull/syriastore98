<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-C (F-C-09) — the customer TIMELINE lists activity, and sums money, from every store.
 * ================================================================================================
 *
 * `CustomerController::customerTimeline()` gets the hard part RIGHT and the easy part wrong. It
 * carries a correct ownership check on the customer ROW -- operator first, then a real store
 * comparison (`CustomerController.php:535-545`) -- and then asks three questions with no store predicate at all:
 *
 *     ActivityLog::where('customer_id', $ids)->groupBy('customer_id')->first()   :570
 *     ActivityLog::where('customer_id', $ids)->get()                             :577
 *     ActivityLog::totalspend($ids)                                              :580
 *
 * The check decides WHICH CUSTOMER may be opened, not WHOSE ACTIVITY is listed. That is the same
 * distinction that produced F-SEC-01 through F-SEC-09 and F-C-07: a permission or an ownership
 * test on one object says nothing about the rows a later query returns.
 *
 * `ActivityLog` HAS a `store_id` column -- it is in `$fillable` and `order_entry()` sets it
 * (`ActivityLog.php:18,37`) -- and the model is absent from `scoped_models`, so nothing supplies
 * the predicate implicitly.
 *
 * WHAT THE FEED LEAKS IS NOT JUST "ACTIVITY". `order_entry()` writes `remark` as JSON carrying
 * `order_id`, `order_date`, `product`, `price` and `payment_type` (`ActivityLog.php:25-35`), so a
 * merchant reading one of their own customers' timelines sees ANOTHER STORE'S order references,
 * products and PRICES.
 *
 * And `ActivityLog::totalspend()` is `Order::where('customer_id', $userId)` with a
 * `SUM(final_price)` select expression and no store predicate, so Total Spend and Total Orders on
 * this page sum across the platform.
 *
 * ALREADY DECLARED OPEN for the totalspend half: `ResidualTenancyExposureTest`'s raw-expression
 * test records it by path and states the harm in as many words. The ACTIVITY FEED half was in no
 * ratchet at all.
 *
 * SAME STORE AUTHORITY AS F-C-07, and for the same measured reason: the customer's own
 * `store_id`, not `getCurrentStore()`, because `getCurrentStore()` returns the literal 1 for a
 * super admin with an empty `current_store` -- and this route deliberately admits the operator to
 * any store's customer, so a `getCurrentStore()` predicate would show them store 1's activity on a
 * store-7 customer's page. A fabrication, not a refusal.
 */
class CustomerTimelineTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * THE NEEDLE IS THE ORDER REFERENCE, NOT THE PRODUCT NAME, and that correction came from the
     * positive control failing. For a `place order` log the view renders `product` through
     * `ActivityLog::products()` (every `end-date` render in that view), which resolves it as a
     * product ID -- so a string in that field renders as nothing. `order_id` and `price` are
     * rendered verbatim in the same block, so those are what a test can see.
     *
     * Without the positive control this file would have passed on an `assertDontSee` that held
     * because the field never rendered at all.
     */
    private function logOrderFor(int $customerId, int $storeId, string $reference, float $price): ActivityLog
    {
        return ActivityLog::forceCreate([
            'customer_id' => $customerId,
            'log_type' => 'place order',
            'remark' => json_encode([
                'order_id' => $reference,
                'order_date' => now()->toDateString(),
                'product' => 0,
                'price' => $price,
                'payment_type' => 'cod',
            ]),
            'store_id' => $storeId,
        ]);
    }

    public function test_the_timeline_does_not_list_another_stores_activity_for_the_same_customer(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Show Customer');

        $customer = Customer::forceCreate([
            'first_name' => 'Timeline', 'last_name' => 'Shopper',
            'email' => 'timeline-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeA->id,
            // `regiester_date` (the column really is spelled that way) IS LOAD-BEARING FOR THIS
            // TEST: customer/timeline.blade.php's `regiester_date` gate wraps the ENTIRE timeline on
            // `!empty($customer_data->regiester_date)` and otherwise renders a "never registered"
            // branch with no activity at all. Without it the first draft of this file passed its
            // `assertDontSee` because NOTHING rendered -- caught by the positive control, which is
            // the only reason I know. A fixture fact, not a product defect.
            'regiester_date' => now()->toDateString(),
        ]);

        $ownRef = 'OWN-REF-' . uniqid();
        $foreignRef = 'FOREIGN-REF-' . uniqid();

        $this->logOrderFor($customer->id, $storeA->id, $ownRef, 11.00);

        /*
         * THE FOREIGN PRICE IS 987654.32 AND NOT 999.00, AND THE REASON IS A REAL CI FAILURE.
         *
         * This test asserted `assertDontSee('999', false)` -- three digits, matched as a substring
         * against the WHOLE RENDERED PAGE. On 2026-11-07 the merge-base regression gate reported it
         * as a new failure on a branch that had not touched the timeline at all, while the same
         * commit passed it in two other CI jobs and passed it locally.
         *
         * THE PAGE CONTAINED `999` FOR A REASON THAT HAS NOTHING TO DO WITH THE OTHER STORE'S
         * ORDER. `makeTenant()` names its store with `uniqid()`, which is hexadecimal, and that run
         * drew `Store 1-6aef11ffb999f`. The name is printed three times in the merchant shell --
         * the workspace label and two header copies -- so the assertion matched the FIXTURE'S OWN
         * STORE NAME. Measured from the CI log: the only three occurrences of `999` in the 63,650
         * characters of that page were all the store name.
         *
         * A 13-character hex string contains `999` about once in every 370 runs. So the test was
         * not wrong about tenancy; it was an assertion that could fail for a reason unrelated to
         * what it tests, which is the defect class this project keeps a record of. A price whose
         * decimal form is six digits long cannot collide with a hex id or a base62 CSRF token by
         * accident -- roughly one run in 1.5 million rather than one in 370.
         *
         * THE ASSERTION IS NOT WEAKENED, and the bite was RE-MEASURED rather than assumed --
         * which turned out to matter, because the first attempt to demonstrate it FAILED and the
         * reason is worth keeping.
         *
         * `customerTimeline()` builds TWO queries from `$timelineStoreId`:
         *
         *   :570  $activityLog = ActivityLog::where('store_id', ...)->groupBy('customer_id')->first()
         *   :577  $logs        = ActivityLog::where('store_id', ...)->get()
         *
         * Removing the predicate from `:570` leaves this test GREEN. Removing it from `:577` turns
         * it RED on exactly the two assertions below. So the view iterates `$logs`, and `:577` is
         * the line this test actually guards.
         *
         * **`:570` THEREFORE HAS NO BITE COVERAGE HERE**, and that is stated rather than implied:
         * a reader who deletes its predicate gets a green suite. It is a `first()` used for a
         * summary rather than the list, so the consequence is narrower -- but "narrower" is not
         * "none", and nothing in this file would notice.
         */
        $this->logOrderFor($customer->id, $storeB->id, $foreignRef, 987654.32);

        $response = $this->actingAs($merchantA)->get('/customer-timeline/' . $customer->id);

        $response->assertStatus(200);

        // The other store's order reference and its PRICE must both be absent.
        $response->assertDontSee($foreignRef, false);
        $response->assertDontSee('987654', false);

        // POSITIVE CONTROL: a fix that listed nothing would satisfy both assertions above, and
        // the first draft of this test did exactly that -- see logOrderFor()'s docblock.
        $response->assertSee($ownRef, false);
    }

    public function test_the_timeline_total_spend_sums_only_this_stores_orders(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Show Customer');

        $customer = Customer::forceCreate([
            'first_name' => 'Spend', 'last_name' => 'Shopper',
            'email' => 'spend-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeA->id,
            // `regiester_date` (the column really is spelled that way) IS LOAD-BEARING FOR THIS
            // TEST: customer/timeline.blade.php's `regiester_date` gate wraps the ENTIRE timeline on
            // `!empty($customer_data->regiester_date)` and otherwise renders a "never registered"
            // branch with no activity at all. Without it the first draft of this file passed its
            // `assertDontSee` because NOTHING rendered -- caught by the positive control, which is
            // the only reason I know. A fixture fact, not a product defect.
            'regiester_date' => now()->toDateString(),
        ]);

        $this->makeOrder($storeA, ['customer_id' => $customer->id, 'final_price' => 25.00]);
        $this->makeOrder($storeB, ['customer_id' => $customer->id, 'final_price' => 775.00]);

        $totals = ActivityLog::totalspend($customer->id, (int) $storeA->id);

        $this->assertSame(
            1,
            (int) $totals['total_orders'],
            "Total Orders on the timeline counted another store's order."
        );
        $this->assertSame(
            25.0,
            (float) $totals['total_spend'],
            "Total Spend on the timeline summed another store's revenue."
        );
    }

    /**
     * FAILS CLOSED when no store is given, rather than falling back to every store. Without this
     * the helper's old signature would keep working platform-wide for any caller that forgot the
     * argument, which is how the defect would come back.
     */
    public function test_total_spend_with_no_store_sums_nothing(): void
    {
        [, $storeA] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'NoStore', 'last_name' => 'Arg',
            'email' => 'nostorearg-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeA->id,
            // `regiester_date` (the column really is spelled that way) IS LOAD-BEARING FOR THIS
            // TEST: customer/timeline.blade.php's `regiester_date` gate wraps the ENTIRE timeline on
            // `!empty($customer_data->regiester_date)` and otherwise renders a "never registered"
            // branch with no activity at all. Without it the first draft of this file passed its
            // `assertDontSee` because NOTHING rendered -- caught by the positive control, which is
            // the only reason I know. A fixture fact, not a product defect.
            'regiester_date' => now()->toDateString(),
        ]);

        $this->makeOrder($storeA, ['customer_id' => $customer->id, 'final_price' => 500.00]);

        $totals = ActivityLog::totalspend($customer->id, null);

        $this->assertSame(0, (int) $totals['total_orders']);
        $this->assertSame(0.0, (float) $totals['total_spend']);
    }

    /**
     * P2.5-PKG-G FOLLOW-UP -- THE PRODUCT NAME, which this file's own docblock explains it does
     * not reach.
     *
     * `logOrderFor()` above sets `'product' => 0` and says why: for a `place order` log the view
     * resolves that field through `ActivityLog::products()`, so a string there renders as nothing.
     * The consequence is that the product-name PATH has never been exercised by this file, and the
     * two tests above pass whether or not it is scoped.
     *
     * IT IS NOT SCOPED. `ActivityLog::products()` was
     *
     *     Product::whereIn('id', explode(',', $id))->get()
     *
     * with no store predicate, and `Product` is absent from `config/tenancy.php`'s
     * `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and NO predicate is added for
     * any principal -- not a customer, not a merchant, not the operator. The same fact produced
     * the S19 cross-tenant stock drain.
     *
     * THE ACTIVITY ROW IS LEGITIMATELY THIS STORE'S. That is the whole point, and it is why the
     * `:577` predicate the tests above guard does not help: both rows below carry
     * `store_id = storeA`, so store A's merchant is entitled to see both EVENTS. What leaks is the
     * NAME the resolver fetches for an id that belongs to store B.
     *
     * HOW A FOREIGN ID GETS IN -- a real writer, not a contrived fixture:
     * `WishlistController::product_wishlist()` validated the posted product with
     * `Product::find($request->product_id)` and no store predicate, then wrote that id into
     * `remark` stamped with the current store. A LOGGED-IN CUSTOMER OF THE VICTIM STORE is the
     * attacker, not an anonymous caller: the route (`routes/customer.php:178`) sits in the group
     * at `:49` whose middleware is only `[ActiveTheme, 'themelanguage']`, but the handler takes
     * `customer_id` from `auth('customers')` and merges over anything posted, so a missing
     * session is refused by the validator. `StorefrontWishlistProductTenancyTest` drives that
     * route; this test drives the sink, so the two halves fail independently.
     *
     * `add wishlist` IS THE LOG TYPE DELIBERATELY. PKG-G slice 5 routed that render through the
     * resolver -- it had been printing the bare id -- so this site is the one my own change turned
     * from a number into a name. The other nine render sites already printed names.
     *
     * THE NEEDLE IS ALPHABETIC AND LONG, for the reason the 987654.32 docblock above records: a
     * short or hex-shaped needle collides with `makeTenant()`'s `uniqid()` store name, which the
     * merchant shell prints three times.
     */
    public function test_the_timeline_does_not_name_another_stores_product_from_this_stores_activity(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Show Customer');

        $customer = Customer::forceCreate([
            'first_name' => 'Wishlist', 'last_name' => 'Shopper',
            'email' => 'wishlist-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeA->id,
            // See logOrderFor(): without this the whole timeline renders a "never registered"
            // branch and every assertDontSee passes for the wrong reason.
            'regiester_date' => now()->toDateString(),
        ]);

        $ownProduct = $this->makeTimelineProduct($storeA->id, $merchantA->id, 'OWNWISHLISTNEEDLE');
        $foreignProduct = $this->makeTimelineProduct($storeB->id, $merchantB->id, 'FOREIGNWISHLISTNEEDLE');

        // BOTH rows are store A's own activity. Only the product id inside the second is foreign.
        $this->logWishlistFor($customer->id, $storeA->id, $ownProduct->id);
        $this->logWishlistFor($customer->id, $storeA->id, $foreignProduct->id);

        $response = $this->actingAs($merchantA)->get('/customer-timeline/' . $customer->id);

        $response->assertStatus(200);

        $response->assertDontSee($foreignProduct->name, false);

        // POSITIVE CONTROL: a resolver that returned the empty string for everything would satisfy
        // the assertion above and leave the merchant's own page useless.
        $response->assertSee($ownProduct->name, false);
    }

    /**
     * THE HELPER FAILS CLOSED, and this test is what makes that a guarantee rather than a comment.
     *
     * `products()` now takes a REQUIRED `$storeId` with no default -- the shape `totalspend()`
     * established in PKG-C -- so a caller that forgets it is a TypeError at the call site rather
     * than a silent platform-wide name lookup. A null store resolves NOTHING rather than
     * everything, which is the direction a tenancy helper has to fail in.
     */
    public function test_the_product_resolver_names_nothing_without_a_store(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $product = $this->makeTimelineProduct($storeA->id, $merchantA->id, 'NOSTORENEEDLE');

        $this->assertSame('', ActivityLog::products((string) $product->id, null));
        $this->assertSame($product->name, ActivityLog::products((string) $product->id, $storeA->id));
    }

    /**
     * `remark.product` is a COMMA-SEPARATED LIST for a multi-product order -- `order_entry()`
     * writes `$data['products']` into it -- so the mixed case is the one that matters: a list
     * holding one of each must name the store's own product and drop the other, not refuse the
     * whole list.
     */
    public function test_the_product_resolver_keeps_this_stores_names_and_drops_the_rest(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $own = $this->makeTimelineProduct($storeA->id, $merchantA->id, 'MIXEDOWNNEEDLE');
        $foreign = $this->makeTimelineProduct($storeB->id, $merchantB->id, 'MIXEDFOREIGNNEEDLE');

        $resolved = ActivityLog::products($own->id . ',' . $foreign->id, $storeA->id);

        $this->assertStringContainsString($own->name, $resolved);
        $this->assertStringNotContainsString($foreign->name, $resolved);
    }

    private function makeTimelineProduct(int $storeId, int $createdBy, string $prefix): Product
    {
        $name = $prefix . strtoupper(bin2hex(random_bytes(4)));
        $catName = 'Timeline Cat ' . uniqid();

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

    private function logWishlistFor(int $customerId, int $storeId, int $productId): ActivityLog
    {
        return ActivityLog::forceCreate([
            'customer_id' => $customerId,
            'log_type' => 'add wishlist',
            'remark' => json_encode(['product' => $productId]),
            'store_id' => $storeId,
        ]);
    }

    /**
     * THE AUTHORITY CHOICE, WHICH NOTHING ASSERTED -- a gap an advisory review found, and it was
     * right: every other test in this file uses `makeTenant()`, where the merchant's
     * `current_store` EQUALS the viewed customer's store. So `$timelineStoreId` and
     * `getCurrentStore()` are the same number in all of them, and swapping one for the other left
     * the whole suite green. The comment at `CustomerController.php:559-563` argues for that
     * choice at length and no test could tell the two apart.
     *
     * THE OPERATOR IS THE CASE THAT SEPARATES THEM, for a measured reason: `getCurrentStore()`
     * returns the LITERAL 1 for a `super admin` whose `current_store` is empty
     * (`helper.php:773-775`), and `customerTimeline()` deliberately admits the operator to ANY
     * store's customer (`:535`). So on a store-2 customer's page a `getCurrentStore()` predicate
     * would name STORE 1's catalogue -- a fabrication, not a refusal, which is the specific harm
     * the comment says it is avoiding.
     *
     * This test puts the operator's `current_store` at store 1 explicitly and gives store 1 a
     * product, so a wrong predicate has something real and wrong to find rather than merely
     * finding nothing. A fix that resolved no name would be caught by the positive control.
     *
     * BITE RE-MEASURED, AND THE FIRST VERSION OF THIS SENTENCE WAS WRONG. Replacing
     * `$timelineStoreId` with `getCurrentStore()` at all fourteen Blade call sites turns this test
     * red and leaves **every other test in this file green** -- which is exactly the gap, measured.
     * But it fails on the `assertSee` ALONE (line 417), not on both assertions as this docblock
     * first claimed. With a store-1 predicate the store-2 product simply resolves to nothing, so
     * the page is EMPTY where the name belongs rather than carrying the wrong name.
     *
     * SO THE `assertDontSee` BELOW HAS NO BITE UNDER THIS MUTATION, and it is kept anyway rather
     * than deleted or dressed up: it would catch a DIFFERENT and worse failure -- a predicate that
     * resolved names from the operator's own current store, which needs a log row referencing that
     * store to become visible and no fixture here creates one. It is a guard against a shape that
     * is not currently reachable, stated as such, because a reader who assumes both halves bite
     * would trust this test for more than it proves.
     */
    public function test_the_operator_sees_the_viewed_customers_catalogue_and_not_their_own_current_store(): void
    {
        [$merchant1, $store1] = $this->makeTenant();
        [$merchant2, $store2] = $this->makeTenant();

        // The operator's current_store points at store 1, while the customer belongs to store 2.
        $operator = $this->makePlatformAdmin(['current_store' => $store1->id]);

        $customer = Customer::forceCreate([
            'first_name' => 'Operator', 'last_name' => 'Viewed',
            'email' => 'opview-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $store2->id,
            'regiester_date' => now()->toDateString(),
        ]);

        $ownedByTheViewedStore = $this->makeTimelineProduct($store2->id, $merchant2->id, 'VIEWEDSTORENEEDLE');
        $ownedByTheOperatorsStore = $this->makeTimelineProduct($store1->id, $merchant1->id, 'CURRENTSTORENEEDLE');

        // The activity is store 2's, naming store 2's product -- the correct render.
        $this->logWishlistFor($customer->id, $store2->id, $ownedByTheViewedStore->id);

        $response = $this->actingAs($operator)->get('/customer-timeline/' . $customer->id);

        $response->assertStatus(200);

        // The viewed customer's own store is the authority, so its product name must render.
        $response->assertSee($ownedByTheViewedStore->name, false);

        // And the operator's current_store must have no bearing on what is named.
        $response->assertDontSee($ownedByTheOperatorsStore->name, false);
    }

    private function makeVariantFor(Product $product, string $label): ProductVariant
    {
        return ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => $label,
            'sku' => 'SKU-' . uniqid(),
            'price' => 10,
            'stock' => 5,
            // DELIBERATELY NULL, which is the whole point of the predicate under test:
            // product_variants.store_id is nullable, so a hard store predicate would drop this
            // legitimate row. The fix scopes through product_id instead.
            'store_id' => null,
        ]);
    }

    /**
     * THE OTHER HALF OF THE SAME ROW -- `variants()`, which an advisory review flagged after
     * `products()` was already fixed, and which the earlier report had handed to review as open.
     *
     * `ProductVariant::find($id)` carried no predicate, and `ProductVariant` has no StoreScope at
     * all, so a foreign variant id in `remark.variant` resolved to the other store's option label
     * beside a product name that was by then correctly withheld -- the row HALF fixed, which reads
     * as a working page and is worse than an obviously broken one.
     *
     * `store_id` IS NULL ON THE FIXTURE ON PURPOSE. `product_variants.store_id` is nullable (its
     * migration declares it so) and `CartController.php:490-496` states the codebase's rule in as
     * many words: scope variants by `product_id`, not `store_id`. A test whose variants all
     * carried a store id would pass against the WRONG fix -- the hard predicate that drops
     * legitimate rows -- so the fixture is built to fail that one.
     */
    public function test_the_timeline_does_not_name_another_stores_variant(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Show Customer');

        $customer = Customer::forceCreate([
            'first_name' => 'Variant', 'last_name' => 'Shopper',
            'email' => 'variant-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeA->id,
            'regiester_date' => now()->toDateString(),
        ]);

        $ownProduct = $this->makeTimelineProduct($storeA->id, $merchantA->id, 'OWNVARPRODUCT');
        $foreignProduct = $this->makeTimelineProduct($storeB->id, $merchantB->id, 'FOREIGNVARPRODUCT');

        $ownVariant = $this->makeVariantFor($ownProduct, 'OWNVARIANTNEEDLE');
        $foreignVariant = $this->makeVariantFor($foreignProduct, 'FOREIGNVARIANTNEEDLE');

        // Both rows are store A's own activity; only the ids inside the second are foreign. This
        // is the shape CartController::cart_remove() really writes.
        $this->logCartRemoveFor($customer->id, $storeA->id, $ownProduct->id, $ownVariant->id);
        $this->logCartRemoveFor($customer->id, $storeA->id, $foreignProduct->id, $foreignVariant->id);

        $response = $this->actingAs($merchantA)->get('/customer-timeline/' . $customer->id);

        $response->assertStatus(200);

        $response->assertDontSee($foreignVariant->variant, false);

        // POSITIVE CONTROL, and it is the one that rules out the WRONG fix: a hard
        // `where('store_id', $storeId)` would withhold this label too, because the fixture's
        // store_id is null. Only the product-scoped predicate satisfies both assertions.
        $response->assertSee($ownVariant->variant, false);
    }

    public function test_the_variant_resolver_names_nothing_without_a_store(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $product = $this->makeTimelineProduct($storeA->id, $merchantA->id, 'NOSTOREVAR');
        $variant = $this->makeVariantFor($product, 'NOSTOREVARIANT');

        $this->assertNull(ActivityLog::variants((string) $variant->id, null));
        $this->assertSame($variant->variant, ActivityLog::variants((string) $variant->id, $storeA->id));
    }

    private function logCartRemoveFor(int $customerId, int $storeId, int $productId, int $variantId): ActivityLog
    {
        return ActivityLog::forceCreate([
            'customer_id' => $customerId,
            'log_type' => 'remove to cart',
            'remark' => json_encode(['product' => $productId, 'variant' => $variantId]),
            'store_id' => $storeId,
        ]);
    }

    /**
     * THE REGISTER CARD SHOWED A `users` ROW, NOT THE CUSTOMER -- found by LOOKING at a capture,
     * which is the only reason it was found at all.
     *
     * `ActivityLog::userinfo()` was `hasOne(User::class, 'id', 'customer_id')`. `customer_id`
     * refers to the `customers` table; this joined it against `users.id`. So the "Registered"
     * card on a customer's timeline rendered whichever USER happened to share that id.
     *
     * MEASURED on the dev fixture before the fix: customer #1 is Layla Haddad, and her timeline's
     * register card printed `operator@example.test` -- user #1, the PLATFORM OPERATOR. Customer
     * #2 would print user #2, another merchant. Every customer whose id falls inside the `users`
     * id range prints somebody's address.
     *
     * SO IT IS A DISCLOSURE, not only a display defect: a merchant reading their own customer's
     * page is shown an unrelated principal's e-mail and mobile number.
     *
     * AND FOUR OF THE FIVE FIELDS WERE ALWAYS BLANK, which is the tell that the relation was
     * always wrong rather than recently broken: `users` has no `first_name`, no `last_name` and
     * no `regiester_date`. Only `email` and `mobile` resolved -- the two that leak.
     *
     * THE VIEW NOW READS `$customer_data`, the customer the controller already fetched AND
     * ownership-checked, so the card cannot name anyone else by construction. That is better than
     * repointing the relation at `Customer` and re-rendering through it, because `Customer` is in
     * `scoped_models` and the operator -- whom this route deliberately admits to any store's
     * customer -- resolves `getCurrentStore()` to the literal 1, which would have blanked the card
     * on a store-7 customer instead. The relation is repaired too, for anything that reaches for
     * it later.
     *
     * THE RELATION REPAIR HAS NO BITE COVERAGE HERE, AND THAT IS STATED RATHER THAN IMPLIED.
     * Measured both ways after the fix:
     *
     *   view back on `$value->userinfo` (relation still correct) -> this test FAILS
     *   relation back to `User` (view on `$customer_data`)       -> this test PASSES
     *
     * So what this test guards is the VIEW reading `$customer_data`. Repointing `userinfo` at
     * `Customer` is correctness hygiene for a relation nothing renders through today, and a
     * reader who deletes that repair gets a green suite. Writing a test for an unused relation
     * would pin a shape no screen depends on; disclosing the gap is the honest alternative, and
     * it is the same disclosure this file already makes about `:570`.
     */
    public function test_the_register_card_names_the_customer_and_never_a_user_row(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Show Customer');

        $customer = Customer::forceCreate([
            'first_name' => 'RegisterCardNeedle', 'last_name' => 'Customer',
            'email' => 'registercard-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeA->id,
            'mobile' => '0911222333',
            'regiester_date' => now()->toDateString(),
        ]);

        /*
         * THE COLLISION IS NATURAL, NOT ENGINEERED, and that is the point: `makeTenant()` creates
         * the first `users` row and this is the first `customers` row, so they share an id exactly
         * as they do in any deployment where both sequences started at 1. The broken relation
         * therefore resolved to a REAL user -- here the viewing merchant, on the dev fixture the
         * PLATFORM OPERATOR. No id surgery is needed to reproduce it, and an earlier draft that
         * tried to force one failed on a unique constraint for the obvious reason.
         */
        $collidingUser = \App\Models\User::find($customer->id);
        $this->assertNotNull(
            $collidingUser,
            'PREMISE: no users row shares this customer id, so the broken relation would have '
            . 'resolved to null and this test could not tell a fix from an absence.'
        );

        $collidingUser->email = 'LEAKYUSERNEEDLE' . strtoupper(bin2hex(random_bytes(4))) . '@example.test';
        $collidingUser->save();
        $collidingUser->flushCache();

        ActivityLog::forceCreate([
            'customer_id' => $customer->id,
            'log_type' => 'register',
            'remark' => json_encode(['date' => now()->toDateTimeString()]),
            'store_id' => $storeA->id,
        ]);

        $response = $this->actingAs($merchantA)->get('/customer-timeline/' . $customer->id);

        $response->assertStatus(200);

        // The other principal's address must not appear anywhere on the page.
        $response->assertDontSee($collidingUser->email, false);

        // POSITIVE CONTROL: the card must still name the CUSTOMER, or a fix that printed nothing
        // would satisfy the assertion above and leave the card blank -- which is what three of
        // these five fields already did.
        $response->assertSee($customer->first_name, false);
        $response->assertSee($customer->email, false);
    }
}
