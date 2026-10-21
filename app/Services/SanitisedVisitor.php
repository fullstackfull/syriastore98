<?php

namespace App\Services;

use Shetabit\Visitor\Visitor;

/**
 * The storefront visit logger, minus the parts that must never reach a database. P1-SEC-13.
 *
 * `Shetabit\Visitor\Visitor::visit()` persists `prepareLog()`, which includes the request's full
 * input (`request()`) and every header (`httpHeaders()`), and its own filter (`config/visitor.php`
 * `except`) matches bare paths only -- never `{storeSlug}/login`. So a customer's plaintext password,
 * their Cookie header, a reset token and the card fields of three payment gateways were stored in
 * `shetabit_visits` on every themed storefront request.
 *
 * The package is vendored, so this narrows it by subclassing and re-binding `shetabit-visitor` in
 * AppServiceProvider. The analytics screens read only store_id, url, ip, platform, browser, device and
 * created_at (grep Api\DashboardController, ThemeAnalyticController, HomeController, StoreController);
 * nothing reads `request` or `headers`.
 *
 *  - request():     [] -- a page-view counter has no use for what was typed.
 *  - httpHeaders(): an ALLOWLIST. A denylist (cookie, authorization ...) is exactly the shape that
 *                   misses the header nobody thought of.
 *  - url()/referer(): the query string is kept (analytics group by it) with the values of
 *                   credential-looking parameters replaced, because a password-reset link carries its
 *                   token and the address in the query.
 */
class SanitisedVisitor extends Visitor
{
    /**
     * Headers that identify a client, never a session. `referer` is deliberately NOT here: its value is
     * a full URL that can carry the token of the page the visitor came from, and the `referer` COLUMN
     * already holds it, redacted. (The first version kept it and a test caught the token.)
     */
    private const HEADERS_KEPT = [
        'user-agent', 'accept', 'accept-language', 'accept-encoding', 'host', 'origin',
        'x-requested-with', 'content-type', 'content-length',
    ];

    /**
     * A query parameter whose NAME contains one of these has its value replaced. Substring match, so
     * `pass` covers password/passwd, `sig` covers signature, `mail` covers email -- no alternative here
     * is listed that another already contains (the removal check showed those could not be isolated).
     */
    private const SENSITIVE_PARAMETER = '/token|pass|pwd|otp|code|secret|sig|key|mail|auth|cookie|session|cvv|card|pin/i';

    public function request(): array
    {
        return [];
    }

    public function httpHeaders(): array
    {
        return array_intersect_key(parent::httpHeaders(), array_flip(self::HEADERS_KEPT));
    }

    public function url(): string
    {
        return self::redact(parent::url());
    }

    public function referer(): ?string
    {
        $referer = parent::referer();

        return $referer === null ? null : self::redact($referer);
    }

    /** Replace the VALUE of every credential-looking query parameter; keep the path and the rest. */
    public static function redact(string $url): string
    {
        $fragment = '';

        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        if (($mark = strpos($url, '?')) === false) {
            return $url . $fragment;
        }

        parse_str(substr($url, $mark + 1), $parameters);

        $clean = self::redactParameters($parameters);

        return substr($url, 0, $mark) . ($clean === [] ? '' : '?' . http_build_query($clean)) . $fragment;
    }

    private static function redactParameters(array $parameters): array
    {
        foreach ($parameters as $name => $value) {
            if (preg_match(self::SENSITIVE_PARAMETER, (string) $name)) {
                $parameters[$name] = '[redacted]';
            } elseif (is_array($value)) {
                $parameters[$name] = self::redactParameters($value);
            }
        }

        return $parameters;
    }
}
