<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * THE STOREFRONT HAS THE SAME ARCHIVE DEAD END THE ADMIN FORM JUST LOST — reproduction only.
 * =================================================================================================
 *
 * Found while answering the owner's question of 2026-11-07, item 3: *"Any cross-tenant consequences
 * introduced by the customer archive/re-registration repairs."*
 *
 * **THE ANSWER TO THAT QUESTION IS: NO CROSS-TENANT CONSEQUENCE.** I censused every raw
 * `DB::table('customers')` read in first-party code — raw reads are the class the F2 finding
 * belonged to, because they bypass `Customer`'s `SoftDeletingScope`. There are four:
 *
 *   `ModuleStorefrontInputGuard:187`  the F2 site — ALREADY FIXED with `whereNull('deleted_at')`
 *   `ModuleStorefrontInputGuard:344`  reads by `id` and compares `store_id`; a trashed row's
 *                                     store_id is still its own store, so it is unaffected
 *   `CustomerLoginController:85`      storefront LOGIN existence check — store-scoped
 *   `CustomerLoginController:934`     storefront REGISTRATION existence check — store-scoped
 *
 * All four carry a store predicate, so **none of them can cross a tenant boundary.** What the last
 * two can do is answer *within* a store using a row the application otherwise treats as deleted.
 *
 * WHAT THIS FILE RECORDS, AND WHY IT IS NOT FIXED HERE
 * -------------------------------------------------------------------------------------------------
 *
 * `:934` is the registration validator. `$customerExists` is true for an **archived** row, so the
 * shopper is failed with *"This Email already exist, please login."* — and they cannot log in,
 * because the actual authentication at `:723` uses **Eloquent**
 * (`Customer::where('email', ...)->where('store_id', ...)->first()`), which IS soft-delete-scoped
 * and returns null for the archived row.
 *
 * So a shopper whose account a merchant archived **can neither sign in nor register again**, and is
 * told to do the one thing that cannot work. That is the same dead end `P2.5-PKG-E` (E-1) removed
 * from the merchant admin form, still present on the storefront, for the same reason: a uniqueness
 * check that counts deleted rows.
 *
 * **IT IS NOT FIXED IN THIS SLICE, DELIBERATELY.** D-40 authorised the admin form by name; this is
 * a storefront *authentication and registration* path, and allowing re-registration at an archived
 * address there is a behaviour change on a surface the owner has not named. It is proposed in
 * `docs/security/P25-CUSTOMER-EMAIL-UNIQUENESS-PROPOSAL.md` and reproduced here so the decision has
 * a measurement rather than a description.
 *
 * `:85` (login) is the milder half and is recorded in the same proposal: the existence check
 * passes for an archived row, but the Eloquent lookup that actually authenticates does not, so the
 * outcome is a misleading message rather than a sign-in. **No authentication bypass**, and that was
 * checked rather than assumed.
 */
class StorefrontArchivedCustomerDeadEndTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = $this->makeMerchant();
        $this->store = $this->makeStore($merchant);
    }

    private static function rows()
    {
        return Customer::withoutGlobalScopes();
    }

    private function archivedCustomer(string $email): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Was', 'last_name' => 'Here',
            'email' => $email, 'password' => bcrypt('the-shoppers-own-password'),
            'store_id' => $this->store->id, 'created_by' => $this->store->created_by,
            'status' => 1,
        ]);
        $customer->delete();   // through Eloquent so the model cache is flushed

        return $customer;
    }

    public function test_premise_the_row_is_archived_and_invisible_to_eloquent(): void
    {
        $archived = $this->archivedCustomer('gone@example.test');

        $this->assertNotNull(self::rows()->find($archived->id)->deleted_at,
            'premise: the row is not soft-deleted, so there is no dead end to measure');

        $this->assertNull(
            Customer::withoutGlobalScope(\App\Scopes\StoreScope::class)
                ->where('email', 'gone@example.test')->first(),
            'premise: Eloquent still finds the archived row, so the SoftDeletingScope is not '
            . 'active and the asymmetry this file is about does not exist');
    }

    /**
     * THE ASYMMETRY, measured on the two queries themselves rather than through the HTTP routes.
     *
     * Driving `POST {slug}/register` end to end would need the storefront's theme, its OTP
     * integration and its session guard, and would measure those as much as this. The two reads
     * are quoted verbatim from `CustomerLoginController:86-88` and `:935-937`, so what is compared
     * here is exactly what those validators compare.
     */
    public function test_the_raw_existence_check_sees_an_archived_row_while_eloquent_does_not(): void
    {
        $this->archivedCustomer('gone@example.test');

        // CustomerLoginController:935-937 — the registration validator's own query.
        $rawSaysExists = \DB::table('customers')
            ->where('email', 'gone@example.test')
            ->where('store_id', $this->store->id)
            ->exists();

        // CustomerLoginController:723 — the query that actually authenticates.
        $eloquentFinds = Customer::withoutGlobalScope(\App\Scopes\StoreScope::class)
            ->where('email', 'gone@example.test')
            ->where('store_id', $this->store->id)
            ->first();

        $this->assertTrue($rawSaysExists,
            'premise: the raw check does not see the archived row, so there is no asymmetry');
        $this->assertNull($eloquentFinds,
            'premise: Eloquent sees the archived row, so there is no asymmetry');

        // The two disagree, and that disagreement IS the dead end: registration refuses with
        // "please login", and login cannot succeed.
        $this->assertNotSame($rawSaysExists, $eloquentFinds !== null,
            'the raw existence check and the authenticating query agree, so the dead end this '
            . 'file documents has been closed — update or delete this test');
    }

    /**
     * AND IT IS WITHIN ONE STORE. The answer to the owner's cross-tenant question: both reads
     * carry a store predicate, so an archived row in store A cannot affect store B.
     */
    public function test_an_archived_row_in_another_store_does_not_affect_this_one(): void
    {
        $otherMerchant = $this->makeMerchant();
        $otherStore = $this->makeStore($otherMerchant);

        $elsewhere = Customer::forceCreate([
            'first_name' => 'Other', 'last_name' => 'Store',
            'email' => 'shared@example.test', 'password' => bcrypt('x'),
            'store_id' => $otherStore->id, 'created_by' => $otherStore->created_by,
            'status' => 1,
        ]);
        $elsewhere->delete();

        $rawSaysExistsHere = \DB::table('customers')
            ->where('email', 'shared@example.test')
            ->where('store_id', $this->store->id)
            ->exists();

        $this->assertFalse($rawSaysExistsHere,
            "an archived row in ANOTHER store makes this store's existence check answer true, so "
            . 'the raw read crosses a tenant boundary');

        // POSITIVE CONTROL: the same query against the store that really holds the row says true,
        // so the false above is a tenant predicate working and not an empty table.
        $this->assertTrue(\DB::table('customers')
            ->where('email', 'shared@example.test')
            ->where('store_id', $otherStore->id)
            ->exists(),
            'positive control: the row exists and the query finds it in its own store');
    }
}
