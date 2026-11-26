<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * SAAS-001 — THE USAGE-LIMIT DOORS DEREFERENCE A PLAN THAT MAY NOT EXIST.
 * =======================================================================
 *
 * `SAAS-001` is "الباقات والتجارب والفواتير وحدود الاستخدام" — plans, trials, invoices and usage
 * limits. The usage-limit half is enforced at two doors, and both read the plan the same way:
 *
 *     StoreController::store()      $plan = Plan::find($user->plan_id);
 *                                   if ($total_store < $plan->max_stores || $plan->max_stores == -1)
 *     ProductController::store()    $plan = Plan::find($creator->plan_id);
 *                                   if ($total_products < $plan->max_products || ...)
 *
 * NEITHER ASKS WHETHER `$plan` IS NULL, AND BOTH SHAPES THAT MAKE IT NULL ARE REAL IN THIS SCHEMA:
 *
 *   - `users.plan_id` is nullable and a merchant can hold no plan at all —
 *     `PlatformRevenueFiguresTest::test_a_merchant_with_no_plan_is_in_no_bucket` exists because
 *     that row shape does;
 *   - `users.plan_id` can point at a plan that has been deleted — `PlanController::destroy()` calls
 *     `$plan->delete()` and nothing repoints its subscribers, which is why
 *     `PlatformRevenueFiguresTest::test_a_subscription_pointing_at_a_missing_plan_is_counted_as_
 *     unreadable` and `PlanAssignmentCascadeSafetyTest::test_a_dangling_old_plan_id_does_not_throw_
 *     before_the_entitlement_is_saved` both exist.
 *
 * Reading a property on null is a PHP warning that Laravel's handler promotes to an
 * `ErrorException`, so the door answers HTTP 500 rather than enforcing or refusing a limit. These
 * cases drive both doors in both shapes and state what each one does, measured.
 *
 * WHAT THIS FILE DOES NOT DO: it does not invent a limit for a planless merchant. Whether
 * "no plan" means "no stores", "one store" or "the free plan's allowance" is a business rule and
 * `SAAS-001` does not state it, so the correction keeps the door CLOSED with the refusal the
 * handler already has for an exceeded limit — a planless merchant gets the same answer as one who
 * is out of allowance, which is the conservative reading and changes no allowance anybody has.
 */
final class UsageLimitWithoutAPlanTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        Cache::forget('super_admin_details');
        Cache::forget('admin_details');
    }

    /** @return array{0: \App\Models\User, 1: Store} */
    private function merchantWithoutAPlan(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = null;
        $merchant->save();

        return [$merchant->fresh(), $store];
    }

    /** @return array{0: \App\Models\User, 1: Store} */
    private function merchantWithADanglingPlanId(): array
    {
        $plan = Plan::create([
            'name' => 'Doomed ' . $this->uniqueFixtureRef(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 5,
            'max_products' => 50,
            'max_users' => 5,
            'storage_limit' => 100,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
        ]);

        [$merchant, $store] = $this->makeTenant();
        $merchant->plan_id = $plan->id;
        $merchant->save();

        // Through Eloquent: `Plan` is `Cachable`, so a raw delete would leave reads serving the
        // row and the dangling id would never actually dangle.
        $plan->delete();

        return [$merchant->fresh(), $store];
    }

    private function storePayload(): array
    {
        $ref = $this->uniqueFixtureRef();

        return [
            'storename' => 'Second store ' . $ref,
            'theme_id' => 'stylique',
            'theme_dir' => 'stylique',
        ];
    }

    // ------------------------------------------------------------------ the store door

    #[Test]
    public function test_the_store_limit_door_refuses_a_merchant_with_no_plan_instead_of_failing(): void
    {
        [$merchant] = $this->merchantWithoutAPlan();

        $before = DB::table('stores')->count();

        $response = $this->actingAs($merchant)->post(route('stores.store'), $this->storePayload());

        $this->assertNotSame(500, $response->getStatusCode(), 'a planless merchant must not fatal the door');
        $this->assertSame($before, DB::table('stores')->count(), 'and no store may be created without an allowance');
    }

    #[Test]
    public function test_the_store_limit_door_refuses_a_merchant_whose_plan_was_deleted(): void
    {
        [$merchant] = $this->merchantWithADanglingPlanId();

        $this->assertNull(Plan::find($merchant->plan_id), 'premise: the plan really is gone');

        $before = DB::table('stores')->count();

        $response = $this->actingAs($merchant)->post(route('stores.store'), $this->storePayload());

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame($before, DB::table('stores')->count());
    }

    /**
     * THE CONTROL. A merchant WITH a plan and allowance left still gets their store, so the
     * correction above is a null check and not a new refusal.
     */
    #[Test]
    public function test_a_merchant_with_allowance_left_still_gets_their_store(): void
    {
        $plan = Plan::create([
            'name' => 'Roomy ' . $this->uniqueFixtureRef(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 5,
            'max_products' => 50,
            'max_users' => 5,
            'storage_limit' => 100,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
        ]);

        [$merchant] = $this->makeTenant();
        $merchant->plan_id = $plan->id;
        $merchant->save();

        $before = DB::table('stores')->count();

        $this->actingAs($merchant->fresh())->post(route('stores.store'), $this->storePayload());

        $this->assertSame(
            $before + 1,
            DB::table('stores')->count(),
            'premise: this door really does create a store when the plan allows it'
        );
    }

    // ------------------------------------------------------------------ the product door

    /**
     * THE SAME DEFECT AT THE SECOND DOOR, AND IT WAS ALREADY KNOWN WITHOUT BEING FIXED.
     * `ProductShippingClassOwnershipTest::twoTenants()` builds a plan with this comment: "A PLAN,
     * or the CREATE path answers HTTP 500 for every principal with `Attempt to read property
     * \"max_products\" on null`", and `OperatorCatalogueWriteTest` does the same. Two test
     * fixtures work around it; nothing refuses it.
     */
    private function productPayload(int $categoryId): array
    {
        return [
            'name' => 'Limit probe ' . $this->uniqueFixtureRef(),
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 0,
            'price' => 10,
            'sale_price' => 0,
            'cost_price' => 5,
            'trending' => 0,
            'track_stock' => 1,
            'product_stock' => 7,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ];
    }

    private function categoryIn(Store $store, \App\Models\User $merchant): int
    {
        // `categories` has NO `created_by` column -- read off the live schema after an insert
        // failed on it. CLAUDE.md: the migrations are not the schema.
        return (int) \App\Models\Category::forceCreate([
            'name' => 'Cat ' . $this->uniqueFixtureRef(),
            'slug' => 'cat-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id,
        ])->id;
    }

    #[Test]
    public function test_the_product_limit_door_refuses_a_merchant_with_no_plan_instead_of_failing(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        [$merchant, $store] = $this->merchantWithoutAPlan();
        $this->grantPermission(\App\Models\User::find($merchant->id), 'Create Product');
        $merchant = \App\Models\User::find($merchant->id);

        $category = $this->categoryIn($store, $merchant);
        $before = DB::table('products')->count();

        $response = $this->actingAs($merchant)->post(route('product.store'), $this->productPayload($category));

        $this->assertNotSame(500, $response->getStatusCode(), 'a planless merchant must not fatal the door');
        $this->assertStringNotContainsString(
            'max_products',
            (string) $response->getContent(),
            'and the merchant must not be shown the name of a column'
        );
        $this->assertSame($before, DB::table('products')->count(), 'no product without an allowance');
    }

    #[Test]
    public function test_the_product_limit_door_refuses_a_merchant_whose_plan_was_deleted(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        [$merchant, $store] = $this->merchantWithADanglingPlanId();
        $this->grantPermission(\App\Models\User::find($merchant->id), 'Create Product');
        $merchant = \App\Models\User::find($merchant->id);

        $this->assertNull(Plan::find($merchant->plan_id), 'premise: the plan really is gone');

        $category = $this->categoryIn($store, $merchant);
        $before = DB::table('products')->count();

        $response = $this->actingAs($merchant)->post(route('product.store'), $this->productPayload($category));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString(
            'max_products',
            (string) $response->getContent(),
            'and the merchant must not be shown the name of a column'
        );
        $this->assertSame($before, DB::table('products')->count());
    }

    /** The control for the product door. */
    #[Test]
    public function test_a_merchant_with_product_allowance_left_still_gets_their_product(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $plan = Plan::create([
            'name' => 'Roomy products ' . $this->uniqueFixtureRef(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 5,
            'max_products' => 50,
            'max_users' => 5,
            'storage_limit' => 100,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
        ]);

        [$merchant, $store] = $this->makeTenant();
        $merchant->plan_id = $plan->id;
        $merchant->save();
        $this->grantPermission(\App\Models\User::find($merchant->id), 'Create Product');
        $merchant = \App\Models\User::find($merchant->id);

        $category = $this->categoryIn($store, $merchant);
        $before = DB::table('products')->count();

        $this->actingAs($merchant)->post(route('product.store'), $this->productPayload($category));

        $this->assertSame(
            $before + 1,
            DB::table('products')->count(),
            'premise: this door really does create a product when the plan allows it'
        );
    }
}
