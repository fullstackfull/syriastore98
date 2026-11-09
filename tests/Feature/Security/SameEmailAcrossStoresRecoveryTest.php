<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Security\SecurityTestCase;

/**
 * OWNER SAFEGUARD 3 (2026-11-07) — account selection, archived accounts and PASSWORD RECOVERY with
 * the same address in more than one store.
 * =================================================================================================
 *
 * *"Verify correct account selection, archived-account behavior and password recovery across
 * multiple stores."*
 *
 * The owner's intended rule makes two live customers with one address in DIFFERENT stores an
 * ordinary, supported state. That state already occurs today through the storefront's own
 * registration, which is per-store — but nothing in the suite drove PASSWORD RECOVERY against it.
 * Account selection and archived behaviour are covered by
 * `UnscopedCustomerEmailLookupTest` and `StorefrontArchivedCustomerDeadEndTest`; recovery is the
 * element I had only READ rather than executed, so it is executed here.
 *
 * WHY IT IS SAFE, and the thing that makes it safe is deliberately not the address.
 * `Customer::getEmailForPasswordReset()` returns **`'customer:' . $this->getKey()`** — the account
 * ID, not a parsable e-mail. `config/auth.php` declares TWO brokers over the SAME
 * `password_reset_tokens` table (`users` and `customers`), and Laravel's
 * `DatabaseTokenRepository` keys every row on whatever that method returns, matching on that value
 * plus the token hash with nothing recording which broker minted it. So if it returned the address,
 * a token minted for one store's customer would be accepted for another store's customer with the
 * same address — and, as the model's own docblock records, for the platform USER with that address,
 * which was a full platform takeover.
 *
 * These tests pin that property against the multi-store state the new policy makes common.
 */
class SameEmailAcrossStoresRecoveryTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private Customer $inA;
    private Customer $inB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = $this->makeStore($this->makeMerchant());
        $this->storeB = $this->makeStore($this->makeMerchant());

        $this->inA = $this->customerIn($this->storeA, 'InA');
        $this->inB = $this->customerIn($this->storeB, 'InB');
    }

    private function customerIn(Store $store, string $first): Customer
    {
        return Customer::forceCreate([
            'first_name' => $first, 'last_name' => 'Person',
            'email' => 'shared@example.test', 'password' => bcrypt('the-' . $first . '-password'),
            'store_id' => $store->id, 'created_by' => $store->created_by,
            'status' => 1,
        ]);
    }

    private static function rows()
    {
        return Customer::withoutGlobalScopes();
    }

    // =============================================================================================
    //  THE PROPERTY THAT KEEPS RECOVERY SAFE
    // =============================================================================================

    public function test_the_reset_key_is_the_account_id_and_not_the_address(): void
    {
        $keyA = $this->inA->getEmailForPasswordReset();
        $keyB = $this->inB->getEmailForPasswordReset();

        $this->assertNotSame('shared@example.test', $keyA,
            'getEmailForPasswordReset() returns the ADDRESS. Two customers in different stores '
            . 'share it, and config/auth.php declares two brokers over one '
            . 'password_reset_tokens table keyed on exactly this value — so a token minted for '
            . "one account would be accepted for the other, and for the platform USER with that "
            . 'address. That is the takeover the model docblock records');

        $this->assertNotSame($keyA, $keyB,
            'the two accounts produce the SAME reset key, so a token minted for one is a token for '
            . 'the other');

        $this->assertSame('customer:' . $this->inA->id, $keyA, 'the key is not the account id');
        $this->assertSame('customer:' . $this->inB->id, $keyB, 'the key is not the account id');
    }

    /**
     * Driven through the real broker, not the model: the token row is what matters, and this is
     * what `CustomerLoginController` mints.
     */
    public function test_a_token_minted_for_one_stores_customer_is_not_valid_for_the_others(): void
    {
        $broker = Password::broker('customers');

        $tokenForA = $broker->createToken($this->inA);

        $this->assertTrue($broker->tokenExists($this->inA, $tokenForA),
            'premise: the token is not valid for the account it was minted for, so nothing below '
            . 'is measured');

        $this->assertFalse($broker->tokenExists($this->inB, $tokenForA),
            "a reset token minted for store A's customer is ACCEPTED for store B's customer with "
            . 'the same address. The two accounts are distinct people; this would let either take '
            . "over the other's account through the recovery flow");
    }

    /**
     * And the reverse direction, because a one-way check can pass on an asymmetry.
     */
    public function test_the_same_holds_in_the_other_direction(): void
    {
        $broker = Password::broker('customers');

        $tokenForB = $broker->createToken($this->inB);

        $this->assertTrue($broker->tokenExists($this->inB, $tokenForB), 'premise: token valid for B');
        $this->assertFalse($broker->tokenExists($this->inA, $tokenForB),
            "a token minted for store B's customer is accepted for store A's");
    }

    /**
     * Two outstanding resets at once — one per store — must stay independent. Laravel's repository
     * keeps ONE row per key, so if the key were the address the second mint would overwrite the
     * first and silently invalidate the other person's link.
     */
    public function test_two_simultaneous_resets_one_per_store_do_not_overwrite_each_other(): void
    {
        $broker = Password::broker('customers');

        $tokenForA = $broker->createToken($this->inA);
        $tokenForB = $broker->createToken($this->inB);

        $this->assertTrue($broker->tokenExists($this->inA, $tokenForA),
            "minting store B's reset invalidated store A's outstanding link — the repository keeps "
            . 'one row per key, so the two accounts are sharing a key');
        $this->assertTrue($broker->tokenExists($this->inB, $tokenForB), 'B\'s own token is invalid');

        $this->assertNotSame($tokenForA, $tokenForB, 'the two accounts received the same token');
    }

    // =============================================================================================
    //  ARCHIVED ACCOUNTS
    // =============================================================================================

    /**
     * An archived account must not be recoverable. The broker resolves the account through the
     * provider, which is soft-delete-scoped, so this is a property of the scope rather than of the
     * token — asserted because the new policy makes archive-then-re-add ordinary, and a recoverable
     * archived account would be a live credential on a row the merchant believes is gone.
     */
    public function test_an_archived_account_cannot_be_resolved_for_recovery(): void
    {
        $this->inA->delete();

        $this->assertNotNull(self::rows()->find($this->inA->id)->deleted_at,
            'premise: the account is not archived');

        $resolved = Customer::withoutGlobalScope(\App\Scopes\StoreScope::class)
            ->where('email', 'shared@example.test')
            ->where('store_id', $this->storeA->id)
            ->first();

        $this->assertNull($resolved,
            "store A's ARCHIVED account is still resolvable by address, so the recovery flow "
            . 'could mint a token for a row the merchant believes is gone');

        // POSITIVE CONTROL: store B's live account with the same address is still resolvable, so
        // the null above is the soft-delete scope and not an empty table.
        $this->assertNotNull(
            Customer::withoutGlobalScope(\App\Scopes\StoreScope::class)
                ->where('email', 'shared@example.test')
                ->where('store_id', $this->storeB->id)
                ->first(),
            "positive control: store B's live account is resolvable");
    }

    /**
     * Archiving one store's account must not disturb the other's outstanding reset.
     */
    public function test_archiving_one_account_leaves_the_others_reset_intact(): void
    {
        $broker = Password::broker('customers');
        $tokenForB = $broker->createToken($this->inB);

        $this->inA->delete();

        $this->assertTrue($broker->tokenExists($this->inB, $tokenForB),
            "archiving store A's customer invalidated store B's outstanding reset link");
    }

    // =============================================================================================
    //  ACCOUNT SELECTION — the login path, across stores
    // =============================================================================================

    /**
     * The two accounts have DIFFERENT passwords, so this measures selection rather than
     * coincidence: each store's credentials must authenticate only that store's account.
     */
    public function test_each_stores_credentials_resolve_only_that_stores_account(): void
    {
        foreach ([[$this->storeA, $this->inA, 'InA'], [$this->storeB, $this->inB, 'InB']] as [$store, $expected, $label]) {
            $resolved = Customer::withoutGlobalScope(\App\Scopes\StoreScope::class)
                ->where('email', 'shared@example.test')
                ->where('store_id', $store->id)
                ->first();

            $this->assertNotNull($resolved, "premise: no account resolved for {$label}");
            $this->assertSame($expected->id, (int) $resolved->id,
                "store {$label}'s lookup resolved the other store's account");
            $this->assertTrue(\Hash::check('the-' . $label . '-password', $resolved->password),
                "the account resolved for {$label} does not hold {$label}'s own password, so the "
                . 'two accounts are being confused');
        }
    }
}
