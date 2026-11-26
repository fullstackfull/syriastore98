<?php

namespace Tests\Support\Import;

use Tests\Support\Analytics\AnalyticsTenancyCensus;

/**
 * DATA-001 — THE IMPORT AND SYNC LAYER, WHICH NO EXISTING TENANCY CENSUS READS.
 * =================================================================================================
 *
 * `DATA-001` is "عزل بيانات المتاجر على جميع الطبقات" — isolate store data at EVERY LAYER. This
 * repository already measures most of them, and this class exists because of the one it does not:
 *
 *   - raw SQL, app/ and packages/      `RawQueryTenancyInventoryTest`   (measured: app 232, packages 45)
 *   - the builder-raw blind spot        `BuilderRawQueryVisibilityTest`
 *   - Eloquent, analytics and reports   `AnalyticsEloquentTenancyCensusTest`
 *   - Eloquent, owned controller paths  `OwnedPathRawQueryInventoryTest`
 *   - the service layer                 `ServiceLayerRawQueryInventoryTest`
 *   - scope-bypass verbs                `EloquentScopeBypassInventoryTest`
 *   - IMPORT AND SYNC                   nothing, until this class
 *
 * MEASURED, 2026-11-26: `grep -rln` over `tests/` for `ImportExport`, `ProductsImportData`,
 * `ProductCSV`, `ShopifyProductController` or `WoocomProductController` returns no tenancy census.
 * The import layer writes `products`, `categories`, `product_brands`, `product_labels`, `shippings`
 * and `taxes` — six tenant-discriminated tables, two of which carry money rules — and nothing
 * counted a single one of its reads.
 *
 * =================================================================================================
 *  WHY THIS IS NOT `AnalyticsTenancyCensus` POINTED AT MORE FILES
 * =================================================================================================
 *
 * It was going to be. `AnalyticsTenancyCensus::sitesIn()` is public precisely so it can be aimed at
 * one file, its comment stripping is tokenizer-based, and its classification has been corrected by
 * mutation twice. Reusing it is the cheap and right instinct.
 *
 * IT READS THE IMPORT LAYER'S CENTRAL DEFECT AS `principal`. Measured, before this class existed:
 *
 *     principal  packages/.../Imports/ProductsImportData.php:39  Category::firstOrCreate  getCurrentStore(
 *     principal  packages/.../Imports/ProductsImportData.php:54  ProductBrand::firstOrCreate  getCurrentStore(
 *     principal  packages/.../Imports/ProductsImportData.php:82  Shipping::firstOrCreate  getCurrentStore(
 *     principal  packages/.../Imports/ProductsImportData.php:94  Tax::firstOrCreate  getCurrentStore(
 *
 * All four credits are false, and they are false in the one direction that matters — crediting a
 * read with a predicate that constrains nothing. `firstOrCreate($lookup, $values)` takes TWO
 * arrays. `$lookup` becomes the `WHERE`. `$values` is merged in ONLY when no row matched. So
 *
 *     Category::firstOrCreate(['name' => $row['category_name']], ['store_id' => getCurrentStore(), ...])
 *
 * looks up a category BY NAME ACROSS EVERY TENANT, and the `store_id` the census saw is applied
 * only on the branch that does not run when another store already has that name. The statement
 * window contains the token `getCurrentStore(`; the query does not contain a store predicate. This
 * is CLAUDE.md §3's census failure in a new dress: the instrument keyed on a TOKEN'S PRESENCE and
 * the token was in the half of the call that is dead on the dangerous path.
 *
 * THAT BLIND SPOT IS LATENT IN THE LIVE CENSUS, NOT ACTIVE, AND THE DIFFERENCE IS MEASURED. Of
 * every site `AnalyticsTenancyCensus::files()` yields, the number whose call is `firstOrCreate`,
 * `firstOrNew` or `updateOrCreate` is **ZERO** — `test_the_shared_census_has_no_two_array_site_of_its_own`
 * executes that count rather than asserting it. So the shared instrument is not reporting a wrong
 * number today, and this class deliberately does NOT modify it: its totals are asserted by a
 * REQUIRED CI job, and widening a shared ratchet to fix a defect in files it does not read is how
 * two workstreams collide. The finding is reported to the coordinator instead.
 *
 * =================================================================================================
 *  THE THREE VERDICTS — ABOUT THE LOOKUP, NEVER THE STATEMENT
 * =================================================================================================
 *
 *  scoped      the LOOKUP carries a `store_id` predicate whose value is principal-derived
 *              (`getCurrentStore()`, `Auth::user()`, or a variable this file binds from one).
 *
 *  unscoped    the LOOKUP carries no store predicate, on a table that HAS a `store_id` column.
 *              This is the finding. It is not "untidy": for `Category`, `ProductBrand`,
 *              `ProductLabel`, `Shipping` and `Tax` there is NO global scope to fall back on —
 *              CLAUDE.md measures that `Product` and `Category` add no predicate for ANY
 *              principal, and the other four carry no `StoreScope` at all — and
 *              `null_tenant => 'open'` adds nothing either. An unscoped lookup here reaches every
 *              tenant's row, full stop.
 *
 *  structural  the model's table has NO `store_id` column, so a predicate is impossible rather
 *              than forgotten. `Country`, `State` and `City` are platform reference data; the
 *              shared census reaches the same conclusion independently in its own `NOT_WATCHED`.
 *              Reporting these as `unscoped` would be a false accusation, which is why they are a
 *              third verdict and not an omission from the list.
 *
 * =================================================================================================
 *  THE FOUR LIMITS, STATED UP FRONT
 * =================================================================================================
 *
 * LIMIT 1. `scoped` IS NOT A PROOF OF CORRECTNESS, only that a principal-derived store predicate
 * constrains the lookup. A lookup scoped to the caller's own store that should have been scoped to
 * some other row's store still reads `scoped`. The `unscoped` count is a LOWER BOUND on exposure.
 *
 * LIMIT 2. THE WINDOW IS THE MATCHED LINE THROUGH THE FIRST `;`, capped at 40 lines — and for a
 * two-array call, the FIRST ARGUMENT of that window and nothing else. A predicate applied to the
 * builder in a later statement, or in another method, reads `unscoped`. Every such case in the
 * censused files is dispositioned by hand in the test's own table rather than guessed at.
 *
 * LIMIT 3. THE MODEL LIST IS NAMED, and it is the shared census's `UNSCOPED_MODELS` plus
 * `ProductLabel`. `ProductLabel` sits in the shared census's `NOT_WATCHED` as "catalogue
 * presentation; no figure", which is a true statement about ANALYTICS and the wrong question here:
 * `product_labels` carries `store_id`, the product importer looks one up by name alone, and the
 * consequence is a cross-tenant foreign key whether or not a label ever reaches a report. The test
 * asserts this list against `scoped_models` and the live schema in both directions.
 *
 * LIMIT 4. COMMENTS ARE STRIPPED THROUGH `AnalyticsTenancyCensus::codeOnly()`, i.e. PHP's own
 * tokenizer, so prose about a defect can neither satisfy nor break this census. CLAUDE.md §3
 * records eight occasions on which it did. The stripper preserves newlines, so every line number
 * reported here is the line number in the real file.
 */
