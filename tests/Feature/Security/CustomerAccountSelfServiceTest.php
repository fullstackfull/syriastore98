<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Security\SecurityTestCase;

/**
 * POST {storeSlug?}/my-account — AccountProfileController::store(). THE THIRD INSTANCE of one
 * pattern, after Api\ApiController::change_password and
 * Api\DeliveryBoyApiController::resetPassword, and the worst of the three because it took the
 * E-MAIL as well as the password.
 *
 * The route carries `auth:customers` (routes/customer.php:202-204) — authentication, not
 * authorization. The handler then:
 *
 *     $customer = Customer::Where('id', $request->customer_id)->first();   // request input
 *     if ($request->old_password && !Hash::check($request->old_password, $customer->password))
 *         return ... error ...                                            // SKIPPED when absent
 *     $customer->email    = $request->email;
 *     if ($request->new_password) $customer->password = Hash::make($request->new_password);
 *
 * Three failures, each sufficient alone: the target came from the body with no store predicate and
 * no comparison to the session; the old-password check was guarded by the presence of the very
 * field it validates, so omitting it skipped verification; and the resolved $store was never read.
 *
 * WHAT WAS MEASURED, rather than argued. Every test below was run against the ORIGINAL handler.
 * Four of them failed — those are the demonstrated defects:
 *
 *   - any signed-in shopper took over ANY OTHER CUSTOMER IN THEIR OWN STORE, by numeric id;
 *   - the posted `customer_id` was trusted, so the account edited was not the session's;
 *   - a password could be set with NO current-password check, by omitting the field;
 *   - a shopper could take over another shopper's E-MAIL in their store — the address every
 *     recovery flow trusts, which makes the takeover permanent.
 *
 * The CROSS-TENANT case did NOT reproduce, and the honest reason matters: `Customer` is on
 * config/tenancy.php's `scoped_models`, so when the slug resolves a tenant the global scope
 * narrows `Customer::where('id', ...)` to this store and the other store's victim is simply not
 * found. The handler itself performed no check at all — the isolation came from the scope, by
 * accident, which is the same situation found earlier in Api\ApiController::change_password. The
 * two cross-store tests below are kept as regressions and are labelled as pinning behaviour the
 * scope also provides, NOT as closed holes.
 *
 * `Validator` is imported at AccountProfileController:39, so the handler really did execute —
 * checked, because a handler that 500s on every call is not a vulnerability.
 */
class CustomerAccountSelfServiceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $label, string $password = 'original-password-not-real'): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'acct-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt($password),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /** Exactly the body the account form posts (account.blade.php:162-211). */
    private function submit($store, Customer $actor, array $body)
    {
        return $this->actingAs($actor, 'customers')
            ->post('/' . $store->slug . '/my-account', $body);
    }

    private function assertUnchanged(Customer $victim, string $attemptedPassword, ?string $attemptedEmail, string $message): void
    {
        $fresh = $victim->fresh();

        $this->assertFalse(Hash::check($attemptedPassword, (string) $fresh->password), $message);

        if ($attemptedEmail !== null) {
            $this->assertNotSame($attemptedEmail, $fresh->email,
                $message . ' (the e-mail was taken over)');
        }
    }

    // ===================================================================================
    // THE TAKEOVER
    // ===================================================================================

    public function test_one_shopper_cannot_take_over_another_in_the_same_store(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a1-victim');
        $attacker = $this->customerIn($store, 'a1-attacker');

        $this->submit($store, $attacker, [
            'customer_id' => $victim->id,
            'first_name' => 'Taken',
            'email' => 'attacker-owns-this-a1@example.test',
            'new_password' => 'attacker-chosen-value-a1',
            'new_password_confirmation' => 'attacker-chosen-value-a1',
            // old_password deliberately ABSENT — that is failure 2.
        ]);

        $this->assertUnchanged($victim, 'attacker-chosen-value-a1', 'attacker-owns-this-a1@example.test',
            "One shopper's session set another shopper's password and e-mail.");
    }

    /**
     * NOT A DEMONSTRATED HOLE — a regression guard. Measured: this test passes against the
     * original handler too, because the global tenant scope narrows Customer::where('id', ...) to
     * the slug's store once a tenant resolves. The handler contributed nothing. It does now, which
     * is the point: the check below does not depend on a scope that is open whenever no tenant
     * resolves (config/tenancy.php 'null_tenant' => 'open').
     */
    public function test_a_shopper_cannot_take_over_a_customer_in_another_store(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'a2-victim');
        $attacker = $this->customerIn($storeB, 'a2-attacker');

        $this->submit($storeB, $attacker, [
            'customer_id' => $victim->id,
            'first_name' => 'Taken',
            'email' => 'attacker-owns-this-a2@example.test',
            'new_password' => 'attacker-chosen-value-a2',
            'new_password_confirmation' => 'attacker-chosen-value-a2',
        ]);

        $this->assertUnchanged($victim, 'attacker-chosen-value-a2', 'attacker-owns-this-a2@example.test',
            'A shopper of store B took over a customer of store A — a cross-tenant account '
            . 'takeover from an ordinary customer account.');
    }

    /**
     * NOT A DEMONSTRATED HOLE either — measured as passing against the original handler. The
     * route's slug is optional, so this probes the case where the tenant parameter is simply
     * absent; the takeover still did not reproduce. Kept because an optional tenant parameter is
     * worth a standing guard.
     */
    public function test_the_slug_can_be_omitted_and_still_grants_nothing(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'a3-victim');
        $attacker = $this->customerIn($storeB, 'a3-attacker');

        // {storeSlug?} is optional, so the tenant parameter can simply be left out.
        $this->actingAs($attacker, 'customers')->post('/my-account', [
            'customer_id' => $victim->id,
            'first_name' => 'Taken',
            'email' => 'attacker-owns-this-a3@example.test',
            'new_password' => 'attacker-chosen-value-a3',
            'new_password_confirmation' => 'attacker-chosen-value-a3',
        ]);

        $this->assertUnchanged($victim, 'attacker-chosen-value-a3', 'attacker-owns-this-a3@example.test',
            'Omitting the optional slug bypassed the store check.');
    }

    /** Pre-existing: `auth:customers` already refused this. A standing guard on that middleware. */
    public function test_an_anonymous_caller_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a4-victim');

        $this->post('/' . $store->slug . '/my-account', [
            'customer_id' => $victim->id,
            'first_name' => 'Taken',
            'email' => 'anon-owns-this-a4@example.test',
            'new_password' => 'anon-chosen-value-a4',
            'new_password_confirmation' => 'anon-chosen-value-a4',
        ]);

        $this->assertUnchanged($victim, 'anon-chosen-value-a4', 'anon-owns-this-a4@example.test',
            'An anonymous caller changed a customer account.');
    }

    // ===================================================================================
    // THE OLD-PASSWORD CHECK IS NO LONGER OPTIONAL
    // ===================================================================================

    public function test_a_shopper_cannot_change_their_own_password_without_the_current_one(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'b1');

        $this->submit($store, $customer, [
            'customer_id' => $customer->id,
            'first_name' => 'Shopper',
            'email' => $customer->email,
            'new_password' => 'self-chosen-value-b1',
            'new_password_confirmation' => 'self-chosen-value-b1',
            // No old_password. Previously this skipped the check entirely.
        ]);

        $this->assertFalse(Hash::check('self-chosen-value-b1', $customer->fresh()->password),
            'The password changed with no current-password check, so a stolen session is a '
            . 'permanent takeover.');
    }

    /** Pre-existing behaviour when the field WAS sent; the defect was omitting it, above. */
    public function test_a_wrong_current_password_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'b2');

        $this->submit($store, $customer, [
            'customer_id' => $customer->id,
            'first_name' => 'Shopper',
            'email' => $customer->email,
            'old_password' => 'not-the-current-password',
            'new_password' => 'self-chosen-value-b2',
            'new_password_confirmation' => 'self-chosen-value-b2',
        ]);

        $this->assertFalse(Hash::check('self-chosen-value-b2', $customer->fresh()->password),
            'A wrong current password was accepted.');
    }

    // ===================================================================================
    // AND THE LEGITIMATE JOURNEYS STILL WORK
    // ===================================================================================

    public function test_a_shopper_can_change_their_own_password_with_the_current_one(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'c1', 'known-current-password');

        $this->submit($store, $customer, [
            'customer_id' => $customer->id,
            'first_name' => 'Shopper',
            'email' => $customer->email,
            'old_password' => 'known-current-password',
            'new_password' => 'self-chosen-value-c1',
            'new_password_confirmation' => 'self-chosen-value-c1',
        ]);

        $this->assertTrue(Hash::check('self-chosen-value-c1', $customer->fresh()->password),
            'A shopper can no longer change their own password; the fix broke the journey the '
            . 'account form depends on.');
    }

    public function test_a_shopper_can_save_their_profile_without_touching_the_password(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'c2', 'known-current-password');

        // The common case: the password boxes are left empty.
        $this->submit($store, $customer, [
            'customer_id' => $customer->id,
            'first_name' => 'Renamed',
            'last_name' => 'Person',
            'email' => $customer->email,
            'mobile' => '0100000000',
            'old_password' => '',
            'new_password' => '',
            'new_password_confirmation' => '',
        ]);

        $fresh = $customer->fresh();

        $this->assertSame('Renamed', $fresh->first_name,
            'A profile-only save no longer works.');
        $this->assertSame('0100000000', $fresh->mobile);
        $this->assertTrue(Hash::check('known-current-password', (string) $fresh->password),
            'A profile-only save altered the password.');
    }

    public function test_the_hidden_customer_id_the_form_posts_is_simply_ignored(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'c3', 'known-current-password');
        $other = $this->customerIn($store, 'c3-other');

        // The form posts the signed-in customer's own id. Even a tampered value must only ever
        // affect the session's own account.
        $this->submit($store, $customer, [
            'customer_id' => $other->id,
            'first_name' => 'OnlyMine',
            'email' => $customer->email,
            'old_password' => 'known-current-password',
            'new_password' => 'self-chosen-value-c3',
            'new_password_confirmation' => 'self-chosen-value-c3',
        ]);

        $this->assertSame('OnlyMine', $customer->fresh()->first_name,
            "The session's own account was not the one edited.");
        $this->assertTrue(Hash::check('self-chosen-value-c3', $customer->fresh()->password));

        $this->assertSame('Shopper', $other->fresh()->first_name,
            'The tampered customer_id still reached another account.');
        $this->assertTrue(Hash::check('original-password-not-real', (string) $other->fresh()->password));
    }

    // ===================================================================================
    // THE E-MAIL IS THE ADDRESS EVERY RECOVERY FLOW TRUSTS
    // ===================================================================================

    public function test_a_shopper_cannot_take_over_another_shoppers_address_in_their_store(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'd1-victim');
        $attacker = $this->customerIn($store, 'd1-attacker', 'known-current-password');

        $this->submit($store, $attacker, [
            'customer_id' => $attacker->id,
            'first_name' => 'Shopper',
            'email' => $victim->email,
            'old_password' => 'known-current-password',
        ]);

        $this->assertNotSame($victim->email, $attacker->fresh()->email,
            "A shopper set their own address to another shopper's in the same store. Two rows "
            . 'sharing an address in one store break login and recovery for both — registration '
            . 'forbids it (CustomerLoginController:728-737) and this handler must too.');
    }

    public function test_profile_update_also_refuses_a_duplicate_address(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'd2-victim');
        $attacker = $this->customerIn($store, 'd2-attacker');

        $this->actingAs($attacker, 'customers')->post('/' . $store->slug . '/profile-update', [
            'first_name' => 'Shopper',
            'email' => $victim->email,
        ]);

        $this->assertNotSame($victim->email, $attacker->fresh()->email,
            'profile-update let a shopper take over another shopper\'s address.');
    }

    public function test_a_shopper_can_still_change_their_own_address(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd3', 'known-current-password');

        $new = 'changed-d3-' . uniqid() . '@example.test';

        $this->submit($store, $customer, [
            'customer_id' => $customer->id,
            'first_name' => 'Shopper',
            'email' => $new,
            'old_password' => 'known-current-password',
        ]);

        $this->assertSame($new, $customer->fresh()->email,
            'A shopper can no longer change their own address; the uniqueness rule must ignore '
            . 'their own row.');
    }

    public function test_keeping_your_own_address_unchanged_is_not_a_duplicate(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd4', 'known-current-password');

        $this->submit($store, $customer, [
            'customer_id' => $customer->id,
            'first_name' => 'Renamed',
            'email' => $customer->email,
            'old_password' => 'known-current-password',
        ]);

        $this->assertSame('Renamed', $customer->fresh()->first_name,
            'Submitting your own unchanged address was rejected as a duplicate, which would break '
            . 'every profile save.');
    }

    public function test_the_same_address_in_a_different_store_is_not_a_duplicate(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $elsewhere = $this->customerIn($storeA, 'd5-elsewhere');
        $customer = $this->customerIn($storeB, 'd5', 'known-current-password');

        // Tenancy means an address may legitimately exist once per store.
        $this->submit($storeB, $customer, [
            'customer_id' => $customer->id,
            'first_name' => 'Shopper',
            'email' => $elsewhere->email,
            'old_password' => 'known-current-password',
        ]);

        $this->assertSame($elsewhere->email, $customer->fresh()->email,
            'The uniqueness rule is not store-scoped, so it refuses an address that is free in '
            . 'this tenant.');
    }

    /**
     * The original code dereferenced a null $customer when the id did not resolve. Measured: this
     * test does not reproduce a 500, because the tenant scope made the lookup miss in a way the
     * original's own null handling survived. Kept as a guard rather than claimed as a fix.
     */
    public function test_an_unknown_customer_id_is_not_a_server_error(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'e1', 'known-current-password');

        // The id is now ignored entirely, so this can only ever edit the session's own account.
        $response = $this->submit($store, $customer, [
            'customer_id' => 999999,
            'first_name' => 'Shopper',
            'email' => $customer->email,
            'old_password' => 'known-current-password',
        ]);

        $this->assertNotSame(500, $response->status(),
            'An unknown customer_id is still an unguarded dereference.');
    }
}
