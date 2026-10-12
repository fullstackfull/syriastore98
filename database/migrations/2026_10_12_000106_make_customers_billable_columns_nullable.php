<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make `customers.billable_id` / `customers.billable_type` NULLABLE.
 *
 * ============================================================================================
 *  WHY: TWO DIFFERENT CONCEPTS SHARE ONE TABLE, AND THE APPLICATION'S HALF CANNOT INSERT.
 * ============================================================================================
 *
 * `laravel/cashier-paddle` ships
 * vendor/laravel/cashier-paddle/database/migrations/2019_05_03_000001_create_customers_table.php,
 * which does `Schema::create('customers')` with `billable_id` (unsignedBigInteger, NOT NULL) and
 * `billable_type` (string, NOT NULL). Its filename sorts as 2019, so it runs BEFORE the
 * application's own 2023_12_15_112855_create_customers_table.php.
 *
 * The application migration already knows about the collision: its `if (!Schema::hasTable(...))`
 * at :14 fails, and the `else` branch at :47-77 ALTERs the Paddle-created table to add the
 * storefront customer columns (first_name, email, password, store_id, ...). So on a from-zero
 * database the single `customers` table carries BOTH Paddle's billable-link columns AND the
 * application's storefront-customer columns. Verified by PRAGMA table_info after a clean
 * `migrate` from zero: id, billable_id, billable_type, trial_ends_at, created_at, updated_at,
 * first_name, ..., store_id, remember_token.
 *
 * `App\Models\Customer` (app/Models/Customer.php:18-21) uses that same `customers` table AND
 * also uses `Laravel\Paddle\Billable`, so the two meanings are genuinely entangled here.
 *
 * THE BREAK: nothing in the application ever writes billable_id or billable_type. They are
 * absent from App\Models\Customer::$fillable and from every creation site --
 * CustomerController.php:31, PaymentController.php:459, Api/DashboardController.php:4851 and
 * :6827, Customer/Auth/CustomerLoginController.php:713, Api/AuthController.php:55 and :75,
 * ShopifyCustomerController.php:176. Against a NOT NULL column with no default that means every
 * one of those inserts fails:
 *
 *   SQLSTATE[23000]: Integrity constraint violation: 19
 *   NOT NULL constraint failed: customers.billable_id
 *
 * i.e. on a from-source install, customer registration and guest checkout cannot create a
 * customer at all. It is invisible on a MySQL server running in non-strict mode, which silently
 * substitutes 0 for a missing NOT NULL integer -- which is why a long-lived deployment can look
 * healthy while a fresh install is broken. On SQLite and on any strict-mode MySQL it is a hard
 * failure, reproduced above.
 *
 * ============================================================================================
 *  WHY NULLABLE AND NOT A DEFAULT, AND WHY THE COLUMNS ARE NOT DROPPED.
 * ============================================================================================
 *
 * Widening NOT NULL to NULL is non-destructive and backward-compatible in both directions:
 *   - existing rows keep whatever value they hold; nothing is rewritten;
 *   - Paddle's own writes, which always supply both columns, keep working unchanged;
 *   - the application's writes, which supply neither, stop failing.
 *
 * A `default 0` was rejected: 0 is a REAL unsignedBigInteger value, so it would make every
 * storefront customer look like a billable link to entity 0 and would make
 * `whereNotNull('billable_id')` useless for telling the two kinds of row apart. NULL is the
 * honest encoding of "this row is not a Paddle billable link".
 *
 * The columns are NOT dropped. `App\Models\User` and `App\Models\Customer` both use
 * `Laravel\Paddle\Billable` (User.php:15,25 and Customer.php:14,21), so Paddle may legitimately
 * write these columns; dropping them would break that path. Disentangling the two concepts into
 * separate tables is a far larger change with a data migration attached, and it is NOT attempted
 * here -- this migration only removes the insert barrier.
 *
 * SQLite needs no special branch: Laravel 11 implements `->change()` natively per driver and on
 * SQLite rebuilds the table, preserving rows. Verified by running this migration from zero on
 * SQLite and then inserting a customer with neither column supplied.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        // Guarded per column so this is a safe no-op on a database where the Paddle migration
        // never ran (Cashier::$runsMigrations disabled), and so re-running it changes nothing.
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'billable_id')) {
                $table->unsignedBigInteger('billable_id')->nullable()->change();
            }

            if (Schema::hasColumn('customers', 'billable_type')) {
                $table->string('billable_type')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Intentionally NOT reversed. Re-imposing NOT NULL would fail against any row this
        // migration has since allowed to be created with a null billable_id -- i.e. against
        // every storefront customer -- so an automatic rollback would be a guaranteed error.
    }
};
