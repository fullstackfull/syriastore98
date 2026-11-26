<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-142 / P4-F113 (write half) — `DELETE api/admin/destroyCustomer` HAD NO PERMISSION CHECK.
 * =================================================================================================
 *
 * `DashboardController::destroy()` (`:6830`) resolves TENANCY — `resolveOwnedStore()` on the
 * query-string `store_id`, then a `store_id` predicate on the customer lookup — and then asks
 * nothing about who inside the store may act. `$targetCustomer->delete()` at `:6887` followed for
 * any authenticated member of the tenant holding nothing at all.
 *
 * **THE ASYMMETRY IS THE WHOLE POINT, AND IT IS NOT HYPOTHETICAL.** The CREATE side of this very
 * resource — `addCustomer()`, `POST api/admin/AddCustomer` — IS gated, on
 * `MerchantDashboardAbilities::PERMISSION_CUSTOMERS` (`:9378-9388`), with its own pinned suite
 * (`AdminApiCustomerCreatePermissionTest`). So until this change one staff member could DELETE a
 * customer they could not CREATE, through two routes nine lines of config apart.
 * `test_the_asymmetry_with_the_create_side_is_gone` drives both halves with one principal and is
 * the case that would have caught it.
 *
 * `customers` is the highest-value PII table that is not storefront-public — name, email, mobile,
 * date of birth, every delivery address hanging off it — and `Customer` soft-deletes, so the row
 * survives the delete but every scoped read stops seeing it. A merchant's customer list simply
 * loses people.
 *
 * -------------------------------------------------------------------------------------------------
 * THE NAME IS `Manage Customer`, WHICH IS WHAT BOTH TWINS ALREADY NAME.
 *
 * `CustomerController::destroy()` — the web twin of this exact action — gates on
 * `Manage Customer` (`:289`, operator-first), and the API's own CREATE side names the same string
 * through `MerchantDashboardAbilities::PERMISSION_CUSTOMERS` (`= 'Manage Customer'`). The constant
 * is used rather than the literal so the two API halves cannot drift apart.
 *
 * `Show Customer` IS DELIBERATELY NOT ACCEPTED. It is seeded, and `CustomerController::show()`
 * accepts it for a READ — but this is a delete, the web delete does not accept it, and the API
 * create does not either. Accepting it would let a staff member granted a read-only customer right
 * destroy PII.
 *
 * -------------------------------------------------------------------------------------------------
 * THE REFUSAL SHAPE IS THE CREATE SIDE'S, NOT `orderPermissionDenied()`, AND THAT IS DELIBERATE.
 *
 * This handler speaks a different envelope from the order routes: every refusal in it is
 * `{'status' => 0, 'message' => 'fail', 'data' => ['message' => ...]}` with a real HTTP code, and
 * its ownership refusal 20 lines above the new gate is already a 403 in exactly that shape. The
 * CREATE side's permission refusal is the same shape again. Matching it does two things an order-
 * shaped refusal would not: the shipped app keeps parsing one envelope per route, and a caller
 * cannot tell "I do not own that store" from "I lack the right" — no new existence oracle, which is
 * the property `addCustomer` was explicitly repaired for.
 *
 * 403 AND NEVER 401: the shipped Flutter app force-clears its session on any 401.
 *
 * -------------------------------------------------------------------------------------------------
 * THE OWNER LIMB, AND THE ONE ASYMMETRY THAT REMAINS — RECORDED, NOT HIDDEN.
 *
 * The gate is two-limbed: the store's own owner, OR `Manage Customer` in THIS store. The CREATE
 * side is one-limbed, so a store owner holding no role at all can now delete a customer they could
 * not create. That is the reverse of the asymmetry this row closes, and it is smaller: it bites
 * only a merchant whose shared global `admin` role is absent, which in production it is not —
 * `Utility.php` grants `Manage Customer` to that role and every self-registered merchant holds it,
 * which is why the create side's own owner test calls `grantMerchantGlobalRole()`.
 *
 * The limb is kept because refusing a store's own owner is the regression this family has already
 * produced once (CLAUDE.md records it), there is no security gain — the owner is the principal who
 * grants the permissions in their own tenant — and widening `addCustomer` instead would change a
 * handler this row does not own and whose behaviour is pinned by another suite. BOTH owner shapes
 * are asserted below, so neither reading is left to inference.
 */
class AdminApiCustomerDeletePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PII_EMAIL = 'pii-shopper-gap142@example.test';

    /** @return array{0:User,1:Store,2:Customer} */
    private function tenantWithACustomer(): array
    {
        $this->assertTrue(Schema::hasColumn('customers', 'deleted_at'),
            'customers.deleted_at is absent, so Customer::delete() is a HARD delete here and the '
            . '"the row survived" assertions below are measuring something else');

        [$merchant, $store] = $this->makeTenant();

        $customer = Customer::create([
            'first_name' => 'Gap142',
            'last_name' => 'Shopper',
            'email' => self::PII_EMAIL,
            'password' => bcrypt('not-a-real-credential'),
            'mobile' => '555000999',
            'status' => 1,
            'store_id' => $store->id,
        ]);

        return [User::find($merchant->id), $store, $customer];
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

    private function as(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        );
    }

    private function destroyCustomer(User $user, Store $store, Customer $customer)
    {
        /* The handler reads BOTH ids from `$request->query()`, and validates against `query()` too,
           so a JSON body would never reach the gate. */
        return $this->as($user)->deleteJson(
            '/api/admin/destroyCustomer?customer_id=' . $customer->id . '&store_id=' . $store->id
        );
    }

    private function createCustomer(User $user, Store $store, string $email)
    {
        return $this->as($user)->postJson('/api/admin/AddCustomer?store_id=' . $store->id, [
            'first_name' => 'Created',
            'last_name' => 'ByStaff',
            'email' => $email,
            'mobile' => '555000778',
            // The endpoint's own regex is /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,}$/.
            'password' => 'Passw0rdLong',
            'status' => '1',
        ]);
    }

    /**
     * Is the customer still live?
     *
     * `withoutGlobalScopes()`, AND IT IS LOAD-BEARING TWICE OVER. `Customer` is one of the ELEVEN
     * models that genuinely filter — it is in `config/tenancy.php`'s `scoped_models` AND carries
     * `SoftDeletingScope` — so a plain `Customer::find()` answers against whichever tenant happens
     * to be resolved and hides a soft-deleted row either way. An "it is gone" or "it survived"
     * assertion through a scoped read passes whether or not the fix works; CLAUDE.md records that
     * costing a real false reading on a sibling table.
     */
    private function isLive(int $customerId): bool
    {
        $row = Customer::withoutGlobalScopes()->find($customerId);

        return $row !== null && $row->deleted_at === null;
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE PREMISE

    /** The fixture holds nothing, so a refusal below is the gate and not an accident of the fixture. */
    public function test_premise_the_staff_member_holds_no_customer_permission(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();
        $staff = $this->staffIn($merchant, $store);

        $this->assertFalse($staff->isAbleTo('Manage Customer'),
            'the fixture staff member holds Manage Customer, so nothing below measures a gap');
        $this->assertTrue($this->isLive((int) $customer->id),
            'the fixture customer is not live to begin with, so "it survived" is vacuous');

        /* AND THE DELETE REACHES THE ROW FOR A PERMITTED CALLER, or every refusal assertion below
           could be passing against a handler that deletes nothing. */
        $this->assertSame(200, $this->destroyCustomer($merchant, $store, $customer)->status());
        $this->assertFalse($this->isLive((int) $customer->id),
            'a legitimate deletion did not remove the customer, so this fixture never reaches the '
            . 'write the gate is supposed to protect');
    }

    // ════════════════════════════════════════════════════════════════════════════════ THE DEFECT

    public function test_a_staff_member_without_manage_customer_cannot_delete_a_customer(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->destroyCustomer($staff, $store, $customer);

        $this->assertSame(403, $response->status(),
            'a staff member holding no customer permission deleted a customer through the admin '
            . 'API, while the CREATE side of the same resource refused them. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertTrue($this->isLive((int) $customer->id),
            'the refused call still deleted the customer. Customer soft-deletes, so the PII row '
            . 'survives but every scoped read stops seeing it -- the merchant\'s customer list '
            . 'simply loses the person.');
    }

    /**
     * THE CASE THAT NAMES THE DEFECT: one principal, both halves of the resource, same answer.
     *
     * Before the gate this test failed on its second assertion — 403 on create, 200 on delete.
     */
    public function test_the_asymmetry_with_the_create_side_is_gone(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();
        $staff = $this->staffIn($merchant, $store);

        $creation = $this->createCustomer($staff, $store, 'asymmetry@example.test');
        $deletion = $this->destroyCustomer($staff, $store, $customer);

        $this->assertSame(403, $creation->status(),
            'premise: the CREATE side is supposed to refuse this principal already (:9378). If it '
            . 'does not, the asymmetry this case measures is not the one described.');

        $this->assertSame($creation->status(), $deletion->status(),
            'ONE STAFF MEMBER CAN DELETE A CUSTOMER THEY CANNOT CREATE. create answered '
            . $creation->status() . ' and delete answered ' . $deletion->status()
            . ' for the same principal on the same resource in the same store.');
    }

    /** 403, NEVER 401. */
    public function test_the_refusal_is_403_and_never_401(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->destroyCustomer($staff, $store, $customer);

        $this->assertNotSame(401, $response->status(),
            'a 401 here logs the merchant out of the whole app');
        $this->assertSame(403, $response->status());

        /* AND THE REFUSAL DOES NOT LEAK THE PII IT IS PROTECTING. */
        $response->assertDontSee(self::PII_EMAIL, false);
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE CONTROLS

    /**
     * The store's own owner still deletes, holding the production-shaped global `admin` role.
     *
     * This is the shape the CREATE side's own owner test uses, so it is the reading that matters
     * for parity between the two halves.
     */
    public function test_the_store_owner_with_the_global_admin_role_still_deletes(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();
        $owner = $this->grantMerchantGlobalRole($merchant, ['Manage Customer']);

        $this->assertSame(200, $this->destroyCustomer($owner, $store, $customer)->status(),
            'the gate refused a store owner holding the global `admin` role that carries '
            . '`Manage Customer`. MerchantPermissions::names() keeps the NULL-store arm for exactly '
            . 'this principal, and if that arm is ever dropped this is the test that says so.');
        $this->assertFalse($this->isLive((int) $customer->id),
            'the owner got a 200 but the delete did not run');
    }

    /**
     * And a BARE store owner, holding no role at all, still deletes — this is the owner limb.
     *
     * See the class docblock: the CREATE side would refuse this principal, which is the one
     * asymmetry this change leaves behind, recorded rather than hidden.
     */
    public function test_a_bare_store_owner_still_deletes_their_own_customer(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();

        $this->assertFalse($merchant->isAbleTo('Manage Customer'),
            'premise: this owner is supposed to hold nothing, or the owner limb is not what is '
            . 'being measured');

        $this->assertSame(200, $this->destroyCustomer($merchant, $store, $customer)->status(),
            'the gate refused the store\'s OWN owner, which is the exact regression this family '
            . 'has already produced once');
        $this->assertFalse($this->isLive((int) $customer->id));
    }

    public function test_staff_holding_manage_customer_still_delete(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();
        $staff = $this->staffIn($merchant, $store, 'Manage Customer');

        $this->assertSame(200, $this->destroyCustomer($staff, $store, $customer)->status(),
            'a staff member who DOES hold `Manage Customer` was refused, so the gate names a '
            . 'permission that admits nobody. CustomerController::destroy() -- the web twin -- and '
            . 'the API CREATE side both name this exact permission.');
        $this->assertFalse($this->isLive((int) $customer->id));
    }

    /**
     * `Show Customer` must NOT admit a delete. It is seeded and the web `show()` accepts it for a
     * READ; neither the web delete nor the API create does, so admitting it would let a read-only
     * grant destroy PII.
     */
    public function test_show_customer_alone_does_not_admit_a_delete(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();
        $staff = $this->staffIn($merchant, $store, 'Show Customer');

        $this->assertSame(403, $this->destroyCustomer($staff, $store, $customer)->status(),
            '`Show Customer` is a READ right and it admitted a delete');
        $this->assertTrue($this->isLive((int) $customer->id));
    }

    /** The platform operator, whom `isAbleTo()` alone refuses, still deletes. */
    public function test_the_platform_operator_still_deletes(): void
    {
        [, $store, $customer] = $this->tenantWithACustomer();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertSame(200, $this->destroyCustomer($operator, $store, $customer)->status(),
            'the platform operator was refused. `isAbleTo()` has no super-admin bypass, which is '
            . 'why the gate goes through MerchantPermissions -- it answers WILDCARD for them, and '
            . 'the web twin CustomerController::destroy() tests the operator first for the same '
            . 'reason.');
        $this->assertFalse($this->isLive((int) $customer->id));
    }

    // ══════════════════════════════════════════════════════════════════════ TENANCY IS UNCHANGED

    /**
     * A member of another tenant holding `Manage Customer` in their OWN store is still refused, by
     * `resolveOwnedStore()` and long before any permission question.
     *
     * This handler's refusals carry real HTTP codes rather than this API's HTTP-200 `{status: 0}`
     * envelope, so the status is the right field here — and `status` is read too, so that if the
     * handler is ever converted to `$this->error()` the status assertion stops being vacuous.
     */
    public function test_another_tenants_member_is_still_refused_and_deletes_nobody(): void
    {
        [, $store, $customer] = $this->tenantWithACustomer();

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);
        $outsiderMerchant->current_store = $outsiderStore->id;
        $outsiderMerchant->save();
        $outsider = $this->staffIn(User::find($outsiderMerchant->id), $outsiderStore, 'Manage Customer');

        $response = $this->destroyCustomer($outsider, $store, $customer);

        $this->assertSame(0, $response->json('status'),
            'a member of another tenant was answered with a success envelope while holding '
            . '`Manage Customer` in their OWN store');
        $this->assertSame(403, $response->status());
        $this->assertTrue($this->isLive((int) $customer->id),
            'the cross-tenant call deleted another tenant\'s customer');
        $response->assertDontSee(self::PII_EMAIL, false);
    }
}
