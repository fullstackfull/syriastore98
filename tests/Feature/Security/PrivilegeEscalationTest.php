<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * S13 -- PRIVILEGE ESCALATION THROUGH ROLE ASSIGNMENT.
 *
 * THE DEFECT, and why it outranks every access-control finding in this project.
 * App\Http\Controllers\UserController::store() resolved the requested role with a bare
 * `Role::find($request->role)` and then wrote BOTH
 *
 *     $user->type = $role_r->name;     // the string every operator check in the codebase reads
 *     $user->addRole($role_r);         // and the Laratrust role, i.e. its permissions
 *
 * with no tenant predicate on the lookup and no reserved-name refusal. The seeded global
 * `super admin` role (database/seeders/PermissionTableSeeder.php) carries store_id NULL, so it is
 * findable by id from any tenant, and `Create User` is granted to the merchant `admin` role
 * (app/Models/Utility.php), so EVERY self-registered merchant held the permission needed.
 *
 * The consequence is not local. Nothing in this application trusts Laratrust for operator
 * identity -- `permissions_as_gates` is disabled and Laratrust has no super-admin bypass -- so
 * authorization code compares `users.type === 'super admin'` directly. App\Policies\OrderPolicy,
 * App\Http\Middleware\OrderDocumentAccessGuard and the rest all branch on that string. A merchant
 * who can write it into a user they create defeats every `store_id` predicate in the codebase at
 * once, including the order-document guard added in P01-SEC-2.
 *
 * THE FIX IS NOT NEW CODE. The sibling update() was already hardened and carries exactly the two
 * controls store() lacked -- a role lookup scoped to the actor's own tenant, and a
 * RolePolicy::RESERVED_NAMES refusal. store() was missed. This suite pins both halves on both
 * methods so the pair cannot drift apart again.
 */
class PrivilegeEscalationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The global platform role, exactly as the seeder creates it: store_id NULL, so visible to
     * every tenant's `Role::find()`.
     */
    private function platformRole(): Role
    {
        return Role::forceCreate([
            'name' => 'super admin',
            'display_name' => 'Super Admin',
            'guard_name' => 'web',
            'created_by' => 1,
            'store_id' => null,
        ]);
    }

    /**
     * A merchant who holds 'Create User', which the seeded merchant role grants.
     *
     * THE PLAN IS PART OF THE FIXTURE, not incidental. store() gates on
     * `$plan = Plan::find($user->plan_id); if ($total_users < $plan->max_users || $plan->max_users == -1)`,
     * so a merchant with no plan_id dereferences null and the request dies BEFORE the role is
     * ever read. A reproduction without a plan therefore looks like "the escalation does not
     * happen", which is exactly the false negative this comment exists to prevent: the first
     * version of this fixture had no plan, three tests created no user, and PHPUnit reported them
     * as risky-no-assertions rather than as passing. max_users is '-1' (unlimited) so the gate
     * cannot mask the result.
     */
    private function merchantWhoCanCreateUsers(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create User');

        $plan = \App\Models\Plan::forceCreate([
            'name' => 'Audit Plan',
            'price' => 0,
            'duration' => 'Unlimited',
            'max_stores' => -1,
            'max_products' => -1,
            'max_users' => -1,
            // The two columns the plans table declares with neither nullable() nor a default
            // (database/migrations/2023_12_08_123752_create_plans_table.php:20 and :24), so
            // omitting either is a NOT NULL violation rather than a silent empty value.
            'themes' => '[]',
            'storage_limit' => '-1',
        ]);

        $merchant->plan_id = $plan->id;
        $merchant->save();

        return [$merchant->fresh(), $store];
    }

    // -----------------------------------------------------------------------------------
    // THE PREMISE. Without these the escalation tests could pass for the wrong reason.
    // -----------------------------------------------------------------------------------

    public function test_the_premise_the_platform_role_is_visible_across_tenants(): void
    {
        $role = $this->platformRole();

        $this->assertNull($role->store_id, 'The seeded platform role is expected to carry a NULL store_id.');
        $this->assertNotNull(Role::find($role->id), 'An unscoped Role::find() must be able to see it -- that is the whole premise.');
    }

    public function test_the_premise_a_merchant_really_holds_create_user(): void
    {
        [$merchant] = $this->merchantWhoCanCreateUsers();

        $this->assertTrue(
            $merchant->isAbleTo('Create User'),
            'If a merchant could not hold Create User, store() would be unreachable and this suite vacuous.'
        );
    }

    // -----------------------------------------------------------------------------------
    // THE ESCALATION ITSELF
    // -----------------------------------------------------------------------------------

    public function test_a_merchant_cannot_create_a_platform_operator(): void
    {
        [$merchant, $store] = $this->merchantWhoCanCreateUsers();
        $platform = $this->platformRole();

        $this->actingAs($merchant)->post('/users', [
            'name' => 'Escalated',
            'email' => 'escalated@example.test',
            'password' => 'not-a-real-password',
            'role' => $platform->id,
        ]);

        // ASSERTED UNCONDITIONALLY. A refusal (no row at all) is the expected outcome, so a
        // bare `if ($created !== null)` would make this test pass by performing no assertions --
        // PHPUnit reports that as risky, not as proof. Both outcomes are stated instead.
        $created = User::where('email', 'escalated@example.test')->first();

        $this->assertSame(
            0,
            User::where('created_by', $merchant->id)->where('type', 'super admin')->count(),
            'A merchant created a user whose users.type is "super admin". Every operator check in '
            . 'this codebase reads that string, so this defeats all tenant isolation at once.'
        );

        if ($created !== null) {
            $this->assertNotSame('super admin', $created->type);
            $this->assertFalse(
                $created->hasRole('super admin'),
                'The Laratrust platform role was attached, which grants its permissions -- a '
                . 'separate escalation from the users.type string.'
            );
        }
    }

    public function test_a_merchant_cannot_attach_another_tenants_role(): void
    {
        [$merchantA, $storeA] = $this->merchantWhoCanCreateUsers();
        [$merchantB, $storeB] = $this->makeTenant();

        $foreignRole = $this->makeRole($merchantB, $storeB, 'tenant-b-manager');

        $this->actingAs($merchantA)->post('/users', [
            'name' => 'Cross Tenant',
            'email' => 'crosstenant@example.test',
            'password' => 'not-a-real-password',
            'role' => $foreignRole->id,
        ]);

        $created = User::where('email', 'crosstenant@example.test')->first();

        $this->assertSame(
            0,
            User::where('created_by', $merchantA->id)->where('type', 'tenant-b-manager')->count(),
            "Merchant A created a user carrying merchant B's role name."
        );

        if ($created !== null) {
            $this->assertFalse($created->hasRole('tenant-b-manager'),
                "Merchant A attached merchant B's role, so the new user holds B's permissions.");
        }
    }

    /**
     * The role_user table is the authority Laratrust actually reads, so it is asserted directly
     * rather than through the model -- a hasRole() that consults a cache could hide a real row.
     */
    public function test_no_role_user_row_links_a_merchant_created_user_to_the_platform_role(): void
    {
        [$merchant] = $this->merchantWhoCanCreateUsers();
        $platform = $this->platformRole();

        $this->actingAs($merchant)->post('/users', [
            'name' => 'Ledger Check',
            'email' => 'ledger@example.test',
            'password' => 'not-a-real-password',
            'role' => $platform->id,
        ]);

        // Asserted against the WHOLE table rather than one user id, so a refusal and a
        // successful-but-unprivileged create are both covered without an `if`.
        $this->assertSame(
            0,
            (int) DB::table('role_user')->where('role_id', $platform->id)->count(),
            'A role_user row attaches a merchant-created user to the platform role. That grants '
            . "the role's permissions, which is a second escalation beyond the users.type string."
        );

        $created = User::where('email', 'ledger@example.test')->first();
        $this->assertTrue($created === null || !$created->hasRole('super admin'));
    }

    // -----------------------------------------------------------------------------------
    // S13-b -- THE RESERVED-NAME LIST, which was the hole in the FIRST S13 fix.
    //
    // UserController::update() was already hardened and BOTH its controls were intact, yet a
    // staff member could still self-promote: RolePolicy::RESERVED_NAMES listed only the
    // `super admin` spellings, and `admin` is an authority literal too -- compared against 47
    // times, and the value User::creatorId() uses to decide that a row is the root of its own
    // tenant. Scoping the lookup was necessary and not sufficient.
    // -----------------------------------------------------------------------------------

    public function test_admin_is_a_reserved_role_name(): void
    {
        $this->assertContains('admin', RolePolicy::RESERVED_NAMES,
            'users.type === "admin" decides tenant-root status (User::creatorId()) and gates '
            . 'store creation (StoreController::create()), so the name must be reserved.');
        $this->assertContains('super admin', RolePolicy::RESERVED_NAMES);
    }

    public function test_staff_cannot_self_promote_to_tenant_owner_by_naming_a_role_admin(): void
    {
        [$merchant, $store] = $this->merchantWhoCanCreateUsers();

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, 'Create Role');
        $this->grantPermission($staff, 'Edit User');
        $staff = $staff->fresh();

        // Step 1: the role must not even be creatable.
        $this->actingAs($staff)->post('/roles', [
            'name' => 'admin',
            'permissions' => [],
        ]);

        $this->assertSame(
            0,
            Role::where('name', 'admin')->where('store_id', $store->id)->count(),
            'A tenant-scoped role literally named "admin" was created. Assigning it writes '
            . 'users.type = "admin", which makes the holder the root of their own tenant.'
        );

        // Step 2: and even if one existed, assigning it must be refused. Created directly here,
        // bypassing the validator, so this half is tested independently of step 1.
        $smuggled = $this->makeRole($merchant, $store, 'admin');

        $this->actingAs($staff)->put('/users/' . $staff->id, [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => $smuggled->id,
        ]);

        $this->assertNotSame('admin', $staff->fresh()->type,
            'A staff member promoted themselves to users.type = "admin".');
        $this->assertSame((int) $merchant->id, (int) $staff->fresh()->creatorId(),
            'creatorId() now resolves to the staff member themselves, so they are their own '
            . 'tenant root and every created_by predicate answers about them.');
    }

    /**
     * A real permission id for the role-write routes.
     *
     * `'permissions' => 'required'` REJECTS AN EMPTY ARRAY, so `['permissions' => []]` never
     * reaches the creating code and every "the role was not created" assertion passes for the
     * wrong reason. The first draft of the case-variant tests below did exactly that, and its own
     * legitimate-name controls are what caught it. Noted here because
     * `test_staff_cannot_self_promote_to_tenant_owner_by_naming_a_role_admin` carries the same
     * `['permissions' => []]` and its step-1 assertion is therefore weaker than it reads -- its
     * step 2, which smuggles the role in directly, is the half that bites.
     */
    private function somePermissionId(): int
    {
        return (int) \App\Models\Permission::firstOrCreate(
            ['name' => 'Manage Dashboard'],
            ['display_name' => 'Manage Dashboard']
        )->id;
    }

    // -----------------------------------------------------------------------------------
    // THE CASE VARIANTS -- the asymmetry S13-b's fix left behind, and why it was not an
    // escalation.
    //
    // S13-b closed the LIST. It did not make the two gates over that list agree about CASE.
    // `RoleController::store():124` and `::update():254` validated with
    // `Rule::notIn(RolePolicy::RESERVED_NAMES)`, which compares case-SENSITIVELY against eight
    // hand-written spellings, while the gate that actually decides the `users.type` write --
    // `UserController:147` and `:302`, in front of `:154` and `:305` -- lowercases the needle and
    // is therefore case-INSENSITIVE by construction. Measured against the real constant, the
    // validator let through 'Super admin', 'sUper admin', 'SUPER Admin', 'Super ADMIN' and
    // 'aDmin'.
    //
    // NO ESCALATION FOLLOWED, AND THAT WAS ESTABLISHED BY EXECUTION BEFORE ANYTHING CHANGED. The
    // chain breaks twice over: at the lowercasing gate, engine-independently; and on production
    // MySQL before that, because `roles.name` carries a UNIQUE index and `utf8mb4_0900_ai_ci` is
    // case-insensitive, so the variant row is a duplicate-key violation against the platform's own
    // 'super admin'.
    //
    // AND THAT SECOND REASON IS WHY ONE OF THESE TESTS CANNOT LIVE HERE. SQLite's unique index is
    // BINARY, so the suite ACCEPTS a row production REFUSES -- the engine hiding the truth in the
    // SAFE direction, which is the more dangerous way round: three independent investigation
    // agents, probing only on SQLite, concluded that
    // `Role::where('name','LIKE','super admin')->first()` at app/Models/Utility.php:3117 was "the
    // real defect" because two rows could match it. On MySQL exactly one row can ever match.
    // Both engines side by side, with that refutation:
    // tests/Support/Security/bin/verify-reserved-role-name-collation-on-mysql.php.
    //
    // So the fix closes an ASYMMETRY, not a hole -- worth making because a defence that only looks
    // like one is worse than none.
    // -----------------------------------------------------------------------------------

    /**
     * THE PREDICATE, driven directly. One place, so a third consumer cannot hand-write a fourth
     * comparison -- the drift `ComparesRowOwnership` exists to prevent, applied to this invariant.
     */
    public function test_the_reserved_name_predicate_is_case_insensitive(): void
    {
        foreach (['super admin', 'Super admin', 'sUper admin', 'SUPER Admin', 'Super ADMIN',
            'admin', 'Admin', 'ADMIN', 'aDmin', 'superadmin', 'SuperAdmin', ' admin '] as $name) {
            $this->assertTrue(RolePolicy::isReservedName($name),
                "`{$name}` must be reserved. Rule::notIn let five of these through.");
        }

        foreach (['shop-helper', 'administrator', 'admin-assistant', 'supervisor'] as $name) {
            $this->assertFalse(RolePolicy::isReservedName($name),
                "`{$name}` is a legitimate role name and must not be refused.");
        }

        // Interior whitespace is deliberately NOT collapsed: 'super  admin' is a different string
        // that matches no comparison in the application, and collapsing it would refuse legitimate
        // names for no gain. Measured: it IS writable into users.type, and grants nothing.
        $this->assertFalse(RolePolicy::isReservedName('super  admin'));

        $this->assertFalse(RolePolicy::isReservedName(['admin']),
            'A non-scalar must not be coerced into a match or a fatal.');
    }

    public function test_staff_cannot_create_a_role_named_with_a_case_variant_of_a_reserved_name(): void
    {
        [$merchant, $store] = $this->merchantWhoCanCreateUsers();

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, 'Create Role');
        $staff = $staff->fresh();

        $permission = $this->somePermissionId();

        foreach (['Super admin', 'sUper admin', 'SUPER Admin', 'aDmin'] as $variant) {
            $this->actingAs($staff)->post('/roles',
                ['name' => $variant, 'permissions' => [$permission]]);

            $this->assertSame(0, Role::where('name', $variant)->count(),
                "A role named `{$variant}` was created. The validator compared case-sensitively "
                . 'against a spelling list until this was normalised.');
        }

        // THE CONTROL. Without it every assertion above is satisfied by the route 404ing, the
        // permission being absent, or the plan gate refusing -- and a legitimate name proves the
        // fixture reaches the creating code at all.
        $this->actingAs($staff)->post('/roles',
            ['name' => 'shop-helper', 'permissions' => [$permission]]);

        $this->assertSame(1, Role::where('name', 'shop-helper')->count(),
            'A legitimate role name must still be creatable, or the refusals above prove nothing '
            . 'about the reserved-name rule.');
    }

    /**
     * THE RENAME IS THE MORE INTERESTING OF THE TWO SITES, because a create-time-only check cannot
     * see it: make a role under a safe name, then rename it.
     *
     * The escalation primitive that once made this devastating is already dead -- RoleController
     * :270-280 records the deleted `User::whereIn(...)->update(['type' => $input['name']])` that
     * propagated a rename into every holder's `users.type`. So this test pins the validator, not a
     * live promotion.
     */
    public function test_staff_cannot_rename_their_own_role_into_a_case_variant(): void
    {
        [$merchant, $store] = $this->merchantWhoCanCreateUsers();

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, 'Create Role');
        $this->grantPermission($staff, 'Edit Role');
        $staff = $staff->fresh();

        $own = $this->makeRole($merchant, $store, 'shop-helper');
        $permission = $this->somePermissionId();

        foreach (['Super admin', 'aDmin'] as $variant) {
            $this->actingAs($staff)->put('/roles/' . $own->id, [
                'name' => $variant,
                'permissions' => [$permission],
            ]);

            $this->assertSame('shop-helper', (string) $own->fresh()->name,
                "The role was renamed to `{$variant}`. update():254 compared case-sensitively "
                . 'until this was normalised, and a create-time-only check cannot see a rename.');
        }

        // THE CONTROL: a legitimate rename must still work.
        $this->actingAs($staff)->put('/roles/' . $own->id, [
            'name' => 'floor-staff',
            'permissions' => [$permission],
        ]);

        $this->assertSame('floor-staff', (string) $own->fresh()->name,
            'A legitimate rename must still succeed, or the refusals above prove nothing.');
    }

    /**
     * WHY THE CHAIN NEVER COMPLETED, pinned so the next reader does not have to re-derive it: the
     * gate in front of the only tenant-reachable writers of `users.type` lowercases, and therefore
     * refuses every variant regardless of what the validator does.
     */
    public function test_the_users_type_writer_is_gated_case_insensitively(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/UserController.php'));

        $this->assertSame(2, substr_count($controller,
            'in_array(strtolower(trim((string) $role'),
            'Both users.type write paths must still lowercase the role name before comparing it '
            . 'to RESERVED_NAMES. This is what made the case-variant finding contained rather '
            . 'than live.');

        [$merchant, $store] = $this->merchantWhoCanCreateUsers();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, 'Edit User');
        $staff = $staff->fresh();

        // Smuggled in directly, bypassing the now-normalised validator, so this half is tested
        // independently of it -- the same shape the `admin` test above uses.
        $smuggled = $this->makeRole($merchant, $store, 'Super admin');

        $this->actingAs($staff)->put('/users/' . $staff->id, [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => $smuggled->id,
        ]);

        $this->assertNotSame('Super admin', (string) $staff->fresh()->type,
            'users.type was written to a case variant of the platform-operator literal. Fifteen '
            . 'normalising comparisons in this codebase would then answer OPERATOR for this row.');
    }

    // -----------------------------------------------------------------------------------
    // S13-c / S13-d -- THE TWO is_enable_login WRITERS, which had no authorization at all.
    // -----------------------------------------------------------------------------------

    public function test_an_unrelated_staff_member_cannot_re_enable_a_locked_out_merchant(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        $victim->is_enable_login = 0;
        $victim->save();

        // A different tenant entirely, holding no permissions whatsoever.
        [$otherMerchant, $otherStore] = $this->makeTenant();
        $attacker = $this->makeStaff($otherMerchant, ['current_store' => $otherStore->id]);

        $this->actingAs($attacker)->post('/user-unable', [
            'id' => $victim->id,
            'owner_id' => $victim->id,
            'name' => 'user',
            'is_active' => 1,
        ]);

        $this->assertSame(0, (int) $victim->fresh()->is_enable_login,
            'A zero-permission staff member in an unrelated tenant re-granted sign-in to a '
            . 'merchant an operator had locked out.');
    }

    public function test_an_unrelated_staff_member_cannot_toggle_login_through_the_encrypted_route(): void
    {
        [$victim] = $this->makeTenant();
        $victim->is_enable_login = 1;
        $victim->save();

        [$otherMerchant, $otherStore] = $this->makeTenant();
        $attacker = $this->makeStaff($otherMerchant, ['current_store' => $otherStore->id]);

        // The encrypted id is an ENCODING, not a control: APP_KEY is in this repository's git
        // history, so anyone with a clone can mint this for any user id.
        $this->actingAs($attacker)->get('/user-enable-login/' . \Illuminate\Support\Facades\Crypt::encrypt($victim->id));

        $this->assertSame(1, (int) $victim->fresh()->is_enable_login,
            'A zero-permission staff member toggled another tenant\'s login gate.');
    }

    public function test_a_merchant_can_still_manage_their_own_staff_login(): void
    {
        [$merchant, $store] = $this->merchantWhoCanCreateUsers();
        $this->grantPermission($merchant, 'Edit User');

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $staff->is_enable_login = 1;
        $staff->save();

        $this->actingAs($merchant->fresh())->post('/user-unable', [
            'id' => $staff->id,
            'owner_id' => $merchant->id,
            'name' => 'user',
            'is_active' => 0,
        ]);

        $this->assertSame(0, (int) $staff->fresh()->is_enable_login,
            'A merchant can no longer disable their own staff member\'s login -- the fix went too far.');
    }

    // -----------------------------------------------------------------------------------
    // THE LEGITIMATE FLOWS MUST SURVIVE. A fix that blocks these is not a fix.
    // -----------------------------------------------------------------------------------

    public function test_a_merchant_can_still_create_staff_with_their_own_role(): void
    {
        [$merchant, $store] = $this->merchantWhoCanCreateUsers();
        $ownRole = $this->makeRole($merchant, $store, 'shop-manager');

        $this->actingAs($merchant)->post('/users', [
            'name' => 'Legit Staff',
            'email' => 'legit@example.test',
            'password' => 'not-a-real-password',
            'role' => $ownRole->id,
        ]);

        $created = User::where('email', 'legit@example.test')->first();

        $this->assertNotNull($created, 'The ordinary staff-creation flow was broken by the fix.');
        $this->assertSame('shop-manager', $created->type);
        $this->assertTrue($created->hasRole('shop-manager'));
        $this->assertSame((int) $store->id, (int) $created->current_store);
        $this->assertSame((int) $merchant->id, (int) $created->created_by);
    }

    public function test_a_platform_operator_retains_their_own_capabilities(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->assertSame('super admin', $operator->type,
            'The fix must not strip or rewrite an existing operator.');
        $this->assertTrue(
            in_array('super admin', RolePolicy::RESERVED_NAMES, true),
            'RESERVED_NAMES must still contain the operator spelling the codebase compares against.'
        );
    }

    // -----------------------------------------------------------------------------------
    // THE PAIR MUST NOT DRIFT AGAIN. store() and update() are asserted to carry the SAME
    // two controls, by reading the source -- this is what was actually wrong: one of a pair
    // was hardened and the other was not, and nothing noticed.
    // -----------------------------------------------------------------------------------

    public function test_both_user_write_paths_scope_the_role_lookup_and_refuse_reserved_names(): void
    {
        $raw = (string) file_get_contents(base_path('app/Http/Controllers/UserController.php'));

        // COMMENT LINES ARE STRIPPED FIRST, the way the repo's own raw-query inventories do it.
        // Both methods now DOCUMENT the old `Role::find($request->role)` primitive in a comment
        // explaining what was wrong, so a naive scan of the whole file matches the documentation
        // and reports the defect as still present. That false positive is exactly what this
        // stripping prevents -- and the documentation is worth more than the convenience of a
        // one-line assertion.
        $source = implode("\n", array_filter(
            preg_split('/\R/', $raw),
            fn ($line) => preg_match('/^\s*(\/\/|\*|\/\*)/', $line) !== 1
        ));

        $this->assertStringNotContainsString(
            'Role::find($request->role)',
            $source,
            'UserController still resolves a role with an unscoped Role::find() on request input. '
            . 'That is the S13 primitive.'
        );

        $this->assertSame(
            2,
            substr_count($source, 'RolePolicy::RESERVED_NAMES'),
            'Both user write paths (store and update) must refuse a reserved role name. Exactly '
            . 'one of them doing so is the defect this suite exists for.'
        );

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, "->where('store_id', getCurrentStore())"),
            'Both user write paths must scope the role lookup to the actor\'s own store.'
        );
    }
}
