<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Analytics\AnalyticsTenancyCensus;
use Tests\Support\Import\ImportLayerTenancyCensus;

/**
 * DATA-001 — THE IMPORT AND SYNC LAYER'S ELOQUENT TENANCY, SITE BY SITE.
 * =================================================================================================
 *
 * `DATA-001` is "isolate store data at EVERY LAYER". `ImportLayerTenancyCensus`'s docblock lists the
 * layers this repository already measures and the one it did not: import and sync. This file is the
 * ratchet over that layer.
 *
 * WHAT IT FOUND, and this is the reason the file exists rather than a by-product of writing it:
 * `ProductsImportData` — the vendored product CSV importer, the handler behind the merchant's
 * "import products" button — looks up SIX tenant-discriminated rows with no store predicate, while
 * FOUR of its five siblings in the same module carry one. The consequences are two defects, in
 * opposite directions, from the same omission:
 *
 *   ADOPTION. `Category::firstOrCreate(['name' => $row['category_name']], ['store_id' => ...])`
 *   matches by NAME across every tenant. A merchant importing "Shoes" when another store already
 *   has a "Shoes" category gets THAT store's row back, and the product is created with a
 *   `category_id` pointing into another tenant. The same shape repeats for `ProductBrand`,
 *   `ProductLabel`, `Shipping` and `Tax` — and the last two are not labels: a product whose
 *   `shipping_id` and `tax_id` name another store's rows is priced and shipped by another
 *   merchant's configuration.
 *
 *   SUPPRESSION. `Product::where('name', ...)->where('category_id', $maincategory->id)` is the
 *   duplicate check, and `Product` adds no predicate for any principal either. Having adopted the
 *   foreign category above, it then finds that store's product of the same name, marks the row
 *   "Skipped: Duplicate product", and the importing merchant's product is SILENTLY NEVER CREATED.
 *
 * NEITHER IS FIXED HERE, AND THE REASON IS A CONSTRAINT RATHER THAN A JUDGEMENT. The file is
 * `packages/workdo/ImportExport/`, vendored WorkDo code, which this project does not edit. The
 * host-side lever that would fix it — putting `Category` and `Product` into
 * `config/tenancy.php`'s `scoped_models` — is measured as unsafe in that file's own docblock: 785
 * of the 1,282 existing `store_id` filters pass a store that is NOT the current one, and a scope
 * keyed on the current store composes with those into `store_id = A AND store_id = B`, which
 * matches no row. So the finding is COUNTED AND DECLARED here, in the shape
 * `EloquentScopeBypassInventoryTest` uses for `forceDelete` and `truncate`
 * ("...and that is an open finding"), and escalated rather than papered over.
 *
 * =================================================================================================
 *  AND A SECOND, WORSE ONE THAT IS NOT IN THE IMPORTER AT ALL
 * =================================================================================================
 *
 * `ProductCSV`'s export route is
 *
 *     Route::middleware(['web'])->group(function () {
 *         Route::get('{storeslug}/csvExport/{id}', [ProductCSVController::class, "csvExport"]);
 *     });
 *
 * — `web` ALONE. No `auth`, no `PlanModuleCheck`, and `csvExport()` asks no permission question. It
 * runs `Product::where('id', $id)->first()` and hands the id to `ProductsExportData`, which runs
 * `Product::where('id', request()->route('id'))`. BOTH ignore the store. `ProductsExportData`
 * resolves a `$store` from the slug or the session and then NEVER USES IT for the product query, so
 * the `{storeslug}` segment is decoration. The CSV carries price, sale price, brand, label, tax,
 * tax status, weight, PRODUCT STOCK, stock status and the low-stock threshold.
 *
 * So any visitor, signed in or not, can export any store's product by its id. It is latent only in
 * that the route exists while the `ProductCSV` module is loaded. Vendored, so declared here with
 * its own named test rather than fixed, and reported for a host-side route override — which
 * CLAUDE.md says is available ("later route declarations replace earlier ones for the same
 * method+URI") but which changes a shipped merchant-facing URL and is therefore the coordinator's
 * call, not this census's.
 *
 * =================================================================================================
 *  HOW TO READ THE TABLE
 * =================================================================================================
 *
 * `unscoped` IS NOT THE SAME AS `defect`, and conflating them would make this census an accusation
 * rather than a measurement. Of the 18 unscoped sites, 8 are open findings and 10 are sound for
 * reasons the census cannot see — a tenant column that is not `store_id`, a row already
 * ownership-checked by its caller, or a store taken from the route as a storefront handler must.
 * Every one of the 10 carries its reason in `DISPOSITIONS` and the reasons are asserted, so a
 * sound site cannot quietly become a defect without this file failing.
 */
final class ImportLayerTenancyCensusTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * EVERY SITE, ITS VERDICT AND ITS DISPOSITION. Measured 2026-11-26.
     *
     * The dispositions, and what each means for a reader deciding whether to act:
     *
     *   ok                  the lookup carries a principal-derived store predicate.
     *   ok_other_column     unscoped as to `store_id`, and that is the WRONG QUESTION for this
     *                       model: `stores` has no `store_id` (the tenant is `stores.id`) and
     *                       `users` has none either (the tenant root is `users.created_by`). The
     *                       shared census records the same limit as its LIMIT 6.
     *   ok_derived          the lookup is keyed on a row the caller has ALREADY ownership-checked,
     *                       so the predicate is the parent's. Weaker than `ok` and named as such.
     *   ok_route_store      a storefront handler taking its store from the route, which CLAUDE.md
     *                       states is the correct source there — `getCurrentStore()` answers for a
     *                       merchant panel session and is null for a shopper.
     *   OPEN                a real exposure. Count and severity below.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>  "file:line" => [verdict, disposition, why]
     */
    private const DISPOSITIONS = [
        // ---------------------------------------------------------------- the vendored CSV importer
        'packages/workdo/ImportExport/src/app/Imports/BrandImportData.php:30' => ['scoped', 'ok',
            'the sibling that gets it right: ->where(store_id, getCurrentStore()) on the duplicate check'],
        'packages/workdo/ImportExport/src/app/Imports/LabelImportData.php:30' => ['scoped', 'ok',
            'as BrandImportData'],
        'packages/workdo/ImportExport/src/app/Imports/MaincategoryImportData.php:33' => ['scoped', 'ok',
            'as BrandImportData'],
        'packages/workdo/ImportExport/src/app/Imports/MaincategoryImportData.php:75' => ['unscoped', 'OPEN',
            'LOW. A slug-uniqueness probe: Category::select()->where(slug, like, ...)->where(id, <>, $id). '
            . 'It reads other tenants\' slugs to avoid a collision and the caller uses the result to '
            . 'suffix a slug, so no tenant row is shown to anyone -- but slugs are guessable store '
            . 'content and the predicate is still missing. Counted, not alarmed about.'],
        'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php:39' => ['unscoped', 'OPEN',
            'HIGH. Category::firstOrCreate by NAME across every tenant -> the imported product gets '
            . 'another store\'s category_id. The store_id in the second argument applies only on the '
            . 'create branch, which is the branch that does NOT run when another store already has '
            . 'the name.'],
        'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php:54' => ['unscoped', 'OPEN',
            'HIGH. ProductBrand::firstOrCreate by name, as :39'],
        'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php:69' => ['unscoped', 'OPEN',
            'HIGH. ProductLabel::firstOrCreate by name, as :39'],
        'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php:82' => ['unscoped', 'OPEN',
            'HIGH, AND IT IS NOT A LABEL. Shipping::firstOrCreate by name -> the product\'s shipping_id '
            . 'names another store\'s shipping row, so another merchant\'s configuration prices its '
            . 'delivery.'],
        'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php:94' => ['unscoped', 'OPEN',
            'HIGH, AND IT IS MONEY. Tax::firstOrCreate by name -> the product\'s tax_id names another '
            . 'store\'s tax row.'],
        'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php:103' => ['unscoped', 'OPEN',
            'HIGH, AND IT LOSES DATA SILENTLY. The duplicate check Product::where(name)->where(category_id) '
            . 'reads every tenant\'s catalogue, so a name another store already uses in the adopted '
            . 'category makes the row "Skipped: Duplicate product" and the importing merchant never '
            . 'gets the product.'],
        'packages/workdo/ImportExport/src/app/Imports/SubcategoryImportData.php:33' => ['unscoped', 'OPEN',
            'HIGH. The PARENT-category lookup, Category::firstOrCreate by name -- in the one sibling '
            . 'whose own duplicate check (:45) IS scoped. So the sub-category is correctly confined to '
            . 'this store and hangs off a parent that may belong to another.'],
        'packages/workdo/ImportExport/src/app/Imports/SubcategoryImportData.php:45' => ['scoped', 'ok',
            'the duplicate check is scoped; only its parent lookup at :33 is not'],

        // ---------------------------------------------------------------------------- the exports
        'packages/workdo/ImportExport/src/app/Exports/ProductsExportData.php:37' => ['unscoped', 'ok_other_column',
            '`stores` has no store_id column; Store::findOrFail($storeId) with $storeId = '
            . 'getCurrentStore() ?? Auth::user()->current_store IS the principal\'s own store'],
        'packages/workdo/ImportExport/src/app/Exports/ProductsExportData.php:39' => ['scoped', 'ok',
            'Product::where(store_id, $store->id), traced two levels to getCurrentStore()'],
        'packages/workdo/ProductCSV/src/app/Exports/ProductsExportData.php:39' => ['unscoped', 'ok_other_column',
            'as ImportExport ProductsExportData:37'],
        'packages/workdo/ProductCSV/src/app/Exports/ProductsExportData.php:41' => ['unscoped', 'ok_route_store',
            'Store::where(slug, request()->route(storeslug)) -- the storefront branch, taken when no '
            . 'panel session exists. `stores` has no store_id, and the route is the correct source '
            . 'for a storefront handler. THE STORE IT RESOLVES IS THEN NEVER USED, which is :44\'s '
            . 'finding, not this line\'s.'],
        'packages/workdo/ProductCSV/src/app/Exports/ProductsExportData.php:44' => ['unscoped', 'OPEN',
            'CRITICAL. Product::where(id, request()->route(id)) -- a bare id, on an UNAUTHENTICATED '
            . 'route, ignoring the $store resolved two lines above. Exports any store\'s product with '
            . 'its price, stock and low-stock threshold.'],
        'packages/workdo/ProductCSV/src/app/Http/Controllers/ProductCSVController.php:85' => ['unscoped', 'OPEN',
            'CRITICAL. The same door\'s controller half: Product::where(id, $id)->first() with no '
            . 'store predicate and no permission check, behind middleware [web] alone.'],

        // ------------------------------------------------------------- the host-side storefront syncs
        'app/Http/Controllers/ShopifyProductController.php:143' => ['unscoped', 'ok_other_column',
            '`users` has no store_id; User::find($user->creatorId()) is the caller\'s OWN tenant root'],
        'app/Http/Controllers/ShopifyProductController.php:160' => ['scoped', 'ok', 'Category::where(store_id, $storeId)'],
        'app/Http/Controllers/ShopifyProductController.php:450' => ['scoped', 'ok', 'as :160'],
        'app/Http/Controllers/ShopifyProductController.php:608' => ['unscoped', 'ok_derived',
            'ProductVariant::where(product_id, $product->id) where $product is the return of '
            . 'ImportMappingOwnership::owned() -- already compared with the caller\'s store'],
        'app/Http/Controllers/ShopifyProductController.php:623' => ['unscoped', 'ok_derived', 'as :608'],
        'app/Http/Controllers/ShopifyProductController.php:626' => ['unscoped', 'ok_derived', 'as :608'],
        'app/Http/Controllers/ShopifyProductController.php:652' => ['scoped', 'ok', 'ProductImage::where(..., store_id, $storeId)'],
        'app/Http/Controllers/WoocomProductController.php:104' => ['unscoped', 'ok_other_column',
            'as ShopifyProductController:143'],
    ];

    private function census(): ImportLayerTenancyCensus
    {
        return new ImportLayerTenancyCensus(base_path());
    }

    /**
     * THE RATCHET. Every site, with its verdict, asserted as a SET in both directions.
     *
     * A new unscoped read in the import layer is an undeclared key and fails. A site that gains a
     * predicate is a changed verdict and fails, so the table cannot keep claiming a defect that has
     * been fixed — CLAUDE.md records a register becoming decoration when its entries outlived their
     * subjects.
     */
    public function test_the_import_layer_verdicts_are_exactly_these(): void
    {
        $actual = [];

        foreach ($this->census()->sites() as $site) {
            $actual[$site['file'] . ':' . $site['line']] = $site['verdict'];
        }

        $expected = [];

        foreach (self::DISPOSITIONS as $key => [$verdict]) {
            $expected[$key] = $verdict;
        }

        ksort($actual);
        ksort($expected);

        $this->assertSame($expected, $actual,
            'the import and sync layer\'s Eloquent tenancy has changed. Add the new site to '
            . 'DISPOSITIONS with its verdict AND its reason, or -- if a verdict improved -- update '
            . 'it and say so.');
    }

    /** The counts, so a reader has the one-line summary and it cannot drift from the table. */
    public function test_the_import_layer_totals_are_exactly_these(): void
    {
        $verdicts = array_count_values(array_map(
            static fn (array $row): string => $row[0],
            array_values(self::DISPOSITIONS)
        ));

        $dispositions = array_count_values(array_map(
            static fn (array $row): string => $row[1],
            array_values(self::DISPOSITIONS)
        ));

        $this->assertSame(['scoped' => 8, 'unscoped' => 18], [
            'scoped' => $verdicts['scoped'],
            'unscoped' => $verdicts['unscoped'],
        ]);

        $this->assertSame([
            'ok' => 8,
            'ok_other_column' => 4,
            'ok_derived' => 3,
            'ok_route_store' => 1,
            'OPEN' => 10,
        ], [
            'ok' => $dispositions['ok'],
            'ok_other_column' => $dispositions['ok_other_column'],
            'ok_derived' => $dispositions['ok_derived'],
            'ok_route_store' => $dispositions['ok_route_store'],
            'OPEN' => $dispositions['OPEN'],
        ], 'ten open findings: seven HIGH in the vendored product importer, one LOW slug probe, and '
            . 'two CRITICAL on the unauthenticated ProductCSV export door.');
    }

    /** Every disposition carries a reason, and an `OPEN` one carries a severity word. */
    public function test_every_site_states_its_reason_and_every_open_finding_its_severity(): void
    {
        foreach (self::DISPOSITIONS as $key => [$verdict, $disposition, $why]) {
            $this->assertNotSame('', trim($why), $key . ' has no stated reason.');

            if ($disposition !== 'OPEN') {
                continue;
            }

            $this->assertMatchesRegularExpression('/^(CRITICAL|HIGH|LOW)\b/', trim($why),
                $key . ' is an open finding with no severity, so a reader cannot tell it from the '
                . 'slug probe.');
        }
    }

    /**
     * THE ONE THIS CENSUS EXISTS FOR, named so a reader of the test list sees it.
     *
     * Declared, not fixed: vendored file, and the host-side scope lever is measured as unsafe.
     */
    public function test_the_vendored_product_importer_looks_up_six_tenant_rows_with_no_store_predicate_and_that_is_an_open_finding(): void
    {
        $sites = $this->census()->sitesIn(
            'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php'
        );

        $unscoped = array_values(array_filter(
            $sites,
            static fn (array $site): bool => $site['verdict'] === 'unscoped'
        ));

        $this->assertCount(6, $unscoped,
            'the product importer\'s unscoped lookup count has changed. If it went DOWN the defect '
            . 'is being fixed and this test and DISPOSITIONS must be updated; if it went UP, a '
            . 'seventh has been added.');

        $this->assertSame(
            ['Category', 'ProductBrand', 'ProductLabel', 'Shipping', 'Tax', 'Product'],
            array_map(static fn (array $site): string => $site['model'], $unscoped),
            'the models the product importer reaches across tenants have changed'
        );

        /*
         * AND THE LOOKUP REALLY IS THE FIRST ARGUMENT. Without this the `firstOrCreate` sites would
         * be indistinguishable from a site with no `store_id` anywhere -- and the whole reason this
         * census exists is that the shared instrument read all five as `principal` because the
         * token was present in the SECOND argument.
         */
        foreach ($unscoped as $site) {
            if (! in_array($site['call'], ImportLayerTenancyCensus::TWO_ARRAY_CALLS, true)) {
                continue;
            }

            $this->assertStringNotContainsString('store_id', $site['lookup'],
                $site['file'] . ':' . $site['line'] . ' -- the lookup argument now mentions '
                . 'store_id, so either the defect is fixed or the first-argument extraction is '
                . 'taking the wrong text.');
        }
    }

    /** The second open door, named for the same reason. */
    public function test_the_product_csv_export_reads_a_product_by_bare_id_on_an_unauthenticated_route_and_that_is_an_open_finding(): void
    {
        $routes = (string) file_get_contents(
            base_path('packages/workdo/ProductCSV/src/routes/web.php')
        );

        /*
         * THE ROUTE'S MIDDLEWARE IS THE SEVERITY, so it is measured rather than described. If an
         * `auth` is ever added to this group the finding drops from CRITICAL to HIGH and this test
         * must be revisited -- which is exactly what it is for.
         */
        $this->assertMatchesRegularExpression(
            '/Route::middleware\(\s*\[\s*[\'"]web[\'"]\s*\]\s*\)->group\(\s*function\s*\(\)\s*\{\s*Route::get\(\s*[\'"]\{storeslug\}\/csvExport\/\{id\}[\'"]/s',
            $routes,
            'the ProductCSV export route group is no longer `[web]` alone. If it gained auth or a '
            . 'module gate, this finding\'s severity has changed and DISPOSITIONS must say so.'
        );

        foreach ([
            'packages/workdo/ProductCSV/src/Http/Controllers/ProductCSVController.php' => null,
            'packages/workdo/ProductCSV/src/app/Http/Controllers/ProductCSVController.php' => 85,
            'packages/workdo/ProductCSV/src/app/Exports/ProductsExportData.php' => 44,
        ] as $file => $line) {
            if ($line === null) {
                continue;
            }

            $found = array_values(array_filter(
                $this->census()->sitesIn($file),
                static fn (array $site): bool => $site['line'] === $line
            ));

            $this->assertCount(1, $found, $file . ':' . $line . ' is gone -- if the handler was '
                . 'fixed, update DISPOSITIONS.');
            $this->assertSame('unscoped', $found[0]['verdict'], $file . ':' . $line);
            $this->assertSame('Product', $found[0]['model'], $file . ':' . $line);
        }
    }

    /**
     * THE PREMISE THAT THE TWO-ARRAY SPLIT IS THE WHOLE POINT, and that the shared instrument
     * really does read these sites the other way.
     *
     * Executed rather than asserted, because "the shared census would have credited this" is the
     * claim that justifies a second instrument existing, and a docblock saying so would rot.
     */
    public function test_the_shared_census_credits_the_very_sites_this_one_refuses(): void
    {
        $relative = 'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php';

        $shared = [];

        foreach ((new AnalyticsTenancyCensus(base_path()))->sitesIn($relative) as $site) {
            if (in_array($site['call'], ImportLayerTenancyCensus::TWO_ARRAY_CALLS, true)) {
                $shared[$site['line']] = $site['verdict'];
            }
        }

        $this->assertNotSame([], $shared, 'the shared census no longer reaches these sites at all');

        foreach ($shared as $line => $verdict) {
            $this->assertSame('principal', $verdict, $relative . ':' . $line
                . ' -- the shared census no longer credits this site, so its two-array blind spot '
                . 'has been fixed and THIS instrument is the redundant one.');

            $mine = array_values(array_filter(
                $this->census()->sitesIn($relative),
                static fn (array $site): bool => $site['line'] === $line
            ));

            $this->assertSame('unscoped', $mine[0]['verdict'], $relative . ':' . $line);
        }
    }

    /**
     * AND THE BLIND SPOT IS LATENT THERE RATHER THAN ACTIVE — measured, so the decision not to
     * widen a REQUIRED-CI ratchet is grounded.
     *
     * If this ever fails, the shared census HAS acquired a two-array site, its totals are wrong by
     * however many, and that is a finding about a required gate rather than about this layer.
     */
    public function test_the_shared_census_has_no_two_array_site_of_its_own(): void
    {
        $shared = new AnalyticsTenancyCensus(base_path());
        $found = [];

        foreach ($shared->sites() as $site) {
            if (in_array($site['call'], ImportLayerTenancyCensus::TWO_ARRAY_CALLS, true)) {
                $found[] = $site['file'] . ':' . $site['line'] . ' ' . $site['model'] . '::' . $site['call'];
            }
        }

        $this->assertSame([], $found,
            'the analytics census now reads a firstOrCreate/firstOrNew/updateOrCreate site, where a '
            . 'store_id in the SECOND argument reads as a predicate and constrains nothing. Its '
            . 'verdict totals are a required CI assertion, so this needs fixing there.');
    }

    /** Foreign short names are excluded, which is what keeps nine WooCommerce API calls out. */
    public function test_the_woocommerce_sdk_facades_are_not_censused_as_local_models(): void
    {
        $relative = 'app/Http/Controllers/WoocomProductController.php';

        $source = (string) file_get_contents(base_path($relative));

        $this->assertStringContainsString('use Codexshaper\WooCommerce\Facades\Product;', $source,
            'the premise is gone: this file no longer aliases a foreign Product, so the exclusion '
            . 'this test checks is no longer load-bearing');

        $models = array_map(
            static fn (array $site): string => $site['model'],
            $this->census()->sitesIn($relative)
        );

        $this->assertNotContains('Product', $models,
            'Product:: in this file is Codexshaper\WooCommerce\Facades\Product -- an HTTP call to '
            . "the merchant's own remote WooCommerce store, not a read of this database. Counting "
            . 'those as unscoped local reads would report a cross-tenant leak in a file that never '
            . 'touches the local table.');

        $this->assertNotContains('Category', $models, 'as Product: the WooCommerce Category facade');
    }

    /**
     * THE WATCH LIST AND THE STRUCTURAL LIST, MEASURED AGAINST THE LIVE SCHEMA IN BOTH DIRECTIONS.
     *
     * CLAUDE.md: the migrations are not the schema. `Schema::hasColumn()` is the check, and the
     * direction that asserts the CONVERSE is the one that catches a stale exemption.
     */
    public function test_the_structural_and_watched_lists_are_measured_against_the_live_schema(): void
    {
        foreach (ImportLayerTenancyCensus::STRUCTURAL as $model) {
            $table = (new ('App\\Models\\' . $model)())->getTable();

            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertFalse(Schema::hasColumn($table, 'store_id'),
                $table . ' DOES carry store_id, so treating ' . $model . ' as platform reference '
                . 'data hides a missing predicate rather than explaining one.');
        }

        /*
         * `ProductLabel` IS THE REASON THIS TEST EXISTS. The shared census exempts it as "catalogue
         * presentation; no figure", which is true of ANALYTICS and the wrong question here:
         * `product_labels` carries `store_id` and the product importer looks one up by name alone.
         */
        $this->assertContains('ProductLabel', $this->census()->watched(),
            'the one model this census adds to the shared watch list is no longer on it');
        $this->assertArrayHasKey('ProductLabel', AnalyticsTenancyCensus::NOT_WATCHED,
            'the shared census now watches ProductLabel, so this census\'s one addition to its list '
            . 'is redundant and should be dropped rather than left as unexplained divergence.');
        $this->assertTrue(Schema::hasColumn('product_labels', 'store_id'),
            'product_labels has no store_id, so watching ProductLabel here asks for a predicate '
            . 'that cannot exist and the importer site is not a defect.');

        foreach (['Category', 'ProductBrand', 'Shipping', 'Tax', 'Product'] as $model) {
            $table = (new ('App\\Models\\' . $model)())->getTable();

            $this->assertTrue(Schema::hasColumn($table, 'store_id'),
                $table . ' has no store_id, so the importer\'s lookup on it is not missing a '
                . 'predicate and that row of DISPOSITIONS is a false accusation.');
        }
    }

    /**
     * AND THE FIVE MODELS THE IMPORTER ADOPTS REALLY ADD NO PREDICATE, against the booted
     * container — not read off `config/tenancy.php`.
     *
     * CLAUDE.md: `getGlobalScopes()` is not the same question as "is it scoped". `Category` answers
     * `[StoreScope]` and filters nothing, because `StoreScope::isActiveFor()` short-circuits for a
     * model absent from `scoped_models`. So the question is asked of the generated SQL.
     */
    public function test_every_model_the_importer_adopts_really_adds_no_predicate(): void
    {
        [, $store] = $this->makeTenant();

        foreach (['Category', 'ProductBrand', 'ProductLabel', 'Shipping', 'Tax', 'Product'] as $model
        ) {
            $sql = ('App\\Models\\' . $model)::query()->toSql();

            $this->assertStringNotContainsString('store_id', $sql,
                $model . ' now adds a store predicate of its own. If it was added to '
                . 'config/tenancy.php\'s scoped_models the importer sites may no longer be '
                . 'defects -- and 785 of the 1,282 existing store_id filters pass a store that is '
                . 'not the current one, so that change needs its own measurement.');
        }

        $this->assertNotNull($store->id);
    }
}
