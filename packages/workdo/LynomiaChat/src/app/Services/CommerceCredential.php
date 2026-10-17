<?php

namespace Workdo\LynomiaChat\app\Services;

use Illuminate\Support\Facades\DB;
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
 * a deploy. Minting a new credential bumps it, so minting twice revokes the first.
 *
 * MINTING AND DERIVING ARE TWO METHODS, AND THE DIFFERENCE MATTERS. `mint()` rotates: it bumps
 * the version and returns the new token. `issue()` only DERIVES at whatever version is current
 * and changes nothing, which is what lets the automatic activation re-send a store's existing
 * credential instead of rotating a working installation in order to verify it.
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
     * Derive the credential for a store AT ITS CURRENT VERSION. Does not rotate.
     *
     * This is the read: it is what the commerce middleware verifies against, what an installation
     * recovery re-presents, and what the automatic activation re-sends when the store already has
     * a working credential. Calling it twice returns the same string, which is the whole reason
     * activation does not have to rotate to re-install (see CommerceActivator).
     */
    public static function issue(LynomiaStoreLink $link): string
    {
        $storeId = (int) $link->store_id;
        $version = (int) $link->commerce_credential_version;

        return self::PREFIX . $storeId . '_' . $version . '_' . self::signature($storeId, $version);
    }

    /**
     * ROTATE AND DERIVE, OR DO NEITHER.
     *
     * THE DEFECT THIS EXISTS TO MAKE IMPOSSIBLE. Issuance used to be two unrelated statements in
     * a controller: bump the version and save, then derive. Deriving can fail -- `signature()`
     * refuses outright when LYNOMIA_WEBHOOK_MASTER_SECRET is unset, which is exactly what a real
     * issuance hit, with an HTTP 500 to show for it. By then the version had been committed, so
     * every credential minted before that instant had been REVOKED by an operation that reported
     * failure. The store's working integration was taken down by a button that said it had not
     * done anything.
     *
     * Two things prevent it now and they are independent on purpose:
     *
     *   the secret is asserted BEFORE anything is written, so the known failure mode never gets
     *   as far as the bump; and
     *
     *   the bump and the derivation share one transaction, so any OTHER failure -- a database
     *   error, a future validation, something nobody has thought of -- rolls the version back and
     *   leaves the previous credential working.
     *
     * AND IT IS THE ONLY WRITER OF THE VERSION ON THE ISSUING SIDE, which is the other half of
     * the fix. The operator path and the automatic activation path both mint here, so they cannot
     * drift into bumping the same column by different rules. Revocation stays where it is: it
     * derives nothing, so it was never able to fail this way.
     */
    public static function mint(LynomiaStoreLink $link): string
    {
        self::assertConfigured();

        return DB::transaction(function () use ($link): string {
            $link->forceFill([
                'commerce_credential_version' => (int) $link->commerce_credential_version + 1,
                'commerce_credential_issued_at' => now(),
            ])->save();

            // Derived INSIDE the transaction and from the in-memory row the save just wrote, so a
            // throw here takes the bump with it rather than leaving a version nothing can produce
            // a token for.
            return self::issue($link);
        });
    }

    /**
     * Whether a credential could be derived at all right now.
     *
     * Read by the merchant-facing activation before it offers the button, so a misconfigured
     * installation says "not available yet" instead of failing mid-flow -- and asserted again by
     * mint(), because a UI gate is never the only thing standing between a caller and a key.
     */
    public static function isConfigured(): bool
    {
        return (string) config('lynomia.webhook_master_secret') !== '';
    }

    private static function assertConfigured(): void
    {
        if (! self::isConfigured()) {
            throw new \RuntimeException('LYNOMIA_WEBHOOK_MASTER_SECRET is not configured.');
        }
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
        // The same refusal EventSignature makes, for the same reason: an unconfigured master key
        // must not quietly produce a predictable credential that anybody could compute. Kept here
        // as well as in mint() because this is also the VERIFY path, which does not go through
        // mint at all.
        self::assertConfigured();

        return hash_hmac('sha256', self::PURPOSE . ':' . $storeId . ':' . $version, (string) config('lynomia.webhook_master_secret'));
    }
}
