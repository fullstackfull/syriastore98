<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-07-13 — the money the dashboard withholds must not be one route away.
 * =================================================================================================
 *
 * INDEPENDENT REVIEW CAUGHT THIS, and it is the finding that mattered most: gating `/dashboard`
 * alone closed one screen and left the exposure intact. Four sibling handlers in the SAME
 * controller served the same money to the same token —
 *
 *   * `Top_orders`   — the dashboard's own widget: the five largest orders, ranked BY `final_price`
 *   * `orderlist`    — `final_price` on every row of the paginator
 *   * `productlist`  — `cost_price` set explicitly, i.e. the store's margin
 *   * `couponlist`   — full Coupon models, `discount_amount` among them
 *   * `staticsdata`  — the analytics twin of the `visitorchart` block
 *
 * — each guarded by `resolveOwnedStore()` and nothing else. That is a TENANT control, and it
 * admits any store in the caller's tenant by design, so a staff member with no order permission
 * read their employer's order money by asking a different route. `routes/api.php` declares no
 * permission middleware anywhere, so there was no second line of defence.
 *
 * 403, NOT 401, AND THAT IS NOT A STYLE CHOICE. The shipped Flutter app force-clears its session
 * on any 401 and treats 403 as "authorized but not permitted" with the session intact
 * (`lib/network/network.dart`, "Deliberately NOT a forced logout"). A 401 here would log merchants
 * out of the app for opening a screen they were never allowed to open. Every test below asserts
 * the refusal is not 401.
 *
 * THE GRANT IS EVALUATED AGAINST THE STORE BEING SERVED, not against `users.current_store`. These
 * handlers key their rows on `?store_id=`, which `resolveOwnedStore()` admits anywhere in the
 * tenant — so `test_a_permission_in_one_store_does_not_unlock_another` is the case that would
 * otherwise let an employee permitted in store A read store B.
 */
class AdminApiListPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Each gated route, with the permission that opens it. */
    private const GATED = [
        ['GET', 'orderlist', 'Manage Order'],
        ['GET', 'Top_orders', 'Manage Order'],
        ['GET', 'productlist', 'Manage Products'],
        ['GET', 'couponlist', 'Manage Coupon'],
        ['GET', 'staticsdata', 'Manage Store Analytics'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // `orderlist`, `Top_orders` and `productlist` all read `currency_rates`, which GATE F
        // leaves unmanaged. WITHOUT THIS THE SUITE LIED: `assertNotSame(403, ...)` passes on a
        // 500, so the three "still serves the owner" assertions were satisfied by a missing-table
        // crash rather than by the gate letting the merchant through. Measured, not supposed --
        // a probe reported 500 for orderlist and Top_orders and 200 for the rest.
        $this->requireCurrencyRatesStandIn();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function ask(User $user, string $route, Store $store)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($user))
            ->getJson('/api/admin/' . $route . '?store_id=' . $store->id);
    }

    /** @return array{0: User, 1: Store} */
    private function merchantWithStore(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        return [User::find($merchant->id), $store];
    }

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    /**
     * THE DEFECT, on every route at once. A staff member of this tenant, with a permission that
     * has nothing to do with orders, products, coupons or analytics.
     */
    public function test_every_gated_route_refuses_a_staff_member_without_its_permission(): void
    {
        [$merchant, $store] = $this->merchantWithStore();
        $staff = $this->staffIn($merchant, $store, 'Manage Product Category');

        foreach (self::GATED as [$method, $route, $permission]) {
            $response = $this->ask($staff, $route, $store);

            $this->assertSame(403, $response->getStatusCode(),
                "api/admin/{$route} served a staff member who does not hold `{$permission}`. "
                . 'Body: ' . substr((string) $response->getContent(), 0, 200));
        }
    }

    /** NEVER 401, on any of them: a 401 logs the merchant out of the shipped app. */
    public function test_no_gated_route_answers_401_for_a_permission_refusal(): void
    {
        [$merchant, $store] = $this->merchantWithStore();
        $staff = $this->staffIn($merchant, $store, 'Manage Product Category');

        foreach (self::GATED as [$method, $route]) {
            $this->assertNotSame(401, $this->ask($staff, $route, $store)->getStatusCode(),
                "api/admin/{$route} answered 401 for a permission refusal, which force-clears "
                . 'the session in the Flutter app');
        }
    }

    /**
     * THE COUNTERPART, and the reason the suite is trustworthy: the merchant who owns the store
     * must still be served. Refusing everyone would pass the test above.
     */
    public function test_every_gated_route_still_serves_the_store_owner(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        // array_unique, because two routes share `Manage Order` and granting the same permission
        // twice violates the permission_role unique key.
        foreach (array_unique(array_column(self::GATED, 2)) as $permission) {
            $this->grantPermission(User::find($merchant->id), $permission);
        }

        $merchant = User::find($merchant->id);

        foreach (self::GATED as [$method, $route, $permission]) {
            // 200, NOT merely "not 403". `assertNotSame(403)` is satisfied by a 500, and three of
            // these five endpoints really do 500 without the stand-in in setUp() -- so the loose
            // assertion was passing on a crash.
            $this->assertSame(200, $this->ask($merchant, $route, $store)->getStatusCode(),
                "api/admin/{$route} did not serve the store owner, who holds `{$permission}`");
        }
    }

    /** And a staff member who DOES hold the permission is served. */
    public function test_a_permitted_staff_member_is_served(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        foreach (self::GATED as [$method, $route, $permission]) {
            $staff = $this->staffIn($merchant, $store, $permission);

            $this->assertSame(200, $this->ask($staff, $route, $store)->getStatusCode(),
                "api/admin/{$route} did not serve a staff member who holds `{$permission}`");
        }
    }

    /**
     * THE PLATFORM OPERATOR, because `isAbleTo()` refuses them. A gate built on role membership
     * alone would lock the operator out of every one of these.
     */
    public function test_the_operator_is_not_refused(): void
    {
        $operator = $this->makePlatformAdmin();
        $store = $this->makeStore($operator, ['default_language' => 'en']);
        $operator->current_store = $store->id;
        $operator->save();
        $operator = User::find($operator->id);

        foreach (self::GATED as [$method, $route]) {
            $this->assertSame(200, $this->ask($operator, $route, $store)->getStatusCode(),
                "api/admin/{$route} did not serve the platform operator");
        }
    }

    /**
     * THE TRAP THE REQUIRED `$storeId` PARAMETER EXISTS TO PREVENT. These handlers serve the store
     * named by `?store_id=`, which `resolveOwnedStore()` admits anywhere in the tenant. If the
     * gate were evaluated against `users.current_store` instead, a staff member permitted in
     * store A would read store B's rows by asking for B while sitting in A.
     */
    public function test_a_permission_in_one_store_does_not_unlock_another(): void
    {
        [$merchant, $storeA] = $this->merchantWithStore();
        $storeB = $this->makeStore($merchant, ['default_language' => 'en']);

        // Permitted in store A, and sitting in store A.
        $staff = $this->staffIn($merchant, $storeA, 'Manage Order');

        $this->assertSame(200, $this->ask($staff, 'orderlist', $storeA)->getStatusCode(),
            'premise: the employee really may read orders in their own store');

        $this->assertSame(403, $this->ask($staff, 'orderlist', $storeB)->getStatusCode(),
            "store A's employee read store B's orders -- the gate was evaluated against "
            . 'current_store rather than against the store being served');
    }

    /**
     * THE TENANT CONTROL IS UNCHANGED, which matters because the permission gate sits after it:
     * another tenant must still be refused, and for the tenancy reason rather than the new one.
     */
    public function test_another_tenant_is_still_refused_entirely(): void
    {
        [, $storeM] = $this->merchantWithStore();

        $otherMerchant = $this->makeMerchant();
        $storeN = $this->makeStore($otherMerchant, ['default_language' => 'en']);
        $otherMerchant->current_store = $storeN->id;
        $otherMerchant->save();
        $this->grantPermission(User::find($otherMerchant->id), 'Manage Order');
        $otherMerchant = User::find($otherMerchant->id);

        $this->assertSame(403, $this->ask($otherMerchant, 'orderlist', $storeM)->getStatusCode(),
            "another tenant read this store's orders");
    }
}
