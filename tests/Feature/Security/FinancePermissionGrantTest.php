<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P1-SEC-02 / S15-B row 8 — the grantable-permission ceiling.
 * =============================================================================================
 *
 * `RoleController::store` and `::update` both pass the posted permission ids through
 * `filterGrantablePermissions()`, which asks `RolePolicy::grantPermission()` per name. That
 * predicate was a DENYLIST: `!in_array($name, config('superadminaccess'))`, 13 platform-operator
 * names. A denylist answers "is this name forbidden", never "is this actor entitled to hand it
 * out", so every name nobody thought to list was grantable by anybody holding `Create Role`.
 *
 * WHAT THAT LET THROUGH, measured against the application's own seeder rather than assumed.
 * `Utility::addNewData()` is the only mechanism that creates Permission rows, and it seeds 200.
 * It grants 181 of them to the merchant role (`$companyNewPermission`) and 29 to the operator
 * role. Nineteen are therefore outside a merchant's own grant, and of those the denylist covers
 * only twelve. The seven it missed:
 *
 *   Add Finance Balance                 }
 *   Deduct Finance Balance              }  the six money-moving finance permissions. Their rows
 *   Edit Finance Payment Status         }  exist and NO ROLE IS SEEDED WITH THEM -- deliberately:
 *   Request Finance Withdrawal          }  Utility.php:2866-2876 records that which role holds a
 *   View Finance Dashboard              }  money power is "a privilege decision for the owner,
 *   View Finance Withdrawal Requests    }  not something to infer from a route file".
 *   Manage Email Template                  operator-only, and simply absent from the denylist.
 *
 * So a merchant could tick a box and hand themselves -- or a staff role -- the authority to
 * credit and debit the merchant balance of record and to drive payouts, a decision the owner had
 * explicitly left unmade. That is the escalation, and it is an escalation ABOVE THE TENANT'S OWN
 * CEILING rather than merely a missing name.
 *
 * THE FIX IS POSITIVE AUTHORIZATION, which is the task's third acceptance criterion: the
 * grantable set is bounded by what the TENANT OWNER holds. The denylist is kept as defence in
 * depth, not as the control.
 *
 * WHY THE TENANT OWNER AND NOT THE ACTOR. For a merchant (`users.type = 'admin'`) `creatorId()`
 * returns their own id, so the two readings are identical and the criterion "a merchant cannot
 * grant a permission they do not themselves hold" is met exactly. They differ only for STAFF,
 * where bounding by the actor would also forbid a merchant delegating role composition to staff
 * -- a working feature. Bounding by the owner closes the escalation (nobody can exceed the
 * tenant) while leaving that delegation intact. The residual it leaves -- staff reaching parity
 * with their own merchant -- is narrower than what is closed here, is within one tenant, and is
 * recorded as its own row rather than silently chosen.
 *
 * THE FIXTURE DOES NOT INHERIT PRODUCTION'S ROLES. Measured: `addNewData()` in a test database
 * creates the 200 rows but leaves `admin`, `super admin` and `company` ABSENT, so the seeded
 * merchant holds ZERO. Every principal below is therefore given its permissions explicitly. A
 * test that leaned on seeded roles would be asserting against a role set that is not there.
 */
class FinancePermissionGrantTest extends SecurityTestCase
{
    /** The six whose rows exist and which no seeded role is granted. */
    private const FINANCE = [
        'Add Finance Balance',
        'Deduct Finance Balance',
        'Edit Finance Payment Status',
        'Request Finance Withdrawal',
        'View Finance Dashboard',
        'View Finance Withdrawal Requests',
    ];

    use RefreshDatabase;

    private function permissionId(string $name): int
    {
        return (int) Permission::firstOrCreate(
            ['name' => $name],
            ['display_name' => $name, 'guard_name' => 'web']
        )->id;
    }

    /** The role the posted name landed on, read without scopes. */
    private function createdRole(string $name): ?Role
    {
        return Role::withoutGlobalScopes()->where('name', $name)->first();
    }

