<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-04 follow-up -- ModuleStorefrontInputGuard, driven through the REAL SpamPrevent and ProductReview routes.
 * ===============================================================================================================
 *
 * Both modules put a storefront write in the `web` group with nothing in front of it and take the identity and tenant columns from the body:
 *
 *   POST {slug}/spamprevent      customer_id / created_by from the body  -> a comment posted AS any customer, raw HTML in it, which the
 *                                merchant's reply modal prints with `${comment.comment}` (unescaped): stored script in the admin origin
 *   GET  spam-fetch-prevent      any customer's thread in any store, anonymously
 *   POST spam-prevent            the merchant's reply, for any customer id
 *   GET  spamprevent/{id}/edit, DELETE spamprevent/{id}   ({id} is a CUSTOMER id) any signed-in, plan-entitled tenant, any customer
 *   POST {slug}/product-review   store_id and status from the body -> a review planted into ANY store, status of the visitor's choosing
 *
 * Every refusal here must be a 403 / redirect, never a 401 (the Flutter app logs out on a 401). Each negative case has a positive twin, so
 * "nothing was stored" cannot pass on a route that fails for everyone.
 */
class ModuleStorefrontInputGuardTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $failed = $this->enableWorkdoModules(['SpamPrevent', 'ProductReview']);

        foreach (\App\Http\Middleware\ModuleStorefrontInputGuard::ROUTE_NAMES as $name) {
            $this->assertTrue(Route::has($name), "Premise: the route {$name} is not loaded, so this test reaches nothing. Failed migrations: " . implode('; ', $failed));
        }

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('product_comments'), 'Premise: product_comments was not migrated.');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('product_reviews'), 'Premise: product_reviews was not migrated.');
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => uniqid(),
            'email' => 'shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function productIn($store, $owner): Product
    {
        return Product::forceCreate([
            'name' => 'p-' . uniqid(), 'slug' => 'p-' . uniqid(), 'product_stock' => 3, 'track_stock' => 0, 'variant_product' => 0,
            'store_id' => $store->id, 'created_by' => $owner->id,
        ]);
    }

    private function comment(int $customerId, int $storeId, string $text = 'a comment', string $type = 'customer'): int
    {
        return (int) DB::table('product_comments')->insertGetId([
            'customer_id' => (string) $customerId, 'store_id' => $storeId, 'comment' => $text, 'type' => $type,
            'created_by' => $customerId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function entitle(\App\Models\User $owner, string $module): void
    {
        DB::table('user_active_modules')->insert(['user_id' => $owner->id, 'module' => $module, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains($module, ActivatedModule($owner->id), 'The entitlement fixture did not take.');
    }

    private function assertRefused($response): void
    {
        $this->assertTrue($response->isRedirect() || $response->status() === 403, 'Neither a redirect nor a 403: ' . $response->status());
        $this->assertNotSame(401, $response->status(), 'A 401 is a forced logout in the Flutter app.');
    }

    public function test_a_route_the_guard_has_no_rule_for_passes_through_untouched(): void
    {
        Route::middleware('web')->post('/p1-sec-04/passthrough/{storeSlug}', fn () => response('REACHED'))->name('p1sec04.passthrough');

        $this->assertSame('REACHED', $this->post('/p1-sec-04/passthrough/anything', ['comment' => '<b>x</b>'])->getContent());
    }

    // ---- POST {slug}/spamprevent ----------------------------------------------------------------

    public function test_an_anonymous_visitor_cannot_post_a_comment_as_a_customer(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->shopper($store);

        $this->assertRefused($this->post('/' . $store->slug . '/spamprevent', ['comment' => '<script>x</script>', 'customer_id' => $victim->id, 'created_by' => $victim->id]));
        $this->assertSame(0, DB::table('product_comments')->count());
    }

    public function test_a_signed_in_merchant_is_not_a_shopper_and_cannot_post_a_comment_either(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->shopper($store);

        $this->assertRefused($this->actingAs($merchant)->post('/' . $store->slug . '/spamprevent', ['comment' => 'hi', 'customer_id' => $victim->id]));
        $this->assertSame(0, DB::table('product_comments')->count());
    }

    public function test_a_shoppers_comment_is_stored_as_theirs_whatever_the_body_says_and_without_markup(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $me = $this->shopper($store);
        $victim = $this->shopper($store);

        $response = $this->actingAs($me, 'customers')->post('/' . $store->slug . '/spamprevent', [
            'comment' => 'hello <script>alert(1)</script><b>there</b>', 'customer_id' => $victim->id, 'created_by' => $victim->id, 'store_id' => 999,
        ]);

        $response->assertOk();
        $row = DB::table('product_comments')->first();
        $this->assertNotNull($row, 'The positive control stored nothing: the route fails for everyone.');
        $this->assertSame((string) $me->id, (string) $row->customer_id);
        $this->assertSame($me->id, (int) $row->created_by);
        $this->assertSame($store->id, (int) $row->store_id);
        $this->assertStringNotContainsString('<', $row->comment);
        $this->assertStringContainsString('hello', $row->comment);
        $this->assertSame(1, DB::table('product_comments')->count());
    }

    public function test_a_comment_to_an_unknown_store_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $me = $this->shopper($store);

        $this->assertRefused($this->actingAs($me, 'customers')->post('/no-such-store-' . uniqid() . '/spamprevent', ['comment' => 'hi']));
        $this->assertSame(0, DB::table('product_comments')->count());
    }

    // ---- GET spam-fetch-prevent -----------------------------------------------------------------

    public function test_an_anonymous_visitor_cannot_read_a_customers_thread(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->shopper($store);
        $this->comment($victim->id, $store->id, 'private');

        $response = $this->getJson('/spam-fetch-prevent?store_id=' . $store->id . '&customer_id=' . $victim->id);

        $this->assertRefused($response);
        $this->assertStringNotContainsString('private', $response->getContent());
    }

    public function test_a_shopper_reads_only_their_own_thread_whatever_customer_id_they_name(): void
    {
        [, $store] = $this->makeTenant();
        $me = $this->shopper($store);
        $other = $this->shopper($store);
        $this->comment($me->id, $store->id, 'mine');
        $this->comment($other->id, $store->id, 'theirs');

        $response = $this->actingAs($me, 'customers')->getJson('/spam-fetch-prevent?store_id=' . $store->id . '&customer_id=' . $other->id);

        $response->assertOk();
        $this->assertStringContainsString('mine', $response->getContent(), 'The positive control returned nothing: the route fails for everyone.');
        $this->assertStringNotContainsString('theirs', $response->getContent());
    }

    public function test_a_shopper_naming_a_store_that_does_not_exist_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $me = $this->shopper($store);

        $this->assertRefused($this->actingAs($me, 'customers')->getJson('/spam-fetch-prevent?store_id=999999'));
    }

    public function test_a_merchant_reads_a_thread_of_their_own_customer_but_not_another_stores(): void
    {
        [$a, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $mine = $this->shopper($storeA);
        $foreign = $this->shopper($storeB);
        $this->comment($mine->id, $storeA->id, 'own-customer-thread');
        $this->comment($foreign->id, $storeB->id, 'foreign-thread');

        $own = $this->actingAs($a)->getJson('/spam-fetch-prevent?customer_id=' . $mine->id . '&store_id=' . $storeA->id);
        $own->assertOk();
        $this->assertStringContainsString('own-customer-thread', $own->getContent());

        // the store_id in the query is NOT the merchant's: it is replaced by theirs, and the customer must belong to it
        $cross = $this->actingAs($a)->getJson('/spam-fetch-prevent?customer_id=' . $foreign->id . '&store_id=' . $storeB->id);
        $this->assertRefused($cross);
        $this->assertStringNotContainsString('foreign-thread', $cross->getContent());
    }

    public function test_the_platform_operator_may_read_any_thread(): void
    {
        // the operator's own "current store" resolves to 1 (getCurrentStore's super-admin default): read a thread in a store that is NOT 1, so
        // the pass cannot come from that coincidence
        $this->makeTenant();
        [, $store] = $this->makeTenant();
        $this->assertNotSame(1, $store->id);
        $customer = $this->shopper($store);
        $this->comment($customer->id, $store->id, 'operator-sees-this');
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator)->getJson('/spam-fetch-prevent?customer_id=' . $customer->id . '&store_id=' . $store->id);

        $response->assertOk();
        $this->assertStringContainsString('operator-sees-this', $response->getContent());
    }

    // ---- POST spam-prevent (the merchant's reply) -----------------------------------------------

    public function test_an_anonymous_visitor_cannot_post_a_merchant_reply(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->shopper($store);

        $this->assertRefused($this->postJson('/spam-prevent', ['comment' => 'x', 'customer_id' => $customer->id]));
        $this->assertSame(0, DB::table('product_comments')->count());
    }

    public function test_a_merchant_replies_to_their_own_customer_but_not_to_another_stores(): void
    {
        [$a, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($a, 'Reply SpamPrevent');
        $mine = $this->shopper($storeA);
        $foreign = $this->shopper($storeB);

        $this->assertRefused($this->actingAs($a->fresh())->postJson('/spam-prevent', ['comment' => 'reply to a stranger', 'customer_id' => $foreign->id]));
        $this->assertSame(0, DB::table('product_comments')->count());

        $this->actingAs($a->fresh())->postJson('/spam-prevent', ['comment' => '<img src=x onerror=alert(1)>kind words', 'customer_id' => $mine->id])->assertOk();

        $row = DB::table('product_comments')->first();
        $this->assertNotNull($row, 'The positive control stored nothing: the route fails for everyone.');
        $this->assertSame('admin', $row->type);
        $this->assertSame($storeA->id, (int) $row->store_id);
        $this->assertStringNotContainsString('<', $row->comment);
        $this->assertStringContainsString('kind words', $row->comment);
    }

    public function test_a_customer_id_with_trailing_junk_never_names_the_customer_it_starts_with(): void
    {
        [$a, $storeA] = $this->makeTenant();
        $this->grantPermission($a, 'Reply SpamPrevent');
        $this->entitle($a, 'SpamPrevent');
        $mine = $this->shopper($storeA);
        $this->comment($mine->id, $storeA->id, 'kept');

        foreach (['abc', '.9', ' 1', '0x1'] as $junk) {
            $id = $mine->id . $junk;

            $this->assertRefused($this->actingAs($a->fresh())->getJson('/spam-fetch-prevent?customer_id=' . urlencode($id) . '&store_id=' . $storeA->id));
            $this->assertRefused($this->actingAs($a->fresh())->postJson('/spam-prevent', ['comment' => 'x', 'customer_id' => $id]));
            $this->assertRefused($this->actingAs($a->fresh())->delete('/spamprevent/' . rawurlencode($id)));
        }

        $this->assertSame(1, DB::table('product_comments')->count(), 'A junk-suffixed id acted on the customer it starts with.');
    }

    public function test_the_operator_cannot_reply_into_another_stores_thread_because_the_module_would_file_it_under_store_one(): void
    {
        $this->makeTenant();                      // store 1: the operator's default "current store"
        [, $store] = $this->makeTenant();
        $this->assertNotSame(1, $store->id);
        $customer = $this->shopper($store);
        $operator = $this->makePlatformAdmin();
        $this->grantPermission($operator, 'Reply SpamPrevent');

        $this->assertRefused($this->actingAs($operator->fresh())->postJson('/spam-prevent', ['comment' => 'x', 'customer_id' => $customer->id]));
        $this->assertSame(0, DB::table('product_comments')->count());
    }

    public function test_the_storefront_guard_refuses_an_anonymous_caller_on_its_own_when_the_administrative_guard_is_absent(): void
    {
        // ModuleAdministrativeRouteGuard (same group) already turns an anonymous caller away from the three merchant-side routes, which would
        // hide a missing check here. Take it out, and the storefront guard must still refuse by itself.
        $this->withoutMiddleware(\App\Http\Middleware\ModuleAdministrativeRouteGuard::class);

        [, $store] = $this->makeTenant();
        $customer = $this->shopper($store);
        $this->comment($customer->id, $store->id, 'kept');

        $this->assertRefused($this->postJson('/spam-prevent', ['comment' => 'x', 'customer_id' => $customer->id]));

        // spamprevent.edit / .destroy sit behind the module's own `auth` middleware, which turns an anonymous caller away BEFORE the group runs, so
        // the real routes cannot show whether the guard refuses by itself. The guard keys on the route NAME and the `spamprevent` parameter:
        // register routes with the same name and parameter in the `web` group alone, which is what the guard sees if a module update drops `auth`.
        Route::middleware('web')->get('/p1-sec-04/synthetic-thread/{spamprevent}/edit', fn () => response('REACHED'))->name('spamprevent.edit');
        Route::middleware('web')->delete('/p1-sec-04/synthetic-thread/{spamprevent}', fn () => response('REACHED'))->name('spamprevent.destroy');

        $edit = $this->get('/p1-sec-04/synthetic-thread/' . $customer->id . '/edit');
        $destroy = $this->delete('/p1-sec-04/synthetic-thread/' . $customer->id);

        $this->assertNotSame('REACHED', $edit->getContent());
        $this->assertNotSame('REACHED', $destroy->getContent());
        $this->assertRefused($edit);
        $this->assertRefused($destroy);
        $this->assertSame(1, DB::table('product_comments')->count());
    }

    // ---- GET spamprevent/{id}/edit, DELETE spamprevent/{id} -------------------------------------

    public function test_a_merchant_cannot_read_or_delete_another_stores_customer_thread_but_can_their_own(): void
    {
        [$a, $storeA] = $this->makeTenant();
        [$b, $storeB] = $this->makeTenant();
        $this->entitle($a, 'SpamPrevent');
        $this->entitle($b, 'SpamPrevent');
        $mine = $this->shopper($storeA);
        $foreign = $this->shopper($storeB);
        $this->comment($mine->id, $storeA->id, 'mine');
        $this->comment($foreign->id, $storeB->id, 'foreign');

        $this->assertRefused($this->actingAs($a->fresh())->delete('/spamprevent/' . $foreign->id));
        $this->assertSame(1, DB::table('product_comments')->where('customer_id', (string) $foreign->id)->count(), 'The stranger deleted the thread.');

        $this->assertRefused($this->actingAs($a->fresh())->get('/spamprevent/' . $foreign->id . '/edit'));

        $this->actingAs($a->fresh())->delete('/spamprevent/' . $mine->id);
        $this->assertSame(0, DB::table('product_comments')->where('customer_id', (string) $mine->id)->count(), 'The positive control deleted nothing: the route fails for everyone.');
    }

    public function test_the_platform_operator_may_read_and_delete_any_customers_thread(): void
    {
        $this->makeTenant();                      // store 1, so the customer below is in a store the operator is NOT "in" by default
        [, $store] = $this->makeTenant();
        $this->assertNotSame(1, $store->id);
        $customer = $this->shopper($store);
        $this->comment($customer->id, $store->id, 'to-be-removed');
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->get('/spamprevent/' . $customer->id . '/edit')->assertOk();
        $this->actingAs($operator)->delete('/spamprevent/' . $customer->id);

        $this->assertSame(0, DB::table('product_comments')->where('customer_id', (string) $customer->id)->count(), 'The positive control deleted nothing: the route fails for the operator.');
    }

    // ---- POST {slug}/product-review -------------------------------------------------------------

    public function test_a_visitors_review_lands_in_the_slugs_store_with_the_forms_status_whatever_the_body_says(): void
    {
        [$a, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $product = $this->productIn($storeA, $a);

        $category = (int) DB::table('categories')->insertGetId(['name' => 'c-' . uniqid(), 'slug' => 'c-' . uniqid(), 'store_id' => $storeA->id]);

        $this->post('/' . $storeA->slug . '/product-review', [
            'description' => 'nice', 'category_id' => $category, 'product_id' => $product->id, 'rating_no' => 5, 'store_id' => $storeB->id, 'status' => 0,
        ]);

        $row = DB::table('product_reviews')->first();
        $this->assertNotNull($row, 'The positive control stored nothing: the route fails for everyone.');
        $this->assertSame($storeA->id, (int) $row->store_id, 'The review was planted into the store the body named.');
        $this->assertSame(1, (int) $row->status);
        $this->assertSame(1, DB::table('product_reviews')->count());
    }

    public function test_a_review_naming_another_stores_product_or_an_unknown_store_is_refused(): void
    {
        [$a, $storeA] = $this->makeTenant();
        [$b, $storeB] = $this->makeTenant();
        $foreignProduct = $this->productIn($storeB, $b);
        $ownProduct = $this->productIn($storeA, $a);

        $this->assertRefused($this->post('/' . $storeA->slug . '/product-review', ['description' => 'x', 'product_id' => $foreignProduct->id]));
        // MySQL reads '5abc' as 5 in `where id = '5abc'`: a product id with trailing junk must not name the store's REAL product N
        $this->assertRefused($this->post('/' . $storeA->slug . '/product-review', ['description' => 'x', 'product_id' => $ownProduct->id . 'abc']));
        $this->assertRefused($this->post('/' . $storeA->slug . '/product-review', ['description' => 'x', 'product_id' => $ownProduct->id . '.9']));
        $this->assertRefused($this->post('/no-such-store-' . uniqid() . '/product-review', ['description' => 'x']));
        $this->assertSame(0, DB::table('product_reviews')->count());
    }

    // ---- P1-SEC-34: the two holes the P1-SEC-04 review left open on these same two routes -------

    public function test_a_shopper_of_another_store_cannot_comment_through_this_stores_slug(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $shopperOfA = $this->shopper($storeA);

        // `customers` is ONE session guard on ONE host: A's shopper carries A's session to B's slug. Deriving `customer_id` from the
        // session (what P1-SEC-04 fixed) is not enough -- the derived customer still has to belong to the store being written.
        $this->assertRefused($this->actingAs($shopperOfA, 'customers')->post('/' . $storeB->slug . '/spamprevent', ['comment' => 'hi']));
        $this->assertSame(0, DB::table('product_comments')->count());

        // the positive twin, so the refusal above is not just "this route fails for everyone"
        $this->actingAs($this->shopper($storeB), 'customers')->post('/' . $storeB->slug . '/spamprevent', ['comment' => 'hi'])->assertOk();
        $this->assertSame(1, DB::table('product_comments')->count());
    }

    public function test_a_comment_naming_another_stores_product_or_one_that_does_not_exist_is_refused(): void
    {
        [$a, $storeA] = $this->makeTenant();
        [$b, $storeB] = $this->makeTenant();
        $me = $this->shopper($storeA);
        $foreign = $this->productIn($storeB, $b);
        $own = $this->productIn($storeA, $a);

        $post = fn (array $body) => $this->actingAs($me, 'customers')->post('/' . $storeA->slug . '/spamprevent', $body);

        $this->assertRefused($post(['comment' => 'x', 'product_id' => $foreign->id]));
        $this->assertRefused($post(['comment' => 'x', 'product_id' => $own->id + 10_000]));
        // MySQL reads '5abc' as 5 in `where id = '5abc'`, so trailing junk must not name the store's REAL product N
        $this->assertRefused($post(['comment' => 'x', 'product_id' => $own->id . 'abc']));
        $this->assertSame(0, DB::table('product_comments')->count(), 'A refused comment was stored anyway.');

        $post(['comment' => 'x', 'product_id' => $own->id])->assertOk();
        $this->assertSame((string) $own->id, (string) DB::table('product_comments')->first()->product_id);
    }

    public function test_a_comment_with_no_product_id_is_still_accepted(): void
    {
        // The module requires only `comment` and its own screen reads `product_id` as nullable, so the guard must not start demanding one.
        [, $store] = $this->makeTenant();

        $this->actingAs($this->shopper($store), 'customers')->post('/' . $store->slug . '/spamprevent', ['comment' => 'no product here'])->assertOk();

        $this->assertNull(DB::table('product_comments')->first()->product_id);
    }

    public function test_a_reviews_description_and_title_are_stored_without_markup(): void
    {
        // ProductReviewDataTable:59 puts `description` in rawColumns(), so the merchant's own review table prints it UNESCAPED -- and this
        // route is anonymous. The module is vendored, so the markup comes off at the door.
        //
        // `product_reviews.product_id` is NOT NULL and `category_id` carries an FK to categories, so a review needs both: the attacker
        // supplies the store's own product and category, which the public product page and the module's own form both hand out.
        [$owner, $store] = $this->makeTenant();
        $product = $this->productIn($store, $owner);
        $category = (int) DB::table('categories')->insertGetId(['name' => 'c-' . uniqid(), 'slug' => 'c-' . uniqid(), 'store_id' => $store->id]);

        $this->post('/' . $store->slug . '/product-review', [
            'description' => 'good <script>alert(document.cookie)</script><b>product</b>',
            'title' => '<img src=x onerror=alert(1)>nice',
            'rating_no' => 5, 'product_id' => $product->id, 'category_id' => $category,
        ]);

        $row = DB::table('product_reviews')->first();
        $this->assertNotNull($row, 'The positive control stored nothing: the route fails for everyone.');
        $this->assertStringNotContainsString('<', (string) $row->description);
        $this->assertStringNotContainsString('<', (string) $row->title);
        $this->assertStringContainsString('good', (string) $row->description);
        $this->assertStringContainsString('nice', (string) $row->title);
    }

    public function test_premise_the_vendored_review_table_still_prints_description_unescaped(): void
    {
        // WHY THE STRIP ABOVE EXISTS, pinned so the reason is auditable and so this turns red if upstream ever escapes the column --
        // at which point the door-step strip could be reconsidered rather than carried forever on faith.
        //
        // Yajra escapes every column by default; rawColumns() is the opt OUT. `description` is in that list, and the route that fills it
        // is anonymous, so markup stored there would reach the merchant's admin origin as live HTML.
        $source = (string) file_get_contents(base_path('packages/workdo/ProductReview/src/DataTables/ProductReviewDataTable.php'));

        $this->assertSame(1, preg_match('/->rawColumns\(\[([^\]]*)\]\)/', $source, $m), 'ProductReviewDataTable no longer calls rawColumns().');
        $this->assertStringContainsString("'description'", $m[1],
            'ProductReviewDataTable no longer prints `description` unescaped. The guard still strips markup at the door, which is now '
            . 'belt-and-braces rather than the only control -- re-read P1-SEC-34 before relaxing it.');

        // and the column really is displayed, so the sink is reachable at all
        $this->assertStringContainsString("'description' => ['title'", $source);
    }

    public function test_a_review_that_sends_no_title_still_stores_null_rather_than_an_empty_string(): void
    {
        // Contract preservation: only keys the caller SENT are rewritten, so the module keeps writing NULL for an absent title.
        [$owner, $store] = $this->makeTenant();
        $product = $this->productIn($store, $owner);
        $category = (int) DB::table('categories')->insertGetId(['name' => 'c-' . uniqid(), 'slug' => 'c-' . uniqid(), 'store_id' => $store->id]);

        $this->post('/' . $store->slug . '/product-review', [
            'description' => 'plain text', 'rating_no' => 4, 'product_id' => $product->id, 'category_id' => $category,
        ]);

        $row = DB::table('product_reviews')->first();
        $this->assertNotNull($row, 'The positive control stored nothing: the route fails for everyone.');
        $this->assertNull($row->title);
    }
}
