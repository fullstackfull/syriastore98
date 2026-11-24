<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\User;
use App\Policies\RolePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * DOES DEMOTING YOUR OWN `users.type` RAISE YOUR GRANT CEILING?
 *
 * `RolePolicy::tenantOwner()` resolves the ceiling from `User::creatorId()`, which returns the
 * actor's own id for type `admin` and `created_by` otherwise. The product lets a merchant change
 * their own `type` to a role name they created -- CLAUDE.md records this as a "supported action"
 * and as the vector that made `created_by = 1` an account takeover until `UserPolicy` fail-closed
 * on it. After such a change `creatorId()` drops to `created_by`, which for EVERY merchant is the
 * platform operator, so `tenantOwner()` answers with the OPERATOR and the ceiling becomes the
 * operator's held set minus `config('superadminaccess')`.
 *
 * `tenantOwner()`'s docblock deliberately accepts that for staff the OPERATOR CREATED, on the
 * ground that the operator is genuinely their tenant and the result is narrower than before. That
 * reasoning does not cover SELF-DEMOTION, where the actor had their own, smaller ceiling a moment
 * earlier. This measures which it is.
 */
final class RoleGrantCeilingSelfDemotionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const CANDIDATES = ['Manage Product', 'Manage Plan', 'Manage Setting'];

    public function test_demoting_your_own_type_does_not_widen_what_you_may_grant(): void
    {
        // The operator AT ID 1, because `makeMerchant()` stamps `created_by = 1` and so does the
        // product: every merchant's `created_by` is the operator.
        $operator = $this->makePlatformAdmin(['id' => 1]);

        $role = $this->makeGlobalAdminRole();

        foreach (['Manage Plan', 'Manage Setting'] as $name) {
            $role->givePermission(Permission::firstOrCreate(['name' => $name], ['display_name' => $name]));
        }

        $operator->addRole($role);
        $operator->flushCache();

        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Product');

        $policy = new RolePolicy;
        $before = $policy->grantableNames(User::find($merchant->id), self::CANDIDATES);

        // ---- premises, each asserted rather than assumed -------------------------------------
        $this->assertSame([1], [(int) $operator->id],
            'PREMISE: the operator must BE id 1, because that is the `created_by` a merchant carries.');

        $this->assertContains('Manage Plan',
            User::find(1)->allPermissions(['name'])->pluck('name')->all(),
            'PREMISE: the operator must hold a name the merchant does not, or this measures nothing.');

        $this->assertSame(['Manage Product'], array_values($before),
            'PREMISE: as a type-`admin` merchant the ceiling is their OWN held set.');

        // ---- the act: the product's own supported self-demotion ------------------------------
        $merchant->type = 'Shop Manager';
        $merchant->save();
        (new User)->flushCache();

        $demoted = User::find($merchant->id);

        $this->assertSame(1, (int) $demoted->creatorId(),
            'PREMISE: the demotion must actually move `creatorId()` onto the operator, or the '
            . 'fixture has not reproduced the condition.');

        // ---- the question --------------------------------------------------------------------
        $after = $policy->grantableNames($demoted, self::CANDIDATES);

        $this->assertSame([], array_values(array_diff($after, $before)),
            'Demoting your own `type` WIDENED what you may grant: ' . implode(', ', array_diff($after, $before))
            . '. The ceiling moved from the actor to the platform operator, so a merchant can mint '
            . "a role carrying permissions they never held, by editing their own row.");
    }

    /**
     * AND THE NEW LIMB MUST NOT FIRE FOR GENUINE STAFF, which is the only thing it could break.
     *
     * `FinancePermissionGrantTest` pins "staff CAN grant what their merchant holds" as the
     * design, and names actor-bounding as the change that would break it. This asserts the same
     * property through the policy directly, in the file that added the limb, so a future
     * tightening of the discriminator fails here too and not only there.
     */
    public function test_a_staff_member_still_takes_their_merchants_ceiling(): void
    {
        $this->makePlatformAdmin(['id' => 1]);

        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Product');

        $staff = $this->makeStaff(User::find($merchant->id), ['current_store' => $store->id]);
        $staff = User::find($staff->id);

        // PREMISE: the staff member holds NOTHING of their own, so a result of `['Manage
        // Product']` can only come from their merchant's ceiling and not from their own.
        $this->assertSame([], $staff->allPermissions(['name'])->pluck('name')->all(),
            'PREMISE: this staff member must hold no permission of their own, or the assertion '
            . 'below cannot tell owner-bounding from actor-bounding.');

        // PREMISE: and they must own no store, or the new limb fires and this measures itself.
        $this->assertFalse(\App\Models\Store::where('created_by', $staff->id)->exists(),
            'PREMISE: genuine staff must own no store.');

        $this->assertSame(['Manage Product'],
            array_values((new RolePolicy)->grantableNames($staff, self::CANDIDATES)),
            "A staff member no longer takes their merchant's ceiling, so delegated role "
            . 'composition is broken — the regression `FinancePermissionGrantTest` warned about.');
    }
}
