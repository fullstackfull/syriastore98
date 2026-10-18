<?php

namespace App\Services\SocialIdentity;

use App\Models\Setting;

/**
 * Resolves a store's provider verification credentials, EXACTLY and with no fallback.
 *
 * WHY NOT Utility::GetValueByName(). That accessor is the project's idiomatic settings reader, and
 * it is wrong for a security credential for two reasons, both read off its body in
 * app/Models/Utility.php:
 *
 *   1. When `$store_id` is empty it applies NO store predicate at all -- it plucks name => value
 *      across the whole `settings` table, so the last row for a given name wins regardless of which
 *      tenant wrote it. `Setting` is not in config/tenancy.php's scoped_models either, so nothing
 *      underneath narrows it. A credential resolved that way could come from another merchant.
 *   2. When the per-store key is absent it falls back to `Utility::Setting($store_id)`, a defaults
 *      map. A default audience is not a credential; silently adopting one would be failing OPEN.
 *
 * So this class reads one row for one store and returns null when there is no row. Null means
 * "refuse", never "use a default".
 *
 * WHERE THE VALUES COME FROM, and what the owner still has to decide, is documented per provider
 * below. No credential is invented here and none is written.
 */
class ProviderCredentials
{
    /**
     * Google: the allowed `aud` values for an ID token.
     *
     * `Google_client_id` already exists per store -- the vendored GoogleLogin module writes it for
     * a WEB OAuth redirect flow. For Google Sign-In on Android and iOS the usual configuration
     * requests the ID token against the WEB client id, so that value is frequently the correct
     * audience for a mobile token too. "Frequently" is not "always": a native client id is a
     * different string, and this repository holds none.
     *
     * So both are read, and either may be absent:
     *   `Google_client_id`          -- existing, web OAuth client id
     *   `google_api_audiences`      -- NEW, optional, comma-separated additional allowed audiences
     *                                  for native clients. An owner populates it if their app
     *                                  presents a platform-specific client id.
     *
     * Neither present => refuse. Nothing is assumed about which client id the app uses.
     *
     * @return array<int, string>
     */
    public function googleAudiences(int $storeId): array
    {
        return $this->mergeAudiences([
            $this->read('Google_client_id', $storeId),
            $this->read('google_api_audiences', $storeId),
        ]);
    }

    /**
     * Apple: the allowed `aud` values for an identity token.
     *
     * There is NO Apple credential anywhere in this repository -- no bundle id, no Services ID, no
     * key, and no vendored module that writes one (unlike GoogleLogin and SignInWithFacebook). The
     * brief forbids inventing provider credentials, so this returns empty until an owner populates
     * `apple_client_ids`, and the Apple branch refuses.
     *
     * That refusal is the correct outcome rather than a gap. An Apple identity token's signature
     * proves only that APPLE issued it -- to some relying party. Without a pinned audience, a token
     * minted for any other app that offers Sign In with Apple verifies here too, so a
     * signature-only check would authenticate the wrong person's app, not the person. See
     * docs/security/P01-SECURITY-WORK-QUEUE.md S20.
     *
     * @return array<int, string>
     */
    public function appleAudiences(int $storeId): array
    {
        return $this->mergeAudiences([
            $this->read('apple_client_ids', $storeId),
        ]);
    }

    /**
     * Facebook: the app id and app secret, which together form the app access token that
     * `debug_token` requires.
     *
     * Unlike Google, a Facebook app id is NOT per-platform -- one app serves web, Android and iOS --
     * so `facebook_client_id` / `facebook_client_secret`, written per store by the vendored
     * SignInWithFacebook module, are the right credentials for verifying a token from any client of
     * that app. That is why Facebook can be verified today and Apple cannot.
     *
     * @return array{app_id: string, app_secret: string}|null
     */
    public function facebookApp(int $storeId): ?array
    {
        $appId = $this->read('facebook_client_id', $storeId);
        $appSecret = $this->read('facebook_client_secret', $storeId);

        if ($appId === null || $appSecret === null) {
            return null;
        }

        return ['app_id' => $appId, 'app_secret' => $appSecret];
    }

    /** One row, one store, no fallback. Blank values read as absent. */
    private function read(string $name, int $storeId): ?string
    {
        $value = Setting::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('name', $name)
            ->value('value');

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, string|null>  $values
     * @return array<int, string>
     */
    private function mergeAudiences(array $values): array
    {
        $audiences = [];

        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            foreach (explode(',', $value) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $audiences[] = $candidate;
                }
            }
        }

        return array_values(array_unique($audiences));
    }
}
