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

        $this->assertNotEquals(403, $response->status(),
            'the guard must not lock a merchant out of their own user-info screen');
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

        $this->assertNotEquals(403, $response->status());
        $this->assertNotEquals(302, $response->status(),
            'the operator was redirected away from their own Admin Info screen. An ownership '
            . 'guard written as a bare isAbleTo() does exactly this, because Laratrust refuses '
            . 'the operator — which is why the check is operator-first.');
    }

    /** A malformed id must refuse rather than raise a decrypt exception. */
    public function test_a_malformed_id_is_refused(): void
    {
        $owner = $this->ownerWithStore('malformed@example.test', 'malformed-store');

        $response = $this->actingAs($owner)->get('/user-info/not-an-encrypted-payload');

        $this->assertNotEquals(500, $response->status());
    }
}
