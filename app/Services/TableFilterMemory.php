<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * P2-06-5 — a merchant's list filters, remembered for the length of their working session.
 * =================================================================================================
 *
 * THE STORE IS THE LARAVEL SERVER SESSION, and every other option was ruled out by something
 * measurable rather than by taste:
 *
 *   - `settings` CANNOT hold a per-employee value. `SettingOwnership` rewrites `created_by` to
 *     `$user->creatorId()` on create and restores it on update, so two employees of one merchant
 *     collapse onto one row — the opposite of the acceptance.
 *   - localStorage cannot be per-employee without putting a user id in the DOM, and P2-06-4's
 *     disclosure argument (`public/js/store-syria-ds.js:733-736`) rests on the stored material
 *     carrying "no identifiers". Adding one would mean re-arguing that note, not re-quoting it.
 *   - A COOKIE cannot: a cookie name is a browser-global slot, so the second employee at a shared
 *     terminal overwrites the first. That is the defect, not the feature.
 *   - A new column or table could, and is a persistent migration, which CLAUDE.md reserves to the
 *     owner. The ledger already holds that decision open for P2-04-9 (saved views) and P2-06-7
 *     (favourites); spending it on the cheapest of the four requirements would be the wrong call.
 *
 * AND THE SESSION'S SHORT LIFE IS THE POINT, not a compromise. P2-06-4 saves page length, sort and
 * column visibility and DELIBERATELY STRIPS the search term, because "a merchant who searched
 * 'refund' last Thursday comes back to a table showing four rows out of nine hundred, and concludes
 * their orders are gone". That defect is TIME-SHAPED: restoring this morning's filter is help,
 * restoring last Thursday's is a support ticket. A session expires with the working day and dies at
 * logout, so the same mechanism that makes it unsuitable for a device preference makes it right for
 * a working context. `SESSION_LIFETIME=120` means two hours idle — stated plainly rather than
 * dressed up as permanence.
 *
 * IT ALSO KEEPS TENANT DATA OFF THE DEVICE. `ss_category` is a store-owned row id whose option list
 * is deliberately store-filtered against cross-tenant name disclosure
 * (`ProductController::index()`). On the server it is never readable by the next person at that
 * terminal, and never readable from JavaScript.
 *
 * THIS CLASS DOES NOT DECIDE WHAT A FILTER MEANS. It knows the key names and three value guards;
 * what each value does to a query stays in `ProductDataTable::applyFilters()` and
 * `OrderDataTable::applyFilters()`, which already validate every value they read. A restored
 * request is byte-identical to a typed one, so it inherits that validation rather than duplicating
 * it — including the invariant that a filter may only ever NARROW.
 */
final class TableFilterMemory
{
    /**
     * The one session key. FLAT, with `:` inside the composite rather than `.`, because Laravel's
     * session helper treats a dot as nesting — `session(['a.b' => 1])` writes `['a' => ['b' => 1]]`
     * — and a key built from ids would then create a tree nobody can assert against in one call.
     */
    private const SESSION_KEY = 'ss_table_filters';

    /**
     * The filter vocabulary per screen, which is ALSO the allowlist: a parameter not named here is
     * never remembered and never restored. Keeping it explicit is what stops a future
     * `?redirect=…` or `?page=…` from becoming part of a merchant's remembered filters.
     */
    private const VOCABULARY = [
        'product' => ['ss_status', 'ss_stock', 'ss_category', 'ss_variant'],
        'order' => ['ss_status', 'ss_payment', 'ss_from', 'ss_to'],
    ];

    /** The submit button's name. Its PRESENCE is the only thing that writes the memory. */
    public const APPLY = 'ss_apply';

    /** The explicit forget signal, so "Clear filters" cannot be re-decorated by a restore. */
    public const CLEAR = 'ss_clear';

