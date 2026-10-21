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
 */
final class HostClaims
{
    /** the two names that hold a host, and the flag that switches each on */
    private const FLAGS = ['domains' => 'enable_domain', 'subdomain' => 'enable_subdomain'];

    public static function normalise(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0];

        return str_replace('www.', '', $host);
    }

    /** The row that makes `$host` this store's, when its store has the matching flag on; null otherwise. A stdClass (properties as the columns). */
    public static function row(string $name, string $host): ?object
    {
        $flag = self::FLAGS[$name];

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
     * The `saving` hook of App\Models\Setting: refuse a write that would make this store hold a host another store holds.
     *
     * @throws ValidationException
     */
    public static function assertFree(Setting $setting): void
    {
        $storeId = $setting->store_id;
        $name = strtolower(trim((string) $setting->name));

        if ($storeId === null || ($name !== 'domains' && $name !== 'subdomain' && $name !== 'enable_domain' && $name !== 'enable_subdomain')) {
            return;
        }

        if (isset(self::FLAGS[$name])) {
            $host = (string) $setting->value;
        } else {
            // a flag: only SWITCHING ON matters, and the host it switches on is the one this store already has saved
            if (strtolower(trim((string) $setting->value)) !== 'on') {
                return;
            }

            $held = array_search($name, self::FLAGS, true);
            $host = (string) (DB::table('settings')->where('store_id', $storeId)->whereRaw('LOWER(TRIM(name)) = ?', [$held])->orderByDesc('id')->value('value') ?? '');
        }

        if (self::heldByAnotherStore($host, $storeId)) {
            throw ValidationException::withMessages([$name => [__('This domain is already used by another store.')]]);
        }
    }
}
