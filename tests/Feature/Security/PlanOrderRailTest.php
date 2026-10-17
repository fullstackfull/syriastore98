<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * The plan-order rail: approve, reject, refund, and the free-trial grant.
 *
 * Route-level authorization (PlatformOperatorOnly) was already correct and is re-asserted
 * here from the live route table rather than from the file, because a group brace moving has
 * broken this project's routing before. What these tests actually cover is everything BELOW
 * that line, which was not:
 *
 *   - approve was not idempotent, and assignPlan() resets plan_expire_date to now()+period,
 *     so a double-submit extended a paying subscriber's term for free;
 *   - reject accepted an already-approved order, leaving a rejected order and a live plan;
 *   - refund read $request->id instead of the {id} route segment, so it downgraded the user
 *     while marking NO order refunded -- and never checked that the order belonged to the
 *     user it was downgrading;
 *   - planTrial enforced the one-trial rule only in Blade, so the route granted unlimited
 *     free trials of PAID plans.
 *
 * Each assertion is on database state, not on a flash message, because the defects were all
 * cases where the response looked like success.
 */
class PlanOrderRailTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Plan ' . uniqid(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => '',
            'trial' => 0,
            'trial_days' => 0,
        ], $attributes));
    }

    private function makePlanOrder(User $user, Plan $plan, array $attributes = []): PlanOrder
    {
        // plan_name, price_currency and txn_id are NOT NULL in the migration, and the model's
        // $fillable omits several columns the table declares, so the row is built with
        // forceFill + save rather than create().
        $order = new PlanOrder();

        $order->forceFill(array_merge([
            'order_id' => 'PO-' . uniqid(),
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price' => $plan->price,
            'price_currency' => 'USD',
            'txn_id' => 'TXN-' . uniqid(),
            'payment_type' => 'Bank Transfer',
            'payment_status' => 'Pending',
        ], $attributes))->save();

        return $order->refresh();
    }

    // ---------------------------------------------------------------- authorization

    public function test_the_three_money_routes_require_the_platform_operator(): void
    {
        foreach (['order.approve', 'order.reject', 'plan.order.refund'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name));

            $this->assertContains(
                \App\Http\Middleware\PlatformOperatorOnly::class,
                $stack,
                "{$name} lost PlatformOperatorOnly. Any verified account could then approve "
                . 'its own plan order or downgrade another user.'
            );
            $this->assertContains(
                \Illuminate\Auth\Middleware\Authenticate::class,
                $stack,
                "{$name} must also carry `auth`: PlatformOperatorOnly passes anonymous "
                . 'requests through on purpose, because `auth` owns that case.'
            );
        }
    }

    public function test_a_merchant_cannot_approve_their_own_plan_order(): void
    {
        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan);

        $this->actingAs($merchant->fresh())->get('/order_approve/' . $order->id);

        $this->assertSame(
            'Pending',
            $order->fresh()->payment_status,
            'A merchant approved their own plan order.'
        );
        $this->assertNotSame($plan->id, $merchant->fresh()->plan_id);
    }

    // ---------------------------------------------------------------- approve

    public function test_approving_twice_does_not_extend_the_subscription_again(): void
    {
        $operator = $this->makePlatformAdmin();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan(['duration' => 'Month']);
        $order = $this->makePlanOrder($merchant, $plan);

        $this->actingAs($operator)->get('/order_approve/' . $order->id);

        $afterFirst = $merchant->fresh();
        $this->assertSame('Approved', $order->fresh()->payment_status);
        $this->assertSame($plan->id, (int) $afterFirst->plan_id);

        $firstExpiry = $afterFirst->plan_expire_date;

        // Re-submit. Before the guard this re-ran assignPlan() and pushed the expiry out by
        // another month, once per press.
        $this->actingAs($operator)->get('/order_approve/' . $order->id);

        $this->assertSame(
            $firstExpiry,
            $merchant->fresh()->plan_expire_date,
            'A second approval extended the subscription. The rail is not idempotent.'
        );
    }

    public function test_approving_an_order_whose_user_is_gone_does_not_fatal(): void
    {
        $operator = $this->makePlatformAdmin();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan);

        User::where('id', $merchant->id)->delete();

        $response = $this->actingAs($operator)->get('/order_approve/' . $order->id);

        $response->assertStatus(302);
        $this->assertSame(
            'Pending',
            $order->fresh()->payment_status,
            'The order was approved against a deleted beneficiary.'
        );
    }

    // ---------------------------------------------------------------- reject

    public function test_an_approved_order_cannot_be_rejected(): void
    {
        $operator = $this->makePlatformAdmin();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan);

        $this->actingAs($operator)->get('/order_approve/' . $order->id);
        $this->assertSame('Approved', $order->fresh()->payment_status);

        $this->actingAs($operator)->get('/order_reject/' . $order->id);

        $this->assertSame(
            'Approved',
            $order->fresh()->payment_status,
            'An approved order was rejected. That only rewrites payment_status -- the granted '
            . 'plan stays on the user, leaving a rejected order and a live subscription.'
        );
    }

    // ---------------------------------------------------------------- refund

    public function test_refund_marks_the_order_named_in_the_url_not_the_query_string(): void
    {
        $operator = $this->makePlatformAdmin();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();

        $target = $this->makePlanOrder($merchant, $plan);
        $bystander = $this->makePlanOrder($merchant, $plan);

        // The old body read $request->id. With ?id= pointing elsewhere it flagged the wrong
        // row; with no ?id= at all it flagged nothing while still downgrading the user.
        $this->actingAs($operator)
            ->get("/refund/{$target->id}/{$merchant->id}?id={$bystander->id}");

        $this->assertSame(1, (int) $target->fresh()->is_refund, 'The URL order was not refunded.');
        $this->assertNotSame(
            1,
            (int) $bystander->fresh()->is_refund,
            'The query-string order was refunded instead. $request->id is being trusted again.'
        );
    }

    public function test_refund_refuses_an_order_that_belongs_to_another_user(): void
    {
        $operator = $this->makePlatformAdmin();
        $owner = $this->makeMerchant();
        $victim = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($owner, $plan);

        $victim->plan_id = $plan->id;
        $victim->save();

        $this->actingAs($operator)->get("/refund/{$order->id}/{$victim->id}");

        $this->assertNotSame(
            1,
            (int) $order->fresh()->is_refund,
            "Another user's order was marked refunded."
        );
        $this->assertSame(
            $plan->id,
            (int) $victim->fresh()->plan_id,
            'An unrelated user was downgraded by a refund aimed at someone else\'s order.'
        );
    }

    public function test_refund_is_idempotent(): void
    {
        $operator = $this->makePlatformAdmin();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan);

        $this->actingAs($operator)->get("/refund/{$order->id}/{$merchant->id}");
        $this->assertSame(1, (int) $order->fresh()->is_refund);

        $response = $this->actingAs($operator)->get("/refund/{$order->id}/{$merchant->id}");

        $response->assertStatus(302);
        $this->assertSame(1, (int) $order->fresh()->is_refund);
    }

    // ---------------------------------------------------------------- free trial

    public function test_a_second_free_trial_is_refused_server_side(): void
    {
        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan(['price' => 100, 'trial' => 1, 'trial_days' => 14]);
        $other = $this->makePlan(['price' => 200, 'trial' => 1, 'trial_days' => 14]);

        $encrypt = fn (int $id) => \Illuminate\Support\Facades\Crypt::encrypt($id);

        $this->actingAs($merchant->fresh())->get('/plan-trial/' . $encrypt($plan->id));

        $afterFirst = $merchant->fresh();
        $this->assertSame(
            $plan->id,
            (int) $afterFirst->trial_plan,
            'The first trial did not activate, so this test is not measuring the second.'
        );

        // The Blade template hides the button once trial_plan != 0. The route did not check.
        $this->actingAs($afterFirst)->get('/plan-trial/' . $encrypt($other->id));

        $this->assertSame(
            $plan->id,
            (int) $merchant->fresh()->trial_plan,
            'A second free trial was granted. The route grants unlimited free access to paid '
            . 'plans, and to every module those plans unlock.'
        );
    }

    public function test_a_plan_with_trials_disabled_cannot_be_trialled(): void
    {
        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan(['price' => 100, 'trial' => 0, 'trial_days' => 0]);

        $this->actingAs($merchant->fresh())->get(
            '/plan-trial/' . \Illuminate\Support\Facades\Crypt::encrypt($plan->id)
        );

        $this->assertSame(
            0,
            (int) $merchant->fresh()->trial_plan,
            'A plan that offers no trial was trialled anyway.'
        );
    }
}
