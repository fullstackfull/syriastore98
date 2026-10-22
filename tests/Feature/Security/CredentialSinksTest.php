<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\Concerns\RedactsApiLogFields;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-16 -- CREDENTIALS AT REST OUTSIDE THE VISIT LOG.
 * =======================================================
 *
 * (1) The API log scrubber only redacted password/otp/token/secret/authorization/api_key, so a field named `pass`, `pwd`, `key`, a card field,
 *     `cvv`, `pin`, a one-time `code` or `cookie` reached storage/logs/api-*.log (15-day retention) in plaintext - and `user_detail` wrote
 *     the WHOLE request body to that channel without going through the scrubber at all.
 * (2) `->withInput()` with no argument flashes every field, password included, into `sessions.payload` (session encryption is off).
 * (3) `google2fa_secret`, `deliverysyria_secret` and `password_otp` rode `toArray()`/JSON.
 * (4) UserController::store COPIED the creator's TOTP seed and enable flag onto the new sub-user: two accounts, one authenticator secret.
 */
class CredentialSinksTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    // ---- (1) the scrubber ---------------------------------------------------------------------

    private function scrubber(): object
    {
        return new class {
            use RedactsApiLogFields {
                scrub as public;
                shouldRedact as public;
            }
        };
    }

    public static function credentialNames(): array
    {
        $names = ['pass', 'user_pass', 'passwd', 'pwd', 'api_key', 'apiKey', 'x-api-key', 'stripe_key', 'card', 'card_number', 'cardNumber', 'cvv', 'cvc',
            'pin', 'login_pin', 'otp_code', 'code', 'verification_code', 'cookie', 'Cookie', 'signature', 'session_id', 'csrf_token', 'bearer',
            // the names the original list already covered must keep being covered
            'password', 'new_password_confirmation', 'otp', 'token', 'access_token', 'secret', 'client_secret', 'authorization'];

        return array_combine($names, array_map(fn ($n) => [$n], $names));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('credentialNames')]
    public function test_a_credential_named_field_is_redacted(string $name): void
    {
        $clean = $this->scrubber()->scrub([$name => 'SENTINEL-FIXTURE-VALUE', 'nested' => [$name => 'SENTINEL-FIXTURE-VALUE']]);

        $this->assertSame('[REDACTED]', $clean[$name], "`{$name}` reached the log in plaintext.");
        $this->assertSame('[REDACTED]', $clean['nested'][$name]);
    }

    public static function ordinaryNames(): array
    {
        $names = ['name', 'email', 'shipping_address', 'mapping', 'monkey', 'product_id', 'quantity', 'city', 'pinned', 'spinner', 'status'];

        return array_combine($names, array_map(fn ($n) => [$n], $names));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ordinaryNames')]
    public function test_an_ordinary_field_is_still_logged_for_diagnostics(string $name): void
    {
        $this->assertSame('visible', $this->scrubber()->scrub([$name => 'visible'])[$name], "`{$name}` was redacted: the diagnostic value of the log shrank.");
    }

    public function test_no_api_log_write_hands_the_raw_request_body_to_the_logger(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'RedactsApiLogFields')) {
                continue;
            }

            foreach (file($file->getPathname()) as $n => $line) {
                if (str_contains($line, "channel('API_log')") && preg_match('/json_encode\(\s*\$request->(all|input)\(\)\s*\)/', $line)) {
                    $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "These write the unscrubbed request body to the API log:\n" . implode("\n", $offenders));
    }

    public function test_user_detail_logs_a_scrubbed_body(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/Api/ApiController.php'));

        $this->assertStringContainsString('use \\App\\Http\\Middleware\\Concerns\\RedactsApiLogFields;', $source, 'ApiController no longer has the scrubber.');
        $this->assertStringContainsString("Log::channel('API_log')->info(json_encode(\$this->scrub(\$request->all())));", $source, 'user_detail() no longer logs through the scrubber.');
    }

    // ---- (2) flashed input --------------------------------------------------------------------

    public function test_a_failed_customer_creation_does_not_flash_the_password_into_the_session(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Customer');

        // invalid on purpose (no first_name) so the handler takes its validation-failure path
        $this->actingAs($merchant->fresh())->post('/customer', ['last_name' => 'L', 'email' => 'flash-FIXTURE@example.test', 'password' => 'PW-SENTINEL-FIXTURE-1', 'password_confirmation' => 'PW-SENTINEL-FIXTURE-1', 'status' => 1]);

        $old = session()->get('_old_input', []);
        $this->assertSame('flash-FIXTURE@example.test', $old['email'] ?? null, 'Premise: the failure path flashed the (non-secret) input, so a missing password is not a handler that never ran.');
        $this->assertArrayNotHasKey('password', $old, 'The password was flashed into the session.');
        $this->assertArrayNotHasKey('password_confirmation', $old);
    }

    public function test_a_failed_delivery_boy_save_does_not_flash_the_password_into_the_session(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create DeliveryBoy');

        $this->actingAs($merchant->fresh())->post('/deliveryboy', ['name' => '', 'email' => 'db-FIXTURE@example.test', 'password' => 'PW-SENTINEL-FIXTURE-2']);

        $old = session()->get('_old_input', []);
        $this->assertSame('db-FIXTURE@example.test', $old['email'] ?? null, 'Premise: the failure path flashed the (non-secret) input.');
        $this->assertArrayNotHasKey('password', $old, 'The password was flashed into the session.');
    }

    // ---- (3) hidden columns -------------------------------------------------------------------

    public function test_secret_columns_do_not_serialise_but_stay_readable(): void
    {
        $user = new User();
        $user->forceFill(['google2fa_secret' => 'TOTP-SEED-FIXTURE', 'deliverysyria_secret' => 'DS-SECRET-FIXTURE', 'name' => 'N']);

        $this->assertArrayNotHasKey('google2fa_secret', $user->toArray());
        $this->assertArrayNotHasKey('deliverysyria_secret', $user->toArray());
        $this->assertStringNotContainsString('TOTP-SEED-FIXTURE', $user->toJson());
        $this->assertSame('TOTP-SEED-FIXTURE', $user->google2fa_secret, 'Hiding must not change attribute access (the 2FA flow reads it).');
        $this->assertSame('DS-SECRET-FIXTURE', $user->deliverysyria_secret);

        $customer = new Customer();
        $customer->forceFill(['password_otp' => 'OTP-HASH-FIXTURE', 'email' => 'c@example.test']);

        $this->assertArrayNotHasKey('password_otp', $customer->toArray());
        $this->assertSame('OTP-HASH-FIXTURE', $customer->password_otp);
    }

    // ---- (4) the copied 2FA seed --------------------------------------------------------------

    public function test_a_user_created_by_a_2fa_enrolled_owner_does_not_inherit_the_owner_seed(): void
    {
        $failed = $this->enableWorkdoModules(['GoogleAuthentication']);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('users', 'google2fa_secret'), 'Premise: the module\'s columns exist. ' . implode('; ', $failed));

        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Create User');
        $plan = Plan::forceCreate(['name' => 'P', 'price' => 0, 'duration' => 'Unlimited', 'max_stores' => -1, 'max_products' => -1, 'max_users' => -1, 'themes' => '[]', 'storage_limit' => '-1']);
        $owner->plan_id = $plan->id;
        DB::table('users')->where('id', $owner->id)->update(['plan_id' => $plan->id, 'google2fa_enable' => 1, 'google2fa_secret' => 'OWNER-TOTP-SEED-FIXTURE']);
        (new User())->flushCache();
        $role = $this->makeRole($owner->fresh(), \App\Models\Store::find($owner->current_store), 'sub-role');

        // module_is_active() is principal-dependent: it needs the OWNER's plan to carry the module, which is what made the old copy block run
        DB::table('user_active_modules')->insert(['user_id' => $owner->id, 'module' => 'GoogleAuthentication', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner->fresh());
        $this->assertTrue(module_is_active('GoogleAuthentication'), 'Premise: the module is active for this principal, so the old copy block would have run.');

        $this->actingAs($owner->fresh())->post('/users', ['name' => 'Sub', 'email' => 'sub-FIXTURE@example.test', 'password' => 'not-a-real-password', 'role' => $role->id]);

        $created = DB::table('users')->where('email', 'sub-FIXTURE@example.test')->first();
        $this->assertNotNull($created, 'Premise: the sub-user was created.');
        $this->assertNull($created->google2fa_secret, 'The new user shares the owner\'s authenticator seed.');
        $this->assertSame(0, (int) $created->google2fa_enable, 'The new user was switched onto a seed they never enrolled.');
    }
}