final class ImportLayerTenancyCensus
{
    /**
     * THE FILES, NAMED RATHER THAN GLOBBED, and why each is in.
     *
     * Named because a glob over `app/Http/Controllers` would pull in the whole application, and a
     * glob over the module would pull in its views and migrations. Each entry is a surface that
     * READS OR WRITES a tenant-discriminated catalogue row on behalf of an import or a storefront
     * sync.
     *
     * @var list<string>
     */
    public const FILES = [
        /* THE VENDORED PRODUCT IMPORTER AND ITS SIX SIBLINGS. `ProductsImportData` is the subject;
           the siblings are in because they are the CONTROL — four of them carry the predicate the
           product importer omits, in the same module, by the same author, which is what makes the
           omission an omission rather than a design. */
        'packages/workdo/ImportExport/src/app/Imports/BrandImportData.php',
        'packages/workdo/ImportExport/src/app/Imports/CityImportData.php',
        'packages/workdo/ImportExport/src/app/Imports/CountryImportData.php',
        'packages/workdo/ImportExport/src/app/Imports/LabelImportData.php',
        'packages/workdo/ImportExport/src/app/Imports/MaincategoryImportData.php',
        'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php',
        'packages/workdo/ImportExport/src/app/Imports/StateImportData.php',
        'packages/workdo/ImportExport/src/app/Imports/SubcategoryImportData.php',

        /* THE EXPORT HALF, because an export that reads another tenant's rows is the same defect
           pointed outwards, and `ProductsExportData` is what the merchant downloads. */
        'packages/workdo/ImportExport/src/app/Exports/ProductsExportData.php',
        'packages/workdo/ProductCSV/src/app/Exports/ProductsExportData.php',
        'packages/workdo/ProductCSV/src/app/Http/Controllers/ProductCSVController.php',

        /* THE HOST-SIDE STOREFRONT SYNCS. These have had ownership work done on them already
           (`ImportMappingOwnership`, `WoocomProductOwnershipTest`, `MerchantCategoryOwnershipTest`),
           so they are in to HOLD that work rather than to accuse it. */
        'app/Http/Controllers/ShopifyProductController.php',
        'app/Http/Controllers/ShopifyCategoryController.php',
        'app/Http/Controllers/ShopifyCustomerController.php',
        'app/Http/Controllers/ShopifyCouponController.php',
        'app/Http/Controllers/WoocomProductController.php',
        'app/Http/Controllers/WoocomCategoryController.php',
        'app/Http/Controllers/WoocomCustomerController.php',
        'app/Http/Controllers/WoocomCouponController.php',
    ];