    /**
     * RESTORE ONLY ON A COMPLETELY EMPTY QUERY STRING, and the bluntness is the design.
     *
     * One condition absorbs five cases that would otherwise each need their own exception, and
     * would then have to be kept in agreement with each other for ever:
     *
     *   1. the DataTables AJAX leg always carries `draw`, `columns`, `start` and `length`, so a
     *      302 can never be served to a request that is expecting JSON and would blank the table;
     *   2. `/product?id=1` is the post-create redirect — a merchant who has just created a product
     *      must never land on a list that hides it;
     *   3. "Apply with every select on Any" arrives as `?ss_status=&ss_stock=&…`, because a GET
     *      form submits every control. That is present-and-empty, and it is an explicit
     *      instruction to show everything;
     *   4. the clear signal;
     *   5. every parameter anybody adds later, without this method being edited.
     */
    public static function shouldRestore(Request $request): bool
    {
        return $request->query() === [];
    }

    /**
     * Store the filters the merchant just applied, or forget them when they applied none.
     *
     * WRITES ONLY ON `ss_apply`. Without that, any url carrying an `ss_` parameter — a bookmark, a
     * link in an email, a url pasted between colleagues — would silently become the merchant's new
     * default for that screen.
     *
     * An apply with every control on "Any" FORGETS rather than being ignored. Treating it as
     * "nothing to remember" would leave the previous narrowing in place, so the one gesture that
     * means "show me everything" would be the one gesture that changed nothing.
     */
    public static function remember(Request $request, string $screen): void
    {
        if (! $request->has(self::APPLY) || ! isset(self::VOCABULARY[$screen])) {
            return;
        }

        $values = self::recognised($request->query(), $screen);

        if ($values === []) {
            self::forget($screen);

            return;
        }

        $all = self::bag();
        $all[self::key($screen)] = $values;
        session([self::SESSION_KEY => $all]);
    }

    /**
     * What to restore, after revalidation.
     *
     * REVALIDATED ON THE WAY OUT, not only on the way in, because the world moves between the two:
     * a remembered `ss_category` whose row has since been deleted, or which belongs to a store the
     * merchant has since switched away from, would narrow the list to zero rows. That is exactly
     * the "my products are gone" screen this feature exists to avoid, so a category id that no
     * longer resolves against the CALLER'S OWN categories is dropped and the rest is still
     * restored.
     */
    public static function recall(string $screen): array
    {
        $values = self::bag()[self::key($screen)] ?? [];

        if (! is_array($values) || $values === []) {
            return [];
        }

        $values = self::recognised($values, $screen);

        if (isset($values['ss_category']) && ! self::categoryIsOwned($values['ss_category'])) {
            unset($values['ss_category']);
        }

        return $values;
    }

    public static function forget(string $screen): void
    {
        $all = self::bag();
        unset($all[self::key($screen)]);
        session([self::SESSION_KEY => $all]);
    }

    /**
     * The composite key: the acting user, their current store, and the screen.
     *
     * THE USER AXIS IS NOT REDUNDANT even though a session is already per-employee. Impersonation
     * makes `auth()->id()` the impersonated merchant (`StoreController`), so without it an
     * operator's own filters would bleed into the merchant persona and survive leaving it. Keyed
     * rather than branched on the impersonation flag, so there is no second rule to keep in step.
     *
     * THE STORE AXIS stops a filter applied in store A being replayed in store B after a store
     * switch — where a category id means something different, or nothing.
     */
    private static function key(string $screen): string
    {
        return (int) auth()->id() . ':' . (int) getCurrentStore() . ':' . $screen;
    }

    private static function bag(): array
    {
        $bag = session(self::SESSION_KEY, []);

        return is_array($bag) ? $bag : [];
    }

