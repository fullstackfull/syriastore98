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

    /** @var null|callable(string, array<int, mixed>): mixed test seam: replaces curl_exec() */
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
     */
    public static function send(string $url, $payload, string $method)
    {
        $plan = self::plan($url, $method);

        if ($plan === null) {
            return null;
        }

        [$pinned, $options] = $plan;
        $options[CURLOPT_POSTFIELDS] = $payload;

        if (self::$transport !== null) {
            return (self::$transport)($pinned, $options);
        }

        $handle = curl_init($pinned);
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        curl_close($handle);

        return $response;
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
