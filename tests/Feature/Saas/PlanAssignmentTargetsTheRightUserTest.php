<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use App\Models\userActiveModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `User::assignPlan()` CREATES MODULE ROWS FOR ONE USER AND DELETES THEM FROM ANOTHER.
 *
 * Phase 06 `SAAS-002` is "الترقيات والتخفيضات والتجديد وفترات السماح" -- upgrades, downgrades,
 * renewal and grace periods. A downgrade's whole job is to reduce entitlement, so the method that
 * performs it has to target the user being downgraded. This one targets two different users:
 *
 *     User.php:345    userActiveModule::create(['user_id' => $this->id, ...])     <- the subject
 *     User.php:351    userActiveModule::where('user_id', $user->id)->delete()     <- Auth::user()
 *
 * where `$user = \Auth::user()` is read at the top of the method (`User.php:286`). The two
 * coincide whenever a merchant buys their own plan, which is every self-service gateway path, and
 * that is why it has never been seen.
 *
 * THEY DIVERGE ON THE OPERATOR-DRIVEN PATH, which is the one Phase 06 is about.
 * `BankTransferController::orderapprove()` loads the buyer explicitly --
 * `$user = User::find($order->user_id)` (`:154`) -- and calls `$user->assignPlan(...)` (`:167`)
 * while the OPERATOR is the authenticated principal. So approving a merchant's bank transfer onto
 * a plan with fewer modules does both halves to the wrong people:
 *
 *   - the MERCHANT keeps `user_active_modules` rows for modules the new plan does not grant, so
 *     the downgrade does not downgrade: they retain access they are no longer entitled to;
 *   - the OPERATOR loses their own rows for those module names.
 *
 * NEITHER IS A POLICY QUESTION. What a plan costs and which modules it includes are the owner's;
 * "the rows you delete belong to the user you were asked to change" is not a decision, it is the
 * method doing what its own `create()` on the line above already does.
 *
 * WHAT THIS IS NOT. It is not a data-loss defect of the kind `SAAS-003` forbids -- no store
 * content, product, order or wallet row is touched, and `user_active_modules` is availability
 * rather than data, so `CTRL-004` ("do not delete a feature's data merely because its
 * availability is switched off") is not engaged. Measured separately: nothing in the application
 * acts on `plan_expire_date` destructively. The findings document records that in full.
 */
class PlanAssignmentTargetsTheRightUserTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: Plan, 1: Plan} a two-module plan and a one-module plan */
    private function twoPlans(): array
    {
        $rich = Plan::create([
            'name' => 'Rich', 'price' => 10, 'duration' => 'Month',
            'max_stores' => 5, 'max_products' => 50, 'max_users' => 5,
            'modules' => 'Marketing,Campaigns',
            // `name`, `themes` and `storage_limit` are the three NOT NULL columns with no
            // default -- read off PRAGMA table_info(plans), not guessed. Values are irrelevant here.
            'themes' => 'stylique',
            'storage_limit' => '1024',
        ]);

        $lean = Plan::create([
            'name' => 'Lean', 'price' => 5, 'duration' => 'Month',
            'max_stores' => 1, 'max_products' => 10, 'max_users' => 5,
            'modules' => 'Marketing',
            'themes' => 'stylique',
            'storage_limit' => '1024',
        ]);

        return [$rich, $lean];
    }

    /** @return list<string> the module names a user currently holds, read past any scope */
    private function modulesOf(int $userId): array
    {
        $modules = userActiveModule::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->pluck('module')
            ->all();

        sort($modules);

        return $modules;
    }

    /**
     * Drive the operator-approved downgrade and return [operatorId, merchantId].
     *
     * SPLIT INTO TWO TESTS ON PURPOSE. Both consequences were first asserted in one method, and
     * the merchant assertion failed -- which stopped the method before the operator assertion ran,
     * so only half the defect was actually measured. One failing assertion masking a second is the
     * same "passed for the wrong reason" family this project keeps hitting, in reverse.
     *
     * @return array{0: int, 1: int}
     */
    private function operatorApprovedDowngrade(): array
    {
        [$rich, $lean] = $this->twoPlans();

        $operator = $this->makePlatformAdmin();
        $merchant = $this->makeMerchant(['plan_id' => $rich->id]);

        // Both principals hold both modules. The operator's rows are the ones the defect eats.
        foreach ([$operator->id, $merchant->id] as $id) {
            foreach (['Marketing', 'Campaigns'] as $module) {
                userActiveModule::create(['user_id' => $id, 'module' => $module]);
            }
        }

        $this->assertSame(['Campaigns', 'Marketing'], $this->modulesOf($merchant->id), 'premise');
        $this->assertSame(['Campaigns', 'Marketing'], $this->modulesOf($operator->id), 'premise');

        /*
         * THE OPERATOR IS THE AUTHENTICATED PRINCIPAL AND THE MERCHANT IS THE SUBJECT -- exactly
         * BankTransferController::orderapprove(), which loads the buyer by id and calls assignPlan
         * on them while the approver is signed in.
         */
        $this->actingAs($operator);
        $merchant->assignPlan($lean->id);

        return [$operator->id, $merchant->id];
    }

    #[Test]
    public function test_an_operator_driven_downgrade_reduces_the_merchants_modules(): void
    {
        [, $merchantId] = $this->operatorApprovedDowngrade();

        $this->assertSame(
            ['Marketing'],
            $this->modulesOf($merchantId),
            'The downgrade did not reduce the MERCHANT: they still hold a module the Lean plan '
            . 'does not grant, so an operator-approved downgrade leaves unpaid entitlement in place.'
        );
    }

    #[Test]
    public function test_an_operator_driven_downgrade_does_not_touch_the_operators_own_modules(): void
    {
        [$operatorId] = $this->operatorApprovedDowngrade();

        $this->assertSame(
            ['Campaigns', 'Marketing'],
            $this->modulesOf($operatorId),
            "The downgrade removed a module from the OPERATOR's own account. assignPlan() deletes "
            . 'where user_id = Auth::user()->id while creating where user_id = $this->id.'
        );
    }

    /**
     * THE SELF-SERVICE PATH MUST BE BYTE-IDENTICAL, because every gateway controller uses it and
     * a fix that only moved the bug would pass the test above.
     */
    #[Test]
    public function test_a_merchant_downgrading_their_own_plan_is_unchanged(): void
    {
        [$rich, $lean] = $this->twoPlans();

        $merchant = $this->makeMerchant(['plan_id' => $rich->id]);

        foreach (['Marketing', 'Campaigns'] as $module) {
            userActiveModule::create(['user_id' => $merchant->id, 'module' => $module]);
        }

        $this->actingAs($merchant);
        $merchant->assignPlan($lean->id);

        $this->assertSame(['Marketing'], $this->modulesOf($merchant->id),
            'A merchant downgrading themselves must end on exactly the new plan\'s modules.');
    }

    /**
     * AN UPGRADE ADDS, AND STILL TOUCHES NOBODY ELSE. The `create()` side already used `$this->id`,
     * so this direction was never broken -- it is here so the fix cannot regress it, and so the
     * pair reads as "the subject is the subject" rather than as one patched line.
     */
    #[Test]
    public function test_an_operator_driven_upgrade_adds_to_the_merchant_only(): void
    {
        [$rich, $lean] = $this->twoPlans();

        $operator = $this->makePlatformAdmin();
        $merchant = $this->makeMerchant(['plan_id' => $lean->id]);

        userActiveModule::create(['user_id' => $merchant->id, 'module' => 'Marketing']);
        userActiveModule::create(['user_id' => $operator->id, 'module' => 'Marketing']);

        $this->actingAs($operator);
        $merchant->assignPlan($rich->id);

        $this->assertSame(['Campaigns', 'Marketing'], $this->modulesOf($merchant->id),
            'The upgrade must grant the new plan\'s modules to the merchant.');
        $this->assertSame(['Marketing'], $this->modulesOf($operator->id),
            'The upgrade must not grant the operator anything.');
    }
}
