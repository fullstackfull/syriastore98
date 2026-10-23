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

    // ---- (2b) the five sites that cannot be edited: the web-group scrubber -----------------------

    /**
     * THE FOUR FIXED SITES WERE NOT THE WHOLE SET.
     *
     * The two tests above cover the host-app handlers P1-SEC-16 named, which were changed to
     * `withInput($request->except([...]))`. A census keyed on the CONSTRUCT rather than on the files
     * somebody had read finds five more bare `withInput()` calls, ALL of them under
     * `packages/workdo/` -- vendored commercial code that must not be edited
     * (guard-no-workdo-changes.sh). One of them is a credential sink in the same sense as the four:
     * EMailBox's mailbox-settings save flashes `emailbox_mail_password` on its catch branch.
     *
     * So the control is a host-app middleware on the `web` group,
     * `App\Http\Middleware\FlashedCredentialScrubber`, which deletes credential-named keys from
     * `_old_input` on the response leg. It covers every one of those sites and any future one,
     * because `_old_input` is written by the session and the session is started by `StartSession`,
     * which is in that same group -- a route that can flash input is by construction a route the
     * scrubber runs on.
     *
     * Driven through a route registered here rather than through EMailBox's own: the module's route
     * needs the module enabled, a plan entitlement and a permission, and none of that is what is
     * under test. What is under test is the middleware's contract, and the census below pins the
     * five sites it exists for.
     */
    private function flashingRoute(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')
            ->post('/p1-sec-16/flash-everything', fn () => back()->withInput())
            ->name('p1sec16.flash');
    }

    public function test_the_scrubber_runs_on_every_web_route(): void
    {
        $this->flashingRoute();

        $route = $this->app['router']->getRoutes()->match(
            \Illuminate\Http\Request::create('/p1-sec-16/flash-everything', 'POST')
        );

        $this->assertContains('web', $route->gatherMiddleware(), 'Premise: the route really is in the web group.');

        /*
         * Asked of the GROUP, because `gatherMiddleware()` returns the group's NAME unexpanded -- the
         * same property that makes this registration survive `route:cache`.
         */
        $group = $this->app['router']->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(\App\Http\Middleware\FlashedCredentialScrubber::class, $group,
            'The scrubber is not on the web group, so the five vendored withInput() sites are unprotected.');
        $this->assertContains(\Illuminate\Session\Middleware\StartSession::class, $group,
            'Premise: _old_input is written by the session, which this group starts -- which is why '
            . 'covering the group covers every flashing route.');

        /*
         * APPENDED, so on the response leg it unwinds FIRST -- before StartSession::saveSession()
         * writes the payload (it is called after $next inside handle(), not in terminate()). A
         * registration ahead of StartSession would scrub a payload that is then overwritten.
         *
         * THIS ASSERTION IS NOT DECORATION: it is the only one that catches a mis-registration.
         * Measured -- with `prependToGroup` instead of `appendToGroup`, the two behavioural tests
         * below still PASS, because `session()->get('_old_input')` reads the in-memory store, which
         * by then has been scrubbed, while the row written to storage was not. Under the array
         * driver the suite uses there is no `sessions` row to read back, so the order is the
         * observable.
         */
        $this->assertGreaterThan(
            array_search(\Illuminate\Session\Middleware\StartSession::class, $group, true),
            array_search(\App\Http\Middleware\FlashedCredentialScrubber::class, $group, true),
            'The scrubber must sit AFTER StartSession in the group, or the session is saved before it runs.'
        );
    }

    public function test_a_bare_with_input_leaves_no_credential_in_the_session(): void
    {
        $this->flashingRoute();

        $this->post('/p1-sec-16/flash-everything', [
            'emailbox_mail_password' => 'SMTP-SENTINEL-FIXTURE-1',
            'emailbox_mail_username' => 'mailbox@example.test',
            'cardNumber' => '4111111111111111',
            'cvv' => '123',
            'stripe_secret_key' => 'SECRET-SENTINEL-FIXTURE-2',
            'login_pin' => '9182',
            'otp' => '554433',
            'cookie' => 'COOKIE-SENTINEL-FIXTURE-3',
            'emailbox_mail_host' => 'smtp.example.test',
            'emailbox_outgoing_port' => '587',
        ]);

        $old = session()->get('_old_input', []);

        $this->assertSame('smtp.example.test', $old['emailbox_mail_host'] ?? null,
            'Premise: the diagnostic fields are still flashed, so a missing password is not an empty _old_input.');
        $this->assertSame('mailbox@example.test', $old['emailbox_mail_username'] ?? null,
            'A username is not a credential by this predicate and the form still needs it back.');
        $this->assertSame('587', $old['emailbox_outgoing_port'] ?? null);

        foreach (['emailbox_mail_password', 'cardNumber', 'cvv', 'stripe_secret_key', 'login_pin', 'otp', 'cookie'] as $name) {
            $this->assertArrayNotHasKey($name, $old, "{$name} was flashed into sessions.payload in plaintext.");
        }

        // and the VALUES are gone, not merely the top-level keys
        $serialised = serialize($old);

        foreach (['SMTP-SENTINEL-FIXTURE-1', '4111111111111111', 'SECRET-SENTINEL-FIXTURE-2', 'COOKIE-SENTINEL-FIXTURE-3'] as $value) {
            $this->assertStringNotContainsString($value, $serialised);
        }
    }

    /** A payment form posts `payment[card_number]`; a settings form posts `settings[smtp_password]`. */
    public function test_a_nested_credential_is_removed_at_every_depth(): void
    {
        $this->flashingRoute();

        $this->post('/p1-sec-16/flash-everything', [
            'payment' => ['card_number' => 'NESTED-CARD-FIXTURE', 'brand' => 'visa'],
            'settings' => ['smtp' => ['password' => 'NESTED-PW-FIXTURE', 'host' => 'deep.example.test']],
        ]);

        $old = session()->get('_old_input', []);

        $this->assertSame('visa', $old['payment']['brand'] ?? null, 'Premise: the nested non-credential survived.');
        $this->assertSame('deep.example.test', $old['settings']['smtp']['host'] ?? null);
        $this->assertStringNotContainsString('NESTED-CARD-FIXTURE', serialize($old));
        $this->assertStringNotContainsString('NESTED-PW-FIXTURE', serialize($old));
    }

    /**
     * THE CENSUS, keyed on the construct. A new bare `withInput()` anywhere is covered by the
     * middleware already -- this exists so that the five sites the middleware was written for cannot
     * silently become six host-app ones that should have been fixed at source instead.
     */
    public function test_the_bare_with_input_census_is_what_the_middleware_was_written_for(): void
    {
        $found = [];

        foreach ([base_path('app'), base_path('packages')] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $entry) {
                if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }

                // comment-stripped: this file's own docblock names the construct, and so does the

                // middleware's -- the recorded trap for a source ratchet is prose satisfying it.

                $code = '';


                foreach (token_get_all((string) file_get_contents($entry->getPathname())) as $token) {

                    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {

                        continue;

                    }


                    $code .= is_array($token) ? $token[1] : $token;

                }

                if (preg_match_all('/->withInput\(\s*\)/', $code) > 0) {
                    $found[] = ltrim(str_replace(base_path(), '', $entry->getPathname()), '/');
                }
            }
        }

        sort($found);

        $this->assertSame([
            // the order-creation form: no credential field in its validator, so nothing to lose
            'app/Http/Controllers/OrderController.php',
            'packages/workdo/EMailBox/src/app/Http/Controllers/Company/SettingsController.php',
            'packages/workdo/Helpdesk/src/app/Http/Controllers/HelpdeskConversionController.php',
            'packages/workdo/SidebarCustomization/src/app/Http/Controllers/SideMenuController.php',
            'packages/workdo/SidebarCustomization/src/app/Http/Controllers/SubMenuController.php',
        ], $found,
            'The set of handlers that flash their whole request body changed. A new HOST-APP site '
            . 'should exclude its credential fields at source (the middleware is the net for the '
            . 'vendored ones, not a licence to stop doing that).');
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
