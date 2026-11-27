<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ARABIC PRODUCT SEARCH: THE STORED FOLD, AND THE PREDICATE THAT READS IT.  `CUST-003` / `GAP-190`.
 * =================================================================================================
 *
 * `CUST-003` is «بحث ذكي ومرادفات وفلاتر ديناميكية». Owner decision `J38` sized this half exactly:
 *
 *   > *`DocumentationSearch::normalise()` is a first-party Arabic normaliser already in this
 *   > repository … Product search is one `LIKE %term%` on `products.name`. **Normalising the query
 *   > alone is insufficient — `LIKE` cannot normalise the haystack** — so the slice is a stored
 *   > `name_normalised` column, a hook on every product writer, and a backfill.*
 *
 * That is the whole shape. A shopper typing `شاشه` cannot reach a product named `شاشة` by folding
 * the query, because the comparison is still `'%شاشه%'` against the raw stored bytes. So the fold
 * is **stored** beside the name (`App\Observers\ProductNameIndexObserver` writes it on every save)
 * and the search asks both columns.
 *
 * -------------------------------------------------------------------------------------------------
 * THE PREDICATE IS AN `OR` INSIDE A GROUP, AND THE GROUP IS THE WHOLE TENANT CONTROL
 * -------------------------------------------------------------------------------------------------
 *
 * `Product` carries `StoreScope` in its registry but is **absent from `config/tenancy.php`'s
 * `scoped_models`**, so `StoreScope::isActiveFor()` short-circuits and the scope adds **no
 * predicate for any principal** (`CLAUDE.md` §1). The `store_id` the handler writes is therefore
 * the entire isolation, and an ungrouped `orWhere` would destroy it — `AND` binds tighter than
 * `OR`, so
 *
 *     where store_id = ? and status = ? and name like ? or name_normalised like ?
 *
 * reads `(store_id AND status AND name) OR (name_normalised)` and answers with **every store's**
 * catalogue. That is not hypothetical here: `Api\ApiController::search`'s own docblock records a
 * live cross-tenant disclosure (`GAP-341`) created by exactly this precedence, through a
 * `whereRaw` in the rating filter two dozen lines from where this predicate is applied.
 * `applyNameFilter()` therefore wraps both limbs in a `where(Closure)` — a parenthesised group —
 * and `ArabicProductSearchTest` drives a second store's matching product at every door.
 *
 * -------------------------------------------------------------------------------------------------
 * THE RAW LIMB IS NOT AN OPTIMISATION. IT IS THE DEGRADATION PATH, AND IT COMES FIRST
 * -------------------------------------------------------------------------------------------------
 *
 * `products.name_normalised` is NULL for every row written before the migration and for every row
 * a backfill has not reached — and the backfill is an owner-run operation across every tenant's
 * catalogue, not something this code may assume has happened. A product with a NULL fold is still
 * found by the raw `name LIKE` limb, which is the behaviour the storefront has today. So the
 * feature **adds** matches and can never remove one:
 *
 *     name_normalised NULL   → found exactly as it is found today
 *     name_normalised stale  → found exactly as it is found today, plus whatever the stale fold matches
 *     name_normalised fresh  → found today's way, plus the orthographic variants
 *
 * And when the COLUMN itself does not exist — a database the migration has not run on, which
 * includes production until the owner runs it — `isStored()` is false and the second limb is not
 * emitted at all, so the query is byte-for-byte today's query. Failing open here is right for the
 * same reason `OrderReturnInspection::blocksRestock()` fails open and `RefundPolicy::allows()`
 * fails closed: this gate WIDENS a result set, so its failure mode must be "find what we found
 * before", never "find nothing".
 *
 * -------------------------------------------------------------------------------------------------
 * WHY A LATIN-SCRIPT QUERY CANNOT CHANGE ITS ANSWER
 * -------------------------------------------------------------------------------------------------
 *
 * For a Latin name the fold is `mb_strtolower()` and nothing else (no prose typography — see
 * `ArabicSearchText`). `LIKE` is case-insensitive under a `_ci` collation and SQLite's `LIKE` is
 * ASCII-case-insensitive, so `name_normalised LIKE '%' || fold(term) || '%'` selects exactly the
 * rows `name LIKE '%term%'` already selects. The added limb is a subset of the first for Latin, so
 * the union is unchanged. Asserted positively at every door by
 * `ArabicProductSearchTest::test_a_latin_catalogue_answers_identically_at_every_door`, not argued
 * from this paragraph.
 *
 * **AND IT IS CONDITIONAL ON A CASE-INSENSITIVE COLLATION, WHICH IS MEASURED RATHER THAN ASSUMED.**
 * `tests/Support/Security/bin/verify-product-name-fold-on-mysql.php` runs that same control against
 * a real server, and on MariaDB 10.11.14:
 *
 *     utf8mb4_unicode_ci   16 checks, 0 failed — every Latin term returns exactly the old rows
 *     utf8mb4_bin          the Latin control FAILS: `Blue` was [1] and becomes [1,2]
 *
 * Under a binary collation the raw limb stops being case-insensitive while the folded limb stays
 * lower-cased, so the union GAINS rows for a Latin query. `products` is `utf8mb4_unicode_ci` and
 * the column is created with `name`'s own collation (check 1 of that script), so this does not
 * apply — but if `products.name` is ever moved to a `_bin` collation, this claim has to be
 * re-measured. The failing run is in the script on purpose: it is what proves the control is
 * capable of failing at all.
 *
 * The same script also measures the fact the whole slice rests on: `utf8mb4_unicode_ci` reports
 * `'ا' = 'أ'`, `'ه' = 'ة'` and `'ي' = 'ى'` as **false**. The engine folds Latin accents and case and
 * does nothing at all about Arabic orthography, which is why the fold has to be stored.
 *
 * -------------------------------------------------------------------------------------------------
 * WILDCARDS ARE PASSED THROUGH, EXACTLY AS TODAY
 * -------------------------------------------------------------------------------------------------
 *
 * Both limbs interpolate the term into the pattern and BIND the pattern, as the three doors already
 * do, so a `%` in a shopper's term stays a `LIKE` wildcard. Stripping it (the rule
 * `MerchantSearchController::strip()` applies, for a leak this door does not have) would change
 * what a Latin query returns, which this slice is forbidden to do. Recorded as a lead.
 */
