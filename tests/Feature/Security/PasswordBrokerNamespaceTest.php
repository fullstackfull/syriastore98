<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE STOREFRONT AND ADMIN PASSWORD BROKERS SHARED ONE TOKEN TABLE, so a customer's reset token
 * reset a platform USER.
 *
 * config/auth.php:108-121 declares both brokers over `password_reset_tokens`:
 *
 *     'users'     => ['provider' => 'users',     'table' => 'password_reset_tokens', ...]
 *     'customers' => ['provider' => 'customers', 'table' => 'password_reset_tokens', ...]
 *
 * Laravel's DatabaseTokenRepository keys each row on $user->getEmailForPasswordReset() and
 * exists() matches that value plus the token hash — nothing records which broker minted the row.
 * While Customer inherited the trait's implementation (return $this->email), a row minted for a
 * customer with address E was accepted verbatim by the `users` broker for the User with address E.
 *
 * THE FULL CHAIN, every link verified in the source:
 *   1. a merchant sets their own store's MAIL_HOST/USERNAME/PASSWORD (SettingController, route
 *      routes/web.php:778) and the customer reset mail goes out through it
 *      (Customer\Auth\CustomerLoginController:826-837);
 *   2. customer registration rejects a duplicate address only WITHIN one store and never consults
 *      `users` (CustomerLoginController:728-737), so the merchant registers a customer in their own
 *      store carrying the platform operator's address;
 *   3. POST {storeB}/forgot-password mints the row and mails the token through the merchant's relay
 *      (CustomerLoginController:845-853);
 *   4. POST /reset-password (routes/auth.php:36 -> Auth\NewPasswordController:41) calls
 *      Password::reset() on the DEFAULT broker — `users` (config/auth.php:18) — which finds the row
 *      by address alone and resets the OPERATOR.
 *
 * Step 1 is what lets the merchant READ the token; the tests below do not need it, because they
 * capture the token directly and so demonstrate the crossing itself.
 *
 * CLOSED by Customer::getEmailForPasswordReset() returning a namespaced key instead of an address.
 * See that method for why this needs no migration and changes no reset link.
 */
class PasswordBrokerNamespaceTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function sharedAddress(): string
    {
        return 'shared-' . uniqid() . '@example.test';
    }

    private function operatorWith(string $email): User
    {
        return $this->makePlatformAdmin([
            'email' => $email,
            'password' => bcrypt('operator-password-not-real'),
        ]);
    }

    private function customerWith(string $email, $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'broker',
            'email' => $email,
            'password' => bcrypt('customer-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /** The SMTP settings forgotPassword() reads before it mints. */
    private function mailSettings($store, User $merchant): void
    {
        foreach (['MAIL_DRIVER' => 'log', 'MAIL_HOST' => 'localhost', 'MAIL_PORT' => '25',
                  'MAIL_ENCRYPTION' => 'tls', 'MAIL_USERNAME' => 'u', 'MAIL_PASSWORD' => 'p',
                  'MAIL_FROM_ADDRESS' => 'noreply@example.test', 'MAIL_FROM_NAME' => 'S'] as $n => $v) {
            Setting::forceCreate([
                'name' => $n, 'value' => $v, 'store_id' => $store->id, 'created_by' => $merchant->id,
            ]);
        }
    }

    /**
     * Mint a token through the customers broker exactly as forgotPassword() does, store scope
     * included.
     */
    private function mintCustomerToken(Customer $customer, $store = null): string
    {
        $captured = null;

        $credentials = ['email' => $customer->email];

        if ($store !== null) {
            $credentials['store_id'] = $store->id;
        } else {
            $credentials['store_id'] = $customer->store_id;
        }

        Password::broker('customers')->sendResetLink(
            $credentials,
            function ($user, $token) use (&$captured) {
                $captured = $token;
            }
        );

        $this->assertNotNull($captured, 'The customers broker minted no token.');

        return $captured;
    }

    // ===================================================================================
    // THE CROSSING
    // ===================================================================================

    public function test_a_customers_reset_token_cannot_reset_the_platform_operator(): void
    {
        $email = $this->sharedAddress();
        $operator = $this->operatorWith($email);

        [, $store] = $this->makeTenant();
        $customer = $this->customerWith($email, $store);

        $token = $this->mintCustomerToken($customer);

        // The admin reset endpoint: routes/auth.php:36 -> Auth\NewPasswordController, which calls
        // Password::reset() on the DEFAULT (`users`) broker.
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'attacker-chosen-value-b1',
            'password_confirmation' => 'attacker-chosen-value-b1',
        ]);

        $this->assertFalse(
            Hash::check('attacker-chosen-value-b1', $operator->fresh()->password),
            'A reset token minted by the STOREFRONT broker set the PLATFORM OPERATOR\'s password. '
            . 'That is a full platform takeover from a merchant account.'
        );

        $this->assertTrue(
            Hash::check('operator-password-not-real', $operator->fresh()->password),
            'The operator\'s password was altered.'
        );
    }

    public function test_a_users_reset_token_cannot_reset_a_customer(): void
    {
        $email = $this->sharedAddress();
        $this->operatorWith($email);

        [, $store] = $this->makeTenant();
        $customer = $this->customerWith($email, $store);

        $captured = null;

        Password::broker('users')->sendResetLink(
            ['email' => $email],
            function ($user, $token) use (&$captured) {
                $captured = $token;
            }
        );

        $this->assertNotNull($captured, 'The users broker minted no token.');

        $status = Password::broker('customers')->reset(
            [
                'email' => $email,
                'token' => $captured,
                'password' => 'attacker-chosen-value-b2',
                'password_confirmation' => 'attacker-chosen-value-b2',
            ],
            function ($user) {
                $user->forceFill(['password' => Hash::make('attacker-chosen-value-b2')])->save();
            }
        );

        $this->assertNotSame(Password::PASSWORD_RESET, $status,
            'A token minted by the ADMIN broker was accepted by the customers broker.');

        $this->assertFalse(Hash::check('attacker-chosen-value-b2', $customer->fresh()->password),
            'An admin-broker token reset a customer\'s password.');
    }

    /**
     * DatabaseTokenRepository::create() deletes by the same key it inserts under. While both
     * brokers keyed on the address, a customer requesting a reset DELETED the operator's
     * outstanding row — a cross-namespace denial of service on account recovery, and certain
     * rather than merely possible.
     */
    public function test_a_customer_request_does_not_invalidate_an_operators_outstanding_reset(): void
    {
        $email = $this->sharedAddress();
        $operator = $this->operatorWith($email);

        [, $store] = $this->makeTenant();
        $customer = $this->customerWith($email, $store);

        $operatorToken = null;
        Password::broker('users')->sendResetLink(['email' => $email], function ($u, $t) use (&$operatorToken) {
            $operatorToken = $t;
        });
        $this->assertNotNull($operatorToken);

        // The customer now asks for their own reset.
        $this->mintCustomerToken($customer);

        // The operator's token must still work.
        $status = Password::broker('users')->reset(
            [
                'email' => $email,
                'token' => $operatorToken,
                'password' => 'operator-chosen-value-b3',
                'password_confirmation' => 'operator-chosen-value-b3',
            ],
            function ($user) {
                $user->forceFill(['password' => Hash::make('operator-chosen-value-b3')])->save();
            }
        );

        $this->assertSame(Password::PASSWORD_RESET, $status,
            'A customer\'s reset request destroyed the operator\'s outstanding reset token, so a '
            . 'merchant could deny an operator account recovery at will.');

        $this->assertTrue(Hash::check('operator-chosen-value-b3', $operator->fresh()->password));
    }

    // ===================================================================================
    // THE SECOND CROSSING: the broker resolved the address across every tenant
    // ===================================================================================

    /**
     * THE BROKER RESOLVED THE ACCOUNT FROM THE ADDRESS ALONE.
     * EloquentUserProvider::retrieveByCredentials ANDs every non-password credential into the
     * query, so ['email' => E] matched across all tenants and ->first() returned the LOWEST-ID
     * customer holding E. The existence check in forgotPassword() is store-scoped, but the broker
     * call beside it was not.
     *
     * Measured before the fix: a reset requested through STORE B's storefront minted the token for
     * the STORE A customer. Combined with the per-store SMTP a merchant controls (:826-837), that
     * is a merchant taking over another tenant's customer.
     */
    public function test_a_reset_requested_through_one_storefront_resolves_that_stores_customer(): void
    {
        $email = $this->sharedAddress();

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->mailSettings($storeA, $merchantA);
        $this->mailSettings($storeB, $merchantB);

        $customerA = $this->customerWith($email, $storeA);
        $customerB = $this->customerWith($email, $storeB);

        // Lowest id first, so an unscoped ->first() would pick A.
        $this->assertLessThan($customerB->id, $customerA->id);

        // Drive the REAL route for store B.
        $this->post('/' . $storeB->slug . '/forgot-password', ['email' => $email]);

        $keys = DB::table('password_reset_tokens')->pluck('email')->all();

        $this->assertSame(['customer:' . $customerB->id], $keys,
            "A reset requested through store B's storefront minted the token for a customer of "
            . 'another store. With the per-store SMTP a merchant controls, that is a cross-tenant '
            . 'account takeover.');
    }

    /**
     * HARDENING, LABELLED AS SUCH. A token must be spent through the storefront it was issued for.
     *
     * Removing the accept-side store scoping does NOT reopen a hole — the namespaced key from
     * FIX 1 already refuses it, because tokenExists() looks the row up under
     * `customer:{resolved id}` and the resolved customer would be the wrong one. Measured: the
     * sibling test below still passes with the accept-side scoping reverted.
     *
     * What it DOES fix is a correctness wart: with the accept side unscoped, a link issued for
     * store A's customer, submitted through store B's URL, succeeded and reset store A's customer
     * — the right account via the wrong storefront, while the handler's own existence check at
     * :890 had just resolved store B's customer. The holder of that token is authorised for that
     * account, so this is not a breach; it is a handler that ignores its own tenant parameter,
     * which is how the next defect gets in.
     */
    public function test_a_token_issued_for_one_storefront_is_not_accepted_through_another(): void
    {
        $email = $this->sharedAddress();

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();
        $this->mailSettings($storeA, $merchantA);
        $this->mailSettings($storeB, $merchantB);

        $customerA = $this->customerWith($email, $storeA);
        $this->customerWith($email, $storeB);

        // A token legitimately issued for store A's customer.
        $token = $this->mintCustomerToken($customerA, $storeA);

        // Submitted through store B's slug.
        $this->post('/' . $storeB->slug . '/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'Wrong-Storefront-Value-1!',
            'password_confirmation' => 'Wrong-Storefront-Value-1!',
        ]);

        $this->assertFalse(Hash::check('Wrong-Storefront-Value-1!', $customerA->fresh()->password),
            "A reset link issued for store A's customer was spent through store B's URL. The "
            . 'handler resolves the store and must use it.');
    }

    public function test_a_reset_cannot_be_completed_against_another_stores_customer(): void
    {
        $email = $this->sharedAddress();

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();
        $this->mailSettings($storeA, $merchantA);
        $this->mailSettings($storeB, $merchantB);

        $customerA = $this->customerWith($email, $storeA);
        $customerB = $this->customerWith($email, $storeB);

        // A token legitimately issued for store B's customer.
        $token = $this->mintCustomerToken($customerB, $storeB);

        // Presented through store A's slug, where the same address also exists.
        $this->post('/' . $storeA->slug . '/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'Cross-Tenant-Value-1!',
            'password_confirmation' => 'Cross-Tenant-Value-1!',
        ]);

        $this->assertFalse(Hash::check('Cross-Tenant-Value-1!', $customerA->fresh()->password),
            "A token issued for store B's customer reset store A's customer.");
    }

    public function test_the_owning_customer_can_still_complete_a_reset_through_their_own_storefront(): void
    {
        $email = $this->sharedAddress();

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();
        $this->mailSettings($storeA, $merchantA);
        $this->mailSettings($storeB, $merchantB);

        $this->customerWith($email, $storeA);
        $customerB = $this->customerWith($email, $storeB);

        $token = $this->mintCustomerToken($customerB, $storeB);

        $this->post('/' . $storeB->slug . '/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'Owner-Chosen-Value-1!',
            'password_confirmation' => 'Owner-Chosen-Value-1!',
        ]);

        $this->assertTrue(Hash::check('Owner-Chosen-Value-1!', $customerB->fresh()->password),
            'The owning customer can no longer reset their own password through their own '
            . 'storefront; the scoping broke the journey it was meant to protect.');
    }

    // ===================================================================================
    // AND THE LEGITIMATE FLOW IS UNCHANGED
    // ===================================================================================

    public function test_a_customer_can_still_complete_a_broker_reset(): void
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['MAIL_DRIVER' => 'log', 'MAIL_HOST' => 'localhost', 'MAIL_PORT' => '25',
                  'MAIL_ENCRYPTION' => 'tls', 'MAIL_USERNAME' => 'u', 'MAIL_PASSWORD' => 'p',
                  'MAIL_FROM_ADDRESS' => 'noreply@example.test', 'MAIL_FROM_NAME' => 'S'] as $n => $v) {
            Setting::forceCreate(['name' => $n, 'value' => $v, 'store_id' => $store->id, 'created_by' => $merchant->id]);
        }

        $customer = $this->customerWith('legit-' . uniqid() . '@example.test', $store);
        $token = $this->mintCustomerToken($customer);

        $status = Password::broker('customers')->reset(
            [
                'email' => $customer->email,
                'token' => $token,
                'password' => 'owner-chosen-value-b5',
                'password_confirmation' => 'owner-chosen-value-b5',
            ],
            function ($user) {
                $user->forceFill(['password' => Hash::make('owner-chosen-value-b5')])->save();
            }
        );

        $this->assertSame(Password::PASSWORD_RESET, $status,
            'The customers broker can no longer complete a legitimate reset; the namespacing broke '
            . 'the flow it was meant to protect.');

        $this->assertTrue(Hash::check('owner-chosen-value-b5', $customer->fresh()->password));
    }

    public function test_the_reset_link_a_customer_receives_still_carries_their_real_address(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerWith('link-' . uniqid() . '@example.test', $store);
        $customer->storeSlug = $store->slug;

        $customer->sendPasswordResetNotification('token-value-for-the-link');

        Notification::assertSentTo($customer, \App\Notifications\ResetPasswordNotification::class,
            function ($notification) use ($customer) {
                $this->assertStringContainsString('email=' . $customer->email, $notification->url,
                    'The reset URL no longer carries the customer\'s real address, so the link in '
                    . 'their mailbox would not work.');
                $this->assertStringNotContainsString('customer:', $notification->url,
                    'The internal token key leaked into the reset URL.');

                return true;
            });
    }

    public function test_the_token_row_is_keyed_on_something_that_is_not_an_address(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerWith('keyed-' . uniqid() . '@example.test', $store);

        $this->mintCustomerToken($customer);

        $keys = DB::table('password_reset_tokens')->pluck('email')->all();

        $this->assertSame(['customer:' . $customer->id], $keys,
            'The customers broker still keys its rows on an address, so the `users` broker can '
            . 'find them.');

        $this->assertStringNotContainsString('@', $keys[0],
            'The key is still parsable as an email address.');
    }

    public function test_the_operator_broker_still_keys_on_the_address(): void
    {
        // The users side is deliberately untouched, so its behaviour must be unchanged.
        $email = $this->sharedAddress();
        $this->operatorWith($email);

        Password::broker('users')->sendResetLink(['email' => $email], function () {});

        $this->assertSame([$email], DB::table('password_reset_tokens')->pluck('email')->all(),
            'The users broker\'s key changed; this package was not supposed to touch it.');
    }
}
