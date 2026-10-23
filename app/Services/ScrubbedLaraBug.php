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
 * Whether the tracker is keyed in production (LB_KEY) is the owner's to confirm (P1-SEC-14 acceptance 3); until they do,
 * isConfigured() below means an unkeyed instance posts nothing at all, so the unanswered question is not an exposure.
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

        if (! self::isConfigured()) {
            return false;
        }

        return parent::handle($exception, $fileType, $customData);
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
