<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-13: `customers.password_otp` is an INTEGER column that the web password-reset flow
 * writes a 60-character bcrypt hash into.
 *
 * WHAT THESE TESTS CAN AND CANNOT ESTABLISH, stated first because it decides how much the green
 * ticks below are worth.
 *
 * CAN: that the column is declared integer and that no migration alters it; that the prepared
 * migration widens it and reverses; that the web flow's verify step now bounds wrong answers;
 * that both of its POST routes are throttled.
 *
 * CANNOT: the MySQL failure itself. This container has the PDO mysql client driver and no MySQL
 * server (measured). SQLite's type affinity stores the hash unchanged, which is exactly why the
 * existing suite never caught this. The engine behaviour is therefore argued from the declared
 * type plus documented MySQL semantics -- error 1366 under STRICT_TRANS_TABLES, coercion to 0
 * without it -- and the first precondition in the draft's docblock is to confirm it on the real
 * server. No test here should be read as evidence about MySQL.
 */
class CustomerPasswordOtpColumnMigrationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function draftPath(): string
    {
        return base_path('database/migrations/_sec13_pending/0001_draft_widen_customers_password_otp.php.draft');
    }

    // ===================================================================================
    // THE DEFECT, AS THE REPOSITORY STATES IT
    // ===================================================================================

    public function test_the_column_is_declared_integer_and_nothing_alters_it(): void
    {
        $create = file_get_contents(
            base_path('database/migrations/2023_12_15_112855_create_customers_table.php')
        );

        $this->assertStringContainsString("integer('password_otp')", $create,
            'The create migration no longer declares password_otp as an integer; this finding '
            . 'and its prepared migration need re-deriving.');

        $altering = [];

        foreach (glob(base_path('database/migrations/*.php')) ?: [] as $file) {
            if (basename($file) === '2023_12_15_112855_create_customers_table.php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file), 'password_otp')) {
                $altering[] = basename($file);
            }
        }

        $this->assertSame([], $altering,
            'A migration now touches password_otp: ' . implode(', ', $altering));
    }

    public function test_the_web_flow_writes_a_bcrypt_hash_into_that_column(): void
    {
        $source = file_get_contents(
            base_path('app/Http/Controllers/Customer/Auth/CustomerLoginController.php')
        );

        $this->assertStringContainsString('$customer->password_otp = Hash::make((string) $otp);', $source,
            'The web flow no longer writes a hash to password_otp, so the type mismatch may be '
            . 'resolved differently than this package assumes.');

        // 60 characters for bcrypt, against a column that holds at most 11 digits.
        $this->assertSame(60, strlen(Hash::make('123456')),
            'The hashing driver no longer produces 60-character output; the prepared column width '
            . 'should be re-checked against it.');
    }

    // ===================================================================================
    // THE PREPARED MIGRATION IS NOT RUNNABLE
    // ===================================================================================

    public function test_the_prepared_migration_cannot_be_discovered_or_run(): void
    {
        $this->assertFileExists($this->draftPath());

        // Property 1: Laravel's own non-recursive glob cannot reach it.
        $discovered = glob(base_path('database/migrations/*_*.php')) ?: [];

        foreach ($discovered as $file) {
            $this->assertStringNotContainsString('_sec13_pending', $file,
                'The P01-SEC-13 draft is discoverable by the migration glob. It must not be '
                . 'applicable by a deploy that runs `php artisan migrate`.');
        }

        // Property 2: nothing in the directory ends in .php, so it stays unrunnable even if the
        // directory were ever registered as a migration path.
        $this->assertSame([], array_map('basename', glob(dirname($this->draftPath()) . '/*.php') ?: []),
            'A file in the P01-SEC-13 staging directory is named *.php rather than *.php.draft.');

        $this->assertFileExists(dirname($this->draftPath()) . '/README.md');
    }

    public function test_nothing_registers_the_staging_directory_as_a_migration_path(): void
    {
        foreach (glob(base_path('app/Providers/*.php')) ?: [] as $provider) {
            $this->assertStringNotContainsString('_sec13_pending', (string) file_get_contents($provider));
        }

        $this->assertNotContains(
            realpath(dirname($this->draftPath())),
            array_map('realpath', app('migrator')->paths()),
            'The staging directory is a registered migration path.'
        );
    }

    // ===================================================================================
    // AND IT WORKS -- EXECUTED, NOT ASSERTED FROM ITS SOURCE
    // ===================================================================================

    /**
     * The draft is loaded and run against the real test schema, then reversed. This is the only
     * evidence that it is a working migration rather than a plausible-looking file.
     */
    public function test_the_prepared_migration_widens_the_column_and_preserves_every_row(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'sec13',
            'email' => 'sec13-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        // A pre-existing numeric value, of the kind a server would already be holding.
        DB::table('customers')->where('id', $customer->id)->update(['password_otp' => 4321]);

        $customersBefore = DB::table('customers')->count();
        $emailBefore = DB::table('customers')->where('id', $customer->id)->value('email');
        $passwordBefore = DB::table('customers')->where('id', $customer->id)->value('password');

        $migration = require $this->draftPath();
        $migration->up();

        // Every row and every other column is untouched.
        $this->assertSame($customersBefore, DB::table('customers')->count(),
            'The migration changed the number of customer rows.');
        $this->assertSame($emailBefore, DB::table('customers')->where('id', $customer->id)->value('email'));
        $this->assertSame($passwordBefore, DB::table('customers')->where('id', $customer->id)->value('password'));
        $this->assertSame('4321', (string) DB::table('customers')->where('id', $customer->id)->value('password_otp'),
            'The pre-existing value did not survive the widening.');

        // And the column now holds what the web flow writes to it.
        $hash = Hash::make('123456');
        DB::table('customers')->where('id', $customer->id)->update(['password_otp' => $hash]);

        $stored = (string) DB::table('customers')->where('id', $customer->id)->value('password_otp');

        $this->assertSame(60, strlen($stored),
            'The widened column truncated the hash.');
        $this->assertTrue(Hash::check('123456', $stored),
            'The stored value is no longer a usable hash, so the web verify step would still fail.');
    }

    public function test_the_prepared_migration_reverses_and_says_what_that_costs(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'sec13b',
            'email' => 'sec13b-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $migration = require $this->draftPath();
        $migration->up();

        // An in-flight reset at the moment of rollback.
        DB::table('customers')->where('id', $customer->id)->update(['password_otp' => Hash::make('123456')]);

        $rows = DB::table('customers')->count();

        $migration->down();

        $this->assertSame($rows, DB::table('customers')->count(),
            'The rollback changed the number of customer rows.');

        // The hash cannot survive the narrowing, and is cleared rather than coerced to a 0 the
        // verify step would read as "a code exists".
        $this->assertNull(DB::table('customers')->where('id', $customer->id)->value('password_otp'),
            'The rollback left a coerced value in the column instead of clearing it.');

        $this->assertNotNull(DB::table('customers')->where('id', $customer->id)->value('email'),
            'The rollback damaged the customer row.');
    }

    public function test_the_prepared_migration_is_a_no_op_where_the_column_is_already_absent(): void
    {
        Schema::table('customers', function ($table) {
            $table->dropColumn('password_otp');
        });

        $migration = require $this->draftPath();

        // Guarded, so a server provisioned outside these migrations cannot be broken by it.
        $migration->up();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('customers', 'password_otp'));
    }

    // ===================================================================================
    // THE CONTROLS THE MIGRATION DEPENDS ON
    //
    // Applying the widening alone turns a flow that cannot verify at all into one that can be
    // guessed. These are the reason the two belong in one release.
    // ===================================================================================

    public function test_the_web_verify_step_bounds_wrong_answers(): void
    {
        $source = file_get_contents(
            base_path('app/Http/Controllers/Customer/Auth/CustomerLoginController.php')
        );

        $this->assertStringContainsString('MAX_OTP_ATTEMPTS', $source,
            'The web verify step has no attempt limit. The prepared migration must not be '
            . 'applied until it does -- it would make a dead flow brute-forceable.');

        $this->assertSame(
            \App\Services\CustomerPasswordResetService::MAX_ATTEMPTS,
            \App\Http\Controllers\Customer\Auth\CustomerLoginController::MAX_OTP_ATTEMPTS,
            'The two surfaces of one feature disagree about how many wrong answers are allowed.'
        );
    }

    public function test_both_web_otp_routes_are_throttled(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['customer.password.otp.send', 'customer.password.otp.verify'] as $name) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, "Route {$name} is gone.");

            $this->assertContains('throttle:auth', $route->gatherMiddleware(),
                "Route {$name} carries no rate limiter, so codes can be minted or guessed as fast "
                . 'as the server will answer.');
        }
    }

    /**
     * THE LIMIT IS EXERCISED, not just present in the source. Driven through the real route so
     * the session binding, the expiry check and the counter are all in play.
     *
     * This runs on SQLite, where the INTEGER column accepts the hash -- which is the only reason
     * the flow can be driven at all here. On MySQL today it would refuse at the `empty()` check
     * before ever reaching the counter.
     */
    public function test_the_attempt_limit_destroys_the_code_after_five_wrong_answers(): void
    {
        [$merchant, $store] = $this->makeTenant();

        Setting::forceCreate([
            'name' => 'MAIL_DRIVER', 'value' => 'log',
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'sec13c',
            'email' => 'sec13c-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        // The state the send step leaves behind, written directly so this test does not depend on
        // the store's SMS/SMTP integration being configured.
        $customer->password_otp = Hash::make('123456');
        $customer->password_otp_datetime = now();
        $customer->save();

        $this->withSession([
            'password_otp_customer_id' => $customer->id,
            'password_otp_store_id' => $store->id,
            'password_otp_store_slug' => $store->slug,
        ]);

        for ($i = 0; $i < \App\Http\Controllers\Customer\Auth\CustomerLoginController::MAX_OTP_ATTEMPTS; $i++) {
            $this->post('/' . $store->slug . '/forgot-password/otp/verify', ['otp' => '000000']);
        }

        // The budget is spent, so even the CORRECT code must now be refused...
        $this->post('/' . $store->slug . '/forgot-password/otp/verify', ['otp' => '123456']);

        // ...and the code itself is destroyed rather than left for further guessing.
        $this->assertNull($customer->fresh()->password_otp,
            'The code survived the attempt limit, so guessing can continue against it.');

        $this->assertFalse((bool) session('password_otp_verified'),
            'The flow granted verification after the attempt limit was exhausted.');
    }
}
