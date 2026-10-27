<?php

namespace App\Services;

use App\Models\MerchantFavouritePage;

/**
 * P2-06-7 — favourite pages: permanent, per employee, per store, permission-aware.
 *
 * THE PRINCIPAL IS THE AUTHENTICATED USER AND THE TENANT IS THEIR CURRENT STORE. Neither is ever
 * read from request input. CLAUDE.md records this as the single most productive defect pattern in
 * the codebase -- "a tenant predicate built from caller-supplied input is not a tenant check, it
 * only compares the caller's own two values" -- and it has produced four separate defects here. So
 * the only identifiers that reach a query are `auth()->id()` and `getCurrentStore()`, and a caller
 * cannot name either. The `page` argument IS caller-supplied, which is exactly why it is checked
 * against the gated navigation before it is ever written or returned.
 *
 * THE PERMISSION IS CHECKED TWICE AND THOSE TWO CHECKS ARE NOT REDUNDANT:
 *
 *   - on WRITE, so a page the employee cannot open never becomes a row;
 *   - on READ, because a row outlives the grant that justified it. An employee favourites Orders,
 *     the merchant edits their role, and the row is still there. The owner's condition is explicit
 *     -- "أعد فحص الصلاحيات عند كل عرض أو انتقال، ولا تعرض مفضلة لم يعد الموظف مخولاً بزيارتها" --
 *     and a write-time check alone would leave a working link to a screen they may no longer open.
 *
 * THE PLATFORM OPERATOR'S ROWS ARE FILED UNDER STORE 1, measured and recorded rather than
 * discovered later. `getCurrentStore()` (app/Helper/helper.php:636) returns a hardcoded `1` --
 * "Default store for super admin" -- for a `super admin` with no `current_store`. So an operator
 * who favourites an operator page gets a row whose `store_id` is a real merchant's store.
 *
 * That is NOT a cross-tenant read: every query here filters on `user_id` AND `store_id`, so the
 * merchant who owns store 1 has a different `user_id` and never sees the operator's rows, and the
 * operator never sees theirs. What it does mean is that the operator's favourites follow whatever
 * store `getCurrentStore()` resolves, which is pre-existing behaviour shared by every caller of
 * that helper and not something to special-case here. The operator's PAGES are their own either
 * way, because `MerchantPageDirectory` fires `SuperAdminMenuEvent` for them.
 *
 * THE READ FILTERS, IT DOES NOT DELETE. Restoring the permission restores the favourite, and a
 * role edit cannot silently destroy a merchant's preferences. Deleting on revocation would also
 * need a hook on every permission write to happen at all.
 */
final class FavouritePages
{
    /** A shortcut list, not a menu of its own. */
    public const LIMIT = 12;

    /* FOUR OUTCOMES, NOT A BOOLEAN, and review caught why that matters. `toggle()` returned
       `false` for "removed", for "the list is full" AND for "no store resolved", and the
       controller mapped `false` to "Removed from your favourites." -- so the 13th favourite was
       silently discarded while the UI announced a removal, and a staff member whose
       `users.current_store` is empty (a state `config/tenancy.php` documents as real, because
       `getCurrentStore()`'s fallback looks for a store they created) got that same message on
       every click while nothing was ever stored. */
    public const ADDED = 'added';
    public const REMOVED = 'removed';
    public const REFUSED = 'refused';
    public const FULL = 'full';
    public const NO_STORE = 'no-store';

    /** @return self::ADDED|self::REMOVED|self::REFUSED|self::FULL|self::NO_STORE */
    public static function toggle(string $page): string
    {
        $userId = (int) auth()->id();
        $storeId = (int) getCurrentStore();

        if ($userId === 0 || $storeId === 0) {
            return self::NO_STORE;
        }

        // THE GATE. A page not currently in this principal's navigation is refused, which is what
        // makes a URL, a query string or an unknown name impossible to store.
        if (! MerchantPageDirectory::permits($page)) {
            return self::REFUSED;
        }

        $existing = MerchantFavouritePage::query()
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->where('page', $page)
            ->first();

        if ($existing) {
            $existing->delete();

            return self::REMOVED;
        }

        $count = MerchantFavouritePage::query()
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->count();

        if ($count >= self::LIMIT) {
            return self::FULL;
        }

        /* `firstOrCreate`, NOT `create`, because read-then-write here is a real race and not a
           theoretical one: the palette can show TWO live controls for the same page at once (the
           Recent row's star and "Add this page to favourites"), and the script disables only the
           button that was clicked. Two concurrent posts both saw no existing row and the second
           violated `merchant_favourite_pages_unique` -- a 500, not a duplicate. The migration's
           note that "a double submit cannot duplicate a favourite" was true and incomplete. */
        MerchantFavouritePage::firstOrCreate(
            ['user_id' => $userId, 'store_id' => $storeId, 'page' => $page],
            ['position' => $count]
        );

        return self::ADDED;
    }

    /**
     * This employee's favourites in this store, in their chosen order, filtered to what they may
     * open right now.
     *
     * @return array<string, array{name:string,title:string,icon:?string,route:string,href:string}>
     */
    public static function all(): array
    {
        $userId = (int) auth()->id();
        $storeId = (int) getCurrentStore();

        if ($userId === 0 || $storeId === 0) {
            return [];
        }

        $rows = MerchantFavouritePage::query()
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('page')
            ->all();

        /* ONE directory, read before the loop. This called `resolve()` per row, and `resolve()`
           rebuilt the whole navigation each time -- up to twelve full menu events to render
           twelve links. Indexing into one array is the same gate, asked once. */
        $directory = MerchantPageDirectory::available();

        $out = [];

        foreach ($rows as $page) {
            $page = (string) $page;

            // The render-time half of the gate. A revoked permission makes the page vanish from
            // the directory, so it vanishes from here, while the row stays put.
            if (isset($directory[$page])) {
                $out[$page] = $directory[$page];
            }
        }

        return $out;
    }

    public static function isFavourite(string $page): bool
    {
        return array_key_exists($page, self::all());
    }
}
