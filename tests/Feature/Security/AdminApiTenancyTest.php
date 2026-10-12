<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Store;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * NEW-api-store-id-is-the-tenant, NEW-api-delete-any-order,
 * NEW-api-edit-any-user, NEW-api-password-oracle.
 *
 * The admin API authenticates with `custom.auth` and then took its TENANT from
 * `$request->store_id` at 59 live code sites. These tests assert BOTH
 * directions on representative routes, because a one-directional authorization
 * test is how a control that breaks the product ships green:
 *
 *   - merchant A, holding a perfectly valid token, cannot touch merchant B's
 *     rows; and
 *   - merchant A's own call on their own store still succeeds.
 *
 * STATIC SWEEP FIRST. test_no_api_method_resolves_its_tenant_from_the_request()
 * reads the controller source, so it covers all 59 sites rather than only the
 * handful exercised over HTTP. A new method pasted from the old pattern fails
 * it on the next run.
 */
class AdminApiTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const CONTROLLER = 'app/Http/Controllers/Api/DashboardController.php';

    /**
     * Two complete tenants, each with a merchant, a store and a token.
     *
     * @return array{0: User, 1: Store, 2: string, 3: User, 4: Store}
     */
    private function twoTenants(): array
    {
        // The platform operator is created FIRST, deliberately, so that it --
        // and not a merchant -- occupies user id 1.
        //
        // This is not cosmetic. Self-registered merchants get
        // `created_by => 1` (Auth/RegisteredUserController :230), and
        // creatorId() returns a type='admin' user's OWN id (User.php:97-100).
        // So if a merchant fixture lands on id 1, its tenant id is 1, which
        // equals every other merchant's created_by, and the tenancy predicate
        // appears to pass for an unrelated tenant. The first draft of this suite
        // hit exactly that and reported a false negative on
        // test_update_password_refuses_another_users_id. In a real install user
        // 1 is the super admin, so this fixture now matches production instead
        // of manufacturing a collision.
        $this->makePlatformAdmin();

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->assertNotSame(1, (int) $merchantA->id, 'fixture: merchant must not be user 1');

        return [
            $merchantA,
            $storeA,
            $merchantA->createToken('auth_token')->plainTextToken,
            $merchantB,
            $storeB,
        ];
    }

    // ---------------------------------------------------------------- static

    /**
     * THE SWEEP. `Store::find($request->store_id)` must not reappear as live
     * code anywhere in the controller.
     *
     * Comment lines are excluded deliberately: four comments quote the old
     * expression as the thing being described, and a test that forbade the
     * STRING would force those explanations to be deleted -- which is how the
     * reason for a fix gets lost. Only code is asserted on.
     */
    public function test_no_api_method_resolves_its_tenant_from_the_request(): void
    {
        $offenders = [];

        foreach (file(base_path(self::CONTROLLER)) as $i => $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (str_contains($line, 'Store::find($request->store_id)')) {
                $offenders[] = ($i + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These sites still resolve the tenant from the request body instead of\n"
            . "through resolveOwnedStore(), so any valid token reaches any store:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * The helpers the sweep depends on must exist and must fail closed.
     *
     * ownedStoreIds() returning [] for an unauthenticated caller is the
     * property that makes every `whereIn('store_id', ...)` built on it match
     * nothing. If it ever returned every store id instead, 22 scoped lookups
     * would silently become unscoped again and no HTTP test here would notice.
     */
    public function test_the_tenancy_helpers_exist_and_fail_closed(): void
    {
        $controller = new \App\Http\Controllers\Api\DashboardController();

        foreach (['resolveOwnedStore', 'ownedStoreIds', 'resolveManageableUser'] as $method) {
            $this->assertTrue(
                method_exists($controller, $method),
                "Api\\DashboardController::{$method}() is gone; the scoped lookups that "
                . 'call it would raise a fatal, and the sweep above would still pass.'
            );
        }

        $this->makeTenant();
        $this->makeTenant();

        $this->assertGreaterThan(0, Store::count(), 'fixture sanity: stores exist');

        $reflect = new \ReflectionMethod($controller, 'ownedStoreIds');
        $reflect->setAccessible(true);

        // No authenticated user.
        $this->assertSame(
            [],
            $reflect->invoke($controller),
            'ownedStoreIds() must fail CLOSED with no principal. Returning every '
            . 'store id would re-open all 22 whereIn() scoped lookups at once.'
        );
    }

    // ------------------------------------------------------------ both ways

    /**
     * DeleteCoupon is the representative of the 22 scoped per-row lookups: the
     * store lookup was decoration and the delete keyed on `coupon_id` alone, so
     * even a correct store_id did not constrain it.
     */
    public function test_delete_coupon_refuses_another_tenants_coupon_and_keeps_the_row(): void
    {
        if (!Schema::hasTable('coupons')) {
            $this->markTestSkipped('BLOCKED: the `coupons` table is not declared by any migration.');
        }

        [, $storeA, $tokenA, , $storeB] = $this->twoTenants();

        // forceCreate, NOT create. Coupon declares a non-empty
        // `$fillable` (app/Models/Coupon.php:13) that does NOT list store_id,
        // so Coupon::create() DROPS it silently and the row lands with a null
        // store. The first draft of this suite used create() and the
        // owner-still-works test then failed for that reason alone -- a test
        // bug that read exactly like the fix having broken the product. Same
        // trap SecurityTestCase::makeRole() documents for Role.
        $victim = Coupon::forceCreate([
            'coupon_name' => 'VICTIM',
            'coupon_code' => 'VICTIM' . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 5,
            'coupon_limit' => 10,
            'store_id' => $storeB->id,
        ]);

        $this->assertSame(
            (int) $storeB->id,
            (int) $victim->fresh()->store_id,
            'fixture: the victim coupon must actually carry the victim store_id.'
        );

        // The attack as it worked: a valid token, the attacker's OWN store_id,
        // and the victim's coupon id.
        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->deleteJson('/api/admin/deletecoupon', [
                'store_id' => $storeA->id,
                'coupon_id' => $victim->id,
            ]);

        $this->assertDatabaseHas('coupons', ['id' => $victim->id]);

        // And with the victim's store_id, which is the other half of the hole.
        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->deleteJson('/api/admin/deletecoupon', [
                'store_id' => $storeB->id,
                'coupon_id' => $victim->id,
            ]);

        $this->assertDatabaseHas('coupons', ['id' => $victim->id]);
    }

    /**
     * THE CONVERSE, and the half that stops this becoming the third "control
     * that broke the product" in this project. The merchant's own delete of
     * their own coupon must still work.
     */
    public function test_delete_coupon_still_works_for_the_owning_merchant(): void
    {
        if (!Schema::hasTable('coupons')) {
            $this->markTestSkipped('BLOCKED: the `coupons` table is not declared by any migration.');
        }

        [, $storeA, $tokenA] = $this->twoTenants();

        $own = Coupon::forceCreate([
            'coupon_name' => 'MINE',
            'coupon_code' => 'MINE' . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 5,
            'coupon_limit' => 10,
            'store_id' => $storeA->id,
        ]);

        $this->assertSame((int) $storeA->id, (int) $own->fresh()->store_id, 'fixture sanity');

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->deleteJson('/api/admin/deletecoupon', [
                'store_id' => $storeA->id,
                'coupon_id' => $own->id,
            ])->assertStatus(200);

        $this->assertDatabaseMissing('coupons', ['id' => $own->id]);
    }

    /**
     * AddCategory is the representative of the resolution half: it DID scope
     * its write by the store, which is exactly why resolving an unowned store
     * was enough to write into another tenant.
     */
    public function test_add_category_cannot_write_into_another_tenants_store(): void
    {
        if (!Schema::hasTable('categories')) {
            $this->markTestSkipped('BLOCKED: the `categories` table is not declared by any migration.');
        }

        [, , $tokenA, , $storeB] = $this->twoTenants();

        $before = Category::where('store_id', $storeB->id)->count();

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/addcategory', [
                'store_id' => $storeB->id,
                'name' => 'planted',
                'image' => 'x',
                'icon_image' => 'x',
                'trending' => 1,
                'status' => 1,
            ]);

        $this->assertSame(
            $before,
            Category::where('store_id', $storeB->id)->count(),
            'A category was created in another tenant\'s store. The store_id came '
            . 'straight off the request body.'
        );
    }

    /**
     * NEW-api-edit-any-user. The worst of the four: `User::find($request->
     * user_id)` with no comparison to the caller, writing `email`. Changing the
     * platform operator's email address is a takeover primitive, because the
     * password-reset flow then delivers to the attacker.
     */
    public function test_edit_profile_cannot_change_another_users_email(): void
    {
        [, $storeA, $tokenA] = $this->twoTenants();

        $platformAdmin = $this->makePlatformAdmin(['email' => 'operator@example.test']);

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/editprofile', [
                'store_id' => $storeA->id,
                'user_id' => $platformAdmin->id,
                'name' => 'pwned',
                'email' => 'attacker@example.test',
            ]);

        $this->assertSame(
            'operator@example.test',
            $platformAdmin->fresh()->email,
            'The platform operator\'s email was rewritten through the admin API by '
            . 'an ordinary merchant token. That is account takeover via password reset.'
        );
        $this->assertNotSame('pwned', $platformAdmin->fresh()->name);
    }

    /**
     * THE CONVERSE: editing your OWN profile is the real Flutter flow and must
     * keep working.
     */
    public function test_edit_profile_still_works_on_your_own_account(): void
    {
        [$merchantA, $storeA, $tokenA] = $this->twoTenants();

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/editprofile', [
                'store_id' => $storeA->id,
                'user_id' => $merchantA->id,
                'name' => 'My New Name',
            ])->assertStatus(200);

        $this->assertSame('My New Name', $merchantA->fresh()->name);
    }

    /**
     * NEW-api-updatepassword-null-deref. `$current_password = $admin->password`
     * ran BEFORE the method's own `!empty($admin)` check, so an unmatched
     * user_id produced "Attempt to read property on null" -- a 500 where the
     * error branch was already written and unreachable.
     *
     * Asserting "not 500" rather than a specific body: the point is that the
     * method answers instead of crashing.
     */
    public function test_update_password_does_not_500_on_an_unknown_user_id(): void
    {
        [, $storeA, $tokenA] = $this->twoTenants();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/updatepassword', [
                'store_id' => $storeA->id,
                'user_id' => 999999,
                'old_password' => 'whatever',
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ]);

        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'UpdatePassword still dereferences a null user before its own null check.'
        );
    }

    /**
     * NEW-api-password-oracle: and it must not accept another user's id at all,
     * so it cannot be used to test old-password guesses against any account on
     * the platform.
     */
    public function test_update_password_refuses_another_users_id(): void
    {
        [, $storeA, $tokenA, $merchantB] = $this->twoTenants();

        $before = $merchantB->fresh()->password;

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/updatepassword', [
                'store_id' => $storeA->id,
                'user_id' => $merchantB->id,
                'old_password' => 'password',
                'password' => 'takenover',
                'password_confirmation' => 'takenover',
            ]);

        $this->assertSame(
            $before,
            $merchantB->fresh()->password,
            'Another merchant\'s password hash changed through the admin API.'
        );
    }

    /**
     * NEW-api-delete-any-order. The store was resolved and then never used:
     * four deletes keyed on `order_id` alone. Irreversible, and it took the
     * order's tax, coupon and billing detail rows with it.
     */
    public function test_delete_order_refuses_another_tenants_order(): void
    {
        [, $storeA, $tokenA, , $storeB] = $this->twoTenants();

        $victimOrder = $this->makeOrder($storeB);

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->deleteJson('/api/admin/deleteorder', [
                'store_id' => $storeA->id,
                'order_id' => $victimOrder->id,
            ]);

        $this->assertDatabaseHas('orders', ['id' => $victimOrder->id]);

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->deleteJson('/api/admin/deleteorder', [
                'store_id' => $storeB->id,
                'order_id' => $victimOrder->id,
            ]);

        // assertDatabaseHas()'s third parameter is the CONNECTION name, not a
        // message -- passing prose there raises
        // "Database connection [...] not configured", which is how the first
        // draft of this test errored rather than asserting.
        $this->assertDatabaseHas('orders', ['id' => $victimOrder->id]);
    }

    public function test_delete_order_still_works_for_the_owning_merchant(): void
    {
        [, $storeA, $tokenA] = $this->twoTenants();

        $own = $this->makeOrder($storeA);

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->deleteJson('/api/admin/deleteorder', [
                'store_id' => $storeA->id,
                'order_id' => $own->id,
            ])->assertStatus(200);

        $this->assertDatabaseMissing('orders', ['id' => $own->id]);
    }

    /**
     * DeleteTax covers the fourth shape: a method that had NO null check at
     * all, so scoping the lookup had to come with a guard or a denial would
     * surface as a 500.
     */
    public function test_delete_tax_refuses_another_tenants_tax_row(): void
    {
        if (!Schema::hasTable('taxes')) {
            $this->markTestSkipped('BLOCKED: the `taxes` table is not declared by any migration.');
        }

        [, $storeA, $tokenA, , $storeB] = $this->twoTenants();

        // `taxes` has exactly id, name, store_id, timestamps
        // (2023_12_22_060417_create_taxes_table.php:15-18).
        $victim = Tax::forceCreate([
            'name' => 'VAT',
            'store_id' => $storeB->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->deleteJson('/api/admin/deletetax', [
                'store_id' => $storeA->id,
                'tax_id' => $victim->id,
            ]);

        $this->assertDatabaseHas('taxes', ['id' => $victim->id]);
        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'A denial must be a clean refusal, not a crash.'
        );
    }

    /**
     * 403-NOT-401, pinned on the API surface.
     *
     * repo-B lib/network/network.dart maps ANY 401 into a forced logout that
     * clears preferences. A tenancy denial that answered 401 would log every
     * merchant out of the shipped app, so none of these authorization denials
     * may be a 401. A VALID token is sent in every case here, so 401 could only
     * mean the denial path chose the wrong status.
     */
    #[DataProvider('denialRouteProvider')]
    public function test_a_tenancy_denial_is_never_a_401(string $method, string $uri, array $payloadKeys): void
    {
        [, , $tokenA, , $storeB] = $this->twoTenants();

        $payload = array_merge(['store_id' => $storeB->id], $payloadKeys);

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->json($method, $uri, $payload);

        $this->assertNotSame(
            401,
            $response->getStatusCode(),
            "{$uri} answered a tenancy denial with 401. repo-B network.dart turns any "
            . '401 into _handleUnauthorized(), which clears preferences and forces a '
            . 're-login -- so this would log the merchant out of the shipped app.'
        );
    }

    public static function denialRouteProvider(): array
    {
        return [
            'deletecoupon' => ['DELETE', '/api/admin/deletecoupon', ['coupon_id' => 1]],
            'deletetax' => ['DELETE', '/api/admin/deletetax', ['tax_id' => 1]],
            'deleteorder' => ['DELETE', '/api/admin/deleteorder', ['order_id' => 1]],
            'deletecategory' => ['DELETE', '/api/admin/deletecategory', ['category_id' => 1]],
            'editprofile' => ['POST', '/api/admin/editprofile', ['user_id' => 1]],
        ];
    }
}
