<?php

namespace App\Services;

use RuntimeException;

/**
 * The one place a merchant-configured webhook URL becomes a network request.  P1-SEC-33.
 *
 * `Utility::WebhookCall` used to `curl_init($url)` with the URL and the HTTP method straight from the `webhooks` row. cURL speaks gopher,
 * dict, ftp, file, smtp and telnet, so `gopher://127.0.0.1:6379/_<RESP commands>` was a raw TCP write to anything listening on the server's
 * own network; a method containing CRLF injected request lines; and there was no timeout. The response is never returned to the caller, so
 * this was blind -- but WRITE-capable, which is the dangerous half.
 *
 * What it does instead: judge the address exactly as the importers' image fetch does (SafeRemoteFetch::check -- http/https only, no
 * credentials in the URL, no private/loopback/link-local/metadata address on ANY of its records, no numeric host spelling; any PORT is
 * allowed here, a receiver on :5678 is ordinary), pin the connection to the address that was judged, allow only the protocols http and https
 * (also after a redirect, which is not followed anyway), accept only the two methods the settings form offers, and bound the time.
 *
 * A webhook aimed at a private network can therefore no longer be delivered. Allowing one would be an owner decision (an allowlist).
 */
final class SafeWebhook
{
    public const METHODS = ['GET', 'POST'];

    /**
     * @var null|callable(string, array<int, mixed>): mixed test seam: replaces curl_exec()
     *
     * TWO ACCEPTED RETURN SHAPES, and the second is additive:
     *   - a scalar (a body string, or `false` for a transport failure) -- what every seam
     *     returned before `sendWithStatus()` existed, and still the whole contract for `send()`;
     *   - `['body' => string|false, 'status' => int|null]`, which also reports the HTTP status
     *     code. Recognised ONLY by the presence of a `body` key, so a body that happens to be an
     *     array cannot be mistaken for metadata.
     * A seam that returns a scalar reports NO status, and `sendWithStatus()` answers `null` for
     * it -- which `OtpTransport` treats as "not a successful HTTP response". That is deliberate:
     * a seam that forgets the status makes a positive test FAIL rather than pass leniently.
     */
    private static $transport = null;

    public static function transportUsing(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /** The settings form's own rule, shared so the form and the sender cannot drift apart. */
    public static function allows(string $url, string $method): bool
    {
        return self::plan($url, $method) !== null;
    }

    /**
     * @param  mixed  $payload  what CURLOPT_POSTFIELDS takes (the callers pass a JSON string)
     * @return mixed|null the response body, `false` when cURL failed; null when the request was refused and NOTHING was sent
     *
     * UNCHANGED CONTRACT. This is now a thin projection of `sendWithStatus()` so the two cannot
     * drift, and it returns exactly what it returned before: the body, `false`, or `null`. The
     * only other caller in the application -- `Utility::WebhookCall` at Utility.php:2119 -- sees
     * no difference, which is the point.
     */
    public static function send(string $url, $payload, string $method)
    {
        $result = self::sendWithStatus($url, $payload, $method);

        return $result === null ? null : $result['body'];
    }

    /**
     * THE SAME PROTECTED SEND, WITH THE HTTP STATUS CODE. Opt-in; nothing else need use it.
     *
     * ==========================================================================================
     * WHY THIS EXISTS: a regression the owner caught in PR #128
     * ==========================================================================================
     * The OTP sends used to decide success as `$response->successful() && ($result['status'] ??
     * null) == 200` -- Laravel's HTTP-level 2xx AND the provider's own `status` field. Moving
     * them onto `send()` kept the second test and silently dropped the first, because `send()`
     * answers the BODY and the HTTP code was discarded with the cURL handle. One case changed
     * behaviour: a provider answering an HTTP error while its body claims `status: 200` began to
     * SUCCEED where it previously failed. The owner's direction was to preserve the original
     * dual condition without weakening DNS pinning, redirect refusal or authorization, and
     * without changing the semantics of other `SafeWebhook` callers.
     *
     * So the status is returned ALONGSIDE the body rather than `send()` being redefined.
     * `CURLINFO_RESPONSE_CODE` is read from the handle before it is released. It is `0` when no
     * HTTP response was received at all (DNS failure, refused connection, timeout), and `0` is
     * reported as `null` rather than as a status -- "no response" is not a status code, and a
     * caller comparing against a 2xx range must not read 0 as one.
     *
     * Every protection is the same single `plan()`, so this cannot become a second, laxer path:
     * `SafeRemoteFetch::check` judges, `CURLOPT_RESOLVE` pins the connection to the address that
     * was judged, `CURLOPT_FOLLOWLOCATION => false` refuses redirects, and the protocol and
     * timeout bounds are identical. The only difference between the two methods is how much of
     * the answer is handed back.
     *
     * @param  mixed  $payload
     * @return array{body: string|false, status: int|null}|null  null when the request was REFUSED
     *                and nothing was sent; `body === false` when the transport failed; `status`
     *                is the HTTP response code, or null when there was no HTTP response.
     */
    public static function sendWithStatus(string $url, $payload, string $method): ?array
    {
        $plan = self::plan($url, $method);

        if ($plan === null) {
            return null;
        }

        [$pinned, $options] = $plan;
        $options[CURLOPT_POSTFIELDS] = $payload;

        if (self::$transport !== null) {
            $raw = (self::$transport)($pinned, $options);

            // The metadata shape is recognised by the `body` KEY, never by being an array, so a
            // seam returning an array body cannot be misread as a status report.
            if (is_array($raw) && array_key_exists('body', $raw)) {
                return [
                    'body' => $raw['body'],
                    'status' => isset($raw['status']) && is_numeric($raw['status']) && (int) $raw['status'] !== 0
                        ? (int) $raw['status']
                        : null,
                ];
            }

            return ['body' => $raw, 'status' => null];
        }

        $handle = curl_init($pinned);
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['body' => $response, 'status' => $code === 0 ? null : $code];
    }

    /** @return array{0: string, 1: array<int, mixed>}|null the URL to request and its cURL options, or null when the webhook is refused */
    private static function plan(string $url, string $method): ?array
    {
        $method = strtoupper($method);

        if (! in_array($method, self::METHODS, true)) {
            return null;
        }

        $url = str_replace("\0", '', trim($url));

        try {
            $target = SafeRemoteFetch::check($url, true);
        } catch (RuntimeException $e) {
            return null;
        }

        return [SafeRemoteFetch::pinnedUrl($url, $target), [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [SafeRemoteFetch::resolveEntry($target)],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]];
    }
}
