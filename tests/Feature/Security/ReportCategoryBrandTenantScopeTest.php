<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\ProductBrand;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * THE CATEGORY AND BRAND SALES CHARTS NAMED ANY TENANT'S CATEGORY OR BRAND BACK TO ANY MERCHANT.
 * ==============================================================================================
 *
 * `ReportController::order_category_reports` read `$request->selectedCategory` in all five of its
 * period branches and resolved each id with a bare `Category::find()`; `order_brand_reports` did
 * the same with `$request->selectedBrand` and `ProductBrand::find()`. Neither model filters:
 * measured on this tree, `Category` carries `App\Scopes\StoreScope` AND `BelongsToStore` but is
 * absent from `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()`
 * short-circuits and the scope adds NO predicate for any principal; `ProductBrand` carries no
 * global scope at all. So `find()` reached every tenant's rows.
 *
 * WHAT LEAKED, EXACTLY. One field -- `$category->name` / `$brand->name` -- plus an EXISTENCE BIT:
 * a non-existent id yields no series, an existing foreign id yields a series named after it. It
 * surfaced in three places at once: the `NetSaleTotal` JSON, the rendered stat cards (the chart
 * views label each card with the series name), and the session that `category_order_export` /
 * `brand_order_export` replay. No id, slug, image path or parent leaked -- the model is never
 * serialised -- and no foreign MONEY was ever reachable, because every branch's order query
 * carries `Order::where('store_id', getCurrentStore())`. That is why the severity is LOW. It is
 * still a disclosure, and the residue that makes it real is that `find()` ignores `status` while
 * every storefront read and both report SELECTORS filter `status = 1`: a DISABLED or draft
 * category or brand name is not public on the victim's own shop, and this named it anyway.
 *
 * ORACLE. The response one tenant receives when it names another tenant's id: neither the foreign
 * NAME nor a series for that id, in the JSON and in the rendered html. Driven for the merchant
 * OWNER and for a staff member holding `Manage Order Reports` (`ReportPermission::ACTIONS` maps
 * both handlers to it), across ALL FIVE period branches of each handler -- a fix applied to one
 * branch must not pass.
 *
 * THREE POSITIVE CONTROLS, because a guard that refuses everyone would satisfy the oracle above
 * for the wrong reason:
 *   1. the tenant's OWN id still produces its own named series;
 *   2. the tenant's own DISABLED category/brand still resolves -- the repair narrows by STORE, and
 *      must not quietly acquire a `status` filter the merchant never asked for;
 *   3. the PLATFORM OPERATOR is still served a foreign id, which is the capability
 *      `CategoryController::categoryIsOutsideCurrentStore()` already exempts them for. Removing it
 *      would be an authorization-policy change, and this suite proves it was preserved rather
 *      than assumed.
 *
 * Every case asserts HTTP 200 before reading a body. `ReportPermission` answers an ajax/JSON
 * request with 403 and a browser request with a redirect, and both would satisfy "the foreign
 * name is absent" without the handler ever running.
 */
class ReportCategoryBrandTenantScopeTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // An APP_DEBUG query log in the body was once nearly mis-filed as a disclosure here.
        config(['app.debug' => false]);
    }

    /**
     * The five period branches of each handler. Each is a separate read of the request key, so
     * each is a separate input point that has to be narrowed.
     *
     * @return array<string, array{0: string}>
     */
    public static function periods(): array
    {
        $range = date('Y-m-01') . ' to ' . date('Y-m-d');

        return [
            'last-month branch' => ['chart_data=last-month'],
            'this-month branch' => ['chart_data=this-month'],
            'seven-day branch' => ['chart_data=seven-day'],
            'year branch' => ['chart_data=year'],
            'date-range branch (else)' => ['chart_data=range&Date=' . urlencode($range)],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function surfaces(): array
    {
        return [
            'category chart' => ['category'],
            'brand chart' => ['brand'],
        ];
    }

    /**
     * The cartesian product of the two handlers and their five branches: ten input points.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function surfacesAndPeriods(): array
    {
        $cases = [];

        foreach (self::surfaces() as $surfaceName => [$surface]) {
            foreach (self::periods() as $periodName => [$period]) {
                $cases["{$surfaceName}, {$periodName}"] = [$surface, $period];
            }
        }

        return $cases;
    }

    private function uri(string $surface): string
    {
        return $surface === 'category' ? '/category-order-reports' : '/brand-order-reports';
    }

    private function key(string $surface): string
    {
        return $surface === 'category' ? 'selectedCategory' : 'selectedBrand';
    }

    private function makeCategory(Store $store, string $name, int $status = 1): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . $store->id,
            'parent_id' => 0,
            'trending' => 0,
            'status' => $status,
            'store_id' => $store->id,
        ]);
    }

    private function makeBrand(Store $store, string $name, int $status = 1): ProductBrand
    {
        return ProductBrand::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . $store->id,
            'status' => $status,
            'is_popular' => 0,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    /**
     * A row of the victim tenant, plus the attacking tenant's owner and store.
     *
     * @return array{0: User, 1: Store, 2: int, 3: string}  [attacker, attackerStore, victimRowId, victimRowName]
     */
    private function twoTenants(string $surface): array
    {
        [$attacker, $attackerStore] = $this->makeTenant();
        [, $victimStore] = $this->makeTenant();

        $name = 'ZZVictim' . ucfirst($surface) . bin2hex(random_bytes(4));

        $row = $surface === 'category'
            ? $this->makeCategory($victimStore, $name)
            : $this->makeBrand($victimStore, $name);

        // Premise, read raw: the row really is the victim's, and really carries that name.
        // Category registers StoreScope (inert, but registered), so an Eloquent read here would
        // answer against whichever tenant happens to be resolved rather than against the table.
        $table = $surface === 'category' ? 'categories' : 'product_brands';
        $raw = DB::table($table)->where('id', $row->id)->first();
        $this->assertNotNull($raw, "Premise: the victim's {$surface} row was inserted.");
        $this->assertSame($name, $raw->name, "Premise: the victim's {$surface} carries the probe name.");
        $this->assertSame((int) $victimStore->id, (int) $raw->store_id, "Premise: the row belongs to the VICTIM's store.");
        $this->assertNotSame((int) $attackerStore->id, (int) $raw->store_id, 'Premise: the two tenants are distinct.');

        return [$attacker, $attackerStore, (int) $row->id, $name];
    }

    /** @return array{status: int, names: list<string>, html: string} */
    private function chart(User $actor, string $surface, string $period, ?int $id = null): array
    {
        $query = $period;

        if ($id !== null) {
            $query .= '&' . $this->key($surface) . '[]=' . $id;
        }

        $response = $this->actingAs($actor->fresh())->get($this->uri($surface) . '?' . $query);

        // Three ways this file could go green without the handler running: the route is gone
        // (404), ReportPermission refused (403 for json/ajax, redirect otherwise), or the handler
        // threw. All of them satisfy "the foreign name is absent".
        $this->assertNotSame(404, $response->status(), $this->uri($surface) . ': the route does not exist.');
        $this->assertSame(200, $response->status(), $this->uri($surface) . ": the handler did not run (HTTP {$response->status()}).");

        $payload = $response->json();
        $this->assertIsArray($payload, $this->uri($surface) . ': the handler did not return the chart JSON.');

        // The LAST way this file could go green vacuously: an empty body satisfies every
        // assertStringNotContainsString() below. The handler always renders the chart partial
        // (stat cards plus the accessible table), so an empty `html` means it bailed out.
        $this->assertNotSame('', (string) ($payload['html'] ?? ''), $this->uri($surface) . ': the chart partial rendered empty, so a "name is absent" assertion proves nothing.');

        return [
            'status' => $response->status(),
            'names' => array_values(array_map(
                static fn ($series) => (string) ($series['name'] ?? ''),
                $payload['NetSaleTotal'] ?? []
            )),
            'html' => (string) ($payload['html'] ?? ''),
        ];
    }

    // ---------------------------------------------------------------------------------------
    // THE DEFECT
    // ---------------------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('surfacesAndPeriods')]
    public function test_a_merchant_owner_is_not_told_another_tenants_category_or_brand_name(string $surface, string $period): void
    {
        [$attacker, , $victimId, $victimName] = $this->twoTenants($surface);

        $chart = $this->chart($attacker, $surface, $period, $victimId);

        $this->assertNotContains(
            $victimName,
            $chart['names'],
            "{$surface}/{$period}: another tenant's {$surface} name was returned as a NetSaleTotal series."
        );
        $this->assertStringNotContainsString(
            $victimName,
            $chart['html'],
            "{$surface}/{$period}: another tenant's {$surface} name was rendered into the chart html."
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfacesAndPeriods')]
    public function test_staff_holding_manage_order_reports_are_not_told_another_tenants_name(string $surface, string $period): void
    {
        [$owner, , $victimId, $victimName] = $this->twoTenants($surface);

        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Order Reports');

        // Premises: this principal is NOT the tenant owner (so it is admitted by the permission
        // limb of ReportPermission, not the owner limb), and it really does hold the permission.
        $this->assertNotSame((int) $staff->id, (int) $staff->creatorId(), 'Premise: the staff member is not their own owner.');
        $this->assertTrue($staff->fresh()->isAbleTo('Manage Order Reports'), 'Premise: the staff member holds Manage Order Reports.');

        $chart = $this->chart($staff, $surface, $period, $victimId);

        $this->assertNotContains($victimName, $chart['names'], "{$surface}/{$period}: staff were told another tenant's {$surface} name.");
        $this->assertStringNotContainsString($victimName, $chart['html'], "{$surface}/{$period}: staff html carried another tenant's {$surface} name.");
    }

    /**
     * The existence bit, which is the half of this finding that survives a name the attacker
     * already knows: today a foreign id produces a series and a non-existent id produces none, so
     * the endpoint answers "does row N exist" for the whole table. After the repair both answer
     * the same way.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_an_existing_foreign_id_is_indistinguishable_from_a_nonexistent_one(string $surface): void
    {
        [$attacker, , $victimId] = $this->twoTenants($surface);

        $foreign = $this->chart($attacker, $surface, 'chart_data=this-month', $victimId);
        $absent = $this->chart($attacker, $surface, 'chart_data=this-month', 99999999);

        $this->assertSame(
            $absent['names'],
            $foreign['names'],
            "{$surface}: an existing foreign id produced a different series list to a non-existent id, which is an existence oracle over the whole table."
        );
    }

    /** The export replays the session the chart wrote, so the leak outlives the chart request. */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_the_export_replay_does_not_carry_another_tenants_name(string $surface): void
    {
        [$attacker, , $victimId, $victimName] = $this->twoTenants($surface);

        $this->chart($attacker, $surface, 'chart_data=this-month', $victimId);

        $exportUri = $surface === 'category' ? '/category-order-export' : '/brand-order-export';
        $export = $this->actingAs($attacker->fresh())->get($exportUri);

        $this->assertNotSame(404, $export->status(), "{$exportUri}: the route does not exist.");
        $this->assertSame(200, $export->status(), "{$exportUri}: the handler did not run.");

        $names = array_map(
            static fn ($series) => (string) ($series['name'] ?? ''),
            $export->json('NetSaleTotal') ?? []
        );

        $this->assertNotContains($victimName, $names, "{$exportUri}: the export replayed another tenant's {$surface} name.");
    }

    // ---------------------------------------------------------------------------------------
    // POSITIVE CONTROLS -- a guard that refuses everybody must not pass this file
    // ---------------------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('surfacesAndPeriods')]
    public function test_the_tenants_own_selection_still_produces_its_own_series(string $surface, string $period): void
    {
        [$owner, $store] = $this->makeTenant();

        $name = 'ZZOwn' . ucfirst($surface) . bin2hex(random_bytes(4));
        $row = $surface === 'category' ? $this->makeCategory($store, $name) : $this->makeBrand($store, $name);

        $chart = $this->chart($owner, $surface, $period, (int) $row->id);

        $this->assertContains(
            $name,
            $chart['names'],
            "{$surface}/{$period}: the tenant's OWN {$surface} lost its series -- the repair over-narrowed."
        );
        $this->assertStringContainsString($name, $chart['html'], "{$surface}/{$period}: the tenant's own {$surface} name is missing from the chart html.");
    }

    /**
     * The repair narrows by STORE only. `find()` ignored `status`, and so must the replacement:
     * the chart is an admin screen and a merchant may legitimately chart a disabled category.
     * (The SELECTORS -- OrderSaleCategoryReport / OrderSaleBrandReport -- filter `status = 1`;
     * that asymmetry is what made the leak reach non-public names, and it is not fixed by
     * narrowing the chart's own reads further.)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_the_tenants_own_disabled_row_still_resolves(string $surface): void
    {
        [$owner, $store] = $this->makeTenant();

        $name = 'ZZDisabled' . ucfirst($surface) . bin2hex(random_bytes(4));
        $row = $surface === 'category'
            ? $this->makeCategory($store, $name, 0)
            : $this->makeBrand($store, $name, 0);

        $table = $surface === 'category' ? 'categories' : 'product_brands';
        $this->assertSame(0, (int) DB::table($table)->where('id', $row->id)->value('status'), 'Premise: the row really is disabled.');

        $chart = $this->chart($owner, $surface, 'chart_data=this-month', (int) $row->id);

        $this->assertContains($name, $chart['names'], "{$surface}: the tenant's own DISABLED {$surface} stopped resolving; the repair acquired a status filter it should not have.");
    }

    /** No selection at all is the default path, and it was already scoped. It must stay scoped. */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfacesAndPeriods')]
    public function test_the_unfiltered_default_lists_only_the_tenants_own_rows(string $surface, string $period): void
    {
        [$attacker, $attackerStore, , $victimName] = $this->twoTenants($surface);

        $own = 'ZZMine' . ucfirst($surface) . bin2hex(random_bytes(4));
        $surface === 'category' ? $this->makeCategory($attackerStore, $own) : $this->makeBrand($attackerStore, $own);

        $chart = $this->chart($attacker, $surface, $period);

        $this->assertContains($own, $chart['names'], "{$surface}/{$period}: the default selection lost the tenant's own row.");
        $this->assertNotContains($victimName, $chart['names'], "{$surface}/{$period}: the default selection reached another tenant's {$surface}.");
    }

    /**
     * THE OPERATOR EXEMPTION, asserted rather than assumed.
     *
     * Plain SQL narrowing would also narrow the platform operator, who today may name any
     * tenant's id (and sees zeros, because their order query is still getCurrentStore()-scoped).
     * That is a zero-value capability, but removing it IS an authorization-policy change, and the
     * owner has forbidden those without approval. `CategoryController::categoryIsOutsideCurrentStore()`
     * already exempts `type === UserPolicy::PLATFORM_ADMIN` from exactly this kind of predicate;
     * the repair mirrors it, and this case is the proof.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_the_platform_operator_is_still_served_a_foreign_selection(string $surface): void
    {
        [, $attackerStore, $victimId, $victimName] = $this->twoTenants($surface);

        $operator = $this->makePlatformAdmin(['current_store' => $attackerStore->id]);
        $this->assertSame(\App\Policies\UserPolicy::PLATFORM_ADMIN, $operator->type, 'Premise: the principal is the canonically spelled operator.');

        $chart = $this->chart($operator, $surface, 'chart_data=this-month', $victimId);

        $this->assertContains(
            $victimName,
            $chart['names'],
            "{$surface}: the platform operator lost a capability they hold today. That is an authorization-policy change and needs owner approval, so the repair must exempt them as CategoryController does."
        );
    }

    /**
     * A NON-CANONICALLY SPELLED OPERATOR IS NARROWED, NOT EXEMPTED -- recorded because it is a
     * DELIBERATE ASYMMETRY and somebody will otherwise read it as a bug.
     *
     * `ReportPermission`, the gate on this very route, normalises
     * (`strtolower(trim($user->type)) === 'super admin'`), so `' Super Admin '` is admitted here
     * as the operator -- `ReportAuthorizationTest` has a case for exactly that. The narrowing
     * compares EXACT-CASE, mirroring `CategoryController::categoryIsOutsideCurrentStore()`, whose
     * own docblock says the exact comparison is chosen so that the predicate FAILS SAFE. So such
     * a principal is admitted to the screen and then narrowed to their own store, losing only the
     * (zero-value) ability to name a foreign id. That is the safe direction, it is not a lockout,
     * and it is the direction the brief for this repair asked for. If the owner would rather the
     * two comparisons agree, this is the case to change -- not the guard's shape.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_a_non_canonically_spelled_operator_is_narrowed_not_exempted(string $surface): void
    {
        [, $attackerStore, $victimId, $victimName] = $this->twoTenants($surface);

        $operator = $this->makePlatformAdmin(['type' => ' Super Admin ', 'current_store' => $attackerStore->id]);
        $this->assertNotSame(
            \App\Policies\UserPolicy::PLATFORM_ADMIN,
            $operator->type,
            'Premise: the spelling is NOT the canonical one the narrowing exempts.'
        );

        $chart = $this->chart($operator, $surface, 'chart_data=this-month', $victimId);

        $this->assertNotContains(
            $victimName,
            $chart['names'],
            "{$surface}: a non-canonically spelled operator was exempted. The comparison is meant to be exact-case so that it fails SAFE."
        );
    }

    /**
     * A principal who resolves NO tenant is OUTSIDE, not everywhere. `StoreScope` fails OPEN on a
     * null tenant by design (`null_tenant => 'open'`); a predicate written to hold where the
     * scope adds nothing must not inherit that. A merchant with no store is reachable here
     * because ReportPermission's owner limb admits any `type = 'admin'` user.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_a_principal_with_no_resolvable_tenant_is_served_nothing(string $surface): void
    {
        [, , $victimId, $victimName] = $this->twoTenants($surface);

        $storeless = $this->makeMerchant(['current_store' => null]);
        $this->assertNull(getCurrentStore($storeless->id), 'Premise: this principal resolves no tenant.');

        $chart = $this->chart($storeless, $surface, 'chart_data=this-month', $victimId);

        $this->assertNotContains($victimName, $chart['names'], "{$surface}: a principal with no tenant was served another tenant's {$surface} name.");
        $this->assertStringNotContainsString($victimName, $chart['html'], "{$surface}: a principal with no tenant was rendered another tenant's {$surface} name.");
    }

    /**
     * AN ORPHAN ROW IS NOT EVERYONE'S ROW, and this case is why the repair cannot just let the
     * predicate run with a null store.
     *
     * MEASURED, not assumed: `Category::where('store_id', null)` does NOT compile to a
     * never-matching comparison. Laravel's `Builder::where()` routes a null value to
     * `whereNull()`, so it compiles to `where "store_id" is null` -- verified on this tree with
     * `->toSql()`. Without an explicit fail-closed branch, a principal who resolves no tenant
     * would therefore be served every row whose `store_id` is NULL: the orphans. This codebase has
     * them (`tests/Support/Security/bin/report-null-store-orders.php` exists for that class of
     * row), and `getCurrentStore()` returns null for any merchant between registration and their
     * first store.
     *
     * Without this case the fail-closed branch is a line no test can redden.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_a_principal_with_no_tenant_is_not_served_rows_belonging_to_no_store(string $surface): void
    {
        [, $store] = $this->makeTenant();

        $name = 'ZZOrphan' . ucfirst($surface) . bin2hex(random_bytes(4));
        $row = $surface === 'category' ? $this->makeCategory($store, $name) : $this->makeBrand($store, $name);

        // Orphaned through the model so the Cachable layer is flushed; a DB::table() update would
        // leave Eloquent -- route-model binding included -- serving the pre-write row for the rest
        // of this test under CACHE_STORE=array.
        $row->store_id = null;
        $row->save();

        $table = $surface === 'category' ? 'categories' : 'product_brands';
        $this->assertNull(
            DB::table($table)->where('id', $row->id)->value('store_id'),
            'Premise: the row really has a NULL store_id in the table.'
        );

        $storeless = $this->makeMerchant(['current_store' => null]);
        $this->assertNull(getCurrentStore($storeless->id), 'Premise: this principal resolves no tenant.');

        $chart = $this->chart($storeless, $surface, 'chart_data=this-month', (int) $row->id);

        $this->assertNotContains(
            $name,
            $chart['names'],
            "{$surface}: a principal with no tenant was served a row belonging to NO store. `where('store_id', null)` compiles to `is null`, so the narrowing must refuse an unresolved tenant explicitly."
        );
        $this->assertStringNotContainsString($name, $chart['html'], "{$surface}: the orphan row's name was rendered into the chart html.");
    }

    // ---------------------------------------------------------------------------------------
    // NO-REGRESSION CONTROLS ON THE SHAPE OF THE ANSWER, not on who may see it
    // ---------------------------------------------------------------------------------------

    /**
     * THE SERIES ORDER FOLLOWS THE SELECTION, NOT THE DATABASE.
     *
     * The handlers push one `NetSaleTotal` series per id in iteration order, so a narrowing that
     * returned the plucked ids directly would silently re-sort the chart legend into ascending id
     * for every merchant who picked their own categories in any other order. That is a visible
     * change to a screen nobody asked to change, and it is invisible to every other case in this
     * file, all of which select exactly one id.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_the_series_order_follows_the_selection_not_the_database(string $surface): void
    {
        [$owner, $store] = $this->makeTenant();

        $suffix = bin2hex(random_bytes(3));
        $lower = $surface === 'category'
            ? $this->makeCategory($store, 'ZZSeriesA' . $suffix)
            : $this->makeBrand($store, 'ZZSeriesA' . $suffix);
        $higher = $surface === 'category'
            ? $this->makeCategory($store, 'ZZSeriesB' . $suffix)
            : $this->makeBrand($store, 'ZZSeriesB' . $suffix);

        $this->assertLessThan((int) $higher->id, (int) $lower->id, 'Premise: the second row has the higher id.');

        // Selected HIGHEST id first -- the opposite of what the table would hand back.
        $response = $this->actingAs($owner->fresh())->get(
            $this->uri($surface) . '?chart_data=this-month'
            . '&' . $this->key($surface) . '[]=' . $higher->id
            . '&' . $this->key($surface) . '[]=' . $lower->id
        );

        $this->assertSame(200, $response->status(), $this->uri($surface) . ': the handler did not run.');

        $this->assertSame(
            [$higher->name, $lower->name],
            array_map(static fn ($series) => (string) $series['name'], $response->json('NetSaleTotal') ?? []),
            "{$surface}: the chart legend was re-ordered into database order. The narrowing must hand the ids back in the CALLER'S order."
        );
    }

    /**
     * A REPEATED ID IS COUNTED ONCE.
     *
     * Not a security property and not the point of this change -- a deliberate, named side effect
     * of resolving the selection as a SET. Measured before the repair: `?selected[]=N&selected[]=N`
     * made the second pass add the same orders into the same `$categoryNetSales[$name]` bucket
     * again, so the series data and the stat card came back at DOUBLE the real figure. The
     * selector is a multi-select that cannot emit a duplicate, so this was only reachable by hand
     * -- but a doubled money figure is worse than the de-duplication, so `unique()` stays and this
     * case records the choice. Flag it if a doubled figure was ever wanted.
     */
    public function test_a_repeated_own_id_is_not_counted_twice(): void
    {
        [$owner, $store] = $this->makeTenant();

        $category = $this->makeCategory($store, 'ZZDup' . bin2hex(random_bytes(3)));

        // forceCreate: `created_by` is NOT NULL in the products table and is not mass-assignable,
        // so Product::create() drops it silently and the insert fails on the constraint.
        $product = \App\Models\Product::forceCreate([
            'name' => 'ZZDupProduct' . bin2hex(random_bytes(3)),
            'slug' => 'zz-dup-product-' . bin2hex(random_bytes(3)),
            'category_id' => $category->id,
            'store_id' => $store->id,
            'track_stock' => 0,
            'created_by' => $owner->id,
        ]);

        $this->makeOrder($store, [
            'order_date' => now()->format('Y-m-d'),
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'qty' => 1,
                'final_price' => 250,
            ]]),
        ]);

        $uri = '/category-order-reports?chart_data=this-month&selectedCategory[]=' . $category->id;

        $once = $this->actingAs($owner->fresh())->get($uri);
        $twice = $this->actingAs($owner->fresh())->get($uri . '&selectedCategory[]=' . $category->id);

        $this->assertSame(200, $once->status(), 'the single-id request did not run.');
        $this->assertSame(200, $twice->status(), 'the repeated-id request did not run.');

        // Premise: the fixture really produced a non-zero figure, or "the two agree" would hold
        // for the trivial reason that both are zero.
        $this->assertSame(
            250.0,
            (float) array_sum($once->json('NetSaleTotal.0.data') ?? []),
            'Premise: the order did not reach the chart, so a doubling could not have been seen.'
        );

        $this->assertSame(
            $once->json('NetSaleTotal'),
            $twice->json('NetSaleTotal'),
            'a repeated id changed the figures: the selection is not being resolved as a set.'
        );
    }
}