final class ProductNameIndex
{
    /** The stored fold. `text`, nullable, no index — see the migration for both choices. */
    public const COLUMN = 'name_normalised';

    public const TABLE = 'products';

    /**
     * Memoised per process. `null` = not yet asked.
     *
     * A `Schema::hasColumn()` per search request would be a second round trip on a hot storefront
     * path; the answer cannot change inside a request. `forgetStored()` is the test seam, and the
     * migration-aware tests use it rather than reaching in.
     */
    private static ?bool $stored = null;

    /**
     * Fold a product name or a search term. One implementation, shared with the documentation
     * centre through `ArabicSearchText` so the two cannot drift.
     */
    public static function normalise(string $text): string
    {
        return ArabicSearchText::fold($text);
    }

    /**
     * Does this database carry the stored fold?
     *
     * FAILS CLOSED ON THE COLUMN AND THEREFORE OPEN ON THE RESULT SET: any error reading the schema
     * is answered `false`, which emits today's single-limb query rather than a broken one. A
     * storefront search must not 500 because an information-schema read hiccuped.
     */
    public static function isStored(): bool
    {
        if (self::$stored !== null) {
            return self::$stored;
        }

        try {
            return self::$stored = Schema::hasColumn(self::TABLE, self::COLUMN);
        } catch (Throwable) {
            return self::$stored = false;
        }
    }

    /** Test seam. Also called by the backfill command, which may create the column mid-process. */
    public static function forgetStored(): void
    {
        self::$stored = null;
    }

