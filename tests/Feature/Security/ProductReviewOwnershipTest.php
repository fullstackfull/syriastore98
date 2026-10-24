<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-34 FOLLOW-UP -- ProductReview IS THE THIRD CLONE OF THE P1-INV-17 SHAPE, AND P1-SEC-34 MIS-CLASSIFIED IT.
 * ===============================================================================================================
 *
 * Found by the read-only multi-lens review OF the P1-SEC-34 fix, which corrected that commit's own record. P1-SEC-34 closed the ANONYMOUS
 * storefront write into `product_reviews.description` -- a column the merchant's review table prints through Yajra `rawColumns()`
 * (packages/workdo/ProductReview/src/DataTables/ProductReviewDataTable.php:59) i.e. UNESCAPED -- and then described the merchant-side
 * resource route as "authenticated, same-tenant ... a staff member able to put script on the owner's screen".
 *
 * That is true of store() and FALSE of update(), which is CROSS-TENANT. `Workdo\ProductReview\app\Models\ProductReview` uses only
 * HasFactory + Cachable -- no BelongsToStore -- and is absent from config/tenancy.php `scoped_models`, so StoreScope adds NO predicate for
 * ANY principal (CLAUDE.md's rule, the same one that makes `Product` reachable across tenants). All three handlers are therefore
 * `ProductReview::find($id)` on a shared auto-increment id:
 *
 *   edit()    ProductReviewController.php:85-91  -- NO PERMISSION CHECK AT ALL; renders another store's review into a Form::model() view
 *   update()  :97-135  -- checks 'Edit Product Review', never the owner, and NEVER ASSIGNS store_id, so the victim's row is rewritten
 *                         IN PLACE and keeps store_id = the victim's. Their own screen then renders the attacker's markup, in THEIR
 *                         admin origin, under THEIR session.
 *   destroy() :140-152 -- checks 'Delete Product Review', never the owner
 *
 * The fix is four method+URI entries in ModuleResourceOwnershipGuard::TARGETS -- the mechanism P1-INV-17 built and P1-INV-23 reused --
 * and nothing under packages/workdo/.
 *
 * EVERY REFUSAL CASE HERE HAS A POSITIVE TWIN, so "the row did not change" cannot pass on a route that is broken for everyone.
 */
class ProductReviewOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'ProductReview',
            'name' => 'ProductReview',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'product-review',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['ProductReview']);

        foreach (glob(base_path('packages/workdo/ProductReview/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('product_reviews'), 'Premise: the module migrations ran.');
        $this->assertNotNull(\Illuminate\Support\Facades\Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/product-review/1', 'PUT')
        ), 'Premise: the module resource routes are registered.');
    }

    /** `PlanModuleCheck` refuses a non-operator whose plan lacks the module; without this every case below dies before a handler. */
    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert(['user_id' => $owner->id, 'module' => 'ProductReview', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertContains('ProductReview', ActivatedModule($owner->id), 'The entitlement fixture did not take.');
    }

    /** `product_reviews.category_id` carries an FK to `categories`, so a review needs a real category row -- 0 does not satisfy it. */
    private function category(int $storeId): int
    {
        return (int) DB::table('categories')->insertGetId([
            'name' => 'c-' . uniqid(), 'slug' => 'c-' . uniqid(), 'store_id' => $storeId,
        ]);
    }

    private function review(int $storeId, string $description = 'the victim wrote this'): int
    {
        return (int) DB::table('product_reviews')->insertGetId([
            'category_id' => $this->category($storeId), 'product_id' => 1, 'rating_no' => 5, 'title' => 'victim title',
            'description' => $description, 'status' => 1, 'store_id' => $storeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: int, 2: int} description, store_id, and the row count -- read raw, since ProductReview carries no scope to trust */
    private function row(int $id): array
    {
        $r = DB::table('product_reviews')->where('id', $id)->first();

        return [(string) ($r->description ?? ''), (int) ($r->store_id ?? 0), DB::table('product_reviews')->where('id', $id)->count()];
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category(1), 'product_id' => 1, 'rating_no' => 1,
            'title' => 'rewritten', 'description' => self::PAYLOAD, 'status' => 1,
        ], $overrides);
    }

    // =============================================================================================
    //  THE CROSS-TENANT WRITE -- the one P1-SEC-34 recorded as "same-tenant"
    // =============================================================================================

    public function test_a_merchant_cannot_rewrite_another_stores_review_into_the_unescaped_sink(): void
    {
        $this->enableModule();
        [$attacker, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        // The attacker HOLDS the permission: without this the refusal could be the permission limb, not ownership, and the test would
        // prove nothing about tenancy. Granting it also creates the `permissions` row, which is the production state after the seeder.
        $this->grantPermission($attacker, 'Edit Product Review');
        $this->entitle($attacker);
        $victim = $this->review($storeB->id);

        $response = $this->actingAs($attacker->fresh())->put('/product-review/' . $victim, $this->body());

        $this->assertNotSame(401, $response->status(), 'A 401 is a forced logout in the Flutter app.');

        [$description, $storeId] = $this->row($victim);
        $this->assertSame('the victim wrote this', $description,
            "Store B's review was rewritten by store A. Its `description` is printed through rawColumns, so this is stored script in B's "
            . 'own admin origin.');
        $this->assertSame($storeB->id, $storeId, 'The row left its store.');
    }

    /** PATCH is a separate method+URI key in TARGETS (keyed on method, not uri), so it needs its own case. */
    public function test_a_merchant_cannot_patch_another_stores_review(): void
    {
        $this->enableModule();
        [$attacker, ] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Product Review');
        $this->entitle($attacker);
        $victim = $this->review($storeB->id);

        $this->actingAs($attacker->fresh())->patch('/product-review/' . $victim, $this->body());

        $this->assertSame('the victim wrote this', $this->row($victim)[0], 'PATCH reached the handler that PUT is refused on.');
    }

    public function test_a_merchant_can_still_rewrite_their_own_review(): void
    {
        // THE POSITIVE TWIN for both cases above: without it, "the row did not change" would pass on a route broken for everyone.
        $this->enableModule();
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Product Review');
        $this->entitle($owner);
        $mine = $this->review($store->id);

        $this->actingAs($owner->fresh())->put('/product-review/' . $mine, $this->body(['description' => 'my own correction']));

        $this->assertSame('my own correction', $this->row($mine)[0], 'The guard refused the owner their own row.');
    }

    // =============================================================================================
    //  THE CROSS-TENANT READ -- edit() checks NO permission at all
    // =============================================================================================

    public function test_a_merchant_cannot_read_another_stores_review_through_the_edit_form(): void
    {
        $this->enableModule();
        [$attacker, ] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Product Review');
        $this->entitle($attacker);
        $victim = $this->review($storeB->id, 'COMMERCIALLY SENSITIVE VICTIM TEXT');

        $response = $this->actingAs($attacker->fresh())->get('/product-review/' . $victim . '/edit');

        $this->assertStringNotContainsString('COMMERCIALLY SENSITIVE VICTIM TEXT', (string) $response->getContent(),
            "Another store's review text came back in the edit form. edit() checks no permission at all and the model carries no scope.");
        $this->assertNotSame(401, $response->status());
    }

    public function test_a_merchant_can_still_read_their_own_edit_form(): void
    {
        $this->enableModule();
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Product Review');
        $this->entitle($owner);
        $mine = $this->review($store->id, 'MY OWN REVIEW TEXT');

        $response = $this->actingAs($owner->fresh())->get('/product-review/' . $mine . '/edit');

        $this->assertStringContainsString('MY OWN REVIEW TEXT', (string) $response->getContent(),
            'The guard refused the owner their own edit form.');
    }

    // =============================================================================================
    //  THE CROSS-TENANT DELETE
    // =============================================================================================

    public function test_a_merchant_cannot_delete_another_stores_review(): void
    {
        $this->enableModule();
        [$attacker, ] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Product Review');
        $this->entitle($attacker);
        $victim = $this->review($storeB->id);

        $this->actingAs($attacker->fresh())->delete('/product-review/' . $victim);

        $this->assertSame(1, $this->row($victim)[2], "Store B's review was deleted by store A.");
    }

    public function test_a_merchant_can_still_delete_their_own_review(): void
    {
        $this->enableModule();
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Delete Product Review');
        $this->entitle($owner);
        $mine = $this->review($store->id);

        $this->actingAs($owner->fresh())->delete('/product-review/' . $mine);

        $this->assertSame(0, $this->row($mine)[2], 'The guard refused the owner their own delete.');
    }

    // =============================================================================================
    //  THE OPERATOR, AND THE ROW NOBODY OWNS
    // =============================================================================================

    public function test_the_platform_operator_is_not_refused_by_this_guard(): void
    {
        // ASSERTED ON edit(), DELIBERATELY. `isAbleTo()` alone refuses the platform operator (Laratrust, no super-admin bypass), and the
        // module's own update()/destroy() call it -- so the operator cannot write a review TODAY, with or without this guard, and a write
        // case here would prove nothing about the guard. edit() checks no permission at all, so it is the one target where the guard's own
        // operator-first ordering is observable: if that ordering ever breaks, the operator loses a page they can reach today.
        $this->enableModule();
        [, $storeB] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $row = $this->review($storeB->id, 'VISIBLE TO THE OPERATOR');

        $response = $this->actingAs($operator)->get('/product-review/' . $row . '/edit');

        $this->assertStringContainsString('VISIBLE TO THE OPERATOR', (string) $response->getContent(),
            'The guard locked the platform operator out of a page they can reach today.');
    }

    public function test_a_review_with_no_store_is_refused_to_everyone(): void
    {
        // `product_reviews.store_id` is NULLABLE (its migration), and readRowStore returns null for such a row. A legacy review with no
        // store has no owner to compare against, so it must be refused rather than offered to the first caller.
        $this->enableModule();
        [$merchant, ] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Product Review');
        $this->entitle($merchant);

        $orphan = (int) DB::table('product_reviews')->insertGetId([
            'category_id' => $this->category(1), 'product_id' => 1, 'rating_no' => 5, 'title' => 't',
            'description' => 'unowned', 'status' => 1, 'store_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($merchant->fresh())->put('/product-review/' . $orphan, $this->body());

        $this->assertSame('unowned', $this->row($orphan)[0], 'A store-less row was claimed by the first caller who asked.');
    }
}