    /**
     * THE CALLS WHOSE FIRST ARGUMENT IS THE WHOLE LOOKUP.
     *
     * `firstOrCreate`, `firstOrNew` and `updateOrCreate` all take `($lookup, $values)`. For
     * `updateOrCreate` the second array is written on BOTH branches, which makes a `store_id`
     * there worse than useless on the match branch: it OVERWRITES another tenant's row's store_id.
     *
     * @var list<string>
     */
    public const TWO_ARRAY_CALLS = ['firstOrCreate', 'firstOrNew', 'updateOrCreate'];

    /**
     * MODELS WHOSE TABLE HAS NO `store_id` COLUMN — a predicate is impossible, not forgotten.
     *
     * Measured with `Schema::hasColumn()` in both directions by the test, which is CLAUDE.md's
     * rule: the migrations are not the schema.
     *
     * @var list<string>
     */
    public const STRUCTURAL = ['Country', 'State', 'City'];

    /**
     * NON-MODEL CLASSES THAT SHARE A WATCHED MODEL'S SHORT NAME, and must not be censused.
     *
     * THIS IS THE FALSE-POSITIVE THAT WOULD HAVE MADE THE WHOLE TABLE WRONG. `WoocomProductController`
     * imports `Codexshaper\WooCommerce\Facades\Product`, `...\Facades\Category` and
     * `...\Facades\Variation` — so `Product::all([...])` and `Category::find($id)` in that file are
     * HTTP calls to the merchant's own remote WooCommerce store, not reads of this database. Nine
     * sites. Counting them as unscoped local reads would have reported a cross-tenant leak in a
     * file that never touches the local table, and a reviewer who checked one would have had cause
     * to distrust the rest.
     *
     * Resolved per file from its own `use` statements rather than hardcoded, so a file that imports
     * the real model keeps its sites. Keyed as model short name => the namespace prefix that means
     * "not ours".
     *
     * @var list<string>
     */
    public const FOREIGN_NAMESPACE_PREFIXES = [
        'Codexshaper\\',
        'Maatwebsite\\',
        'Illuminate\\',
    ];

    public function __construct(private readonly string $root)
    {
    }

    /** The model short names this census watches: the shared list plus `ProductLabel`. */
    public function watched(): array
    {
        $models = AnalyticsTenancyCensus::UNSCOPED_MODELS;
        $models[] = 'ProductLabel';

        sort($models);

        return array_values(array_unique($models));
    }

