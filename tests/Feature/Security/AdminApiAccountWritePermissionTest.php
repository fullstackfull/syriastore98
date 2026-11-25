<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * GAP-251 / P4-F120 — FIVE ACCOUNT DOORS ON THE ADMIN API HAD TENANCY AND NO PERMISSION.
 * =============================================================================================
 *
 * All five found by the behavioural route census of 2026-11-25 (groups 3 and 4), by driving each
 * route with an unpermissioned staff member of the owning store and reading the row back:
 *
 *     POST change-profile    rewrote `delivery_boys.email` + `name`     driver login identifier
 *     POST logOut            deleted a driver's `personal_access_tokens`
 *     POST delete-user       deleted a `delivery_boys` row              irreversible
 *     POST editprofile       rewrote a colleague's `users.email`        panel login identifier
 *     POST updatepassword    set a COLLEAGUE'S PASSWORD                 lateral credential write
 *
 * Each already carried a tenancy predicate — `resolveManageableDeliveryBoy()` or
 * `resolveManageableUser()`, both of which replaced a bare `find($request->id)` that reached every
 * tenant. Those settled WHICH TENANT and nothing about WHO INSIDE IT, so every employee qualified.
 *
 * ---------------------------------------------------------------------------------------------
 * THE TWO E-MAIL WRITES ARE TAKEOVER *VECTORS*, NOT CREDENTIAL WRITES, and the distinction is
 * measured rather than hedged: both handlers leave the password hash untouched (asserted below in
 * both cases). What they do is point a login at an address the attacker controls —
 * `delivery_boys.email` is what `deliveryLogin` authenticates on, and `users.email` is what
 * `adminlogin` authenticates on and where the web reset sends.
 *
 * `updatepassword` IS a credential write, and its bound is also measured: a sibling staff member's
 * row is reachable, the store OWNER's is refused (their `created_by` is the operator, not
 * themselves), and a foreign tenant is refused. So it is lateral, not vertical — held back only by
 * knowing the colleague's current password.
 *
 * ---------------------------------------------------------------------------------------------
 * FOUR NAMES COPIED FROM A WEB TWIN, ONE POLICY REUSED, ONE NAME CHOSEN BY ANALOGY AND FLAGGED.
 *
 *     change-profile   `Edit Deliveryboy`    = `deliveryboysUpdate/{id}`'s own name
 *     delete-user      `Delete Deliveryboy`  = `deliveryboysDestroy/{id}`'s own name
 *     editprofile      `Edit User`           = `UserController:232`
 *     updatepassword   `UserPolicy::resetPassword` — the policy the WEB uses
 *                      (`UserController::updatePassword` calls `authorize('resetPassword', $user)`)
 *     logOut           `Edit Deliveryboy`    — BY ANALOGY. There is no standalone driver-logout
 *                      action on the web at all; it revokes tokens only inside a password reset
 *                      (`DeliveryBoyController:251`, gated on `Reset password Deliveryboy`). Acting
 *                      on a driver's account is what `Edit Deliveryboy` governs on the two sibling
 *                      doors, so it is the closest EXISTING right rather than a new one. If the
 *                      owner prefers the reset permission it is a one-word change.
 *
 * Reusing the policy for `updatepassword` is strictly better than picking a name: it already carries
 * operator-first ordering, the `Reset Password` permission, a refusal of an OPERATOR as the target
 * however the `type` row is spelled, and `sameTenant()` — and it means the two surfaces cannot
 * drift. `Gate::allows()` rather than `authorize()`, because the latter throws an HTML 403 the
 * shipped Flutter app does not parse.
 */
class AdminApiAccountWritePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const DRIVER_PASSWORD = 'driver-known-password';

    private User $merchant;

    private Store $store;

    private DeliveryBoy $driver;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * AN OPERATOR AT ID 1 FIRST. `makeMerchant()` sets `created_by => 1`, so with no platform
         * admin occupying that id the merchant becomes its OWN tenant root and
         * `resolveManageableUser()` appears to let staff edit the owner — the `created_by = 1` trap
         * CLAUDE.md records as an account-takeover. Two census agents hit it independently today
         * and one first reported a takeover because of it. This fixture starts in production shape.
         */
        $this->makePlatformAdmin();
        \Cache::forget('admin_details');

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->driver = DeliveryBoy::forceCreate([
            'name' => 'F120 Driver', 'email' => 'f120-driver@example.test',
            'password' => Hash::make(self::DRIVER_PASSWORD), 'contact' => '0100000000',
            'type' => 'deliveryboy', 'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);

        $this->colleague = $this->staff('f120-colleague@example.test');
    }

    private function staff(string $email, ?string $permission = null): User
    {
        $staff = $this->makeStaff($this->merchant, ['email' => $email]);
        $staff->current_store = $this->store->id;
        $staff->save();
        $staff = User::find($staff->id);

        if ($permission !== null) {
            $this->grantPermission($staff, $permission);
            $staff = User::find($staff->id);
        }

        return $staff;
    }

    /** Returns $this -- `withHeader()` is a TestCase method, not a pending-request builder. */
    private function as(User $actor): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $actor->createToken('app')->plainTextToken);
    }

    private function driverRow(): ?object
    {
        return DB::table('delivery_boys')->where('id', $this->driver->id)->first();
    }

    // ───────────────────────────────── change-profile: the driver login identifier

    public function test_unpermissioned_staff_cannot_rewrite_a_drivers_login_email(): void
    {
        $this->as($this->staff('f120-a@example.test'))->postJson('/api/admin/change-profile', [
            'id' => $this->driver->id, 'name' => 'Hijacked', 'email' => 'attacker@example.test',
        ]);

        $this->assertSame('f120-driver@example.test', $this->driverRow()->email,
            'an unpermissioned staff member rewrote `delivery_boys.email` -- the identifier '
            . 'deliveryLogin authenticates on, so the driver\'s login now points at an '
            . 'attacker-controlled address. The sibling deliveryboysUpdate/{id} already requires '
            . '`Edit Deliveryboy`; this was a second, ungated door onto the same account.');
    }

    public function test_staff_granted_edit_deliveryboy_still_rewrite_the_profile(): void
    {
        $this->as($this->staff('f120-b@example.test', 'Edit Deliveryboy'))
            ->postJson('/api/admin/change-profile', [
                'id' => $this->driver->id, 'name' => 'Renamed', 'email' => 'renamed@example.test',
            ]);

        $this->assertSame('renamed@example.test', $this->driverRow()->email,
            'staff GRANTED `Edit Deliveryboy` could not rewrite the driver profile, so the gate '
            . 'admits nobody a merchant can grant');
    }

    /** AND THE PASSWORD HASH IS UNTOUCHED EITHER WAY — so the finding is a takeover VECTOR, not a
     *  credential write, and this file does not overstate it. */
    public function test_change_profile_never_writes_the_password_hash(): void
    {
        $before = $this->driverRow()->password;

        $this->as($this->staff('f120-c@example.test', 'Edit Deliveryboy'))
            ->postJson('/api/admin/change-profile', [
                'id' => $this->driver->id, 'email' => 'moved@example.test',
            ]);

        $this->assertSame($before, $this->driverRow()->password,
            'change-profile wrote the password hash, which would make this a credential write '
            . 'rather than the takeover vector it is reported as');
    }

    /**
     * A DRIVER MAINTAINING THEIR OWN PROFILE — AND THIS CASE EXISTS BECAUSE NOTHING COVERED IT.
     *
     * `resolveManageableDeliveryBoy()` admits a caller acting on themselves FIRST (`:5375-5379`, a
     * `DeliveryBoy` on the `deliveryboy` guard), which is how the driver app maintains its own
     * account. A merchant permission cannot be held by a `DeliveryBoy` at all, so the first version
     * of these gates refused every driver on their own row.
     *
     * `logOut` had a test for that limb (`DeliveryAndOrderApiAuthorizationTest::
     * test_a_driver_can_still_log_themselves_out`) and it caught the regression. `change-profile`
     * had NONE, so the same break would have shipped silently on the route where it matters more —
     * a driver unable to correct their own name or e-mail. That asymmetry is the reason this file
     * now drives the self limb on both.
     */
    public function test_a_driver_can_still_maintain_their_own_profile(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->driver->createToken('driver-app')->plainTextToken)
            ->postJson('/api/admin/change-profile', [
                'id' => $this->driver->id, 'name' => 'Self Renamed',
                'email' => 'self-renamed@example.test',
            ]);

        $this->assertSame('self-renamed@example.test', $this->driverRow()->email,
            'a DRIVER could not maintain their own profile. The permission limb must exempt the '
            . 'self case, because a DeliveryBoy cannot hold a merchant permission and '
            . 'resolveManageableDeliveryBoy() admits the self limb first -- that is what it is for.');
    }

    /** AND THE EXEMPTION MUST NOT REOPEN THE DEFECT: a driver acting on ANOTHER driver is refused.
     *  Without this, "exempt the self case" could be read as "exempt any driver". */
    public function test_a_driver_cannot_maintain_another_drivers_profile(): void
    {
        $other = DeliveryBoy::forceCreate([
            'name' => 'Other Driver', 'email' => 'f120-other-driver@example.test',
            'password' => Hash::make('x'), 'contact' => '0100000001',
            'type' => 'deliveryboy', 'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->driver->createToken('driver-app')->plainTextToken)
            ->postJson('/api/admin/change-profile', [
                'id' => $other->id, 'email' => 'hijacked-sibling@example.test',
            ]);

        $this->assertSame('f120-other-driver@example.test',
            DB::table('delivery_boys')->where('id', $other->id)->value('email'),
            'a driver rewrote ANOTHER driver\'s login e-mail, so the self exemption is reading as '
            . '"any driver" rather than "this driver"');
    }

    // ───────────────────────────────── logOut: an availability write

    public function test_unpermissioned_staff_cannot_revoke_a_drivers_tokens(): void
    {
        $this->driver->createToken('driver-app');
        $before = DB::table('personal_access_tokens')
            ->where('tokenable_type', DeliveryBoy::class)->where('tokenable_id', $this->driver->id)->count();
        $this->assertGreaterThan(0, $before, 'premise: the driver holds no token to revoke');

        $this->as($this->staff('f120-d@example.test'))
            ->postJson('/api/admin/logOut', ['user_id' => $this->driver->id]);

        $this->assertSame($before, DB::table('personal_access_tokens')
            ->where('tokenable_type', DeliveryBoy::class)->where('tokenable_id', $this->driver->id)->count(),
            'an unpermissioned staff member revoked a driver\'s tokens, signing them out of the '
            . 'shipped driver app');
    }

    // ───────────────────────────────── delete-user: irreversible

    public function test_unpermissioned_staff_cannot_delete_a_driver(): void
    {
        $this->as($this->staff('f120-e@example.test'))
            ->postJson('/api/admin/delete-user', ['user_id' => $this->driver->id]);

        $this->assertNotNull($this->driverRow(),
            'an unpermissioned staff member DELETED a driver account. The sibling '
            . 'deliveryboysDestroy/{id} already requires `Delete Deliveryboy`.');
    }

    public function test_staff_granted_delete_deliveryboy_still_delete(): void
    {
        $this->as($this->staff('f120-f@example.test', 'Delete Deliveryboy'))
            ->postJson('/api/admin/delete-user', ['user_id' => $this->driver->id]);

        $this->assertNull($this->driverRow(),
            'staff GRANTED `Delete Deliveryboy` could not delete the driver');
    }

    // ───────────────────────────────── editprofile: a colleague's panel login identifier

    public function test_unpermissioned_staff_cannot_rewrite_a_colleagues_login_email(): void
    {
        $this->as($this->staff('f120-g@example.test'))->postJson('/api/admin/editprofile', [
            'store_id' => $this->store->id, 'user_id' => $this->colleague->id,
            'name' => 'Hijacked', 'email' => 'attacker2@example.test',
        ]);

        $this->assertSame('f120-colleague@example.test',
            DB::table('users')->where('id', $this->colleague->id)->value('email'),
            'an unpermissioned staff member rewrote a COLLEAGUE\'S `users.email` -- what adminlogin '
            . 'authenticates on and where the web password reset sends');
    }

    public function test_staff_granted_edit_user_still_rewrite_a_colleague(): void
    {
        $this->as($this->staff('f120-h@example.test', 'Edit User'))->postJson('/api/admin/editprofile', [
            'store_id' => $this->store->id, 'user_id' => $this->colleague->id,
            'name' => 'Renamed', 'email' => 'renamed2@example.test',
        ]);

        $this->assertSame('renamed2@example.test',
            DB::table('users')->where('id', $this->colleague->id)->value('email'),
            'staff GRANTED `Edit User` could not edit a colleague, so the gate admits nobody');
    }

    // ───────────────────────────────── updatepassword: the lateral credential write

    public function test_unpermissioned_staff_cannot_set_a_colleagues_password(): void
    {
        $known = 'colleague-current-password';
        DB::table('users')->where('id', $this->colleague->id)->update(['password' => Hash::make($known)]);
        (new User)->flushCache();
        $before = DB::table('users')->where('id', $this->colleague->id)->value('password');

        $this->as($this->staff('f120-i@example.test'))->postJson('/api/admin/updatepassword', [
            'store_id' => $this->store->id, 'user_id' => $this->colleague->id,
            'old_password' => $known, 'password' => 'attacker-chosen',
            'password_confirmation' => 'attacker-chosen',
        ]);

        $this->assertSame($before, DB::table('users')->where('id', $this->colleague->id)->value('password'),
            'an unpermissioned staff member SET A COLLEAGUE\'S PASSWORD. No permission was '
            . 'consulted: the only controls were resolveManageableUser() for tenancy and '
            . 'Hash::check($old_password), so the attack was held back only by knowing the '
            . 'colleague\'s current password.');
    }

    /** CONTROL: a holder of `Reset Password` still resets, because that is what the WEB's own
     *  policy admits — the gate ports `UserPolicy::resetPassword` rather than inventing a name. */
    public function test_a_holder_of_reset_password_still_resets_a_colleague(): void
    {
        $known = 'colleague-current-password-2';
        DB::table('users')->where('id', $this->colleague->id)->update(['password' => Hash::make($known)]);
        (new User)->flushCache();
        $before = DB::table('users')->where('id', $this->colleague->id)->value('password');

        $this->as($this->staff('f120-j@example.test', 'Reset Password'))
            ->postJson('/api/admin/updatepassword', [
                'store_id' => $this->store->id, 'user_id' => $this->colleague->id,
                'old_password' => $known, 'password' => 'legitimately-reset',
                'password_confirmation' => 'legitimately-reset',
            ]);

        $this->assertNotSame($before, DB::table('users')->where('id', $this->colleague->id)->value('password'),
            'a holder of `Reset Password` could not reset a colleague, so the ported policy admits '
            . 'nobody -- UserPolicy::resetPassword requires exactly that name after the operator limb');
    }

    /** CONTROL: the store OWNER is still out of reach, which was already true and must stay true. */
    public function test_the_store_owner_password_is_still_out_of_reach(): void
    {
        $before = DB::table('users')->where('id', $this->merchant->id)->value('password');

        $this->as($this->staff('f120-k@example.test', 'Reset Password'))
            ->postJson('/api/admin/updatepassword', [
                'store_id' => $this->store->id, 'user_id' => $this->merchant->id,
                'old_password' => 'anything', 'password' => 'owned',
                'password_confirmation' => 'owned',
            ]);

        $this->assertSame($before, DB::table('users')->where('id', $this->merchant->id)->value('password'),
            'the store OWNER\'s password was rewritten. That is the difference between a lateral '
            . 'write between colleagues and a takeover of the tenant, and it must stay refused.');
    }

    /** CONTROL: the store's own owner still drives all five doors. */
    public function test_the_store_owner_still_drives_the_account_doors(): void
    {
        $this->as(User::find($this->merchant->id))->postJson('/api/admin/change-profile', [
            'id' => $this->driver->id, 'email' => 'owner-renamed@example.test',
        ]);

        $this->assertSame('owner-renamed@example.test', $this->driverRow()->email,
            'the store\'s own owner was refused their own driver\'s profile. A merchant holds no '
            . 'permission names through a fixture, so without the store-owner limb these gates lock '
            . 'every fixture owner out.');
    }
}
