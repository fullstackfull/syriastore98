<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 03 M3-B — WHICH VERSION IS LIVE: THE POINTER, ENFORCED BY THE ENGINE.
 * =================================================================================================
 *
 * APPROVED FOR DESIGN, DEVELOPMENT AND ISOLATED DATABASE TESTING. **No production migration has
 * been run and none may be.**
 *
 * WHY A WHOLE TABLE FOR ONE POINTER
 * -------------------------------------------------------------------------------------------------
 * "Exactly one published version per store and theme" is the single invariant the whole publish /
 * rollback story rests on. Three ways to express it were considered and two were rejected on
 * measured grounds:
 *
 *   1. `theme_versions.status = 'published'` ALONE. Rejected: neither MySQL nor MariaDB supports a
 *      partial/filtered unique index, so there is no way to say "unique among rows where status is
 *      published". The invariant would live only in application code, and with
 *      `config/database.php:55` setting `'strict' => false` this project has already been bitten by
 *      conventions that the engine does not enforce (P1-INV-01). Two concurrent publishes would
 *      both pass a read-then-write check and both set `status = 'published'`.
 *
 *   2. A `published_theme_version_id` COLUMN ON `stores`. Rejected by measurement: a store has ONE
 *      `theme_id` at a time but may hold versions for several themes, because `makeActiveTheme()`
 *      (`ThemeCustomizeController:182`) lets a merchant switch theme and
 *      `app/Helper/helper.php:3538` keeps looking up overrides under the store's CURRENT theme. A
 *      single column on `stores` would silently forget the published version of every other theme
 *      the moment a merchant switched, so switching away and back would lose a published
 *      configuration.
 *
 *   3. THIS TABLE, with `UNIQUE (store_id, theme_id)`. The engine refuses a second live version for
 *      a pair, per theme, with no application cooperation required. Switching theme keeps each
 *      theme's pointer intact.
 *
 * `ON DELETE RESTRICT` ON `version_id` IS THE POINT, NOT AN OVERSIGHT
 * -------------------------------------------------------------------------------------------------
 * A published version must not be deletable while it is live. `RESTRICT` makes the database refuse,
 * so "the storefront renders a version that no longer exists" is unreachable rather than merely
 * unlikely. Unpublishing is a deliberate act — delete the pointer row first — and that is exactly
 * the kind of step that gets skipped in a hurry.
 *
 * THE STORE's OWN CASCADE IS DIFFERENT ON PURPOSE. `store_id` cascades (deleting a store removes
 * its pointer) while `version_id` restricts. Those are not in tension: deleting the store removes
 * the pointer first, which then permits the version rows to cascade from `theme_versions`'s own
 * store foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('theme_published_versions')) {
            return;
        }

        Schema::create('theme_published_versions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');

            /* 191 to match `theme_versions.theme_id`; see that migration for why. */
            $table->string('theme_id', 191);

            $table->unsignedBigInteger('version_id');

            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();

            $table->timestamps();

            /* THE INVARIANT. At most one live version per store and theme, refused by the engine. */
            $table->unique(['store_id', 'theme_id'], 'tpv_store_theme_unique');

            /* "Is this version the live one?" */
            $table->index('version_id', 'tpv_version_id_index');

            /* The composite foreign key's own covering index. */
            $table->index(['store_id', 'theme_id', 'version_id'], 'tpv_identity_index');

            $table->foreign('store_id', 'tpv_store_id_foreign')
                ->references('id')->on('stores')->onDelete('cascade');

            /*
             * COMPOSITE, AND THAT IS THE WHOLE POINT.
             *
             * A foreign key on `version_id` ALONE proves only that the version exists. It would
             * happily accept STORE A's version id in STORE B's pointer row -- a cross-tenant
             * publication primitive sitting in the schema, waiting for one forgotten
             * `where('store_id', ...)` in a handler. Referencing
             * `theme_versions (store_id, theme_id, id)` instead means the engine itself refuses a
             * pointer whose store or theme does not match the version's.
             *
             * MEASURED on MariaDB 10.11 before adopting it:
             *   store 2 -> store 1's version          ERROR 1452, refused
             *   store 1, theme 'greentic' -> a 'stylique' version   ERROR 1452, refused
             *   store 1, theme 'stylique' -> its own version        accepted
             *
             * RESTRICT is kept: a live version must not be deletable while it is live, so
             * "the storefront renders a version that no longer exists" is unreachable rather than
             * merely unlikely.
             */
            $table->foreign(['store_id', 'theme_id', 'version_id'], 'tpv_version_identity_foreign')
                ->references(['store_id', 'theme_id', 'id'])->on('theme_versions')
                ->restrictOnDelete();

            $table->foreign('published_by', 'tpv_published_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /** Safe: this migration created the table. See the note in the `theme_versions` migration. */
    public function down(): void
    {
        Schema::dropIfExists('theme_published_versions');
    }
};
