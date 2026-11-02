<?php

namespace App\Services;

/**
 * P2-06-7 — recent pages: short-lived, inside the working session, NOT a browsing history.
 *
 * THE OWNER DREW THIS LINE EXPLICITLY and it is the whole reason this class is not a table:
 * "الصفحات الحديثة قصيرة الأجل داخل جلسة العمل، وليست سجل تصفح دائماً." So it lives in the Laravel
 * server session, which expires with the working day and dies at logout -- the same store P2-06-5
 * chose for filter memory, for the same reason, and that file's argument applies here unchanged.
 *
 * A PERSISTENT VERSION OF THIS WOULD BE A DIFFERENT AND WORSE FEATURE. A durable list of the
 * screens an employee visited, keyed to them, is surveillance of staff by whoever can read the
 * table, and it is the kind of record that has to be reasoned about under data protection rather
 * than shipped as a convenience. Session scope removes that question instead of answering it.
 *
 * WHAT IS STORED IS A NAVIGATION `name`, never a URL. `MerchantPageDirectory::nameForRoute()` maps
 * the request's own route name to a known identifier, so a path carrying a customer's data cannot
 * be recorded even in the session -- the owner's condition holds for both halves of this slice,
 * not only the persistent one.
 *
 * AND IT IS PERMISSION-FILTERED ON READ, exactly like a favourite. The session outlives a role
 * edit within one working day, so the same "a stored entry outlives the grant" problem applies,
 * and the same answer: the directory decides at render time.
 */
final class RecentPages
{
    /* KEYED TO THE PRINCIPAL, because the session can outlive one. The standard web logout
       invalidates the session, so the shared-browser case is already closed by that control --
       but `APP_THEME()` calls `auth()->logout()` with no invalidate, and `Impersonate::take()`
       keeps the session deliberately, so entries CAN survive a principal change. What could
       cross is bounded (only names present in both directories, and resolution always runs
       through the CURRENT principal's directory, so a stale name resolves to their own page or is
       dropped) -- no record data and no cross-tenant href. Including the id costs one line and
       removes the question instead of bounding it. */
    private const SESSION_PREFIX = 'ss_recent_pages';

    /** Short enough to stay a shortcut. */
    public const LIMIT = 6;

    private static function key(): string
    {
        return self::SESSION_PREFIX . '.' . (int) auth()->id();
    }

    /**
     * Note a visit. Most recent first; a repeat visit moves the page up rather than duplicating.
     *
     * A page the principal may not open is not recorded at all -- it could not be rendered
     * anyway, and not storing it means the session never holds an identifier the employee has no
     * business having.
     */
    public static function remember(string $page): void
    {
        if (! MerchantPageDirectory::permits($page)) {
            return;
        }

        $pages = self::stored();

        // Move-to-front, so the list is genuinely "most recent" rather than "first seen".
        $pages = array_values(array_filter($pages, static fn ($p) => $p !== $page));
        array_unshift($pages, $page);

        session()->put(self::key(), array_slice($pages, 0, self::LIMIT));
    }

    /**
     * The recent pages this employee may open right now, most recent first.
     *
     * @return array<string, array{name:string,title:string,icon:?string,route:string,href:string}>
     */
    public static function all(): array
    {
        $stored = self::stored();

        if ($stored === []) {
            return [];
        }

        // One directory for the whole list -- see FavouritePages::all() for why per-row
        // resolution was expensive enough to matter.
        $directory = MerchantPageDirectory::available();

        $out = [];

        foreach ($stored as $page) {
            if (isset($directory[$page])) {
                $out[$page] = $directory[$page];
            }
        }

        return $out;
    }

    /** Used by the tests; no request path needs it, since the session expires on its own. */
    public static function forget(): void
    {
        session()->forget(self::key());
    }

    /** @return array<int, string> */
    private static function stored(): array
    {
        $pages = session()->get(self::key(), []);

        return is_array($pages) ? array_values(array_filter($pages, 'is_string')) : [];
    }
}