    /**
     * A MERCHANT CANNOT MINT A ROLE CARRYING A MONEY POWER THEY DO NOT HOLD.
     *
     * One case per finance permission, so a fix that happens to cover one name and miss another
     * cannot pass. The principal is legitimate throughout: a merchant, signed in, holding
     * `Create Role` on their own store.
     */
    public function test_a_merchant_cannot_grant_a_finance_permission_they_do_not_hold(): void
    {
        foreach (self::FINANCE as $i => $financeName) {
            [$merchant] = $this->makeTenant();
            $this->grantPermission($merchant, 'Create Role');

            $financeId = $this->permissionId($financeName);
            $ordinaryId = $this->permissionId('Manage Product');
            $this->grantPermission($merchant, 'Manage Product');

            $roleName = 'escalation-probe-' . $i;

            $this->actingAs($merchant)->post('/roles', [
                'name' => $roleName,
                'permissions' => [$ordinaryId, $financeId],
            ]);

            $role = $this->createdRole($roleName);
            $this->assertNotNull($role,
                'The role was not created at all, so this case proves nothing about which '
                . 'permissions it carries. A refusal of the whole request would make every '
                . 'assertion below vacuous.');

            $carried = $role->permissions()->pluck('name')->all();

            // PER-CASE PREMISE. `'permissions' => 'required'` is validated against the RAW
            // request before the filter runs, so the role is created even when every posted
            // permission is dropped -- `assertNotNull` above does not rule out "the filter
            // dropped everything". Without this line a fix that refused ALL grants would satisfy
            // the assertion below. Raised by adversarial review; the class's own positive control
            // already covered it, but each case should stand on its own.
            $this->assertContains('Manage Product', $carried,
                'The ordinary permission was dropped too, so this case does not show the finance '
                . 'name being singled out. Carried: ' . implode(', ', $carried));
            $this->assertNotContains($financeName, $carried,
                'A merchant who does not hold "' . $financeName . '" granted it to a role they '
                . 'created. Carried: ' . implode(', ', $carried));
        }
    }

    /**
     * THE SAME CEILING ON THE UPDATE PATH.
     *
     * `update()` filters through the same helper, so this is not a second control -- it is the
     * second CALL SITE, and S13-b records that these two paths have diverged before (the
     * reserved-name validator existed on one and not the other).
     */
    public function test_a_merchant_cannot_add_a_finance_permission_to_an_existing_role(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');
        $this->grantPermission($merchant, 'Edit Role');
        $this->grantPermission($merchant, 'Manage Product');

        $ordinaryId = $this->permissionId('Manage Product');
        $financeId = $this->permissionId('Add Finance Balance');

        $role = Role::forceCreate([
            'name' => 'staff-role',
            'display_name' => 'Staff Role',
            'guard_name' => 'web',
            'created_by' => $merchant->creatorId(),
            'store_id' => $merchant->current_store,
        ]);

        $this->actingAs($merchant)->put('/roles/' . $role->id, [
            'name' => 'staff-role',
            'permissions' => [$ordinaryId, $financeId],
        ]);

        $carried = $role->fresh()->permissions()->pluck('name')->all();

        $this->assertContains('Manage Product', $carried,
            'The update did not apply at all, so the assertion below is vacuous. Carried: '
            . implode(', ', $carried));
        $this->assertNotContains('Add Finance Balance', $carried,
            'A merchant added a money-moving permission they do not hold to an existing role.');
    }

    /**
     * AND THE OPERATOR-ONLY PERMISSION THE DENYLIST ALSO MISSED.
     *
     * `Manage Email Template` is seeded to the operator role only and is absent from
     * config/superadminaccess.php. It is not money-moving, which is why S15-B did not name it;
     * it is in scope because positive authorization catches it for the same reason it catches
     * the finance six, and because a name-list fix would have missed it again.
     */
    public function test_a_merchant_cannot_grant_an_operator_only_permission(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');
        $this->grantPermission($merchant, 'Manage Product');

        $ordinaryId = $this->permissionId('Manage Product');
        $operatorOnlyId = $this->permissionId('Manage Email Template');

        $this->actingAs($merchant)->post('/roles', [
            'name' => 'operator-reach',
            'permissions' => [$ordinaryId, $operatorOnlyId],
        ]);

        $role = $this->createdRole('operator-reach');
        $this->assertNotNull($role);

        $carried = $role->permissions()->pluck('name')->all();
        // Per-case premise -- see the note in the finance test above.
        $this->assertContains('Manage Product', $carried,
            'The ordinary permission was dropped too, so this case does not show the '
            . 'operator-only name being singled out. Carried: ' . implode(', ', $carried));
        $this->assertNotContains('Manage Email Template', $carried,
            'A merchant granted an operator-only permission; the denylist never covered it.');
    }

    /**
     * THE POSITIVE CONTROL: ordinary role management still works.
     *
     * Without this, every assertion above is satisfied by a fix that refuses all grants.
     */
    public function test_a_merchant_can_still_grant_permissions_they_hold(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');
        foreach (['Manage Product', 'Create Product', 'Edit Product'] as $held) {
            $this->grantPermission($merchant, $held);
        }

        $ids = array_map(fn (string $n): int => $this->permissionId($n),
            ['Manage Product', 'Create Product', 'Edit Product']);

        $this->actingAs($merchant)->post('/roles', [
            'name' => 'ordinary-staff',
            'permissions' => $ids,
        ]);

        $role = $this->createdRole('ordinary-staff');
        $this->assertNotNull($role,
            'The merchant can no longer create a role at all, so the refusals above prove '
            . 'nothing. Session error: ' . json_encode(session('error')));

        $carried = $role->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame(['Create Product', 'Edit Product', 'Manage Product'], $carried,
            'A merchant lost the ability to delegate permissions they themselves hold.');
    }

