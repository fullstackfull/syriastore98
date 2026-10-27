<?php

namespace Tests\Feature\Merchant;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-05-5 — the staff and roles row actions: named at runtime, and each gate mirroring its own
 * handler.
 *
 * THE STAFF LIST HAD FIVE CONTROLS, NONE NAMED AND NONE GATED. The names are the usual trap --
 * `title`, which Bootstrap's tooltip blanks on initialisation; here it looked more deliberate
 * than usual because the markup also pre-set `data-bs-original-title`, which is the plugin's own
 * storage and not an accessible name either.
 *
 * THE GATES ARE THE INTERESTING HALF, because the four handlers do not agree with each other and
 * a single blanket gate would have been wrong for three of them:
 *   - `users.edit`            -> `isAbleTo('Edit User')`
 *   - `users.destroy`         -> `isAbleTo('Delete User')`
 *   - `stores.reset.password` -> `authorize('resetPassword', $user)`   (a POLICY)
 *   - `users.enable.login`    -> `can('update', $user)`                (a POLICY)
 *
 * Each is asserted here in the direction that proves it is a gate: a user who holds nothing sees
 * nothing, and a user who holds one permission does not get the others' controls.
 */
class StaffAndRoleRowActionsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function render(string $view, array $data): string
    {
        return (string) view($view, $data)->render();
    }

    private function target(): \App\Models\User
    {
        return $this->makeStaff($this->makeMerchant(['current_store' => 1]), ['name' => 'Rami Haddad']);
    }

    public function test_the_staff_actions_are_named_for_the_user_they_act_on(): void
    {
        $actor = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($actor, 'Edit User');
        $this->grantPermission($actor, 'Delete User');
        $this->actingAs($actor);

        $html = $this->render('users.action', ['user' => $this->target()]);

        $this->assertStringContainsString('aria-label="Edit user Rami Haddad"', $html,
            'the control must name the staff member, not just the verb — five identical '
            . '"button"s per row is what a screen-reader user heard before.');
        $this->assertStringContainsString('aria-label="Delete user Rami Haddad"', $html);
        $this->assertStringNotContainsString('btn-info', $html, 'the vendored theme\'s button');
    }

    public function test_a_user_holding_nothing_is_shown_no_staff_control(): void
    {
        $actor = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($actor, 'Manage User');
        $this->actingAs($actor);

        $html = $this->render('users.action', ['user' => $this->target()]);

        $this->assertStringNotContainsString('aria-label="Edit user', $html);
        $this->assertStringNotContainsString('aria-label="Delete user', $html);
        $this->assertStringNotContainsString('users.destroy', $html);
    }

    /** Each gate is its own: Edit must not carry Delete, and neither carries the policy ones. */
    public function test_edit_permission_alone_reveals_only_the_edit_control(): void
    {
        $actor = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($actor, 'Edit User');
        $this->actingAs($actor);

        $html = $this->render('users.action', ['user' => $this->target()]);

        $this->assertStringContainsString('aria-label="Edit user Rami Haddad"', $html);
        $this->assertStringNotContainsString('aria-label="Delete user', $html);
    }

    /**
     * THE LOGIN TOGGLE, which is the control this slice found hidden from everybody. Its gate is
     * `can('update', $user)`, and `UserPolicy::update` requires `Edit User` plus the tenant
     * rules — so a holder of `Edit User` in the same tenant must SEE it. Before P2-05-5 the
     * markup gated it on `Mange User Login`, a name seeded nowhere in either spelling, so it was
     * invisible to every principal including the tenant owner.
     */
    public function test_the_login_toggle_is_visible_to_someone_the_handler_would_allow(): void
    {
        $owner = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($owner, 'Edit User');
        $staff = $this->makeStaff($owner, ['name' => 'Rami Haddad']);
        $this->actingAs($owner);

        $html = $this->render('users.action', ['user' => $staff]);

        $this->assertTrue(
            str_contains($html, 'aria-label="Enable login for Rami Haddad"')
            || str_contains($html, 'aria-label="Disable login for Rami Haddad"'),
            'the login toggle must appear for a principal UserPolicy::update() allows. If this '
            . 'fails the control is hidden from someone the handler would accept — which is the '
            . 'exact defect P2-05-5 fixed, in a new disguise.');
    }

    // ------------------------------------------------------------------------------- roles

    public function test_the_role_actions_are_named_for_the_role_they_act_on(): void
    {
        $actor = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($actor, 'Edit Role');
        $this->grantPermission($actor, 'Delete Role');
        $this->actingAs($actor);

        $role = Role::forceCreate([
            'name' => 'Shop Manager', 'display_name' => 'Shop Manager',
            'guard_name' => 'web', 'created_by' => $actor->id, 'store_id' => 1,
        ]);

        $html = $this->render('roles.action', ['role' => $role]);

        $this->assertStringContainsString('aria-label="Edit role Shop Manager"', $html);
        $this->assertStringContainsString('aria-label="Delete role Shop Manager"', $html);
        $this->assertStringContainsString('ss-btn', $html);
    }

    public function test_a_user_without_role_permissions_is_shown_no_role_control(): void
    {
        $actor = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($actor, 'Manage Role');
        $this->actingAs($actor);

        $role = Role::forceCreate([
            'name' => 'Shop Manager 2', 'display_name' => 'Shop Manager 2',
            'guard_name' => 'web', 'created_by' => $actor->id, 'store_id' => 1,
        ]);

        $html = $this->render('roles.action', ['role' => $role]);

        $this->assertStringNotContainsString('aria-label="Edit role', $html);
        $this->assertStringNotContainsString('aria-label="Delete role', $html);
    }
}
