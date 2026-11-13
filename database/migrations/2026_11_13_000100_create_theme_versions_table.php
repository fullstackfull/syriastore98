<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 03 M3-B — THEME CONFIGURATION VERSIONS: THE SNAPSHOTS.
 * =================================================================================================
 *
 * APPROVED FOR DESIGN, DEVELOPMENT AND ISOLATED DATABASE TESTING. **No production migration has
 * been run and none may be.** Promoting this is a separate owner decision and the standing
 * prohibitions are unchanged.
 *
 * WHY A NEW TABLE AND NOT COLUMNS ON `theme_customizes`
 * -------------------------------------------------------------------------------------------------
 * Measured at M3-A. `theme_customizes` is read by exactly one function — `getThemeSetting()`,
 * `app/Helper/helper.php:3538` — which returns a FLAT `name => value` array, and that function is
 * called from `ViewServiceProvider:65` on **every storefront request** and from **two vendored
 * packages** (`packages/workdo/Donation`, `packages/workdo/CouponList`) that this project is
 * forbidden to edit.
 *
 * So `theme_customizes` keeps its exact shape and becomes the **published projection**: the live
 * key/value set the storefront renders. Versions live here, beside it. Every existing reader keeps
 * working because none of them is touched. Adding a `status` or `version` column to
 * `theme_customizes` instead would have changed what that table means for readers this project
 * cannot modify.
 *
 * WHAT A PAYLOAD IS, AND WHY IT IS THE OVERRIDE SET RATHER THAN THE MERGED ARRAY
 * -------------------------------------------------------------------------------------------------
 * `getThemeSetting()` merges the theme's `default_data/settings.php` **under** the merchant's
 * stored rows. Three key spaces were measured per theme:
 *
 *                defaults   theme_json (editable)   defaulted-but-not-editable   of those, DEAD
 *     stylique      511              307                      216                     150
 *     techzonix     523              321                      205                     150
 *     greentic      521              328                      197                     156
 *
 * "DEAD" means: has a default, is exposed by no `theme_json`, and is read by no Blade view — an
 * older naming scheme (`default_data` carries `abouts_us_description`, while the current
 * `theme_json` declares section `abouts_us_full` with setting `description`, so the live name is
 * `abouts_us_full_description`).
 *
 * A payload therefore stores the merchant's **overrides** — exactly the pairs `theme_customizes`
 * holds — and not the merged result. Freezing the merge would carry ~150 dead keys per version AND
 * would change behaviour the moment a theme ships a new default, because today a key the merchant
 * never touched always resolves to the CURRENT default. Storing overrides keeps the semantics
 * byte-identical to what ships now.
 *
 * ONE `longtext` AND NOT 500 CHILD ROWS. Measured: a full snapshot is 31-35 KB of JSON
 * (stylique 31,455 bytes / 511 keys, techzonix 34,933 / 523, greentic 33,437 / 521). A child-row
 * table would mean ~500 rows per version for no benefit, and would make "is this version byte-equal
 * to that one" a join instead of a hash comparison.
 *
 * `payload_hash` IS NOT DECORATION. It is how an immutable snapshot proves it was not edited after
 * publication, and how `theme_publication_events` records *what was actually published* independent
 * of any later row. It is also what makes "this draft is identical to what is live" an O(1) check
 * rather than a 31 KB string comparison on every editor page load.
 *
 * WHY EVERY RULE HERE IS A REAL CONSTRAINT
 * -------------------------------------------------------------------------------------------------
 * `config/database.php:55` sets **`'strict' => false`** on the production MySQL connection. A
 * non-strict connection COERCES rather than refuses — that is the measured mechanism behind
 * P1-INV-01, where `NULL` into a `NOT NULL` column landed on MySQL while SQLite refused it. An
 * application-level convention is therefore not an integrity guarantee here, so version identity is
 * a UNIQUE index and ownership is a FOREIGN KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('theme_versions')) {
            return;
        }

        Schema::create('theme_versions', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * TENANT OWNERSHIP IS A COLUMN WITH A FOREIGN KEY, NOT AN INFERENCE.
             *
             * `ThemeCustomize` has no global scope, does not use `BelongsToStore`, and is absent
             * from `config/tenancy.php`'s `scoped_models`, so the tenancy scope adds NO predicate
             * for any principal — and `null_tenant => 'open'` means an unresolved tenant gets no
             * predicate either. Every query against this table must therefore name its store
             * explicitly, and `store_id` is NOT NULL so a row cannot exist without an owner.
             *
             * ON DELETE CASCADE matches `theme_customizes`'s own foreign key: deleting a store
             * already removes its live configuration, and leaving its version history behind would
             * orphan rows that still contain that merchant's content.
             */
            $table->unsignedBigInteger('store_id');

            /*
             * 191, not 255: this project's established ceiling for an indexed string under utf8mb4
             * (4 bytes/char against InnoDB's 3072-byte key limit). `stores.theme_id` is
             * varchar(255), but the longest shipped theme name is 9 characters ("techzonix"), so
             * 191 is twenty times the observed maximum. A version belongs to ONE theme: a store
             * that switches theme keeps a separate, intact history for each.
             */
            $table->string('theme_id', 191);

            /* Monotonic per (store_id, theme_id). Allocation is the service's job; UNIQUENESS is
             * the database's, because two concurrent saves would otherwise both read "max + 1". */
            $table->unsignedInteger('version_no');

            /*
             * `draft` | `published` | `archived`.
             *
             * Deliberately a short string and not an enum: MariaDB and MySQL differ on how an
             * unknown enum value behaves under `strict => false` (it coerces to the empty string
             * rather than refusing), so an enum would give the appearance of a constraint while
             * silently accepting garbage. The value set is asserted in the model and in tests, and
             * the thing that genuinely MUST be enforced by the engine — "at most one published
             * version per store and theme" — is enforced in `theme_published_versions` instead,
             * where a UNIQUE key can express it.
             */
            $table->string('status', 16)->default('draft');

            /* The merchant's own name for this version. Never used as an identifier. */
            $table->string('label', 255)->nullable();

            /* The override set as JSON. See the docblock: 31-35 KB at full size. */
            $table->longText('payload');

            /* SHA-256 of the canonical payload, lower-case hex. */
            $table->char('payload_hash', 64);

            /*
             * Nullable with ON DELETE SET NULL: a version must survive the departure of the
             * staff member who created it. History that disappears when a user is deleted is not
             * an audit trail — and `users` rows DO get deleted here; the cascade behaviour of that
             * deletion is the subject of `docs/security/P0-USER-DELETION-CASCADE.md`.
             */
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            /* VERSION IDENTITY. Two concurrent "save draft" calls cannot both claim version 7. */
            $table->unique(['store_id', 'theme_id', 'version_no'], 'tv_store_theme_version_unique');

            /*
             * THE KEY THAT MAKES CROSS-TENANT PUBLICATION IMPOSSIBLE, not merely unlikely.
             *
             * `theme_published_versions` carries a COMPOSITE foreign key on
             * (store_id, theme_id, version_id) which references this one. A plain FK on `version_id`
             * alone would only prove the version EXISTS -- so a handler with a bug, or a request
             * carrying someone else's version id, could publish STORE A's version into STORE B's
             * pointer row and the database would accept it. MEASURED with a composite key in place:
             * store 2 pointing at store 1's version is refused with ERROR 1452, and so is the wrong
             * THEME of the right store, while a store pointing at its own version is accepted.
             *
             * That matters more here than it normally would, because `ThemeCustomize` has no global
             * scope, is absent from `scoped_models`, and `null_tenant` is `open` -- so the only
             * isolation the M3-C/D handlers will have is hand-written `where('store_id', ...)`
             * predicates, and this project has already produced four separate defects from a tenant
             * predicate that was built from caller-supplied input instead of the principal. The
             * engine refusing the write is the backstop for the day a predicate is forgotten.
             *
             * It is redundant with the primary key for lookups, and that is fine: its job is to be
             * a referenceable UNIQUE target, not a read path.
             */
            $table->unique(['store_id', 'theme_id', 'id'], 'tv_identity_unique');

            /* The editor's list query: this store's versions for this theme, by state. */
            $table->index(['store_id', 'theme_id', 'status'], 'tv_store_theme_status_index');

            /* "Is any version byte-equal to this payload?" without reading 31 KB per row. */
            $table->index('payload_hash', 'tv_payload_hash_index');

            $table->foreign('store_id', 'tv_store_id_foreign')
                ->references('id')->on('stores')->onDelete('cascade');

            $table->foreign('created_by', 'tv_created_by_foreign')
                ->references('id')->on('users')->nullOnDelete();

            $table->foreign('published_by', 'tv_published_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Safe to drop: this migration created the table, so rolling it back cannot destroy data that
     * predated it.
     *
     * That is NOT true of every migration in this project and the distinction is why this note
     * exists. Two GATE F drafts dropped a COLUMN in `down()` *because* it existed, and a rollback
     * destroyed 45 real `cost_price` values. A table this migration created has no such history —
     * but anything that ever alters a pre-existing table must have a documented no-op `down()`
     * instead. See `2026_11_13_000400_add_store_theme_index_to_theme_customizes_table.php`.
     */
    public function down(): void
    {
        Schema::dropIfExists('theme_versions');
    }
};
