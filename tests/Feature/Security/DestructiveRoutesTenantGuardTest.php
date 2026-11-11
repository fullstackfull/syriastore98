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

    public function test_the_owner_of_a_subscription_payment_record_can_still_delete_it(): void
    {
        [$owner, $store] = $this->tenant();

        $orderId = $this->planOrderFor($store->id, $owner->id);

        $this->actingAs($owner)->delete('/bank_transfer/' . $orderId);

        $this->assertSame(
            0,
            DB::table('plan_orders')->where('id', $orderId)->count(),
            'the gate refuses the record\'s OWN user. The list shows a non-operator exactly the '
            . 'rows where users.id = their id, so this is the one principal who could always '
            . 'reach it -- blocking them would be an outage, not a fix. (Whether they SHOULD be '
            . 'able to is a separate audit question, recorded for the owner and not decided here.)'
        );
    }

    public function test_the_platform_operator_can_still_delete_any_subscription_payment_record(): void
    {
        [$merchant, $store] = $this->tenant();

        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->save();

        $orderId = $this->planOrderFor($store->id, $merchant->id);

        $this->actingAs(User::find($operator->id))->delete('/bank_transfer/' . $orderId);

        $this->assertSame(0, DB::table('plan_orders')->where('id', $orderId)->count(),
            'the operator was refused a record they are supposed to administer');
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
