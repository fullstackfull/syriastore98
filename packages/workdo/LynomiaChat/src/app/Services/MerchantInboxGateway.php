<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\User;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * The one place a merchant request turns into a Lynomia call.
 *
 * EVERY BFF ROUTE GOES THROUGH HERE, and the reason is that the authorization question is the
 * same every time and must have one answer. Given a request, this resolves: is there an active
 * link for the store the middleware authorized, does this person have an identity in that
 * workspace, and what credential acts for them. A controller that assembled any of that itself
 * would be a second answer, and the second answer is the one that gets it wrong.
 *
 * THE CREDENTIAL IS PER PERSON, NOT PER STORE. It carries the merchant's own Lynomia identity
 * and their own role, so Lynomia attributes every reply, assignment and status change to the
 * person who made it, and refuses anything their role does not permit. The platform token --
 * which can create workspaces and reaches every account this app holds -- is used for exactly
 * one thing here: asking Lynomia for that per-person credential. It never serves a merchant
 * request and never leaves the server.
 *
 * THE CREDENTIAL IS NEVER PERSISTED. config/lynomia.php explains at length why this integration
 * puts no secret in the database: APP_KEY is published and unrotated, so Laravel's `encrypted`
 * cast protects nothing here. A Lynomia bearer token is a secret by that same standard, so it is
 * held for the life of the request and nowhere else by default -- see credentialStore().
 */
class MerchantInboxGateway
{
    public function __construct(
        private readonly PlatformApiClient $api,
        private readonly IdentityProvisioner $identities
    ) {
    }

    /**
     * An agent client acting as this person, in this store's workspace.
     *
     * @throws LynomiaApiException when the store has no workspace a merchant may open
     */
    public function clientFor(LynomiaStoreLink $link, User $person): AgentApiClient
    {
        $identity = $this->identityFor($link, $person);
        $accountId = (int) $link->lynomia_account_id;

        return new AgentApiClient(
            new LynomiaHttp(
                (string) config('lynomia.base_url'),
                $this->tokenFor($identity, $accountId),
                (array) config('lynomia.http', [])
            ),
            $accountId
        );
    }

    /**
     * This person's Lynomia identity in this workspace, created on first use.
     *
     * The link state is re-checked here even though a route already resolved the link, because
     * a link can be revoked between the page load and the request: allowsSso() is the same
     * predicate that governs opening the workspace, so a revoked store loses the inbox at the
     * same moment it loses the redirect, not at the next deploy.
     */
    public function identityFor(LynomiaStoreLink $link, User $person): LynomiaStoreIdentity
    {
        if (! $link->allowsSso()) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'This store has no active Lynomia workspace to open.'
            );
        }

        return $this->identities->identityFor($link, $person);
    }

    /**
     * A bearer confined to this workspace, carrying this person's rights.
     *
     * CACHED ONLY WHERE CACHING IS NOT WRITING IT DOWN. The default store is `array`, which
     * lives for the request and is then gone -- so by default this costs one extra call to
     * Lynomia per merchant request and leaves no copy anywhere. That is deliberate: this
     * installation's default cache driver is `file`, and caching a bearer token there would
     * write a live Lynomia credential to disk in plaintext, which is the exact mistake the
     * retired Chatwoot integration made with its agent token and the reason config/lynomia.php
     * keeps credentials out of the database.
     *
     * An operator who has provisioned a non-persistent store can point
     * LYNOMIA_CREDENTIAL_CACHE_STORE at it and get the round trip back. Nothing here chooses
     * that for them, because the safe default must not depend on reading a comment.
     */
    private function tokenFor(LynomiaStoreIdentity $identity, int $accountId): string
    {
        $key = sprintf('lynomia.identity-token.%d.%d', $accountId, $identity->lynomia_user_id);
        $store = $this->credentialStore();

        $cached = $store->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $issued = $this->api->issueScopedAccessToken(
            (int) $identity->lynomia_user_id,
            $accountId,
            (int) config('lynomia.credential_lifetime_seconds', 3600)
        );

        // Expire the copy well before the credential itself does, so a request never picks up a
        // token that lapses mid-flight and fails against Lynomia for no reason the merchant can see.
        $store->put($key, $issued['token'], now()->addSeconds(
            max(60, (int) (config('lynomia.credential_lifetime_seconds', 3600) * 0.5))
        ));

        return $issued['token'];
    }

    private function credentialStore()
    {
        return cache()->store(config('lynomia.credential_cache_store', 'array'));
    }
}
