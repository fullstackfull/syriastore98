<?php

namespace Workdo\LynomiaChat\app\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
 *   POST   /platform/api/v1/users                         create a user  (see the warning below)
 *   POST   /platform/api/v1/accounts/{id}/account_users   attach a member, idempotent upstream
 *   GET    /platform/api/v1/users/{id}/login              mint a one-time SSO URL
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
 * AccountProvisioner, only for an address the merchant has proven control of on the Store
 * Syria side, and never from the existing-account path -- AccountLinker refuses to call it.
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

        $body = $this->send('post', '/platform/api/v1/accounts', $payload);

        return [
            'id' => $this->requireInt($body, 'id'),
            'name' => (string) ($body['name'] ?? $name),
        ];
    }

    /**
     * Create a Lynomia user. See the class-level warning: with an email that already exists
     * upstream this attaches that existing user to this app. Only ever call it for an address
     * whose control has been established on the Store Syria side.
     *
     * @return array{id:int,email:string}
     */
    public function createUser(string $name, string $email, string $password): array
    {
        $body = $this->send('post', '/platform/api/v1/users', [
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        return [
            'id' => $this->requireInt($body, 'id'),
            'email' => (string) ($body['email'] ?? $email),
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
     * Mint a one-time SSO URL for a user.
     *
     * Upstream the token is SecureRandom.hex(32) held in Redis for 5 minutes and invalidated
     * the moment it is redeemed (app/models/concerns/sso_authenticatable.rb,
     * devise_overrides/sessions_controller.rb). Treat the URL as a single-use secret: hand it
     * straight to the browser as a redirect and never persist or log it.
     */
    public function issueSsoUrl(int $userId): string
    {
        $body = $this->send('get', "/platform/api/v1/users/{$userId}/login");

        $url = $body['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_INVALID_RESPONSE,
                'Lynomia did not return an SSO url.'
            );
        }

        return $url;
    }

    // ------------------------------------------------------------------ transport

    private function send(string $method, string $path, array $payload = []): array
    {
        $this->assertUsable();

        $request = $this->request();

        try {
            /** @var Response $response */
            $response = $method === 'get'
                ? $request->get($path)
                : $request->{$method}($path, $payload);
        } catch (ConnectionException $e) {
            // The message can name the host but never a credential: the token travels in a
            // header, which Laravel does not put in a ConnectionException message.
            throw new LynomiaApiException(
                LynomiaApiException::KIND_UNREACHABLE,
                'Could not reach Lynomia: ' . $e->getMessage()
            );
        }

        if ($response->failed()) {
            throw LynomiaApiException::fromStatus($response->status());
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_INVALID_RESPONSE,
                'Lynomia returned a non-JSON body.',
                $response->status()
            );
        }

        return $decoded;
    }

    private function request(): PendingRequest
    {
        $http = $this->config['http'] ?? [];

        return Http::baseUrl(rtrim((string) $this->config['base_url'], '/'))
            ->withHeaders(['api_access_token' => (string) $this->config['platform_token']])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($http['connect_timeout'] ?? 5))
            ->timeout((int) ($http['timeout'] ?? 10))
            // Retry only the transport. A 4xx is never retried -- re-sending a rejected
            // create would be how duplicate accounts appear.
            ->retry(
                max(1, (int) ($http['retries'] ?? 2)),
                (int) ($http['retry_delay_ms'] ?? 250),
                fn ($exception) => $exception instanceof ConnectionException,
                throw: false
            );
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
