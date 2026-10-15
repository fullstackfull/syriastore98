<?php

namespace Workdo\LynomiaChat\app\Services;

use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;

/**
 * Server-side client for the Lynomia Platform API.
 *
 * THIS CLASS IS THE ONLY PLACE THE PLATFORM TOKEN IS READ, AND IT NEVER LEAVES THE SERVER.
 * No controller receives it, no view renders it, no response carries it and no log line
 * contains it. The merchant browser never talks to Lynomia directly -- partly because
 * Lynomia's CORS policy does not expose /api/* to other origins by default
 * (config/initializers/cors.rb in the Lynomia repository), and mainly because a browser that
 * could call it would need the credential.
 *
 * WHAT IT REUSES RATHER THAN REBUILDS. Every endpoint below already exists in Lynomia and is
 * already authorized there:
 *
 *   POST   /platform/api/v1/accounts                      create a workspace
 *   GET    /platform/api/v1/accounts                      list this app's workspaces
 *   POST   /platform/api/v1/users                         create a user  (see the warning below)
 *   POST   /platform/api/v1/accounts/{id}/account_users   attach a member, idempotent upstream
 *   GET    /platform/api/v1/users/{id}/login?account_id=  mint a one-time, workspace-scoped SSO URL
 *   POST   /platform/api/v1/account_links                 redeem a merchant's link code
 *   DELETE /platform/api/v1/account_links/{account}       hand the grant back
 *   POST   /platform/api/v1/users/{id}/scoped_access_token  a per-person, per-workspace bearer
 *   GET/POST/DELETE /platform/api/v1/channel_claims        one channel identity, one store
 *   GET/PUT/DELETE  .../accounts/{id}/entitlement_grants   seats a Store Syria package includes
 *
 * The last two are added by this integration on the Lynomia side. Everything else is stock.
 *
 * Authorization is Lynomia's: the token belongs to a PlatformApp and
 * validate_platform_app_permissible restricts every call to resources in that app's
 * platform_app_permissibles. Store Syria therefore cannot reach a Lynomia account it did not
 * create -- which is what keeps independently-registered Lynomia customers out of reach.
 *
 * ⚠ createUser() IS NOT A LINKING PRIMITIVE. Upstream, Platform::Api::V1::UsersController#create
 * reads `User.from_email(...) || User.new(...)` and then grants the calling app a permissible
 * on whatever it found. Called with an email that already exists it therefore ATTACHES AN
 * EXISTING LYNOMIA USER to this app, after which /users/{id}/login mints a working session for
 * them. That is an account-takeover primitive, not a linking one. It is used here only from
 * IdentityProvisioner, only for a synthetic address in a reserved domain that cannot belong to
 * a real person, and only when the user that comes back carries the marker proving this
 * integration is what created it. AccountLinker never calls it: the existing-account path
 * learns who the merchant is from a code minted inside their own workspace, not from an email.
 */
