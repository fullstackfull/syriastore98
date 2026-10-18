<?php

namespace App\Services\SocialIdentity;

use App\Models\Store;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Establishes WHO a social caller is, server-side, instead of believing the id they posted.
 *
 * THE DEFECT THIS REPLACES. api/{slug}/register matched an existing account on a provider id taken
 * straight from the request body:
 *
 *     $existing = Customer::...->where('google_id', $request->google_id)->first();
 *
 * A previous package scoped that lookup to the store and stopped it adopting a password account,
 * which reduced the attack from "know an email address" to "know a provider id". It did not and
 * could not close it: a posted string is an assertion, not evidence. Anyone holding a victim's
 * Google subject identifier could sign in as them.
 *
 * WHAT A SERVER CAN ACTUALLY VERIFY, per provider, and why the three differ:
 *
 *   google    An OpenID Connect ID token -- an RS256 JWT signed by Google. The server fetches
 *             Google's published keys, checks the signature, then checks that the token was issued
 *             FOR THIS APPLICATION (`aud`) and BY Google (`iss`). `sub` is then a stable
 *             per-application identifier for that person.
 *   apple     The same shape, signed by Apple, with the audience being the app's bundle id or
 *             Services ID.
 *   facebook  Not a JWT. Facebook publishes a server-to-server inspection endpoint,
 *             `/debug_token`, which the server calls using an app access token composed of its own
 *             app id and secret. The response states whether the token is valid and WHICH app it
 *             was minted for.
 *
 * THE AUDIENCE CHECK IS THE CONTROL, not the signature. A signature proves a provider issued the
 * token to SOMEBODY. Any other app using the same provider can obtain a validly signed token for
 * the same person; replaying it here would then authenticate that person without their having used
 * this storefront at all. So a verifier with no configured audience is not a weaker verifier -- it
 * is not a verifier. Every path below therefore refuses when no credential is configured, rather
 * than degrading to a signature-only check.
 *
 * NO CREDENTIAL IS INVENTED. Credentials come from the store's existing settings rows; see
 * ProviderCredentials for exactly which keys, which already exist, and which an owner must
 * populate.
 */
