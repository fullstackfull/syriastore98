<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-05-5 — `GET user-info/{id}` discloses another tenant's store and staff counts.
 *
 * `UserController::userInfo` carries **no authorization of any kind**: no permission check, no
 * ownership predicate, and no null guard. It resolves its target with
 * `User::find(decrypt($id))` and then calls `storeUserCounter()`, which reports that user's
 * total / active / disabled STORE counts and, per store, their total / active / disabled USER
 * counts.
 *
 * NOTHING ELSE STOPS IT, each checked rather than assumed:
 *   - the route (`routes/web.php:443`) sits in the group carrying only
 *     `['auth', 'xss', 'setlocate', 'verified']` — authenticated and verified is the whole bar;
 *   - `User` is not in `config/tenancy.php`'s `scoped_models` and registers no global scope, so
 *     `find()` reaches every tenant;
 *   - `Crypt::decrypt()` IS NOT A CONTROL. `APP_KEY` is in git history and unrotated, which this
 *     repository records in CLAUDE.md and in `StoreController::storeResetPassword`'s own comment
 *     (SEC-F1): an encrypted id in a URL is forgeable for any user id. This test encrypts the
 *     victim's id directly, which is precisely what an attacker holding the key can do.
 *
 * It also contains the fallback-to-request-input shape this codebase has paid for repeatedly:
 * `if (empty(decrypt($id))) { $id = $request->id; }`.
 */
class UserInfoDisclosureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function ownerWithStore(string $email, string $slug): \App\Models\User
    {
        $owner = $this->makeMerchant(['email' => $email]);

        $store = Store::create([
            'name' => $slug, 'email' => $slug . '@example.test', 'slug' => $slug,
            'theme_id' => 'stylique', 'created_by' => $owner->id, 'is_active' => 1,
            'default_language' => 'en',
        ]);

        $owner->current_store = $store->id;
        $owner->save();

        return $owner;
    }

    public function test_a_merchant_cannot_read_another_tenants_user_info(): void
    {
        $victim = $this->ownerWithStore('victim@example.test', 'victim-store');
        $attacker = $this->ownerWithStore('attacker@example.test', 'attacker-store');

        $response = $this->actingAs($attacker)
            ->get('/user-info/' . \Crypt::encrypt($victim->id));

        $this->assertTrue(
            in_array($response->status(), [302, 403], true),
            'GET user-info/{id} answered ' . $response->status() . ' for another tenant\'s owner. '
            . 'The handler has no permission check and no ownership predicate, User is not a '
            . 'scoped model, and Crypt::decrypt() is not a control because APP_KEY is in git '
            . 'history — so this reports the victim\'s store and staff counts to any '
            . 'authenticated user who can mint an encrypted id.');
    }

    /** The positive control: a merchant must still be able to read their OWN. */
    public function test_a_merchant_can_still_read_their_own_user_info(): void
    {
        $owner = $this->ownerWithStore('self@example.test', 'self-store');

        $response = $this->actingAs($owner)->get('/user-info/' . \Crypt::encrypt($owner->id));

        /*
         * `assertOk()`, NOT `assertNotEquals(403)`. The first version of this test asserted the
         * latter AND COULD NOT FAIL: this handler has no 403 path at all -- every refusal is
         * `redirect()->back()`, a 302 -- so it passed on a denial, on a 500, and against the
         * unfixed code. A vacuous positive control is worse than none, because it makes the
         * negative tests beside it look balanced. Found by the independent review of this change.
         */
        $response->assertOk();
    }

    /**
     * A VALID-BUT-UNKNOWN id must not 500. `$user = User::find(...)` was followed immediately by
     * `$user->is_active`, so a deleted or never-existing id raised "property on null".
     */
    public function test_an_unknown_id_is_refused_rather_than_crashing(): void
    {
        $attacker = $this->ownerWithStore('prober@example.test', 'prober-store');

        $response = $this->actingAs($attacker)->get('/user-info/' . \Crypt::encrypt(999999));

        $this->assertNotEquals(500, $response->status(),
            'an id naming no user reached `$user->is_active` unguarded');
    }

    /**
     * THE POSITIVE CONTROL THAT MATTERS MOST: this is the PLATFORM OPERATOR'S screen, reached
     * from "Admin Info" on their store list, so the operator must still see any tenant owner.
     *
     * It is also the half a naive guard gets wrong. `isAbleTo()` alone REFUSES the operator in
     * this codebase, so an ownership fix written as a permission check would have locked the
     * operator out of their own screen while the tests about cross-tenant access all passed.
     */
    public function test_the_platform_operator_can_still_read_any_tenants_user_info(): void
    {
        $victim = $this->ownerWithStore('tenant@example.test', 'tenant-store');
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator)
            ->get('/user-info/' . \Crypt::encrypt($victim->id));

        /*
         * The status alone would pass on a 500 and on a handler that resolved the WRONG target
         * (showing the operator their own figures). So the victim's store NAME is asserted:
         * `user_info.blade.php` renders it, and only the authorized target's stores reach it.
         */
        $response->assertOk();
        $response->assertSee('tenant-store', false);
    }

    /** A malformed id must refuse rather than raise a decrypt exception. */
    public function test_a_malformed_id_is_refused(): void
    {
        $owner = $this->ownerWithStore('malformed@example.test', 'malformed-store');

        $response = $this->actingAs($owner)->get('/user-info/not-an-encrypted-payload');

        $this->assertNotEquals(500, $response->status());
    }

    /**
     * THE RAW-ID SHAPE, which is the new input surface this change admits and which nothing
     * pinned before. `store/index.blade.php:159` passes `$user->id` unencrypted, so
     * `Crypt::decrypt()` throws and the numeric fallback resolves it. That caller was a 500
     * before this change; the positive case below is therefore a repair, not just a guard.
     */
    public function test_the_operator_can_use_the_raw_id_shape_the_store_list_sends(): void
    {
        $victim = $this->ownerWithStore('rawtarget@example.test', 'raw-store');
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator)->get('/user-info/' . $victim->id);

        $response->assertOk();
        $response->assertSee('raw-store', false);
    }

    /** ...and the raw shape must be refused for a foreign target just as the encrypted one is. */
    public function test_a_merchant_cannot_use_the_raw_id_shape_to_read_another_tenant(): void
    {
        $victim = $this->ownerWithStore('rawvictim@example.test', 'rawvictim-store');
        $attacker = $this->ownerWithStore('rawattacker@example.test', 'rawattacker-store');

        $response = $this->actingAs($attacker)->get('/user-info/' . $victim->id);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringNotContainsString('rawvictim-store', (string) $response->getContent());
    }

    /**
     * THE NARROWING IS ASSERTED, not just the widening. The guard is operator-or-self and
     * deliberately carries NO same-tenant limb: an earlier version admitted anyone whose
     * `creatorId()` matched, which let a zero-permission staff member read their own tenant's
     * store counts and, through the view, every colleague's name and login state — while the
     * sibling screens gate that roster on `Manage User`. Both callers of this route are
     * operator-only, so the limb bought nothing.
     */
    public function test_a_staff_member_cannot_read_their_own_tenant_owners_user_info(): void
    {
        $owner = $this->ownerWithStore('staffowner@example.test', 'staffowner-store');
        $staff = $this->makeStaff($owner);

        $response = $this->actingAs($staff)->get('/user-info/' . \Crypt::encrypt($owner->id));

        $response->assertRedirect();
        $this->assertStringNotContainsString('staffowner-store', (string) $response->getContent());
    }

    /** Premise: the route exists, so a 404 cannot be mistaken for a refusal above. */
    public function test_the_route_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('user.info'),
            'every denial assertion in this file would be satisfied by a missing route');
    }

    /**
     * THE VIEW RECEIVES THE RESOLVED ID, NOT THE ROUTE PARAMETER — pinned through what the
     * merchant actually sees.
     *
     * `users/user_info.blade.php:59` runs `User::where('created_by', $id)` as a tenant
     * predicate. The handler used to hand it `compact('id')`, i.e. the RAW parameter, which from
     * the operator's primary button is ciphertext. `users.created_by` is an unsignedBigInteger,
     * so on MySQL that ciphertext casts to **0** and the modal lists whatever rows carry
     * `created_by = 0`, from any tenant.
     *
     * THAT CROSS-TENANT HALF CANNOT BE DRIVEN HERE — on SQLite the same comparison matches
     * nothing, which is exactly CLAUDE.md's warning that SQLite's type affinity hides real MySQL
     * defects. What CAN be driven, and is, is the functional half that moves in lockstep with
     * it: with the raw parameter the modal found NO colleagues and printed "User not found" in
     * every store tab; with the resolved id it lists them. So this test fails if the parameter
     * ever goes back, on either engine, even though it can only demonstrate one of the two
     * consequences.
     */
    public function test_the_modal_lists_the_targets_own_staff(): void
    {
        $owner = $this->ownerWithStore('listowner@example.test', 'list-store');

        $colleague = $this->makeStaff($owner, ['name' => 'Nadia Barakat']);
        $colleague->current_store = $owner->current_store;
        $colleague->save();

        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator)->get('/user-info/' . \Crypt::encrypt($owner->id));

        $response->assertOk();
        $response->assertSee('Nadia Barakat', false);
    }
}