class PlatformApiClient
{
    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('lynomia', []));
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /** True when the module could actually talk to Lynomia right now. */
    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && filled($this->config['base_url'] ?? null)
            && filled($this->config['platform_token'] ?? null);
    }

    // ------------------------------------------------------------------ endpoints

    /** @return array{id:int,name:string} */
    public function createAccount(string $name, array $customAttributes = []): array
    {
        $payload = ['name' => $name];

        if ($customAttributes !== []) {
            // Account.custom_attributes is permitted by the upstream strong params and is the
            // natural home for the back-reference to the Store Syria store, so an operator
            // looking at a Lynomia account can tell which store it serves.
            $payload['custom_attributes'] = $customAttributes;
        }

        // NOT retried -- see send(). A retry here is how a second workspace gets created
        // for a store that already has one. AccountProvisioner reconciles instead.
        $body = $this->send('post', '/platform/api/v1/accounts', $payload, retryTransport: false);

        return [
            'id' => $this->requireInt($body, 'id'),
            'name' => (string) ($body['name'] ?? $name),
        ];
    }

    /**
     * The accounts this app holds a permissible on, optionally narrowed to those carrying a
     * custom attribute.
     *
     * THIS IS THE ORPHAN-RECOVERY PRIMITIVE. When POST /accounts times out, Lynomia may have
     * created the workspace and lost the answer on the way back. The id is then known only to
     * Lynomia, and a blind retry would make a second one. Every account this integration
     * creates is stamped with the store it serves, so the store can find its own workspace
     * again and adopt it. Scoping is Lynomia's: the index returns the app's permissibles and
     * nothing else, so this cannot enumerate accounts belonging to anyone else.
     *
     * @return list<array{id:int,name:string,custom_attributes:array}>
     */
    public function listAccounts(array $customAttributeFilter = []): array
    {
        $body = $this->send('get', '/platform/api/v1/accounts', $customAttributeFilter === []
            ? []
            : ['custom_attributes' => $customAttributeFilter]);

        // The index renders a bare JSON array; Laravel decodes that to a list.
        $rows = array_is_list($body) ? $body : ($body['payload'] ?? []);

        $accounts = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! is_numeric($row['id'])) {
                continue;
            }

            $accounts[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'custom_attributes' => (array) ($row['custom_attributes'] ?? []),
            ];
        }

        return $accounts;
    }

    /**
     * Redeem a link code a merchant generated INSIDE their own Lynomia account.
     *
     * THIS IS THE ONLY SAFE WAY TO LINK AN ACCOUNT THIS INTEGRATION DID NOT CREATE, and it is
     * safe for one reason: the proof is minted on the Lynomia side by someone who had to
     * authenticate there as an administrator of the account. Store Syria asserts nothing. It
     * forwards a code the merchant pasted in, and Lynomia answers with the account and the
     * user that code was created by -- or refuses. No email is sent, matched or guessed, and
     * there is no input on this path that names an account directly, so there is nothing a
     * merchant can type that points at a workspace they cannot already sign in to.
     *
     * The code is single-use and short-lived upstream, and is never stored here.
     *
     * WHAT COMES BACK IS THE WORKSPACE, AND ONLY THE WORKSPACE. Redemption used to hand this
     * app the authorizing administrator's own identity as well, which cashed a proof about ONE
     * account in for the ability to sign in as that person in every account they belong to.
     * Upstream now grants the account and names the authorizer without granting them; who
     * signs in, as whom, and with what role is decided per person afterwards by
     * IdentityProvisioner. The two authorizer fields are recorded for the audit trail -- this
     * app cannot act as that user and does not try.
     *
     * @return array{account_id:int,account_name:string,authorized_by_user_id:int,authorized_by_name:string}
     */
    public function redeemLinkCode(string $code): array
    {
        // Not retried. Redemption burns the code upstream, so a retry after a lost answer
        // would report "already used" for a link that in fact succeeded.
        $body = $this->send('post', '/platform/api/v1/account_links', ['code' => $code], retryTransport: false);

        return [
            'account_id' => $this->requireInt($body, 'account_id'),
            'account_name' => (string) ($body['account_name'] ?? ''),
            'authorized_by_user_id' => $this->requireInt($body, 'authorized_by_user_id'),
            'authorized_by_name' => (string) ($body['authorized_by_name'] ?? ''),
        ];
    }

    /**
     * Hand back every grant this app holds on an account.
     *
     * Revoking in Store Syria alone would leave Lynomia still trusting this app with the
     * merchant's workspace -- the mapping would be gone while the access remained. Idempotent
     * upstream: revoking a grant that is already gone succeeds.
     */
    public function revokeAccountLink(int $accountId): void
    {
        $this->send('delete', "/platform/api/v1/account_links/{$accountId}");
    }

    /**
     * Create a Lynomia user. See the class-level warning: with an email that already exists
     * upstream this attaches that existing user to this app. Only ever call it for an address
     * whose control has been established on the Store Syria side.
     *
     * @return array{id:int,email:string}
     */
    public function createUser(string $name, string $email, string $password, array $customAttributes = []): array
    {
        $payload = [
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ];

        if ($customAttributes !== []) {
            $payload['custom_attributes'] = $customAttributes;
        }

        $body = $this->send('post', '/platform/api/v1/users', $payload);

        return [
            'id' => $this->requireInt($body, 'id'),
            'email' => (string) ($body['email'] ?? $email),
            // UPSTREAM APPLIES THE PARAMS ONLY WHEN IT CREATES. For an address that already
            // exists it returns the EXISTING user untouched, so these attributes come back
            // only if this integration is the one that made it. That is what lets the caller
            // tell "we created this" from "somebody got here first" -- see AccountProvisioner.
            'custom_attributes' => (array) ($body['custom_attributes'] ?? []),
        ];
    }

    /**
     * Attach a user to an account with a role. Idempotent upstream:
     * AccountUsersController#create uses find_or_initialize_by(user_id:) then update!, so
     * re-sending the same pair changes the role rather than duplicating the membership.
     */
    public function attachAccountUser(int $accountId, int $userId, string $role = 'administrator'): void
    {
        $this->send('post', "/platform/api/v1/accounts/{$accountId}/account_users", [
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    /**
     * Mint a one-time SSO URL for a user, confined to one workspace.
     *
     * Upstream the token is SecureRandom.hex(32) held in Redis for 5 minutes and invalidated
     * the moment it is redeemed (app/models/concerns/sso_authenticatable.rb,
     * devise_overrides/sessions_controller.rb). Treat the URL as a single-use secret: hand it
     * straight to the browser as a redirect and never persist or log it.
     *
     * THE ACCOUNT IS NOT OPTIONAL HERE, although it is optional upstream. An unscoped link
     * signs the person in as themselves EVERYWHERE -- every Lynomia account that user belongs
     * to, which for an identity this integration created is one today and need not stay one.
     * Upstream keeps the parameter optional because the OAuth and SAML callbacks mint a token
     * before any account has been chosen; this integration always knows the workspace, so it
     * always says so, and a caller who forgot cannot compile.
     *
     * The scope is VERIFIED upstream, not trusted: the app must already hold a permissible on
     * the account and the user must be a member of it, and failing either is a refusal rather
     * than a quiet downgrade to an unscoped link.
     */
    public function issueSsoUrl(int $userId, int $accountId): string
    {
        $body = $this->send('get', "/platform/api/v1/users/{$userId}/login", ['account_id' => $accountId]);

        $url = $body['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_INVALID_RESPONSE,
                'Lynomia did not return an SSO url.'
            );
        }

        return $url;
    }

    /**
     * A credential this integration may hold to act AS one merchant person, in ONE workspace.
     *
     * WHY NOT THE PERSON'S PERSONAL TOKEN. Every Lynomia user has one, it never expires, and --
     * measured on this fork before the account scope existed -- it reached every account that
     * person belonged to. For a server acting on a merchant's behalf that is the wrong shape
     * twice over: too broad, and permanent.
     *
     * WHY NOT AN SSO LINK. This integration can already mint one and it is account-scoped, but
     * it is a five-minute single-use artefact that creates a browser SESSION. Redeeming one per
     * request would burn a device slot each time and evict the merchant's own browser session
     * once they reached max_number_of_devices -- signing a person out of their own workspace to
     * service a background call.
     *
     * Lynomia verifies the pairing before honouring it: this app must already hold the account
     * and the person must be a member of it, so this is a request for a boundary rather than an
     * assertion of one.
     *
     * @return array{token:string,account_id:int,expires_at:?string}
     */
    public function issueScopedAccessToken(int $userId, int $accountId, ?int $lifetimeSeconds = null): array
    {
        $payload = array_filter([
            'account_id' => $accountId,
            'expires_in_seconds' => $lifetimeSeconds,
        ], static fn ($value) => $value !== null);

        $body = $this->send('post', "/platform/api/v1/users/{$userId}/scoped_access_token", $payload);

        $token = $body['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_INVALID_RESPONSE,
                'Lynomia did not return a scoped access token.'
            );
        }

        return [
            'token' => $token,
            'account_id' => $this->requireInt($body, 'account_id'),
            'expires_at' => isset($body['expires_at']) ? (string) $body['expires_at'] : null,
        ];
    }

    // ------------------------------------------------------------------ channel exclusivity

    /**
     * Claim a channel identity for one of this integration's workspaces.
     *
     * THE REGISTRY LIVES IN LYNOMIA, NOT HERE, and that is the whole point. Inboxes are created
     * in Lynomia through nine different paths -- the agent API, five OAuth callbacks, two
     * WhatsApp services and the widget onboarding service -- and a registry kept only on this
     * side would be consulted by none of them. A merchant with an administrator identity could
     * connect a number in Lynomia directly and Store Syria would never hear about it. The claim
     * has to be where the creation is.
     *
     * Lynomia normalizes the identity; this sends what the merchant typed. Two spellings of one
     * phone number must collide, and deciding that here would mean two implementations of the
     * same rule.
     *
     * @return array{claimed:bool, held_by:?string}
     */
    public function claimChannelIdentity(int $accountId, string $provider, string $identity, ?string $ownerRef = null): array
    {
        try {
            $this->send('post', '/platform/api/v1/channel_claims', array_filter([
                'account_id' => $accountId,
                'provider' => $provider,
                'identity' => $identity,
                'external_owner_ref' => $ownerRef,
            ], static fn ($value) => $value !== null), retryTransport: false);

            return ['claimed' => true, 'held_by' => null];
        } catch (LynomiaApiException $e) {
            // A conflict is an ANSWER, not a failure: another store holds this channel, and the
            // merchant needs to be told which one rather than shown an error.
            if ($e->kind === LynomiaApiException::KIND_CONFLICT && $e->status === 409) {
                return ['claimed' => false, 'held_by' => $e->heldBy];
            }

            throw $e;
        }
    }

    public function releaseChannelIdentity(string $provider, string $identity): bool
    {
        $body = $this->send('delete', '/platform/api/v1/channel_claims', [
            'provider' => $provider,
            'identity' => $identity,
        ]);

        return (bool) ($body['released'] ?? false);
    }

    /** @return list<array{provider:string,identity:string,account_id:int,external_owner_ref:?string}> */
    public function listChannelClaims(?string $ownerRef = null): array
    {
        $body = $this->send('get', '/platform/api/v1/channel_claims', array_filter([
            'external_owner_ref' => $ownerRef,
        ], static fn ($value) => $value !== null));

        return array_values(array_filter((array) ($body['claims'] ?? []), 'is_array'));
    }

    // ------------------------------------------------------------------ included seats

    /**
     * Tell Lynomia how many seats this merchant's Store Syria package includes.
     *
     * A GRANT, NOT A SUBSCRIPTION. Lynomia composes this with whatever else the account has -- an
     * independent plan it bought directly, an operator's override -- by taking the HIGHEST, so
     * this can raise a merchant's ceiling and can never lower one they paid for separately. And
     * because it is not a subscription, there is no second place the merchant could be charged
     * for seats their Store Syria package already covers.
     */
    public function grantEntitlement(int $accountId, string $resource, int $limit, ?string $externalRef = null): array
    {
        return $this->send('put', "/platform/api/v1/accounts/{$accountId}/entitlement_grants", array_filter([
            'resource' => $resource,
            'limit_value' => $limit,
            'external_ref' => $externalRef,
        ], static fn ($value) => $value !== null));
    }

    /**
     * Stop including seats. The merchant keeps every member they already have -- Lynomia does not
     * remove anybody when a ceiling goes away -- so this is a change to what happens NEXT, not a
     * downgrade that deletes people.
     */
    public function withdrawEntitlementGrant(int $accountId, string $resource): bool
    {
        $body = $this->send('delete', "/platform/api/v1/accounts/{$accountId}/entitlement_grants", [
            'resource' => $resource,
        ]);

        return (bool) ($body['withdrawn'] ?? false);
    }

    /**
     * What is actually enforced for this workspace, which layer decided it, and how much is used.
     *
     * THE EFFECTIVE NUMBER, NOT OUR GRANT. Showing a merchant "3 of 5 seats" from the Store Syria
     * plan alone would be wrong whenever the account also has its own Lynomia plan or an operator
     * override -- the number that refuses the next invitation is Lynomia's, so that is the number
     * to display.
     *
     * @return array{limit:?int, source:?string, used:?int}
     */
    public function effectiveEntitlement(int $accountId, string $resource = 'agents'): array
    {
        $body = $this->send('get', "/platform/api/v1/accounts/{$accountId}/entitlement_grants");
        $effective = $body['effective'][$resource] ?? [];

        return [
            'limit' => isset($effective['limit']) ? (int) $effective['limit'] : null,
            'source' => isset($effective['source']) ? (string) $effective['source'] : null,
            'used' => isset($effective['used']) ? (int) $effective['used'] : null,
        ];
    }

    // ------------------------------------------------------------------ transport

    /**
     * Speak to Lynomia as the INTEGRATION.
     *
     * The transport itself lives in LynomiaHttp, shared with AgentApiClient, which speaks as a
     * merchant person with a workspace-scoped token. What stays here is the part that is
     * specific to this credential: the readiness check, and the fact that the platform token is
     * read from the environment on every call and never held anywhere else.
     *
     * @param bool $retryTransport see LynomiaHttp::send
     */
    /**
     * Report one consent decision a customer gave this store.
     *
     * OVER THE PLATFORM CREDENTIAL, not a merchant's, because consent sync is system to system:
     * it happens when nobody is signed in, and binding it to a person would make it stop
     * working the moment that person left the company.
     *
     * The response is the ANSWER, not an acknowledgement -- `outcome` says whether Lynomia
     * applied the decision, ignored it as a duplicate, found it superseded, or refused it
     * because the customer had already withdrawn. A caller that treats a 201 as success and
     * discards the body loses the one fact a merchant needs.
     */
    public function recordPartnerConsent(int $accountId, array $decision): array
    {
        return $this->send('post', "/platform/api/v1/accounts/{$accountId}/partner_consents", $decision);
    }

    /**
     * What Lynomia currently holds for one installation, for reconciliation.
     *
     * BOTH COLLECTIONS, and they are kept apart rather than merged. `consents` are the
     * decisions this partner reported; `native` are the ones Lynomia recorded itself -- a
     * shopper replying STOP on WhatsApp, an agent's record, an operator's suppression -- and
     * those are the ones a reconciliation cannot do without, because a view containing only
     * what we told Lynomia would converge happily with every opt-out missing.
     *
     * NOT MERGED because `revision` counts in a different sequence in each: a
     * partner_consent_events id and a commerce_messaging_consents id. Ordering across them
     * decides nothing, which is why they are projected under different origins.
     *
     * @return array{consents: array<int, array>, native: array<int, array>}
     */
    public function partnerConsents(int $accountId, string $externalStoreId, string $provider = 'storesyria'): array
    {
        $body = $this->send('get', "/platform/api/v1/accounts/{$accountId}/partner_consents", [
            'provider' => $provider,
            'external_store_id' => $externalStoreId,
        ]);

        return [
            'consents' => is_array($body['consents'] ?? null) ? $body['consents'] : [],
            'native' => is_array($body['native'] ?? null) ? $body['native'] : [],
            // WHETHER THE NATIVE LIST IS THE WHOLE TRUTH. Both collections are capped
            // upstream at 200, newest first, because this read is the safety net behind the
            // event feed rather than a full export. ConsentReconciler deletes a projected
            // native row that is ABSENT here -- that is how a cleared suppression, which
            // destroys its row and publishes nothing, is learned -- so it may only do that
            // when the list is complete. Defaulting to FALSE is the safe direction: an
            // upstream that does not send the flag gets no pruning rather than silent
            // deletion of everything past the cap.
            'native_complete' => ($body['native_complete'] ?? false) === true,
        ];
    }

    private function send(string $method, string $path, array $payload = [], bool $retryTransport = true): array
    {
        $this->assertUsable();

        $http = new LynomiaHttp(
            (string) $this->config['base_url'],
            (string) $this->config['platform_token'],
            (array) ($this->config['http'] ?? [])
        );

        return $http->send($method, $path, $payload, $retryTransport);
    }

    private function assertUsable(): void
    {
        if (! $this->isEnabled()) {
            throw LynomiaApiException::disabled();
        }

        if (blank($this->config['base_url'] ?? null)) {
            throw LynomiaApiException::notConfigured('LYNOMIA_BASE_URL');
        }

        if (blank($this->config['platform_token'] ?? null)) {
            throw LynomiaApiException::notConfigured('LYNOMIA_PLATFORM_TOKEN');
        }
    }

    private function requireInt(array $body, string $key): int
    {
        // Upstream renders the account/user through a jbuilder view, so the id may arrive at
        // the root or nested under the resource name depending on the view. Accept both
        // rather than guess, and fail loudly when neither is present.
        $value = $body[$key] ?? ($body['payload'][$key] ?? null);

        if (! is_numeric($value)) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_INVALID_RESPONSE,
                "Lynomia response did not contain a numeric `{$key}`."
            );
        }

        return (int) $value;
    }
}
