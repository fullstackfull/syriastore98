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
