<?php

namespace Tests\Feature\Security;

use App\Exports\CouponExport;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `coupon/export` had NO permission check at all.
 * =================================================================================================
 *
 * Found by the independent tenancy/authorization review of the P2-04-12 order-export change — in
 * the sibling that change did not touch. `CouponController::index()`, the list this file is the
 * export OF, requires `Manage Coupon`. `fileExport()` was three lines with no gate beyond the
 * route group's `auth`, so **any authenticated principal could download the store's entire coupon
 * book**: codes, limits, discount amounts and expiry dates. The same file-shaped hole in the
 * permission boundary that P1-SEC-11 closed on `order/export`, left open on its twin.
 *
 * IT IS NOT A CROSS-TENANT LEAK AND IS NOT ASSERTED AS ONE. `CouponExport::collection()` has
 * always filtered on `getStoreById(getCurrentStore())`, so the rows were the caller's own store's.
 * The defect is the missing permission. `test_the_export_holds_only_the_callers_own_store` pins the
 * isolation that already held, so a later change to the predicate cannot pass unnoticed — it is a
 * regression pin, not evidence for this fix.
 *
 * THE POSITIVE CASE IS NOT PADDING. Without it, a refusal for ANY reason — a broken route, a
 * missing store, a typo in the permission name — would satisfy the refusal cases. The permitted
 * merchant and the operator are asserted separately because `isAbleTo()` alone REFUSES the
 * platform operator: on a database built from source no role holds `Manage Coupon`, so a test
 * that happened to use an operator would measure the operator limb instead of the permission.
 *
 * AND THE REFUSAL MUST NOT BE A 401. The shipped Flutter app forces a logout on any 401, and this
 * is an ordinary commercial refusal on a web route, so it redirects back with a message.
 */
class CouponExportPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * `Coupon::$fillable` is non-empty and omits `store_id`, so `create()` would silently drop it
     * and the coupon would land in NO store — which would make every assertion below pass for the
     * wrong reason, since a null-`store_id` row is excluded from every store's export. The same
     * trap `CouponTenancyTest` documents; `forceCreate` plus a premise assertion is its answer.
     */
    private function couponIn(int $storeId, string $name): Coupon
    {
        $coupon = Coupon::forceCreate([
            'coupon_name' => $name,
            'coupon_code' => $name . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 5,
            'coupon_limit' => 10,
            'store_id' => $storeId,
        ]);

        $this->assertSame($storeId, (int) $coupon->fresh()->store_id,
            "fixture: the coupon {$name} must actually carry store {$storeId}");

        return $coupon;
    }

    // =============================================================================================
    //  THE PERMISSION
    // =============================================================================================

    public function test_a_principal_without_the_permission_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->couponIn((int) $store->id, 'SECRET');

        // Deliberately granted NOTHING: this is the staff member the hole exposed the book to.
        $response = $this->actingAs(User::find($merchant->id))->get('/coupon/export');

        $this->assertSame(302, $response->status(),
            'coupon/export answered with something other than a refusal for a principal holding '
            . 'no coupon permission — the whole coupon book is downloadable');
        $this->assertNotSame(200, $response->status());
    }

    public function test_a_principal_holding_the_permission_can_still_export(): void
    {
        // Without this case, a refusal for ANY reason would satisfy the case above.
        [$merchant, $store] = $this->makeTenant();
        $this->couponIn((int) $store->id, 'MINE');
        $this->grantPermission(User::find($merchant->id), 'Manage Coupon');

        $response = $this->actingAs(User::find($merchant->id))->get('/coupon/export')->baseResponse;

        // A refusal is a redirect; a grant is a real file download. Asserting the DOWNLOAD rather
        // than a 200 is what distinguishes "allowed through" from "rendered some page".
        $this->assertInstanceOf(BinaryFileResponse::class, $response,
            'a merchant holding Manage Coupon no longer receives the export file');
        $this->assertStringContainsString('Coupon.xlsx',
            (string) $response->headers->get('content-disposition'));
    }

    public function test_the_platform_operator_can_export_without_holding_the_permission(): void
    {
        // `isAbleTo()` alone refuses the operator, so the gate must test operator status FIRST.
        [, $store] = $this->makeTenant();
        $this->couponIn((int) $store->id, 'ANY');

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertInstanceOf(
            BinaryFileResponse::class,
            $this->actingAs($operator)->get('/coupon/export')->baseResponse,
            'the gate was written isAbleTo()-first and locked the platform operator out'
        );
    }

    public function test_the_refusal_is_not_a_401(): void
    {
        // An ordinary commercial refusal. A 401 forces a logout in the shipped Flutter app.
        [$merchant] = $this->makeTenant();

        // `baseResponse`, because a granted export is a BinaryFileResponse and a refusal is a
        // redirect: reading the status off the underlying response measures the status in both
        // cases instead of erroring on one of them.
        $this->assertNotSame(401,
            $this->actingAs(User::find($merchant->id))->get('/coupon/export')->baseResponse->getStatusCode());
    }

    public function test_an_anonymous_visitor_is_refused(): void
    {
        $response = $this->get('/coupon/export');

        $this->assertContains($response->status(), [302, 401, 403],
            'coupon/export served a coupon book to an anonymous visitor');
        $this->assertNotSame(200, $response->status());
    }

    // =============================================================================================
    //  THE STORE MUST RESOLVE  (P1-SEC-11 criterion 2)
    // =============================================================================================

    public function test_an_unresolvable_store_exports_nothing_rather_than_a_stack_trace(): void
    {
        /*
         * `getCurrentStore()` returns a hardcoded 1 for an operator with no `current_store`, so
         * `empty()` is not the question. On a deployment whose stores do not start at 1 — a deleted
         * first store is enough — `getStoreById(1)` is null and `CouponExport` dereferenced
         * `$store->id`. This drives the class directly, because it is constructible from anywhere.
         */
        [, $store] = $this->makeTenant();
        DB::table('stores')->where('id', $store->id)->update(['id' => 7]);

        $this->assertNull(DB::table('stores')->where('id', 1)->first(), 'fixture premise: no store id 1');

        $operator = $this->makePlatformAdmin(['current_store' => null]);
        $this->actingAs($operator);

        $this->assertSame(1, (int) getCurrentStore(), 'premise: the hardcoded 1 is what resolves');

        $threw = null;

        try {
            $rows = (new CouponExport())->collection();
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNull($threw,
            'CouponExport dereferenced a store that does not exist: ' . ($threw ? $threw->getMessage() : ''));
        $this->assertCount(0, $rows);
    }

    public function test_the_route_refuses_rather_than_crashing_when_no_store_resolves(): void
    {
        [, $store] = $this->makeTenant();
        DB::table('stores')->where('id', $store->id)->update(['id' => 7]);

        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->assertNotSame(500, $this->actingAs($operator)->get('/coupon/export')->status(),
            'coupon/export 500s for an operator with no current store on a deployment with no store 1');
    }

    // =============================================================================================
    //  ISOLATION  (a regression pin on behaviour that already held, not evidence for the fix)
    // =============================================================================================

    public function test_the_export_holds_only_the_callers_own_store(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->couponIn((int) $storeA->id, 'MINE');
        $this->couponIn((int) $storeB->id, 'THEIRS');

        $this->grantPermission(User::find($merchant->id), 'Manage Coupon');
        $this->actingAs(User::find($merchant->id));

        // `Coupon` is NOT in config/tenancy.php's scoped_models, so no global scope filters this
        // and the export's own `where('store_id', ...)` is the only control. That is also why a
        // plain assertion here is sound rather than needing withoutGlobalScopes().
        $names = (new CouponExport())->collection()->pluck('coupon_name')->all();

        $this->assertContains('MINE', $names, 'the merchant cannot export their own coupons');
        $this->assertNotContains('THEIRS', $names, 'the coupon export crossed into another store');
    }
}
