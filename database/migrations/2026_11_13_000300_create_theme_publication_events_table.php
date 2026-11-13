<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 03 M3-B — THE APPEND-ONLY RECORD OF EVERY PUBLISH AND EVERY ROLLBACK.
 * =================================================================================================
 *
 * APPROVED FOR DESIGN, DEVELOPMENT AND ISOLATED DATABASE TESTING. **No production migration has
 * been run and none may be.**
 *
 * WHY THE POINTER AND THE VERSION ROWS ARE NOT ENOUGH
 * -------------------------------------------------------------------------------------------------
 * The directive requires that rollback "restore a previously valid configuration while preserving an
 * auditable version history". Rollback re-points `theme_published_versions` at an older version —
 * and that is where a history kept only in current state loses the plot:
 *
 *   - `theme_versions.published_at` can only hold ONE timestamp. Publish v7, roll back to v3,
 *     publish v3 again: v3's `published_at` is overwritten and the fact that it was live twice,
 *     with v7 in between, is gone.
 *   - `version_no` orders CREATION, not publication. After a rollback the live version has a lower
 *     number than an archived one, so "which configuration was the storefront actually serving last
 *     Tuesday" cannot be answered from the version rows at all.
 *
 * This table answers it. One row per publication EVENT, never updated and never deleted. It is the
 * only table in M3 that is append-only, and that is the whole of its job.
 *
 * `payload_hash` IS DUPLICATED HERE ON PURPOSE
 * -------------------------------------------------------------------------------------------------
 * It records what was ACTUALLY published at that moment, independently of the version row. A draft
 * is mutable until it is published; if a bug, a migration or a support action ever altered a
 * payload afterwards, comparing this hash against `theme_versions.payload_hash` is what reveals it.
 * Storing a copy is the difference between an audit trail and a foreign key to something that may
 * since have changed.
 *
 * `from_version_id` makes each event self-contained: "what was live immediately before this" is
 * readable without reconstructing the sequence, which is what a reviewer actually asks. It is
 * NULLABLE because the first publication of a theme has no predecessor.
 *
 * NO FOREIGN KEY ON `version_id` HERE, AND THAT IS DELIBERATE
 * -------------------------------------------------------------------------------------------------
 * A foreign key to `theme_versions` would mean either CASCADE — in which case deleting an old
 * version silently erases the record that it was once live, destroying the audit trail this table
 * exists to keep — or RESTRICT, which would make every version in the history permanently
 * undeletable and turn routine pruning into a support ticket. An audit log that the thing it audits
 * can delete is not an audit log. `audit_logs` in this codebase takes the same shape for the same
 * reason, and is likewise not `Cachable`.
 *
 * The cost is stated rather than hidden: `version_id` may point at a row that has since been pruned.
 * `payload_hash` and `theme_id` are carried here precisely so an event remains interpretable when
 * that happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('theme_publication_events')) {
            return;
        }

        Schema::create('theme_publication_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');

            /* 191 to match the sibling tables. */
            $table->string('theme_id', 191);

            /* Intentionally NOT a foreign key. See the docblock. */
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('from_version_id')->nullable();

            /* `publish` | `rollback`. A short string for the same reason as `status`: an enum under
             * `strict => false` coerces an unknown value to '' instead of refusing it. */
            $table->string('action', 16);

            /* What was really published, independent of the version row's later state. */
            $table->char('payload_hash', 64);

            $table->unsignedBigInteger('actor_id')->nullable();

            /*
             * `created_at` only. There is no `updated_at` because a row here is NEVER updated, and
             * an `updated_at` column would invite exactly that. Laravel's `timestamps()` would add
             * both, so the column is declared explicitly.
             */
            $table->timestamp('created_at')->nullable();

            /* The audit query: this store's publication history for a theme, newest first. */
            $table->index(['store_id', 'theme_id', 'id'], 'tpe_store_theme_id_index');
            $table->index('version_id', 'tpe_version_id_index');

            /* The store cascade is kept: a deleted store's audit rows contain that merchant's own
             * content and must go with it. */
            $table->foreign('store_id', 'tpe_store_id_foreign')
                ->references('id')->on('stores')->onDelete('cascade');

            $table->foreign('actor_id', 'tpe_actor_id_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /** Safe: this migration created the table. See the note in the `theme_versions` migration. */
    public function down(): void
    {
        Schema::dropIfExists('theme_publication_events');
    }
};
