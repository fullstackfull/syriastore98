<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which store HOLDS a host?  P1-SEC-29.
 *
 * The storefront answers a request by looking its host up in `settings`: a row named `domains` (a custom domain) or `subdomain` whose
 * value is the host, belonging to a store that has the matching flag (`enable_domain` / `enable_subdomain`) on. Before this class the
 * lookup was `Setting::where('name', 'domains')->where('value', $host)->first()` -- no store predicate, no ORDER BY -- and nothing kept
 * a host unique, so the row with the lowest id of ANY tenant won: a merchant whose rows pre-dated a victim's could save the victim's
 * host as their own and be served in its place (EXECUTED, P1-SEC-29).
 *
 * WRITE. A store cannot save, or switch on, a host that ANOTHER store holds. "Holds" is the row AND that store's own flag, in either
 * name -- the two names are one namespace because the lookup tries `subdomain` first, then `domains`. A host a store has switched off
 * is free; it is then held by whoever took it, and the first store cannot take it back while it is. The check lives in the `Setting`
 * model's `saving` event so that every Eloquent writer -- the generic web handlers, ThemeSettings, and any vendored module that saves
 * through the host model -- passes through it; a raw `DB::table('settings')` write does not (none of those writes these names).
 *
 * READ. row() returns the row only when its store has the flag on, so a duplicate that is ALREADY in the data (the repository cannot see
 * the live table: P1-OWN-11) resolves to the store that is actually serving the host and not to a stale row with a lower id. Among
 * several enabled holders the lowest id wins, which is what the old `->first()` did.
 *
 * Comparison is case-insensitive and ignores `www.`, as the lookups do (they lower-case the request host and strip `www.`), and MySQL's
 * collation does for the stored value.
 *
 * WHICH WRITE IS A CLAIM is decided by the engine that answers the lookups, not by PHP. The lookups compare names and the flag value in
 * SQL under utf8mb4_unicode_ci, which is case-, accent- and PAD-SPACE-insensitive and ignores some control bytes, so on MySQL `dómains` IS
 * the stored `domains` row, `enablé_domain` IS the flag and a flag value of `ón` IS 'on' -- and a PHP `=== 'domains'` let an owner post
 * them and rewrite the real rows past this hook (review of the first fix; EXECUTED on MySQL 8.0.46:
 * tests/Support/Security/bin/verify-host-claims-on-mysql.php; SQLite compares bytes, so the suite cannot show it). A name or value that is
 * plain printable ASCII can only fold by case and by surrounding spaces -- that script measures, on the server it runs against, that no
 * such character is ignorable, that two of them are equal only as a case pair and that no two equal one -- so those take the PHP path;
 * anything else is put to the engine. The name is judged as it will be STORED (cut to the column's 255 characters).
 *
 * Six host lookups in app/ go through row(); a seventh, in the vendored ThemeCustomizer module's view composer, cannot be edited
 * (P1-SEC-31).
 */
final class HostClaims
{
    /** the two names that hold a host, and the flag that switches each on */
    private const FLAGS = ['domains' => 'enable_domain', 'subdomain' => 'enable_subdomain'];

    /**
     * P1-SEC-32 (a) and (b), under owner decision D-07: "Platform-owned hosts and system labels are
     * reserved."
     *
     * THE LABELS. RFC 2142's mailbox names plus the hosting-panel set every control panel creates by
     * default. The list is a CONVENTION, not a business policy -- it is the set whose capture would
     * take over the platform's own mail, panel or API rather than merely look confusing -- so it is
     * settled here and recorded rather than put back to the owner, and the owner can amend it in one
     * place. It is deliberately SHORT: a long list refuses legitimate merchant names (`shop`, `store`,
     * `my`) for no security gain, and every entry below is one the platform or its host either already
     * answers or would answer after any ordinary panel install.
     *
     * WHAT A RESERVED LABEL MEANS HERE. Only under the PLATFORM's own registrable host. `admin.acme.com`
     * is Acme's business; `admin.<APP_URL host>` is the platform's. A merchant's own custom domain is
     * not inspected for these labels at all, which is why this is a reservation and not a blocklist.
     */
    public const RESERVED_LABELS = [
        'www', 'admin', 'api', 'app', 'mail', 'smtp', 'imap', 'pop', 'pop3', 'ftp', 'sftp',
        'ns', 'ns1', 'ns2', 'mx', 'cpanel', 'whm', 'webmail', 'webdisk', 'autodiscover',
        'autoconfig', 'cdn', 'static', 'assets', 'status', 'billing', 'account', 'accounts',
        'login', 'auth', 'sso', 'support', 'help', 'abuse', 'postmaster', 'hostmaster',
        'webmaster', 'security', 'noc', 'test', 'staging', 'dev', 'localhost',
    ];

    /** the length of `settings.name` (database/migrations/2023_12_11_100148_create_settings_table.php: a plain string() column) */
    private const NAME_LENGTH = 255;

    /**
     * The bare host a stored or posted value means. It reads a value the way the hosting-panel block of ThemeSettings does (trim `/`,
     * prefix `http://` when there is no scheme, parse_url's host) so the early check and the panel agree on EVERY spelling -- a leading
     * slash, several `@`, a port, a query -- and then drops the root dot (`shop.example.` is the DNS name `shop.example`; Symfony accepts
     * it as a Host) and `www.`. A value parse_url cannot read falls back to cutting at the first `/ ? #`, user-info and port.
     */
    public static function normalise(string $host): string
    {
        $host = strtolower(trim($host));
        $trimmed = trim($host, '/');
        $parts = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#', $trimmed) ? $trimmed : 'http://' . $trimmed);

        if (is_array($parts) && isset($parts['host']) && $parts['host'] !== '') {
            $host = $parts['host'];
        } else {
            $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host) ?? $host;
            $host = preg_split('#[/?\#]#', $host, 2)[0];
            $host = preg_replace('#^[^@]*@#', '', $host) ?? $host;
            $host = preg_replace('#:\d*$#', '', $host) ?? $host;
        }

        return rtrim(str_replace('www.', '', $host), '.');
    }

    /** The row that makes `$host` this store's, when its store has the matching flag on; null otherwise. A stdClass (properties as the columns). */
    public static function row(string $name, string $host): ?object
    {
        $flag = self::FLAGS[$name];
        $host = rtrim($host, '.');   // the root dot: `shop.example.` is the DNS name `shop.example`

        return DB::table('settings')
            ->where('settings.name', $name)
            ->where('settings.value', $host)
            ->whereExists(function ($q) use ($flag) {
                $q->select(DB::raw(1))->from('settings as f')->whereColumn('f.store_id', 'settings.store_id')->where('f.name', $flag)->where('f.value', 'on');
            })
            ->orderBy('settings.id')
            ->first();
    }

    /** Does a store OTHER than `$exceptStoreId` hold `$host` (a row in either name, with its own flag on)? */
    public static function heldByAnotherStore(string $host, $exceptStoreId = null): bool
    {
        $wanted = self::normalise($host);

        if ($wanted === '') {
            return false;
        }

        $query = DB::table('settings')
            ->whereNotNull('settings.store_id')
            ->whereIn(DB::raw('LOWER(TRIM(settings.name))'), array_keys(self::FLAGS))
            ->whereRaw("REPLACE(LOWER(TRIM(settings.value)), 'www.', '') = ?", [$wanted])
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('settings as f')
                    ->whereColumn('f.store_id', 'settings.store_id')
                    ->where('f.value', 'on')
                    ->whereRaw("LOWER(TRIM(f.name)) = CASE LOWER(TRIM(settings.name)) WHEN 'domains' THEN 'enable_domain' ELSE 'enable_subdomain' END");
            });

        if ($exceptStoreId !== null && $exceptStoreId !== '') {
            $query->where('settings.store_id', '<>', $exceptStoreId);
        }

        return $query->exists();
    }

    /**
     * The platform's own host, lower-cased and www-stripped -- or '' when APP_URL has none.
     *
     * Read from `config('app.url')` with the SAME normalisation the claim path uses, because the two
     * must agree on every spelling. The five existing readers of this value all do a bare
     * `parse_url(config('app.url'))['host']`, which answers null for a configured value with no scheme
     * (`storesyria.com`) and then compares null against a host; normalise() handles that shape, so this
     * method is the one place that gets it right and the comparison below cannot be defeated by how
     * the environment happens to spell APP_URL.
     */
    public static function platformHost(): string
    {
        return self::normalise((string) config('app.url'));
    }

    /**
     * P1-SEC-32 (a) and (b). Is `$host` the platform's own, or a reserved label under it?
     *
     * TWO QUESTIONS, ONE ANSWER, and they are different claims:
     *
     *   (a) `$host` IS the platform host. Today a store owner who can save the Custom tab may set
     *       `domains = <APP_URL host>` with `enable_domain = on`, and the platform's own landing page
     *       and login redirect are then answered by that store's storefront through
     *       HomeController::Landing. That is the whole platform, not one tenant's data.
     *   (b) `$host` is `<label>.<platform host>` for a label in RESERVED_LABELS -- `admin.`, `api.`,
     *       `mail.`, `www.`. Capturing one of those takes over a name the platform or its hosting
     *       panel answers, or would answer after any ordinary install.
     *
     * ONLY UNDER THE PLATFORM'S OWN HOST. A merchant's `admin.acme.com` is Acme's business and is not
     * inspected: the suffix test is what makes this a reservation rather than a blocklist on the word
     * "admin".
     *
     * `www.` NEEDS NO SPECIAL CASE and must not have one: normalise() strips it from both sides, so
     * `www.<platform host>` reduces to the platform host and is caught by limb (a). The label stays in
     * RESERVED_LABELS for the two-level case (`www.shop.<platform host>`), which normalise() does not
     * touch.
     *
     * AN UNCONFIGURED APP_URL RESERVES NOTHING, deliberately. With no platform host there is no
     * namespace to reserve, and inventing one would refuse every merchant host on a misconfigured
     * install -- a lockout, which is the failure direction this codebase has already paid for once
     * (App\Http\Middleware\FinancePermission).
     */
    public static function isReserved(string $host): bool
    {
        $wanted = self::normalise($host);
        $platform = self::platformHost();

        if ($wanted === '' || $platform === '') {
            return false;
        }

        // (a) the platform host itself. `www.` on either side is already gone.
        if ($wanted === $platform) {
            return true;
        }

        // (b) a reserved label directly under it. The suffix must be a LABEL boundary: `notstoresyria.com`
        // must not match `storesyria.com`, which a bare str_ends_with would let through.
        $suffix = '.' . $platform;

        if (! str_ends_with($wanted, $suffix)) {
            return false;
        }

        $prefix = substr($wanted, 0, -strlen($suffix));

        // Every label of the prefix is examined, not just the first: `api.shop.<platform>` reserves on
        // `api` and `shop.api.<platform>` reserves on `api` too. A deeper name under a reserved label
        // is still under it.
        foreach (explode('.', $prefix) as $label) {
            if (in_array($label, self::RESERVED_LABELS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `saving` hook of App\Models\Setting: refuse a write that would make this store hold a host another store holds.
     *
     * A re-save that changes none of what a claim is made of -- name, value, store -- is not a claim and is let through: the Domain
     * tab re-posts a stale hidden `domains` and the Home and Checkout tabs re-post whatever they were given, and a store that is
     * already in a duplicate (P1-OWN-11) must still be able to save what it already has.
     *
     * @throws ValidationException
     */
    public static function assertFree(Setting $setting): void
    {
        $storeId = $setting->store_id;

        if ($storeId === null) {
            return;
        }

        if ($setting->exists && ! $setting->isDirty(['name', 'value', 'store_id'])) {
            return;
        }

        $name = self::canonicalName((string) $setting->name);

        if ($name === null) {
            return;
        }

        if (isset(self::FLAGS[$name])) {
            $hosts = [(string) $setting->value];
        } else {
            // a flag: only SWITCHING ON matters, and the hosts it switches on are every one this store has saved under the matching
            // name (the lookups accept ANY row, not the newest)
            if (! self::isOn((string) $setting->value)) {
                return;
            }

            $held = array_search($name, self::FLAGS, true);
            $hosts = DB::table('settings')->where('store_id', $storeId)->whereRaw('LOWER(TRIM(name)) = ?', [$held])->pluck('value')->all();
        }

        foreach ($hosts as $host) {
            /*
             * P1-SEC-32 (a)/(b), D-07. The reservation is checked BEFORE the held-by-another-store
             * question and is a different refusal: nobody holds the platform host, so
             * heldByAnotherStore() answers false for it and the old message would have been a lie.
             *
             * A STORE THAT ALREADY HOLDS ONE KEEPS IT until it saves that tab, because assertFree()
             * returns early for a re-save that changes none of (name, value, store). That is the
             * stated compatibility impact, and it is the right one: refusing a stale re-post would
             * lock such a store out of its own settings screen entirely.
             */
            if (self::isReserved((string) $host)) {
                throw ValidationException::withMessages([$name => [__('This domain is reserved by the platform and cannot be used by a store.')]]);
            }

            if (self::heldByAnotherStore((string) $host, $storeId)) {
                throw ValidationException::withMessages([$name => [__('This domain is already used by another store.')]]);
            }
        }
    }

    /** Plain printable ASCII, spaces aside: the only strings whose fold under the collation is a case fold and nothing more. */
    private static function plain(string $text): bool
    {
        // trim()'s default would also strip NUL, tab and newline -- exactly the bytes a collation may ignore
        return preg_match('/[^\x21-\x7E]/', trim($text, ' ')) !== 1;
    }

    /**
     * Which of the four names is this?  Null for any other. A non-plain name is asked of the engine the lookups run on.
     *
     * The name is judged as it will be STORED. `settings.name` is VARCHAR(255) and production runs with `strict => false`, so MySQL keeps
     * the first 255 characters and the spaces that remain are PAD SPACE: `domains` + 300 spaces + `x` is stored as `domains`
     * (EXECUTED on MySQL 8.0.46 -- verify-host-claims-on-mysql.php; found by the second review of this fix).
     */
    private static function canonicalName(string $name): ?string
    {
        $name = self::asStored($name);

        if (self::plain($name)) {
            $normal = strtolower(trim($name, ' '));

            return isset(self::FLAGS[$normal]) || in_array($normal, self::FLAGS, true) ? $normal : null;
        }

        $row = DB::selectOne("select case lower(trim(?)) when 'domains' then 'domains' when 'subdomain' then 'subdomain' "
            . "when 'enable_domain' then 'enable_domain' when 'enable_subdomain' then 'enable_subdomain' end as n", [$name]);

        return $row->n ?? null;
    }

    /**
     * The name as the column keeps it. Non-strict MySQL stores the first 255 characters, and ends the value at the first byte that is
     * not valid UTF-8 (EXECUTED on 8.0.46 through the native prepared statements Laravel uses: `domains\xFFx` is stored as `domains`,
     * while the hook's own SELECT on that string answered NULL -- verify-host-claims-on-mysql.php, which failed on it before this).
     */
    private static function asStored(string $name): string
    {
        // 255 characters are at most 1020 bytes; the rest can only matter if everything before it is valid
        $name = substr($name, 0, 4 * self::NAME_LENGTH + 4);

        if (! mb_check_encoding($name, 'UTF-8')) {
            preg_match('/\A(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*/', $name, $valid);
            $name = $valid[0] ?? '';
        }

        return mb_substr($name, 0, self::NAME_LENGTH, 'UTF-8');
    }

    /** Is this flag value 'on' to the lookups?  (`f.value = 'on'` in SQL.) */
    private static function isOn(string $value): bool
    {
        if (self::plain($value)) {
            return strtolower(trim($value, ' ')) === 'on';
        }

        return (bool) (DB::selectOne("select (lower(trim(?)) = 'on') as o", [$value])->o ?? false);
    }
}
