<?php

namespace App\Services;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use LaraBug\LaraBug;
use Throwable;

/**
 * The error tracker's payload without the request's secrets.  P1-SEC-14.
 *
 * vendor/larabug/larabug posts, on every reported exception, the full URL with its query string, the DECRYPTED cookies (session id and any
 * `remember_*`), the raw `cookie` header, `Session::all()`, `Request::all()`, old input, and the signed-in user's `toArray()`, to
 * https://www.larabug.com/api/log -- whether or not LB_KEY is set (Client::report does not check). Its own protection is a key-NAME glob
 * (`config/larabug.php` `blacklist`), which missed cookies, sessions, secrets, card numbers and expiries, and it is not applied to the
 * user or to the exception message at all.
 *
 * Three layers, each measured in LaraBugPayloadTest with the real LaraBug classes:
 *   1. the blacklist (config/larabug.php) covers the key names this application actually carries;
 *   2. THIS class: every VALUE the live request carried under a blacklisted key (a card number, a gateway secret, a session id) is also
 *      removed from the exception message, the trace string and the URL -- a QueryException message embeds its bound values, and the key
 *      glob cannot see those -- and the URL's query string is filtered like any other variable;
 *   3. App\Models\User::toLarabug() sends an id and a type, not the model.
 *
 * What it does not do: a secret under a key nobody blacklisted is still sent; the layers above shrink that surface, they do not close it.
 *
 * AND A FOURTH LAYER, WHICH IS NOW THE OUTERMOST ONE. The owner's decision on P1-SEC-14 is that outbound reporting is DISABLED until the
 * protection above has been verified against a real payload, so {@see mayReportOutbound()} requires BOTH a configured account AND an
 * explicit `LARABUG_OUTBOUND_ENABLED`, which defaults to false. Local error logging is untouched -- the `stack` channel's `single` has
 * already written the exception to disk before this class is reached. Whether the tracker was keyed in production historically, and
 * therefore whether anything was transmitted before this change, cannot be answered from this repository: `.env` is not in it. That
 * question needs server access and is recorded in docs/security/P01-SEC-14-ERROR-TRACKER-EXPOSURE.md.
 */
class ScrubbedLaraBug extends LaraBug
{
    private const MIN_SECRET_LENGTH = 6;

    /**
     * The package routes `POST /larabug-api/javascript-report` with NO middleware, and its `handle(..., 'javascript', $custom)` branch runs
     * `file($custom['file'])` -- a path from the request body -- and posts the lines around `line` to the tracker. Anyone could make the server
     * read a local file (or open a URL: `file('http://...')`) and hand the lines to a third party. No view in this application includes the
     * package's JavaScript client, so nothing legitimate calls it; only the 'php' report is accepted.
     */
    public function handle(Throwable $exception, $fileType = 'php', array $customData = [])
    {
        if ($fileType !== 'php') {
            return false;
        }

        if (! self::mayReportOutbound()) {
            /*
             * LOCAL LOGGING IS PRESERVED, which is the whole point of refusing HERE rather than by
             * removing the channel. The `stack` channel is `['single', 'larabug']`: `single` has
             * already written the exception to storage/logs/laravel.log by the time this runs, so
             * nothing is lost by declining to transmit. Only the outbound POST stops.
             *
             * `debug` and not `warning`: a suppressed report is the CONFIGURED state, not an
             * incident, and logging it at warning on every exception would bury the real warnings.
             * It is logged at all so that "the tracker is quiet" can be told apart from "the tracker
             * is broken" without reading the config.
             */
            \Illuminate\Support\Facades\Log::debug('LaraBug report suppressed: outbound reporting is disabled.', [
                'configured' => self::isConfigured(),
                'outbound_enabled' => (bool) config('larabug.outbound_enabled', false),
            ]);

            return false;
        }

        return parent::handle($exception, $fileType, $customData);
    }

