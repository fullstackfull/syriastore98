<?php

namespace Tests\Feature\Security;

use App\Models\ProductBrand;
use App\Models\ProductLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-05-1 -- the brand and label toggles were cross-tenant writes with no permission check.
 * =============================================================================================
 *
 * THREE HANDLERS, THE SAME THREE LINES, AND THE SAME THREE MISSING CONTROLS:
 *
 *     $productBrand = ProductBrand::find($request->id);
 *     $productBrand->status = $request->status;
 *     $productBrand->save();
 *
 * No permission check. No ownership check. No tenant predicate. And `ProductBrand` and
 * `ProductLabel` are **not in `config/tenancy.php`'s `scoped_models`**, carry no
 * `BelongsToStore`, and register no `StoreScope` -- so `find()` reaches every tenant's rows for
 * every principal. The same fact behind the S19 cross-tenant stock drain, applied to catalogue
 * metadata.
 *
 * SO ANY AUTHENTICATED WEB PRINCIPAL could flip any brand's status or popularity, and any
 * label's status, in any store, by walking the id. A merchant. A staff member holding no
 * permission at all. The blast radius is a competitor's brand hidden from their own storefront
 * -- the `status` column is what the storefront filters on.
 *
 * AND ON A GET, so it is also reachable from a third-party `<img src>` and by link prefetching:
 * the F12/F14 pattern, third and fourth instances.
 *
 * THE API SIBLINGS WERE ALREADY FIXED, which is the most useful fact here.
 * `Api\DashboardController::ChangeStatus`, `ChangePopular` and `LableChangeStatus` all carry
 * `whereIn('store_id', $this->ownedStoreIds())`. Somebody closed the Flutter path and the WEB
 * path was left open -- a census keyed on the surface somebody happened to be looking at, which
 * is the fourth time that shape has produced a defect in this project.
 *
 * `Edit Product Brand` / `Edit Product Label` are the permissions chosen, because they are what
 * `edit()` and `update()` on the same row already use: the toggle edits a brand, so it asks the
 * same question the editor asks. Both are seeded (`app/Models/Utility.php`), so neither is the
 * F15 trap of gating on a name no row exists for.
 */
class BrandLabelStatusAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeBrand(int $storeId, int $createdBy, string $name): ProductBrand
    {
        return ProductBrand::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'logo' => null,
            'status' => 1,
            'is_popular' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    private function makeLabel(int $storeId, int $createdBy, string $name): ProductLabel
    {
        return ProductLabel::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'status' => 1,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    /** Raw, because both models are `Cachable` and an Eloquent read can serve a cached row. */
    private function rawStatus(string $table, int $id, string $column = 'status'): int
    {
        return (int) \DB::table($table)->where('id', $id)->value($column);
    }

    // =========================================================================================
    // Cross-tenant -- the live defect
    // =========================================================================================

    public function test_a_merchant_cannot_change_another_tenants_brand_status(): void
    {
        [$merchantA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Edit Product Brand');

        $foreign = $this->makeBrand($storeB->id, $merchantB->id, 'Beta Brand');

        // PREMISE: the row starts enabled, so a successful attack is visible as a change.
        $this->assertSame(1, $this->rawStatus('product_brands', $foreign->id),
            'premise: the foreign brand starts enabled');

        $response = $this->actingAs($merchantA)->postJson(
            route('product-brand.status'),
            ['id' => $foreign->id, 'status' => 0]
        );

        // 404, not 403: a row outside the caller's store is indistinguishable from one that does
        // not exist, so the response does not confirm an id is in use.
        $this->assertSame(404, $response->getStatusCode(),
            "merchant A was allowed to act on merchant B's brand");

        $this->assertSame(1, $this->rawStatus('product_brands', $foreign->id),
            "merchant A disabled merchant B's brand -- the status column is what the storefront "
            . 'filters on, so this hides a competitor\'s brand from their own shop');
    }

    public function test_a_merchant_cannot_change_another_tenants_brand_popularity(): void
    {
        [$merchantA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Edit Product Brand');

        $foreign = $this->makeBrand($storeB->id, $merchantB->id, 'Beta Brand');

        $response = $this->actingAs($merchantA)->postJson(
            route('product-brand.popular'),
            ['id' => $foreign->id, 'is_popular' => 1]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $this->rawStatus('product_brands', $foreign->id, 'is_popular'),
            "merchant A promoted merchant B's brand on their storefront");
    }

    public function test_a_merchant_cannot_change_another_tenants_label_status(): void
    {
        [$merchantA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Edit Product Label');

        $foreign = $this->makeLabel($storeB->id, $merchantB->id, 'Beta Label');

        $response = $this->actingAs($merchantA)->postJson(
            route('product-label.status'),
            ['id' => $foreign->id, 'status' => 0]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(1, $this->rawStatus('product_labels', $foreign->id),
            "merchant A disabled merchant B's label");
    }

    // =========================================================================================
    // The caller's OWN rows still work -- or the three cases above hold for free
    // =========================================================================================

    public function test_a_merchant_can_change_their_own_brand_status(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Product Brand');

        $own = $this->makeBrand($store->id, $merchant->id, 'Alpha Brand');

        $response = $this->actingAs($merchant)->postJson(
            route('product-brand.status'),
            ['id' => $own->id, 'status' => 0]
        );

        $response->assertOk();
        $this->assertSame(0, $this->rawStatus('product_brands', $own->id),
            'the fix refuses the caller their own row, which is a lockout rather than a fix');
    }

    public function test_a_merchant_can_change_their_own_label_status(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Product Label');

        $own = $this->makeLabel($store->id, $merchant->id, 'Alpha Label');

        $response = $this->actingAs($merchant)->postJson(
            route('product-label.status'),
            ['id' => $own->id, 'status' => 0]
        );

        $response->assertOk();
        $this->assertSame(0, $this->rawStatus('product_labels', $own->id));
    }

    // =========================================================================================
    // Permission
    // =========================================================================================

    public function test_a_staff_member_without_the_permission_cannot_toggle_their_own_brand(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        // Granted something ELSE, so this measures the brand permission rather than "a staff
        // member with no permissions at all can do nothing".
        $this->grantPermission($staff, 'Manage Order');

        $own = $this->makeBrand($store->id, $owner->id, 'Alpha Brand');

        $response = $this->actingAs($staff)->postJson(
            route('product-brand.status'),
            ['id' => $own->id, 'status' => 0]
        );

        $this->assertSame(403, $response->getStatusCode(),
            'a staff member holding no brand permission changed a brand');

        $this->assertSame(1, $this->rawStatus('product_brands', $own->id));
    }

    public function test_the_same_staff_member_can_toggle_it_once_granted(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $own = $this->makeBrand($store->id, $owner->id, 'Alpha Brand');

        $this->assertSame(403, $this->actingAs($staff)->postJson(
            route('product-brand.status'),
            ['id' => $own->id, 'status' => 0]
        )->getStatusCode(), 'premise: without the permission it is refused');

        $this->grantPermission($staff, 'Edit Product Brand');

        $this->assertSame(200, $this->actingAs($staff)->postJson(
            route('product-brand.status'),
            ['id' => $own->id, 'status' => 0]
        )->getStatusCode(), 'the permission does not admit the caller, so the case above '
            . 'measures nothing');
    }

    /**
     * THE PLATFORM OPERATOR IS NOT LOCKED OUT. Laratrust has no super-admin bypass, so
     * `isAbleTo()` alone refuses them -- reversing the two limbs is a lockout, not a tightening,
     * and `App\Http\Middleware\FinancePermission` records that happening here once already.
     *
     * The operator holds NO Laratrust permission in this fixture, deliberately.
     */
    public function test_the_platform_operator_is_not_locked_out(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->makeTenant();

        $own = $this->makeBrand($store->id, $merchant->id, 'Alpha Brand');

        $this->assertFalse($operator->isAbleTo('Edit Product Brand'),
            'premise: the operator must hold no such permission, or the operator limb is not '
            . 'what this measures');

        $response = $this->actingAs($operator)->postJson(
            route('product-brand.status'),
            ['id' => $own->id, 'status' => 0]
        );

        $response->assertOk();
        $this->assertSame(0, $this->rawStatus('product_brands', $own->id),
            'the platform operator was refused -- isAbleTo() alone denies them, so the operator '
            . 'limb must come FIRST');
    }

    // =========================================================================================
    // The verb, and the identifier
    // =========================================================================================

    /**
     * A GET DOES NOT WRITE. These three were `Route::get`, so an `<img src>` on any page a
     * signed-in merchant visited flipped a brand with no token and no click, and link
     * prefetching reached them too. F12 and F14 on this phase's ledger are the same shape.
     */
    public function test_a_get_cannot_toggle_a_brand_or_a_label(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Product Brand');
        $this->grantPermission($merchant, 'Edit Product Label');

        $brand = $this->makeBrand($store->id, $merchant->id, 'Alpha Brand');
        $label = $this->makeLabel($store->id, $merchant->id, 'Alpha Label');

        foreach ([
            ['product-brand.status', ['id' => $brand->id, 'status' => 0], 'product_brands', $brand->id, 'status'],
            ['product-brand.popular', ['id' => $brand->id, 'is_popular' => 1], 'product_brands', $brand->id, 'is_popular'],
            ['product-label.status', ['id' => $label->id, 'status' => 0], 'product_labels', $label->id, 'status'],
        ] as [$name, $query, $table, $id, $column]) {
            $before = $this->rawStatus($table, $id, $column);

            $response = $this->actingAs($merchant)
                ->get(route($name) . '?' . http_build_query($query));

            $this->assertSame(405, $response->getStatusCode(),
                "{$name} still answers a GET, so it is reachable from a third-party <img>");

            $this->assertSame($before, $this->rawStatus($table, $id, $column),
                "{$name} wrote on a GET");
        }
    }

    /**
     * `filter_var`, not `is_numeric`. `is_numeric` accepts '5.9' and rejects '5abc', while MySQL
     * coerces '5abc' to row 5 in a `WHERE id = ?` comparison.
     *
     * STATED HONESTLY: this cannot be reproduced on SQLite, where that comparison matches
     * nothing, so removing `filter_var` leaves this green. The control is needed on MySQL -- how
     * SQLite's affinity hid P01-SEC-13 is the project's own cautionary tale -- and what this case
     * really pins is that a malformed id is refused rather than 500ing, and that no row moves.
     */
    public function test_a_non_integer_id_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Product Brand');

        $own = $this->makeBrand($store->id, $merchant->id, 'Alpha Brand');

        $response = $this->actingAs($merchant)->postJson(
            route('product-brand.status'),
            ['id' => $own->id . 'abc', 'status' => 0]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(1, $this->rawStatus('product_brands', $own->id),
            'a coerced id changed a real row');
    }

    /** An anonymous caller is refused, and is told nothing about the row. */
    public function test_an_anonymous_caller_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $own = $this->makeBrand($store->id, $merchant->id, 'Alpha Secret Brand');

        $response = $this->postJson(route('product-brand.status'), ['id' => $own->id, 'status' => 0]);

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
        $this->assertStringNotContainsString('Alpha Secret Brand', (string) $response->getContent());
        $this->assertSame(1, $this->rawStatus('product_brands', $own->id));
    }

    /**
     * A ROW WITH NO `store_id` IS NOT EDITABLE BY ANYONE but the operator.
     *
     * It is invisible to every store predicate in the application, and must not become visible
     * here by the predicate comparing null to null or by `empty()` short-circuiting.
     */
    public function test_a_brand_with_no_store_is_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Product Brand');

        $orphan = ProductBrand::forceCreate([
            'name' => 'Orphan Brand',
            'slug' => 'orphan-brand-' . uniqid(),
            'logo' => null,
            'status' => 1,
            'is_popular' => 0,
            'store_id' => null,
            'created_by' => $merchant->id,
        ]);

        $this->assertNull(\DB::table('product_brands')->where('id', $orphan->id)->value('store_id'),
            'premise: the row really has no store');

        $response = $this->actingAs($merchant)->postJson(
            route('product-brand.status'),
            ['id' => $orphan->id, 'status' => 0]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(1, $this->rawStatus('product_brands', $orphan->id));
    }

    // =========================================================================================
    // The switch is not OFFERED to a caller the handler will refuse
    // =========================================================================================

    /**
     * A SWITCH THAT ANSWERS 403 IS A CONTROL OFFERED AND THEN REFUSED.
     *
     * Before this slice the toggles rendered for everybody and the handler checked nothing, so
     * they always worked. Tightening the handler without touching the markup would have left a
     * live switch that fails -- the same mistake the notification feed's "View order" button
     * made (ledger F27), found one slice earlier.
     *
     * DISABLED, NOT HIDDEN. The column's VALUE is information a caller holding
     * `Manage Product Brand` may read; hiding it would withhold something they are allowed to
     * see in order to prevent something they are not allowed to do. Driven through the real
     * yajra AJAX leg, because the switch markup exists only in the rendered rows -- the HTML
     * leg returns a scaffold with no rows in it.
     */
    public function test_the_switch_is_disabled_for_a_caller_who_may_read_but_not_edit(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        // May SEE the list, may not edit it.
        $this->grantPermission($staff, 'Manage Product Brand');

        $this->makeBrand($store->id, $owner->id, 'Alpha Brand');

        $rows = $this->brandRows($staff);

        // PREMISE: the row is served at all, so what follows is about the switch.
        $this->assertStringContainsString('Alpha Brand', $rows,
            'premise: Manage Product Brand admits the row');
        $this->assertStringContainsString('status-index', $rows,
            'premise: the switch is rendered');

        $this->assertStringContainsString('disabled', $rows,
            'a caller who may read but not edit was offered a live switch that the handler '
            . 'answers 403 to');
    }

    /** And it is NOT disabled once they hold the permission, or the case above measures nothing. */
    public function test_the_switch_is_live_for_a_caller_who_may_edit(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $this->grantPermission($staff, 'Manage Product Brand');
        $this->grantPermission($staff, 'Edit Product Brand');

        $this->makeBrand($store->id, $owner->id, 'Alpha Brand');

        $rows = $this->brandRows($staff);

        $this->assertStringContainsString('status-index', $rows, 'premise: the switch is rendered');
        $this->assertStringNotContainsString('disabled', $rows,
            'the switch stays disabled for a caller who may edit');
    }

    /**
     * The rendered brand rows out of the real yajra AJAX leg.
     *
     * Both headers, because `render()` branches on `ajax() && wantsJson()` together -- with only
     * one of them the drive silently takes the HTML branch and returns a scaffold with no rows,
     * so every assertion above would be reading an empty string.
     */
    private function brandRows($user): string
    {
        $query = ['draw' => 1, 'start' => 0, 'length' => 10];

        foreach (['name', 'status', 'is_popular'] as $i => $name) {
            $query["columns[{$i}][data]"] = $name;
            $query["columns[{$i}][name]"] = $name;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'asc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        $response = $this->actingAs($user)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->getJson('/product-brand?' . http_build_query($query));

        $this->assertNotSame(404, $response->getStatusCode(), 'the brand list route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the brand list must not 500');

        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('draw', $payload, 'premise: yajra served the AJAX leg');

        return (string) json_encode($payload['data'] ?? []);
    }

    // =========================================================================================
    // The premise: the routes are these handlers'
    // =========================================================================================

    public function test_the_three_routes_resolve_to_these_handlers_and_accept_only_post(): void
    {
        $expected = [
            'product-brand.status' => 'App\Http\Controllers\ProductBrandController@changeStatus',
            'product-brand.popular' => 'App\Http\Controllers\ProductBrandController@changePopular',
            'product-label.status' => 'App\Http\Controllers\ProductLabelController@changeStatus',
        ];

        foreach ($expected as $name => $action) {
            $route = \Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "the route {$name} does not exist");

            // Keyed on the ACTION, not the uri's spelling -- four censuses in this project have
            // made that mistake.
            $this->assertSame($action, $route->getActionName(),
                "the route {$name} was taken over by another declaration");

            $this->assertContains('POST', $route->methods(), "{$name} must accept POST");
            $this->assertNotContains('GET', $route->methods(),
                "{$name} still accepts GET, so it is a state-changing GET");
        }
    }
}
