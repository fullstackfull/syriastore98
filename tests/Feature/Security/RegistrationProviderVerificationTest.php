<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Scopes\StoreScope;
use App\Services\SocialIdentity\SocialIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\SocialIdentity\FakeOidcProvider;

/**
 * P01-SEC-14 Work Package A — a social sign-in must be proved, not asserted.
 *
 * THE DEFECT. api/{slug}/register is the only unauthenticated route in this API (routes/api.php
 * carries ['APILog'] alone, and APILog is `return $next($request)`). It matched an existing account
 * on a provider id read straight from the request body. P01-SEC-11 scoped that lookup to the store
 * and stopped it adopting a password account, which narrowed the attack from "know a victim's email
 * address" to "know a victim's provider id" -- and recorded the rest as explicitly NOT closed,
 * because closing it needs real provider verification. This file closes it.
 *
 * WHY THE TESTS GENERATE REAL KEYS. Stubbing the verifier would leave the signature, issuer,
 * audience and expiry checks unexecuted, so every "refused" assertion would pass against a verifier
 * that checked nothing. Tests\Support\SocialIdentity\FakeOidcProvider generates an RSA keypair,
 * publishes a real JWKS for it and signs real RS256 tokens, so the production verifier runs its
 * actual code. Only the HTTP hop is faked, through the `Http` facade. Nothing leaves the process.
 *
 * THE AUDIENCE TESTS ARE THE IMPORTANT ONES. A signature proves a provider issued a token to
 * SOMEBODY; any other app using the same provider can get a validly signed token for the same
 * person. Without the audience check, replaying such a token here would authenticate that person
 * without their ever having used this storefront -- so
 * test_a_token_minted_for_another_application_is_refused is the test that distinguishes a control
 * from a decoration.
 */
class RegistrationProviderVerificationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const AUDIENCE = 'store-google-client-id.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        // Any outbound call this suite does not explicitly fake is a test bug, not a pass.
        Http::preventStrayRequests();
    }

    private function setting($store, string $name, string $value): void
    {
        DB::table('settings')->insert([
            'name' => $name, 'value' => $value, 'created_by' => $store->created_by,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A store with Google configured, plus the fake provider whose keys it will be served. */
    private function googleStore(): array
    {
        [, $store] = $this->makeTenant();
        $this->setting($store, 'Google_client_id', self::AUDIENCE);

        $provider = new FakeOidcProvider();
        Http::fake([
            SocialIdentityVerifier::GOOGLE_JWKS_URL => Http::response($provider->jwks(), 200),
        ]);

        return [$store, $provider];
    }

    private function register($store, array $payload)
    {
        return $this->postJson('/api/' . $store->slug . '/register', $payload);
    }

    private static function customers()
    {
        return Customer::withoutGlobalScope(StoreScope::class);
    }

    // ===================================================================================
    // THE PREMISE — if a verified sign-in does not work, every refusal below is vacuous
    // ===================================================================================

    public function test_a_verified_google_sign_in_creates_an_account(): void
    {
        [$store, $provider] = $this->googleStore();

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token(['sub' => 'google-sub-aaa', 'aud' => self::AUDIENCE]),
            'first_name' => 'Verified',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 1);

        $created = self::customers()->where('google_id', 'google-sub-aaa')->first();
        $this->assertNotNull($created,
            'A verified google sign-in no longer creates an account, so every refusal in this file '
            . 'would pass against a handler that refuses everyone.');
        $this->assertSame((int) $store->id, (int) $created->store_id);
        $this->assertNotNull($response->json('data.token'),
            'No bearer token was returned, so the client cannot proceed.');
    }

    public function test_a_returning_verified_user_resolves_to_their_own_account(): void
    {
        [$store, $provider] = $this->googleStore();
        $payload = [
            'register_type' => 'google',
            'google_token' => $provider->token(['sub' => 'google-sub-returning', 'aud' => self::AUDIENCE]),
            'first_name' => 'Returning',
        ];

        $this->register($store, $payload)->assertJsonPath('status', 1);
        $firstId = (int) self::customers()->where('google_id', 'google-sub-returning')->value('id');

        // A second, independently signed token for the same subject.
        $payload['google_token'] = $provider->token([
            'sub' => 'google-sub-returning', 'aud' => self::AUDIENCE,
        ]);
        $this->register($store, $payload)->assertJsonPath('status', 1);

        $this->assertSame(1, self::customers()->where('google_id', 'google-sub-returning')->count(),
            'Signing in again created a duplicate account, orphaning the first.');
        $this->assertSame($firstId,
            (int) self::customers()->where('google_id', 'google-sub-returning')->value('id'));
    }

    public function test_email_registration_is_untouched(): void
    {
        [, $store] = $this->makeTenant();

        // No Http::fake is installed for this test, and preventStrayRequests is on: the email path
        // must not reach a provider at all.
        $this->register($store, [
            'register_type' => 'email',
            'email' => 'email-path@example.test',
            'password' => 'a-real-password',
            'mobile' => '555000111',
            'first_name' => 'Email',
        ])->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertNotNull(self::customers()->where('email', 'email-path@example.test')->first(),
            'Email registration broke, which Work Package A was required to preserve.');
    }

    // ===================================================================================
    // THE DEFECT ITSELF
    // ===================================================================================

    /**
     * The exact attack P01-SEC-11 left open: the attacker knows the victim's provider subject and
     * presents it with no credential.
     */
    public function test_knowing_a_victims_provider_id_is_no_longer_enough(): void
    {
        [$store, $provider] = $this->googleStore();

        // The victim registers legitimately.
        $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token(['sub' => 'victim-sub', 'aud' => self::AUDIENCE]),
            'first_name' => 'Victim',
            'mobile' => '555000222',
        ])->assertJsonPath('status', 1);

        $victim = self::customers()->where('google_id', 'victim-sub')->first();
        $this->assertNotNull($victim, 'the victim fixture did not register');

        // The attacker presents the victim's subject with NO token.
        $response = $this->register($store, [
            'register_type' => 'google',
            'google_id' => 'victim-sub',
            'first_name' => 'Attacker',
            'token' => 'attacker-firebase-token',
            'device_type' => 'attacker-device',
        ]);

        $this->assertSame(0, $response->json('status'),
            'A caller with no provider credential was served.');

        $victim->refresh();
        $this->assertSame('Victim', $victim->first_name,
            "An attacker overwrote the victim's identity by naming their provider id.");
        $this->assertNotSame('attacker-firebase-token', $victim->firebase_token,
            "An attacker redirected the victim's push notifications by naming their provider id.");
        $this->assertNotSame('attacker-device', $victim->device_type);

        $token = $response->json('data.token');
        $this->assertNull($token, 'A bearer token was issued without any verified identity.');
    }

    /**
     * THE AUDIENCE CHECK. This token is perfectly valid -- correctly signed by the provider, live,
     * right issuer -- but was minted for a DIFFERENT application. Any other app that offers Google
     * sign-in can obtain one for the same person, so accepting it would authenticate that person
     * here without their ever having used this storefront.
     */
    public function test_a_token_minted_for_another_application_is_refused(): void
    {
        [$store, $provider] = $this->googleStore();

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token([
                'sub' => 'someone-elses-app-user',
                'aud' => 'a-totally-different-app.apps.googleusercontent.com',
            ]),
            'first_name' => 'Replayed',
        ]);

        $this->assertSame(0, $response->json('status'),
            'A token issued for another application was accepted. The signature was valid, so this '
            . 'is the assertion that distinguishes an audience check from a signature check.');
        $this->assertSame(0, self::customers()->where('google_id', 'someone-elses-app-user')->count());
    }

    public function test_a_token_signed_by_the_wrong_key_is_refused(): void
    {
        [$store, $provider] = $this->googleStore();

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->tokenSignedByAnotherKey([
                'sub' => 'forged-sub', 'aud' => self::AUDIENCE,
            ]),
            'first_name' => 'Forged',
        ]);

        $this->assertSame(0, $response->json('status'), 'A forged signature was accepted.');
        $this->assertSame(0, self::customers()->where('google_id', 'forged-sub')->count());
    }

    public function test_an_expired_token_is_refused(): void
    {
        [$store, $provider] = $this->googleStore();

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token([
                'sub' => 'expired-sub', 'aud' => self::AUDIENCE,
                'iat' => time() - 7200, 'exp' => time() - 3600,
            ]),
            'first_name' => 'Expired',
        ]);

        $this->assertSame(0, $response->json('status'), 'An expired token was accepted.');
        $this->assertSame(0, self::customers()->where('google_id', 'expired-sub')->count());
    }

    public function test_a_token_from_the_wrong_issuer_is_refused(): void
    {
        [$store, $provider] = $this->googleStore();

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token([
                'sub' => 'wrong-iss-sub', 'aud' => self::AUDIENCE,
                'iss' => 'https://evil.example.test',
            ]),
            'first_name' => 'WrongIssuer',
        ]);

        $this->assertSame(0, $response->json('status'), 'A token from another issuer was accepted.');
        $this->assertSame(0, self::customers()->where('google_id', 'wrong-iss-sub')->count());
    }

    public function test_a_token_with_no_subject_is_refused(): void
    {
        [$store, $provider] = $this->googleStore();

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token(['sub' => null, 'aud' => self::AUDIENCE]),
            'first_name' => 'NoSub',
        ]);

        $this->assertSame(0, $response->json('status'));
        $this->assertSame(0, self::customers()->count(), 'An account was created with no subject.');
    }

    /** The posted provider id must be inert, exactly as customer_id is across the storefront API. */
    public function test_the_posted_provider_id_is_ignored(): void
    {
        [$store, $provider] = $this->googleStore();

        $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token(['sub' => 'the-real-subject', 'aud' => self::AUDIENCE]),
            'google_id' => 'a-completely-different-id',
            'first_name' => 'Inert',
        ])->assertJsonPath('status', 1);

        $this->assertSame(1, self::customers()->where('google_id', 'the-real-subject')->count(),
            'The account was not keyed on the VERIFIED subject.');
        $this->assertSame(0, self::customers()->where('google_id', 'a-completely-different-id')->count(),
            'The posted provider id still decided the account key.');
    }

    // ===================================================================================
    // FAILING CLOSED WHEN A STORE HAS NOT CONFIGURED THE PROVIDER
    // ===================================================================================

    /**
     * An unconfigured store is refused AND the provider is never contacted.
     *
     * TWO SEPARATE PROPERTIES, and it is worth being precise about which code enforces which,
     * because an earlier version of this test credited the wrong one. The REFUSAL is guaranteed by
     * the audience intersection: with no configured audience, `array_intersect($tokenAudiences, [])`
     * is empty for every token, so the verifier cannot admit anyone. The early
     * `$audiences === []` guard in verifyOidcToken adds something different -- it stops the
     * outbound key fetch, so a store that has not configured Google does not hand a provider
     * outage or a slow DNS lookup a say in its registration latency, and does not emit traffic on
     * behalf of a feature it has not switched on.
     *
     * Http::assertNothingSent() is what isolates the guard. The keys ARE faked here, so a handler
     * without the guard would fetch them successfully and then refuse at the audience check -- the
     * refusal assertion alone cannot tell the two apart.
     */
    public function test_a_store_with_no_google_credential_refuses_without_contacting_the_provider(): void
    {
        [, $store] = $this->makeTenant();   // no Google_client_id setting

        $provider = new FakeOidcProvider();
        Http::fake([
            SocialIdentityVerifier::GOOGLE_JWKS_URL => Http::response($provider->jwks(), 200),
        ]);

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token(['sub' => 'unconfigured-sub', 'aud' => self::AUDIENCE]),
            'first_name' => 'Unconfigured',
        ]);

        $this->assertSame(0, $response->json('status'),
            'A store with no configured audience accepted a social sign-in.');
        $this->assertSame(0, self::customers()->count());
        Http::assertNothingSent();
    }

    /**
     * THE FAIL-CLOSED GUARD, ISOLATED. The test above passes even with the guard removed, because
     * without an Http::fake the key fetch becomes a stray request and the handler refuses for the
     * WRONG reason ("keys unavailable" rather than "not configured"). That is a vacuous pass.
     *
     * Here the provider's keys ARE available, so the only thing that can refuse is the guard. With
     * it removed, this store would accept a correctly signed token with no audience check at all --
     * i.e. a token minted for any other application.
     */
    public function test_an_unconfigured_store_is_refused_even_when_provider_keys_are_available(): void
    {
        [, $store] = $this->makeTenant();   // deliberately NO Google_client_id

        $provider = new FakeOidcProvider();
        Http::fake([
            SocialIdentityVerifier::GOOGLE_JWKS_URL => Http::response($provider->jwks(), 200),
        ]);

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token([
                'sub' => 'no-audience-sub', 'aud' => 'whatever-app-i-like',
            ]),
            'first_name' => 'NoAudience',
        ]);

        $this->assertSame(0, $response->json('status'),
            'A store with no configured audience accepted a correctly signed token. With no '
            . 'audience there is no check to make, so this is a signature-only verification -- '
            . 'which authenticates whoever holds any validly signed token for that person.');
        $this->assertSame(0, self::customers()->count());
    }

    /**
     * Apple has NO credential anywhere in this repository -- no bundle id, no Services ID, and no
     * vendored module that writes one, unlike GoogleLogin and SignInWithFacebook. The brief forbids
     * inventing provider credentials, so the apple branch must refuse until an owner populates
     * `apple_client_ids`. This asserts that refusal is real rather than assumed.
     */
    public function test_apple_refuses_while_no_apple_credential_is_configured(): void
    {
        [, $store] = $this->makeTenant();
        $provider = new FakeOidcProvider();

        $response = $this->register($store, [
            'register_type' => 'apple',
            'apple_token' => $provider->token([
                'sub' => 'apple-sub', 'aud' => 'com.example.app',
                'iss' => SocialIdentityVerifier::APPLE_ISSUER,
            ]),
            'first_name' => 'Apple',
        ]);

        $this->assertSame(0, $response->json('status'));
        $this->assertSame(0, self::customers()->count());
    }

    /** And once an owner DOES configure Apple, the same code path works. */
    public function test_apple_works_once_an_audience_is_configured(): void
    {
        [, $store] = $this->makeTenant();
        $this->setting($store, 'apple_client_ids', 'com.storesyria.app');

        $provider = new FakeOidcProvider();
        Http::fake([
            SocialIdentityVerifier::APPLE_JWKS_URL => Http::response($provider->jwks(), 200),
        ]);

        $this->register($store, [
            'register_type' => 'apple',
            'apple_token' => $provider->token([
                'sub' => 'apple-sub-ok', 'aud' => 'com.storesyria.app',
                'iss' => SocialIdentityVerifier::APPLE_ISSUER,
            ]),
            'first_name' => 'Apple',
        ])->assertJsonPath('status', 1);

        $this->assertSame(1, self::customers()->where('apple_id', 'apple-sub-ok')->count(),
            'The apple branch does not work even with an audience configured, so the refusal above '
            . 'is a dead end rather than a gate.');
    }

    public function test_an_unreachable_provider_key_set_refuses_rather_than_admits(): void
    {
        [, $store] = $this->makeTenant();
        $this->setting($store, 'Google_client_id', self::AUDIENCE);

        $provider = new FakeOidcProvider();
        Http::fake([
            SocialIdentityVerifier::GOOGLE_JWKS_URL => Http::response('gateway timeout', 504),
        ]);

        $response = $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token(['sub' => 'outage-sub', 'aud' => self::AUDIENCE]),
            'first_name' => 'Outage',
        ]);

        $this->assertSame(0, $response->json('status'),
            'A provider outage admitted an unverified sign-in.');
        $this->assertSame(0, self::customers()->count());
    }

    // ===================================================================================
    // FACEBOOK — a different mechanism, the same requirement
    // ===================================================================================

    private function facebookStore(): array
    {
        [, $store] = $this->makeTenant();
        $this->setting($store, 'facebook_client_id', '1234567890');
        $this->setting($store, 'facebook_client_secret', 'fb-app-secret');

        return [$store];
    }

    private function fakeDebugToken(array $data): void
    {
        Http::fake([
            SocialIdentityVerifier::FACEBOOK_DEBUG_TOKEN_URL . '*' => Http::response(['data' => $data], 200),
        ]);
    }

    public function test_a_verified_facebook_sign_in_creates_an_account(): void
    {
        [$store] = $this->facebookStore();
        $this->fakeDebugToken([
            'app_id' => '1234567890', 'is_valid' => true,
            'user_id' => 'fb-user-1', 'expires_at' => time() + 3600,
        ]);

        $this->register($store, [
            'register_type' => 'facebook',
            'facebook_token' => 'an-opaque-facebook-token',
            'first_name' => 'FB',
            'mobile' => '555000333',
        ])->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertSame(1, self::customers()->where('facebook_id', 'fb-user-1')->count(),
            'A verified facebook sign-in does not work, so the refusals below are vacuous.');
    }

    public function test_a_facebook_token_facebook_calls_invalid_is_refused(): void
    {
        [$store] = $this->facebookStore();
        $this->fakeDebugToken([
            'app_id' => '1234567890', 'is_valid' => false, 'user_id' => 'fb-user-bad',
        ]);

        $response = $this->register($store, [
            'register_type' => 'facebook',
            'facebook_token' => 'a-revoked-token',
            'first_name' => 'FB',
        ]);

        $this->assertSame(0, $response->json('status'));
        $this->assertSame(0, self::customers()->where('facebook_id', 'fb-user-bad')->count());
    }

    /** The audience check again, in Facebook's shape: a token minted for a different app. */
    public function test_a_facebook_token_for_another_app_is_refused(): void
    {
        [$store] = $this->facebookStore();
        $this->fakeDebugToken([
            'app_id' => '9999999999', 'is_valid' => true,
            'user_id' => 'fb-user-other-app', 'expires_at' => time() + 3600,
        ]);

        $response = $this->register($store, [
            'register_type' => 'facebook',
            'facebook_token' => 'a-token-from-another-app',
            'first_name' => 'FB',
        ]);

        $this->assertSame(0, $response->json('status'),
            "A token minted for another Facebook app was accepted -- Facebook said it was valid, so "
            . 'the app_id comparison is the only thing that could refuse it.');
        $this->assertSame(0, self::customers()->where('facebook_id', 'fb-user-other-app')->count());
    }

    public function test_an_expired_facebook_token_is_refused(): void
    {
        [$store] = $this->facebookStore();
        $this->fakeDebugToken([
            'app_id' => '1234567890', 'is_valid' => true,
            'user_id' => 'fb-user-expired', 'expires_at' => time() - 60,
        ]);

        $this->assertSame(0, $this->register($store, [
            'register_type' => 'facebook',
            'facebook_token' => 'an-expired-token',
            'first_name' => 'FB',
        ])->json('status'));
        $this->assertSame(0, self::customers()->where('facebook_id', 'fb-user-expired')->count());
    }

    public function test_a_store_with_no_facebook_app_refuses_and_makes_no_outbound_call(): void
    {
        [, $store] = $this->makeTenant();   // no facebook_client_id / secret

        // No Http::fake, preventStrayRequests on: reaching debug_token would fail as a stray call.
        $this->assertSame(0, $this->register($store, [
            'register_type' => 'facebook',
            'facebook_token' => 'a-token',
            'first_name' => 'FB',
        ])->json('status'));
        $this->assertSame(0, self::customers()->count());
    }

    /**
     * The Facebook equivalent of the guard test above, and vacuous for the same reason without it:
     * Facebook ANSWERS here, so only the missing-credential guard can refuse. Removing it would
     * have the server call debug_token with an empty app access token and compare the returned
     * app_id against an empty string.
     */
    public function test_an_unconfigured_store_is_refused_even_when_facebook_answers(): void
    {
        [, $store] = $this->makeTenant();   // deliberately NO facebook_client_id / secret

        $this->fakeDebugToken([
            'app_id' => '', 'is_valid' => true,
            'user_id' => 'fb-unconfigured', 'expires_at' => time() + 3600,
        ]);

        $response = $this->register($store, [
            'register_type' => 'facebook',
            'facebook_token' => 'a-token',
            'first_name' => 'FB',
        ]);

        $this->assertSame(0, $response->json('status'),
            'A store with no Facebook app accepted a sign-in. An empty app id compared equal to an '
            . 'empty returned app_id, so the audience check passed vacuously.');
        $this->assertSame(0, self::customers()->where('facebook_id', 'fb-unconfigured')->count());
    }

    // ===================================================================================
    // THE EMAIL THE ACCOUNT ENDS UP WITH
    // ===================================================================================

    /**
     * A caller presenting their OWN valid token could still post somebody else's address and have
     * a new account created holding it -- email squatting, and it points that address's
     * password-recovery mail at an account its owner never made.
     */
    public function test_a_provider_verified_email_wins_over_the_posted_one(): void
    {
        [$store, $provider] = $this->googleStore();

        $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token([
                'sub' => 'email-sub', 'aud' => self::AUDIENCE,
                'email' => 'real-owner@example.test', 'email_verified' => true,
            ]),
            'email' => 'victim-i-do-not-own@example.test',
            'first_name' => 'Squatter',
        ])->assertJsonPath('status', 1);

        $account = self::customers()->where('google_id', 'email-sub')->first();
        $this->assertNotNull($account);
        $this->assertSame('real-owner@example.test', $account->email,
            'The posted email was used instead of the provider-verified one, so a caller can '
            . "create an account holding somebody else's address.");
    }

    /** An UNVERIFIED provider email is not evidence and must not be adopted. */
    public function test_an_unverified_provider_email_is_not_adopted(): void
    {
        [$store, $provider] = $this->googleStore();

        $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token([
                'sub' => 'unverified-email-sub', 'aud' => self::AUDIENCE,
                'email' => 'claimed-but-unverified@example.test', 'email_verified' => false,
            ]),
            'email' => 'posted@example.test',
            'first_name' => 'Unverified',
        ])->assertJsonPath('status', 1);

        $account = self::customers()->where('google_id', 'unverified-email-sub')->first();
        $this->assertNotNull($account);
        $this->assertNotSame('claimed-but-unverified@example.test', $account->email,
            'An unverified provider email was adopted, which is how a social sign-in becomes a way '
            . 'to claim an address the caller does not control.');
    }

    /**
     * `email_verified` is accepted only as boolean true or the string "true", which is what Google
     * and Apple actually send. A truthy-but-non-canonical value -- an integer 1, a "yes" -- is not
     * a verification claim, and a loose `! empty()` test would adopt it.
     *
     * This is the test that isolates the strictness: with `! empty()` in its place, the address
     * below would be adopted on the strength of a value the provider never defined.
     */
    public function test_a_noncanonical_email_verified_value_is_not_treated_as_verified(): void
    {
        [$store, $provider] = $this->googleStore();

        $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token([
                'sub' => 'noncanonical-sub', 'aud' => self::AUDIENCE,
                'email' => 'loosely-claimed@example.test', 'email_verified' => 1,
            ]),
            'email' => 'posted@example.test',
            'first_name' => 'Loose',
        ])->assertJsonPath('status', 1);

        $this->assertNotSame('loosely-claimed@example.test',
            self::customers()->where('google_id', 'noncanonical-sub')->value('email'),
            'A non-canonical email_verified value was treated as a verification claim.');
    }

    /** A verified sign-in must not move an EXISTING account's address. */
    public function test_a_verified_sign_in_does_not_move_an_existing_accounts_email(): void
    {
        [$store, $provider] = $this->googleStore();

        $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token([
                'sub' => 'stable-sub', 'aud' => self::AUDIENCE,
                'email' => 'first@example.test', 'email_verified' => true,
            ]),
            'first_name' => 'Stable',
        ])->assertJsonPath('status', 1);

        $this->register($store, [
            'register_type' => 'google',
            'google_token' => $provider->token([
                'sub' => 'stable-sub', 'aud' => self::AUDIENCE,
                'email' => 'changed@example.test', 'email_verified' => true,
            ]),
            'first_name' => 'Stable',
        ])->assertJsonPath('status', 1);

        $this->assertSame('first@example.test',
            self::customers()->where('google_id', 'stable-sub')->value('email'),
            "A returning sign-in rewrote the account's email, which moves password recovery.");
    }
}
