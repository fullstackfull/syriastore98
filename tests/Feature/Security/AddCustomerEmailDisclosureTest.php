<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * CUSTOMER E-MAIL EXISTENCE DISCLOSED BEFORE AUTHORIZATION — `POST api/admin/AddCustomer`.
 * =================================================================================================
 *
 * Owner instruction of 2026-11-07, item 3: "Customer email existence disclosure before
 * authorization."
 *
 * THE ORDERING IS THE WHOLE DEFECT, and every individual step is correct.
 * `Api\DashboardController::addCustomer()`:
 *
 *   :8598  $storeId = $request->query('store_id');           <- CALLER-SUPPLIED
 *   :8600  Validator::make(['store_id' => $storeId], ['store_id' => '...exists:stores,id'])
 *   :8604  $request->validate([ ... 'email' => [..., Rule::unique('customers','email')
 *                                                      ->where('store_id', $storeId)] ])
 *   :8628  $store = $this->resolveOwnedStore($storeId);      <- OWNERSHIP, finally
 *   :8630  if (!$store) { return 403 }
 *
 * The uniqueness rule is correctly scoped to `$storeId` — but `$storeId` is the caller's own
 * query parameter and NOTHING has yet checked that the caller owns that store. So a merchant posts
 * another merchant's `store_id` together with an address, and the 422 tells them whether that
 * address is a customer of that store. The 403 arrives only afterwards, for the requests that get
 * past validation.
 *
 * `resolveOwnedStore()` ITSELF IS CORRECT (`:4543-4563`) — it compares `created_by` against
 * `creatorId()` and exempts the operator. It is simply called too late to protect anything.
 *
 * This is an oracle, not a data read: the response carries no customer record, only the single bit
 * "that address is / is not taken in that store". A bit at a time, with a list of addresses, is how
 * a competitor enumerates another merchant's customer base.
 *
 * THE FIX IS PURE ORDERING — move the ownership resolution above the field validation. No rule
 * changes, no new permission, and nothing changes for a caller who owns the store: they get the
 * same 422 for the same reason. Only a NON-owner's answer changes, from 422 to 403, which is the
 * point. 403 and not 401, so the shipped Flutter app's force-logout-on-401 rule is not engaged.
 */
class AddCustomerEmailDisclosureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchantA;
    private Store $storeA;
    private User $merchantB;
    private Store $storeB;
    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchantA = $this->makeMerchant();
        $this->storeA = $this->makeStore($this->merchantA);
        $this->merchantA->current_store = $this->storeA->id;
        $this->merchantA->save();
        $this->merchantA = User::find($this->merchantA->id);

        $this->merchantB = $this->makeMerchant();
        $this->storeB = $this->makeStore($this->merchantB);

        $this->tokenA = $this->merchantA->createToken('auth_token')->plainTextToken;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Probe',
            'last_name' => 'Caller',
            'email' => 'victim@example.test',
            'mobile' => '555000444',
            // The endpoint's own regex is /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,}$/ --
            // a hyphen is NOT in that character class, so my first draft of this payload failed
            // validation and the positive control below failed for my reason rather than the
            // endpoint's. Letters plus a digit, nothing else.
            'password' => 'Passw0rdLong',
            'status' => '1',
        ], $overrides);
    }

    private function victimIn(Store $store, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Known', 'last_name' => 'Shopper',
            'email' => $email, 'password' => bcrypt('x'),
            'store_id' => $store->id, 'created_by' => $store->created_by,
            'status' => 1,
        ]);
    }

    private function addCustomer(int $storeId, array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->postJson('/api/admin/AddCustomer?store_id=' . $storeId, $payload);
    }

    // =============================================================================================
    //  THE ORACLE
    // =============================================================================================

    /**
     * Two requests that differ ONLY in whether the address is a customer of a store merchant A
     * does not own. If the answers differ, the endpoint has told merchant A something about
     * merchant B's customer base.
     */
    public function test_the_answer_does_not_reveal_whether_an_address_exists_in_a_store_the_caller_does_not_own(): void
    {
        $this->victimIn($this->storeB, 'known@example.test');

        $known = $this->addCustomer($this->storeB->id, $this->payload(['email' => 'known@example.test']));
        $unknown = $this->addCustomer($this->storeB->id, $this->payload(['email' => 'unknown@example.test']));

        $this->assertSame($unknown->status(), $known->status(),
            'the status code differs by whether the address exists in a store the caller does NOT '
            . 'own (' . $known->status() . ' vs ' . $unknown->status() . '), so the endpoint is an '
            . "existence oracle over another merchant's customer base. The uniqueness rule is "
            . 'scoped to the caller-supplied store_id and runs BEFORE resolveOwnedStore()');

        $this->assertSame(403, $known->status(),
            'a caller who does not own the store must be refused before any field is validated '
            . 'against that store');
    }

    public function test_a_non_owner_is_refused_before_validation_even_with_a_wholly_invalid_body(): void
    {
        // Nothing here could pass validation. If the answer is 422 rather than 403, field
        // validation ran against a store the caller does not own.
        $response = $this->addCustomer($this->storeB->id, ['email' => 'not-an-email']);

        $this->assertSame(403, $response->status(),
            'the endpoint validated the body against another merchant\'s store before checking '
            . 'ownership, so it answered ' . $response->status() . ' instead of 403');
    }

    // =============================================================================================
    //  AND THE OWNER'S OWN EXPERIENCE IS UNCHANGED — positive controls
    // =============================================================================================

    public function test_the_owner_still_gets_the_uniqueness_error_for_their_own_store(): void
    {
        $this->victimIn($this->storeA, 'mine@example.test');

        $response = $this->addCustomer($this->storeA->id, $this->payload(['email' => 'mine@example.test']));

        $this->assertSame(422, $response->status(),
            'a caller who DOES own the store must still be told the address is taken — the fix is '
            . 'ordering, not a rule change');
    }

    public function test_the_owner_can_still_create_a_customer_in_their_own_store(): void
    {
        $response = $this->addCustomer($this->storeA->id, $this->payload(['email' => 'fresh@example.test']));

        $this->assertNotSame(403, $response->status(),
            'the fix refuses the store\'s own owner, so it has broken the endpoint rather than '
            . 'reordered it');

        $this->assertSame(1, Customer::withoutGlobalScopes()
            ->where('store_id', $this->storeA->id)
            ->where('email', 'fresh@example.test')->count(),
            'the owner can no longer create a customer in their own store');
    }

    /**
     * POSITIVE CONTROL for the two refusal tests: the token really authenticates, so a 403 is a
     * tenancy refusal and not an authentication failure dressed up as one.
     */
    public function test_premise_the_token_authenticates(): void
    {
        $response = $this->addCustomer($this->storeA->id, $this->payload(['email' => 'premise@example.test']));

        $this->assertNotSame(401, $response->status(),
            'premise: the bearer token does not authenticate, so every refusal above could be a '
            . '401 in disguise and none of them would prove anything about tenancy');
    }
}
