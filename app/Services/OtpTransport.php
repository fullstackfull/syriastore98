<?php

namespace App\Services;

/**
 * The ONE place a merchant-configured OTP provider URL becomes a network request. P1-SEC-55.
 *
 * ==============================================================================================
 * WHY THIS EXISTS, measured rather than argued
 * ==============================================================================================
 * P1-SEC-45 guarded ONE of the four outbound sends of a store's OTP `api_secret`, and guarded it
 * with the wrong half of a two-part control. A retrospective review of PR #124 on `main`
 * (docs/security/RETROSPECTIVE-REVIEW-PR124.md) measured both halves of that mistake:
 *
 *   1. `SafeWebhook::allows()` IS VERDICT-ONLY. It is `plan() !== null`, a bool. It judges the
 *      initial host and then returns, pinning nothing and handing the caller no pinned URL. The
 *      controller then issued its own `Http::asMultipart()->post()`, with no `CURLOPT_RESOLVE`
 *      pin and no `allow_redirects => false`. Laravel sets no redirect options, so Guzzle
 *      follows up to five, and A 307 PRESERVES METHOD AND BODY.
 *
 *      MEASURED in the booted application with that exact idiom: a first hop answered
 *      `307 Location: <second hop>`, the client FOLLOWED it, and delivered the identical
 *      562-byte multipart body -- INCLUDING THE `secret` FIELD -- to the second hop. So a public
 *      host that passes `allows()` could 307-redirect the store's credential to an internal or
 *      metadata address that `allows()` itself refuses. DNS rebinding is the same hole by a
 *      second route: `allows()` resolves the name, then the HTTP client resolves it again.
 *
 *   2. THE GUARDED SEND WAS NEVER THE ONLY SEND. A census of `api_secret` across `app/` and
 *      `packages/` finds exactly four outbound sends: two in `OtpIntegrationController` and two
 *      in `CustomerLoginController`, and the latter two had no guard of any kind. The trigger
 *      route for those carries NO AUTH, so an unauthenticated POST caused the platform to send
 *      the store's own OTP secret to the stored address -- and reflected transport errors back,
 *      naming internal ports. An anonymous internal-network oracle.
 *
 * ==============================================================================================
 * WHAT THIS DOES INSTEAD
 * ==============================================================================================
 * Every OTP send goes through `SafeWebhook::send()`, which is the half that actually protects:
 * it judges the address through `SafeRemoteFetch::check` (http/https only, no credentials in the
 * URL, no private, loopback, link-local or metadata address on ANY of the host's records, no
 * numeric host spelling), PINS the connection to the address it judged via `CURLOPT_RESOLVE`,
 * and sets `CURLOPT_FOLLOWLOCATION => false` so a redirect cannot move the request at all.
 *
 * THE FULL URL IS JUDGED, NOT THE BASE. The previous code judged `$baseUrl` and then requested
 * `$baseUrl . '/api/send/sms'`, so what was judged was not what was requested. `pinnedUrl()`
 * preserves the path and query, so passing the complete URL makes the two the same string.
 *
 * MULTIPART WITHOUT GUZZLE. `SafeWebhook::send()` hands its payload to `CURLOPT_POSTFIELDS`, and
 * cURL builds a `multipart/form-data` body with its own boundary when given an array. So the
 * provider's wire format is unchanged -- the same field names, the same values -- and no
 * legitimate SMS or WhatsApp send behaves differently. What changes is only where the request is
 * allowed to go.
 *
 * TRANSPORT DETAIL NEVER LEAVES THIS CLASS. `send()` answers `null` when it refused and `false`
 * when cURL failed; neither carries text a caller could surface. The only message this class
 * ever returns is the PROVIDER's own `message` field, parsed out of its JSON body. A cURL error
 * string, a connection refusal naming a port, or a remote service banner cannot reach a caller
 * through here, which is what closes the anonymous oracle.
 */
final class OtpTransport
{
    /** The address was refused before anything was sent. */
    public const REFUSED = 'refused';

    /** The request was attempted and the transport failed. Deliberately carries NO detail. */
    public const FAILED = 'failed';

    /** The provider answered. `status` and `provider_message` are its own. */
    public const ANSWERED = 'answered';