    /**
     * May an exception be transmitted to the third-party tracker at all?
     *
     * OWNER DECISION, P1-SEC-14: outbound reporting is OFF until the sensitive-data protection has
     * been verified against a real payload. Both conditions must hold -- a key AND a deliberate
     * switch -- and they are different questions:
     *
     *   isConfigured()      is there an account to report to? (an unkeyed instance posts to nobody,
     *                       with an empty bearer token, which is a disclosure with no recipient
     *                       rather than a safe no-op)
     *   outbound_enabled    has an operator decided, on purpose, that this deployment may transmit?
     *
     * Keyed alone is NOT consent. That is the ordering the owner's decision fixes: restoring the
     * keys for any reason must not silently resume sending.
     */
    public static function mayReportOutbound(): bool
    {
        return self::isConfigured() && (bool) config('larabug.outbound_enabled', false);
    }

    /**
     * Is a tracker account configured at all?
     *
     * WHAT THE SCRUBBER ABOVE DOES NOT COVER, and why this gate is a second control rather than a
     * tidy-up. `LaraBug\Http\Client::report()` POSTs to `config('larabug.server')` -- by default
     * `https://www.larabug.com/api/log`, a third party -- with `Authorization: Bearer ` and an empty
     * `project` when `LB_KEY` and `LB_PROJECT_KEY` are unset. It checks neither. The scrubber removes
     * the values the REQUEST carried; the payload still holds `executor` (the twelve source lines
     * either side of the throw, verbatim), `exception` (the trace string), `file`, `line`, `class`,
     * the route name and the URL. So on an unkeyed production instance this application would ship
     * its own source code and stack traces outbound on every reported exception, to an account
     * nobody owns -- a disclosure the key-name glob cannot reach, because none of it is a request
     * variable.
     *
     * P1-SEC-14 acceptance 3 asks whether the tracker is keyed in production. `.env` is not in this
     * repository and cannot be read from here, so that half stays the owner's to answer. This gate
     * makes the unanswered half harmless: unkeyed means nothing is posted, so the honest answer
     * "we do not know" no longer describes an exposure. It changes nothing for a keyed instance.
     */
    public static function isConfigured(): bool
    {
        return trim((string) config('larabug.login_key')) !== ''
            && trim((string) config('larabug.project_key')) !== '';
    }

    public function getExceptionData(Throwable $exception)
    {
        $data = parent::getExceptionData($exception);

        $secrets = $this->secretValues();

        foreach (['exception', 'error'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = $this->scrub($data[$key], $secrets);
            }
        }

        if (isset($data['fullUrl']) && is_string($data['fullUrl'])) {
            $data['fullUrl'] = $this->scrub($this->filterQuery($data['fullUrl']), $secrets);
        }

        return $data;
    }

    /** @return list<string> the string values the live request carries under a blacklisted key, longest first */
    private function secretValues(): array
    {
        $found = [];
        $sources = [Request::all(), Request::cookie(), Request::header()];

        if (Request::hasSession()) {
            // Old input is flashed into the session (`_old_input`), so Session::all() already carries it.
            $sources[] = Session::all();
        }

        foreach ($sources as $source) {
            $this->collect((array) $source, false, $found);
        }

        $found = array_values(array_unique($found));
        usort($found, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $found;
    }

    private function collect(array $variables, bool $inSecret, array &$found): void
    {
        foreach ($variables as $key => $value) {
            $secret = $inSecret || $this->isBlacklisted((string) $key);

            if (is_array($value)) {
                $this->collect($value, $secret, $found);
            } elseif ($secret && (is_string($value) || is_int($value) || is_float($value))) {
                $value = (string) $value;

                if (strlen($value) >= self::MIN_SECRET_LENGTH) {
                    $found[] = $value;
                }
            }
        }
    }

    private function isBlacklisted(string $key): bool
    {
        foreach ((array) config('larabug.blacklist', []) as $glob) {
            if (Str::is(strtolower($glob), strtolower($key))) {
                return true;
            }
        }

        return false;
    }

    private function scrub(string $text, array $secrets): string
    {
        return $secrets === [] ? $text : str_replace($secrets, '***', $text);
    }

    private function filterQuery(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);

        return substr($url, 0, strpos($url, '?')) . '?' . http_build_query($this->filterVariables($query));
    }
}
