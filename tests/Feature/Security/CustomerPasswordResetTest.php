<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerPasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE PASSWORD-RECOVERY EMERGENCY. Two confirmed defects, both reproduced against these tests
 * before the fix.
 *
 * 1. POST api/{slug}/forgot-password-save carried ['APILog','throttle:auth'] and NO
 *    authentication. It found a **User** -- the ADMIN model -- by email with no store predicate
 *    and wrote bcrypt($request->password), never reading the OTP. One anonymous request set any
 *    account's password, the platform operator included.
 * 2. POST api/{slug}/forgot-password-verify-otp replaced the customer's password with the literal
 *    'hello@1234', committed to this repository, and mailed it. The value being fixed and public,
 *    anyone who knew a target had used recovery could sign in WITHOUT the OTP.
 *
 * The two handlers did not address the same table -- OTP on `customers`, password on `users` -- so
 * the flow could not serve its purpose either.
 *
 * These tests drive the real routes through the real middleware stack. Mail is faked so no message
 * leaves the test, and no credential is ever asserted by value.
 */
class CustomerPasswordResetTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

    /** A store with the SMTP settings these handlers read, plus a customer in it. */
    private function storeWithCustomer(string $label): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach ([
            'MAIL_DRIVER' => 'log', 'MAIL_HOST' => 'localhost', 'MAIL_PORT' => '25',
            'MAIL_ENCRYPTION' => 'tls', 'MAIL_USERNAME' => 'u', 'MAIL_PASSWORD' => 'p',
            'MAIL_FROM_ADDRESS' => 'noreply@example.test', 'MAIL_FROM_NAME' => 'Store',
        ] as $name => $value) {
            // settings.created_by is NOT NULL with no default.
            Setting::forceCreate([
                'name' => $name, 'value' => $value,
                'store_id' => $store->id, 'created_by' => $merchant->id,
            ]);
        }

        $customer = Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => $label,
            'email' => 'reset-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $store->id,
            'status' => 1,
            'register_type' => 'email',
        ]);

        return [$store, $customer];
    }

    /**
     * Drive send-otp and recover the issued OTP FROM THE EMAIL, which is the only place the
     * plaintext legitimately exists.
     *
     * THIS IS DELIBERATE AND IT TESTS TWO THINGS AT ONCE. It proves the mail is actually sent
     * (brief item: email delivery) and it proves the OTP is NOT recoverable from storage: the
     * cache holds only a bcrypt hash, so a test that needed the plaintext has nowhere else to get
     * it. An earlier version of this helper brute-forced the 4-digit space against that hash --
     * correct, but 10,000 bcrypt comparisons per test timed the suite out, which is itself a
     * fair measure of how much the attempt limit is worth.
     */
    private function completeSendOtp($store, Customer $customer): string
    {
        $this->postJson('/api/' . $store->slug . '/forgot-password-send-otp', [
            'email' => $customer->email,
        ])->assertStatus(200)->assertJsonPath('status', 1);

        $captured = null;

        Mail::assertSent(\App\Mail\SendOTP::class, function ($mail) use ($customer, &$captured) {
            if (! $mail->hasTo($customer->email)) {
                return false;
            }

            $captured = (string) $mail->OTP;

            return true;
        });

        $this->assertNotNull($captured, 'send-otp did not email an OTP to the customer.');

        // And the stored form is a hash of exactly that value, never the value itself.
        $state = Cache::get("pwreset:otp:{$store->id}:{$customer->id}");
        $this->assertIsArray($state, 'send-otp stored no reset state.');
        $this->assertArrayHasKey('hash', $state);
        $this->assertNotSame($captured, $state['hash'], 'The OTP is stored in plaintext.');
        $this->assertTrue(Hash::check($captured, $state['hash']),
            'The stored hash does not correspond to the emailed OTP.');

        return $captured;
    }

    // ===================================================================================
    // THE TWO DEFECTS
    // ===================================================================================

    public function test_an_anonymous_caller_cannot_set_an_administrators_password(): void
    {
        [$store] = $this->storeWithCustomer('admin-target');

        $operator = $this->makePlatformAdmin(['email' => 'operator-target@example.test']);
        $originalHash = $operator->password;

        $this->postJson('/api/' . $store->slug . '/forgot-password-save', [
            'email' => $operator->email,
            'password' => 'attacker-chosen-password',
        ]);

        $this->assertSame($originalHash, $operator->fresh()->password,
            'An anonymous request changed the platform operator\'s password. This endpoint must '
            . 'not be able to write to the User model at all.');
    }

    public function test_an_anonymous_caller_cannot_set_a_customers_password_without_an_otp(): void
    {
        [$store, $customer] = $this->storeWithCustomer('no-otp');
        $originalHash = $customer->password;

        $this->postJson('/api/' . $store->slug . '/forgot-password-save', [
            'email' => $customer->email,
            'password' => 'attacker-chosen-password',
        ])->assertStatus(200)->assertJsonPath('status', 0);

        $this->assertSame($originalHash, $customer->fresh()->password,
            'A password was set with no reset authorisation at all.');
    }

    public function test_verifying_an_otp_does_not_change_the_password(): void
    {
        [$store, $customer] = $this->storeWithCustomer('no-fixed-pw');
        $originalHash = $customer->password;

        $otp = $this->completeSendOtp($store, $customer);

        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email,
            'otp' => $otp,
        ])->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertSame($originalHash, $customer->fresh()->password,
            'OTP verification still writes a password. It must only prove control of the mailbox.');
    }

    public function test_the_hardcoded_password_is_gone_from_the_codebase(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/Api/AuthController.php'));

        // Comment lines are stripped: both the controller and the service DOCUMENT the old
        // literal in an explanation of what was wrong, and that documentation is worth more than
        // the convenience of a bare string search.
        $live = implode("\n", array_filter(
            preg_split('/\R/', $source),
            fn ($line) => preg_match('/^\s*(\/\/|\*|\/\*)/', $line) !== 1
        ));

        $this->assertStringNotContainsString('hello@1234', $live,
            'The fixed recovery password is still live in AuthController.');
    }

    // ===================================================================================
    // THE CORRECT FLOW
    // ===================================================================================

    public function test_the_full_authorized_recovery_succeeds_and_sets_the_chosen_password(): void
    {
        [$store, $customer] = $this->storeWithCustomer('happy');

        $otp = $this->completeSendOtp($store, $customer);

        $verify = $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email,
            'otp' => $otp,
        ])->assertStatus(200)->assertJsonPath('status', 1);

        $resetToken = $verify->json('data.reset_token');
        $this->assertIsString($resetToken, 'verify-otp returned no reset token.');

        $this->postJson('/api/' . $store->slug . '/forgot-password-save', [
            'email' => $customer->email,
            'password' => 'the-customers-own-choice',
            'reset_token' => $resetToken,
        ])->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertTrue(Hash::check('the-customers-own-choice', $customer->fresh()->password),
            'The authorised reset did not set the password the customer chose.');
    }

    public function test_a_client_can_authorize_the_save_with_the_bearer_token_verify_otp_returns(): void
    {
        [$store, $customer] = $this->storeWithCustomer('bearer');

        $otp = $this->completeSendOtp($store, $customer);

        $verify = $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email,
            'otp' => $otp,
        ]);

        $bearer = $verify->json('data.token');
        $this->assertIsString($bearer, 'verify-otp no longer returns the Sanctum token it used to.');

        // No reset_token in the body: this is the compatibility path for a client that was never
        // updated but does forward the token it already receives.
        $this->withHeaders(['Authorization' => 'Bearer ' . $bearer, 'Accept' => 'application/json'])
            ->postJson('/api/' . $store->slug . '/forgot-password-save', [
                'email' => $customer->email,
                'password' => 'chosen-via-bearer',
            ])->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertTrue(Hash::check('chosen-via-bearer', $customer->fresh()->password));
    }

    // ===================================================================================
    // REPLAY, EXPIRY, ATTEMPTS
    // ===================================================================================

    public function test_a_replayed_otp_is_refused(): void
    {
        [$store, $customer] = $this->storeWithCustomer('replay');
        $otp = $this->completeSendOtp($store, $customer);

        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->assertJsonPath('status', 1);

        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->assertJsonPath('status', 0);
    }

    public function test_a_replayed_reset_token_is_refused(): void
    {
        [$store, $customer] = $this->storeWithCustomer('token-replay');
        $otp = $this->completeSendOtp($store, $customer);

        $resetToken = $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->json('data.reset_token');

        $this->postJson('/api/' . $store->slug . '/forgot-password-save', [
            'email' => $customer->email, 'password' => 'first-use', 'reset_token' => $resetToken,
        ])->assertJsonPath('status', 1);

        $this->postJson('/api/' . $store->slug . '/forgot-password-save', [
            'email' => $customer->email, 'password' => 'second-use', 'reset_token' => $resetToken,
        ])->assertJsonPath('status', 0);

        $this->assertTrue(Hash::check('first-use', $customer->fresh()->password),
            'The replayed token changed the password a second time.');
    }

    public function test_an_expired_otp_is_refused(): void
    {
        [$store, $customer] = $this->storeWithCustomer('expired');
        $this->completeSendOtp($store, $customer);

        // Expire it the way the cache would: the entry simply ceases to exist.
        Cache::forget("pwreset:otp:{$store->id}:{$customer->id}");

        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => '0000',
        ])->assertJsonPath('status', 0);
    }

    public function test_an_expired_reset_token_is_refused(): void
    {
        [$store, $customer] = $this->storeWithCustomer('token-expired');
        $otp = $this->completeSendOtp($store, $customer);

        $resetToken = $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->json('data.reset_token');

        Cache::forget('pwreset:token:' . hash('sha256', $resetToken));

        $this->postJson('/api/' . $store->slug . '/forgot-password-save', [
            'email' => $customer->email, 'password' => 'after-expiry', 'reset_token' => $resetToken,
        ])->assertJsonPath('status', 0);
    }

    /**
     * A 4-DIGIT OTP IS ONLY DEFENSIBLE WITH AN ATTEMPT LIMIT. 10,000 values would otherwise be
     * exhaustible well inside the 10-minute window.
     */
    public function test_the_otp_is_destroyed_after_too_many_wrong_attempts(): void
    {
        [$store, $customer] = $this->storeWithCustomer('attempts');
        $otp = $this->completeSendOtp($store, $customer);

        $wrong = $otp === '1111' ? '2222' : '1111';

        for ($i = 0; $i < CustomerPasswordResetService::MAX_ATTEMPTS + 1; $i++) {
            $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
                'email' => $customer->email, 'otp' => $wrong,
            ])->assertJsonPath('status', 0);
        }

        // Even the CORRECT code must now fail: the OTP was destroyed, not merely rejected.
        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->assertJsonPath('status', 0);
    }

    // ===================================================================================
    // TENANT ISOLATION
    // ===================================================================================

    public function test_an_otp_cannot_be_verified_through_another_stores_slug(): void
    {
        [$storeA, $customerA] = $this->storeWithCustomer('tenant-a');
        [$storeB] = $this->storeWithCustomer('tenant-b');

        $otp = $this->completeSendOtp($storeA, $customerA);

        $this->postJson('/api/' . $storeB->slug . '/forgot-password-verify-otp', [
            'email' => $customerA->email, 'otp' => $otp,
        ])->assertJsonPath('status', 0);
    }

    public function test_a_reset_token_cannot_be_spent_through_another_stores_slug(): void
    {
        [$storeA, $customerA] = $this->storeWithCustomer('cross-a');
        [$storeB] = $this->storeWithCustomer('cross-b');

        $otp = $this->completeSendOtp($storeA, $customerA);
        $resetToken = $this->postJson('/api/' . $storeA->slug . '/forgot-password-verify-otp', [
            'email' => $customerA->email, 'otp' => $otp,
        ])->json('data.reset_token');

        $originalHash = $customerA->fresh()->password;

        $this->postJson('/api/' . $storeB->slug . '/forgot-password-save', [
            'email' => $customerA->email, 'password' => 'cross-tenant', 'reset_token' => $resetToken,
        ])->assertJsonPath('status', 0);

        $this->assertSame($originalHash, $customerA->fresh()->password);
    }

    public function test_a_reset_token_for_one_customer_cannot_reset_another(): void
    {
        [$store, $victim] = $this->storeWithCustomer('victim');

        $attacker = Customer::forceCreate([
            'first_name' => 'Attacker', 'last_name' => 'Same Store',
            'email' => 'attacker-' . uniqid() . '@example.test',
            'password' => bcrypt('attacker-own'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        // The attacker runs the flow legitimately for their OWN account.
        $otp = $this->completeSendOtp($store, $attacker);
        $resetToken = $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $attacker->email, 'otp' => $otp,
        ])->json('data.reset_token');

        $victimHash = $victim->fresh()->password;

        // ...then points it at the victim.
        $this->postJson('/api/' . $store->slug . '/forgot-password-save', [
            'email' => $victim->email, 'password' => 'hijacked', 'reset_token' => $resetToken,
        ])->assertJsonPath('status', 0);

        $this->assertSame($victimHash, $victim->fresh()->password,
            "A reset token minted for one account reset another account in the same store.");
    }

    public function test_send_otp_will_not_find_a_customer_from_another_store(): void
    {
        [$storeA, $customerA] = $this->storeWithCustomer('send-a');
        [$storeB] = $this->storeWithCustomer('send-b');

        $this->postJson('/api/' . $storeB->slug . '/forgot-password-send-otp', [
            'email' => $customerA->email,
        ])->assertJsonPath('status', 0);

        $this->assertNull(Cache::get("pwreset:otp:{$storeB->id}:{$customerA->id}"));
        $this->assertNull(Cache::get("pwreset:otp:{$storeA->id}:{$customerA->id}"),
            "An OTP was issued for store A's customer through store B's slug.");
    }

    // ===================================================================================
    // STORAGE AND LOGGING
    // ===================================================================================

    public function test_the_otp_is_never_stored_in_plaintext_on_the_customer_row(): void
    {
        [$store, $customer] = $this->storeWithCustomer('at-rest');
        $this->completeSendOtp($store, $customer);

        $this->assertNull($customer->fresh()->password_otp,
            'The OTP is still written to customers.password_otp in plaintext, where any reader of '
            . 'that table holds a live reset credential.');
    }

    public function test_the_api_log_redacts_passwords_otps_and_tokens(): void
    {
        $middleware = new \App\Http\Middleware\APILog();

        $scrub = new \ReflectionMethod($middleware, 'scrub');
        $scrub->setAccessible(true);

        $clean = $scrub->invoke($middleware, [
            'email' => 'keep@example.test',
            'password' => 'must-not-appear',
            'otp' => '1234',
            'reset_token' => 'must-not-appear',
            'data' => ['token' => 'must-not-appear', 'first_name' => 'Keep'],
        ]);

        $encoded = json_encode($clean);

        $this->assertStringNotContainsString('must-not-appear', $encoded,
            'The API log still records credentials. storage/logs/api.log is a daily channel with '
            . '15-day retention, so a reader could complete a reset or reuse a token for a fortnight.');
        $this->assertStringNotContainsString('1234', $encoded, 'The OTP still reaches the log.');
        $this->assertStringContainsString('keep@example.test', $encoded,
            'Redaction removed the diagnostic value of the log, not just the credentials.');
        $this->assertStringContainsString('Keep', $encoded, 'Nested non-sensitive fields were lost.');
    }

    // ===================================================================================
    // CONTRACT
    // ===================================================================================

    public function test_the_three_routes_keep_their_paths_verbs_and_middleware(): void
    {
        $routes = \Illuminate\Support\Facades\Route::getRoutes();

        foreach ([
            'api/{slug}/forgot-password-send-otp',
            'api/{slug}/forgot-password-verify-otp',
            'api/{slug}/forgot-password-save',
        ] as $uri) {
            $route = collect($routes)->first(fn ($r) => $r->uri() === $uri);
            $this->assertNotNull($route, "{$uri} is no longer registered.");
            $this->assertContains('POST', $route->methods());
            $this->assertContains('throttle:auth', $route->gatherMiddleware(),
                "{$uri} lost its rate limiter.");
        }
    }

    public function test_verify_otp_still_returns_every_field_it_returned_before(): void
    {
        [$store, $customer] = $this->storeWithCustomer('shape');
        $otp = $this->completeSendOtp($store, $customer);

        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->assertStatus(200)->assertJsonStructure([
            'status', 'message',
            'data' => [
                'id', 'first_name', 'last_name', 'image', 'email', 'mobile',
                'company_name', 'country_id', 'state_id', 'city', 'address', 'postcode',
                'token', 'token_type', 'message',
                // additive
                'reset_token',
            ],
        ]);
    }
}