    /**
     * IS THIS A SUCCESSFUL SEND? Both halves, exactly as before P1-SEC-55.
     *
     * The original condition at both call sites was
     * `$response->successful() && ($result['status'] ?? null) == 200` -- Laravel's HTTP-level 2xx
     * AND the provider's own `status` field. Moving the sends onto `SafeWebhook::send()` kept the
     * second and dropped the first, because that method answers the body alone. The owner caught
     * it: a provider answering an HTTP error while its body claimed `status: 200` began to
     * succeed where it had failed.
     *
     * Restored here rather than at the call sites so the two cannot disagree -- the previous
     * version of this had the same condition written out twice in two controllers, which is how
     * one of them would eventually have been updated alone.
     *
     * `http_status` is null when there was no HTTP response at all, and that is NOT success.
     * `$response->successful()` could never see such a case (Guzzle would have thrown), so
     * treating it as a failure is the faithful reading, and it fails CLOSED.
     *
     * @param  array{outcome: string, status: int|null, http_status: int|null, provider_message: string|null}  $sent
     */
    public static function succeeded(array $sent): bool
    {
        if (($sent['outcome'] ?? null) !== self::ANSWERED) {
            return false;
        }

        $http = $sent['http_status'] ?? null;

        // Laravel's `successful()` is exactly `status >= 200 && status < 300`.
        if (! is_int($http) || $http < 200 || $http >= 300) {
            return false;
        }

        return ($sent['status'] ?? null) === 200;
    }

    /**
     * @param  string  $baseUrl  the merchant-configured provider base, e.g. https://sms.example.com
     * @param  string  $path     the provider endpoint, e.g. /api/send/sms -- a literal, never user input
     * @param  array<int, array{name?: string, contents?: mixed}>  $multipart  Guzzle-shaped parts,
     *                                                             which is the shape both callers already build
     * @return array{outcome: string, status: int|null, http_status: int|null, provider_message: string|null}
     */
    public static function post(string $baseUrl, string $path, array $multipart): array
    {
        $url = rtrim($baseUrl, '/') . $path;

        /*
         * `sendWithStatus()` rather than `send()`, so the HTTP status survives. It is the SAME
         * protected send -- one `plan()`, so the same `SafeRemoteFetch::check` judgement, the same
         * `CURLOPT_RESOLVE` pin, the same `CURLOPT_FOLLOWLOCATION => false`. Only the amount of
         * the answer handed back differs. `send()` keeps its old contract for its other caller.
         */
        $result = SafeWebhook::sendWithStatus($url, self::fields($multipart), 'POST');

        if ($result === null) {
            // Refused by SafeRemoteFetch::check. NOTHING was sent, so no secret left the server.
            return self::outcome(self::REFUSED);
        }

        $response = $result['body'];
        $httpStatus = $result['status'];

        if ($response === false) {
            // cURL failed. The error text is NOT propagated: it is the thing that turned this
            // endpoint into a port scanner ("cURL error 7: ... port 6379 ... Connection refused").
            return self::outcome(self::FAILED, null, $httpStatus);
        }

        $decoded = json_decode((string) $response, true);

        if (! is_array($decoded)) {
            // A body that is not the provider's JSON contract is not a message to repeat. A
            // remote service banner arrives exactly this way. The HTTP status is still reported,
            // because the caller's success test needs it and it carries no remote text.
            return self::outcome(self::ANSWERED, null, $httpStatus);
        }

        $status = $decoded['status'] ?? null;
        $message = $decoded['message'] ?? null;

        return self::outcome(
            self::ANSWERED,
            is_numeric($status) ? (int) $status : null,
            $httpStatus,
            is_string($message) ? $message : null
        );
    }

    /**
     * One builder for the result shape, so a new key cannot be added to three of four returns.
     *
     * @return array{outcome: string, status: int|null, http_status: int|null, provider_message: string|null}
     */
    private static function outcome(
        string $outcome,
        ?int $status = null,
        ?int $httpStatus = null,
        ?string $providerMessage = null
    ): array {
        return [
            'outcome' => $outcome,
            'status' => $status,
            'http_status' => $httpStatus,
            'provider_message' => $providerMessage,
        ];
    }

    /**
     * Guzzle's multipart shape -> the `field => value` array cURL turns into multipart/form-data.
     *
     * A part with no `name` is dropped rather than sent under an empty key, and every value is
     * cast to string because `CURLOPT_POSTFIELDS` with a non-scalar member is a cURL error
     * rather than a send.
     *
     * @param  array<int, array{name?: string, contents?: mixed}>  $multipart
     * @return array<string, string>
     */
    private static function fields(array $multipart): array
    {
        $fields = [];

        foreach ($multipart as $part) {
            if (! is_array($part) || ! isset($part['name']) || $part['name'] === '') {
                continue;
            }

            $contents = $part['contents'] ?? '';

            if (is_scalar($contents) || $contents === null) {
                $fields[(string) $part['name']] = (string) $contents;
            }
        }

        return $fields;
    }
}