    /**
     * What the three doors' own `'%' . $value . '%'` produced, without the warning.
     *
     * **AN ARRAY MUST NOT BECOME "NO FILTER", AND THAT IS THE ONLY INTERESTING CASE HERE.** The API
     * filter branch calls this behind `if (! empty($request->name))`, and `! empty(['x'])` is TRUE —
     * so returning `''` for an array would DROP the name predicate and answer with the store's whole
     * catalogue. That is precisely the shape of `GAP-339`, where a filter guarded by a `count()`
     * was dropped whenever its list came back empty and a price band returned everything. Today an
     * array matches products named "...Array...", i.e. effectively nothing; that is reproduced
     * exactly, so the result set is unchanged and cannot widen.
     *
     * @param  mixed  $term
     */
    private static function stringifyTerm($term): string
    {
        if (is_array($term)) {
            return 'Array';
        }

        if (is_object($term) && ! method_exists($term, '__toString')) {
            /* `(string)` on such an object is a fatal Error, not a warning. */
            return get_class($term);
        }

        /* `is_scalar` already covers bool, int, float and string; `null` and `__toString` do not. */
        if ($term === null || is_scalar($term) || is_object($term)) {
            return (string) $term;
        }

        /* A resource or a closed stream: no door can produce one, and '' is "no filter". */
        return '';
    }

    /**
     * Add "the name matches this term" to a product query, in a parenthesised group.
     *
     * `$term` IS DELIBERATELY UNTYPED, AND THE FIRST DRAFT TYPED IT `?string`. Every caller passes
     * `$request->name` or `$request->product` straight in, and a query string or JSON body can make
     * that an **array** (`?product[]=x`). Measured on this PHP 8.3:
     *
     *     '%' . ['x'] . '%'                 ->  "%Array%"  plus a PHP warning   (today's behaviour)
     *     f(?string $t) called with ['x']   ->  TypeError                        (the `?string` draft)
     *
     * So the type hint would have turned a weird-but-harmless search into a 500 on a storefront
     * door. `stringifyTerm()` reproduces today's answer without the warning instead — see there for
     * why an array must not become "no filter".
     *
     * @template T of EloquentBuilder|QueryBuilder
     *
     * @param  T  $query
     * @param  mixed  $term
     * @return T
     */
    public static function applyNameFilter($query, $term)
    {
        $term = self::stringifyTerm($term);

        if ($term === '') {
            /* Today's doors only apply the filter for a non-empty term; an empty term is "no name
               filter", not "match the empty string". Preserved rather than reinterpreted. */
            return $query;
        }

        $raw = '%' . $term . '%';
        $folded = '%' . self::normalise($term) . '%';
        $stored = self::isStored();

        /*
         * THE GROUP IS NOT OPTIONAL. Without the closure the `orWhere` escapes every predicate the
         * caller already applied -- `store_id` and `status` included -- because AND binds tighter
         * than OR. See the class docblock; `GAP-341` is the measured precedent in this very handler.
         */
        $query->where(function ($inner) use ($raw, $folded, $stored) {
            $inner->where('name', 'LIKE', $raw);

            if ($stored) {
                /*
                 * EMITTED EVEN WHEN `$folded === $raw`, AND THE FIRST DRAFT OF THIS CLASS SKIPPED
                 * IT THEN AS A QUERY-SHAPE OPTIMISATION. That was wrong, and wrong in the direction
                 * the whole slice exists for. Folding is not symmetric between the two sides:
                 *
                 *     term  `شاشه`  folds to itself       -> the "optimisation" drops this limb
                 *     name  `شاشة`  folds to `شاشه`
                 *     raw limb: `name LIKE '%شاشه%'`      -> NO MATCH, the stored bytes differ
                 *
                 * so the shopper who types the bare, unadorned spelling -- the common case, and
                 * exactly the one `ArabicSearchText`'s docblock describes -- would have lost the
                 * product. The stored fold is a match on the HAYSTACK's spelling, which has nothing
                 * to do with whether the NEEDLE's spelling happened to change.
                 */
                $inner->orWhere(self::COLUMN, 'LIKE', $folded);
            }
        });

        return $query;
    }
}
