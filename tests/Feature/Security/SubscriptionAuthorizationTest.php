<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * THE SUBSCRIPTION-AUTHORIZATION RE-CHECK ITEMS, each established by execution rather than by
 * reading, because "already fixed" has been wrong in this project before.
 *
 * Covered here:
 *   order.approve / order.reject  NEW-PLAN-APPROVE-UNGATED-01  (fixed, route middleware)
 *   plan.order.refund             NEW-PLAN-REFUND-UNGATED-01   (fixed, route middleware)
 *   ModuleController::enable()    NEW-MODULE-ENABLE-UNGATED-01 (fixed, in the controller)
 *   store ownership checks        NEW-STORE-OWNERSHIP-01       (fixed, in StoreController)
 *
 * EditProfile is covered elsewhere and deliberately not duplicated: the API half
 * (Api\DashboardController::EditProfile) is pinned by AdminApiTenancyTest:385 and :410 through
 * resolveManageableUser(), and the web half (UserController::editprofile, routes/web.php
 * `update.account`) takes NO user id from the request at all -- it writes
 * `\Auth::guard()->user()->id` only -- so there is no caller-supplied identity to abuse. That
 * is asserted below as a source property, so a future edit that introduces one turns this red.
 *
 * ASSERTION ORDER IS DELIBERATE THROUGHOUT: the database readback comes BEFORE the status-code
 * assertion, so a regression reports what was actually destroyed or granted rather than failing
 * on a status and never reaching the question.
 */
class SubscriptionAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Gold-' . uniqid(),
            'price' => 100,
            'duration' => 'Month',
            'max_users' => 10,
            'max_stores' => 2,
            // NOT NULL with no default, verified from the schema: plans.themes and
            // plans.storage_limit.
            'themes' => '',
            'storage_limit' => '0',
            'description' => 'test plan',
            'image' => null,
            'modules' => null,
            'is_disable' => 1,
        ], $attributes));
    }

    private function makePlanOrder(User $user, Plan $plan, Store $store, array $attributes = []): int
    {
        return (int) DB::table('plan_orders')->insertGetId(array_merge([
            'order_id' => 'PO-' . uniqid(),
            'plan_name' => $plan->name,
            'plan_id' => $plan->id,
            'price' => $plan->price,
            'price_currency' => 'USD',
            'txn_id' => '',
            'payment_status' => 'Pending',
            'payment_type' => 'Bank Transfer',
            'user_id' => $user->id,
            'store_id' => $store->id,
            'is_refund' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    // =========================================================================================
    //  order.approve / order.reject -- A FREE PAID PLAN FOR ANY AUTHENTICATED MERCHANT
    // =========================================================================================

    /**
     * THE REVENUE BYPASS. A merchant uploads a bank-transfer receipt, their plan order sits at
     * 'Pending', and the Approval button in resources/views/order/show.blade.php:39 is rendered
     * to THEM. BankTransferController::orderapprove:119 had no authorization at all, so clicking
     * it set payment_status='Approved' and called assignPlan() -- a paid plan, unpaid.
     */
    public function test_a_merchant_cannot_approve_their_own_pending_bank_transfer_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $plan = $this->makePlan();
        $orderId = $this->makePlanOrder($merchant, $plan, $store);

        $planBefore = $merchant->fresh()->plan_id;

        $response = $this->actingAs($merchant)->get(route('order.approve', [$orderId]));

        $this->assertSame(
            'Pending',
            DB::table('plan_orders')->where('id', $orderId)->value('payment_status'),
            'REVENUE BYPASS: a merchant approved their own unpaid bank-transfer plan order.'
        );
        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            'REVENUE BYPASS: the merchant was granted a plan by approving their own payment.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_merchant_cannot_approve_another_merchants_order(): void
    {
        [$attacker] = $this->makeTenant();
        [$victim, $victimStore] = $this->makeTenant();
        $plan = $this->makePlan();
        $orderId = $this->makePlanOrder($victim, $plan, $victimStore);

        $response = $this->actingAs($attacker)->get(route('order.approve', [$orderId]));

        $this->assertSame(
            'Pending',
            DB::table('plan_orders')->where('id', $orderId)->value('payment_status'),
            'A merchant changed the payment status of another merchant\'s plan order.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_merchant_cannot_reject_a_plan_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $plan = $this->makePlan();
        $orderId = $this->makePlanOrder($merchant, $plan, $store);

        $response = $this->actingAs($merchant)->get(route('order.reject', [$orderId]));

        $this->assertSame(
            'Pending',
            DB::table('plan_orders')->where('id', $orderId)->value('payment_status'),
            'A merchant moved a plan order out of Pending.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * THE POSITIVE PROOF. The platform operator -- the only principal this review is actually
     * for -- still approves a bank transfer and the plan is still assigned.
     */
    public function test_the_platform_operator_can_still_approve_a_bank_transfer_order(): void
    {
        $admin = $this->makePlatformAdmin();
        [$merchant, $store] = $this->makeTenant();
        $plan = $this->makePlan();
        $orderId = $this->makePlanOrder($merchant, $plan, $store);

        $this->actingAs($admin)->get(route('order.approve', [$orderId]));

        $this->assertSame(
            'Approved',
            DB::table('plan_orders')->where('id', $orderId)->value('payment_status'),
            'The platform operator can no longer approve a bank transfer, which is a regression.'
        );
        $this->assertSame(
            $plan->id,
            (int) $merchant->fresh()->plan_id,
            'Approval no longer assigns the purchased plan.'
        );
    }

    // =========================================================================================
    //  plan.order.refund -- DOWNGRADE ANY MERCHANT ON THE PLATFORM
    // =========================================================================================

    /**
     * PlanController::refund marks any plan order refunded AND calls `assignPlan(1)` on a
     * caller-supplied user_id, so it is both a false refund record and a way to downgrade a
     * THIRD PARTY to the free plan. Both halves are asserted.
     */
    public function test_a_merchant_cannot_refund_a_plan_order_or_downgrade_another_merchant(): void
    {
        [$attacker] = $this->makeTenant();
        [$victim, $victimStore] = $this->makeTenant();

        $freePlan = $this->makePlan(['name' => 'Free', 'price' => 0]);
        $paidPlan = $this->makePlan();

        $victim->plan_id = $paidPlan->id;
        $victim->save();

        $orderId = $this->makePlanOrder($victim, $paidPlan, $victimStore, [
            'payment_status' => 'succeeded',
        ]);

        $response = $this->actingAs($attacker)
            ->get(route('plan.order.refund', [$orderId, $victim->id]));

        $this->assertSame(
            0,
            (int) DB::table('plan_orders')->where('id', $orderId)->value('is_refund'),
            'A merchant marked another merchant\'s plan order as refunded.'
        );
        $this->assertSame(
            $paidPlan->id,
            (int) $victim->fresh()->plan_id,
            'SABOTAGE: a merchant downgraded another merchant\'s subscription.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_merchant_cannot_refund_their_own_plan_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $plan = $this->makePlan();
        $orderId = $this->makePlanOrder($merchant, $plan, $store, ['payment_status' => 'succeeded']);

        $response = $this->actingAs($merchant)
            ->get(route('plan.order.refund', [$orderId, $merchant->id]));

        $this->assertSame(
            0,
            (int) DB::table('plan_orders')->where('id', $orderId)->value('is_refund'),
            'A merchant recorded a refund against their own order without the platform agreeing.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_the_platform_operator_can_still_refund_a_plan_order(): void
    {
        $admin = $this->makePlatformAdmin();
        [$merchant, $store] = $this->makeTenant();

        // PlanController::refund hardcodes assignPlan(1), so plan id 1 must exist. Created
        // first so it takes id 1.
        $freePlan = $this->makePlan(['name' => 'Free', 'price' => 0]);
        $this->assertSame(1, $freePlan->id, 'Fixture assumption: the free plan is id 1.');

        $paidPlan = $this->makePlan();
        $merchant->plan_id = $paidPlan->id;
        $merchant->save();

        $orderId = $this->makePlanOrder($merchant, $paidPlan, $store, ['payment_status' => 'succeeded']);

        $this->actingAs($admin)->get(route('plan.order.refund', [$orderId, $merchant->id]));

        $this->assertSame(
            1,
            (int) DB::table('plan_orders')->where('id', $orderId)->value('is_refund'),
            'The platform operator can no longer record a refund, which is a regression.'
        );
    }

    // =========================================================================================
    //  ModuleController::enable() -- A PLATFORM-WIDE SWITCH WITH NO GATE
    // =========================================================================================

    private function seedEnabledAddon(string $module = 'BulkDelete'): void
    {
        AddOnManager::create([
            'module' => $module,
            'name' => $module,
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => strtolower($module),
        ]);

        Cache::flush();
    }

    /**
     * `add_on_managers.is_enable` is ONE global row per module, so this endpoint turns a module
     * off for every store on the platform. It required only a session.
     */
    public function test_a_merchant_without_manage_module_cannot_disable_a_module_platform_wide(): void
    {
        $this->seedEnabledAddon();
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant)->post(route('module.enable'), ['name' => 'BulkDelete']);

        $this->assertSame(
            1,
            (int) DB::table('add_on_managers')->where('module', 'BulkDelete')->value('is_enable'),
            'PLATFORM-WIDE: a merchant disabled an addon module for every store.'
        );
        $this->assertNotSame(500, $response->getStatusCode());
    }

    /**
     * The module name reached `Artisan::call('migrate --path=/packages/workdo/' . $request->name
     * . '/...')`, `Artisan::call('package:seed ' . $request->name)` and
     * `base_path('packages/workdo/' . $request->name . '/module.json')` unvalidated.
     * `Module::find()` cannot reject it -- app/Classes/Module.php:75-88 fabricates a Module for
     * ANY string -- so the allowlist is `Module::has()`, over the real package directories.
     *
     * Run as the PLATFORM OPERATOR on purpose: with the permission check satisfied, a pass here
     * can only be the name validation.
     */
    public function test_a_module_name_that_is_not_an_installed_module_is_refused(): void
    {
        $admin = $this->makePlatformAdmin();

        foreach (['../../../etc', 'NotAModule', 'BulkDelete/../LandingPage', ''] as $name) {
            $this->actingAs($admin)->post(route('module.enable'), ['name' => $name]);

            $this->assertSame(
                0,
                DB::table('add_on_managers')->where('module', $name)->count(),
                "A bogus module name `{$name}` created an add_on_managers row, so it reached the "
                . 'Artisan calls and the module.json read.'
            );
        }

        $this->assertSame(0, DB::table('add_on_managers')->count());
    }

    /**
     * THE POSITIVE PROOF. The platform operator still toggles a module. 'Manage Module' is
     * listed in config/superadminaccess.php:10, so the operator is the intended principal.
     */
    public function test_the_platform_operator_can_still_disable_an_installed_module(): void
    {
        $this->seedEnabledAddon();
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)->post(route('module.enable'), ['name' => 'BulkDelete']);

        $this->assertSame(
            0,
            (int) DB::table('add_on_managers')->where('module', 'BulkDelete')->value('is_enable'),
            'The platform operator can no longer disable a module, which is a regression.'
        );
    }

    /**
     * The permission name is real. Gating on a name Utility::addNewData() does not create denies
     * everyone -- the defect that once 403'd the whole finance surface.
     */
    public function test_manage_module_is_a_seeded_permission_and_is_platform_scoped(): void
    {
        $utility = file_get_contents(base_path('app/Models/Utility.php'));

        $this->assertGreaterThan(
            0,
            substr_count($utility, "'Manage Module'") + substr_count($utility, '"Manage Module"'),
            "'Manage Module' must be created by Utility::addNewData()."
        );

        $this->assertContains(
            'Manage Module',
            config('superadminaccess'),
            'If Manage Module is no longer platform-scoped, revisit whether enable() should '
            . 'require it.'
        );

        // The same name index() already requires, so the form and the action agree.
        $controller = file_get_contents(base_path('app/Http/Controllers/ModuleController.php'));
        $this->assertSame(
            2,
            substr_count($controller, "isAbleTo('Manage Module')"),
            'index() and enable() must require the same permission.'
        );

        // And enable() must keep its explicit operator path, because isAbleTo() has none.
        $this->assertStringContainsString("=== 'super admin'", $controller,
            'enable() must retain the platform-operator path, or an unseeded permission row '
            . 'locks the operator out of module management entirely.');
    }

    // =========================================================================================
    //  StoreController -- THREE CALLER-SUPPLIED STORE IDS WITH NO OWNERSHIP TEST
    // =========================================================================================

    /**
     * THE WORST OF THE THREE, and worse than the bulk-delete endpoint: ownerstoredestroy
     * deletes the target store's pages, orders, app settings, blogs, contacts, coupons,
     * categories, newsletters, plan orders, testimonials, settings, shippings, taxes, product
     * variants and shetabit_visits rows, runs ProductController::destroy over every product
     * (which unlinks uploaded files from disk) and then deletes the store -- all irreversibly.
     *
     * The `$user_stores > 1` guard counted the CALLER's stores while the deletion ran against a
     * store id from the URL, so any merchant with two stores could destroy any other merchant's.
     */
    public function test_a_merchant_cannot_destroy_another_merchants_store_or_its_content(): void
    {
        [$attacker] = $this->makeTenant();
        $this->makeStore($attacker); // the attacker's second store, so the > 1 guard passes

        [$victim, $victimStore] = $this->makeTenant();

        // `pages` has page_name / page_slug, not title / slug (verified from the schema).
        $pageId = (int) DB::table('pages')->insertGetId([
            'page_name' => 'victim page', 'page_slug' => 'victim-page-' . uniqid(),
            'store_id' => $victimStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $blogId = (int) DB::table('blogs')->insertGetId([
            'title' => 'victim blog', 'slug' => 'victim-blog-' . uniqid(),
            'store_id' => $victimStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($attacker)
            ->delete(route('ownerstore.remove', [$victimStore->id]));

        $this->assertSame(
            1,
            DB::table('stores')->where('id', $victimStore->id)->count(),
            'IRREVERSIBLE CROSS-TENANT DESTRUCTION: another merchant\'s store was deleted.'
        );
        $this->assertSame(1, DB::table('pages')->where('id', $pageId)->count(),
            'The victim store\'s pages were deleted.');
        $this->assertSame(1, DB::table('blogs')->where('id', $blogId)->count(),
            'The victim store\'s blog posts were deleted.');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * THE POSITIVE PROOF for the same method: a merchant with two stores can still delete their
     * OWN second store, which is the feature this endpoint exists for.
     */
    public function test_a_merchant_can_still_destroy_their_own_second_store(): void
    {
        [$merchant, $first] = $this->makeTenant();
        $second = $this->makeStore($merchant);

        $this->actingAs($merchant)->delete(route('ownerstore.remove', [$second->id]));

        $this->assertSame(
            0,
            DB::table('stores')->where('id', $second->id)->count(),
            'A merchant can no longer delete their own store, which is a regression.'
        );
        $this->assertSame(1, DB::table('stores')->where('id', $first->id)->count());
    }

    /**
     * changeStatus took `Store::find($request->id)` with no ownership test, so any authenticated
     * user could take any store's storefront offline.
     */
    public function test_a_merchant_cannot_deactivate_another_merchants_store(): void
    {
        [$attacker] = $this->makeTenant();
        [$victim, $victimStore] = $this->makeTenant();

        // THE VICTIM NEEDS A SECOND ACTIVE STORE, and this is not fixture padding. changeStatus
        // refuses to deactivate a merchant's LAST active store (:1030-1036), so with only one
        // store the row survives for a reason that has nothing to do with authorization -- which
        // a teeth proof caught: with the ownership predicate removed, this test still passed on
        // its database assertion and failed only on the status code. A negative that can pass
        // without the control is not a negative.
        $this->makeStore($victim);

        $response = $this->actingAs($attacker)
            ->get(route('store.active.status', ['id' => $victimStore->id, 'status' => 0]));

        $this->assertSame(
            1,
            (int) DB::table('stores')->where('id', $victimStore->id)->value('is_active'),
            'DENIAL OF SERVICE: a merchant took another merchant\'s storefront offline.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_merchant_can_still_deactivate_their_own_second_store(): void
    {
        [$merchant] = $this->makeTenant();
        $second = $this->makeStore($merchant);

        $this->actingAs($merchant)
            ->get(route('store.active.status', ['id' => $second->id, 'status' => 0]))
            ->assertStatus(200);

        $this->assertSame(
            0,
            (int) DB::table('stores')->where('id', $second->id)->value('is_active'),
            'A merchant can no longer deactivate their own store, which is a regression.'
        );
    }

    /**
     * An unknown store id must not be a 500. changeStatus dereferenced `$store->created_by`
     * BEFORE its own `if ($store)` check, so it raised "Attempt to read property on null".
     */
    public function test_an_unknown_store_id_is_a_denial_and_not_a_server_error(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant)
            ->get(route('store.active.status', ['id' => 999999, 'status' => 0]));

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * pwaSetting rewrote another store's `enable_pwa_store` flag and its published
     * manifest.json. `pwa_store` is left off here so the manifest branch is not entered: the
     * flag write alone is the cross-tenant mutation, and it is the thing asserted.
     */
    public function test_a_merchant_cannot_change_another_merchants_pwa_setting(): void
    {
        [$attacker] = $this->makeTenant();
        [, $victimStore] = $this->makeTenant();

        // THE VICTIM'S FLAG STARTS AT 'on' AND THE ATTACKER POSTS 'off'. The other way round is
        // vacuous: the column defaults to 'off' (2023_12_14_090259_create_stores_table.php:23),
        // so posting 'off' writes the value it already had and the assertion passes with the
        // control removed -- which a teeth proof caught. 'off' is also what the attacker sends
        // deliberately, so the request does NOT enter the manifest-writing branch and this test
        // measures the cross-tenant WRITE and nothing else.
        DB::table('stores')->where('id', $victimStore->id)->update(['enable_pwa_store' => 'on']);

        $response = $this->actingAs($attacker)
            ->post(route('pwa.setting', [$victimStore->id]), ['pwa_store' => 'off']);

        $this->assertSame(
            'on',
            DB::table('stores')->where('id', $victimStore->id)->value('enable_pwa_store'),
            'A merchant rewrote another store\'s PWA setting.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    // =========================================================================================
    //  EditProfile -- ESTABLISHED, NOT RE-IMPLEMENTED
    // =========================================================================================

    /**
     * The WEB half of EditProfile takes no identity from the request: it resolves the subject as
     * `\Auth::guard()->user()->id` and writes that row only. Asserted as a source property so a
     * future edit that introduces a caller-supplied user id turns this red; the behavioural
     * cover for the API half lives in AdminApiTenancyTest.
     */
    public function test_the_web_edit_profile_path_takes_no_caller_supplied_user_id(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/UserController.php'));
        $start = strpos($source, 'function editprofile');

        $this->assertNotFalse($start, 'UserController::editprofile has moved or been renamed.');

        $body = substr($source, $start, 1600);

        $this->assertStringContainsString('$user_id = \Auth::guard()->user()->id;', $body);
        $this->assertStringNotContainsString('$request->user_id', $body,
            'editprofile now reads a user id from the request. It must then compare it against '
            . 'the caller, the way Api\DashboardController::resolveManageableUser() does.');

        $this->assertFileExists(base_path('tests/Feature/Security/AdminApiTenancyTest.php'));
        $this->assertStringContainsString(
            "/api/admin/editprofile",
            (string) file_get_contents(base_path('tests/Feature/Security/AdminApiTenancyTest.php')),
            'The API half of EditProfile must keep its behavioural cover.'
        );
    }
}
