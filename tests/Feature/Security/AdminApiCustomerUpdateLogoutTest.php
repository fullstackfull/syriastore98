<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P4-F114 / GAP-125 — `POST api/admin/update-customer` ANSWERED **401** TO MERCHANT STAFF.
 * =================================================================================================
 *
 * A worse failure mode than every other row of GAP-142, and the reason it is its own suite. The
 * shipped Flutter admin app maps ANY 401 onto `_handleUnauthorized()`, which clears preferences and
 * forces a re-login (`repo-B lib/network/network.dart:285-288`). So a staff member who tapped
 * "save" on a customer was not told they lacked a right — **they were signed out of the whole
 * app**, with no explanation and their draft lost. Every refusal this branch adds answers 403
 * precisely to avoid that, and this handler was doing the opposite.
 *
 * -------------------------------------------------------------------------------------------------
 * WHERE THE 401 CAME FROM: THIS HANDLER, NOT MIDDLEWARE. Established by reading, not assumed.
 *
 * `updateCustomerInfo()`'s first statement was:
 *
 *     if (!$adminUser || !in_array($adminUser->type, ['admin', 'super admin'])) { ... 401 }
 *
 * `custom.auth` is not involved: it resolves the Sanctum token and logs a `User` into the `web`
 * guard, so `auth()->user()` returns the staff member perfectly well. The refusal is a **string
 * comparison on `users.type`** inside the handler, and `type` is `'staff'` for every staff member,
 * so the condition was true for all of them regardless of what their role granted.
 *
 * **IT WAS A TYPE CHECK STANDING IN FOR A PERMISSION CHECK, and it was wrong in both directions.**
 * It admitted every `type = 'admin'` row — which public registration hands every self-registered
 * merchant — whatever they had been granted, and it refused every staff member, whatever they had
 * been granted. The thing it never consulted is the permission.
 *
 * -------------------------------------------------------------------------------------------------
 * SHOULD A STAFF MEMBER BE ABLE TO UPDATE A CUSTOMER? YES — BOTH SIBLING HALVES ALREADY SAY SO.
 *
 * **There is no web twin to copy, and that is measured:** `Route::resource('customer', ...)
 * ->except(['edit', 'update'])` — the web update route is deliberately unregistered because no
 * handler exists for it (`routes/web.php:1420`, which records that `GET /customer/1/edit` used to
 * answer a 500). So the admin API is the ONLY customer-update surface on the platform and the
 * answer has to come from the resource's own siblings:
 *
 *   `addCustomer` (CREATE, `POST AddCustomer`) admits staff holding `Manage Customer` —
 *   `AdminApiCustomerCreatePermissionTest::test_POSITIVE_staff_WITH_the_permission_still_create
 *   _customers` pins it.
 *
 *   `destroy` (DELETE, `DELETE destroyCustomer`) admits them on the same name since GAP-142's
 *   write half — `AdminApiCustomerDeletePermissionTest::test_staff_holding_manage_customer_still
 *   _delete`.
 *
 *   `CustomerController::customerStatus()` on the web writes `customers.status` — one of the very
 *   columns this handler writes — gated on `Manage Customer` (`:471`, operator-first).
 *
 * So `Manage Customer` is the consistent name and nothing is invented. Taken through
 * `MerchantDashboardAbilities::PERMISSION_CUSTOMERS`, the same constant the other two halves use,
 * so the three cannot drift apart.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT CHANGED, EXACTLY: the type limb is replaced by the real permission gate, below the store
 * resolution because the grant is per store. Two statuses move and one does not:
 *
 *   staff WITH `Manage Customer`  — was 401 (forced logout) → now 200, the update runs.
 *   staff WITHOUT it              — was 401 (forced logout) → now 403, session intact.
 *   a caller with no `web` principal at all — **401, unchanged**. That branch is kept deliberately:
 *   it is the only one where the caller is genuinely not a panel principal, and `custom.auth` makes
 *   it reachable by a `Customer` or `DeliveryBoy` token (their tokenable class logs them into a
 *   DIFFERENT guard, so `auth()->user()` is null while the middleware passed). Changing it would be
 *   a behaviour change on a path no client in either repository is known to take, and the shipped
 *   ADMIN app cannot produce it — it only ever holds a `User` token. Recorded rather than widened.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TRIPWIRE IN THE SIBLING FILE HAS BEEN UPDATED — DONE, not outstanding.
 *
 * This section used to read "MUST BE UPDATED BY WHOEVER OWNS THAT FILE". It has been:
 * `AdminApiCustomerCreatePermissionTest::test_update_customer_still_refuses_unpermitted_staff_now
 * _through_the_permission_gate` now asserts `assertSame(403, ...)` and carries the reasoning for
 * the change. It was a TRIPWIRE rather than a correctness claim — its old message read *"If this
 * changes, the gap recorded in this file has grown a second site"* — and it fired for the opposite
 * reason: the gap was CLOSED. It was also renamed, because `..._because_it_checks_the_type`
 * described a type check that no longer exists.
 *
 * Left here as a record rather than deleted: a reader who finds the old 401 anywhere should know it
 * was deliberate and when it moved.
 */
class AdminApiCustomerUpdateLogoutTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ORIGINAL_NAME = 'GAP125-ORIGINAL-FIRST-NAME';

    private const ATTEMPTED_NAME = 'GAP125-RENAMED-BY-STAFF';

    /** @return array{0:User,1:Store,2:Customer} */
    private function tenantWithACustomer(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $customer = Customer::create([
            'first_name' => self::ORIGINAL_NAME,
            'last_name' => 'Shopper',
            'email' => 'gap125-shopper@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'mobile' => '555000111',
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

    private function updateCustomer(User $user, Store $store, Customer $customer, string $name = self::ATTEMPTED_NAME)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/update-customer?store_id=' . $store->id, [
            'customer_id' => $customer->id,
            'first_name' => $name,
        ]);
    }

    /**
     * The customer's live first name, read through `withoutGlobalScopes()`.
     *
     * `Customer` is one of the ELEVEN models that genuinely filter — it is in
     * `config/tenancy.php`'s `scoped_models` and carries `SoftDeletingScope` — so a plain
     * `Customer::find()` answers against whichever tenant happens to be resolved rather than
     * against the table, and "the write did not happen" would pass either way.
     */
    private function firstName(int $customerId): ?string
    {
        $row = Customer::withoutGlobalScopes()->find($customerId);

        return $row === null ? null : (string) $row->first_name;
    }

    // ════════════════════════════════════════════════════════════════ THE DEFECT: A FORCED LOGOUT

    /**
     * THE CASE THAT NAMES THE USER-VISIBLE DEFECT. A staff member who HOLDS `Manage Customer` was
     * signed out of the app for tapping save.
     *
     * Asserted as "not 401" AND as the update actually succeeding — a "not 401" assertion alone
     * would pass against a 500, and a 500 is not a fix.
     */
    public function test_staff_holding_manage_customer_are_not_logged_out_and_the_update_runs(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();
        $staff = $this->staffIn($merchant, $store, 'Manage Customer');

        $response = $this->updateCustomer($staff, $store, $customer);

        $this->assertNotSame(401, $response->status(),
            'A STAFF MEMBER WAS SIGNED OUT OF THE WHOLE APP FOR TAPPING SAVE. The shipped Flutter '
            . 'admin app maps any 401 onto _handleUnauthorized(), which clears preferences and '
            . 'forces a re-login (network.dart:285-288). The refusal came from a users.type string '
            . 'comparison inside the handler, not from middleware.');

        $this->assertSame(200, $response->status(),
            'the 401 is gone but the staff member still cannot save. They hold `Manage Customer`, '
            . 'which is what BOTH sibling halves of this resource admit on -- addCustomer and '
            . 'destroyCustomer. Body: ' . substr((string) $response->getContent(), 0, 400));

        $this->assertSame(self::ATTEMPTED_NAME, $this->firstName((int) $customer->id),
            'HTTP 200 but the name did not change, so the gate is short-circuiting the handler '
            . 'rather than admitting it -- which a status-only assertion could not tell apart');
    }

    /**
     * AND A STAFF MEMBER WITHOUT THE PERMISSION IS REFUSED WITHOUT BEING LOGGED OUT.
     *
     * 403, session intact, and the write must not have happened. The old 401 refused them too, so
     * the ACCESS decision is unchanged for this principal — only the status, and the status is the
     * defect.
     */
    public function test_staff_without_manage_customer_get_403_and_keep_their_session(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->updateCustomer($staff, $store, $customer);

        $this->assertNotSame(401, $response->status(),
            'an unpermitted staff member was signed out of the app instead of being told they lack '
            . 'a right. The shipped app treats 403 as "authorized but not permitted" with the '
            . 'session INTACT and 401 as "start again".');
        $this->assertSame(403, $response->status(),
            'the refusal is not an explicit 403: ' . substr((string) $response->getContent(), 0, 400));

        $this->assertSame(self::ORIGINAL_NAME, $this->firstName((int) $customer->id),
            'the refused call still rewrote the customer\'s name');
    }

    /**
     * THE TYPE CHECK ADMITTED ON `users.type` AND NOT ON A GRANT, which is the other half of the
     * defect and the half a status fix alone would leave behind.
     *
     * A second merchant's staff member is irrelevant here; what matters is that the decision is now
     * made by the permission. This case drives the two staff principals side by side: same type,
     * same store, same tenant, different grant, different answer. Under the old type check they got
     * the SAME answer (401) because `type` was the only thing consulted.
     */
    public function test_the_decision_is_made_by_the_grant_and_not_by_users_type(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();

        $granted = $this->staffIn($merchant, $store, 'Manage Customer');
        $bare = $this->staffIn($merchant, $store);

        $this->assertSame(
            strtolower(trim((string) $granted->type)),
            strtolower(trim((string) $bare->type)),
            'fixture: the two principals must share a users.type, or this case is not measuring '
            . 'that the type is no longer what decides'
        );

        $grantedResponse = $this->updateCustomer($granted, $store, $customer, 'BY-GRANTED-STAFF');
        $bareResponse = $this->updateCustomer($bare, $store, $customer, 'BY-BARE-STAFF');

        $this->assertNotSame($grantedResponse->status(), $bareResponse->status(),
            'two staff members of the same type in the same store got the SAME answer whether or '
            . 'not they held `Manage Customer`, so `users.type` is still what decides and the '
            . 'permission is still not consulted');
        $this->assertSame(200, $grantedResponse->status());
        $this->assertSame(403, $bareResponse->status());
        $this->assertSame('BY-GRANTED-STAFF', $this->firstName((int) $customer->id),
            'the bare staff member\'s call overwrote the permitted one\'s change');
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE CONTROLS

    /**
     * The store's own owner still updates their own customer, holding no role.
     *
     * `AdminApiTenancyTest::test_update_customer_still_works_on_your_own_customer` drives exactly
     * this principal and was green before, so a permission-only gate would turn it red.
     */
    public function test_the_store_owner_still_updates_their_own_customer(): void
    {
        [$merchant, $store, $customer] = $this->tenantWithACustomer();

        $response = $this->updateCustomer($merchant, $store, $customer);

        $this->assertSame(200, $response->status(),
            'the gate refused the store\'s OWN owner. The old type check admitted every '
            . 'type = "admin" row, which is every self-registered merchant, so removing it without '
            . 'an owner limb would refuse a merchant holding no explicit grant: '
            . substr((string) $response->getContent(), 0, 400));
        $this->assertSame(self::ATTEMPTED_NAME, $this->firstName((int) $customer->id));
    }

    /** The platform operator, whom `isAbleTo()` alone refuses, still updates. */
    public function test_the_platform_operator_still_updates(): void
    {
        [, $store, $customer] = $this->tenantWithACustomer();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertSame(200, $this->updateCustomer($operator, $store, $customer)->status(),
            'the platform operator was refused. The old type check admitted them by listing '
            . '"super admin" explicitly; the replacement must keep admitting them, which it does '
            . 'through MerchantPermissions answering WILDCARD -- `isAbleTo()` alone would refuse '
            . 'them, as there is no super-admin bypass here.');
        $this->assertSame(self::ATTEMPTED_NAME, $this->firstName((int) $customer->id));
    }

    // ══════════════════════════════════════════════════════════════════════ TENANCY IS UNCHANGED

    /**
     * A member of another tenant holding `Manage Customer` in their OWN store is still refused —
     * and NOT with a 401, which is what the old type check gave them.
     *
     * `resolveOwnedStore()` refuses them before any permission question, so this proves the two
     * axes are independent rather than that either works. The handler's tenancy refusal is already
     * a real 403 in its own `{status: 0}` body, so the status is the right field here.
     */
    public function test_another_tenants_member_is_still_refused_and_writes_nothing(): void
    {
        [, $store, $customer] = $this->tenantWithACustomer();

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);
        $outsiderMerchant->current_store = $outsiderStore->id;
        $outsiderMerchant->save();
        $outsider = $this->staffIn(User::find($outsiderMerchant->id), $outsiderStore, 'Manage Customer');

        $response = $this->updateCustomer($outsider, $store, $customer);

        $this->assertNotSame(401, $response->status(),
            'a cross-tenant attempt signed the caller out of the app rather than refusing them');
        $this->assertSame(403, $response->status(),
            'a member of another tenant was admitted to this store\'s customer while holding '
            . '`Manage Customer` in their OWN store');
        $this->assertSame(0, $response->json('status'));
        $this->assertSame(self::ORIGINAL_NAME, $this->firstName((int) $customer->id),
            'the cross-tenant call rewrote another tenant\'s customer');
    }
}