class SocialIdentityVerifier
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_APPLE = 'apple';
    public const PROVIDER_FACEBOOK = 'facebook';

    /** The request field each provider's credential must arrive in. */
    public const TOKEN_FIELDS = [
        self::PROVIDER_GOOGLE => 'google_token',
        self::PROVIDER_APPLE => 'apple_token',
        self::PROVIDER_FACEBOOK => 'facebook_token',
    ];

    public const GOOGLE_JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    public const GOOGLE_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public const APPLE_JWKS_URL = 'https://appleid.apple.com/auth/keys';
    public const APPLE_ISSUER = 'https://appleid.apple.com';

    public const FACEBOOK_DEBUG_TOKEN_URL = 'https://graph.facebook.com/debug_token';

    public function __construct(
        private readonly JwksProvider $jwks = new JwksProvider(),
        private readonly ProviderCredentials $credentials = new ProviderCredentials(),
    ) {
    }

    public static function isSupported(string $provider): bool
    {
        return array_key_exists($provider, self::TOKEN_FIELDS);
    }

    /**
     * @throws SocialIdentityVerificationException
     */
    public function verify(string $provider, ?string $token, Store $store): VerifiedSocialIdentity
    {
        if (! self::isSupported($provider)) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_UNSUPPORTED_PROVIDER
            );
        }

        if ($token === null || trim($token) === '') {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_NO_TOKEN,
                'No ' . self::TOKEN_FIELDS[$provider] . ' was presented.'
            );
        }

        $token = trim($token);
        $storeId = (int) $store->id;

        return match ($provider) {
            self::PROVIDER_GOOGLE => $this->verifyOidcToken(
                $provider,
                $token,
                self::GOOGLE_JWKS_URL,
                self::GOOGLE_ISSUERS,
                $this->credentials->googleAudiences($storeId)
            ),
            self::PROVIDER_APPLE => $this->verifyOidcToken(
                $provider,
                $token,
                self::APPLE_JWKS_URL,
                [self::APPLE_ISSUER],
                $this->credentials->appleAudiences($storeId)
            ),
            self::PROVIDER_FACEBOOK => $this->verifyFacebookToken($token, $storeId),
        };
    }

    /**
     * Google and Apple share one procedure, because both are OpenID Connect providers publishing
     * an RS256 key set. Only the URL, the issuer set and the audience source differ.
     *
     * @param  array<int, string>  $issuers
     * @param  array<int, string>  $audiences
     *
     * @throws SocialIdentityVerificationException
     */
    private function verifyOidcToken(
        string $provider,
        string $token,
        string $jwksUrl,
        array $issuers,
        array $audiences
    ): VerifiedSocialIdentity {
        // FAIL CLOSED FIRST, before any outbound call. With no audience there is no check to make,
        // so there is nothing to be gained by fetching keys -- and refusing here means a store that
        // has not configured a provider never reaches the network at all.
        if ($audiences === []) {
            throw SocialIdentityVerificationException::notConfigured($provider);
        }

        $keys = $this->jwks->keysFor($jwksUrl);

        try {
            // decode() verifies the RS256 signature against the key set and enforces exp/nbf/iat.
            // It does NOT check aud or iss -- those are checked below, explicitly, because getting
            // them wrong is the difference between a control and a decoration.
            $claims = JWT::decode($token, $keys);
        } catch (Throwable $e) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_TOKEN_INVALID,
                'Token signature or lifetime rejected.',
                $e
            );
        }

        $issuer = isset($claims->iss) ? (string) $claims->iss : '';
        if (! in_array($issuer, $issuers, true)) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_WRONG_ISSUER,
                'Token issuer [' . $issuer . '] is not this provider.'
            );
        }

        // `aud` is a string or an array of strings per RFC 7519. Both are handled; a token whose
        // audience set does not intersect the configured one is refused.
        $tokenAudiences = [];
        if (isset($claims->aud)) {
            $tokenAudiences = is_array($claims->aud)
                ? array_map('strval', $claims->aud)
                : [(string) $claims->aud];
        }

        if (array_intersect($tokenAudiences, $audiences) === []) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_WRONG_AUDIENCE,
                'Token was not issued for this application.'
            );
        }

        $subject = isset($claims->sub) ? trim((string) $claims->sub) : '';
        if ($subject === '') {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_NO_SUBJECT
            );
        }

        $email = isset($claims->email) ? trim((string) $claims->email) : '';

        return new VerifiedSocialIdentity(
            provider: $provider,
            providerId: $subject,
            email: $email === '' ? null : $email,
            // Providers send this as a real boolean or as the string "true"; anything else is NOT
            // a verification claim and must not be read as one.
            emailVerified: isset($claims->email_verified)
                && ($claims->email_verified === true || $claims->email_verified === 'true'),
        );
    }

    /**
     * @throws SocialIdentityVerificationException
     */
    private function verifyFacebookToken(string $token, int $storeId): VerifiedSocialIdentity
    {
        $app = $this->credentials->facebookApp($storeId);

        if ($app === null) {
            throw SocialIdentityVerificationException::notConfigured(self::PROVIDER_FACEBOOK);
        }

        try {
            $response = Http::timeout(5)->retry(2, 200)->acceptJson()->get(
                self::FACEBOOK_DEBUG_TOKEN_URL,
                [
                    'input_token' => $token,
                    // An app access token is literally "{app-id}|{app-secret}". It authenticates
                    // this server to Facebook; it is never sent to or derived from the client.
                    'access_token' => $app['app_id'] . '|' . $app['app_secret'],
                ]
            );
        } catch (Throwable $e) {
            Log::warning('social-identity: facebook debug_token call failed', [
                'error' => $e->getMessage(),
            ]);

            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_KEYS_UNAVAILABLE,
                'Facebook token inspection was unreachable.',
                $e
            );
        }

        if (! $response->successful()) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_TOKEN_INVALID,
                'Facebook token inspection returned HTTP ' . $response->status() . '.'
            );
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_TOKEN_INVALID,
                'Facebook token inspection carried no data member.'
            );
        }

        // `is_valid` is the only field that says the token is live. Absent or false means refuse --
        // never defaulted to true.
        if (($data['is_valid'] ?? false) !== true) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_TOKEN_INVALID,
                'Facebook reported the token as not valid.'
            );
        }

        // THE AUDIENCE CHECK. `app_id` is which app the token was minted for. Without this, a token
        // from any other Facebook app would authenticate its holder here.
        if ((string) ($data['app_id'] ?? '') !== (string) $app['app_id']) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_WRONG_AUDIENCE,
                'Facebook token was minted for a different application.'
            );
        }

        // Facebook returns expiry in seconds since the epoch; 0 means a long-lived token.
        $expiresAt = (int) ($data['expires_at'] ?? 0);
        if ($expiresAt !== 0 && $expiresAt <= time()) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_TOKEN_INVALID,
                'Facebook token has expired.'
            );
        }

        $subject = trim((string) ($data['user_id'] ?? ''));
        if ($subject === '') {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_NO_SUBJECT
            );
        }

        // debug_token does not return an email, and Facebook's user id is APP-SCOPED: the same
        // person has a different id in a different app. Both facts are fine here -- the id is only
        // ever compared against customers.facebook_id written by this same app -- but they mean the
        // email cannot be taken from this response and must not be inferred.
        return new VerifiedSocialIdentity(
            provider: self::PROVIDER_FACEBOOK,
            providerId: $subject,
            email: null,
            emailVerified: false,
        );
    }
}
