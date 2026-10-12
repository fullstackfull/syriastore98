<?php

namespace Tests\Feature\Tenancy;

use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WHAT TENANCY STILL DOES NOT COVER -- pinned as a test, not written up as prose.
 *
 * This is the brief's items 3 and 4. Both are inventories, and an inventory in a document is
 * wrong within a week. These assertions fail, loudly and by name, the moment the exposure
 * changes in either direction: a model added to the bulk-delete map, a model allowlisted, or a new
 * raw-SQL site added to the files this workstream owns.
 *
 * NOTHING HERE IS A FIX. It is a measurement of what is still open, deliberately kept adjacent to
 * the thing being measured so it cannot quietly drift out of date.
 */
class ResidualTenancyExposureTest extends TestCase
{
    use RefreshDatabase;

    private const BULK_DELETE_CONTROLLER =
        'packages/workdo/BulkDelete/src/app/Http/Controllers/BulkDeleteController.php';

    // =====================================================================================
    //  ITEM 3 -- CROSS-TENANT BULK DELETION
    // =====================================================================================

    /**
     * The model map is READ FROM THE CONTROLLER'S OWN SOURCE, every time this test runs.
     *
     * Hand-copying it would make this test a snapshot of someone's reading rather than of the
     * code. The map is a local variable inside bulkDelete(), so reflection cannot reach it and
     * parsing the source is the only honest option.
     *
     * TWO SHAPES EXIST IN THAT FILE: `'key' => Model::class` for the always-present models
     * (resolved through the file's own `use` statements) and `'key' => 'Workdo\...\Model'` strings
     * for the addon models that loadConditionalModels() adds behind a `class_exists()` check.
     *
     * @return array<string, class-string> bulk-delete type key => fully qualified model
     */
    private function bulkDeleteModelMap(): array
    {
        $path = base_path(self::BULK_DELETE_CONTROLLER);

        $this->assertFileExists($path, 'The BulkDelete addon has moved; this inventory needs rewriting.');

        $source = (string) file_get_contents($path);

        $imports = [];
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $useMatches);

        foreach ($useMatches[1] as $fqcn) {
            $imports[substr($fqcn, strrpos($fqcn, '\\') + 1)] = $fqcn;
        }

        $map = [];

        preg_match_all("/'([^']+)'\s*=>\s*([A-Za-z_\\\\]+)::class/", $source, $classConst, PREG_SET_ORDER);

        foreach ($classConst as $entry) {
            $short = ltrim($entry[2], '\\');
            $map[$entry[1]] = $imports[$short] ?? $short;
        }

        preg_match_all("/'([^']+)'\s*=>\s*'(Workdo\\\\[^']+)'/", $source, $strings, PREG_SET_ORDER);

        foreach ($strings as $entry) {
            $map[$entry[1]] = $entry[2];
        }

