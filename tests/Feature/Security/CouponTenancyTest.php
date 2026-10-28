<?php

namespace Tests\Feature\Security;

use App\Models\Coupon;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * CROSS-TENANT COUPON ACCESS on the merchant web surface — one of them destructive.
 * =================================================================================================
 *
 * `App\Models\Coupon` uses `HasFactory, Cachable` and NOTHING ELSE (app/Models/Coupon.php:11). No
 * `BelongsToStore`, and it is not in `config/tenancy.php`'s `scoped_models` — so it adds **no**
 * global predicate for any principal, and the open-tenant rule does not even come into it.
 * `CouponController`'s handlers take a route-model-bound `Coupon $coupon` resolved from a bare id
 * and compare it with nothing.
 *
 * THE CODEBASE ALREADY KNOWS THIS SHAPE. `ImportMappingOwnershipTest:23` records F1 as
 * "`WoocomCouponController::edit` ... never compared the coupon with the caller's store (Coupon
 * carries no [scope])" — the same defect, found and fixed in the WOOCOMMERCE import controller and
 * left standing in the main one.
 *
 * MEASURED, not inferred. Driven with a store-A merchant holding the coupon permissions against a
 * store-B coupon:
 *
 *   GET  coupon/{id}/edit  -> 200          another store's coupon code and discount terms
 *   GET  coupon/{id}       -> 200          another store's coupon detail
 *   DELETE coupon/{id}     -> 302, ROW GONE   a store-A merchant deleted a store-B coupon
 *
 * The delete is the one that matters most: a destructive cross-tenant write, confirmed against the
 * database rather than against a status code.
 *
 * WHAT IS *NOT* CLAIMED HERE, because driving it did not reproduce it: `PUT coupon/{id}` answered
 * 302 and left the row untouched (`coupon_name` and `discount_amount` unchanged), so the write path
 * is refused or rejected somewhere for the payload used. It is covered below so that the behaviour
 * is pinned either way, and the test asserts the row is unchanged rather than asserting a status —
 * if a future payload does reach the update, this fails.
 *
 * A STAFF MEMBER WITH NO PERMISSION got 404 on the edit form, not 200 — so this is a
 * TENANT-BOUNDARY defect between merchants, not an "any authenticated user" one. An independent
 * sweep claimed the wider shape; driving it narrowed the claim, which is why it is driven.
 */
class CouponTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['Manage Coupon', 'Show Coupon', 'Create Coupon', 'Edit Coupon', 'Delete Coupon'];

    /** @return array{0: User, 1: Store, 2: Coupon} */
    private function twoTenantsAndAVictimCoupon(): array
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        // forceCreate: Coupon's non-empty $fillable omits store_id, so create() would drop it and
        // the "victim" would land in no store at all -- the trap SecurityTestCase::makeRole()
        // documents, and one this suite cannot afford, because a null store_id would make every
        // assertion below pass for the wrong reason.
        $victim = Coupon::forceCreate([
            'coupon_name' => 'VICTIM',
            'coupon_code' => 'VICTIM' . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 5,
            'coupon_limit' => 10,
            'store_id' => $storeB->id,
        ]);

        $this->assertSame((int) $storeB->id, (int) $victim->fresh()->store_id,
            'fixture: the victim coupon must actually carry the victim store_id');

        foreach (self::PERMISSIONS as $permission) {
            $this->grantPermission(User::find($merchantA->id), $permission);
        }

        return [User::find($merchantA->id), $storeA, $victim];
    }

    public function test_a_merchant_cannot_open_another_stores_coupon_for_editing(): void
    {
        [$merchantA, , $victim] = $this->twoTenantsAndAVictimCoupon();

        $response = $this->actingAs($merchantA)->get('coupon/' . $victim->id . '/edit');

        $this->assertNotSame(200, $response->getStatusCode(),
            "a merchant opened another store's coupon for editing, reading its code and discount "
            . 'terms');
    }

    public function test_a_merchant_cannot_view_another_stores_coupon(): void
    {
        [$merchantA, , $victim] = $this->twoTenantsAndAVictimCoupon();

        $response = $this->actingAs($merchantA)->get('coupon/' . $victim->id);

        $this->assertNotSame(200, $response->getStatusCode(),
            "a merchant viewed another store's coupon detail");
    }

    /**
     * THE DESTRUCTIVE ONE, asserted on the DATABASE. A status code cannot distinguish "refused"
     * from "deleted and redirected" here -- both are 302.
     */
    public function test_a_merchant_cannot_delete_another_stores_coupon(): void
    {
        [$merchantA, , $victim] = $this->twoTenantsAndAVictimCoupon();

        $this->actingAs($merchantA)->delete('coupon/' . $victim->id);

        $this->assertNotNull(Coupon::withoutGlobalScopes()->find($victim->id),
            "a merchant DELETED another store's coupon. Asserted through withoutGlobalScopes() "
            . 'because Coupon adds no global predicate, so a plain find() answers against '
            . 'whichever tenant happens to be resolved rather than against the table.');
    }

    /** And the write path, pinned on the row rather than on a status. */
    public function test_a_merchant_cannot_rewrite_another_stores_coupon(): void
    {
        [$merchantA, , $victim] = $this->twoTenantsAndAVictimCoupon();

        $this->actingAs($merchantA)->put('coupon/' . $victim->id, [
            'coupon_name' => 'HIJACKED',
            'coupon_code' => 'HIJACKED' . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 999,
            'coupon_limit' => 1,
        ]);

        $after = Coupon::withoutGlobalScopes()->find($victim->id);

        $this->assertSame('VICTIM', (string) $after->coupon_name,
            "a merchant rewrote another store's coupon name");
        $this->assertSame(5.0, (float) $after->discount_amount,
            "a merchant rewrote another store's discount amount");
    }

    /**
     * THE COUNTERPART, and the reason the four above are not satisfied by refusing everyone: the
     * owning merchant must still manage their own coupons.
     */
    public function test_the_owning_merchant_still_manages_their_own_coupon(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        foreach (self::PERMISSIONS as $permission) {
            $this->grantPermission(User::find($merchantA->id), $permission);
        }

        $merchantA = User::find($merchantA->id);

        $own = Coupon::forceCreate([
            'coupon_name' => 'MINE',
            'coupon_code' => 'MINE' . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 5,
            'coupon_limit' => 10,
            'store_id' => $storeA->id,
        ]);

        $this->assertSame(200, $this->actingAs($merchantA)->get('coupon/' . $own->id . '/edit')->getStatusCode(),
            'the owning merchant was refused their own coupon');

        $this->actingAs($merchantA)->delete('coupon/' . $own->id);

        $this->assertNull(Coupon::withoutGlobalScopes()->find($own->id),
            'the owning merchant could not delete their own coupon');
    }
}
