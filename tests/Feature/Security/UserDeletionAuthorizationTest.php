<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\UserDeletionSafety;

/**
 * P0-DELETE — SEC-F4's defect, still open on the one operation that destroys a tenant.
 * =================================================================================================
 *
 * `UserController::update()` was repaired by SEC-F4 because it "checked only the 'Edit User'
 * permission, which every merchant holds for their own tenant, and then operated on
 * `User::findOrFail($id)` with no ownership test at all -- so any merchant could edit any user on
 * the platform". `UserController::destroy()` has the SAME SHAPE and was not repaired:
 *
 *     public function destroy(User $user)
 *     {
 *         if (auth()->user() && auth()->user()->isAbleTo('Delete User')) {
 *             Setting::where('created_by', $user->id)->delete();
 *             $user->delete();
 *
 * The target is bound from the URL. `User` is NOT in `config/tenancy.php`'s `scoped_models` --
 * that list holds six models and `User` is not one of them -- so route-model binding reaches
 * every tenant's users and the global scope adds no predicate. The only gate is a PERMISSION, and
 * a permission is not an ownership test.
 *
 * AND DELETE IS NOT EDIT. `users` has no soft delete and no observer, so `$user->delete()` is a
 * hard DELETE, and the production schema turns it into a tenant wipe: `stores.created_by`
 * references `users.id` ON DELETE CASCADE (storesyria_ecommercego.sql:37441, declared in
 * database/migrations/..._create_stores_table.php:25), and twelve further foreign keys cascade
 * off `stores.id` -- including `store_wallets`, `store_wallet_transactions`, `store_withdrawals`
 * and `store_order_financials`. Of the 89 foreign keys in that schema exactly one is not
 * CASCADE, and none is RESTRICT, so the database will never refuse.
 *
 * There is a LIVE BUTTON for this: `resources/views/users/index.blade.php:124` opens a DELETE
 * form to `users.destroy`. This is not a crafted-request-only path.
 *
 * WHAT THIS FILE ASSERTS, and why each part is here rather than implied:
 *
 *   PREMISE   the cascade is real IN THIS DATABASE, and the victim has something to lose. Without
 *             both, a "nothing was destroyed" assertion passes against an empty blast radius --
 *             which is exactly why the existing `ProfileTest::test_user_can_delete_their_account`
 *             has been green for so long: `UserFactory` makes a childless user.
 *   REFUSAL   the cross-tenant delete is refused, and the row is still there afterwards.
 *   NO HALF   the store is still there too. A guard that refuses after the cascade has fired is
 *             not a guard.
 *   KEEP      the legitimate cases still work -- a merchant deleting their own staff, and the
 *             platform operator deleting anybody -- so the fix cannot be "refuse everything".
 */
class UserDeletionAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * THE PREMISE, asserted rather than assumed.
     *
     * SQLite in this suite DOES enforce foreign keys -- `config/database.php:39` sets
     * `foreign_key_constraints` to true by default and Laravel's SQLite connection issues the
     * pragma at construction -- so a cascade assertion here is not vacuous. But a cascade added
     * by `Schema::table()->foreign()` AFTER table creation is silently absent on SQLite, so the
     * only honest thing to do is read the live schema.
     */
    public function test_premise_the_store_cascade_is_real_in_this_database(): void
    {
        $this->assertSame(
            1,
            (int) DB::scalar('pragma foreign_keys'),
            'Foreign keys are OFF on this connection, so nothing in this file measures a cascade '
            . 'and every "nothing was destroyed" assertion below would pass for the wrong reason.'
        );

        $this->assertTrue(Schema::hasTable('stores'), 'no stores table to cascade from');

        $keys = collect(DB::select('pragma foreign_key_list(stores)'))
            ->filter(fn ($k) => $k->table === 'users');

        $this->assertNotEmpty(
            $keys,
            'stores declares no foreign key to users in THIS database, so deleting a user would '
            . 'not take their store here even though production says it does '
            . '(storesyria_ecommercego.sql:37441). The refusal assertions below would then be '
            . 'measuring nothing.'
        );

        $this->assertTrue(
            $keys->contains(fn ($k) => strtoupper((string) $k->on_delete) === 'CASCADE'),
            'the stores -> users foreign key exists but is not ON DELETE CASCADE here.'
        );
    }

    /**
     * THE FIXTURE HAS A TRAP IN IT, and the first version of this test fell in.
     *
     * `SecurityTestCase::makeMerchant()` hardcodes `created_by => 1` for every merchant, and the
     * FIRST merchant a test creates is itself id 1. So `makeTenant()` twice gives an attacker
     * with id 1 and a victim with `created_by = 1` -- and `UserPolicy::sameTenant()` then reads
     * them as ONE tenant, correctly, because by the data they are: the victim really was created
     * by the attacker.
     *
     * That is not production. There, both merchants are created by the platform admin, and
     * neither IS the platform admin, so `victim->created_by` (1) matches neither
     * `attacker->creatorId()` nor `attacker->id`. The filler below reproduces that: it consumes
     * id 1 so the attacker is a merchant like any other.
     *
     * Without it this test reported the guard as broken when it was working -- the exact shape
     * of a test that fails for the wrong reason, which is worse here than one that passes for
     * the wrong reason only because it would have sent me to rewrite a correct policy.
     */
    public function test_merchant_cannot_delete_a_user_in_another_tenant(): void
    {
        $this->makeMerchant(['email' => 'platform-filler@example.test']);

        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete User');

        [$victimOwner, $victimStore] = $this->makeTenant();

        $this->assertNotSame(
            (int) $attacker->id,
            (int) $victimOwner->created_by,
            'the victim was created BY the attacker, so they are one tenant and this test would '
            . 'be measuring the wrong thing'
        );

        // The blast radius must be non-empty, or "nothing was destroyed" proves nothing.
        $this->assertTrue(
            Store::withoutGlobalScopes()->whereKey($victimStore->id)->exists(),
            'the victim has no store, so this test would pass against an empty cascade'
        );

        $this->actingAs($attacker)->delete("/users/{$victimOwner->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($victimOwner->id)->exists(),
            'A merchant holding only the `Delete User` permission destroyed a user in ANOTHER '
            . 'tenant. `User` is not in config/tenancy.php scoped_models, so route-model binding '
            . 'reached them and nothing refused.'
        );

        $this->assertTrue(
            Store::withoutGlobalScopes()->whereKey($victimStore->id)->exists(),
            'The victim survived but their STORE did not, which means the cascade fired and the '
            . 'guard refused afterwards. stores.created_by is ON DELETE CASCADE, so a refusal '
            . 'has to happen before $user->delete(), not after.'
        );
    }

    /**
     * The same question in the shape SEC-F4's own cross-tenant edit test uses: a STAFF row
     * belonging to someone else. Immune to the id-1 fixture trap above, because a staff user's
     * `created_by` is their own owner's id.
     */
    public function test_merchant_cannot_delete_another_tenants_staff(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete User');

        [$victimOwner] = $this->makeTenant();
        $victimStaff = $this->makeStaff($victimOwner);

        $this->actingAs($attacker)->delete("/users/{$victimStaff->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($victimStaff->id)->exists(),
            'a merchant deleted a staff user belonging to another tenant'
        );
    }

    public function test_merchant_cannot_delete_a_platform_admin(): void
    {
        $this->makeMerchant(['email' => 'platform-filler2@example.test']);

        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete User');

        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->save();

        $this->actingAs($attacker)->delete("/users/{$operator->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($operator->id)->exists(),
            'a merchant deleted the platform operator'
        );
    }

    /**
     * THE SAME PROTECTION, DRIVEN SO THAT ONLY ONE LIMB CAN SAVE IT.
     *
     * Independent review found that `test_merchant_cannot_delete_a_platform_admin` above is
     * satisfied by TWO independent limbs: the attacker and the operator are in different tenants,
     * so `UserPolicy::sameTenant()` already returns false and the case still passes with
     * `self::isOperator($target)` (UserPolicy.php:187) deleted. That limb is not belt-and-braces
     * -- it is load-bearing, and nothing was pinning it.
     *
     * This case removes the other limb. The attacker is a staff row the OPERATOR created, so its
     * `created_by` is the operator's id; `User::creatorId()` returns `created_by` for any type
     * that is not `admin`/`super admin`, so the attacker's tenant root IS the operator, and
     * `sameTenant()`'s `$target->id === $actorTenant` limb returns TRUE. The only thing left
     * between a staff member holding `Delete User` and the deletion of the platform operator is
     * the target-is-operator check.
     *
     * THE PREMISE IS ASSERTED, because a test that proves a limb matters only proves it if the
     * other limb really is out of the way.
     */
    public function test_operator_created_staff_cannot_delete_the_operator(): void
    {
        $operator = $this->makeMerchant(['email' => 'operator-target@example.test']);
        $operator->type = 'super admin';
        $operator->save();

        $attacker = $this->makeStaff($operator, ['email' => 'operator-staff@example.test']);
        $this->grantPermission($attacker, 'Delete User');

        // THE PREMISE: the attacker's tenant root really is the operator, so the tenancy limb
        // cannot be what refuses this. If this ever stops holding, the case below is measuring
        // the same thing the sibling above already measures.
        $this->assertSame(
            (int) $operator->id,
            (int) $attacker->fresh()->creatorId(),
            'the attacker is no longer rooted at the operator, so `sameTenant()` would refuse '
            . 'this on its own and the case cannot isolate the target-is-operator limb'
        );

        $this->actingAs($attacker)->delete("/users/{$operator->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($operator->id)->exists(),
            'A staff member the operator created, holding `Delete User`, deleted the platform '
            . 'operator. `sameTenant()` admits them -- creatorId() returns `created_by` for a '
            . 'non-admin type -- so `UserPolicy::delete()`\'s target-is-operator check is the '
            . 'only thing that refuses this, and it is gone.'
        );
    }

    /**
     * THE LEGITIMATE CASE, so the repair cannot be "refuse everything". A merchant managing their
     * own staff is what the `Delete User` permission is for.
     */
    public function test_merchant_may_still_delete_their_own_staff(): void
    {
        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Delete User');

        $staff = $this->makeStaff($owner);

        $this->actingAs($owner)->delete("/users/{$staff->id}");

        $this->assertFalse(
            User::withoutGlobalScopes()->whereKey($staff->id)->exists(),
            'the merchant can no longer remove their own staff, so the guard is too wide'
        );
    }

    /**
     * And the operator keeps working, because `isAbleTo()` alone refuses the platform operator in
     * this codebase -- the operator-first ordering CLAUDE.md records.
     */
    public function test_the_platform_operator_may_still_delete_a_merchant_staff_user(): void
    {
        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->save();

        [$owner] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $this->actingAs($operator)->delete("/users/{$staff->id}");

        $this->assertFalse(
            User::withoutGlobalScopes()->whereKey($staff->id)->exists(),
            'the platform operator can no longer remove a user, which breaks support'
        );
    }

    // =============================================================================================
    // DATA SAFETY -- the owner-approved fail-closed half. These are not authorization tests:
    // every actor below is allowed to delete the target, and the refusal is about what the
    // deletion would destroy.
    // =============================================================================================

    /**
     * THE CASE THE OLD SUITE COULD NOT SEE. A store owner is the tenant root, and
     * `stores.created_by` is ON DELETE CASCADE, so deleting them takes the store and everything
     * hanging off it.
     */
    public function test_a_store_owner_cannot_be_hard_deleted_even_by_the_operator(): void
    {
        $operator = $this->makeMerchant(['email' => 'operator-store@example.test']);
        $operator->type = 'super admin';
        $operator->save();

        [$owner, $store] = $this->makeTenant();

        $this->actingAs($operator)->delete("/users/{$owner->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($owner->id)->exists(),
            'A store owner was hard-deleted. stores.created_by is ON DELETE CASCADE, so the '
            . 'store and its wallet, transactions, withdrawals and per-order financials went '
            . 'with them.'
        );

        $this->assertTrue(
            Store::withoutGlobalScopes()->whereKey($store->id)->exists(),
            'the store was destroyed by the cascade'
        );
    }

    /**
     * The guard is NOT an authorization control, so it must refuse the operator too. Asserted on
     * the service directly as well as through the route, because the route could refuse for the
     * wrong reason.
     */
    public function test_the_safety_service_names_store_ownership_as_a_blocker(): void
    {
        [$owner] = $this->makeTenant();

        $blockers = UserDeletionSafety::blockers($owner->fresh());

        $this->assertNotEmpty($blockers, 'a store owner produced no blockers');

        $this->assertTrue(
            collect($blockers)->contains(fn ($b) => str_contains($b, 'store')),
            'the blockers do not mention the store: ' . implode(' | ', $blockers)
        );
    }

    /**
     * FINANCIAL DEPENDENCIES. A user with a subscription order is not dependency-free even if
     * they own no store: `plan_orders.user_id` is ON DELETE CASCADE, so their billing history
     * would be destroyed.
     */
    public function test_a_user_with_a_subscription_record_cannot_be_hard_deleted(): void
    {
        $this->requireTable('plan_orders');

        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Delete User');

        $staff = $this->makeStaff($owner);

        DB::table('plan_orders')->insert([
            'order_id' => 'PO-SAFETY-' . $staff->id,
            'user_id' => $staff->id,
            'plan_id' => 1,
            'plan_name' => 'Growth',
            'price' => 100,
            'price_currency' => 'USD',
            'txn_id' => 'TXN-SAFETY',
            'payment_type' => 'Manually',
            'payment_status' => 'succeeded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)->delete("/users/{$staff->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($staff->id)->exists(),
            'a user carrying a subscription record was hard-deleted, destroying their billing '
            . 'history with them'
        );

        $this->assertSame(
            1,
            DB::table('plan_orders')->where('user_id', $staff->id)->count(),
            'the subscription row was destroyed by the cascade'
        );
    }

    /**
     * ORPHAN DEPENDENCIES. `orders` carries no foreign key to `users`, so an order created by the
     * deleted user SURVIVES pointing at a row that is gone -- the half a single-table restore
     * cannot repair.
     */
    public function test_a_user_who_created_orders_cannot_be_hard_deleted(): void
    {
        $this->requireTable('orders');

        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Delete User');

        $staff = $this->makeStaff($owner);

        $order = $this->makeOrder($store, ['created_by' => $staff->id]);

        $this->actingAs($owner)->delete("/users/{$staff->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($staff->id)->exists(),
            'a user who created orders was hard-deleted, orphaning them'
        );

        $this->assertSame(
            1,
            DB::table('orders')->where('id', $order->id)->count(),
            'the order itself should still exist either way -- it has no foreign key'
        );
    }

    /**
     * MISSING PERMISSION, with everything else in order. The authorization half must still refuse
     * a same-tenant delete when the actor holds no `Delete User`.
     */
    public function test_a_merchant_without_the_permission_cannot_delete_their_own_staff(): void
    {
        [$owner] = $this->makeTenant();
        // deliberately NOT granted 'Delete User'
        $staff = $this->makeStaff($owner);

        $this->actingAs($owner)->delete("/users/{$staff->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($staff->id)->exists(),
            'a merchant with no Delete User permission deleted a staff account'
        );
    }

    // =============================================================================================
    // SELF-DELETION THROUGH /profile -- the same safety question, the other door.
    // =============================================================================================

    public function test_a_store_owner_cannot_delete_themselves_through_profile(): void
    {
        [$owner, $store] = $this->makeTenant();
        $owner->forceFill(['password' => 'password'])->save();

        $this->actingAs($owner)->delete('/profile', ['password' => 'password']);

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($owner->id)->exists(),
            'a store owner deleted themselves through /profile, taking the tenant with them'
        );

        $this->assertTrue(
            Store::withoutGlobalScopes()->whereKey($store->id)->exists(),
            'the store was destroyed'
        );

        // AND THEY ARE STILL SIGNED IN. The logout used to run BEFORE the delete, so a refusal
        // would have signed the merchant out of an account that still exists.
        $this->assertAuthenticatedAs($owner->fresh());
    }

    public function test_a_dependency_free_user_can_still_delete_themselves_through_profile(): void
    {
        $user = $this->makeStaff($this->makeMerchant(['email' => 'owner-sd@example.test']));
        $user->forceFill(['password' => 'password'])->save();

        $this->actingAs($user)->delete('/profile', ['password' => 'password']);

        $this->assertFalse(
            User::withoutGlobalScopes()->whereKey($user->id)->exists(),
            'a genuinely dependency-free account could not delete itself, so the guard is too wide'
        );
    }

    /**
     * THE WHOLE POINT, asserted as one picture: after a REFUSED deletion, every class of record
     * the owner named is still there.
     */
    public function test_nothing_is_lost_when_a_deletion_is_refused(): void
    {
        $this->requireTable('orders');

        $operator = $this->makeMerchant(['email' => 'operator-intact@example.test']);
        $operator->type = 'super admin';
        $operator->save();

        [$owner, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $hasPlanOrders = Schema::hasTable('plan_orders');

        if ($hasPlanOrders) {
            DB::table('plan_orders')->insert([
                'order_id' => 'PO-INTACT',
                'user_id' => $owner->id,
                'plan_id' => 1,
                'plan_name' => 'Growth',
                'price' => 100,
                'price_currency' => 'USD',
                'txn_id' => 'TXN-INTACT',
                'payment_type' => 'Manually',
                'payment_status' => 'succeeded',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($operator)->delete("/users/{$owner->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($owner->id)->exists(),
            'the user was deleted'
        );
        $this->assertTrue(
            Store::withoutGlobalScopes()->whereKey($store->id)->exists(),
            'the STORE was destroyed by the cascade'
        );
        $this->assertSame(
            1,
            DB::table('orders')->where('id', $order->id)->count(),
            'the ORDER is gone'
        );

        if ($hasPlanOrders) {
            $this->assertSame(
                1,
                DB::table('plan_orders')->where('user_id', $owner->id)->count(),
                'the SUBSCRIPTION record was destroyed by the cascade'
            );
        }
    }

    /**
     * THE THIRD DOOR. `StoreController::destroy` (DELETE stores/{store}) deletes a STORE by
     * deleting its OWNER -- the unsafe pattern this whole repair is about. It is operator-only
     * and has NO view linking to it anywhere in the repository, so it is reachable only by a
     * crafted request; the merchant's own store removal is `ownerstore-remove`, which deletes the
     * STORE and preserves orders under decision D14.
     *
     * It now refuses, and because a store owner always owns a store it refuses ALWAYS. That is
     * the limitation, chosen on the owner's instruction to keep deletion disabled rather than
     * proceed with destructive behaviour.
     */
    public function test_the_store_route_cannot_delete_an_owner_either(): void
    {
        $operator = $this->makeMerchant(['email' => 'operator-third@example.test']);
        $operator->type = 'super admin';
        $operator->save();

        [$owner, $store] = $this->makeTenant();

        $this->actingAs($operator)->delete("/stores/{$owner->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($owner->id)->exists(),
            'StoreController::destroy hard-deleted a store owner, taking the wallet and ledgers '
            . 'with them'
        );

        $this->assertTrue(
            Store::withoutGlobalScopes()->whereKey($store->id)->exists(),
            'the store was destroyed by the cascade'
        );
    }
}
