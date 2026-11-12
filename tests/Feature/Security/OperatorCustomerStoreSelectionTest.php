<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * ISSUE #84 — the platform operator writes a customer into a merchant's store with no selection.
 * =================================================================================================
 *
 * Owner instruction of 2026-11-07, item 3, first priority: "platform operator creating a customer
 * in a merchant store without an explicit valid merchant selection."
 *
 * THE MECHANISM, three lines that each look correct on their own:
 *
 *   1. `CustomerController::store():50` sets `$isOperator` from `users.type`, and the gate at `:52`
 *      admits the operator — correctly, because Laratrust has no super-admin bypass and a bare
 *      `isAbleTo()` would refuse the one principal who legitimately works across stores.
 *   2. `$store = getCurrentStore()` at `:56`. For a `super admin` whose `current_store` is empty,
 *      `app/Helper/helper.php:774-775` returns **the integer literal `1`** — "Default store for
 *      super admin".
 *   3. `if (empty($store))` at `:58` is the guard meant to catch "no store selected". **`1` is not
 *      empty**, so it passes.
 *
 * SO THE OPERATOR CREATES A CUSTOMER IN WHOEVER OWNS STORE 1 — with a password the OPERATOR chose
 * (`:125`) and `email_verified_at` already set (`:132`), which is an account that can sign in to
 * that merchant's storefront immediately. The merchant is not told, and nothing in the response
 * says which store was written.
 *
 * IT IS NOT A CROSS-TENANT READ and it is not an escalation of the operator's rights: the operator
 * may legitimately act in any store. The defect is that **no store was chosen**. A silent default
 * is the wrong answer to "which merchant did you mean" when the answer determines who owns a new
 * credential-bearing account.
 *
 * WHY THE FIX IS HERE AND NOT IN THE HELPER. `getCurrentStore()` carries that literal for a large
 * number of call sites, and changing it is a platform-wide behaviour change that would need its own
 * decision and its own evidence. This handler asks the narrower question — may I CREATE an account
 * in a store the caller has not named — and answers it locally. Merchant behaviour is untouched:
 * a merchant's `current_store` is set, so the first branch of the helper returns it and this
 * handler never reaches the operator limb.
 */
class OperatorCustomerStoreSelectionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $merchantStore;

    protected function setUp(): void
    {
        parent::setUp();

        // The merchant's store is created FIRST so it takes id 1 — which is the id the helper's
        // operator branch hands out, and the whole point of the finding.
        $this->merchant = $this->makeMerchant();
        $this->merchantStore = $this->makeStore($this->merchant);
        $this->merchant->current_store = $this->merchantStore->id;
        $this->merchant->save();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Walid',
            'last_name' => 'Najjar',
            'email' => 'walid@example.test',
            'mobile' => '555000333',
            'password' => 'a-long-enough-password',
            'status' => '1',
        ], $overrides);
    }

    /** Through withoutGlobalScopes: Customer carries StoreScope AND SoftDeletingScope. */
    private static function rows()
    {
        return Customer::withoutGlobalScopes();
    }

    public function test_premise_the_merchants_store_is_store_1_and_the_helper_hands_that_out(): void
    {
        $this->assertSame(1, (int) $this->merchantStore->id,
            'premise: the merchant\'s store must be id 1, because the helper\'s operator branch '
            . 'returns the literal 1 and that is the defect under test');

        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->actingAs(User::find($operator->id));

        $this->assertSame(1, (int) getCurrentStore(),
            'premise: getCurrentStore() must return the literal 1 for an operator with no '
            . 'current_store (app/Helper/helper.php:774-775), or this finding does not exist');
    }

    /**
     * THE DEFECT. An operator who has selected no store must not be able to create a
     * credential-bearing customer account in a merchant's store.
     */
    public function test_an_operator_with_no_store_selected_cannot_create_a_customer(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $response = $this->actingAs(User::find($operator->id))
            ->post('/customer', $this->payload());

        $this->assertSame(0, self::rows()->where('email', 'walid@example.test')->count(),
            'the platform operator created a customer with NO store selected. '
            . 'getCurrentStore() returned the literal 1, `empty(1)` is false so the handler\'s '
            . 'own guard passed, and the account — with a password the operator chose and '
            . 'email_verified_at already set — landed in whichever merchant owns store 1');

        $response->assertStatus(302);
    }

    /**
     * Same for `current_store = 0` and `current_store = ''`, because `!empty()` in the helper
     * treats all three as "unset" and routes every one of them to the literal 1.
     */
    public function test_the_other_two_empty_spellings_are_refused_too(): void
    {
        foreach ([0, ''] as $i => $empty) {
            $operator = $this->makePlatformAdmin(['current_store' => $empty]);
            $email = 'empty' . $i . '@example.test';

            $this->actingAs(User::find($operator->id))
                ->post('/customer', $this->payload(['email' => $email]));

            $this->assertSame(0, self::rows()->where('email', $email)->count(),
                'an operator with current_store = ' . var_export($empty, true)
                . ' created a customer: `!empty()` in the helper treats it as unset and hands '
                . 'out the literal 1');
        }
    }

    // =============================================================================================
    //  AND THE OPERATOR MUST STILL BE ABLE TO WORK — the fix refuses ambiguity, not the principal
    // =============================================================================================

    /**
     * POSITIVE CONTROL, and the half that makes this a narrowing rather than a refusal. An operator
     * who HAS selected a store is doing exactly what the operator role is for, and must still be
     * able to create a customer in it. A fix that refused this would have removed a capability
     * rather than disambiguated one.
     */
    public function test_an_operator_who_has_selected_a_store_can_still_create_a_customer(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => $this->merchantStore->id]);

        $response = $this->actingAs(User::find($operator->id))
            ->post('/customer', $this->payload(['email' => 'chosen@example.test']));

        $response->assertSessionHasNoErrors();

        $customer = self::rows()->where('email', 'chosen@example.test')->first();

        $this->assertNotNull($customer,
            'an operator with an explicitly selected store can no longer create a customer, so '
            . 'the fix removed a capability instead of disambiguating one');
        $this->assertSame($this->merchantStore->id, (int) $customer->store_id,
            'the customer did not land in the store the operator selected');
    }

    /**
     * And the merchant path is untouched. A merchant's `current_store` is always set, so the
     * helper's first branch returns it and the operator limb is never reached.
     */
    public function test_a_merchant_is_unaffected(): void
    {
        $this->grantPermission(User::find($this->merchant->id), 'Manage Customer');

        $response = $this->actingAs(User::find($this->merchant->id))
            ->post('/customer', $this->payload(['email' => 'merchantmade@example.test']));

        $response->assertSessionHasNoErrors();

        $customer = self::rows()->where('email', 'merchantmade@example.test')->first();

        $this->assertNotNull($customer, 'the merchant can no longer create a customer');
        $this->assertSame($this->merchantStore->id, (int) $customer->store_id,
            'the merchant\'s customer did not land in the merchant\'s own store');
    }
}
