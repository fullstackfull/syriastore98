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

            /* The FK used to provide this read path; now it is declared explicitly. */
            $table->index('actor_id', 'tpe_actor_id_index');

            /*
             * NO FOREIGN KEYS AT ALL -- and the first draft of this migration got that wrong.
             *
             * It gave `store_id` ON DELETE CASCADE and `actor_id` ON DELETE SET NULL, with the
             * comment "a deleted store's audit rows contain that merchant's own content and must go
             * with it". A review caught the contradiction: this table's own docblock argues that an
             * audit log the audited thing can delete is not an audit log, and cites `audit_logs` --
             * so the cascade destroyed exactly the record the table exists to keep, one paragraph
             * after arguing it must not.
             *
             * The stated justification was also FACTUALLY WRONG about this table. MEASURED against
             * the column list above: it holds `store_id`, `theme_id`, `version_id`,
             * `from_version_id`, `action`, `payload_hash`, `actor_id` and `created_at`. There is no
             * payload here and no setting value -- **no merchant content whatsoever**, only
             * identifiers, a verb and a digest. The content lives in `theme_versions.payload`, which
             * does cascade with the store, and that is the right place for it to.
             *
             * `audit_logs` is the house precedent and it carries no foreign keys either. MEASURED,
             * `SHOW CREATE TABLE audit_logs`: four plain KEYs (`idx_audit_store_created`,
             * `idx_audit_subject`, `idx_audit_user`, `idx_audit_action`) and not one CONSTRAINT.
             *
             * `actor_id` loses its FK for the same reason: `nullOnDelete` would blank WHO performed
             * a publication when that user is deleted, and `users` rows do get deleted here (see
             * docs/security/P0-USER-DELETION-CASCADE.md). "Someone published version 7" is not an
             * audit trail.
             *
             * The cost, stated rather than hidden: a row here may name a store, a version or a user
             * that no longer exists. `store_id`, `theme_id` and `payload_hash` are carried precisely
             * so an event stays interpretable when that happens, and the indexes below give the same
             * read paths a foreign key would have.
             */
        });
    }

    /** Safe: this migration created the table. See the note in the `theme_versions` migration. */
    public function down(): void
    {
        Schema::dropIfExists('theme_publication_events');
    }
};
