<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * GAP-249 / P4-F113 — A PLATFORM-WIDE CUSTOMER-EXISTENCE ORACLE, ABOVE BOTH GATES.
 * =============================================================================================
 *
 * `getCustomerOrdersApi` validated `['id' => 'required|integer|exists:customers,id']` BEFORE
 * `resolveOwnedStore()` and before the permission limb. So the validator answered a question about
 * the whole `customers` table while the caller had not yet been shown to own anything:
 *
 *     customer id exists SOMEWHERE on the platform  ->  403 from the tenancy gate
 *     customer id does not exist                    ->  422 from the validator
 *
 * Two different answers, decided by a row in another tenant. Any admin-API token holder could walk
 * the id space and learn how many customers the platform has and which ids are live — an
 * unpermissioned staff member of a one-customer store, or a merchant of an unrelated tenant.
 *
 * ONLY A BOOLEAN CROSSES, which is why this is hardening and was NOT called a disclosure: no name,
 * no e-mail, no store, no order. It is also **pre-existing** — the permission limb added on
 * 2026-11-25 sits below both validators and neither worsened nor closed it. Found by the
 * independent review of that batch, which placed it correctly as LOW.
 *
 * ---------------------------------------------------------------------------------------------
 * THE FIX TIGHTENS THE RULE AND DOES NOT TOUCH THE ORDERING, which matters more than it looks.
 *
 * The tempting fix — move the permission limb above the validators — would be wrong twice over.
 * `resolveOwnedStore()` must answer FIRST, because a caller from another tenant has to keep getting
 * the STORE refusal: three shipped suites assert that status, and
 * `AdminApiCustomerReadPermissionTest::test_another_tenants_staff_still_gets_the_tenancy_refusal`
 * pins it by asserting the refusal body says "this store" rather than "perform this action".
 *
 * So instead the `exists` rule is scoped to the store the request names:
 * `exists:customers,id,store_id,<storeId>`. A foreign customer id is then simply "not found" —
 * the same 422 an absent id gets — and the two answers become indistinguishable. The `store_id`
 * used is the caller's own request value, which is NOT a tenant check and is not relied on as one:
 * `resolveOwnedStore()` still decides ownership immediately below. All the scoping does is stop the
 * validator speaking about rows outside the store named in the same request.
 */
class AdminApiCustomerExistenceOracleTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn(Store $store, User $merchant, string $tag): Customer
    {
        return Customer::forceCreate([
            'first_name' => $tag, 'last_name' => 'F113-ORACLE',
            'email' => 'oracle-' . $tag . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'created_by' => $merchant->id,
            'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function ask(User $actor, int $storeId, int $customerId): int
    {
        $bearer = $actor->createToken('app')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->getJson('/api/admin/customer/orders?store_id=' . $storeId . '&id=' . $customerId)
            ->status();
    }

    /**
     * THE ORACLE: a foreign tenant's customer id must be indistinguishable from an id that exists
     * nowhere. Both legs are driven in one case on purpose — the defect IS the difference between
     * them, so asserting either alone would prove nothing.
     */
    public function test_a_foreign_customers_id_is_indistinguishable_from_an_absent_one(): void
    {
        $victimMerchant = $this->makeMerchant();
        $victimStore = $this->makeStore($victimMerchant);
        $foreignCustomer = $this->customerIn($victimStore, $victimMerchant, 'victim');

        $attacker = $this->makeMerchant();
        $attackerStore = $this->makeStore($attacker);
        $attacker->current_store = $attackerStore->id;
        $attacker->save();
        $attacker = User::find($attacker->id);

        $absentId = ((int) Customer::withoutGlobalScopes()->max('id')) + 5000;

        $forForeign = $this->ask($attacker, $attackerStore->id, $foreignCustomer->id);
        $forAbsent = $this->ask($attacker, $attackerStore->id, $absentId);

        $this->assertSame($forAbsent, $forForeign, sprintf(
            'a FOREIGN tenant\'s customer id answered HTTP %d while an id that exists nowhere '
            . 'answered HTTP %d. The `exists:customers,id` validator runs ABOVE resolveOwnedStore(), '
            . 'so it answers a question about the whole platform before the caller has been shown '
            . 'to own anything -- an id-space oracle for any admin-API token holder. Only a boolean '
            . 'crosses, so this is hardening rather than a disclosure, but the two answers must not '
            . 'differ.',
            $forForeign,
            $forAbsent
        ));
    }

    /** The same oracle is reachable by an UNPERMISSIONED STAFF MEMBER of a real store, who is a far
     *  more ordinary principal than a foreign merchant — driven separately because the gate that
     *  refuses them is a different one. */
    public function test_unpermissioned_staff_cannot_use_the_oracle_either(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $otherMerchant = $this->makeMerchant();
        $otherStore = $this->makeStore($otherMerchant);
        $foreignCustomer = $this->customerIn($otherStore, $otherMerchant, 'other');

        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();
        $staff = User::find($staff->id);

        $absentId = ((int) Customer::withoutGlobalScopes()->max('id')) + 5000;

        $this->assertSame(
            $this->ask($staff, $store->id, $absentId),
            $this->ask($staff, $store->id, $foreignCustomer->id),
            'an unpermissioned staff member of their own store could still tell a foreign '
            . 'customer id from an absent one'
        );
    }

    /**
     * CONTROL: the store's own customer is still served. If this fails the fix has scoped the rule
     * so tightly that the legitimate request is refused, which is a user-visible regression in the
     * shipped app rather than a hardening.
     */
    public function test_the_stores_own_customer_is_still_served(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();

        $own = $this->customerIn($store, $merchant, 'own');

        $this->assertSame(200, $this->ask(User::find($merchant->id), $store->id, $own->id),
            'the store\'s own merchant was refused their own customer\'s orders, so the scoped '
            . '`exists` rule now refuses a legitimate request');
    }

    /** CONTROL: the tenancy refusal for a store the caller does not own is UNCHANGED, and still
     *  says "this store" rather than the permission message. The ordering is load-bearing. */
    public function test_naming_a_foreign_store_still_gives_the_tenancy_refusal(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $own = $this->customerIn($store, $merchant, 'own2');

        $outsider = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsider);
        $outsider->current_store = $outsiderStore->id;
        $outsider->save();

        $bearer = User::find($outsider->id)->createToken('app')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->getJson('/api/admin/customer/orders?store_id=' . $store->id . '&id=' . $own->id);

        $this->assertSame(403, $response->status());
        $this->assertStringContainsString('this store', (string) $response->getContent(),
            'a caller naming a store they do not own got something other than the STORE refusal, '
            . 'so the fix has reordered the gates. resolveOwnedStore() must answer first.');
    }
}