    /**
     * The recognised, non-empty subset.
     *
     * NON-EMPTY, NOT MERELY PRESENT, and that distinction has already produced a defect on these
     * screens: a GET form submits every control, so "Apply" with nothing chosen yields
     * `?ss_status=&ss_stock=&ss_category=&ss_variant=`, where `query('ss_status')` is `''` — which
     * is `!== null`, which is what the filter bars' own "Clear filters" visibility test used, so
     * an unfiltered list showed a Clear button.
     *
     * `is_string` on every value: `?ss_stock[]=out` makes it an array, and an array reaching a
     * comparison downstream is an "Array to string conversion" and a 500 from a query string.
     */
    private static function recognised(array $input, string $screen): array
    {
        $out = [];

        foreach (self::VOCABULARY[$screen] ?? [] as $name) {
            $value = $input[$name] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            // 64 is longer than any value these bars produce (a date, an id, a short enum) and
            // short enough that a session bag cannot be inflated through the query string.
            if ($value === '' || strlen($value) > 64) {
                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * The filters currently in force, as readable labels, for the summary above the bar.
     *
     * READ FROM THE REQUEST, not from the memory: after a restore the two agree, and on a typed
     * or bookmarked url only the request is true. One source means the receipt cannot disagree
     * with the list it describes.
     */
    public static function describe(Request $request, string $screen, array $categories = []): array
    {
        $values = self::recognised($request->query(), $screen);
        $out = [];

        foreach ($values as $name => $value) {
            $label = self::label($name, $value, $categories);

            if ($label !== null) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * One filter in words.
     *
     * A VALUE THIS METHOD DOES NOT RECOGNISE PRODUCES NOTHING, deliberately. `recognised()` only
     * checks shape — a string, non-empty, short — while the real vocabulary of each filter lives
     * in `applyFilters()`, which ignores anything outside it. So `?ss_stock=banana` narrows
     * nothing, and a summary that announced "Stock: banana" would be describing a filter that is
     * not in force. Saying nothing is the only honest option; the empty-set case then hides the
     * whole receipt, which is correct because nothing is filtered.
     */
    private static function label(string $name, string $value, array $categories): ?string
    {
        $map = [
            'ss_status' => ['1' => 'Active', '0' => 'Inactive'],
            'ss_variant' => ['1' => 'With variants', '0' => 'Without variants'],
            'ss_stock' => ['in' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock'],
        ];

        $heading = [
            'ss_status' => 'Status', 'ss_variant' => 'Variants', 'ss_stock' => 'Stock',
            'ss_category' => 'Category', 'ss_payment' => 'Payment', 'ss_from' => 'From',
            'ss_to' => 'To',
        ];

        if (isset($map[$name])) {
            return isset($map[$name][$value])
                ? __($heading[$name]) . ': ' . __($map[$name][$value])
                : null;
        }

        if ($name === 'ss_category') {
            $id = filter_var($value, FILTER_VALIDATE_INT);

            return ($id !== false && isset($categories[$id]))
                ? __('Category') . ': ' . $categories[$id]
                : null;
        }

        /* The order screen's status and payment vocabularies are data, not constants, and its
           dates are free-form. Echoing a date or a status NAME back is safe -- it is the
           merchant's own input, rendered by Blade, and it is already visible in the select
           beside it. */
        return isset($heading[$name]) ? __($heading[$name]) . ': ' . $value : null;
    }

    /**
     * Whether a remembered category id is one of the caller's own.
     *
     * `Category` is NOT in `config/tenancy.php`'s `scoped_models`, so it adds no predicate for any
     * principal and the `store_id` here is the only thing confining it. This is not standing in for
     * a tenant check on the LIST — `applyFilters()` narrows a set already confined to the caller's
     * store, so a foreign id returns zero rows rather than somebody else's. It is here to stop
     * that zero-row screen from being served silently.
     */
    private static function categoryIsOwned(string $id): bool
    {
        $categoryId = filter_var($id, FILTER_VALIDATE_INT);

        if ($categoryId === false || $categoryId <= 0) {
            return false;
        }

        return \App\Models\Category::where('id', $categoryId)
            ->where('store_id', getCurrentStore())
            ->exists();
    }
}
