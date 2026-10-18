<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerPasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * AN INDEPENDENT ADVERSARIAL REVIEW of the password-recovery emergency fix (commit 58b8e287).
 *
 * The first suite, CustomerPasswordResetTest, was written by the same pass that wrote the fix and
 * therefore tests what the author intended. This one tries to DISPROVE the fix. Each test states
 * the attack it attempts and asserts the OUTCOME, so a test that goes green is evidence about the
 * system rather than evidence about the author's intent.
 *
 * It covers the whole password-CHANGE surface, not only the three recovery endpoints, because the
 * question the owner asked -- can an administrator or delivery-driver token authorise a customer
 * password change -- is answered by the surface, not by one handler. That is how
 * POST api/{slug}/change-password came into scope.
 *
 * Mail is faked; no credential is ever asserted by value.
 */
class CustomerPasswordResetAdversarialTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

    // ===================================================================================
    // FIXTURES
    // ===================================================================================

    /** A store with the SMTP settings these handlers read, plus a customer in it. */
    private function storeWithCustomer(string $label): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach ([
            'MAIL_DRIVER' => 'log', 'MAIL_HOST' => 'localhost', 'MAIL_PORT' => '25',
            'MAIL_ENCRYPTION' => 'tls', 'MAIL_USERNAME' => 'u', 'MAIL_PASSWORD' => 'p',
            'MAIL_FROM_ADDRESS' => 'noreply@example.test', 'MAIL_FROM_NAME' => 'Store',
        ] as $name => $value) {
            Setting::forceCreate([
                'name' => $name, 'value' => $value,
                'store_id' => $store->id, 'created_by' => $merchant->id,
            ]);
        }

        return [$store, $this->customerIn($store, $label), $merchant];
    }

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => $label,
            'email' => 'adv-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $store->id,
            'status' => 1,
            'register_type' => 'email',
        ]);
    }

    private function driverFor(User $merchant, $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver',
            'email' => 'driver-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id,
            'store_id' => $store->id,
        ]);
    }

    /** send-otp, then recover the OTP from the email -- the only place the plaintext exists. */
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

        $this->assertNotNull($captured, 'send-otp did not email an OTP.');

        return $captured;
    }

    /** Run the full legitimate flow and return [reset_token, login_bearer]. */
    private function completeVerify($store, Customer $customer): array
    {
        $otp = $this->completeSendOtp($store, $customer);

        $response = $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email,
            'otp' => $otp,
        ])->assertStatus(200)->assertJsonPath('status', 1);

        $data = $response->json('data');

        $this->assertArrayHasKey('reset_token', $data, 'verify-otp minted no reset token.');
        $this->assertArrayHasKey('token', $data, 'verify-otp stopped returning the Bearer token.');

        return [$data['reset_token'], $data['token']];
    }

    private function save($store, Customer $customer, string $password, array $body = [], ?string $bearer = null)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->postJson(
            '/api/' . $store->slug . '/forgot-password-save',
            array_merge(['email' => $customer->email, 'password' => $password], $body)
        );
    }

    private function assertPasswordUnchanged(Customer $customer, string $attempted, string $message): void
    {
        $this->assertFalse(
            Hash::check($attempted, $customer->fresh()->password),
            $message
        );
    }

    // ===================================================================================
    // A. A PASSWORD CHANGE WITHOUT A VALID OTP  (brief item 1)
    // ===================================================================================

    public function test_save_refuses_a_caller_who_presents_no_authorisation_at_all(): void
    {
        [$store, $customer] = $this->storeWithCustomer('a1');

        $this->save($store, $customer, 'attacker-chosen-value-1')
            ->assertStatus(200)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'attacker-chosen-value-1',
            'forgot-password-save set a password with no authorisation whatsoever.');
    }

    public function test_save_refuses_a_fabricated_reset_token(): void
    {
        [$store, $customer] = $this->storeWithCustomer('a2');

        $this->save($store, $customer, 'attacker-chosen-value-2', ['reset_token' => str_repeat('a', 64)])
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'attacker-chosen-value-2',
            'A fabricated reset token was accepted.');
    }

    public function test_save_refuses_a_reset_token_belonging_to_another_customer_in_the_same_store(): void
    {
        [$store, $victim] = $this->storeWithCustomer('a3');
        $attacker = $this->customerIn($store, 'a3-attacker');

        // The attacker completes recovery for THEIR OWN account, which they control, then points
        // the resulting authorisation at the victim's email.
        [$resetToken] = $this->completeVerify($store, $attacker);

        $this->save($store, $victim, 'attacker-chosen-value-3', ['reset_token' => $resetToken])
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($victim, 'attacker-chosen-value-3',
            'A reset token for one account authorised a password change on another.');
    }

    public function test_verify_otp_no_longer_writes_any_password(): void
    {
        [$store, $customer] = $this->storeWithCustomer('a4');

        $before = $customer->fresh()->password;
        $this->completeVerify($store, $customer);

        $this->assertSame($before, $customer->fresh()->password,
            'verify-otp changed the password; the fixed-password defect is back.');

        // And specifically not the committed literal.
        $this->assertFalse(Hash::check('hello@1234', $customer->fresh()->password),
            'The committed literal hello@1234 is in use again.');
    }

    // ===================================================================================
    // B. CROSS-STORE AND CROSS-ACCOUNT AUTHORISATION  (brief item 2)
    // ===================================================================================

    public function test_a_reset_token_minted_in_one_store_is_refused_by_another(): void
    {
        [$storeA, $customerA] = $this->storeWithCustomer('b1a');
        [$storeB] = $this->storeWithCustomer('b1b');

        // Same email address present in both stores -- the realistic shape, since a shopper may
        // hold an account at two merchants on one platform.
        $customerB = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'b1b',
            'email' => $customerA->email,
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $storeB->id, 'status' => 1, 'register_type' => 'email',
        ]);

        [$resetToken] = $this->completeVerify($storeA, $customerA);

        $this->save($storeB, $customerB, 'attacker-chosen-value-b1', ['reset_token' => $resetToken])
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customerB, 'attacker-chosen-value-b1',
            'A token minted in store A reset the same email address in store B.');

        // A misrouted request must not have spent the customer's one chance.
        $this->save($storeA, $customerA, 'legitimate-value-b1', ['reset_token' => $resetToken])
            ->assertJsonPath('status', 1);
    }

    public function test_send_otp_will_not_resolve_a_customer_through_another_stores_slug(): void
    {
        [$storeA, $customerA] = $this->storeWithCustomer('b2a');
        [$storeB] = $this->storeWithCustomer('b2b');

        $this->postJson('/api/' . $storeB->slug . '/forgot-password-send-otp', [
            'email' => $customerA->email,
        ])->assertJsonPath('status', 0);

        Mail::assertNotSent(\App\Mail\SendOTP::class);

        $this->assertNull(Cache::get("pwreset:otp:{$storeB->id}:{$customerA->id}"));
        $this->assertNull(Cache::get("pwreset:otp:{$storeA->id}:{$customerA->id}"));
    }

    public function test_an_unresolvable_slug_is_refused_rather_than_defaulted_to_a_store(): void
    {
        [, $customer] = $this->storeWithCustomer('b3');

        $this->postJson('/api/no-such-store-slug-' . uniqid() . '/forgot-password-send-otp', [
            'email' => $customer->email,
        ])->assertJsonPath('status', 0);

        Mail::assertNotSent(\App\Mail\SendOTP::class);
    }

    // ===================================================================================
    // C. REPLAY  (brief item 3)
    // ===================================================================================

    public function test_a_reset_token_cannot_be_used_twice(): void
    {
        [$store, $customer] = $this->storeWithCustomer('c1');
        [$resetToken] = $this->completeVerify($store, $customer);

        $this->save($store, $customer, 'first-use-value-c1', ['reset_token' => $resetToken])
            ->assertJsonPath('status', 1);

        $this->save($store, $customer, 'replayed-value-c1', ['reset_token' => $resetToken])
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'replayed-value-c1',
            'A spent reset token was accepted a second time.');
    }

    public function test_an_otp_cannot_be_verified_twice(): void
    {
        [$store, $customer] = $this->storeWithCustomer('c2');
        $otp = $this->completeSendOtp($store, $customer);

        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->assertJsonPath('status', 1);

        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->assertJsonPath('status', 0);
    }

    /**
     * SINGLE USE MUST MEAN SINGLE USE. One completed OTP step must not leave behind an
     * authorisation that resets the password repeatedly.
     */
    public function test_one_completed_otp_step_does_not_authorise_a_second_password_change(): void
    {
        [$store, $customer] = $this->storeWithCustomer('c3');
        [$resetToken, $bearer] = $this->completeVerify($store, $customer);

        $this->save($store, $customer, 'first-use-value-c3', ['reset_token' => $resetToken])
            ->assertJsonPath('status', 1);

        // The Bearer token verify-otp also returned is the other accepted authorisation. If it
        // survives the change, the OTP step bought unlimited resets, not one.
        $this->save($store, $customer, 'second-use-value-c3', [], $bearer)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'second-use-value-c3',
            'The Bearer token from verify-otp authorised a SECOND password change, so the '
            . 'single-use guarantee does not hold.');
    }

    /**
     * Two recovery rounds mint two tokens. Spending one must retire the other, or an attacker who
     * once held the mailbox keeps a spare authorisation after the owner recovers the account.
     */
    public function test_a_successful_change_invalidates_every_other_outstanding_reset_token(): void
    {
        [$store, $customer] = $this->storeWithCustomer('c4');

        [$firstToken] = $this->completeVerify($store, $customer);
        [$secondToken] = $this->completeVerify($store, $customer);

        $this->assertNotSame($firstToken, $secondToken);

        $this->save($store, $customer, 'owner-chosen-value-c4', ['reset_token' => $secondToken])
            ->assertJsonPath('status', 1);

        $this->save($store, $customer, 'stale-token-value-c4', ['reset_token' => $firstToken])
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'stale-token-value-c4',
            'An older outstanding reset token still worked after a successful password change.');
    }

    /**
     * A RECOVERY RESET MUST END THE ACCOUNT'S OTHER SESSIONS. Recovery is the one moment the
     * platform has positive evidence that control of the account was in doubt. If the sessions
     * that existed before it survive, the owner changes their password while the attacker keeps
     * their token -- and the reset accomplishes nothing against the actor it was aimed at.
     */
    public function test_a_recovery_reset_ends_the_sessions_that_existed_before_it(): void
    {
        [$store, $customer] = $this->storeWithCustomer('c5');

        // The attacker's session, established before the owner recovers the account.
        $stolen = $customer->createToken('auth_token');
        $stolenId = (int) $stolen->accessToken->id;

        [$resetToken] = $this->completeVerify($store, $customer);

        $this->save($store, $customer, 'owner-chosen-value-c5', ['reset_token' => $resetToken])
            ->assertJsonPath('status', 1);

        $this->assertNull(
            \Laravel\Sanctum\PersonalAccessToken::find($stolenId),
            'A session that predated the recovery survived the password change, so resetting the '
            . 'password does not evict whoever already had the account.'
        );
    }

    /**
     * ...WITHOUT LOGGING OUT THE CLIENT MID-FLOW. verify-otp signs the caller in, so a client
     * that then saves a password must still hold a usable session afterwards.
     */
    public function test_the_session_minted_by_the_recovery_round_survives_the_change_it_authorised(): void
    {
        [$store, $customer] = $this->storeWithCustomer('c6');

        [, $bearer] = $this->completeVerify($store, $customer);

        $this->save($store, $customer, 'owner-chosen-value-c6', [], $bearer)
            ->assertJsonPath('status', 1);

        // The same Bearer still authenticates, so the client is not forced to re-login.
        $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/change-password', ['password' => 'later-value-c6'])
            ->assertJsonPath('status', 1);
    }

    /**
     * SPENDING ONE AUTHORISATION RETIRES ITS SIBLINGS, tested against the service directly.
     *
     * Through the HTTP surface this property is masked: the reset token's twin Bearer is also
     * deleted by the session revocation above, so the endpoint-level test would pass even with
     * the generation record broken. Two independent controls is the right answer for production
     * and the wrong answer for a test that is supposed to pin ONE of them, so this one addresses
     * the service.
     */
    public function test_spending_a_reset_token_retires_the_bearer_minted_in_the_same_round(): void
    {
        [$store, $customer] = $this->storeWithCustomer('c7');
        $service = app(CustomerPasswordResetService::class);

        $otp = $this->completeSendOtp($store, $customer);
        $resetToken = $service->verifyOtp($customer, (int) $store->id, $otp);
        $this->assertIsString($resetToken);

        // The Sanctum token the handler would mint and register for this same round.
        $bearerId = (int) $customer->createToken('auth_token')->accessToken->id;
        $service->registerRecoveryBearer($bearerId, (int) $customer->id, (int) $store->id);

        $this->assertIsArray($service->consumeRecoveryBearer($bearerId, (int) $store->id),
            'The registered Bearer did not authorise anything, so this test proves nothing.');

        // Re-register and spend the OTHER half of the pair instead.
        $otp = $this->completeSendOtp($store, $customer);
        $resetToken = $service->verifyOtp($customer, (int) $store->id, $otp);
        $bearerId = (int) $customer->createToken('auth_token')->accessToken->id;
        $service->registerRecoveryBearer($bearerId, (int) $customer->id, (int) $store->id);

        $this->assertIsArray($service->consumeResetToken($resetToken, (int) $store->id));

        $this->assertNull($service->consumeRecoveryBearer($bearerId, (int) $store->id),
            'Spending the reset token left its twin Bearer alive, so one completed OTP step '
            . 'authorises more than one password change.');
    }

    // ===================================================================================
    // D. EXPIRY AND THE ATTEMPT LIMIT  (brief items 4 and 5)
    // ===================================================================================

    public function test_an_expired_otp_is_refused(): void
    {
        [$store, $customer] = $this->storeWithCustomer('d1');
        $otp = $this->completeSendOtp($store, $customer);

        $this->travel(CustomerPasswordResetService::OTP_TTL_MINUTES + 1)->minutes();

        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->assertJsonPath('status', 0);

        $this->travelBack();
    }

    public function test_an_expired_reset_token_is_refused(): void
    {
        [$store, $customer] = $this->storeWithCustomer('d2');
        [$resetToken] = $this->completeVerify($store, $customer);

        $this->travel(CustomerPasswordResetService::TOKEN_TTL_MINUTES + 1)->minutes();

        $this->save($store, $customer, 'expired-token-value-d2', ['reset_token' => $resetToken])
            ->assertJsonPath('status', 0);

        $this->travelBack();

        $this->assertPasswordUnchanged($customer, 'expired-token-value-d2',
            'An expired reset token was accepted.');
    }

    public function test_the_correct_otp_is_refused_once_the_attempt_limit_is_spent(): void
    {
        [$store, $customer] = $this->storeWithCustomer('d3');
        $otp = $this->completeSendOtp($store, $customer);

        $service = app(CustomerPasswordResetService::class);

        // Driven through the service rather than the route so the throttle is not the thing under
        // test; the attempt limit is.
        for ($i = 0; $i < CustomerPasswordResetService::MAX_ATTEMPTS; $i++) {
            $this->assertNull($service->verifyOtp($customer, (int) $store->id, 'XXXX'),
                'A deliberately wrong OTP was accepted.');
        }

        $this->assertNull($service->verifyOtp($customer, (int) $store->id, $otp),
            'The correct OTP was accepted after the attempt limit was exhausted.');
    }

    /**
     * WRONG GUESSES MUST NOT BUY TIME. Re-putting the attempt counter with a fresh TTL would let a
     * guesser hold an OTP alive past its promised ten minutes.
     */
    public function test_a_wrong_guess_does_not_extend_the_otps_lifetime(): void
    {
        [$store, $customer] = $this->storeWithCustomer('d4');
        $otp = $this->completeSendOtp($store, $customer);

        $service = app(CustomerPasswordResetService::class);

        // Guess wrong at minute 9, one minute before the deadline.
        $this->travel(9)->minutes();
        $this->assertNull($service->verifyOtp($customer, (int) $store->id, 'XXXX'));

        // Past the ORIGINAL deadline. The OTP must be gone regardless of the wrong guess.
        $this->travel(2)->minutes();

        $accepted = $service->verifyOtp($customer, (int) $store->id, $otp);
        $this->travelBack();

        $this->assertNull($accepted,
            'A wrong guess extended the OTP past its ten-minute promise, so the lifetime can be '
            . 'held open indefinitely by guessing.');
    }

    /**
     * THE ATTEMPT LIMIT MUST BIND THE ACCOUNT, NOT ONLY ONE CODE. If re-sending resets the counter
     * for free, the limit costs an attacker one extra request per five guesses and a four-digit
     * code is exhaustible.
     */
    public function test_requesting_a_new_otp_does_not_hand_back_a_fresh_attempt_budget_indefinitely(): void
    {
        [$store, $customer] = $this->storeWithCustomer('d5');
        $service = app(CustomerPasswordResetService::class);

        $roundsAllowed = 0;

        // Ten rounds of (issue a code, spend the whole attempt budget on it). With no per-account
        // cap all ten are granted, which is what makes the five-guess limit cost an attacker one
        // extra request per five guesses and bound nothing.
        for ($round = 0; $round < 10; $round++) {
            $sent = $this->postJson('/api/' . $store->slug . '/forgot-password-send-otp', [
                'email' => $customer->email,
            ]);

            if ((int) $sent->json('status') !== 1) {
                break;
            }

            $roundsAllowed++;

            for ($i = 0; $i < CustomerPasswordResetService::MAX_ATTEMPTS; $i++) {
                $service->verifyOtp($customer, (int) $store->id, 'XXXX');
            }
        }

        $this->assertLessThan(10, $roundsAllowed,
            'Ten consecutive OTPs were issued for one account after fifty wrong guesses, so the '
            . 'attempt limit resets for the price of one request and bounds nothing.');

        $this->assertSame(CustomerPasswordResetService::MAX_SENDS_PER_WINDOW, $roundsAllowed,
            'The number of codes granted in one window does not match the declared cap.');
    }

    /**
     * THE READ-MODIFY-WRITE ON THE ATTEMPT COUNTER MUST BE SERIALISED. Two guesses that interleave
     * -- both reading the counter before either writes -- otherwise each spend one attempt and
     * record one between them, so parallelism buys guesses the limit has already paid for.
     *
     * The mechanism is tested rather than the race: the lock is taken in the test and held, and
     * verify must then REFUSE a request it cannot serialise, even one carrying the correct code.
     * That is the only part of a race that can be asserted deterministically in one process, and
     * it is the part that matters -- a verify step that proceeds without the lock is the bug.
     */
    public function test_verification_refuses_to_proceed_when_it_cannot_serialise(): void
    {
        [$store, $customer] = $this->storeWithCustomer('d6');
        $otp = $this->completeSendOtp($store, $customer);

        $this->assertInstanceOf(\Illuminate\Contracts\Cache\LockProvider::class, Cache::getStore(),
            'This test needs a lockable cache store to mean anything.');

        // Another request is mid-verification for this customer.
        $held = Cache::lock("pwreset:lock:{$store->id}:{$customer->id}", 30);
        $this->assertTrue($held->get(), 'Could not take the lock the service uses.');

        try {
            $accepted = app(CustomerPasswordResetService::class)
                ->verifyOtp($customer, (int) $store->id, $otp);
        } finally {
            $held->release();
        }

        $this->assertNull($accepted,
            'Verification proceeded while another request held the lock, so the attempt counter '
            . 'is a read-modify-write that concurrent guesses can each spend.');

        // And the OTP survives, so a legitimate user who lost a race can simply retry.
        $this->assertIsArray(Cache::get("pwreset:otp:{$store->id}:{$customer->id}"),
            'A refused-for-contention attempt destroyed the OTP.');
    }

    public function test_two_sequential_wrong_guesses_each_count_against_the_attempt_limit(): void
    {
        [$store, $customer] = $this->storeWithCustomer('d7');
        $this->completeSendOtp($store, $customer);

        $service = app(CustomerPasswordResetService::class);
        $service->verifyOtp($customer, (int) $store->id, 'XXXX');
        $service->verifyOtp($customer, (int) $store->id, 'XXXX');

        $state = Cache::get("pwreset:otp:{$store->id}:{$customer->id}");

        $this->assertSame(2, (int) ($state['attempts'] ?? 0),
            'Two wrong guesses did not record two attempts.');
    }

    /**
     * THE SEND CAP MUST NOT BECOME A PERMANENT LOCKOUT. A rate limit on a victim's own recovery
     * is a denial of service against the real owner if its window never reopens, which is why the
     * window is fixed from the first send rather than pushed forward by each one.
     */
    public function test_the_send_cap_window_reopens_so_a_legitimate_owner_is_not_locked_out(): void
    {
        [$store, $customer] = $this->storeWithCustomer('d8');

        for ($i = 0; $i < CustomerPasswordResetService::MAX_SENDS_PER_WINDOW; $i++) {
            $this->postJson('/api/' . $store->slug . '/forgot-password-send-otp', [
                'email' => $customer->email,
            ])->assertJsonPath('status', 1);
        }

        // The budget is spent.
        $this->postJson('/api/' . $store->slug . '/forgot-password-send-otp', [
            'email' => $customer->email,
        ])->assertJsonPath('status', 0);

        $this->travel(CustomerPasswordResetService::SEND_WINDOW_MINUTES + 1)->minutes();

        $reopened = $this->postJson('/api/' . $store->slug . '/forgot-password-send-otp', [
            'email' => $customer->email,
        ]);

        $this->travelBack();

        $reopened->assertJsonPath('status', 1);
    }

    // ===================================================================================
    // E. OPERATOR AND DRIVER TOKENS  (brief item 6)  AND THE BEARER FALLBACK  (item 7)
    // ===================================================================================

    public function test_an_administrators_token_cannot_authorise_a_customer_password_reset(): void
    {
        [$store, $customer, $merchant] = $this->storeWithCustomer('e1');

        $this->save($store, $customer, 'merchant-chosen-value-e1', [], $merchant->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'merchant-chosen-value-e1',
            "A merchant's own API token authorised a customer password reset.");
    }

    public function test_a_platform_operators_token_cannot_authorise_a_customer_password_reset(): void
    {
        [$store, $customer] = $this->storeWithCustomer('e2');
        $operator = $this->makePlatformAdmin();

        $this->save($store, $customer, 'operator-chosen-value-e2', [], $operator->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'operator-chosen-value-e2',
            "The platform operator's token authorised a customer password reset.");
    }

    public function test_a_delivery_drivers_token_cannot_authorise_a_customer_password_reset(): void
    {
        [$store, $customer, $merchant] = $this->storeWithCustomer('e3');
        $driver = $this->driverFor($merchant, $store);

        $this->save($store, $customer, 'driver-chosen-value-e3', [], $driver->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'driver-chosen-value-e3',
            "A delivery driver's token authorised a customer password reset.");
    }

    public function test_a_customers_token_cannot_authorise_a_reset_for_a_different_customer(): void
    {
        [$store, $victim] = $this->storeWithCustomer('e4');
        $attacker = $this->customerIn($store, 'e4-attacker');

        $this->save($store, $victim, 'attacker-chosen-value-e4', [], $attacker->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($victim, 'attacker-chosen-value-e4',
            "One customer's token authorised another customer's password reset.");
    }

    public function test_a_customers_token_from_another_store_is_refused(): void
    {
        [$storeA, $customerA] = $this->storeWithCustomer('e5a');
        [$storeB] = $this->storeWithCustomer('e5b');

        $customerB = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'e5b',
            'email' => $customerA->email,
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $storeB->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $this->save($storeB, $customerB, 'cross-store-value-e5', [], $customerA->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customerB, 'cross-store-value-e5',
            'A customer token from store A authorised a reset in store B.');
    }

    public function test_an_expired_customer_token_is_refused_by_the_fallback(): void
    {
        [$store, $customer] = $this->storeWithCustomer('e6');

        $token = $customer->createToken('t');
        $token->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->save($store, $customer, 'expired-bearer-value-e6', [], $token->plainTextToken)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'expired-bearer-value-e6',
            'An expired Bearer token authorised a password reset.');
    }

    /**
     * THE FALLBACK MUST NOT EXCEED THE OTP IT STANDS IN FOR. An ordinary long-lived login token --
     * the one a shopper's phone has held since they signed in, with no OTP anywhere in the story --
     * must not be a password-reset authorisation, or a single stolen token becomes a permanent
     * account takeover and the whole OTP step is optional.
     */
    public function test_an_ordinary_login_token_is_not_a_password_reset_authorisation(): void
    {
        [$store, $customer] = $this->storeWithCustomer('e7');

        // Signed in the ordinary way. No recovery flow has been started for this account.
        $loginToken = $customer->createToken('auth_token')->plainTextToken;

        $this->save($store, $customer, 'stolen-session-value-e7', [], $loginToken)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'stolen-session-value-e7',
            'Any customer login token sets that account\'s password with no OTP and no knowledge '
            . 'of the current password, so a stolen token is a permanent takeover.');
    }

    // ===================================================================================
    // F. THE REST OF THE PASSWORD-CHANGE SURFACE
    //
    // The owner's question -- can an administrator or delivery-driver token authorise a customer
    // password change -- is a question about the surface. api/{slug}/change-password is the other
    // endpoint on it.
    // ===================================================================================

    private function changePassword($store, array $body, ?string $bearer = null)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->postJson('/api/' . $store->slug . '/change-password', $body);
    }

    public function test_change_password_refuses_an_administrator_naming_an_arbitrary_customer(): void
    {
        [$store, $victim, $merchant] = $this->storeWithCustomer('f1');

        $this->changePassword($store, [
            'customer_id' => $victim->id,
            'password' => 'merchant-chosen-value-f1',
        ], $merchant->createToken('t')->plainTextToken);

        $this->assertPasswordUnchanged($victim, 'merchant-chosen-value-f1',
            'change-password let a merchant set an arbitrary customer\'s password by id.');
    }

    public function test_change_password_refuses_a_delivery_driver_naming_an_arbitrary_customer(): void
    {
        [$store, $victim, $merchant] = $this->storeWithCustomer('f2');
        $driver = $this->driverFor($merchant, $store);

        $this->changePassword($store, [
            'customer_id' => $victim->id,
            'password' => 'driver-chosen-value-f2',
        ], $driver->createToken('t')->plainTextToken);

        $this->assertPasswordUnchanged($victim, 'driver-chosen-value-f2',
            'change-password let a delivery driver set an arbitrary customer\'s password by id.');
    }

    public function test_change_password_refuses_an_operator_naming_a_customer_in_a_store_they_do_not_own(): void
    {
        [$storeA, $victim] = $this->storeWithCustomer('f3a');
        [$storeB, , $merchantB] = $this->storeWithCustomer('f3b');

        // A merchant of store B, acting through store B's own slug, names a customer of store A.
        $this->changePassword($storeB, [
            'customer_id' => $victim->id,
            'password' => 'cross-store-value-f3',
        ], $merchantB->createToken('t')->plainTextToken);

        $this->assertPasswordUnchanged($victim, 'cross-store-value-f3',
            'change-password reached across the tenant boundary: a merchant of one store set the '
            . 'password of a customer belonging to another.');
    }

    /**
     * THE WORST CASE ON THIS ENDPOINT. A delivery driver is the lowest-privileged principal the
     * platform issues tokens to, and nothing resolves a tenant for the `deliveryboy` guard, so
     * `config/tenancy.php`'s `null_tenant => 'open'` leaves Customer unscoped for them. The
     * handler itself performs no ownership or store check at all, which is the actual defect:
     * the sibling test above passes only because a MERCHANT token happens to resolve a tenant and
     * the global scope narrows the lookup for them. A control that works by accident for one
     * principal is not a control.
     */
    public function test_change_password_refuses_a_driver_naming_a_customer_of_a_different_store(): void
    {
        [$storeA, $victim] = $this->storeWithCustomer('f6a');
        [$storeB, , $merchantB] = $this->storeWithCustomer('f6b');
        $driver = $this->driverFor($merchantB, $storeB);

        $this->changePassword($storeB, [
            'customer_id' => $victim->id,
            'password' => 'driver-cross-store-value-f6',
        ], $driver->createToken('t')->plainTextToken);

        $this->assertPasswordUnchanged($victim, 'driver-cross-store-value-f6',
            'A delivery driver of store B set the password of a customer belonging to store A. '
            . 'That is a cross-tenant account takeover from the least privileged principal on '
            . 'the platform.');
    }

    public function test_change_password_refuses_one_customer_naming_another(): void
    {
        [$store, $victim] = $this->storeWithCustomer('f4');
        $attacker = $this->customerIn($store, 'f4-attacker');

        $this->changePassword($store, [
            'customer_id' => $victim->id,
            'password' => 'attacker-chosen-value-f4',
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertPasswordUnchanged($victim, 'attacker-chosen-value-f4',
            'change-password let one customer set another\'s password.');
    }

    /**
     * DEFENCE IN DEPTH, STATED AS SUCH. The slug was resolved by this handler and then never
     * read, so the endpoint was indifferent to which storefront it was called through. Binding it
     * closes no demonstrated hole -- a customer reaching this check is only ever changing their
     * own password -- but an endpoint that ignores its own tenant parameter is how the next
     * defect gets in, and the cost of reading it is one comparison.
     */
    public function test_change_password_refuses_a_customer_calling_through_another_stores_slug(): void
    {
        [$storeA, $customerA] = $this->storeWithCustomer('f7a');
        [$storeB] = $this->storeWithCustomer('f7b');

        $this->changePassword($storeB, [
            'password' => 'wrong-slug-value-f7',
        ], $customerA->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customerA, 'wrong-slug-value-f7',
            'change-password accepted a customer of store A through store B\'s slug.');
    }

    public function test_change_password_still_lets_a_signed_in_customer_change_their_own(): void
    {
        [$store, $customer] = $this->storeWithCustomer('f5');

        $this->changePassword($store, [
            'password' => 'self-chosen-value-f5',
        ], $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $this->assertTrue(Hash::check('self-chosen-value-f5', $customer->fresh()->password),
            'A signed-in customer can no longer change their own password; the fix broke the '
            . 'legitimate journey.');
    }

    /**
     * A customer row with NO store still changes its own password. `customers.store_id` is
     * nullable and imports write that table, so refusing such a row would deny a real shopper
     * their own password change to close nothing -- the endpoint is self-scoped by the principal
     * check, so which slug was used does not change whose password it sets.
     */
    public function test_change_password_still_works_for_a_customer_with_no_store(): void
    {
        [$store] = $this->storeWithCustomer('f8');

        $orphan = Customer::forceCreate([
            'first_name' => 'Imported', 'last_name' => 'f8',
            'email' => 'f8-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => null, 'status' => 1, 'register_type' => 'email',
        ]);

        $this->changePassword($store, ['password' => 'self-chosen-value-f8'],
            $orphan->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 1);

        $this->assertTrue(Hash::check('self-chosen-value-f8', $orphan->fresh()->password),
            'A customer with no store cannot change their own password.');
    }

    // ===================================================================================
    // F2. THE OTHER PASSWORD-RESET ENDPOINT ON THE ADMIN SURFACE
    //
    // POST api/admin/deliveryboysReset-password/{id}?store_id=N -- routes/api.php:363, inside the
    // `Route::prefix('admin')` group opened at :133 -- read while checking this route's logging.
    // `store_id` arrives in the QUERY STRING, so the `where('store_id', ...)` predicate checked
    // the caller's two supplied values against each other and authorised nothing.
    //
    // The prefix matters: the shipped admin app's base URL is `https://storesyria.com/api/admin`
    // (repo B lib/utils/base_api.dart:3), so this is exactly the path it calls.
    // ===================================================================================

    private function resetDriver($driverId, int $storeId, string $password, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->postJson(
            '/api/admin/deliveryboysReset-password/' . $driverId . '?store_id=' . $storeId,
            ['password' => $password, 'password_confirmation' => $password]
        );
    }

    private function assertDriverPasswordUnchanged(DeliveryBoy $driver, string $attempted, string $message): void
    {
        $this->assertFalse(Hash::check($attempted, (string) $driver->fresh()->password), $message);
    }

    public function test_a_customers_token_cannot_reset_a_delivery_drivers_password(): void
    {
        [$store, $customer, $merchant] = $this->storeWithCustomer('g1');
        $driver = $this->driverFor($merchant, $store);

        $this->resetDriver($driver->id, (int) $store->id, 'customer-chosen-value-g1',
            $customer->createToken('t')->plainTextToken);

        $this->assertDriverPasswordUnchanged($driver, 'customer-chosen-value-g1',
            "A shopper's token set a delivery driver's password.");
    }

    public function test_one_drivers_token_cannot_reset_another_drivers_password(): void
    {
        [$store, , $merchant] = $this->storeWithCustomer('g2');
        $victim = $this->driverFor($merchant, $store);
        $attacker = $this->driverFor($merchant, $store);

        $this->resetDriver($victim->id, (int) $store->id, 'driver-chosen-value-g2',
            $attacker->createToken('t')->plainTextToken);

        $this->assertDriverPasswordUnchanged($victim, 'driver-chosen-value-g2',
            "One delivery driver's token set another driver's password.");
    }

    public function test_a_merchant_cannot_reset_a_driver_belonging_to_a_store_they_do_not_own(): void
    {
        [$storeA, , $merchantA] = $this->storeWithCustomer('g3a');
        [, , $merchantB] = $this->storeWithCustomer('g3b');
        $victim = $this->driverFor($merchantA, $storeA);

        // Merchant B names store A, which they do not own. The query-string store_id matches the
        // driver's row, so the old predicate was satisfied.
        $this->resetDriver($victim->id, (int) $storeA->id, 'cross-tenant-value-g3',
            $merchantB->createToken('t')->plainTextToken);

        $this->assertDriverPasswordUnchanged($victim, 'cross-tenant-value-g3',
            'A merchant of one store set the password of a delivery driver belonging to another. '
            . 'The query-string store_id was checked only against the id the same caller supplied.');
    }

    public function test_the_owning_merchant_can_still_reset_their_own_drivers_password(): void
    {
        [$store, , $merchant] = $this->storeWithCustomer('g4');
        $driver = $this->driverFor($merchant, $store);

        // Exactly the request the shipped admin app makes: its own store_id, the merchant's
        // Bearer, password + confirmation in the body.
        $this->resetDriver($driver->id, (int) $store->id, 'merchant-chosen-value-g4',
            $merchant->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertTrue(Hash::check('merchant-chosen-value-g4', (string) $driver->fresh()->password),
            'The owning merchant can no longer reset their own driver\'s password; the fix broke '
            . 'the journey the admin app depends on.');
    }

    // ===================================================================================
    // G. THE OTP ITSELF
    // ===================================================================================

    /**
     * THE OTP MUST COME FROM A CRYPTOGRAPHIC SOURCE. rand()/mt_rand() is a Mersenne Twister seeded
     * per process: an attacker who can observe a few outputs -- by requesting codes for accounts
     * they own at the same store, served by the same php-fpm worker -- can recover the state and
     * predict a victim's code without ever seeing their mailbox. Reproducing the sequence from a
     * known seed is the demonstration.
     */
    public function test_the_otp_cannot_be_reproduced_from_a_known_generator_seed(): void
    {
        mt_srand(424242);
        $first = \App\Models\Utility::generateNumericOTP(4);

        mt_srand(424242);
        $second = \App\Models\Utility::generateNumericOTP(4);

        $this->assertNotSame($first, $second,
            'The OTP generator is seedable and therefore predictable: two calls from the same seed '
            . 'produced the same code. It must draw from a cryptographic source.');
    }

    public function test_the_otp_still_has_the_shape_the_existing_clients_expect(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $otp = \App\Models\Utility::generateNumericOTP(4);

            $this->assertMatchesRegularExpression('/^[0-9]{4}$/', (string) $otp,
                'generateNumericOTP no longer returns exactly four digits; the web and app flows '
                . 'both depend on that shape.');
        }
    }

    public function test_the_otp_draws_on_the_whole_digit_alphabet(): void
    {
        $seen = [];

        for ($i = 0; $i < 400; $i++) {
            foreach (str_split((string) \App\Models\Utility::generateNumericOTP(4)) as $digit) {
                $seen[$digit] = true;
            }
        }

        $this->assertCount(10, $seen,
            'The OTP alphabet is smaller than ten digits, which shrinks the space an attacker '
            . 'must cover. Observed: ' . implode(',', array_keys($seen)));
    }

    // ===================================================================================
    // H. CACHE FAILURE AND EXPIRY BEHAVIOUR  (brief item 9)
    // ===================================================================================

    public function test_a_cleared_cache_fails_closed_rather_than_open(): void
    {
        [$store, $customer] = $this->storeWithCustomer('h1');
        [$resetToken] = $this->completeVerify($store, $customer);

        // A deploy, a cache:clear, an evicted key -- the state is simply gone.
        Cache::flush();

        $this->save($store, $customer, 'flushed-cache-value-h1', ['reset_token' => $resetToken])
            ->assertJsonPath('status', 0);

        $this->assertPasswordUnchanged($customer, 'flushed-cache-value-h1',
            'Losing the reset state authorised the change instead of refusing it.');
    }

    public function test_the_cache_never_holds_the_otp_or_the_reset_token_in_a_replayable_form(): void
    {
        [$store, $customer] = $this->storeWithCustomer('h2');

        $otp = $this->completeSendOtp($store, $customer);

        $state = Cache::get("pwreset:otp:{$store->id}:{$customer->id}");
        $this->assertIsArray($state);
        $this->assertNotSame($otp, $state['hash'] ?? null, 'The OTP is cached in plaintext.');
        $this->assertTrue(Hash::check($otp, (string) $state['hash']));

        $response = $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->assertJsonPath('status', 1);

        $resetToken = $response->json('data.reset_token');

        // The token is the cache KEY, hashed -- so a reader of the cache cannot replay it.
        $this->assertNull(Cache::get('pwreset:token:' . $resetToken),
            'The reset token is cached under its own plaintext value.');
        $this->assertIsArray(Cache::get('pwreset:token:' . hash('sha256', $resetToken)));
    }

    // ===================================================================================
    // I. LOG EXPOSURE  (brief item 10)
    // ===================================================================================

    /**
     * THE ADMIN LOGGER IS THE SAME DEFECT ON THE HIGHER-PRIVILEGE SURFACE, and it survived the
     * emergency because only the storefront logger was looked at.
     *
     * AdminApiLog::terminate() logged `$request` -- the whole object, which stringifies to the
     * complete HTTP request, Authorization header and body included -- and `$response` verbatim,
     * to the same `API_log` channel with the same 15-day retention. It guards 128 routes in
     * routes/api.php, among them adminlogin, updatepassword and deliveryboysReset-password.
     */
    public function test_no_credential_reaches_the_api_log_through_the_admin_logger(): void
    {
        [, $store] = $this->storeWithCustomer('i2');

        $logFile = storage_path('logs/api-' . date('Y-m-d') . '.log');
        $before = is_file($logFile) ? (int) filesize($logFile) : 0;

        $password = 'admin-log-probe-' . uniqid();
        $bearer = 'probe-bearer-' . uniqid();

        // adminlogin carries ['AdminApiLog','throttle:auth'] and no authentication, so the
        // request reaches the logger whether or not the credentials are valid -- which is the
        // point: a FAILED admin login was logged with its password in plaintext too.
        $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/adminlogin', [
                'email' => 'operator@example.test',
                'password' => $password,
            ]);

        if (! is_file($logFile)) {
            $this->markTestSkipped('The API log was not written in this environment.');
        }

        $written = (string) file_get_contents($logFile, false, null, $before);

        $this->assertNotSame('', $written,
            'The admin logger wrote nothing, so this test proves nothing.');

        $this->assertStringNotContainsString($password, $written,
            'The admin API log contains a submitted administrator password in plaintext.');

        $this->assertStringNotContainsString($bearer, $written,
            'The admin API log contains the Authorization header in plaintext.');

        $this->assertStringContainsString('[REDACTED]', $written,
            'The admin logger did not redact anything, so it is not applying the scrubber.');
    }

    public function test_both_api_loggers_share_one_redaction_implementation(): void
    {
        foreach ([\App\Http\Middleware\APILog::class, \App\Http\Middleware\AdminApiLog::class] as $logger) {
            $this->assertContains(
                \App\Http\Middleware\Concerns\RedactsApiLogFields::class,
                class_uses($logger) ?: [],
                $logger . ' does not use the shared redaction trait, so the two loggers can drift '
                . 'apart again -- which is exactly how the admin one kept leaking after the '
                . 'storefront one was fixed.'
            );

            $source = (string) file_get_contents((new \ReflectionClass($logger))->getFileName());

            $this->assertStringNotContainsString('->info($request);', $source,
                $logger . ' still logs the raw Request object, which includes the Authorization '
                . 'header and the whole body.');
            $this->assertStringNotContainsString('->info($response);', $source,
                $logger . ' still logs the raw response.');
        }
    }

    public function test_no_credential_from_the_recovery_flow_reaches_the_api_log(): void
    {
        [$store, $customer] = $this->storeWithCustomer('i1');

        $logFile = storage_path('logs/api-' . date('Y-m-d') . '.log');
        $before = is_file($logFile) ? (int) filesize($logFile) : 0;

        $otp = $this->completeSendOtp($store, $customer);

        $response = $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $otp,
        ])->assertJsonPath('status', 1);

        $resetToken = (string) $response->json('data.reset_token');
        $bearer = (string) $response->json('data.token');

        $chosen = 'log-probe-value-i1-' . uniqid();
        $this->save($store, $customer, $chosen, ['reset_token' => $resetToken])
            ->assertJsonPath('status', 1);

        if (! is_file($logFile)) {
            $this->markTestSkipped('The API log was not written in this environment.');
        }

        $written = (string) file_get_contents($logFile, false, null, $before);

        foreach ([
            'the chosen password' => $chosen,
            'the OTP' => $otp,
            'the reset token' => $resetToken,
            'the Bearer token' => $bearer,
        ] as $what => $secret) {
            $this->assertStringNotContainsString($secret, $written,
                "The API log contains {$what} in plaintext.");
        }
    }
}
