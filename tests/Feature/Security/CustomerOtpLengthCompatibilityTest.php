<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Utility;
use App\Services\CustomerPasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Security\SecurityTestCase;

/**
 * OTP LENGTH COMPATIBILITY — can the storefront API recovery code move from four digits to six?
 *
 * THE ANSWER THESE TESTS ESTABLISH: the SERVER can, today, with no code change. The DEFAULT does
 * not move, because the client that consumes the code is in neither repository in this session.
 *
 * WHAT IS LENGTH-AGNOSTIC, each pinned below:
 *   - the generator takes the length as an argument (Utility::generateNumericOTP);
 *   - nothing validates the digit count: forgot_password_verify_otp passes the submitted string to
 *     Hash::check against a bcrypt hash of the issued one, and bcrypt does not care how long it is;
 *   - the mail template renders {{ $otp }} into a percentage-width box
 *     (resources/views/email/sendOTP.blade.php), so six digits lay out as four do;
 *   - the request and response shapes are identical at either length;
 *   - the attempt limit and the per-account send cap are independent of the length.
 *
 * WHAT IS NOT INSPECTABLE, and is therefore the blocker:
 *   - the unreleased storefront client. Neither Flutter repository in this project calls these
 *     endpoints (they are absent from tests/Support/ApiContract/manifests/flutter-endpoints.json,
 *     and the admin app's base URL is /api/admin), and the only contract artefact that exists for
 *     them, packages/workdo/Api/src/documentation/theme.json, documents the request as
 *     `"otp": "1234"`. If that client's input is four characters wide, a six-digit code is
 *     un-enterable and every storefront recovery breaks — a worse outcome than the brute-force
 *     margin four digits leaves.
 *
 * SO THE LENGTH IS CONFIGURATION. config/auth.php's 'customer_password_reset.otp_digits', default
 * 4, clamped to [4, 8] by the service so a typo cannot shorten it. The tests below prove the
 * default is unchanged, that six works end to end, and that the clamp holds.
 *
 * THE WEB FLOW ALREADY ISSUES SIX (CustomerLoginController:151, random_int(100000, 999999)), which
 * is independent evidence that the platform's delivery and storage paths handle six — once
 * P01-SEC-13's column widening is applied, which is what makes that flow work at all on MySQL.
 */
class CustomerOtpLengthCompatibilityTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

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

        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'otplen-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        return [$store, $customer];
    }

    /** Drive send-otp and recover the code from the email — the only place the plaintext exists. */
    private function sendOtp($store, Customer $customer): string
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

        $this->assertNotNull($captured, 'send-otp emailed no code.');

        return $captured;
    }

    // ===================================================================================
    // THE DEFAULT DOES NOT MOVE
    // ===================================================================================

    public function test_the_shipped_default_is_still_four_digits(): void
    {
        // No CUSTOMER_OTP_DIGITS set, which is the state of every deployed environment today.
        $this->assertSame(4, app(CustomerPasswordResetService::class)->otpDigits(),
            'The default OTP length changed. No deployed client has been verified against a '
            . 'different length, so the default must stay at 4 until one is.');

        [$store, $customer] = $this->storeWithCustomer('d1');

        $this->assertMatchesRegularExpression('/^[0-9]{4}$/', $this->sendOtp($store, $customer),
            'The issued code is no longer four digits by default.');
    }

    public function test_the_documented_request_shape_is_what_the_default_still_produces(): void
    {
        // packages/workdo/Api/src/documentation/theme.json documents the verify request as
        // {"email": ..., "otp": "1234"} -- a four-character string. This pins that the default
        // still matches the only published contract for this endpoint.
        $doc = json_decode(
            (string) file_get_contents(base_path('packages/workdo/Api/src/documentation/theme.json')),
            true
        );

        $documented = null;

        array_walk_recursive($doc, function ($value, $key) use (&$documented) {
            // The verify-otp entry's request.otp.
            if ($key === 'otp' && is_string($value) && preg_match('/^[0-9]+$/', $value)) {
                $documented = $value;
            }
        });

        $this->assertNotNull($documented,
            'The API documentation no longer carries an example OTP; the compatibility argument '
            . 'in this file needs re-deriving from whatever replaced it.');

        $this->assertSame(
            strlen($documented),
            app(CustomerPasswordResetService::class)->otpDigits(),
            'The configured OTP length no longer matches the length the published API '
            . 'documentation shows clients sending (' . $documented . '). That is exactly the '
            . 'compatibility risk this test exists to flag.'
        );
    }

    // ===================================================================================
    // AND SIX WORKS, END TO END, TODAY
    // ===================================================================================

    public function test_the_whole_recovery_flow_succeeds_with_six_digit_codes(): void
    {
        config(['auth.customer_password_reset.otp_digits' => 6]);

        $this->assertSame(6, app(CustomerPasswordResetService::class)->otpDigits());

        [$store, $customer] = $this->storeWithCustomer('s1');

        $otp = $this->sendOtp($store, $customer);

        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $otp,
            'The configured length was not honoured.');

        // Verify, with the response shape unchanged.
        $verify = $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email,
            'otp' => $otp,
        ])->assertStatus(200)->assertJsonPath('status', 1);

        foreach (['id', 'first_name', 'last_name', 'image', 'name', 'email', 'mobile',
                  'company_name', 'country_id', 'state_id', 'city', 'address', 'postcode',
                  'token', 'token_type', 'message'] as $field) {
            $this->assertArrayHasKey($field, $verify->json('data'),
                "verify-otp stopped returning `{$field}` at six digits; the documented response "
                . 'shape must not depend on the code length.');
        }

        $chosen = 'six-digit-flow-value-s1';

        $this->postJson('/api/' . $store->slug . '/forgot-password-save', [
            'email' => $customer->email,
            'password' => $chosen,
            'reset_token' => $verify->json('data.reset_token'),
        ])->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertTrue(Hash::check($chosen, $customer->fresh()->password),
            'The six-digit flow did not actually set the password.');
    }

    public function test_at_six_digits_a_wrong_code_of_the_same_length_is_still_refused(): void
    {
        config(['auth.customer_password_reset.otp_digits' => 6]);

        [$store, $customer] = $this->storeWithCustomer('s2');
        $otp = $this->sendOtp($store, $customer);

        // A different six-digit value.
        $wrong = str_pad((string) ((((int) $otp) + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        $this->assertNotSame($otp, $wrong);

        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => $wrong,
        ])->assertJsonPath('status', 0);

        $this->assertPasswordStillOriginal($customer);
    }

    public function test_at_six_digits_a_four_digit_guess_is_refused(): void
    {
        config(['auth.customer_password_reset.otp_digits' => 6]);

        [$store, $customer] = $this->storeWithCustomer('s3');
        $otp = $this->sendOtp($store, $customer);

        // A client stuck on four characters would send a truncation. It must not verify.
        $this->postJson('/api/' . $store->slug . '/forgot-password-verify-otp', [
            'email' => $customer->email, 'otp' => substr($otp, 0, 4),
        ])->assertJsonPath('status', 0);

        $this->assertPasswordStillOriginal($customer);
    }

    public function test_the_attempt_limit_and_send_cap_are_unchanged_at_six_digits(): void
    {
        config(['auth.customer_password_reset.otp_digits' => 6]);

        [$store, $customer] = $this->storeWithCustomer('s4');
        $otp = $this->sendOtp($store, $customer);

        $service = app(CustomerPasswordResetService::class);

        for ($i = 0; $i < CustomerPasswordResetService::MAX_ATTEMPTS; $i++) {
            $this->assertNull($service->verifyOtp($customer, (int) $store->id, '000000'));
        }

        $this->assertNull($service->verifyOtp($customer, (int) $store->id, $otp),
            'The attempt limit stopped binding at six digits.');

        // And the send cap still counts the send that has already happened.
        for ($i = 1; $i < CustomerPasswordResetService::MAX_SENDS_PER_WINDOW; $i++) {
            $this->postJson('/api/' . $store->slug . '/forgot-password-send-otp', [
                'email' => $customer->email,
            ])->assertJsonPath('status', 1);
        }

        $this->postJson('/api/' . $store->slug . '/forgot-password-send-otp', [
            'email' => $customer->email,
        ])->assertJsonPath('status', 0);
    }

    public function test_the_email_carries_the_configured_length_and_the_template_does_not_constrain_it(): void
    {
        config(['auth.customer_password_reset.otp_digits' => 6]);

        [$store, $customer] = $this->storeWithCustomer('s5');
        $otp = $this->sendOtp($store, $customer);

        // The rendered mail must contain the whole code, not a truncation.
        Mail::assertSent(\App\Mail\SendOTP::class, function ($mail) use ($otp) {
            $rendered = $mail->render();

            return str_contains($rendered, $otp);
        });

        // And the template itself must not hardcode a digit count anywhere.
        $template = (string) file_get_contents(resource_path('views/email/sendOTP.blade.php'));

        foreach (['4-digit', 'four-digit', '{4}', 'maxlength'] as $constraint) {
            $this->assertStringNotContainsStringIgnoringCase($constraint, $template,
                "The OTP email template constrains the code length ({$constraint}).");
        }
    }

    // ===================================================================================
    // A MISCONFIGURATION MUST NOT WEAKEN IT
    // ===================================================================================

    #[DataProvider('unsafeConfigurations')]
    public function test_an_unsafe_configured_length_is_clamped(mixed $configured, int $expected): void
    {
        config(['auth.customer_password_reset.otp_digits' => $configured]);

        $this->assertSame($expected, app(CustomerPasswordResetService::class)->otpDigits(),
            'A configured OTP length of ' . var_export($configured, true) . ' was not clamped to a '
            . 'safe value. A typo in the environment must never be able to shorten the code.');
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'one digit'      => [1, CustomerPasswordResetService::MIN_OTP_DIGITS],
            'zero'           => [0, CustomerPasswordResetService::MIN_OTP_DIGITS],
            'negative'       => [-6, CustomerPasswordResetService::MIN_OTP_DIGITS],
            'absurdly long'  => [64, CustomerPasswordResetService::MAX_OTP_DIGITS],
            'non-numeric'    => ['six', CustomerPasswordResetService::OTP_DIGITS],
            'null'           => [null, CustomerPasswordResetService::OTP_DIGITS],
            'numeric string' => ['6', 6],
            'at the floor'   => [4, 4],
            'at the ceiling' => [8, 8],
        ];
    }

    public function test_a_clamped_short_configuration_still_issues_a_safe_code(): void
    {
        // Someone sets CUSTOMER_OTP_DIGITS=1. The issued code must be the floor, not one digit.
        config(['auth.customer_password_reset.otp_digits' => 1]);

        [$store, $customer] = $this->storeWithCustomer('c1');

        $this->assertMatchesRegularExpression(
            '/^[0-9]{' . CustomerPasswordResetService::MIN_OTP_DIGITS . '}$/',
            $this->sendOtp($store, $customer),
            'A one-digit configuration produced a one-digit code.'
        );
    }

    // ===================================================================================
    // THE GENERATOR
    // ===================================================================================

    public function test_the_generator_honours_every_permitted_length(): void
    {
        for ($n = CustomerPasswordResetService::MIN_OTP_DIGITS; $n <= CustomerPasswordResetService::MAX_OTP_DIGITS; $n++) {
            for ($i = 0; $i < 20; $i++) {
                $this->assertMatchesRegularExpression('/^[0-9]{' . $n . '}$/', Utility::generateNumericOTP($n),
                    "generateNumericOTP({$n}) did not return exactly {$n} digits.");
            }
        }
    }

    public function test_a_six_digit_code_still_comes_from_a_cryptographic_source(): void
    {
        mt_srand(987654);
        $first = Utility::generateNumericOTP(6);

        mt_srand(987654);
        $second = Utility::generateNumericOTP(6);

        $this->assertNotSame($first, $second,
            'Six-digit codes are reproducible from a seeded generator, so the length change would '
            . 'have reintroduced predictability.');
    }

    public function test_generateNumericOTP_has_exactly_one_caller_so_the_length_is_not_shared(): void
    {
        // If a second feature ever starts using this generator, changing the recovery length would
        // silently drag that feature along. This pins that it does not.
        $callers = [];

        foreach ([base_path('app'), base_path('packages/workdo'), base_path('database')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (str_contains($source, 'generateNumericOTP(') && ! str_contains($source, 'function generateNumericOTP')) {
                    $callers[] = str_replace(base_path() . '/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            ['app/Http/Controllers/Api/AuthController.php'],
            $callers,
            'generateNumericOTP now has callers other than the storefront recovery handler, so the '
            . 'configured length would affect them too. Observed: ' . implode(', ', $callers)
        );
    }

    private function assertPasswordStillOriginal(Customer $customer): void
    {
        $this->assertTrue(Hash::check('original-password-not-real', $customer->fresh()->password),
            'The password changed when it should not have.');
    }
}
