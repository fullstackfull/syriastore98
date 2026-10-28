<?php

namespace Tests\Feature\Security;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F157 — the platform operator's rights over a merchant's coupons: READ AND EXPORT ONLY.
 * =================================================================================================
 *
 * **DECIDED 2026-10-28.** The operator may read a store's coupon list and export it, and may not
 * create, edit, delete or change the value of a merchant's coupons.
 *
 * WHAT WAS WRONG BEFORE, AND IT WAS AN ASYMMETRY RATHER THAN A HOLE. When F156 closed the
 * unguarded export it gave `fileExport` the project's standard operator-first gate, so the operator
 * could export. `index()` stayed a bare `isAbleTo('Manage Coupon')`, which refuses the operator
 * because Laratrust has no super-admin bypass. So the operator could **export a coupon book they
 * could not open** — and the owner's instruction was to unify the two so neither path allows what
 * the other forbids.
 *
 * AND THE SECURITY HALF IS THE STORE, NOT THE PERMISSION. `getCurrentStore()` hands an operator
 * with an empty `current_store` the **hardcoded literal 1** (`helper.php:636-637`). `getStoreById(1)`
 * normally resolves, so the null-store guard passes and the operator is served *store 1's* coupon
 * book — codes, limits, discounts, expiry — for a store they never chose. That is the
 * "default that could expose an unintended store" the decision forbids by name, and it is what
 * `test_an_operator_with_no_store_selected_is_refused_rather_than_shown_store_one` is about. That
 * case is the reason this file exists; everything else here is the matrix around it.
 *
 * THE WRITES ARE REFUSED EXPLICITLY, NOT BY ACCIDENT. `store()`, `update()` and `destroy()` gate on
 * bare `isAbleTo()`, which already refused the operator — the right answer for the wrong reason.
 * Anyone adding an operator limb "to match the read paths" would have granted exactly what this
 * decision withholds, so the refusal is now stated in the code and asserted here.
 */
class CouponOperatorRightsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function couponIn(int $storeId, string $name): Coupon
    {
        // `Coupon::$fillable` omits store_id, so create() would drop it and the row would land in
        // no store -- making every assertion below pass for the wrong reason.
        $coupon = Coupon::forceCreate([
            'coupon_name' => $name,
            'coupon_code' => $name . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 5,
            'coupon_limit' => 10,
            'store_id' => $storeId,
        ]);

        $this->assertSame($storeId, (int) $coupon->fresh()->store_id,
            "fixture: coupon {$name} must really carry store {$storeId}");

        return $coupon;
    }

    // =============================================================================================
    //  THE OPERATOR: READ AND EXPORT YES
    // =============================================================================================

    public function test_the_operator_may_read_the_list_of_the_store_they_selected(): void
    {
        [, $store] = $this->makeTenant();
        $this->couponIn((int) $store->id, 'READABLE');

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertSame(200, $this->actingAs($operator)->get('/coupon')->status(),
            'the operator cannot open the coupon list of the store they selected, while being able '
            . 'to export it -- the asymmetry F157 named');
    }

    public function test_the_operator_may_export_the_store_they_selected(): void
    {
        [, $store] = $this->makeTenant();
        $this->couponIn((int) $store->id, 'EXPORTABLE');

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertInstanceOf(
            \Symfony\Component\HttpFoundation\BinaryFileResponse::class,
            $this->actingAs($operator)->get('/coupon/export')->baseResponse
        );
    }

    // =============================================================================================
    //  THE OPERATOR: THE STORE MUST BE CHOSEN
    // =============================================================================================

    public function test_an_operator_with_no_store_selected_is_refused_rather_than_shown_store_one(): void
    {
        /*
         * The case this file exists for. A store whose id really is 1 is created and filled with a
         * coupon, and the operator selects NOTHING. `getCurrentStore()` returns the hardcoded 1,
         * `getStoreById(1)` resolves, and before this fix both the list and the export served that
         * merchant's coupon book to an operator who had chosen no store at all.
         */
        [, $store] = $this->makeTenant();
        \Illuminate\Support\Facades\DB::table('stores')->where('id', $store->id)->update(['id' => 1]);
        $this->couponIn(1, 'STORE-ONE-SECRET');

        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('stores')->where('id', 1)->first(),
            'fixture premise: store 1 exists, so a null-store guard would NOT catch this');

        $operator = $this->makePlatformAdmin(['current_store' => null]);
        $this->actingAs($operator);

        $this->assertSame(1, (int) getCurrentStore(),
            'premise: the helper really does hand back the hardcoded 1');

        $this->assertSame(302, $this->get('/coupon')->status(),
            'an operator who selected no store was served a store they never chose');

        $export = $this->get('/coupon/export');
        $this->assertNotInstanceOf(
            \Symfony\Component\HttpFoundation\BinaryFileResponse::class,
            $export->baseResponse,
            'an operator who selected no store was handed store 1\'s coupon book as a file'
        );
    }

    // =============================================================================================
    //  THE OPERATOR: WRITES NO
    // =============================================================================================

    /** @dataProvider writeRoutes */
    public function test_the_operator_may_not_write_a_merchants_coupon(string $method, string $uri): void
    {
        [, $store] = $this->makeTenant();
        $coupon = $this->couponIn((int) $store->id, 'PROTECTED');
        $before = $coupon->fresh()->toArray();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $uri = str_replace('{id}', (string) $coupon->id, $uri);
        $response = $this->actingAs($operator)->call($method, $uri, [
            'coupon_name' => 'OPERATOR-REWROTE-THIS',
            'coupon_code' => 'HACKED' . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 99,
            'coupon_limit' => 1,
        ]);

        $this->assertNotSame(200, $response->status(),
            "the operator reached {$method} {$uri}, which the decision withholds");

        $after = Coupon::find($coupon->id);
        $this->assertNotNull($after, 'the operator deleted a merchant\'s coupon');
        $this->assertSame($before['coupon_name'], $after->coupon_name,
            'the operator changed a merchant\'s coupon name');
        $this->assertEquals($before['discount_amount'], $after->discount_amount,
            'the operator changed a merchant\'s discount amount');
    }

    /**
     * @dataProvider writeRoutes
     *
     * THE CASE ABOVE DID NOT BITE, AND THIS ONE IS WHY IT HAD TO EXIST.
     *
     * Removing `refuseOperatorWrite()` from `destroy()` left all of the other cases passing,
     * because the bare `isAbleTo('Delete Coupon')` underneath already refuses the operator --
     * Laratrust has no super-admin bypass and no role holds that permission on a database built
     * from source. So the test was measuring Laratrust, not my guard, and could not have noticed
     * the guard disappearing.
     *
     * The risk F157 names is precisely the case the other test cannot construct: a principal who
     * WOULD pass the permission check. So the operator is GRANTED the coupon permission here and
     * must still be refused. That is the only arrangement in which the explicit refusal is the
     * thing doing the work -- and removing it now fails this case while the rest stay green.
     */
    public function test_an_operator_who_holds_the_permission_is_still_refused_the_write(
        string $method,
        string $uri
    ): void {
        [, $store] = $this->makeTenant();
        $coupon = $this->couponIn((int) $store->id, 'GRANTED-OPERATOR');
        $before = $coupon->fresh()->toArray();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        // Every coupon permission, so nothing but the explicit refusal can stop them.
        foreach (['Manage Coupon', 'Create Coupon', 'Edit Coupon', 'Delete Coupon'] as $permission) {
            $this->grantPermission(User::find($operator->id), $permission);
        }
        $operator = User::find($operator->id);

        $this->assertTrue($operator->isAbleTo('Delete Coupon'),
            'premise: the operator really does hold the permission, or this case proves nothing');

        $uri = str_replace('{id}', (string) $coupon->id, $uri);
        $response = $this->actingAs($operator)->call($method, $uri, [
            'coupon_name' => 'GRANTED-OPERATOR-REWROTE-THIS',
            'coupon_code' => 'GRANTED' . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 77,
            'coupon_limit' => 1,
        ]);

        $this->assertNotSame(200, $response->status(),
            "an operator HOLDING the coupon permission reached {$method} {$uri}; the owner's "
            . 'decision is read and export only, so the refusal must not depend on the permission '
            . 'happening to be absent');

        $after = Coupon::find($coupon->id);
        $this->assertNotNull($after, 'a permission-holding operator deleted a merchant\'s coupon');
        $this->assertSame($before['coupon_name'], $after->coupon_name);
        $this->assertEquals($before['discount_amount'], $after->discount_amount);
    }

    public static function writeRoutes(): array
    {
        return [
            'create form' => ['GET', '/coupon/create'],
            'store'       => ['POST', '/coupon'],
            'edit form'   => ['GET', '/coupon/{id}/edit'],
            'update'      => ['PUT', '/coupon/{id}'],
            'destroy'     => ['DELETE', '/coupon/{id}'],
        ];
    }

    // =============================================================================================
    //  THE MATRIX AROUND IT
    // =============================================================================================

    public function test_a_merchant_holding_the_permission_still_reads_and_exports(): void
    {
        // Without this, a refusal for any reason would satisfy every case above.
        [$merchant, $store] = $this->makeTenant();
        $this->couponIn((int) $store->id, 'MINE');
        $this->grantPermission(User::find($merchant->id), 'Manage Coupon');

        $this->assertSame(200, $this->actingAs(User::find($merchant->id))->get('/coupon')->status());
        $this->assertInstanceOf(
            \Symfony\Component\HttpFoundation\BinaryFileResponse::class,
            $this->actingAs(User::find($merchant->id))->get('/coupon/export')->baseResponse
        );
    }

    public function test_a_principal_without_the_permission_reads_nothing_and_exports_nothing(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->couponIn((int) $store->id, 'SECRET');

        $this->assertSame(302, $this->actingAs(User::find($merchant->id))->get('/coupon')->status());
        $this->assertNotInstanceOf(
            \Symfony\Component\HttpFoundation\BinaryFileResponse::class,
            $this->actingAs(User::find($merchant->id))->get('/coupon/export')->baseResponse
        );
    }

    public function test_the_read_and_the_export_agree_for_every_principal(): void
    {
        /*
         * The unification itself, asserted as one property rather than as two separate cases:
         * whatever the list allows, the export allows, and the reverse. That is the invariant F157
         * asked for, and it is the one a future edit to either handler would break.
         */
        [$merchant, $store] = $this->makeTenant();
        $this->couponIn((int) $store->id, 'PAIRED');

        $permitted = User::find($merchant->id);
        $this->grantPermission($permitted, 'Manage Coupon');

        [$other] = $this->makeTenant();

        foreach ([
            'permitted merchant' => User::find($permitted->id),
            'unpermitted merchant' => User::find($other->id),
            'operator with a store' => $this->makePlatformAdmin(['current_store' => $store->id]),
            'operator with no store' => $this->makePlatformAdmin(['current_store' => null]),
        ] as $label => $principal) {
            $listOk = $this->actingAs($principal)->get('/coupon')->status() === 200;
            $exportOk = $this->actingAs($principal)->get('/coupon/export')->baseResponse
                instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse;

            $this->assertSame($listOk, $exportOk,
                "{$label}: the list and the export disagree (list=" . var_export($listOk, true)
                . ', export=' . var_export($exportOk, true) . ') -- that disagreement IS F157');
        }
    }
}