    /**
     * THE PLATFORM OPERATOR IS UNAFFECTED — the task's second acceptance criterion.
     *
     * The operator holds nothing through a role in this fixture, so if the ceiling applied to
     * them they would be able to grant NOTHING. That makes this a real measurement of the
     * exemption rather than a restatement of it.
     */
    public function test_the_platform_operator_can_still_grant_anything(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->makeTenant();
        $this->grantPermission($operator, 'Create Role');

        $ids = array_map(fn (string $n): int => $this->permissionId($n),
            ['Add Finance Balance', 'Manage Email Template', 'Create Plan']);

        $this->actingAs($operator)->post('/roles', [
            'name' => 'operator-authored',
            'permissions' => $ids,
        ]);

        $role = $this->createdRole('operator-authored');
        $this->assertNotNull($role,
            'The operator can no longer create a role. Session error: '
            . json_encode(session('error')));

        $carried = $role->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame(['Add Finance Balance', 'Create Plan', 'Manage Email Template'], $carried,
            'The grantable ceiling is being applied to the platform operator, who is supposed to '
            . 'be exempt. Carried: ' . implode(', ', $carried));
    }

    /**
     * THE FORM AND THE HANDLER AGREE BY CONSTRUCTION.
     *
     * The role editor used to carry its own hard-coded copy of the denylist in three places
     * (`roles/create.blade.php` twice, `roles/edit.blade.php` once). With the server rule changed
     * to positive authorization those copies would have gone stale in the worst direction: the
     * merchant would be SHOWN a finance checkbox, tick it, read "Role successfully created." and
     * silently not have it. All three now ask the same policy the handler asks, so this asserts
     * what a user can actually see.
     *
     * Asserted on the rendered HTML because that is where the divergence would live -- a test of
     * the policy alone could not notice a view carrying its own copy.
     */
    public function test_the_role_form_does_not_offer_a_permission_the_handler_would_drop(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');
        $this->grantPermission($merchant, 'Manage Product');

        // Both must exist as rows, or their absence from the page proves nothing.
        $this->permissionId('Manage Product');
        foreach (self::FINANCE as $name) {
            $this->permissionId($name);
        }
        $this->permissionId('Manage Email Template');

        $response = $this->actingAs($merchant)->get('/roles/create');
        $this->assertSame(200, $response->status(),
            'The role create form no longer renders, so every assertion below is vacuous. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $html = (string) $response->getContent();

        // THE PREMISE: a permission the merchant DOES hold is on the page. Without this the
        // absences below are satisfied by a page that lists nothing at all.
        $this->assertStringContainsString('Manage Product', $html,
            'The form offers none of the merchant\'s own permissions, so the absence of the '
            . 'finance names below measures nothing.');

        foreach (self::FINANCE as $name) {
            $this->assertStringNotContainsString($name, $html,
                'The role form still offers "' . $name . '", which the handler will silently '
                . 'drop -- the view is carrying a stale copy of the rule.');
        }
        $this->assertStringNotContainsString('Manage Email Template', $html,
            'The role form still offers an operator-only permission.');
    }

    /**
     * THE OWNER-BOUNDING BRANCH, WHICH NOTHING ELSE EXECUTES.
     * ==========================================================================================
     *
     * Raised by adversarial review and it was right: every other principal in this file is type
     * `admin` (so `creatorId()` returns its own id and `tenantOwner()` returns early) or
     * `super admin` (so the operator limb returns before `tenantOwner()` is reached). So
     * `User::find($ownerId)` and the fail-closed `$owner !== null` limb had NEVER RUN, and the
     * design decision this task turns on -- bounding by the tenant owner rather than the actor,
     * so a merchant can still delegate role composition -- had zero regression protection. A
     * change making `tenantOwner()` return null for staff would have locked every delegated
     * staff member out of role composition and passed the whole suite green.
     *
     * Three cases, each biting a different way:
     *   1. staff CAN grant what their merchant holds   -- actor-bounding would fail this
     *   2. staff CANNOT grant what their merchant lacks -- the denylist would fail this
     *   3. staff with no resolvable owner grant nothing -- the fail-closed limb
     */
    public function test_staff_can_grant_a_permission_their_merchant_holds(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Product');

        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Create Role');

        $ordinaryId = $this->permissionId('Manage Product');

        $this->actingAs($staff->fresh())->post('/roles', [
            'name' => 'staff-composed',
            'permissions' => [$ordinaryId],
        ]);

        $role = $this->createdRole('staff-composed');
        $this->assertNotNull($role,
            'Staff can no longer create a role at all. Session error: '
            . json_encode(session('error')));
        $this->assertContains('Manage Product', $role->permissions()->pluck('name')->all(),
            'Staff cannot grant a permission their own merchant holds, so the ceiling is being '
            . 'read from the ACTOR rather than the tenant owner -- which breaks the delegation '
            . 'this design was chosen to preserve.');
    }

    public function test_staff_cannot_grant_a_permission_their_merchant_does_not_hold(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Product');

        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Create Role');

        $ordinaryId = $this->permissionId('Manage Product');
        $financeId = $this->permissionId('Add Finance Balance');

        $this->actingAs($staff->fresh())->post('/roles', [
            'name' => 'staff-escalation',
            'permissions' => [$ordinaryId, $financeId],
        ]);

        $role = $this->createdRole('staff-escalation');
        $this->assertNotNull($role);

        $carried = $role->permissions()->pluck('name')->all();
        $this->assertContains('Manage Product', $carried,
            'Nothing was granted, so this case does not single out the finance name.');
        $this->assertNotContains('Add Finance Balance', $carried,
            'Staff granted a money-moving permission that their own merchant does not hold.');
    }

    /**
     * FAIL CLOSED when the tenant owner cannot be resolved.
     *
     * `created_by = null` makes `creatorId()` return null, `(int) null === 0`, and
     * `User::find(0)` null -- so `heldBy()` returns [] and nothing is grantable. Without the
     * null limb this is a fatal on `null->allPermissions()`, so the test fails loudly either
     * way; what it pins is that the answer is "grant nothing" rather than "grant everything".
     */
    public function test_staff_with_no_resolvable_owner_grants_nothing(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Product');

        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Create Role');
        $staff->created_by = null;
        $staff->save();

        $ordinaryId = $this->permissionId('Manage Product');

        $response = $this->actingAs($staff->fresh())->post('/roles', [
            'name' => 'orphan-staff',
            'permissions' => [$ordinaryId],
        ]);

        /*
         * THE STATUS ASSERTION IS THE ONE THAT BITES, and the first version of this test did not
         * have it. It read `if ($role !== null) { assertSame([], ...) }`, which is unfalsifiable:
         * removing the null limb makes `null->allPermissions()` a fatal, Laravel answers 500, the
         * role is never created, `$role` is null and the whole assertion is SKIPPED. Measured --
         * the removal check passed with the control deleted, which is how it was caught.
         *
         * "Fail closed" means grant nothing, NOT crash. A 500 is a different defect and this
         * separates them.
         */
        $this->assertNotSame(500, $response->status(),
            'Resolving no tenant owner crashed the request instead of granting nothing. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $role = $this->createdRole('orphan-staff');

        // The row itself may exist -- `'permissions' => 'required'` passes on the raw request,
        // before the filter runs -- but it must carry nothing.
        $this->assertSame([], $role === null ? [] : $role->permissions()->pluck('name')->all(),
            'A principal whose tenant owner cannot be resolved was granted something. The '
            . 'ceiling must fail closed.');
    }

    /**
     * THE EDIT VIEW, WHICH THE CREATE-VIEW TEST DOES NOT COVER.
     *
     * Raised by review: the agreement test rendered only `GET /roles/create`, while the third
     * site that used to carry its own copy of the rule is `roles/edit.blade.php`. A stale copy
     * left there would have shipped silently -- exactly the divergence the design claims to
     * prevent.
     */
    public function test_the_role_edit_form_does_not_offer_a_permission_the_handler_would_drop(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');
        $this->grantPermission($merchant, 'Edit Role');
        $this->grantPermission($merchant, 'Manage Product');

        $this->permissionId('Manage Product');
        foreach (self::FINANCE as $name) {
            $this->permissionId($name);
        }

        $role = Role::forceCreate([
            'name' => 'editable-role',
            'display_name' => 'Editable Role',
            'guard_name' => 'web',
            'created_by' => $merchant->creatorId(),
            'store_id' => $merchant->current_store,
        ]);

        $response = $this->actingAs($merchant->fresh())->get('/roles/' . $role->id . '/edit');

        $this->assertSame(200, $response->status(),
            'The edit form does not render, so every assertion below is vacuous. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $html = (string) $response->getContent();

        $this->assertStringContainsString('Manage Product', $html,
            'The edit form offers none of the merchant\'s own permissions, so the absences '
            . 'below measure nothing.');

        foreach (self::FINANCE as $name) {
            $this->assertStringNotContainsString($name, $html,
                'The role EDIT form still offers "' . $name . '", which the handler will drop.');
        }
    }
}
