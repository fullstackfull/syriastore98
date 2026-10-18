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
