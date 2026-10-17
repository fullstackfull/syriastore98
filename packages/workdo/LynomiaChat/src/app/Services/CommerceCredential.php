<?php

namespace Workdo\LynomiaChat\app\Services;

use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * The credential Lynomia's commerce adapter authenticates with, and the only thing that decides
 * which store a commerce request is about.
 *
 * WHY NOT THE EXISTING ADMIN API TOKEN. routes/api.php already has an authenticated admin
 * surface, and it is the wrong boundary for an integration. CustomAuth resolves a Sanctum token
 * to its owner and logs them into the `web` guard; every admin handler then tenants itself with
 * getCurrentStore(), which reads users.current_store -- a persisted, MUTABLE column written by
 * the store switcher, by registration and by the Flutter admin app, shared across every session
 * and device for that user. An integration bound to that would see its tenant change the moment
 * a merchant clicked a different store in a browser tab. That is not a tenancy boundary, it is a
 * race, and no amount of care on the Lynomia side can fix it.
 *
 * THE CREDENTIAL NAMES THE STORE. Nothing else does: not a request parameter, not a session, not
 * the token owner's current selection. There is no store id in the request for a caller to
 * declare, which is also how this satisfies "never rely on a self-declared store id as proof of
 * ownership" without a second ownership check.
 *
 * SHAPE
 *
 *     lsc_{storeId}_{version}_{hmac}
 *
 * The id and version travel in the clear because they are not secrets -- they are the lookup.
 * The hmac is what cannot be produced without the master key, and it covers BOTH, so a token
 * cannot be edited to point at another store or to resurrect a revoked version.
 *
 * NOTHING IS STORED. The hmac is recomputed per request and compared in constant time, the same
 * derivation EventSignature already uses for webhook secrets and for the same reason: APP_KEY is
 * published and unrotated in this installation, so Laravel's `encrypted` cast protects nothing
 * here, and a credential that is never written down cannot be read out of a database.
 *
 * REVOCATION IS THE VERSION. A credential cannot be deleted because it was never stored, so it
 * is invalidated instead: bumping lynomia_store_links.commerce_credential_version changes the
 * hmac input and every token minted before that moment stops verifying, immediately and without
 * a deploy. Issuing a new credential bumps it, so issuing twice revokes the first.
 *
 * PURPOSE SEPARATION. The prefix 'lynomia-commerce:' is inside the hmac input, so this token
 * cannot be replayed against the webhook ingress (whose secrets derive from
 * 'lynomia-webhook:{store}') and a webhook secret cannot be replayed here. It is also not a
 * Sanctum personal access token, so CustomAuth will not resolve it and it cannot reach the
 * shopper or admin APIs at all -- the only middleware that understands it is the one guarding
 * the commerce routes.
 */
class CommerceCredential
{
    public const PREFIX = 'lsc_';

    /** @var string the purpose label inside the hmac; see the note on purpose separation */
    private const PURPOSE = 'lynomia-commerce';

    /**
     * Mint the credential for a store. Shown to an operator once and never persisted.
     */
    public static function issue(LynomiaStoreLink $link): string
    {
        $storeId = (int) $link->store_id;
        $version = (int) $link->commerce_credential_version;

        return self::PREFIX . $storeId . '_' . $version . '_' . self::signature($storeId, $version);
    }

    /**
     * Resolve a presented credential to the store it is bound to.
     *
     * @return int|null the store id, or null when the token is malformed, forged or revoked
     */
    public static function resolveStoreId(?string $token): ?int
    {
        if (! is_string($token) || ! str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $parts = explode('_', substr($token, strlen(self::PREFIX)), 3);

        if (count($parts) !== 3) {
            return null;
        }

        [$storeId, $version, $presented] = $parts;

        if (! ctype_digit($storeId) || ! ctype_digit($version)) {
            return null;
        }

        // Constant time, because a byte-at-a-time comparison leaks how much of a guess was right.
        if (! hash_equals(self::signature((int) $storeId, (int) $version), $presented)) {
            return null;
        }

        // The version in the token must still be the CURRENT one. This is the revocation check
        // and it is a database read on purpose: a revoked credential has to stop working now,
        // not when a cache expires.
        $link = LynomiaStoreLink::forStore((int) $storeId)->first();

        if ($link === null || (int) $link->commerce_credential_version !== (int) $version) {
            return null;
        }

        return (int) $storeId;
    }

    private static function signature(int $storeId, int $version): string
    {
        $master = (string) config('lynomia.webhook_master_secret');

        if ($master === '') {
            // The same refusal EventSignature makes, for the same reason: an unconfigured master
            // key must not quietly produce a predictable credential that anybody could compute.
            throw new \RuntimeException('LYNOMIA_WEBHOOK_MASTER_SECRET is not configured.');
        }

        return hash_hmac('sha256', self::PURPOSE . ':' . $storeId . ':' . $version, $master);
    }
}