    /**
     * Every site in the import and sync layer, with its verdict.
     *
     * @return list<array{file: string, line: int, model: string, call: string, verdict: string, lookup: string}>
     */
    public function sites(): array
    {
        $sites = [];

        foreach (self::FILES as $relative) {
            foreach ($this->sitesIn($relative) as $site) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /**
     * The sites of ONE file, so the pattern can be self-tested against a file of known shape.
     *
     * @return list<array{file: string, line: int, model: string, call: string, verdict: string, lookup: string}>
     */
    public function sitesIn(string $relative): array
    {
        if (! is_file($this->root . '/' . $relative)) {
            return [];
        }

        $code = (new AnalyticsTenancyCensus($this->root))->codeOnly($relative);
        $lines = explode("\n", $code);
        $foreign = $this->foreignShortNames($code);

        /*
         * The exclusions are the shared census's, for the reason its own docblock gives: `\w` stops
         * `SomeOrder::`, `$` stops `$Order::`, `>` stops `->Order::`, and a BACKSLASH IS NOT
         * EXCLUDED because excluding it hid 34 fully-qualified reads there.
         */
        $pattern = '/(?<![\w$>])(' . implode('|', $this->watched()) . ')::(\w+)\s*\(/';

        $sites = [];

        foreach ($lines as $index => $line) {
            if (! preg_match($pattern, $line, $match)) {
                continue;
            }

            [$model, $call] = [$match[1], $match[2]];

            if (in_array($model, $foreign, true)) {
                continue;
            }

            /*
             * `slugs()` and the other pure helpers are not reads. `Product::slugs($name)` builds a
             * string; it has no `WHERE` to scope and reporting it would pad the table with sites
             * nobody can fix. Measured: the shared census reports three of them in these files.
             */
            if (in_array($call, ['slugs', 'query'], true)) {
                continue;
            }

            $statement = $this->statementAt($lines, $index);

            $lookup = in_array($call, self::TWO_ARRAY_CALLS, true)
                ? $this->firstArgument($statement, $model . '::' . $call)
                : $statement;

            $sites[] = [
                'file' => $relative,
                'line' => $index + 1,
                'model' => $model,
                'call' => $call,
                'verdict' => $this->classify($model, $lookup, implode("\n", $lines)),
                'lookup' => $this->collapse($lookup),
            ];
        }

        return $sites;
    }

    /** scoped | unscoped | structural */
    private function classify(string $model, string $lookup, string $file): string
    {
        if (in_array($model, self::STRUCTURAL, true)) {
            return 'structural';
        }

        if (! preg_match('/[\'"]?store_id[\'"]?/', $lookup)) {
            return 'unscoped';
        }

        /*
         * A `store_id` whose VALUE is caller-supplied is not a tenant check -- CLAUDE.md: "it only
         * compares the caller's own two values". So the predicate must resolve to the principal.
         * `$storeId` and the other locals are traced to their binding in the file; a binding from
         * `getCurrentStore()` or `Auth::user()` is principal-derived and anything else is not.
         */
        if ($this->isPrincipalDerived($lookup, $file)) {
            return 'scoped';
        }

        return 'unscoped';
    }

    /** Expressions whose value can only have come from the authenticated principal. */
    private const PRINCIPAL_TOKENS = [
        'getCurrentStore(',
        'Auth::user(',
        'auth()->user(',
        'creatorId(',
        'current_store',
    ];

    private function isPrincipalDerived(string $lookup, string $file): bool
    {
        foreach (self::PRINCIPAL_TOKENS as $token) {
            if (str_contains($lookup, $token)) {
                return true;
            }
        }

        if (! preg_match('/[\'"]store_id[\'"]\s*(?:,|=>)\s*\$(\w+)/', $lookup, $m)) {
            return false;
        }

        return $this->variableIsPrincipalDerived($m[1], $file, 2);
    }

    /**
     * TWO LEVELS OF INDIRECTION AND NO MORE, and the second level was not a refinement — it was
     * the difference between a finding and a false accusation.
     *
     * `ImportExport`'s `ProductsExportData::collection()` is
     *
     *     $storeId = getCurrentStore() ?? \Auth::user()->current_store;
     *     $store   = Store::findOrFail($storeId);
     *     $products = Product::where('store_id', $store->id)->...
     *
     * At one level, `$store`'s only assignment is `Store::findOrFail($storeId)`, which contains no
     * principal token, so the export of the merchant's OWN catalogue read as `unscoped` and sat in
     * the table beside six real cross-tenant defects. A reader who checked that row and found it
     * correct would have had cause to distrust the other eighteen. Following `$storeId` one level
     * further reaches `getCurrentStore()` and the row is `scoped`, which it is.
     *
     * THE FAILURE DIRECTION IS DELIBERATE. A variable counts only when EVERY assignment to it in
     * the file is principal-derived; any assignment from anything else, and an unknown, both read
     * `unscoped`. So the tracing can withdraw a false accusation and cannot manufacture a credit —
     * which is the right way round for a census whose `unscoped` column is a lower bound.
     */
    private function variableIsPrincipalDerived(string $variable, string $file, int $depth): bool
    {
        if ($depth <= 0) {
            return false;
        }

        if (! preg_match_all('/\$' . preg_quote($variable, '/') . '\s*=\s*([^;]+);/', $file, $all)) {
            return false;
        }

        foreach ($all[1] as $value) {
            $ok = false;

            foreach (self::PRINCIPAL_TOKENS as $token) {
                if (str_contains($value, $token)) {
                    $ok = true;
                }
            }

            /*
             * `$store = Store::findOrFail($storeId)` and `$x = $y` alike: follow the variables the
             * right-hand side names. `request()` and `$request->` are NOT followed, deliberately —
             * CLAUDE.md: a predicate built from caller-supplied input is not a tenant check.
             */
            if (! $ok && ! preg_match('/request\(|\$request->/', $value)) {
                foreach (array_unique($this->variablesIn($value)) as $inner) {
                    if ($inner !== $variable && $this->variableIsPrincipalDerived($inner, $file, $depth - 1)) {
                        $ok = true;
                    }
                }
            }

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> the variable names an expression mentions */
    private function variablesIn(string $expression): array
    {
        preg_match_all('/\$(\w+)/', $expression, $found);

        return $found[1];
    }

    /**
     * The FIRST argument of a two-array call: the text between its opening parenthesis and the
     * comma that closes the first array, counting nesting so a comma inside the array does not end
     * it.
     */
    private function firstArgument(string $statement, string $needle): string
    {
        $at = strpos($statement, $needle);

        if ($at === false) {
            return $statement;
        }

        $open = strpos($statement, '(', $at);

        if ($open === false) {
            return $statement;
        }

        $depth = 0;

        for ($i = $open, $n = strlen($statement); $i < $n; $i++) {
            $char = $statement[$i];

            if ($char === '(' || $char === '[') {
                $depth++;

                continue;
            }

            if ($char === ')' || $char === ']') {
                $depth--;

                if ($depth === 0) {
                    return substr($statement, $open + 1, $i - $open - 1);
                }

                continue;
            }

            if ($char === ',' && $depth === 1) {
                return substr($statement, $open + 1, $i - $open - 1);
            }
        }

        return $statement;
    }

    /** The matched line through the first `;`, capped at 40 lines. LIMIT 2. */
    private function statementAt(array $lines, int $index): string
    {
        $out = [];

        for ($i = $index, $n = min($index + 40, count($lines)); $i < $n; $i++) {
            $out[] = $lines[$i];

            if (str_contains($lines[$i], ';')) {
                break;
            }
        }

        return implode("\n", $out);
    }

    /**
     * The short names in this file that resolve to a class OUTSIDE this application.
     *
     * @return list<string>
     */
    private function foreignShortNames(string $code): array
    {
        $foreign = [];

        if (! preg_match_all('/^\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $code, $all, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($all as $use) {
            $fqcn = $use[1];
            $short = $use[2] ?? substr($fqcn, (int) strrpos($fqcn, '\\') + 1);

            foreach (self::FOREIGN_NAMESPACE_PREFIXES as $prefix) {
                if (str_starts_with($fqcn, $prefix)) {
                    $foreign[] = $short;
                }
            }
        }

        return array_values(array_unique($foreign));
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
