<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * NEW-sqlite-migration-blocker / SCHEMA-02 / SCHEMA-03 / SCHEMA-04 / MONEY-08 — Increment 2
 * acceptance criteria, as executable assertions.
 *
 * These are the acceptance criteria of Increment 2 in
 * 04-INCREMENTAL-IMPLEMENTATION-PLAN.md turned into tests:
 *   1. `migrate:fresh` exits 0 on the SQLite test connection.
 *   2. A Customer can be created (today the SoftDeletes global scope appends
 *      `and customers.deleted_at is null` against a non-existent column and EVERY Customer
 *      query fails).
 *   3. An Order can be created and the `creating` hook's currency_code/currency_symbol persist.
 *   4. `orders.created_by` exists so OrderController::store can write it.
 *   5. Every new statement is hasColumn/hasTable guarded, so re-running is a no-op.
 *
 * NOT RUN IN THE ENVIRONMENT THIS WAS WRITTEN IN -- reported as BLOCKED, not as passing.
 * PHPUnit cannot be installed in these checkouts: three locked packages are unreachable from
 * here (lahirulhr/laravel-payhere distributes as an api.github.com zipball the proxy refuses for
 * out-of-scope repositories, and yoomoney/* is hosted on git.yoomoney.ru). Resolving that touches
 * composer.json and is an owner decision. The companion
 * tests/Unit/Database/MigrationHygieneTest.php needs no database and no framework and HAS been
 * executed; this class is the half that needs a booted application.
 *
 * SAFETY: this class relies on tests/bootstrap.php (Layer 0) and tests/TestCase.php (Layer 3) to
 * pin the connection to sqlite/:memory: before any DDL. It calls `migrate:fresh`, which DROPS
 * EVERY TABLE in whatever database it is pointed at, so it must never run without those guards.
 * It additionally re-asserts the connection itself before issuing the command.
 */
class MigrationPortabilityTest extends TestCase
{
    /**
     * Belt-and-braces: never issue migrate:fresh without proving the target first.
     */
    private function assertTargetIsInMemorySqlite(): void
    {
        $connection = config('database.default');

        $this->assertSame('sqlite', config("database.connections.{$connection}.driver"));
        $this->assertSame(':memory:', config("database.connections.{$connection}.database"));
    }

    /**
     * Increment 2, acceptance criterion 1. FAILS before the two driver guards land.
     */
    public function test_migrate_fresh_succeeds_on_sqlite(): void
    {
        $this->assertTargetIsInMemorySqlite();

        $this->artisan('migrate:fresh')->assertExitCode(0);

        // A from-zero build that exits 0 but produced nothing would pass vacuously.
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('customers'));
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('user_coupons'));
    }

    /**
     * The five code-derivable columns Increment 2 declares.
     */
    public function test_from_zero_schema_has_the_five_code_derivable_columns(): void
    {
        $this->assertTargetIsInMemorySqlite();
        $this->artisan('migrate:fresh')->assertExitCode(0);

        $this->assertTrue(
            Schema::hasColumn('customers', 'deleted_at'),
            'SCHEMA-03: Customer uses SoftDeletes (app/Models/Customer.php:16,20), so every '
            . 'Customer query appends "and customers.deleted_at is null". Without the column there '
            . 'is no working customers guard at all.'
        );

        $this->assertTrue(
            Schema::hasColumn('orders', 'currency_code'),
            'SCHEMA-02: app/Models/Order.php:68-72 assigns currency_code on EVERY insert.'
        );

        $this->assertTrue(
            Schema::hasColumn('orders', 'currency_symbol'),
            'SCHEMA-02: app/Models/Order.php:74-78 assigns currency_symbol on EVERY insert.'
        );

        $this->assertTrue(
            Schema::hasColumn('orders', 'created_by'),
            'MONEY-08: app/Http/Controllers/OrderController.php:159 passes created_by to Order::create().'
        );

        $this->assertTrue(
            Schema::hasColumn('users', 'deliverysyria_secret'),
            'SCHEMA-04: written at app/Http/Controllers/SettingController.php:99 and :118.'
        );
    }

    /**
     * Increment 2, acceptance criterion 5 -- the property that makes the whole set safe to run
     * against the real schema later. Running the catch-up migrations a second time over a schema
     * that already has every object must do nothing and must not error.
     */
    public function test_rerunning_the_catch_up_migrations_is_a_no_op(): void
    {
        $this->assertTargetIsInMemorySqlite();
        $this->artisan('migrate:fresh')->assertExitCode(0);

        $before = [
            'customers' => Schema::getColumnListing('customers'),
            'orders' => Schema::getColumnListing('orders'),
            'users' => Schema::getColumnListing('users'),
        ];

        // Forget the catch-up migrations so `migrate` re-runs their up() over a populated schema,
        // which is exactly the production-rerun scenario the guards exist for.
        DB::table('migrations')->where('migration', 'like', '2026_10_12_%')->delete();

        $this->artisan('migrate')->assertExitCode(0);

        foreach ($before as $table => $columns) {
            $this->assertSame(
                $columns,
                Schema::getColumnListing($table),
                "Re-running the catch-up migrations changed {$table}. They must be no-ops against a "
                . 'database that already holds the object.'
            );
        }
    }

    /**
     * Increment 2, acceptance criterion 2. The SoftDeletes trait must stay on the model, and the
     * soft-delete semantics must actually work rather than merely not crash.
     */
    public function test_customer_can_be_created_and_soft_delete_semantics_hold(): void
    {
        $this->assertTargetIsInMemorySqlite();
        $this->artisan('migrate:fresh')->assertExitCode(0);

        $customer = \App\Models\Customer::create([
            'first_name' => 'Layla',
            'last_name' => 'Haddad',
            'email' => 'layla.haddad@example.test',
            'password' => 'correct-horse-battery-staple',
            'store_id' => 1,
        ]);

        $this->assertNotNull($customer->id);
        $this->assertNull($customer->deleted_at);

        $customer->delete();

        // A soft delete, NOT a hard one. If the trait were ever removed to "fix" the schema
        // mismatch, this assertion is what would catch the data loss.
        $this->assertNull(\App\Models\Customer::find($customer->id));
        $this->assertNotNull(\App\Models\Customer::withTrashed()->find($customer->id));
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    /**
     * Increment 2, acceptance criterion 3. The `creating` hook at app/Models/Order.php:65-78 must
     * land its values in real columns.
     */
    public function test_order_insert_persists_currency_code_and_symbol(): void
    {
        $this->assertTargetIsInMemorySqlite();
        $this->artisan('migrate:fresh')->assertExitCode(0);

        $order = \App\Models\Order::create([
            'product_order_id' => 'ORD-TEST-0001',
            'customer_id' => 1,
            'store_id' => 1,
            'final_price' => 10,
        ]);

        $order->refresh();

        $this->assertNotNull(
            $order->currency_code,
            'app/Models/Order.php:68-72 falls back to USD, so this can never legitimately be null.'
        );
        $this->assertNotNull($order->currency_symbol);
    }
}