        return $map;
    }

    public function test_the_bulk_delete_map_is_larger_than_the_forty_models_previously_recorded(): void
    {
        $map = $this->bulkDeleteModelMap();

        // CORRECTION WITH EVIDENCE. Earlier notes in this project describe BulkDeleteController's
        // map as "a ~40-model map". Measured from the source it is 65 type keys over 61 distinct
        // model classes -- 38 unconditional plus 23 added by loadConditionalModels(). The
        // conclusion (an unconstrained delete over a wide model map) is unchanged; the number was
        // understated and is corrected here rather than repeated.
        $this->assertCount(65, $map, 'The bulk-delete type map changed size. Re-run this inventory '
            . 'and review each new entry before trusting the exposure lists below.');
        $this->assertCount(61, array_unique(array_values($map)));
    }

    /**
     * EXACTLY which models the global scope now constrains on the bulk-delete path, by name.
     *
     * BulkDeleteController.php:115 runs `$model::whereIn('id', $request->ids)->delete()` with no
     * tenant predicate at all, and :113 runs the `customer_id` variant for `abandoncart` and
     * `wishlist`. packages/ cannot be edited by this workstream, so the ONLY lever is which models
     * are scoped -- which is why this list is the deliverable.
     */
    public function test_the_bulk_delete_models_the_scope_now_protects_are_exactly_these_five(): void
    {
        $protected = [];

        foreach (array_unique(array_values($this->bulkDeleteModelMap())) as $class) {
            if (class_exists($class) && StoreScope::isAllowlisted($class)) {
                $protected[] = $class;
            }
        }

        sort($protected);

        $this->assertSame(
            [
                \App\Models\Cart::class,       // batch 2 -- and the `customer_id` delete shape at :113
                \App\Models\Customer::class,   // batch 2
                \App\Models\Menu::class,       // batch 1
                \App\Models\OrderRefund::class, // batch 2
                \App\Models\SupportTicket::class, // batch 1
            ],
            $protected,
            'The set of bulk-delete-reachable models that tenancy actually constrains has changed.'
        );
    }

    /**
     * EXACTLY which models remain deletable across the tenant boundary. This is the residual
     * exposure the brief asks to be reported precisely, by model.
     *
     * Every entry here has a `store_id` column -- so it COULD be scoped -- and is deliberately not
     * on the allowlist. The reason differs by group and the reason is the point:
     *
     *   PUBLIC STOREFRONT CONTENT (scoping blanks the shop for a signed-in merchant browsing
     *   another store, and buys nothing because anonymous visitors were never filtered):
     *     Blog, BlogCategory, Category, Faq, FlashSale, Page, Product, ProductAttribute,
     *     ProductBrand, ProductLabel, ProductQuestion, Tag, Testimonial
     *
     *   CHECKOUT / PRICING read on the storefront during an anonymous or customers-guard
     *   checkout: Coupon, Shipping, ShippingMethod, ShippingZone, Tax, TaxMethod
     *
     *   CUSTOMER-OWNED, written anonymously on the storefront: Contact (contact form),
     *   Newsletter (signup -- the control that has ALREADY been broken once here, when a brace
     *   move behind `auth` killed newsletter signup on every store), Wishlist
     *
     *   STRUCTURALLY UNSCOPABLE DESPITE THE COLUMN:
     *     Role       -- roles.store_id is NULLABLE and platform roles carry NULL
     *                   (laratrust_setup_tables.php:25); `store_id = ?` matches no NULL, so
     *                   scoping it would hide every platform role and take Laratrust down.
     *     PlanOrder  -- a SaaS subscription record, platform-level by nature.
     *
     *   NOT STOREFRONT CONTENT AND THEREFORE THE NEXT BATCH'S BEST CANDIDATE:
     *     Order -- deferred here only because it does not yet carry the BelongsToStore trait and
     *     its 37 admin-API call sites plus every payment-gateway callback need the same
     *     per-site audit the four batch-2 models got. Reported as OPEN, not as safe.
     */
    public function test_the_bulk_delete_models_still_exposed_are_exactly_these_twenty_five(): void
    {
        $exposed = [];

        foreach (array_unique(array_values($this->bulkDeleteModelMap())) as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $table = (new $class())->getTable();

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                continue;
            }

            if (! StoreScope::isAllowlisted($class)) {
                $exposed[] = $class;
            }
        }

        sort($exposed);

        $this->assertSame([
            \App\Models\Blog::class,
            \App\Models\BlogCategory::class,
            \App\Models\Category::class,
            \App\Models\Contact::class,
            \App\Models\Coupon::class,
            \App\Models\Faq::class,
            \App\Models\FlashSale::class,
            \App\Models\Newsletter::class,
            \App\Models\Order::class,
            \App\Models\Page::class,
            \App\Models\PlanOrder::class,
            \App\Models\Product::class,
            \App\Models\ProductAttribute::class,
            \App\Models\ProductBrand::class,
            \App\Models\ProductLabel::class,
            \App\Models\ProductQuestion::class,
            \App\Models\Role::class,
            \App\Models\Shipping::class,
            \App\Models\ShippingMethod::class,
            \App\Models\ShippingZone::class,
            \App\Models\Tag::class,
            \App\Models\Tax::class,
            \App\Models\TaxMethod::class,
            \App\Models\Testimonial::class,
            \App\Models\Wishlist::class,
        ], $exposed, 'The residual bulk-delete exposure changed. If a model LEFT this list it was '
            . 'allowlisted and needs its own negative/positive isolation proof; if one JOINED it, '
            . 'enforcement was removed.');
    }

    public function test_platform_global_bulk_delete_models_have_no_discriminator_to_scope_on(): void
    {
        // These eight are a DIFFERENT finding from the 25 above and must not be reported with
        // them: they have no `store_id` column at all, so no global scope can ever constrain
        // them. Country, State, City and Currency are platform reference data a merchant should
        // not be able to bulk-delete at all, and the remedy is an authorization check on the
        // BulkDelete route -- not tenancy. Recorded here so the distinction survives.
        $withoutDiscriminator = [];

        foreach (array_unique(array_values($this->bulkDeleteModelMap())) as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $table = (new $class())->getTable();

            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'store_id')) {
                $withoutDiscriminator[] = $class;
            }
        }

        sort($withoutDiscriminator);

        $this->assertSame([
            \App\Models\City::class,
            \App\Models\Country::class,
            \App\Models\Currency::class,
            \App\Models\PlanCoupon::class,
            \App\Models\PlanRequest::class,
            \App\Models\PlanUserCoupon::class,
            \App\Models\State::class,
            \App\Models\UserCoupon::class,
        ], $withoutDiscriminator);
    }

    public function test_the_addon_models_in_the_map_cannot_be_measured_in_this_install(): void
    {
        // HONESTY, NOT A PASS. 23 of the 65 entries name addon model classes under `Workdo\`.
        // Their SOURCE FILES EXIST (e.g. packages/workdo/Helpdesk/src/app/Models/
        // HelpdeskTicket.php), but `Workdo\` is absent from composer.json's psr-4 block -- which
        // holds only App\, Database\Factories\, Database\Seeders\ and Easebuzz\ -- so
        // class_exists() is false here and loadConditionalModels() never adds them to the map.
        //
        // THAT IS A PROPERTY OF THIS WORKTREE, NOT OF PRODUCTION. On a deployment where those
        // addons are active the classes resolve, the entries join the map, and they are deleted by
        // bare id with no tenant predicate exactly like the 25 above. Their exposure is therefore
        // UNMEASURED here, which is not the same as zero, and is reported as such. composer.json
        // is another workstream's file and packages/ cannot be edited by this one, so this is
        // recorded rather than resolved.
        $unresolvable = [];

        foreach (array_unique(array_values($this->bulkDeleteModelMap())) as $class) {
            if (! class_exists($class)) {
                $unresolvable[] = $class;
            }
        }

        $this->assertCount(23, $unresolvable, "The set of unresolvable addon models changed:\n  "
            . implode("\n  ", $unresolvable));

        foreach ($unresolvable as $class) {
            $this->assertStringStartsWith('Workdo\\', $class, $class . ' is unresolvable but is not '
                . 'an addon class, which means a first-party model in the map has gone missing.');
        }
    }

    // =====================================================================================
    //  ITEM 4 -- RAW SQL, WHICH NO ELOQUENT GLOBAL SCOPE CAN EVER REACH
    // =====================================================================================

    /**
     * The files this workstream owns, for the raw-SQL scan below.
     *
     * @return array<int, string>
     */
    private function ownedPhpFiles(): array
    {
        $files = array_merge(
            glob(base_path('app/Models/*.php')) ?: [],
            glob(base_path('app/Models/Concerns/*.php')) ?: [],
            glob(base_path('app/Scopes/*.php')) ?: [],
            [base_path('app/Helper/helper.php')]
        );

        sort($files);

        return $files;
    }

    /**
     * Every raw query-builder entry point in the owned files, as `relative/path.php:line`.
     *
     * `DB::table()`, `DB::select()` and friends bypass Eloquent entirely, so NO global scope
     * reaches them -- proven by execution, not assumed, in
     * EnforcedModelIsolationTest::test_raw_db_table_queries_bypass_the_scope_entirely. The remedy
     * at such a site is always an explicit predicate at the call site.
     *
     * @return array<int, string>
     */
    private function rawSqlSites(string $pattern): array
    {
        $sites = [];

        foreach ($this->ownedPhpFiles() as $file) {
            $relative = ltrim(str_replace(base_path(), '', $file), '/');
            $lines = file($file) ?: [];

            foreach ($lines as $index => $line) {
                // A docblock mentioning DB::table() is documentation, not a query.
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }

                if (preg_match($pattern, $line)) {
                    $sites[] = $relative . ':' . ($index + 1);
                }
            }
        }

        return $sites;
    }

    /**
     * A TRUE SCOPE BYPASS is a raw query ENTRY POINT: DB::table(), DB::select(), DB::insert(),
     * DB::update(), DB::delete(), DB::statement(), DB::unprepared(). Those build their own query
     * from nothing, so no Eloquent global scope is ever consulted -- proven by execution in
     * EnforcedModelIsolationTest::test_raw_db_table_queries_bypass_the_scope_entirely. The only
     * remedy at such a site is an explicit predicate written there.
     *
     * `DB::raw()` is NOT in that list and is asserted separately below, because it is a select
     * EXPRESSION inside an Eloquent builder and the surrounding query still carries its scopes.
     * Conflating the two would have overstated this inventory by one site.
     */
    private const RAW_ENTRY_POINT_PATTERN = '/\\\\?DB::(table|select|insert|update|delete|statement|unprepared)\\s*\\(/';

    private const RAW_EXPRESSION_PATTERN = '/\\\\?DB::raw\\s*\\(/';

    public function test_the_raw_query_entry_points_in_the_owned_files_are_exactly_these_thirteen(): void
    {
        $this->assertSame([
            // ------------------------------------------------------------------------------
            // THE SLUG-UNIQUENESS HELPER. Five live copies of one method, each
            //     DB::table($table)->where('slug','like',$slug.'%')->where('id','<>',$id)->get()
            // with NO tenant predicate, so slug generation reads slugs across EVERY store.
            // CONSEQUENCE: slugs are globally unique rather than per-store, and the "-1" suffix a
            // merchant is given discloses that SOME other store already uses that slug -- a weak
            // existence oracle, not a disclosure of data.
            // DELIBERATELY NOT FIXED. Adding `where('store_id', ...)` makes slugs unique only
            // WITHIN a store, so two stores could mint the same product slug. Whether the
            // storefront can still resolve a product from a slug then depends on every slug
            // lookup also carrying a store predicate, across app/Http/** and themes/** which this
            // workstream neither owns nor can verify. Changing URL generation on that assumption
            // is the exact class of change that has already broken this storefront twice, so it
            // is recorded rather than attempted.
            //
            // NOTE on the two models that are NOT here: ProductBrand.php:43 and
            // ProductLabel.php:43 declare the same method but implement it with ELOQUENT
            // (`ProductBrand::select()->...`), so they do carry global scopes and are not
            // bypasses. Found by measuring, after a name-based assumption put them on this list.
            'app/Models/Customer.php:149',
            // ^ and this one is UNREACHABLE: Customer.php declares getRelatedSlugs() but contains
            //   no `self::getRelatedSlugs(` caller, unlike the four below. Dead code -- which is
            //   why the one model in this group that IS now scoped has no live bypass.
            'app/Models/Product.php:97',
            'app/Models/ProductAttribute.php:48',
            'app/Models/Tag.php:47',
            'app/Models/User.php:155',

            // ------------------------------------------------------------------------------
            // Utility::cookies(). `DB::table('settings')` filtered on `created_by`, NOT on
            // `store_id`: the authenticated branch uses creatorId(), the anonymous branch the
            // HARDCODED literal 1.
            // CONSEQUENCE, and this one is a real cross-tenant content bleed rather than an
            // oracle: an anonymous visitor to ANY storefront is served the cookie-consent text
            // belonging to user 1, and a merchant owning several stores gets one shared banner
            // across all of them.
            // DELIBERATELY NOT FIXED here: the correct store id on the anonymous branch has to
            // come from the slug-resolved store, which is plumbing in app/Http/** and themes/**.
            'app/Models/Utility.php:830',

            // ------------------------------------------------------------------------------
            // FOREIGN_KEY_CHECKS toggles around the country/state/city reference-data import.
            // Platform-global data with no store_id at all, so tenancy does not apply. Listed so
            // this inventory is exhaustive rather than curated -- a filtered inventory is how a
            // site gets missed.
            'app/Models/Utility.php:3171',
            'app/Models/Utility.php:3173',
            'app/Models/Utility.php:3196',
            'app/Models/Utility.php:3198',
            'app/Models/Utility.php:3212',
            'app/Models/Utility.php:3214',

            // ------------------------------------------------------------------------------
            // The scope's OWN owned-store lookup, raw ON PURPOSE: no Eloquent, no global scopes,
            // no Cachable, so it cannot re-enter the model-cache key machinery from inside query
            // compilation. See StoreScope::ownedStoreIds().
            'app/Scopes/StoreScope.php:601',
        ], $this->rawSqlSites(self::RAW_ENTRY_POINT_PATTERN), 'The raw-query inventory in the owned '
            . 'files changed. A NEW site here is invisible to every global scope and needs an '
            . 'explicit predicate written at the call site; a REMOVED one means a finding above was '
            . 'addressed and its note should go with it.');
    }

    public function test_the_single_db_raw_expression_site_is_inside_an_eloquent_query(): void
    {
        // ActivityLog::totalspend(). `\DB::raw('SUM(final_price) ..., COUNT(*) ...')` is a select
        // expression on `Order::where('customer_id', $userId)`, so the surrounding query carries
        // its global scopes and this is NOT a bypass.
        //
        // IT IS STILL A CROSS-TENANT AGGREGATION, for a different reason, and that is the finding:
        // the query filters on `customer_id` ALONE with no store predicate, and `Order` is not on
        // the allowlist, so a customer's "total spend" and "total orders" sum their purchases
        // across EVERY store on the platform. One merchant's dashboard therefore shows a figure
        // that includes other merchants' revenue. Scoping `Order` would close it; Order is
        // reported OPEN above with the reasons, so this is recorded as the concrete harm that
        // deferral leaves behind rather than being quietly folded into the bypass list.
        $this->assertSame(
            ['app/Models/ActivityLog.php:70'],
            $this->rawSqlSites(self::RAW_EXPRESSION_PATTERN)
        );
    }

    public function test_the_helper_file_itself_contains_no_raw_sql(): void
    {
        // app/Helper/helper.php holds getCurrentStore(), the single tenant resolver, plus ~2,500
        // lines of view helpers. Asserted raw-SQL-free so the resolver's inputs stay reachable by
        // the scope and by any future audit.
        $helperSites = array_values(array_filter(
            array_merge(
                $this->rawSqlSites(self::RAW_ENTRY_POINT_PATTERN),
                $this->rawSqlSites(self::RAW_EXPRESSION_PATTERN)
            ),
            static fn (string $site) => str_starts_with($site, 'app/Helper/helper.php:')
        ));

        $this->assertSame([], $helperSites);
    }
}
