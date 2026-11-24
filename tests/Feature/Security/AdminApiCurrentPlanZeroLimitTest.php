<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `GET api/admin/indexAllPlan` ANSWERED HTTP 500 FOR A PLAN WITH A ZERO LIMIT.
 * =================================================================================================
 *
 * The shipped admin app's current-plan screen computes three usage percentages:
 *
 *     'percentage' => $maxProducts == -1 ? 0 : round(($usedProducts / $maxProducts) * 100, 2),
 *
 * `-1` means unlimited and is handled. **`0` is not**, and `0` is the SHIPPED COLUMN DEFAULT:
 * `plans.max_products`, `max_stores` and `max_users` are each `->default(0)`
 * (2023_12_08_123752_create_plans_table.php:22). So a plan created without a products limit makes
 * this endpoint divide by zero for every merchant holding it.
 *
 * AND IT IS A 500 RATHER THAN A HANDLED ERROR, which had to be measured rather than assumed. The
 * handler's body is wrapped in `try { ... } catch (\Exception $e)`, and `DivisionByZeroError`
 * extends `ArithmeticError` extends `Error` -- NOT `Exception`. The catch cannot see it.
 *
 * HOW IT WAS FOUND, AND WHAT ABOUT THE REPORT WAS WRONG. An adversarial verifier reading a Phase 06
 * subscriptions inventory reported this, and claimed it composes with the ungated
 * `POST /plan-disable` (fixed in the same change as NEW-PLAN-DISABLE-UNGATED-01): switch
 * `is_disable` and `$currentPlan` becomes null, `$maxProducts` falls to the `: 0` branch at
 * `Api/DashboardController.php:8074`, and every merchant on that plan gets a 500.
 *
 * **That half is REFUTED, by reading the ten lines between the two facts.** The divisions are inside
 * `if ($currentPlan) {` at :8104, so they are unreachable when `$currentPlan` is null -- `$planData`
 * simply stays null. The `: 0` fallbacks at :8074-8076 feed only the `$remaining*` subtractions,
 * which cannot divide. The trigger is a plan row whose OWN limit is 0, which needs no second defect
 * and no attacker: it is what the operator gets by leaving the field blank. The verdict is kept
 * because the reachable half is real, and the refuted half is recorded so nobody re-derives it.
 *
 * ZERO PERCENT IS THE ANSWER, AND IT IS THE FILE'S OWN CONVENTION rather than a new policy: the
 * same expression already answers `0` for an unlimited plan, where a percentage is equally
 * meaningless. A plan with a zero allowance sells nothing, so there is no denominator to be a
 * percentage of; `used` and `max` are reported beside it and say the real state.
 */
class AdminApiCurrentPlanZeroLimitTest extends SecurityTestCase
{
    use RefreshDatabase;

    public function test_the_current_plan_screen_does_not_divide_by_a_zero_limit(): void
    {
        // The three limits are left at their column defaults deliberately: that IS the condition
        // under test, and explicit values would hide it.
        $plan = Plan::create([
            'name' => 'Starter-' . $this->uniqueFixtureRef(),
            'price' => 10,
            'duration' => 'Month',
            // NOT NULL with no default, verified from the schema.
            'themes' => '',
            'storage_limit' => '0',
            'description' => 'a plan whose limits were never filled in',
        ]);

        $this->assertSame(0, (int) $plan->fresh()->max_products,
            'PREMISE: plans.max_products no longer defaults to 0, so this is not the case that '
            . 'crashed. Re-point the test rather than deleting it.');
        $this->assertSame(1, (int) $plan->fresh()->is_disable,
            'PREMISE: the handler builds the limits block only for a plan with is_disable = 1, so '
            . 'a plan that does not default to 1 never reaches the division at all.');

        [$merchant, $store] = $this->makeTenant();
        $merchant->plan_id = $plan->id;
        $merchant->save();

        $token = $merchant->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/indexAllPlan?store_id=' . $store->id);

        $this->assertSame(200, $response->getStatusCode(),
            'GET api/admin/indexAllPlan did not answer 200 for a merchant whose plan has a zero '
            . 'products limit. A DivisionByZeroError is an Error, not an Exception, so the '
            . "handler's own catch cannot turn it into a JSON failure: it is a 500. Body: "
            . substr((string) $response->getContent(), 0, 300));

        $limits = $response->json('data.current_plan.limits');

        $this->assertIsArray($limits,
            'PREMISE: the response no longer carries a limits block where this test looks for it. '
            . 'Body: ' . substr((string) $response->getContent(), 0, 400));

        foreach (['products', 'stores', 'users'] as $axis) {
            $this->assertSame(0, (int) ($limits[$axis]['max'] ?? -1),
                "PREMISE: the {$axis} limit under test is not 0.");
            $this->assertSame(0, (int) ($limits[$axis]['percentage'] ?? -1),
                "The {$axis} percentage for a zero allowance is not 0. There is no denominator to "
                . 'be a percentage of, and the endpoint already answers 0 for an unlimited plan.');
        }
    }
}
