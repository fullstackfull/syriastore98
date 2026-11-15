<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;

/**
 * SECTION ORDERING FOR A STORE'S THEME PAGES.
 * =================================================================================================
 *
 * PHASE 03 M3-D ADDED `theme_id`, AND THE HISTORY MATTERS FOR READING THIS CLASS.
 *
 * Both handlers used to filter on `theme_id` while the column DID NOT EXIST. M3-C measured that as
 * `ERROR 1054` on MySQL -- section ordering answered 500 in production -- and as SILENCE on SQLite,
 * which reinterprets the unresolvable identifier as a string literal, so no test could see it. The
 * predicates were removed, which fixed the 500 and left ordering keyed on `(store_id, page_slug)`:
 * shared across every theme. M3-D adds the column the handlers always assumed.
 *
 * `theme_id` IS NULLABLE AND NULL MEANS SOMETHING. A legacy row -- written before the column
 * existed -- keeps NULL, because this table never recorded which theme was active when the merchant
 * chose that order, and `stores.theme_id` only knows which theme is active TODAY. Backfilling from
 * it would invent a fact rather than recover one. So NULL reads as *"applies to whichever theme has
 * no row of its own"*, and `resolveFor()` is the only place that rule lives.
 */
class ThemeCustomizeOrder extends Model
{
    use Cachable, BelongsToStore;

    protected $fillable = [
        'page_slug',
        'orders',
        'theme_id',
        'store_id',
    ];

    /**
     * The ordering that applies to one store's page under one theme.
     *
     * THEME-SPECIFIC FIRST, LEGACY SECOND, and in that order for a reason: once a merchant has
     * reordered a page under this theme there is a row that says so, and it must win. Until then the
     * legacy row -- the one chosen before themes were distinguished -- is the best available answer
     * and is still honoured, so the column's arrival changes nothing a merchant can see until they
     * next reorder something.
     *
     * TWO QUERIES RATHER THAN ONE `orWhere`. An `orderByRaw('theme_id IS NULL')` would do it in one
     * trip, but it puts the precedence rule inside a string the query planner reorders and no test
     * reads. Two explicit queries cost one extra indexed lookup on a table with a handful of rows
     * per store, and the precedence is visible in the code.
     *
     * BOTH ARE EXPLICITLY SCOPED BY `store_id` -- AND THE REASON I FIRST GAVE FOR THAT WAS THE
     * OPPOSITE OF THE TRUTH.
     *
     * This docblock claimed `theme_customize_orders` is NOT in `config/tenancy.php`'s
     * `scoped_models` and that the caller's predicate is therefore the only boundary. **It IS in
     * `scoped_models`** -- `config/tenancy.php:176`, with a per-model justification at `:170-175` --
     * and `BelongsToStore` registers `StoreScope` unconditionally, so this model really is globally
     * scoped. Measured: `getGlobalScopes()` returns `App\Scopes\StoreScope`. An independent review
     * caught it; CLAUDE.md's "check before asserting absence" rule exists for exactly this.
     *
     * THE EXPLICIT PREDICATES STAY, and not out of caution. The scope's admissible set is
     * `{current_store} ∪ {stores created_by = creatorId()}`, and it adds NOTHING for an operator or
     * for an unresolved tenant (`null_tenant => 'open'`). So the scope and the predicate agree for
     * every caller today, and the predicate is what still holds when the scope abstains -- which is
     * the case every tenancy defect in this project has come from.
     *
     * The practical consequence of getting this backwards: a future caller that passes a store id
     * the resolved tenant does NOT admit -- an operator-named store, a storefront renderer handed a
     * slug -- gets `null` from the scope and would read it as "no ordering chosen" rather than
     * "filtered". That is why the correction is here rather than only in a commit message.
     */
    public static function resolveFor(int $storeId, string $themeId, ?string $pageSlug): ?self
    {
        if ($storeId <= 0) {
            /* A non-positive id would become `where store_id = 0`, which matches no store -- but
             * `where store_id IS NULL` would match every ownerless row, and that is the shared
             * bucket this phase has refused everywhere else. Refused here too, explicitly. */
            return null;
        }

        $specific = static::query()
            ->where('store_id', $storeId)
            ->where('theme_id', $themeId)
            ->where('page_slug', $pageSlug)
            ->first();

        if ($specific !== null) {
            return $specific;
        }

        return static::query()
            ->where('store_id', $storeId)
            ->whereNull('theme_id')
            ->where('page_slug', $pageSlug)
            ->first();
    }
}
