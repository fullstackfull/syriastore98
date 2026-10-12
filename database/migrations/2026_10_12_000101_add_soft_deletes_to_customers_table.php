<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA-03 / SEC-R5-03 — `customers.deleted_at` is declared nowhere.
 *
 * NOT PROVISIONAL. This column's shape is unambiguous from code, so no production dump is
 * required to author it: `Illuminate\Database\Eloquent\SoftDeletes` requires exactly one
 * nullable TIMESTAMP named by `Model::getDeletedAtColumn()`, which `Customer` does not
 * override, so it is `deleted_at`. `$table->softDeletes()` emits precisely that.
 *
 * EVIDENCE
 *   - app/Models/Customer.php:16  `use Illuminate\Database\Eloquent\SoftDeletes;`
 *   - app/Models/Customer.php:20  `use Cachable, Notifiable, CanResetPassword,HasApiTokens,Billable,SoftDeletes;`
 *   - grep for "deleted_at" / "softDeletes" over BOTH migration trees (root 105 files plus
 *     the 102 under the WorkDo packages) returns 0 hits: no migration anywhere creates it.
 *   - `Customer` is the ONLY model in either tree using the trait, so the scope of this fix
 *     is one table and one column.
 *
 * WHY THIS IS CRITICAL. `SoftDeletes` installs a global scope that appends
 * `and "customers"."deleted_at" is null` to EVERY Customer query. Against a schema without
 * the column, every customer read and the whole `customers` guard
 * (app/Models/Customer.php:22 `protected $guard = 'customers'`) fail outright — reachable
 * anonymously at the storefront login form and from the Flutter app's customer endpoints.
 *
 * WHAT MUST NOT BE DONE: do NOT "fix" the mismatch by removing the trait from
 * app/Models/Customer.php:20. That would silently convert soft deletes into HARD deletes and
 * destroy customer data. The trait is correct; it simply has no column to land in.
 *
 * SAFETY. `hasColumn`-guarded, so this is a NO-OP against any database that already has the
 * column — which is what makes it safe to run against the real schema later. Purely additive
 * and nullable, so no existing row changes and no existing query result changes: a NULL
 * `deleted_at` is exactly "not deleted".
 *
 * FLUTTER IMPACT: none. An additive nullable column cannot change any JSON key's presence or
 * type for a client that reads named keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || Schema::hasColumn('customers', 'deleted_at')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'deleted_at')) {
            return;
        }

        // DESTRUCTIVE ON ROLLBACK, deliberately and symmetrically: dropping this column
        // resurrects every soft-deleted customer. Reviewed and accepted as the correct
        // inverse of `up()`; see 06-COMPATIBILITY-AND-ROLLBACK.md.
        Schema::table('customers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
