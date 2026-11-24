<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * TWO MORE `User::assignPlan()` INVARIANTS, BOTH FOUND BY THE INDEPENDENT REVIEW OF A ONE-LINE FIX.
 *
 * The review that confirmed the entitlement-target fix in
 * `PlanAssignmentTargetsTheRightUserTest` returned four further findings. These are the two that
 * are defects rather than notes, and both are PRE-EXISTING -- neither was introduced by that fix.
 *
 * 1. THE STAFF CASCADE READS `created_by` AS A TENANT, WHICH IT IS NOT.
 *
 *    `User.php:376` selects the subject's staff as
 *    `User::where('created_by', '=', $this->id)->where('type', '!=', 'super admin')`, then
 *    deactivates everyone beyond `$plan->max_users`. That is correct for a merchant.
 *
 *    It is not correct for the platform operator, because EVERY MERCHANT IS CREATED WITH
 *    `created_by = 1` -- `Auth\RegisteredUserController:133` and `:286`, `StoreController:280` --
 *    and user 1 is the operator. So with the operator as subject, that query selects every
 *    merchant on the platform (the `type != 'super admin'` clause excludes only other operators),
 *    in `->get()` order with no `orderBy`, and sets `is_active = 0` on all of them past the plan's
 *    `max_users`. One request, platform-wide merchant deactivation.
 *
 *    This is the same trap CLAUDE.md records costing an account takeover once already:
 *    "`created_by = 1` is NOT a tenant, and reading it as one was an account-takeover". The fix
 *    there was to require a type-`admin` tenant root (`UserPolicy::sameTenant()`); the fix here is
 *    the same shape.
 *
 *    REACHABLE: `StoreController::activePlan` (`:1171-1181`, gated only by an in-handler
 *    `type == 'super admin'` check) and `PlanRequestController::acceptRequest` (`:67-80`) both
 *    take the subject from input, so an operator can name themselves. Operator-only, so it is
 *    self-inflicted rather than an escalation -- which is why it is a safety fix and not a
 *    security report.
 *
 *    WHY THE GUARD IS ON THE CASCADE AND NOT ON THE METHOD'S AUDIENCE. Refusing `assignPlan()`
 *    outright for a `super admin` subject was the first candidate and is rejected: an operator is
 *    authenticated and verified, so the self-service purchase routes are reachable by them, and
 *    refusing would change whether an operator can buy a plan at all -- a product question. Only
 *    the cascade is wrong, so only the cascade is guarded. The operator's own plan row still
 *    updates exactly as before.
 *
 *    NOTHING IS LOST BY SKIPPING IT. An operator has no plan-limited staff: `helper.php:1932-1933`
 *    gives `type == 'super admin'` every active module regardless of any row, so operators are not
 *    plan-limited in the first place.
 *
 * 2. `$oldplan` IS NULLABLE AND IS DEREFERENCED.
 *
 *    `$oldplan = Plan::where('id', $this->plan_id)->first()` (`:288`) can be null, and
 *    `$oldplan->custom_plan` (`:324`) is reached whenever the first operand of the `&&` is truthy.
 *    Laravel's error handler promotes the warning to an `ErrorException`, so this is a 500 in the
 *    middle of the method -- AFTER `plan_id` and `plan_expire_date` have been mutated in memory and
 *    BEFORE `$this->save()` at `:375`. On a gateway path the `PlanOrder` row is already written, so
 *    the money is captured and the entitlement is never stored.
 *
 * WHAT THE REVIEW SAID NOT TO DO, recorded here because the tempting "fix" is a widening.
 * `:324`'s first operand reads the ACTING user's `active_module`, not the subject's. Changing it to
 * `$this` looks like the same correction as the entitlement-target fix and is NOT: every merchant
 * who has held a plan has a non-empty `active_module`, so `$this` would make that operand true on
 * exactly the operator-driven downgrade path, and the merge branch would then PRESERVE modules the
 * new plan does not grant whenever the old plan was a custom plan. That is the over-entitlement
 * just closed, re-opened through the other operand, and whether a custom plan should keep its
 * extras through an operator-initiated change is the owner's call. Left alone deliberately.
 */
class PlanAssignmentCascadeSafetyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function plan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Small', 'price' => 5, 'duration' => 'Month',
            'max_stores' => 1, 'max_products' => 10, 'max_users' => 1,
            'modules' => 'Marketing',
            // The three NOT NULL columns with no default, read off PRAGMA table_info(plans).
            'themes' => 'stylique', 'storage_limit' => '1024',
        ], $attributes));
    }

    #[Test]
    public function test_assigning_a_plan_to_the_operator_does_not_deactivate_every_merchant(): void
    {
        $operator = $this->makePlatformAdmin();

        /*
         * PRODUCTION'S SHAPE, not a contrived one: merchants carry `created_by = <operator id>`
         * because all three first-party creation sites hardcode 1 and user 1 is the operator.
         * Three of them against a plan whose max_users is 1, so two are past the limit.
         */
        $merchants = collect(range(1, 3))->map(
            fn (int $n) => $this->makeMerchant(['created_by' => $operator->id, 'is_active' => 1])
        );

        $plan = $this->plan(['max_users' => 1]);

        $this->actingAs($operator);
        $operator->assignPlan($plan->id);

        foreach ($merchants as $merchant) {
            $this->assertSame(
                1,
                (int) User::withoutGlobalScopes()->findOrFail($merchant->id)->is_active,
                'Assigning a plan to the OPERATOR deactivated a merchant. The staff cascade read '
                . 'created_by = <operator id> as "my staff", but every merchant carries that value, '
                . 'so one request deactivates the platform.'
            );
        }
    }

    /**
     * THE CASCADE MUST STILL WORK FOR A MERCHANT, or the fix above has simply disabled a feature.
     */
    #[Test]
    public function test_a_merchants_own_staff_are_still_limited_by_the_plan(): void
    {
        /*
         * THE OPERATOR IS CREATED FIRST, AND THAT IS NOT DECORATION.
         *
         * `makeMerchant()` defaults to `created_by => 1`, and in a fresh RefreshDatabase the first
         * user created takes id 1 -- so a lone merchant has `created_by === $this->id` and the
         * cascade at `User.php:376` selects THE MERCHANT ITSELF alongside its staff. Measured: it
         * returned `1(admin), 2(staff), 3(staff), 4(staff)`, the merchant consumed the single seat,
         * and all three staff were deactivated. That is a fixture artefact, not the product: in
         * production user 1 is the operator, so a merchant's `created_by` is never its own id.
         *
         * Creating the operator first restores production's shape. Recorded rather than papered
         * over, because the underlying edge is real -- a type-`admin` user whose id is 1 would
         * consume a seat in its own cascade -- it just is not what this test is about.
         */
        $this->makePlatformAdmin();
        $merchant = $this->makeMerchant();

        $staff = collect(range(1, 3))->map(
            fn (int $n) => User::factory()->create([
                'type' => 'staff',
                'created_by' => $merchant->id,
                'is_active' => 1,
                'email_verified_at' => now(),
            ])
        );

        $plan = $this->plan(['max_users' => 1]);

        $this->actingAs($merchant);
        $merchant->assignPlan($plan->id);

        $states = $staff->map(
            fn (User $u) => (int) User::withoutGlobalScopes()->findOrFail($u->id)->is_active
        )->all();

        $this->assertSame([1, 0, 0], $states,
            'A merchant on a one-seat plan must keep one staff member active and deactivate the '
            . 'rest. If this is all 1s the cascade has been disabled rather than scoped.');
    }

    /**
     * THE STORE CASCADE IS THE STAFF CASCADE'S TWIN, AND IT NEEDED ITS OWN TEST.
     *
     * Both cascades were guarded together, but a mutation reverting ONLY the store guard survived
     * the staff test -- so the store half was protected by symmetry of reasoning rather than by
     * evidence, which is precisely how a guard rots. Measured and then closed rather than left as a
     * stated limit.
     *
     * The blast radius is genuinely smaller than the staff half: stores are created with
     * `created_by => $user->id` (the merchant, `StoreController:142/:171/:221/:291/:328`), so an
     * operator subject selects only stores the operator personally created -- not every store on
     * the platform. It is the same wrong question all the same.
     */
    #[Test]
    public function test_assigning_a_plan_to_the_operator_does_not_deactivate_its_own_stores(): void
    {
        $operator = $this->makePlatformAdmin();

        $stores = collect(range(1, 3))->map(fn (int $n) => Store::create([
            // The five NOT NULL columns with no default, read off PRAGMA table_info(stores).
            'name' => "Operator store {$n}",
            'email' => "operator-store-{$n}@example.test",
            'theme_id' => 'stylique',
            'slug' => "operator-store-{$n}",
            'created_by' => $operator->id,
            'is_active' => 1,
        ]));

        $plan = $this->plan(['max_stores' => 1]);

        $this->actingAs($operator);
        $operator->assignPlan($plan->id);

        foreach ($stores as $store) {
            $this->assertSame(
                1,
                (int) Store::withoutGlobalScopes()->findOrFail($store->id)->is_active,
                'Assigning a plan to the operator deactivated a store. An operator is not '
                . 'plan-limited, so the store cascade must be skipped for an operator subject.'
            );
        }
    }

    /**
     * A SUBJECT WHOSE `plan_id` RESOLVES TO NO PLAN MUST NOT THROW MID-METHOD.
     *
     * Both operands are needed to reach it: the ACTOR must have a non-empty `active_module`, and
     * the SUBJECT's `plan_id` must match no `plans` row. Before the fix this raised
     * "Attempt to read property \"custom_plan\" on null" after `plan_id` and the expiry had been
     * mutated and before `save()`, so the entitlement write was lost.
     */
    #[Test]
    public function test_a_dangling_old_plan_id_does_not_throw_before_the_entitlement_is_saved(): void
    {
        $actor = $this->makePlatformAdmin(['active_module' => 'Marketing']);

        // 999999 resolves to no plan: Plan::where('id', ...)->first() returns null.
        $subject = $this->makeMerchant(['plan_id' => 999999]);

        $plan = $this->plan(['max_users' => -1]);

        $this->actingAs($actor);
        $subject->assignPlan($plan->id);

        $this->assertSame(
            $plan->id,
            (int) User::withoutGlobalScopes()->findOrFail($subject->id)->plan_id,
            'The new plan was never saved, so assignPlan() threw after mutating the model. On a '
            . 'gateway path that means the payment is captured and the entitlement is not stored.'
        );
    }
}
