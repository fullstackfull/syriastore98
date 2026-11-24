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

    /**
     * `plans.is_disable` IS NOT FILLABLE, which cost one of these cases a false pass.
     *
     * `Plan::create(['is_disable' => 0])` silently DROPS the value -- the column is absent from
     * `Plan::$fillable` -- and the migration default of 1 applies instead
     * (2024_03_13_062916_add_is_disable_to_plans_table.php:17). The first version of
     * `test_a_merchant_cannot_enable_a_plan_the_operator_disabled` arranged `0`, got `1`, and then
     * failed its own assertion against a fixture that had never been in the state it was testing.
     * Setting the attribute on the instance and saving bypasses mass-assignment protection, which
     * only guards `fill()`.
     */
    private function withdrawnPlan(): Plan
    {
        $plan = $this->makePlan();
        $plan->is_disable = 0;
        $plan->save();

        return $plan;
    }

    /** The stored flag, read past the model cache: `Plan` is `Cachable`. */
    private function storedDisableFlag(int $planId): int
    {
        return (int) DB::table('plans')->where('id', $planId)->value('is_disable');
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

    /**
     * THE MIDDLEWARE'S NORMALISATION, ISOLATED -- and `plan.order.refund` is the route that can
     * isolate it. `PlanController::refund` documents that it has NO in-handler operator test and
     * relies on the route gate alone, so a lookalike admitted by the middleware would reach the
     * write. On `plan.disable` the same lookalike is refused by the handler's own strict `!==`, so
     * a test there cannot tell the two layers apart.
     *
     * `' super admin'` -- a LEADING space -- is the string the project has measured as a
     * NON-operator: MySQL's PAD SPACE ignores a trailing space and not a leading one, and
     * `OperatorCheckCensusTest`'s data provider asserts the helper must refuse it. The middleware
     * used `trim()`, which strips both, and so admitted it.
     */
    public function test_a_leading_space_in_the_type_is_not_the_platform_operator(): void
    {
        $lookalike = $this->makePlatformAdmin(['type' => ' super admin']);

        $this->assertFalse(\App\Policies\UserPolicy::isOperator($lookalike),
            'PREMISE: the canonical helper now treats a leading space as the operator, so this '
            . 'string is no longer the lookalike the test needs.');

        [$victim, $victimStore] = $this->makeTenant();
        $paidPlan = $this->makePlan();
        $victim->plan_id = $paidPlan->id;
        $victim->save();

        $orderId = $this->makePlanOrder($victim, $paidPlan, $victimStore, [
            'payment_status' => 'succeeded',
        ]);

        $response = $this->actingAs($lookalike)
            ->get(route('plan.order.refund', [$orderId, $victim->id]));

        $this->assertSame(
            0,
            (int) DB::table('plan_orders')->where('id', $orderId)->value('is_refund'),
            'A principal whose type is NOT the operator to the database engine was admitted by '
            . 'PlatformOperatorOnly and recorded a refund. The middleware and the engine must '
            . 'agree on who the operator is.'
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
    /**
     * NEW-PLAN-DISABLE-UNGATED-01. `POST /plan-disable` WROTE A PLATFORM TABLE FOR ANYBODY.
     *
     * Found 2026-11-24 by an adversarial verifier reading a Phase 06 subscriptions inventory, and
     * confirmed from the router before anything was written: the route carried `web`,
     * `Authenticate`, `XSS`, `SetLocate` and `EmailIsVerified` and NOTHING ELSE -- no permission,
     * no operator check, no ownership check. `PlanController::planDisable` then does
     * `Plan::where('id', $request->id)->update(['is_disable' => $request->is_disable])` from the
     * request body. Registration is open, so that is every authenticated visitor.
     *
     * IT IS THE SAME DEFECT AS `order.approve` AND `plan.order.refund` ABOVE, with the same
     * evidence for the same remedy: the affordance is rendered ONLY inside
     * `@if (\Auth::user()->type == 'super admin' && $plan->price > 0)` in
     * `resources/views/plans/index.blade.php:77`, so it is already a platform-operator control in
     * the UI and the route simply never enforced it. `PlatformOperatorOnly` is therefore the
     * matching gate rather than a permission: `Edit Plan` is a MERCHANT permission a tenant can
     * grant itself through the roles screen.
     *
     * THE FIXTURE AVOIDS THE HANDLER'S OWN ACCIDENTAL GUARD, which is the whole reason these
     * tests use a plan NOBODY is subscribed to. `planDisable` refuses with an HTTP 200 JSON error
     * when `$userPlan != null && $request->id != 1 && $request->is_disable == 0` -- a
     * subscriber-protection check, not an authorization check. A test driven against a subscribed
     * plan would be refused by THAT and would pass whether or not the route is gated.
     */
    public function test_a_merchant_cannot_disable_a_platform_plan(): void
    {
        [$merchant] = $this->makeTenant();

        // Nobody is subscribed to this plan, so the handler's subscriber guard does not fire and
        // the update() really would run.
        $plan = $this->makePlan();

        $this->assertSame(1, $this->storedDisableFlag($plan->id),
            'PREMISE: the plan is not in the ON state this case starts from.');

        $response = $this->actingAs($merchant)
            ->post(route('plan.disable'), ['id' => $plan->id, 'is_disable' => 0]);

        $this->assertSame(
            1,
            (int) DB::table('plans')->where('id', $plan->id)->value('is_disable'),
            'A merchant switched a PLATFORM plan off. Every merchant holding it is affected, and '
            . 'the Flutter current-plan screen divides by max_products on a plan it can no longer '
            . 'resolve.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_merchant_cannot_enable_a_plan_the_operator_disabled(): void
    {
        [$merchant] = $this->makeTenant();

        // The other direction, and it needs its own case: `is_disable = 1` skips the handler's
        // subscriber guard entirely, so this path reaches `update()` for ANY plan, subscribed or
        // not.
        $plan = $this->withdrawnPlan();

        $this->assertSame(0, $this->storedDisableFlag($plan->id),
            'PREMISE: the plan is not in the withdrawn state this case starts from.');

        $response = $this->actingAs($merchant)
            ->post(route('plan.disable'), ['id' => $plan->id, 'is_disable' => 1]);

        $this->assertSame(
            0,
            (int) DB::table('plans')->where('id', $plan->id)->value('is_disable'),
            'A merchant switched a plan the platform operator had withdrawn back ON, making it '
            . 'purchasable again.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_the_platform_operator_can_still_disable_a_plan(): void
    {
        $admin = $this->makePlatformAdmin();
        $plan = $this->makePlan();

        $this->assertSame(1, $this->storedDisableFlag($plan->id),
            'PREMISE: the plan is not in the ON state this case starts from.');

        $response = $this->actingAs($admin)
            ->post(route('plan.disable'), ['id' => $plan->id, 'is_disable' => 0]);

        $this->assertSame(
            0,
            (int) DB::table('plans')->where('id', $plan->id)->value('is_disable'),
            'The platform operator can no longer switch a plan off, which is a regression in the '
            . 'screen this control belongs to.'
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * THE CONTROL AND THE AFFORDANCE MUST AGREE, which is the argument the gate was chosen on.
     *
     * If the toggle were ever rendered for a merchant, `PlatformOperatorOnly` would make it a
     * button that always fails -- so this asserts the view's own condition rather than trusting
     * the sentence in the docblock above. Blade comments are stripped, because this file's own
     * docblock quotes the condition it is looking for.
     */
    /**
     * THE TWO CASES ABOVE PIN THE PAIR, NOT EITHER LAYER, and an independent review said so before
     * this assertion existed: remove the route middleware and the handler still answers 403;
     * remove the handler check and the middleware still aborts 403. Both database readbacks pass
     * either way, so neither case can tell which layer is carrying the refusal. A mutation run
     * found the same thing and reported both single-layer mutations as survivors.
     *
     * This is the idiom the same file already uses for the plan-coupon gate below
     * (`test_apply_coupon_is_exempt_from_the_platform_operator_gate`): ask the ROUTER what the
     * route carries, so dropping the middleware is red even while the handler keeps refusing.
     */
    public function test_the_plan_disable_route_carries_the_platform_operator_gate(): void
    {
        $middleware = app('router')->getRoutes()->getByName('plan.disable')->gatherMiddleware();

        $this->assertContains(
            \App\Http\Middleware\PlatformOperatorOnly::class,
            $middleware,
            'plan.disable has LOST the platform-operator gate. The in-handler check still refuses, '
            . 'so the two cases above stay green -- which is exactly why this assertion exists.'
        );

        $this->assertContains('auth', $middleware,
            'plan.disable must still require authentication.');
    }

    public function test_the_plan_disable_toggle_is_rendered_only_for_the_platform_operator(): void
    {
        $view = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(base_path('resources/views/plans/index.blade.php'))
        );

        /*
         * ONE SWITCH, AND THE CONDITION MUST ENCLOSE IT -- both strengthened after an independent
         * review named two mutations the first version survived. It took `strpos` (the FIRST
         * occurrence) and the nearest preceding `@if`, which establishes PROXIMITY, not enclosure:
         * a second unconditional copy later in the file was never looked at, and moving the switch
         * BELOW the `@endif` left the same `@if` as the nearest one above it. The jQuery delegate
         * binds on the `is_disable` class, so a second copy would be live.
         */
        /*
         * THE CONTROL, NOT THE WORD. `is_disable` itself appears SIX times -- the input's class
         * and its `checked` read, then four more in the jQuery handler and its payload -- which is
         * what the first version of this premise got wrong. What must be unique is the INPUT, and
         * the class attribute is both unique to it and the thing the delegate at :341 binds on, so
         * a copy-pasted second switch carries it verbatim.
         */
        $control = 'class="form-check-input input-primary is_disable"';
        $occurrences = substr_count($view, $control);

        $this->assertSame(1, $occurrences, sprintf(
            'PREMISE: the enable/disable input appears %d times in plans/index.blade.php, not '
            . 'once. The jQuery delegate binds on that class, so a second copy is a second LIVE '
            . 'control, and the enclosure check below only ever judged the first.',
            $occurrences
        ));

        $at = strpos($view, $control);

        $this->assertNotFalse($at,
            'PREMISE: resources/views/plans/index.blade.php no longer contains the is_disable '
            . 'switch this test keys on. Re-point it rather than deleting it.');

        $before = substr($view, 0, $at);
        $lastCondition = strrpos($before, '@if');

        $this->assertNotFalse($lastCondition, 'The switch is not inside any @if at all.');

        $enclosing = substr($before, $lastCondition);

        $this->assertStringNotContainsString('@endif', $enclosing,
            'An @endif sits between the nearest @if and the switch, so the switch is OUTSIDE that '
            . 'condition and is rendered unconditionally. Proximity is not enclosure.');

        $this->assertStringContainsString(
            "type == 'super admin'",
            $enclosing,
            'The plan enable/disable switch is no longer rendered only for the platform operator. '
            . 'Either widen the route gate deliberately or keep the two in step -- a control the '
            . 'route refuses is a button that always fails.'
        );
    }

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
            $response = $this->actingAs($admin)->post(route('module.enable'), ['name' => $name]);

            // THE DISCRIMINATOR IS THE STATUS, AND IT WAS MEASURED BOTH WAYS. "no row was
            // created" is NOT the right assertion: it passes with the allowlist removed too,
            // because the method then crashes before the insert can succeed. Measured, with the
            // allowlist removed, for every one of these four names:
            //     STATUS=500  session('error')=NULL  add_on_managers rows=0
            // and with it in place:
            //     STATUS=302  session('error')='Oops something went wrong!'  rows=0
            // A 500 here means the request REACHED
            // `Artisan::call('migrate --path=/packages/workdo/<name>/...')`,
            // `Artisan::call('package:seed <name>')` and
            // `file_get_contents(base_path('packages/workdo/<name>/module.json'))` and died in
            // them. A 302 with that message means it was refused before any of the three.
            $this->assertSame(
                302,
                $response->getStatusCode(),
                "A bogus module name `{$name}` was not refused up front: a non-302 means the "
                . 'request reached the Artisan --path call and the traversed module.json read.'
            );
            $this->assertSame(
                'Oops something went wrong!',
                session('error'),
                "A bogus module name `{$name}` did not come back through the name allowlist."
            );
            $this->assertSame(
                0,
                DB::table('add_on_managers')->where('module', $name)->count(),
                "A bogus module name `{$name}` created an add_on_managers row."
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
            ->post(route('store.active.status', ['id' => $victimStore->id, 'status' => 0]));

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
            ->post(route('store.active.status', ['id' => $second->id, 'status' => 0]))
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
            ->post(route('store.active.status', ['id' => 999999, 'status' => 0]));

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

    // =========================================================================================
    //  plan.order.show -- READ ANY MERCHANT'S SUBSCRIPTION ORDER, AND THEIR BANK SLIP
    //  NEW-PLAN-ORDER-SHOW-IDOR-01
    // =========================================================================================

    /**
     * BankTransferController::show():111 resolved its subject as `PlanOrder::find($id)` with NO
     * ownership test and rendered resources/views/order/show.blade.php for it, so any
     * authenticated user could walk the plan_orders primary key.
     *
     * WHAT THE TEMPLATE PRINTS, and therefore what leaked (order/show.blade.php:4/8/12/20/31):
     * the order id, the plan the merchant is on, the price they actually paid, the payment
     * status, and a download link to the bank slip they uploaded. The gate is attached as route
     * middleware (App\Http\Middleware\PlanOrderViewGate) because BankTransferController is a
     * plan-purchase gateway controller -- the same division of ownership PlatformOperatorOnly
     * documents for order.approve/order.reject on this same controller.
     *
     * ASSERTION ORDER: the DISCLOSURE is asserted before the status code. A bare
     * assertSame(403) would also pass against a 403 produced by something unrelated, and this
     * project has already shipped three status-only assertions that passed with the defect
     * reintroduced.
     */
    public function test_a_merchant_cannot_read_another_merchants_plan_order(): void
    {
        $this->seedBankTransferSetting();

        [$attacker] = $this->makeTenant();
        [$victim, $victimStore] = $this->makeTenant();
        $plan = $this->makePlan(['name' => 'VictimGold-' . uniqid()]);
        $orderId = $this->makePlanOrder($victim, $plan, $victimStore, [
            'price' => 4242,
            'receipt' => 'uploads/receipt/victim-bank-slip.png',
        ]);

        $response = $this->actingAs($attacker)->get(route('plan.order.show', [$orderId]));

        $body = (string) $response->getContent();

        // THE DISCLOSURE ITSELF. Each of these is a distinct field the template renders, and
        // each was readable by any session before the gate existed.
        $this->assertStringNotContainsString($plan->name, $body,
            'DISCLOSURE: another merchant\'s plan name was rendered to a foreign merchant.');
        $this->assertStringNotContainsString('4242', $body,
            'DISCLOSURE: another merchant\'s subscription price was rendered to a foreign merchant.');
        $this->assertStringNotContainsString('victim-bank-slip.png', $body,
            'DISCLOSURE: the download link to another merchant\'s UPLOADED BANK SLIP was rendered '
            . 'to a foreign merchant.');

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * THE POSITIVE PROOF, and it is not vacuous: the owner must get 200 AND see their own data.
     * "not 403" alone would be satisfied by the 500 this view throws when $order is null.
     */
    public function test_the_paying_merchant_can_still_read_their_own_plan_order(): void
    {
        $this->seedBankTransferSetting();

        [$merchant, $store] = $this->makeTenant();
        $plan = $this->makePlan(['name' => 'MyGold-' . uniqid()]);
        $orderId = $this->makePlanOrder($merchant, $plan, $store, [
            'price' => 777,
            'receipt' => 'uploads/receipt/my-bank-slip.png',
        ]);

        $response = $this->actingAs($merchant)->get(route('plan.order.show', [$orderId]));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'The paying merchant can no longer read their own plan order, which is a regression: '
            . 'PlanOrderDataTable::query():87-89 lists exactly these rows to them.'
        );

        $body = (string) $response->getContent();
        $this->assertStringContainsString($plan->name, $body);
        $this->assertStringContainsString('777', $body);
        $this->assertStringContainsString('my-bank-slip.png', $body);
    }

    /**
     * The platform operator reviews the uploaded receipt -- that is what this modal is FOR
     * (the Approval/Reject buttons at order/show.blade.php:39-40 are theirs). So the operator
     * must still read an order they did not pay for.
     */
    public function test_the_platform_operator_can_still_read_any_merchants_plan_order(): void
    {
        $this->seedBankTransferSetting();

        $admin = $this->makePlatformAdmin();
        [$merchant, $store] = $this->makeTenant();
        $plan = $this->makePlan(['name' => 'OpGold-' . uniqid()]);
        $orderId = $this->makePlanOrder($merchant, $plan, $store, ['price' => 999]);

        $response = $this->actingAs($admin)->get(route('plan.order.show', [$orderId]));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'The platform operator can no longer review a bank-transfer receipt, which breaks the '
            . 'only workflow this route exists for.'
        );
        $this->assertStringContainsString($plan->name, (string) $response->getContent());
    }

    /**
     * STAFF ARE NOT THE PAYER. plan_orders.user_id is written from `Auth::user()` at
     * BankTransferController:101, and PlanOrderDataTable never listed a merchant's orders to
     * their staff either. Pinned so a later "widen it to creatorId()" is a deliberate decision
     * rather than an accident.
     */
    public function test_staff_are_not_handed_their_employers_plan_order(): void
    {
        $this->seedBankTransferSetting();

        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $plan = $this->makePlan();
        $orderId = $this->makePlanOrder($merchant, $plan, $store, [
            'receipt' => 'uploads/receipt/employer-slip.png',
        ]);

        $response = $this->actingAs($staff)->get(route('plan.order.show', [$orderId]));

        $this->assertStringNotContainsString(
            'employer-slip.png',
            (string) $response->getContent(),
            'A staff user was handed their employer\'s uploaded bank slip.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * An unknown id must be the SAME answer as an unowned one, so this route is not an oracle
     * for which plan_orders ids exist -- and must not be the 500 it used to be, because
     * order/show.blade.php dereferenced the null that `PlanOrder::find()` returned.
     */
    public function test_an_unknown_plan_order_id_is_a_denial_and_not_a_server_error(): void
    {
        $this->seedBankTransferSetting();

        [$merchant] = $this->makeTenant();

        $this->assertSame(
            403,
            $this->actingAs($merchant)->get(route('plan.order.show', [999999]))->getStatusCode()
        );
    }

    /**
     * 403 AND NEVER 401. repo B's lib/network/network.dart maps any 401 to a forced logout that
     * clears stored preferences, so answering a tenancy denial with 401 would sign the merchant
     * out of the shipped admin app instead of telling them no.
     */
    public function test_the_plan_order_denial_is_403_and_never_401(): void
    {
        $this->seedBankTransferSetting();

        [$attacker] = $this->makeTenant();
        [$victim, $victimStore] = $this->makeTenant();
        $orderId = $this->makePlanOrder($victim, $this->makePlan(), $victimStore);

        $status = $this->actingAs($attacker)
            ->get(route('plan.order.show', [$orderId]))
            ->getStatusCode();

        $this->assertSame(403, $status,
            'plan.order.show answered ' . $status . ' to a foreign merchant. 401 is the dangerous '
            . 'answer (repo B force-logs-out on it); a 2xx means the gate is gone.');
    }

    /**
     * order/show.blade.php:23 renders `$admin_payment_setting['bank_transfer']` with no
     * null-coalesce, so without this row the view raises "Undefined array key" and every
     * POSITIVE test above would 500 for a reason that has nothing to do with authorization.
     * getSuperAdminAllSetting() (app/Helper/helper.php:338-345) reads the settings of the FIRST
     * `type = 'super admin'` user, keyed by that user's current_store.
     */
    private function seedBankTransferSetting(): void
    {
        $admin = \App\Models\User::where('type', 'super admin')->first()
            ?? $this->makePlatformAdmin();

        \Illuminate\Support\Facades\Cache::flush();

        DB::table('settings')->insert([
            'name' => 'bank_transfer',
            'value' => 'ACME Bank / IBAN 0000',
            'created_by' => $admin->id,
            'store_id' => $admin->current_store,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================================
    //  PlanCouponController -- PLATFORM PLAN COUPONS GATED ON MERCHANT STORE-COUPON PERMISSIONS
    //  NEW-PLAN-COUPON-MERCHANT-PERM-01
    // =========================================================================================

    private function makePlanCoupon(array $attributes = []): int
    {
        return (int) DB::table('plan_coupons')->insertGetId(array_merge([
            'name' => 'PlatformPromo-' . uniqid(),
            'code' => strtoupper('PC' . uniqid()),
            'discount' => 25,
            'limit' => '10',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /**
     * THE DEFECT, AND THE REASON THIS TEST IS NOT VACUOUS: the merchant is GRANTED the real
     * 'Delete Coupon' permission first. That is the permission the unfixed destroy() checked, so
     * before the gate this request genuinely deleted the platform's subscription coupon. Without
     * the grant the row would survive for the wrong reason -- a merchant who simply lacks the
     * permission -- and the test would pass against the defect.
     *
     * 'Delete Coupon' is a MERCHANT permission for STORE coupons: Utility.php:3018-3021 grants
     * it to the merchant `admin` role (and :3126-3129 to `super admin`). That dual grant is the
     * whole defect, and it is pinned as a source property below.
     */
    public function test_a_merchant_with_store_coupon_rights_cannot_delete_a_platform_plan_coupon(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Delete Coupon');
        $this->assertTrue($merchant->fresh()->isAbleTo('Delete Coupon'),
            'Fixture: the merchant must really hold the permission the unfixed destroy() checked, '
            . 'or the row below survives for the wrong reason.');

        $couponId = $this->makePlanCoupon();

        $response = $this->actingAs($merchant)
            ->delete(route('plan-coupon.destroy', [$couponId]));

        $this->assertSame(
            1,
            DB::table('plan_coupons')->where('id', $couponId)->count(),
            'A merchant holding ordinary STORE-coupon rights deleted a PLATFORM subscription '
            . 'coupon. plan_coupons is platform revenue configuration and has no store_id at all.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * THE SAME MISTAKE ON THE OTHER SIX METHODS. destroy() was the reported one; index(),
     * create(), store(), edit() and update() repeat it, and show() had NO check whatsoever.
     * Every one is gated now, so every one is asserted.
     */
    public function test_a_merchant_with_store_coupon_rights_is_refused_every_plan_coupon_route(): void
    {
        [$merchant] = $this->makeTenant();
        foreach (['Manage Coupon', 'Create Coupon', 'Edit Coupon', 'Delete Coupon'] as $permission) {
            $this->grantPermission($merchant, $permission);
        }

        $couponId = $this->makePlanCoupon();

        $cases = [
            ['get',  route('plan-coupon.index')],
            ['get',  route('plan-coupon.create')],
            ['get',  route('plan-coupon.show', [$couponId])],
            ['get',  route('plan-coupon.edit', [$couponId])],
        ];

        foreach ($cases as [$verb, $uri]) {
            $this->assertSame(
                403,
                $this->actingAs($merchant)->{$verb}($uri)->getStatusCode(),
                "{$verb} {$uri} was not refused to a merchant holding store-coupon rights."
            );
        }

        // store() -- a WRITE. The row count is the assertion; the status code is the corroboration.
        $before = DB::table('plan_coupons')->count();
        $storeResponse = $this->actingAs($merchant)->post(route('plan-coupon.store'), [
            'name' => 'MerchantMade-' . uniqid(),
            'code' => 'MM' . uniqid(),
            'discount' => 30,
            'limit' => 5,
        ]);
        $this->assertSame($before, DB::table('plan_coupons')->count(),
            'A merchant created a PLATFORM plan coupon.');
        $this->assertSame(403, $storeResponse->getStatusCode());

        // update() -- a WRITE against an existing platform coupon.
        $originalDiscount = DB::table('plan_coupons')->where('id', $couponId)->value('discount');
        $updateResponse = $this->actingAs($merchant)->put(route('plan-coupon.update', [$couponId]), [
            'name' => 'Hijacked-' . uniqid(),
            'code' => 'HJ' . uniqid(),
            'discount' => 99,
            'limit' => 99999,
        ]);
        $this->assertSame(
            (float) $originalDiscount,
            (float) DB::table('plan_coupons')->where('id', $couponId)->value('discount'),
            'A merchant rewrote a PLATFORM plan coupon\'s discount -- i.e. set their own price '
            . 'for a subscription.'
        );
        $this->assertSame(403, $updateResponse->getStatusCode());
    }

    /**
     * THE POSITIVE PROOF. The platform operator still administers plan coupons. The existing
     * isAbleTo() checks were deliberately LEFT IN PLACE behind the new operator gate, so the
     * operator needs the permission too -- which Utility.php:3126-3130 grants to the `super
     * admin` role in production and grantPermission() supplies here.
     */
    public function test_the_platform_operator_can_still_delete_a_plan_coupon(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->grantPermission($admin, 'Delete Coupon');

        $couponId = $this->makePlanCoupon();

        $this->actingAs($admin)->delete(route('plan-coupon.destroy', [$couponId]));

        $this->assertSame(
            0,
            DB::table('plan_coupons')->where('id', $couponId)->count(),
            'The platform operator can no longer delete a plan coupon, which is a regression.'
        );
    }

    public function test_the_platform_operator_can_still_reach_the_plan_coupon_list(): void
    {
        $admin = $this->makePlatformAdmin();

        // THE OPERATOR NEEDS A STORE, and this is fixture necessity rather than padding: this
        // route renders the full admin chrome, and resources/views/partision/header.blade.php
        // dereferences the current store's `slug`. Without one the request is a 500 --
        // "Attempt to read property \"slug\" on null" -- for a reason that has nothing to do
        // with authorization, which would make a 200 assertion impossible and a "not 403"
        // assertion vacuous.
        $adminStore = $this->makeStore($admin);
        $admin->current_store = $adminStore->id;
        $admin->save();
        $admin = $admin->fresh();

        $this->grantPermission($admin, 'Manage Coupon');

        $this->assertSame(
            200,
            $this->actingAs($admin)->get(route('plan-coupon.index'))->getStatusCode(),
            'The platform operator can no longer list plan coupons, which is a regression.'
        );
    }

    /**
     * THE EXEMPTION, AND WHY IT IS ONE. applyCoupon is the AJAX price preview on the
     * plan-purchase page and its caller is the MERCHANT who is buying a plan. Gating the whole
     * controller without excluding it would 403 every merchant out of applying a plan coupon at
     * checkout -- the "tightened a predicate without checking who it excludes" failure this
     * phase has already caused twice. So the exemption is asserted, not assumed.
     *
     * ASSERTED ON THE MIDDLEWARE STACK, because the handler CANNOT BE RUN HERE. applyCoupon
     * resolves the code with `whereRaw('BINARY `code` = ?')` (PlanCouponController::applyCoupon),
     * and `BINARY` is MySQL-only: on the sqlite :memory: database these suites are pinned to
     * (tests/bootstrap.php aborts on anything else) it is a hard
     *     SQLSTATE[HY000]: General error: 1 near "`code`": syntax error
     * -- measured, not assumed. So a 200 assertion is impossible in this environment and a
     * "not 403" assertion would be vacuously true of that 500. The route's gathered middleware
     * is the exact property the exemption consists of, and it is environment-independent.
     *
     * The companion assertion -- that the gated sibling DOES carry the middleware -- is what
     * stops this test passing because the gate was removed from the whole controller.
     */
    public function test_apply_coupon_is_exempt_from_the_platform_operator_gate(): void
    {
        $routes = app('router')->getRoutes();

        $applyMiddleware = $routes->getByName('apply.coupon')->gatherMiddleware();

        $this->assertNotContains(
            \App\Http\Middleware\PlatformOperatorOnly::class,
            $applyMiddleware,
            'apply.coupon is now behind the platform-operator gate, which 403s every merchant out '
            . 'of applying a plan coupon at checkout. It must stay outside the plan-coupon '
            . 'resource declaration.'
        );

        // Still authenticated -- exempt from the OPERATOR gate, not from the session.
        $this->assertContains('auth', $applyMiddleware,
            'apply.coupon must still require authentication.');

        $this->assertContains(
            \App\Http\Middleware\PlatformOperatorOnly::class,
            $routes->getByName('plan-coupon.destroy')->gatherMiddleware(),
            'plan-coupon.destroy has LOST the platform-operator gate -- the defect is back, and '
            . 'the exemption assertion above would then pass for the wrong reason.'
        );
    }

    /**
     * THE sqlite BLOCKER ABOVE, PINNED BY NAME so it is not mistaken for an authorization
     * problem and so it reddens if it is ever repaired. `BINARY` is MySQL-only and
     * PlanCouponController::applyCoupon uses it (as does BankTransferController:57 and every plan
     * gateway), so this endpoint cannot complete on sqlite at all. The status is a 500 from the
     * QUERY, which proves the request got PAST the operator gate and into the handler -- the
     * closest behavioural evidence of the exemption available here.
     */
    public function test_apply_coupon_reaches_the_handler_and_is_blocked_only_by_mysql_only_sql(): void
    {
        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan();
        $couponId = $this->makePlanCoupon();
        $code = DB::table('plan_coupons')->where('id', $couponId)->value('code');

        $response = $this->actingAs($merchant)->get(route('apply.coupon', [
            'plan_id' => \Illuminate\Support\Facades\Crypt::encrypt($plan->id),
            'coupon' => $code,
        ]));

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'A merchant was refused the checkout coupon preview by the platform-operator gate.'
        );

        $this->assertSame(500, $response->getStatusCode(),
            'applyCoupon no longer dies on the MySQL-only BINARY comparison. If that was fixed, '
            . 'replace this test with a real assertion that a merchant gets is_success=true.');

        $this->assertStringContainsString(
            'BINARY',
            (string) $response->getContent(),
            'applyCoupon now fails for a DIFFERENT reason than the MySQL-only BINARY comparison. '
            . 'Find out what it is -- a 500 here wearing this test\'s expected status code would '
            . 'hide a real regression.'
        );
    }

    /**
     * THE SOURCE PROPERTY THAT MAKES THE DEFECT A DEFECT, pinned so nobody "simplifies" the gate
     * back to isAbleTo('Delete Coupon'). The five coupon permissions are granted to the MERCHANT
     * role as well as to super admin, so they cannot distinguish a platform operator.
     *
     * It also pins the opposite trap: these names ARE created by Utility::addNewData(), so the
     * gate did not need a new permission name -- and inventing one that addNewData() does not
     * seed is exactly the 'Edit Order' defect this project already shipped once.
     */
    public function test_the_coupon_permissions_are_merchant_permissions_and_cannot_gate_the_platform(): void
    {
        $utility = (string) file_get_contents(base_path('app/Models/Utility.php'));

        // The merchant (`admin`) role's grant list and the super-admin grant list both contain
        // them -- that dual grant is the defect.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($utility, '"Delete Coupon"'),
            "'Delete Coupon' is expected in BOTH the merchant and the super-admin grant lists in "
            . 'Utility::addNewData(). If it is now in only one, re-check whether the '
            . 'platform-operator gate on PlanCouponController is still the right control.'
        );

        // And it is NOT a platform-scoped permission, unlike 'Manage Module' etc.
        $this->assertNotContains(
            'Delete Coupon',
            config('superadminaccess'),
            'If the coupon permissions became platform-scoped, revisit the gate.'
        );

        // The gate in place is the EXISTING platform-operator middleware, not a new permission
        // name. Asserted on routes/web.php because that is where it is attached: the base
        // App\Http\Controllers\Controller extends the legacy Illuminate\Routing\Controller,
        // whose non-static middleware() makes Laravel 11's HasMiddleware a fatal error on these
        // controllers -- `php -l` accepts it and every request then dies at class load.
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertMatchesRegularExpression(
            "/Route::resource\('plan-coupon'.*?PlatformOperatorOnly/s",
            $routes,
            'The plan-coupon resource must keep the platform-operator gate.'
        );

        // And NO new permission name was invented for this. Utility::addNewData() is the only
        // thing that creates Permission rows, so a name it does not seed is a permanent 403 for
        // everyone including the operator -- the 'Edit Order' defect this project already shipped.
        $controller = (string) file_get_contents(
            base_path('app/Http/Controllers/PlanCouponController.php')
        );
        $utility = (string) file_get_contents(base_path('app/Models/Utility.php'));
        preg_match_all("/isAbleTo\('([^']+)'\)/", $controller, $gated);
        foreach (array_unique($gated[1]) as $permission) {
            $this->assertStringContainsString(
                '"' . $permission . '"',
                $utility,
                "PlanCouponController gates on '{$permission}', which Utility::addNewData() does "
                . 'not create. An unseeded permission name can never be granted to any role, so '
                . 'that route is a permanent 403 for every user including the platform operator.'
            );
        }
    }

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

        /*
         * THE SLICE IS THE WHOLE METHOD, NOT A FIXED BYTE WINDOW. This read `substr($source,
         * $start, 1600)`, which made the assertion below a claim about the first 1600 bytes of
         * editprofile rather than about editprofile. P2.5 added a withErrors/withInput failure
         * path and its reasoning to this method, which pushed `$user_id` to offset 2211 and
         * turned this test red WITHOUT any caller-supplied id appearing anywhere -- a false
         * reading, and the second assertion had silently been covering less and less of the
         * method for as long as the window stood.
         *
         * Taking the slice to the next method declaration STRENGTHENS both assertions: the
         * negative one now sees every line of editprofile instead of its first two thirds. The
         * two premises below are what stop the slice from being wrong in the other direction --
         * one proves it reaches the END of the method, the other proves it stops before the
         * next one, so neither a truncated nor an over-long slice can pass quietly.
         */
        $end = strpos($source, 'public function password_update', $start);

        $this->assertNotFalse($end,
            'password_update has moved or been renamed, so this slice no longer has an end.');

        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            "return redirect()->back()->with('success', __('Personal info successfully updated.'));",
            $body,
            'premise: the slice reaches the end of editprofile. Without this the negative '
            . 'assertion below could pass by covering only part of the method.');

        $this->assertStringNotContainsString('function password_update', $body,
            'premise: the slice stops before the next method, so an assertion about '
            . 'editprofile cannot be satisfied or broken by password_update.');

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
