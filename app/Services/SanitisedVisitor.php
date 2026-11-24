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
     * WHICH STORE THE VISIT BEING WRITTEN BELONGS TO. ANA — measured 2026-11-20, it was nobody's.
     *
     * `ActiveTheme` calls `visitor()->visit($store)`, but the vendored package records the subject only
     * when the model it is handed has a `visitLogs()` method (`Visitor::visit()`, the `method_exists`
     * branch). `App\Models\Store` has neither that method nor the package's `Visitable` trait, so the
     * call fell through to `Visit::create($data)` and the `$store` argument was DISCARDED: the row
     * carried no `visitable_*` and no `store_id`.
     *
     * Nine first-party queries filter those rows by `store_id` — `Api\DashboardController`'s visitor and
     * unique-visitor counts (twice each), `ThemeAnalyticController`'s urls, device, browser, platform,
     * count and unique IPs, and `HomeController`'s `whereNotNull('store_id')`. Every one of them read
     * nothing for every store and showed **0 visitors** rather than failing: the "fallback that never
     * fires" shape P5-10 recorded.
     *
     * This remembers the attribution for the duration of one `visit()` so the `creating` hook registered
     * in `AppServiceProvider` can fill the column in the SAME insert — no second write, no vendored edit
     * and no migration, since the column has existed since 2024. The store the CALLER named wins, because
     * `ActiveTheme` has already resolved it (custom domain and subdomain included); `getRouteStoreId()` is
     * the fallback, being the documented tenancy-safe answer to "which store is this REQUEST for". It is
     * never `getCurrentStore()`, which reads the web guard and would attribute a shopper's page view to
     * whichever store a merchant happens to be administering.
     */
    private static ?int $attributedStoreId = null;

    /** The store id the visit currently being written belongs to, or null when nothing can name one. */
    public static function attributedStoreId(): ?int
    {
        return self::$attributedStoreId;
    }

    /** @param  mixed  $model  the subject the caller named, if any */
    public function visit($model = null)
    {
        $previous = self::$attributedStoreId;

        self::$attributedStoreId = $model instanceof \App\Models\Store && (int) $model->id > 0
            ? (int) $model->id
            : getRouteStoreId();

        try {
            return parent::visit($model);
        } finally {
            // Restored rather than cleared: a nested call must not leave the outer one unattributed.
            self::$attributedStoreId = $previous;
        }
    }

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
     * `pass` covers password/passwd, `pw` covers pwd, `sess` covers session/sessid/PHPSESSID, `sig`
     * covers signature, `mail` covers email, `cred` covers credential -- no alternative here is listed
     * that another already contains (the removal check showed those could not be isolated).
     */
    private const SENSITIVE_PARAMETER = '/token|pass|pw|otp|code|secret|sig|key|mail|auth|cookie|sess|cvv|cvc|card|pin|hash|nonce|jwt|bearer|cred|ticket|iban|account/i';

    /** Too short to match by substring without hitting innocent names (`inside`, `occurrence`). */
    private const SENSITIVE_EXACT = ['sid', 'cc'];

    /**
     * A value longer than this is not an analytics dimension; it is a payload (the Easebuzz return puts the whole
     * checkout in one). A value that BEGINS `eyJ` is a Laravel ciphertext or a JWT whatever its length --
     * Crypt::encrypt() of a small scalar is exactly 200 characters, so the length rule alone missed it.
     */
    private const MAX_VALUE_LENGTH = 200;

    /** A Laravel Crypt::encrypt() payload is base64 of `{"iv":...`, i.e. it begins `eyJpdiI6`; as a PATH segment it is a capability link. */
    private const ENCRYPTED_SEGMENT = '#/eyJ[A-Za-z0-9%_\-=.]{40,}#';

    public function request(): array
    {
        return [];
    }

    public function httpHeaders(): array
    {
        $kept = array_intersect_key(parent::httpHeaders(), array_flip(self::HEADERS_KEPT));

        // Invalid UTF-8 in a header value makes the `headers` JSON cast throw, which used to lose the
        // visit (and, reported, ship the live cookies to the error tracker). Scrubbed, it is recorded.
        return array_map(fn ($values) => is_array($values) ? array_map(fn ($v) => is_string($v) ? mb_scrub($v) : $v, $values) : $values, $kept);
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
            $fragment = mb_scrub(substr($url, $hash));
            $url = substr($url, 0, $hash);
        }

        $query = null;

        if (($mark = strpos($url, '?')) !== false) {
            $query = substr($url, $mark + 1);
            $url = substr($url, 0, $mark);
        }

        // The PATH is the only part that can still carry raw invalid bytes (the query is re-encoded below), and
        // it is scrubbed AFTER the split: scrubbing first turned a bad byte in the path into a '?', which then
        // became the query separator and shifted every parameter name.
        $path = mb_scrub($url);

        // Route matching percent-DECODES the path, so a capability link spelled `/order/%65yJ...` still routes
        // while a literal `/eyJ` pattern misses it. Match on the decoded form and, only when it hits, keep that
        // form; an ordinary URL is returned exactly as it came.
        $decoded = rawurldecode($path);

        if (preg_match(self::ENCRYPTED_SEGMENT, $decoded) === 1) {
            $path = preg_replace(self::ENCRYPTED_SEGMENT, '/[encrypted]', $decoded);

            // Fail CLOSED: a regex error must never fall back to storing the ciphertext.
            if ($path === null) {
                return '[unparseable url]';
            }
        }

        if ($query === null) {
            return $path . $fragment;
        }

        parse_str($query, $parameters);

        $clean = self::redactParameters($parameters);

        return $path . ($clean === [] ? '' : '?' . http_build_query($clean)) . $fragment;
    }

    private static function redactParameters(array $parameters): array
    {
        foreach ($parameters as $name => $value) {
            if (preg_match(self::SENSITIVE_PARAMETER, (string) $name) || in_array(strtolower((string) $name), self::SENSITIVE_EXACT, true)) {
                $parameters[$name] = '[redacted]';
            } elseif (is_array($value)) {
                $parameters[$name] = self::redactParameters($value);
            } elseif (is_string($value) && (strlen($value) > self::MAX_VALUE_LENGTH || preg_match('/^\s*[\[{]/', $value) || str_starts_with($value, 'eyJ'))) {
                $parameters[$name] = '[redacted]';
            } elseif (is_string($value) && str_contains($value, '?')) {
                // A URL-valued parameter (`?next=/reset-password?token=T`): its own query is parsed too.
                $parameters[$name] = self::redact($value);
            }
        }

        return $parameters;
    }
}
