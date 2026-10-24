<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Services\SocialIdentity\SocialIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SocialIdentity\FakeOidcProvider;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11, AUTHZ-1 — POST api/{slug}/register, the only UNAUTHENTICATED row in the package.
 *
 * routes/api.php:46 carries ['APILog'] alone, and APILog is `return $next($request)`
 * (app/Http/Middleware/APILog.php:26-29), so there is no principal to confuse here: the caller is
 * the open internet.
 *
 * THE DEFECT. `register_type == 'email'` applies 'unique:customers,email', so that path can only
 * create. Every OTHER value of `register_type` skips that rule and takes a lookup branch:
 *
 *     $user = Customer::where('email', $request->email)->first();   // no store predicate
 *     ...
 *     $user->first_name = $request->first_name ?? null;             // overwrites the found row
 *     $user->email      = $request->email ?? null;
 *     $user->mobile     = $request->mobile ?? null;
 *     $user->created_by = $store->created_by;                       // RE-PARENTS the account
 *     $user->store_id   = $store->id;
 *     $user->save();
 *     auth('customers')->login($user);
 *     $token = $user->createToken('auth_token')->plainTextToken;    // and hands over a token
 *
 * So anyone who knows a customer's email address posts `register_type=google` with any
 * `google_id`, and receives a working Bearer token for that account — in ANY store, because the
 * lookup has no tenant predicate, and the victim is moved into the attacker's chosen store as a
 * side effect. The victim's password is never touched, so nothing tells them it happened.
 *
 * RESIDUAL, STATED BECAUSE THIS FIX DOES NOT CLOSE IT. This endpoint verifies no provider token —
 * `google_id` is just a posted string. Matching on the provider id instead of the email means an
 * attacker must now know the victim's provider id rather than their email address, and can never
 * adopt a password account at all, but a known provider id is still sufficient. Closing that needs
 * real Google/Apple/Facebook token verification, which is a new outbound dependency and not this
 * package's to add. Tracked rather than claimed.
 */
class StorefrontRegistrationTakeoverTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function victimIn($store, string $email, array $attributes = []): Customer
    {
        return Customer::forceCreate(array_merge([
            'first_name' => 'Real', 'last_name' => 'Shopper',
            'email' => $email,
            'mobile' => '555000111',
            'password' => bcrypt('the-victims-own-password'),
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'status' => 1,
            'register_type' => 'email',
        ], $attributes));
    }

    /**
     * Lookups bypass the tenant scope: `Customer` carries StoreScope and SoftDeletingScope, so a
     * plain query answers against whichever tenant happens to be resolved rather than against the
     * table. The cross-store tests here would otherwise be measuring the scope.
     */
    private static function customers()
    {
        return Customer::withoutGlobalScopes();
    }

    /**
     * P01-SEC-14 UPDATED THE CONTRACT THIS FILE WAS WRITTEN AGAINST.
     *
     * When these tests were written, a social registration was performed by posting a bare
     * provider id, because that is what the handler accepted -- and that acceptance was the residual
     * this file recorded as NOT closed. Work Package A closed it: a social sign-in must now present
     * a provider credential the server verifies.
     *
     * So the FIXTURES below changed and the ASSERTIONS did not. Every security assertion in this
     * file still stands unaltered; what changed is how a legitimate social registration is
     * performed, which is the contract change the fix deliberately made. The new behaviour has its
     * own file, RegistrationProviderVerificationTest; these helpers only keep this file's existing
     * assertions exercising the handler rather than bouncing off the new gate.
     */
    private const VERIFIED_AUDIENCE = 'legacy-suite-google-client-id';

    private function configureGoogle($store): FakeOidcProvider
    {
        DB::table('settings')->insert([
            'name' => 'Google_client_id', 'value' => self::VERIFIED_AUDIENCE,
            'created_by' => $store->created_by, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $provider = new FakeOidcProvider();
        Http::fake([
            SocialIdentityVerifier::GOOGLE_JWKS_URL => Http::response($provider->jwks(), 200),
        ]);

        return $provider;
    }

    /** A google_token whose verified subject is $subject. */
    private function googleToken(FakeOidcProvider $provider, string $subject): string
    {
        return $provider->token(['sub' => $subject, 'aud' => self::VERIFIED_AUDIENCE]);
    }

    private function register($store, array $payload)
    {
        return $this->postJson('/api/' . $store->slug . '/register', $payload);
    }

    // ===================================================================================
    // THE TAKEOVER
    // ===================================================================================

    public function test_a_social_registration_cannot_adopt_an_existing_password_account(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->victimIn($store, 'victim-a1@example.test');

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_id' => 'attacker-supplied-google-id',
            'email' => 'victim-a1@example.test',
            'first_name' => 'Attacker',
        ]);

        $victim->refresh();

        $this->assertSame('Real', $victim->first_name,
            'An anonymous caller overwrote the identity of an existing account.');
        $this->assertNull($victim->google_id,
            "An anonymous caller attached their own google_id to the victim's account.");

        $token = $response->json('data.token');
        if ($token !== null) {
            $owner = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            $this->assertNotSame(
                (int) $victim->id,
                (int) ($owner->tokenable_id ?? 0),
                'The response handed the caller a working token for the VICTIM\'s account.'
            );
        }
    }

    public function test_a_social_registration_cannot_adopt_an_account_in_another_store(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->victimIn($storeA, 'victim-a2@example.test', [
            'register_type' => 'google',
            'google_id' => 'the-victims-real-google-id',
        ]);

        $this->register($storeB, [
            'register_type' => 'google',
            'google_id' => 'the-victims-real-google-id',
            'email' => 'victim-a2@example.test',
            'first_name' => 'Attacker',
        ]);

        $victim->refresh();

        $this->assertSame((int) $storeA->id, (int) $victim->store_id,
            "Registering at store B re-parented store A's customer into store B.");
        $this->assertSame((int) $storeA->created_by, (int) $victim->created_by,
            "Registering at store B re-assigned store A's customer to store B's owner.");
        $this->assertSame('Real', $victim->first_name,
            "A caller at store B overwrote the identity of store A's customer.");
    }

    public function test_an_unknown_register_type_cannot_adopt_an_account(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->victimIn($store, 'victim-a3@example.test');

        // Not one of the three handled providers. The lookup branch is entered for ANY
        // register_type that is not 'email', so this must not resolve to the victim either.
        $this->register($store, [
            'register_type' => 'not-a-real-provider',
            'email' => 'victim-a3@example.test',
            'first_name' => 'Attacker',
        ]);

        $victim->refresh();

        $this->assertSame('Real', $victim->first_name,
            'An unrecognised register_type still reached the victim row.');
    }

    public function test_a_facebook_registration_cannot_adopt_an_account_by_email(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->victimIn($store, 'victim-a4@example.test');

        $this->register($store, [
            'register_type' => 'facebook',
            'facebook_id' => 'attacker-supplied-facebook-id',
            'email' => 'victim-a4@example.test',
            'mobile' => '555000111',
            'first_name' => 'Attacker',
        ]);

        $victim->refresh();

        $this->assertSame('Real', $victim->first_name,
            'A facebook registration overwrote an existing account found by email.');
        $this->assertNull($victim->facebook_id,
            "A facebook registration attached its own id to the victim's account.");
    }

    // ===================================================================================
    // AND THE LEGITIMATE JOURNEYS STILL WORK
    // ===================================================================================

    public function test_a_new_email_registration_still_works(): void
    {
        [, $store] = $this->makeTenant();

        $response = $this->register($store, [
            'register_type' => 'email',
            'email' => 'fresh-b1@example.test',
            'password' => 'a-real-password',
            'mobile' => '555999000',
            'first_name' => 'New',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 1);

        $created = self::customers()->where('email', 'fresh-b1@example.test')->first();
        $this->assertNotNull($created, 'Email registration no longer creates an account.');
        $this->assertSame((int) $store->id, (int) $created->store_id,
            'A new account was not placed in the store it registered at.');
        $this->assertNotNull($response->json('data.token'),
            'Email registration no longer returns a token, so the client cannot proceed.');
    }

    public function test_a_first_time_social_registration_still_creates_an_account(): void
    {
        [, $store] = $this->makeTenant();
        $provider = $this->configureGoogle($store);

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $this->googleToken($provider, 'brand-new-google-id'),
            'email' => 'fresh-b2@example.test',
            'first_name' => 'New',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 1);

        $created = self::customers()->where('google_id', 'brand-new-google-id')->first();
        $this->assertNotNull($created, 'First-time social registration no longer creates an account.');
        $this->assertSame((int) $store->id, (int) $created->store_id);
        $this->assertNotNull($response->json('data.token'));
    }

    /**
     * The journey the lookup branch exists for: the same person signing in again. It must resolve
     * to their existing row rather than creating a duplicate, or their orders and addresses are
     * orphaned on every sign-in.
     */
    public function test_a_returning_social_user_resolves_to_their_own_existing_account(): void
    {
        [, $store] = $this->makeTenant();
        $provider = $this->configureGoogle($store);

        $first = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $this->googleToken($provider, 'returning-google-id'),
            'email' => 'returning-b3@example.test',
            'first_name' => 'Returning',
        ]);
        $first->assertJsonPath('status', 1);
        $firstId = (int) self::customers()->where('google_id', 'returning-google-id')->value('id');

        $second = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $this->googleToken($provider, 'returning-google-id'),
            'email' => 'returning-b3@example.test',
            'first_name' => 'Returning',
        ]);
        $second->assertJsonPath('status', 1);

        $this->assertSame(1, self::customers()->where('google_id', 'returning-google-id')->count(),
            'Signing in again created a duplicate account, orphaning the first one.');
        $this->assertSame($firstId, (int) self::customers()->where('google_id', 'returning-google-id')->value('id'),
            'The returning user resolved to a different row than the one they created.');
    }

    public function test_a_returning_social_user_is_not_adopted_across_stores(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        // Both stores configure the SAME audience and are served the SAME provider keys, so the
        // identical token verifies in both. That is the strongest form of this test: the two
        // accounts stay separate because of the store predicate on the lookup, not because the
        // credential failed to verify in the second store.
        $provider = $this->configureGoogle($storeA);
        DB::table('settings')->insert([
            'name' => 'Google_client_id', 'value' => self::VERIFIED_AUDIENCE,
            'created_by' => $storeB->created_by, 'store_id' => $storeB->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = $this->googleToken($provider, 'multi-store-google-id');

        $this->register($storeA, [
            'register_type' => 'google', 'google_token' => $token,
            'email' => 'multi-b4@example.test', 'first_name' => 'Shopper',
        ])->assertJsonPath('status', 1);

        // The same person shopping at a different store gets their own row there. That is what a
        // per-store `customers` table means; the alternative is store B silently inheriting
        // store A's account.
        $this->register($storeB, [
            'register_type' => 'google', 'google_token' => $token,
            'email' => 'multi-b4@example.test', 'first_name' => 'Shopper',
        ])->assertJsonPath('status', 1);

        $inA = self::customers()->where('google_id', 'multi-store-google-id')->where('store_id', $storeA->id)->count();
        $inB = self::customers()->where('google_id', 'multi-store-google-id')->where('store_id', $storeB->id)->count();

        $this->assertSame(1, $inA, 'The store A account was moved or duplicated.');
        $this->assertSame(1, $inB, 'Store B did not get its own account for this shopper.');
    }

    /**
     * Isolates the "an existing account's email, tenant and owner are its own" guard.
     *
     * A provider-id match is not proof of anything strong -- see the residual in the class
     * docblock -- so the blast radius of one has to stay bounded. Without the guard, a caller who
     * supplies a matching provider id and a DIFFERENT email rewrites the account's address, and
     * password recovery then delivers to them permanently. With it, the worst a provider-id match
     * does is sign in. It also means a provider that starts reporting a new address cannot
     * silently rewrite a real shopper's account; that is a profile update, not a registration.
     */
    public function test_a_provider_id_match_cannot_rewrite_the_accounts_email(): void
    {
        [, $store] = $this->makeTenant();
        $provider = $this->configureGoogle($store);
        $token = $this->googleToken($provider, 'email-rewrite-google-id');

        $this->register($store, [
            'register_type' => 'google', 'google_token' => $token,
            'email' => 'real-owner@example.test', 'first_name' => 'Real',
        ])->assertJsonPath('status', 1);

        $this->register($store, [
            'register_type' => 'google', 'google_token' => $token,
            'email' => 'attacker@evil.test', 'first_name' => 'Real',
        ]);

        $account = self::customers()->where('google_id', 'email-rewrite-google-id')->first();
        $this->assertNotNull($account);
        $this->assertSame('real-owner@example.test', $account->email,
            "A registration rewrote an existing account's email address, so password recovery "
            . 'now delivers to the caller.');
    }

    /**
     * A SOFT-DELETED account must stay deleted. Found by the adversarial review of this fix, not
     * by the fix's own tests.
     *
     * The lookup originally used `Customer::withoutGlobalScopes()`, whose plural form also strips
     * SoftDeletingScope. So a trashed customer could be matched by provider id, have its fields
     * overwritten, and be logged in with a Bearer token returned -- while `deleted_at` was never
     * cleared. That token is dead on arrival, because `custom.auth` resolves the principal through
     * `$accessToken->tokenable`, which DOES apply the soft-delete scope and yields null. So the
     * caller received "Register successfully." plus a token that 401s on every later request, and
     * a partially overwritten trashed row was left behind.
     *
     * Only the tenant scope is dropped now.
     */
    public function test_a_soft_deleted_account_is_not_resurrected_by_a_social_registration(): void
    {
        [, $store] = $this->makeTenant();

        $provider = $this->configureGoogle($store);
        $token = $this->googleToken($provider, 'trashed-google-id');

        $this->register($store, [
            'register_type' => 'google', 'google_token' => $token,
            'email' => 'trashed@example.test', 'first_name' => 'Original',
        ])->assertJsonPath('status', 1);

        $account = self::customers()->where('google_id', 'trashed-google-id')->first();
        $this->assertNotNull($account);
        $account->delete();
        $this->assertNotNull($account->fresh()->deleted_at, 'the fixture did not soft-delete');

        $response = $this->register($store, [
            'register_type' => 'google', 'google_token' => $token,
            'email' => 'trashed@example.test', 'first_name' => 'Attacker',
        ]);

        $reread = self::customers()->withTrashed()->find($account->id);
        $this->assertNotNull($reread->deleted_at,
            'A social registration resurrected a soft-deleted account.');
        $this->assertSame('Original', $reread->first_name,
            "A soft-deleted account's identity was overwritten.");

        // And no usable token for it.
        $token = $response->json('data.token');
        if ($token !== null) {
            $owner = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            $this->assertNotSame((int) $account->id, (int) ($owner->tokenable_id ?? 0),
                'A token was issued for a soft-deleted account.');
        }
    }

    // ===================================================================================
    // THE ID-SPACE CONFLATION IN THE RESPONSE
    // ===================================================================================

    /**
     * The handler ended with `$user_data = User::find($user->id)` — the ADMIN model, keyed by the
     * CUSTOMER's id — and returned `$user_data->profile_image`. Those are independent sequences,
     * so the image path returned to a shopper was whichever MERCHANT happened to share their
     * integer.
     */
    public function test_the_response_image_does_not_come_from_the_users_table(): void
    {
        [, $store] = $this->makeTenant();
        $provider = $this->configureGoogle($store);
        $token = $this->googleToken($provider, 'image-c1-google-id');

        // Step 1: create the account through the endpoint, so the id is whatever the sequence gave.
        $this->register($store, [
            'register_type' => 'google',
            'google_token' => $token,
            'email' => 'fresh-c1@example.test',
            'first_name' => 'New',
        ])->assertJsonPath('status', 1);

        $created = self::customers()->where('google_id', 'image-c1-google-id')->first();
        $this->assertNotNull($created, 'the fixture did not register');

        // Step 2: force the collision rather than hoping the two sequences line up, so this
        // measures the defect instead of depending on fixture luck.
        $collidingId = (int) $created->id;
        if (\App\Models\User::find($collidingId) === null) {
            \Illuminate\Support\Facades\DB::table('users')->insert([
                'id' => $collidingId,
                'name' => 'Colliding Merchant',
                'email' => 'collide-c1-' . uniqid() . '@example.test',
                'password' => bcrypt('x'),
                'type' => 'admin',
                'profile_image' => 'uploads/merchant-private-avatar.png',
                'lang' => 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            \Illuminate\Support\Facades\DB::table('users')->where('id', $collidingId)
                ->update(['profile_image' => 'uploads/merchant-private-avatar.png']);
        }

        // App\Models\User uses GeneaLabs\LaravelModelCaching\Traits\Cachable (User.php:16,21),
        // so a raw-query write leaves Eloquent reads serving the pre-write value -- and with
        // CACHE_STORE=array (phpunit.xml) that stale entry lives for the rest of the test. Without
        // this flush the precondition below reads null, the handler's own User::find() reads null
        // too, and the test passes against the UNFIXED handler for a reason that has nothing to do
        // with the defect.
        (new \App\Models\User())->flushCache();

        $this->assertSame(
            'uploads/merchant-private-avatar.png',
            \App\Models\User::find($collidingId)->profile_image,
            'Could not construct the id collision, so this test would prove nothing.'
        );

        // Step 3: the returning-user path runs the same response tail.
        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $token,
            'email' => 'fresh-c1@example.test',
            'first_name' => 'New',
        ]);

        $this->assertNotSame(
            'uploads/merchant-private-avatar.png',
            $response->json('data.image'),
            "A shopper's registration response returned a MERCHANT's profile image path, because "
            . 'the lookup used User::find() on a customers.id.'
        );
    }
}
