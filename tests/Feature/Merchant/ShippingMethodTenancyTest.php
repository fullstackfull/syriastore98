<?php

namespace Tests\Feature\Merchant;

use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F258 — `ShippingMethodController` had no authorization at all, and its writes stole rows.
 * =================================================================================================
 *
 * MEASURED BEFORE ANYTHING WAS CHANGED: `grep -c 'isAbleTo\|authorize(\|permission'` over all 155
 * lines of the controller returned **0**. Six handlers are reachable and every one resolved its
 * row with a bare `ShippingMethod::find($id)`.
 *
 * `ShippingMethod` is absent from `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds no
 * predicate for any principal — the handler's own `where` is the only control there has ever been,
 * and there was none.
 *
 * THE WRITES DID NOT MERELY EDIT ANOTHER TENANT'S ROW — THEY TOOK IT. All three ended with
 * `$shipping->store_id = getCurrentStore();`, so posting another store's id rewrote that store's
 * shipping cost AND moved the row into the caller's store. The victim's shipping method vanished
 * from their own settings. That is the assertion this file exists for, and it is checked
 * separately from the cost, because a fix that stopped the cost changing while still moving the
 * row would be a different defect wearing the same green tick.
 *
 * EVERY ASSERTION ABOUT THE VICTIM'S ROW GOES THROUGH `withoutGlobalScopes()`. Not ceremony: a
 * plain `ShippingMethod::find()` in a test answers against whichever tenant the test happens to
 * have resolved, so an "it is unchanged" assertion can pass whether or not the fix works. This
 * repository has already paid for that once — a cart test reported a store-B row deleted while the
 * raw table still held it.
 *
 * AND THE MODEL IS `Cachable`. `(new ShippingMethod)->flushCache()` before every read-back, because
 * under `CACHE_STORE=array` a pre-write row survives for the rest of the process and has produced
 * three measured false readings in this project.
 */
class ShippingMethodTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchantA;

    private Store $storeA;

    private User $merchantB;

    private Store $storeB;

    private ShippingMethod $methodB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchantA, $this->storeA] = $this->tenant();
        [$this->merchantB, $this->storeB] = $this->tenant();

        $this->methodB = $this->makeMethod($this->storeB, 'Store B courier', '25.00');
    }

    /**
     * `shipping_methods.zone_id` carries a real foreign key to `shipping_zones`, which in turn
     * has foreign keys to `countries` and `states`. Neither is seeded in this suite, so the
     * fixture creates the rows it depends on rather than assuming ids 1 and 1 exist.
     */
    private function makeMethod(Store $store, string $name, string $cost): ShippingMethod
    {
        $countryId = (int) \DB::table('countries')->insertGetId([
            'name' => 'Testland ' . uniqid(),
        ]);
        $stateId = (int) \DB::table('states')->insertGetId([
            'name' => 'Teststate ' . uniqid(),
            'country_id' => $countryId,
        ]);

        $zone = ShippingZone::create([
            'zone_name' => $name . ' zone',
            'country_id' => $countryId,
            'state_id' => $stateId,
            'shipping_method' => '1',
            'store_id' => $store->id,
        ]);

        $method = ShippingMethod::create([
            'method_name' => $name,
            'zone_id' => $zone->id,
            'cost' => $cost,
            'calculation_type' => 'fixed',
            'shipping_requires' => '1',
            'store_id' => $store->id,
        ]);
        (new ShippingMethod)->flushCache();

        return $method;
    }

    /** @return array{0: User, 1: Store} */
    private function tenant(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->grantPermission(User::find($merchant->id), 'Edit Shipping Method');

        return [User::find($merchant->id), $store];
    }

    /**
     * Drives a request and fails the test if it never reached the handler.
     *
     * THE WHOLE FILE NEEDED THIS. `shipping-method.update` comes from `Route::resource`, so it is
     * PUT|PATCH — and the first version of these tests POSTed to it. A POST matches no route, so
     * every "store B's cost is unchanged" assertion passed while the request was being rejected
     * by the router. The gate under test was never executed once. The allow-path test is what
     * exposed it, which is the entire reason this file has one.
     *
     * 404 is accepted alongside 2xx/3xx because a correctly-gated request for another tenant's
     * row may legitimately answer 404; 405 and 500 are not.
     */
    private function assertReachedHandler(\Illuminate\Testing\TestResponse $response, string $what): void
    {
        $this->assertNotSame(405, $response->getStatusCode(),
            "{$what}: the router rejected the verb, so the handler never ran and whatever this "
            . 'test asserts afterwards is vacuous');
        $this->assertNotSame(500, $response->getStatusCode(),
            "{$what}: the handler raised a 500");
    }

    /** Store B's row as the DATABASE holds it, with no scope in the way. */
    private function victim(): ShippingMethod
    {
        (new ShippingMethod)->flushCache();

        return ShippingMethod::withoutGlobalScopes()->findOrFail($this->methodB->id);
    }

    // ---------------------------------------------------------------------------------------
    //  THE CROSS-TENANT WRITE
    // ---------------------------------------------------------------------------------------

    public function test_another_tenant_cannot_rewrite_this_stores_shipping_cost(): void
    {
        $response = $this->actingAs($this->merchantA)
            ->put(route('shipping-method.update', $this->methodB->id), [
                'cost' => '0.01',
                'calculation_type' => 'fixed',
            ]);
        $this->assertReachedHandler($response, 'cross-tenant update');

        $this->assertSame('25.00', (string) $this->victim()->cost,
            "store A rewrote store B's shipping cost");
    }

    /**
     * THE SECOND HALF, AND THE WORSE ONE. `$shipping->store_id = getCurrentStore()` ran on every
     * save, so the row did not merely get edited — it changed owner.
     */
    public function test_another_tenant_cannot_move_this_stores_shipping_method_into_their_own(): void
    {
        $response = $this->actingAs($this->merchantA)
            ->put(route('shipping-method.update', $this->methodB->id), [
                'cost' => '0.01',
                'calculation_type' => 'fixed',
            ]);
        $this->assertReachedHandler($response, 'cross-tenant store_id move');

        $this->assertSame((int) $this->storeB->id, (int) $this->victim()->store_id,
            "store B's shipping method was moved into store A, so it disappeared from store B's "
            . 'own settings');
    }

    public function test_another_tenant_cannot_rewrite_free_shipping(): void
    {
        $response = $this->actingAs($this->merchantA)
            ->post(route('free-shipping.update', $this->methodB->id), [
                'shipping_requires' => '3',
                'cost' => '0.01',
            ]);
        $this->assertReachedHandler($response, 'cross-tenant free shipping');

        $victim = $this->victim();
        $this->assertSame('1', (string) $victim->shipping_requires);
        $this->assertSame((int) $this->storeB->id, (int) $victim->store_id);
    }

    public function test_another_tenant_cannot_rewrite_local_shipping(): void
    {
        $response = $this->actingAs($this->merchantA)
            ->post(route('local-shipping.update', $this->methodB->id), [
                'cost' => '0.01',
            ]);
        $this->assertReachedHandler($response, 'cross-tenant local shipping');

        $victim = $this->victim();
        $this->assertSame('25.00', (string) $victim->cost);
        $this->assertSame((int) $this->storeB->id, (int) $victim->store_id);
    }

    // ---------------------------------------------------------------------------------------
    //  THE CROSS-TENANT READ
    // ---------------------------------------------------------------------------------------

    public function test_another_tenants_shipping_method_is_not_readable(): void
    {
        foreach ([
            'shipping-method.edit',
            'free-shipping.edit',
            'local-shipping.edit',
        ] as $route) {
            $response = $this->actingAs($this->merchantA)->get(route($route, $this->methodB->id));

            $this->assertStringNotContainsString('Store B courier',
                (string) $response->getContent(),
                "{$route} rendered another tenant's shipping method");
        }
    }

    // ---------------------------------------------------------------------------------------
    //  THE PERMISSION, WHICH DID NOT EXIST AT ALL
    // ---------------------------------------------------------------------------------------

    public function test_a_staff_member_without_the_permission_cannot_write_their_own_stores_method(): void
    {
        $ownMethod = $this->makeMethod($this->storeA, 'Store A courier', '10.00');

        $staff = $this->makeStaff($this->merchantA, ['current_store' => $this->storeA->id]);
        $this->grantPermission(User::find($staff->id), 'Manage Blog');

        $response = $this->actingAs(User::find($staff->id))
            ->put(route('shipping-method.update', $ownMethod->id), [
                'cost' => '0.01',
                'calculation_type' => 'fixed',
            ]);
        $this->assertReachedHandler($response, 'unpermissioned staff');

        (new ShippingMethod)->flushCache();
        $this->assertSame('10.00',
            (string) ShippingMethod::withoutGlobalScopes()->findOrFail($ownMethod->id)->cost,
            'a staff member whose role grants only Manage Blog edited the store\'s shipping prices');
    }

    /**
     * THE OPERATOR IS REFUSED, AND THAT IS A CHOICE, NOT AN ACCIDENT.
     *
     * A bare `isAbleTo()` refuses the operator as a side effect — Laratrust has no super-admin
     * bypass — which is the right answer for the wrong reason. It is refused explicitly here, by
     * analogy with the coupon decision (F157, owner decision 2026-10-28: the operator reads and
     * exports, and does not rewrite a merchant's commercial settings). No owner decision names
     * shipping, so this test is the thing to change if the owner decides otherwise.
     */
    public function test_the_platform_operator_is_refused_a_shipping_write_explicitly(): void
    {
        $operator = $this->makeMerchant(['type' => 'super admin']);
        $operator->current_store = $this->storeB->id;
        $operator->save();

        /* THE GRANT IS WHAT MAKES THIS TEST MEAN ANYTHING, and its first version did not have it.
           Found by an independent review, and it is the exact failure this file exists to guard
           against elsewhere: without the permission the operator is refused by the `isAbleTo()`
           limb anyway, so deleting the operator limb entirely changed nothing the test could see.
           It asserted a decision it never exercised. With the grant, removing the operator limb
           lets the write through and this goes red. */
        $this->grantPermission(User::find($operator->id), 'Edit Shipping Method');
        (new User)->flushCache();

        $response = $this->actingAs(User::find($operator->id))
            ->put(route('shipping-method.update', $this->methodB->id), [
                'cost' => '0.01',
                'calculation_type' => 'fixed',
            ]);
        $this->assertReachedHandler($response, 'operator write');

        $this->assertSame('25.00', (string) $this->victim()->cost);
    }

    /**
     * AND THE OPERATOR MAY STILL READ, which is the other half of the same decision.
     *
     * The first version of this change used the WRITE refusal for the three edit-form reads too,
     * so the operator lost a read they had before the change — an unstated second decision riding
     * on a security fix. The coupon precedent this follows splits them: `couponReadRefusal`
     * admits the operator, `refuseOperatorWrite` refuses them.
     */
    public function test_the_platform_operator_may_still_open_a_shipping_form(): void
    {
        $operator = $this->makeMerchant(['type' => 'super admin']);
        $operator->current_store = $this->storeB->id;
        $operator->save();
        (new User)->flushCache();

        $response = $this->actingAs(User::find($operator->id))
            ->get(route('local-shipping.edit', $this->methodB->id));

        $this->assertSame(200, $response->getStatusCode(),
            'the operator can no longer open a shipping form they could open before this change');
    }

    // ---------------------------------------------------------------------------------------
    //  AND THE GATE IS NOT A DELETION
    // ---------------------------------------------------------------------------------------

    /**
     * Every test above passes against a controller that refuses EVERYTHING. This one fails against
     * it, which is what makes the others mean something.
     */
    public function test_a_permitted_merchant_still_edits_their_own_shipping_method(): void
    {
        $ownMethod = $this->makeMethod($this->storeA, 'Store A courier', '10.00');

        $response = $this->actingAs($this->merchantA)
            ->put(route('shipping-method.update', $ownMethod->id), [
                'cost' => '12.50',
                'calculation_type' => 'fixed',
            ]);
        $this->assertReachedHandler($response, 'permitted own-store update');

        (new ShippingMethod)->flushCache();
        $saved = ShippingMethod::withoutGlobalScopes()->findOrFail($ownMethod->id);

        $this->assertSame('12.50', (string) $saved->cost,
            'the gate refuses the merchant their own shipping method, so it is a deletion rather '
            . 'than a control');
        $this->assertSame((int) $this->storeA->id, (int) $saved->store_id);
    }

    public function test_a_permitted_merchant_still_opens_their_own_edit_form(): void
    {
        $ownMethod = $this->makeMethod($this->storeA, 'Store A courier', '10.00');

        $response = $this->actingAs($this->merchantA)
            ->get(route('local-shipping.edit', $ownMethod->id));

        $response->assertStatus(200);
    }

    // ---------------------------------------------------------------------------------------
    //  F261 — THE SIBLING RESOURCE, WHERE THE SAME DEFECT WAS WORSE
    // ---------------------------------------------------------------------------------------
    //
    // `ShippingZoneController` carried the identical shape one route away, and the F258 work
    // would have left it: `destroy()` deleted another tenant's zone AND one of their
    // `shipping_methods` rows on nothing more than holding `Delete Shipping Zone`, and `update()`
    // carried the same `store_id = getCurrentStore()` theft. Found by an independent review of
    // the F258 change.

    /** The zone store B's method hangs from, by the id the fixture really created. */
    private function victimZoneId(): int
    {
        return (int) ShippingMethod::withoutGlobalScopes()
            ->findOrFail($this->methodB->id)->zone_id;
    }

    public function test_another_tenant_cannot_delete_this_stores_shipping_zone(): void
    {
        $zoneId = $this->victimZoneId();
        $this->grantPermission(User::find($this->merchantA->id), 'Delete Shipping Zone');

        $response = $this->actingAs(User::find($this->merchantA->id))
            ->delete(route('shipping-zone.destroy', $zoneId));
        $this->assertReachedHandler($response, 'cross-tenant zone delete');

        $this->assertNotNull(
            \App\Models\ShippingZone::withoutGlobalScopes()->find($zoneId),
            "store B's shipping zone was deleted by store A"
        );
        $this->assertNotNull(
            ShippingMethod::withoutGlobalScopes()->find($this->methodB->id),
            "store B's shipping method was deleted along with their zone"
        );
    }

    public function test_another_tenant_cannot_take_over_this_stores_shipping_zone(): void
    {
        $zoneId = $this->victimZoneId();
        $this->grantPermission(User::find($this->merchantA->id), 'Edit Shipping Zone');

        $response = $this->actingAs(User::find($this->merchantA->id))
            ->put(route('shipping-zone.update', $zoneId), [
                'zone_name' => 'Taken',
                'country_id' => 1,
                'state_id' => 1,
                'shipping_method' => ['Stolen method'],
            ]);
        $this->assertReachedHandler($response, 'cross-tenant zone update');

        $zone = \App\Models\ShippingZone::withoutGlobalScopes()->findOrFail($zoneId);
        $this->assertSame((int) $this->storeB->id, (int) $zone->store_id,
            "store B's shipping zone was moved into store A");
        $this->assertNotSame('Taken', (string) $zone->zone_name);
    }

    public function test_another_tenants_shipping_zone_is_not_readable(): void
    {
        $zoneId = $this->victimZoneId();
        $this->grantPermission(User::find($this->merchantA->id), 'Edit Shipping Zone');

        $response = $this->actingAs(User::find($this->merchantA->id))
            ->get(route('shipping-zone.edit', $zoneId));

        $this->assertNotSame(500, $response->getStatusCode(),
            'the edit form raised a 500 rather than refusing');
        $this->assertStringNotContainsString('Store B courier zone',
            (string) $response->getContent(),
            "store B's zone name was rendered on store A's edit form");
    }

    /**
     * A MISSING ROW IS A REFUSAL, NOT A 500. The handlers used to call `->cost = …` straight on
     * the result of `find()`, so an id that does not exist was a fatal on null.
     */
    public function test_an_unknown_id_is_refused_rather_than_raising_a_500(): void
    {
        $response = $this->actingAs($this->merchantA)
            ->put(route('shipping-method.update', 999999), [
                'cost' => '1.00',
                'calculation_type' => 'fixed',
            ]);

        $this->assertNotSame(500, $response->getStatusCode());
    }
}
