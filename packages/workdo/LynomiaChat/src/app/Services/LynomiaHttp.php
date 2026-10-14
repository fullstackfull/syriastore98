<?php

namespace Workdo\LynomiaChat\app\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;

/**
 * One way of speaking to Lynomia, used by every client that does.
 *
 * EXTRACTED BECAUSE THERE ARE NOW TWO CALLERS WITH TWO CREDENTIALS, not because transport code
 * is pretty. PlatformApiClient speaks as the INTEGRATION, with the platform token from the
 * environment, and may create workspaces and users. AgentApiClient speaks as ONE MERCHANT
 * PERSON, with a short-lived token confined to one workspace, and may only do what that person
 * may do. They differ in exactly one thing -- which bearer goes in the header -- and everything
 * else about talking to Lynomia is identical: the base URL, the timeouts, which failures may be
 * retried, how a non-JSON body is treated, and the rule that a provider's own sentence is never
 * shown to a merchant.
 *
 * Copying that into a second class would have left two definitions of "what a Lynomia failure
 * means", and the one that drifted would have been the merchant-facing one.
 *
 * THE TOKEN IS A CONSTRUCTOR ARGUMENT AND IS NEVER LOGGED. It travels in a header, which Laravel
 * does not put into a ConnectionException message, so the error paths below cannot leak it.
 */
class LynomiaHttp
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly array $httpConfig = []
    ) {
    }

    /**
     * @param bool $retryTransport Whether a dropped connection may be retried.
     *
     *   RETRYING IS ONLY SAFE FOR A CALL WHOSE SECOND EXECUTION IS A NO-OP. A dropped
     *   connection does not mean the server did nothing -- a read timeout is the case where
     *   it did everything and the answer was lost on the way back. Retrying POST /accounts
     *   there creates a SECOND workspace, which is precisely the duplicate this integration
     *   must not produce, so account creation passes false and the caller reconciles instead.
     *   Sending a MESSAGE is the same shape of hazard and passes false for the same reason:
     *   a retried reply is a second reply the merchant did not write, visible to the customer.
     *   The idempotent calls -- a user keyed by a deterministic address, an account_user
     *   upsert, a GET -- pass true, because running them twice lands in the same place.
     */
    public function send(string $method, string $path, array $payload = [], bool $retryTransport = true): array
    {
        $request = $this->request($retryTransport);

        try {
            /** @var Response $response */
            $response = in_array($method, ['get', 'delete'], true) && $payload === []
                ? $request->{$method}($path)
                : $request->{$method}($path, $payload);
        } catch (ConnectionException $e) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_UNREACHABLE,
                'Could not reach Lynomia: ' . $e->getMessage()
            );
        }

        if ($response->failed()) {
            // THE CODE, NEVER THE PROSE. A rejected link code has two causes a merchant can
            // act on differently -- a dead code, and a code minted for a different integration
            // -- and they are only distinguishable from the body. What is read out of it is a
            // short machine-readable token that this integration defines on both sides; the
            // provider's sentence is still never shown to anyone (see LynomiaApiException).
            throw LynomiaApiException::fromStatus($response->status(), $this->errorCode($response));
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

    private function request(bool $retryTransport): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders(['api_access_token' => $this->token])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($this->httpConfig['connect_timeout'] ?? 5))
            ->timeout((int) ($this->httpConfig['timeout'] ?? 10));

        if (! $retryTransport) {
            return $request;
        }

        // Transport only, and only where a second execution is a no-op. A 4xx is never
        // retried: re-sending a rejected create is another way duplicates appear.
        return $request->retry(
            max(1, (int) ($this->httpConfig['retries'] ?? 2)),
            (int) ($this->httpConfig['retry_delay_ms'] ?? 250),
            fn ($exception) => $exception instanceof ConnectionException,
            throw: false
        );
    }

    private function errorCode(Response $response): ?string
    {
        $code = $response->json('error_code');

        return is_string($code) && $code !== '' ? $code : null;
    }
}
