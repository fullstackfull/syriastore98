<?php

namespace App\Services\SocialIdentity;

use Firebase\JWT\JWK;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches and caches a provider's JSON Web Key Set, and parses it into firebase/php-jwt keys.
 *
 * TWO DESIGN POINTS, both of which exist to make this testable without touching the network:
 *
 *   1. The fetch goes through the `Http` FACADE, not curl and not file_get_contents. That is the
 *      one seam `Http::fake()` can intercept, so a test can serve a key set it generated itself and
 *      the real verification code still runs end to end. A verifier whose key source cannot be
 *      faked can only be tested by talking to Google, which no test may do.
 *   2. Keys are cached, because a provider rotates them on the order of days while a storefront
 *      may sign people in every second. An uncached fetch would put an outbound HTTPS call on the
 *      critical path of every registration and hand a provider outage the power to stop sign-ins.
 *
 * The cache is keyed by URL, so Google and Apple never share an entry. On a fetch failure the
 * previous key set is NOT served stale beyond its TTL: a stale key set is how a revoked signing key
 * keeps working, so this fails closed and the sign-in is refused.
 */
class JwksProvider
{
    /** Providers publish rotation schedules in days; an hour is short enough to follow one. */
    public const CACHE_TTL_SECONDS = 3600;

    /**
     * @return array<string, \Firebase\JWT\Key>
     *
     * @throws SocialIdentityVerificationException
     */
    public function keysFor(string $url): array
    {
        $cacheKey = 'social_identity_jwks:' . sha1($url);

        $jwks = Cache::get($cacheKey);

        if (! is_array($jwks)) {
            $jwks = $this->fetch($url);
            Cache::put($cacheKey, $jwks, self::CACHE_TTL_SECONDS);
        }

        try {
            // parseKeySet validates the key material itself and throws on a malformed set.
            return JWK::parseKeySet($jwks);
        } catch (Throwable $e) {
            // A key set that will not parse is indistinguishable from no key set: refuse.
            Cache::forget($cacheKey);

            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_KEYS_UNAVAILABLE,
                'Provider key set could not be parsed.',
                $e
            );
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SocialIdentityVerificationException
     */
    private function fetch(string $url): array
    {
        try {
            $response = Http::timeout(5)->retry(2, 200)->acceptJson()->get($url);
        } catch (Throwable $e) {
            Log::warning('social-identity: provider key fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_KEYS_UNAVAILABLE,
                'Provider key set could not be fetched.',
                $e
            );
        }

        if (! $response->successful()) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_KEYS_UNAVAILABLE,
                'Provider key set request returned HTTP ' . $response->status() . '.'
            );
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ! isset($decoded['keys'])) {
            throw new SocialIdentityVerificationException(
                SocialIdentityVerificationException::REASON_KEYS_UNAVAILABLE,
                'Provider key set response carried no "keys" member.'
            );
        }

        return $decoded;
    }
}
