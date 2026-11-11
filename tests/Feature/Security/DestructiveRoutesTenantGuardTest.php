<?php

namespace Tests\Feature\Security;

use App\Models\Menu;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Four routed destructive actions that any verified account could aim at any tenant.
 * =================================================================================================
 *
 * FOUND IN THE PHASE 2.5 SECURITY CLOSURE, measured end to end rather than read off the source.
 * All four share one shape, which is this codebase's recorded defect shape:
 *
 *     a routed DESTRUCTIVE action, on a model absent from config/tenancy.php `scoped_models`,
 *     whose target id comes from the URL, with no authorization in the handler and no gate on
 *     the route.
 *
 * `null_tenant => 'open'` means the global scope adds NO predicate when no tenant resolves, and
 * four of the five models here (Plan, MenuItem, CustomLink, and PlanOrder as routed) have no
 * `store_id` the scope could use even if they were allowlisted. So nothing upstream was ever going
 * to stop these; the handler is the only place a check can live.
 *
 *   F-1  DELETE /bank_transfer/{order}   BankTransferController::destroy
 *        `destroy(PlanOrder $order)` -> `if ($order) { $order->delete(); }`. Under route-model
 *        binding `$order` is never null, so the condition is decoration. Hard delete, no soft
 *        delete on PlanOrder, no audit row: a merchant's SUBSCRIPTION PAYMENT RECORD -- order id,
 *        price paid, payment status and the pointer to the bank slip they uploaded -- erased by
 *        anyone with an account.
 *
 *        The repository already states the principle this breaks. `BulkDeleteTenantGuard`
 *        classifies `plan-order` as PLATFORM_GLOBAL because "letting the payer erase it is an
 *        audit problem". THE BULK DOOR REFUSES IT AND THE SINGLE-ROW DOOR DID NOT LOOK.
 *
 *   F-2  DELETE /menu/delete-item/{id}   MenuController::deleteItem
 *        `MenuItem::find($id)->delete()`, and it cascades into `CustomLink::find(...)->delete()`.
 *        Any tenant's storefront navigation, silently. `index()` and `store()` on the same
 *        controller both check `isAbleTo('Manage Menu')`; `deleteItem()` checks nothing.
 *
 *   F-3  DELETE /menu-items/{id}         MenuItemsController::destroy
 *        `CustomLink::find($id)->delete()`, same shape.
 *
 *   F-4  DELETE /plan/{plan}             PlanController::destroy
 *        A PLATFORM subscription plan, deleted by an ordinary merchant. This is privilege
 *        escalation rather than only isolation: the merchant role is granted `Manage Plan` and
 *        never Create or Edit (`Utility::addNewData()`'s `$companyNewPermission`), yet `destroy`
 *        outranked the view-only grant it was paired with.
 *
 * WHAT THIS TEST FIXES AND WHAT IT DELIBERATELY DOES NOT. Every case below asserts that a
 * CROSS-TENANT or UNAUTHORISED actor is refused and the row SURVIVES. Not one of them asserts that
 * the legitimate owner may or may not delete their own record -- whether a merchant should be able
 * to erase their own subscription payment at all is an AUDIT question with a financial dimension,
 * it is recorded for the owner in the acceptance report, and this change does not decide it.
 */
class DestructiveRoutesTenantGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();

        return [User::find($merchant->id), $store];
    }

    private function planOrderFor(int $storeId, int $userId): int
    {
        return (int) DB::table('plan_orders')->insertGetId([
            'order_id' => 'ORD-' . $storeId . '-' . $userId,
            'name' => 'Subscriber',
            'plan_name' => 'Growth',
            'plan_id' => 1,
            'price' => 499.00,
            'price_currency' => 'USD',
            'txn_id' => 'txn_' . $storeId . '_' . $userId,
            'payment_status' => 'succeeded',
            'payment_type' => 'Bank Transfer',
            'receipt' => 'uploads/bank-slip-' . $storeId . '.png',
            'user_id' => $userId,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_merchant_cannot_delete_another_tenants_subscription_payment_record(): void
    {
        [$attacker] = $this->tenant();
        [$victim, $victimStore] = $this->tenant();

        $orderId = $this->planOrderFor($victimStore->id, $victim->id);

        $this->actingAs($attacker)->delete('/bank_transfer/' . $orderId);

        /* THROUGH THE RAW TABLE, not Eloquent. PlanOrder is not in `scoped_models`, but a plain
           find() in a test answers against whichever tenant happens to be resolved -- the recorded
           way an "it is gone" assertion passes whether or not the fix works. */
        $this->assertSame(
            1,
            DB::table('plan_orders')->where('id', $orderId)->count(),
            'another tenant erased this merchant\'s subscription payment record: order id, price '
            . 'paid, payment status and the pointer to their uploaded bank slip. PlanOrder has no '
            . 'soft delete and no audit row, so there is nothing to restore it from.'
        );
    }

    public function test_a_permissionless_staff_member_cannot_delete_a_subscription_payment_record_either(): void
    {
        [$owner, $store] = $this->tenant();

        $staff = $this->makeMerchant();
        $staff->created_by = $owner->id;
        $staff->current_store = $store->id;
        $staff->type = 'staff';
        $staff->save();

        $orderId = $this->planOrderFor($store->id, $owner->id);

        $this->actingAs(User::find($staff->id))->delete('/bank_transfer/' . $orderId);

        $this->assertSame(
            1,
            DB::table('plan_orders')->where('id', $orderId)->count(),
            'a staff member holding no permission at all erased their own tenant\'s payment record'
        );
    }

    public function test_a_merchant_cannot_delete_another_tenants_menu_item_or_its_custom_link(): void
    {
        [$attacker] = $this->tenant();
        [, $victimStore] = $this->tenant();

        $menu = Menu::forceCreate(['name' => 'Victim main menu', 'store_id' => $victimStore->id]);

        $linkId = DB::table('custom_links')->insertGetId([
            'menu_id' => $menu->id, 'title' => 'Victim link', 'url' => 'https://victim.example',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemId = DB::table('menu_items')->insertGetId([
            'menu_id' => $menu->id,
            'menu_itemable_type' => 'App\\Models\\CustomLink',
            'menu_itemable_id' => $linkId,
            'order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($attacker)->delete('/menu/delete-item/' . $itemId);

        $this->assertSame(1, DB::table('menu_items')->where('id', $itemId)->count(),
            'another tenant deleted this store\'s menu item');

        /* THE CASCADE IS THE WORSE HALF. deleteItem() removes the CustomLink the item points at
           before removing the item, so a refused delete must leave BOTH rows. */
        $this->assertSame(1, DB::table('custom_links')->where('id', $linkId)->count(),
            'the cascade deleted another tenant\'s custom link');
    }

    /*
     * F-3 GETS ITS OWN CASE, and the reason is worth recording: the revert check caught that it
     * did not have one. Reverting MenuItemsController left every test GREEN, because the only
     * custom-link assertion above reaches that row as a CASCADE through
     * MenuController::deleteItem -- F-2's route, not F-3's. A fix nothing can redden is a fix
     * nobody will keep.
     */
    public function test_a_merchant_cannot_delete_another_tenants_custom_link_directly(): void
    {
        [$attacker] = $this->tenant();
        [, $victimStore] = $this->tenant();

        $this->grantPermission(User::find($attacker->id), 'Manage Menu');

        $menu = Menu::forceCreate(['name' => 'Victim menu', 'store_id' => $victimStore->id]);

        $linkId = DB::table('custom_links')->insertGetId([
            'menu_id' => $menu->id, 'title' => 'Victim link', 'url' => 'https://victim.example',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /* `delete-custom-link/{id}` (routes/web.php:974) -- the DIRECT door, with no menu item
           in between. The attacker is given `Manage Menu` on purpose: this case has to fail on
           OWNERSHIP, not on the permission, or it would pass for the wrong reason. */
        $this->actingAs(User::find($attacker->id))->delete('/delete-custom-link/' . $linkId);

        $this->assertSame(
            1,
            DB::table('custom_links')->where('id', $linkId)->count(),
            'a merchant holding Manage Menu deleted another tenant\'s custom link through the '
            . 'direct route. Manage Menu says they may manage THEIR menus, not anyone\'s.'
        );
    }

    public function test_a_merchant_can_still_delete_their_own_custom_link_directly(): void
    {
        [$merchant, $store] = $this->tenant();
        $this->grantPermission(User::find($merchant->id), 'Manage Menu');

        $menu = Menu::forceCreate(['name' => 'My menu', 'store_id' => $store->id]);

        $linkId = DB::table('custom_links')->insertGetId([
            'menu_id' => $menu->id, 'title' => 'My link', 'url' => 'https://mine.example',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($merchant->id))->delete('/delete-custom-link/' . $linkId);

        $this->assertSame(0, DB::table('custom_links')->where('id', $linkId)->count(),
            'a merchant was blocked from deleting a link in their OWN store\'s menu');
    }

    /* =============================================================================================
       THE OTHER HALF. Refusing everyone is not a fix, it is an outage, and this project has a
       standing instruction that security fixes must not block legitimate operations. Each case
       below drives the SAME route as a case above, as the principal who is supposed to be allowed,
       and asserts the row really goes.
       ============================================================================================= */

    /* =============================================================================================
       THESE TWO CASES WERE INVERTED ON 2026-11-11, AND THAT IS NOT A REGRESSION.
       =============================================================================================
       They used to assert that the record's own user, and the platform operator, COULD still
       delete a subscription payment record -- the legitimate-path half of the F-1 repair. The
       first of them closed with: "(Whether they SHOULD be able to is a separate audit question,
       recorded for the owner and not decided here.)"

       The owner decided it: *"Treat financial-record deletion as prohibited pending an approved
       retention and correction policy."* (2026-11-11.) So `BankTransferController::destroy()` now
       refuses EVERY caller, and these two cases assert the refusal instead. The method's own
       docblock carries the same instruction, and the delete button was removed from
       `resources/views/plans/action.blade.php` so that nobody re-admits a caller in order to fix
       an affordance.

       If you are here because these look like over-tightening: the fix is a correction mechanism
       -- a reversing entry, or a soft delete that writes an audit row -- under an approved
       retention policy. It is NOT re-admitting a principal to a hard delete that leaves no trace.
       ============================================================================================= */

    public function test_the_owner_of_a_subscription_payment_record_may_no_longer_delete_it(): void
    {
        [$owner, $store] = $this->tenant();

        $orderId = $this->planOrderFor($store->id, $owner->id);

        $this->actingAs($owner)->delete('/bank_transfer/' . $orderId);

        $this->assertSame(
            1,
            DB::table('plan_orders')->where('id', $orderId)->count(),
            'The record\'s OWN user deleted their subscription payment record. Financial-record '
            . 'deletion is prohibited pending an approved retention and correction policy (owner '
            . 'instruction 2026-11-11), and this is the principal the earlier repair deliberately '
            . 'left able to do it. Asserted through the raw table because PlanOrder is not in '
            . 'scoped_models and a plain Eloquent count answers against whichever tenant resolved.'
        );
    }

    public function test_not_even_the_platform_operator_may_delete_a_subscription_payment_record(): void
    {
        [$merchant, $store] = $this->tenant();

        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->save();

        $orderId = $this->planOrderFor($store->id, $merchant->id);

        $this->actingAs(User::find($operator->id))->delete('/bank_transfer/' . $orderId);

        $this->assertSame(
            1,
            DB::table('plan_orders')->where('id', $orderId)->count(),
            'The platform operator deleted a subscription payment record. The prohibition is not '
            . 'an authorization rule with an operator exemption -- it is a prohibition on the '
            . 'operation, so the operator is refused too. Re-admitting them here would be the '
            . 'first step back to a hard delete with no audit row.'
        );
    }

    /* =============================================================================================
       THE PROHIBITION HELD ON ONE ROUTE AND NOT ON THE PLATFORM -- found by independent review.
       =============================================================================================
       `BankTransferController::destroy()`'s docblock claimed the prohibition as a property of the
       OPERATION ("refuses EVERY caller", "the operator is refused too"). A review of that change
       showed the claim was false: two other paths hard-deleted `PlanOrder` rows, and the more
       reachable one was LIVE for a merchant.

       1. `Api\DashboardController::DeleteStore` ran `PlanOrder::where('store_id', $store->id)
          ->delete()`, routed `DELETE api/admin/deletestore` under `custom.auth` -- which the
          SHIPPED Flutter admin app calls with a `User` Sanctum token. Its own predicate
          (`created_by = $user->id`) is satisfied by a merchant deleting THEIR OWN store, so a
          merchant with more than one store erased their own payment history. The web twin,
          `StoreController::destroy`, has carried that exact line commented out with the exact
          rationale ("PlanOrder IS BILLING ... they outlive the store") -- the decision had simply
          been applied to one door.
       2. `BulkDeleteTenantGuard` returned `$next($request)` for an operator BEFORE its
          platform-global denial, so `POST /bulk-delete` with `type=plan-order` passed for a
          `super admin` although `plan-order` is in `PLATFORM_GLOBAL`.

       Both are closed, and these cases exist so the claim and the code cannot drift apart again.
       ============================================================================================= */

    public function test_deleting_a_store_through_the_admin_api_keeps_its_payment_records(): void
    {
        [$merchant, $store] = $this->tenant();

        // THE SECOND STORE IS LOAD-BEARING, and the first version of this test did not have it.
        // `DeleteStore` refuses with "You cannot delete your last store. Create another store
        // first." before it reaches any delete, so with one store the case passed with the bypass
        // fully restored -- a vacuous pass that proved nothing. Measured: status 200,
        // {"status":0,"message":"fail",...}. The exposure needs a merchant holding two or more
        // stores, which is also what makes it realistic.
        $this->makeStore($merchant);

        $orderId = $this->planOrderFor($store->id, $merchant->id);

        // The shipped Flutter admin app's own authentication shape: a `User` Sanctum token
        // through `custom.auth`, not a web session.
        $token = $merchant->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/admin/deletestore', ['store_id' => $store->id]);

        // PREMISE: the delete must actually have been carried out, or the assertion below is
        // measuring a refusal rather than a prohibition.
        $this->assertSame(
            0,
            DB::table('stores')->where('id', $store->id)->count(),
            'premise: DeleteStore did not delete the store, so this case is not exercising the '
            . 'path it claims. Response was: ' . $response->getContent()
        );

        $this->assertSame(
            1,
            DB::table('plan_orders')->where('id', $orderId)->count(),
            'Deleting a store through the admin API erased the merchant\'s subscription payment '
            . 'record. This was the LIVE bypass of the financial-record prohibition: the web twin '
            . 'StoreController::destroy already had this line commented out as billing data that '
            . 'outlives the store, and the API twin kept deleting. Re-commenting it is the fix; do '
            . 'not re-add the delete to make store removal feel tidier.'
        );
    }

    /**
     * A request the guard will actually inspect.
     *
     * `BulkDeleteTenantGuard::handle()` opens with
     * `if (! $this->isBulkDeleteRoute($request)) { return $next($request); }`, and
     * `isBulkDeleteRoute()` reads `$request->route()` -- the MATCHED route's name or action. A
     * bare `Request::create()` has no matched route, so the guard passed everything straight
     * through and the first version of these two cases proved nothing: the plan-order case failed
     * and the platform-administration case passed, both for the same irrelevant reason.
     *
     * So bind a route named exactly `BulkDeleteTenantGuard::ROUTE_NAME`. The vendored controller
     * is not loaded in tests -- which is why `POST /bulk-delete` is a 404 here and why these cases
     * drive the middleware directly -- so the action is a closure and the name is what matches.
     *
     * AND THE ACTOR MUST BE AUTHENTICATED THROUGH THE GUARD, NOT THROUGH THE REQUEST. `handle()`
     * reads `$user = auth()->user()` (:318) and returns `$next($request)` when it is null,
     * deliberately, so that a reordered stack yields a sign-in redirect rather than a 403. A
     * `setUserResolver()` on the request does not reach `auth()`, so an earlier version of these
     * two cases bailed at that null check: the plan-order case failed and the
     * platform-administration case passed, both because the guard never inspected anything.
     * `actingAs()` is what makes `auth()->user()` resolve.
     */
    private function bulkDeleteRequest(array $payload, User $actor): \Illuminate\Http\Request
    {
        $this->actingAs(User::find($actor->id));

        $request = \Illuminate\Http\Request::create('/bulk-delete', 'POST', $payload);

        $route = new \Illuminate\Routing\Route(['POST'], '/bulk-delete', ['uses' => fn () => null]);
        $route->name(\App\Http\Middleware\BulkDeleteTenantGuard::ROUTE_NAME);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    /**
     * Driven through the MIDDLEWARE, not over HTTP, and the reason matters.
     *
     * `POST /bulk-delete` answers **404** in any test run: the route belongs to a vendored WorkDo
     * addon, and CLAUDE.md records that no module loads unless the test inserts an
     * `add_on_managers` row. The first version of this case went over HTTP and passed with the
     * guard's financial check fully removed -- it was measuring a 404, not a refusal. Measured
     * directly: status 404.
     *
     * So the guard is invoked against a real request instead. That is deterministic, needs no
     * module loader, and tests the thing the fix changed: that a financial type is refused BEFORE
     * the operator bypass returns `$next()`.
     */
    public function test_the_operator_cannot_bulk_delete_payment_records_either(): void
    {
        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->save();

        $request = $this->bulkDeleteRequest(['type' => 'plan-order', 'ids' => [1, 2, 3]], $operator);

        $reached = false;
        $response = (new \App\Http\Middleware\BulkDeleteTenantGuard())->handle(
            $request,
            function () use (&$reached) {
                $reached = true;

                return response('reached the controller', 200);
            }
        );

        $this->assertFalse(
            $reached,
            'The guard passed a plan-order bulk delete through to the controller for an operator. '
            . '`plan-order` is in BulkDeleteTenantGuard::PLATFORM_GLOBAL, which refuses a MERCHANT '
            . '-- but the operator branch returns $next() BEFORE that check runs, so the '
            . 'financial-record refusal has to sit ahead of the bypass. It narrows exactly that '
            . "one type and leaves the operator's reach over countries, states, cities and "
            . 'newsletters untouched.'
        );

        $this->assertStringContainsString(
            'not permitted',
            (string) $response->getContent(),
            'the refusal should name the prohibition rather than read as a generic permission error'
        );
    }

    /**
     * The other half of the same guard, so the change above cannot be "fixed" by refusing
     * everything. An operator must still reach the platform-administration types that an earlier
     * owner decision deliberately left them.
     */
    public function test_the_operator_still_reaches_other_platform_administration_types(): void
    {
        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->save();

        foreach (['country', 'state', 'city', 'newsletter'] as $type) {
            $request = $this->bulkDeleteRequest(['type' => $type, 'ids' => [1]], $operator);

            $reached = false;
            (new \App\Http\Middleware\BulkDeleteTenantGuard())->handle(
                $request,
                function () use (&$reached) {
                    $reached = true;

                    return response('ok', 200);
                }
            );

            $this->assertTrue(
                $reached,
                "The operator was refused bulk type '{$type}'. The financial-record check added "
                . 'ahead of the operator bypass must cover ONLY the types in FINANCIAL_RECORDS; '
                . 'refusing platform administration generally would contradict the earlier owner '
                . 'decision that the operator keeps those, and would be a behaviour change nobody '
                . 'asked for.'
            );
        }
    }

    public function test_a_merchant_holding_manage_menu_can_still_delete_their_own_menu_item(): void
    {
        [$merchant, $store] = $this->tenant();
        $this->grantPermission(User::find($merchant->id), 'Manage Menu');

        $menu = Menu::forceCreate(['name' => 'My menu', 'store_id' => $store->id]);

        $linkId = DB::table('custom_links')->insertGetId([
            'menu_id' => $menu->id, 'title' => 'My link', 'url' => 'https://mine.example',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('menu_items')->insertGetId([
            'menu_id' => $menu->id,
            'menu_itemable_type' => 'App\\Models\\CustomLink',
            'menu_itemable_id' => $linkId,
            'order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($merchant->id))->delete('/menu/delete-item/' . $itemId);

        $this->assertSame(0, DB::table('menu_items')->where('id', $itemId)->count(),
            'a merchant was blocked from deleting an item in their OWN store\'s menu');
        $this->assertSame(0, DB::table('custom_links')->where('id', $linkId)->count(),
            'the legitimate cascade no longer removes the link the item pointed at');
    }

    public function test_the_platform_operator_can_still_delete_a_subscription_plan(): void
    {
        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->save();

        $planId = (int) DB::table('plans')->insertGetId([
            'name' => 'Retired tier', 'price' => 99.00, 'duration' => 'Monthly', 'themes' => 'theme-1',
            'max_stores' => 1, 'max_products' => 10, 'max_users' => 1, 'storage_limit' => 128,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($operator->id))->delete('/plan/' . $planId);

        $this->assertSame(0, Plan::withoutGlobalScopes()->where('id', $planId)->count(),
            'the operator can no longer retire a subscription tier -- platform administration is '
            . 'exactly whose job this is');
    }

    public function test_an_ordinary_merchant_cannot_delete_a_platform_subscription_plan(): void
    {
        [$merchant] = $this->tenant();

        $planId = (int) DB::table('plans')->insertGetId([
            'name' => 'Growth', 'price' => 499.00, 'duration' => 'Monthly', 'themes' => 'theme-1',
            'max_stores' => 3, 'max_products' => 100, 'max_users' => 10, 'storage_limit' => 1024,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($merchant)->delete('/plan/' . $planId);

        $this->assertSame(
            1,
            Plan::withoutGlobalScopes()->where('id', $planId)->count(),
            'an ordinary merchant deleted a PLATFORM subscription plan. The merchant role is '
            . 'granted Manage Plan and never Create or Edit, so destroy outranked the view-only '
            . 'grant it was paired with -- privilege escalation, not only cross-tenant reach.'
        );
    }
}
