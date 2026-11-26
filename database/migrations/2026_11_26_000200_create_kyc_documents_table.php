<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 / KYC-002 — THE PRIVATE DOCUMENTS, THEIR REVIEWS AND THEIR RENEWAL DATES.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation.**
 *
 *   KYC-002  إدارة الوثائق الخاصة والمراجعات والتجديد وسجلات الوصول.
 *            Manage private documents, reviews, renewal and access logs.
 *
 * Four clauses, and this table carries three of them: the documents, the review decision per
 * document, and `expires_on` for renewal. THE FOURTH — ACCESS LOGS — IS DELIBERATELY NOT A TABLE
 * HERE. It is written to `audit_logs` through `App\Services\Kyc\KycAccessLog`, because:
 *
 *   - `audit_logs` already exists in production (GATE F, `2026_10_12_000105`), already resolves the
 *     hard part correctly (`App\Support\Audit\AuditAttribution` files an OPERATOR's action against
 *     the SUBJECT's store rather than the fabricated store 1 `getCurrentStore()` hands them), and
 *     is one of the sixteen models that is NOT `Cachable`, so a read of the trail is never a stale
 *     read of a cache;
 *   - a second audit system beside the first is how a trail ends up with two half-answers. This
 *     project's own SUPPORT-004 finding is that the impersonation door writes NO audit row; the
 *     remedy there is the same table, not another one.
 *
 * WHAT IS **NOT** IN THIS TABLE, AND MUST NEVER BE: the file's bytes, and any public URL for them.
 * The row holds `disk` + `storage_name`, and `storage_name` is 64 hex characters of
 * `random_bytes(32)` under `<store>/<authority>/`, generated server-side, with NO extension. The
 * bytes live on the `kyc` disk, whose config entry carries no `url` key so `Storage::url()` throws.
 * `App\Services\Kyc\KycDocumentStore` is the only class in `app/` permitted to name that disk and
 * `KycPrivateStorageTest` asserts that whitelist — enumerating the SAFE set rather than hunting
 * for dangerous spellings, which is this project's recorded census rule.
 *
 * `sha256` EARNS ITS COLUMN THREE TIMES: a reviewer can tell a re-upload from the same paper sent
 * twice; a merchant replacing an expiring licence with the identical file can be told so rather
 * than silently appearing to have renewed; and the trail can show that the bytes a reviewer
 * approved are the bytes still on disk. It is NOT an access control — anyone holding the file can
 * compute it.
 *
 * `authority` AND `store_id` ARE DENORMALISED FROM THE PARENT ON PURPOSE. Every tenant predicate in
 * this codebase is `where('store_id', ...)` on the row being served, and a predicate that needs a
 * join is a predicate somebody will forget — `Product` and `Category` carry no effective global
 * scope at all (CLAUDE.md §1), so the explicit column is the whole defence. `KycDocument::booted()`
 * refuses a row whose two values disagree with its profile's, and `KycDocumentTenancyTest` drives
 * it.
 *
 * NO FOREIGN KEYS, for the reason the verifications migration gives: a cascade from `stores` or
 * `users` would silently destroy the record of a review, which is what an access log exists to
 * prevent. Retention is `D-P6-46`, an owner decision, not a delete side effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'kyc_documents',
            [
                'kyc_verification_id', 'store_id', 'authority', 'document_kind', 'disk',
                'storage_name', 'original_name', 'mime', 'size_bytes', 'sha256',
                'issued_on', 'expires_on', 'status', 'reviewed_by', 'reviewed_at',
                'review_note', 'uploaded_by', 'created_at', 'updated_at',
            ],
            ['kyc_doc_storage_name_unique'],
            []
        );

        if (Schema::hasTable('kyc_documents')) {
            return;
        }

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('kyc_verification_id');

            /* Denormalised from the profile. See the docblock: the tenant predicate must not need a
               join. Kept honest by KycDocument's own `saving` guard. */
            $table->unsignedBigInteger('store_id');
            $table->string('authority', 32);

            /* A machine name from config('kyc.document_kinds'), which ships EMPTY because which
               documents are required is an owner decision (D-P6-46). An empty list refuses every
               upload, which is the safest default for sensitive personal data. */
            $table->string('document_kind', 64);

            /* WHERE the bytes are, and under WHAT NAME. `disk` is recorded per row rather than read
               from config at download time so that changing the configured disk cannot silently
               re-point existing rows at a directory that does not hold their files. */
            $table->string('disk', 32);
            $table->string('storage_name', 191);

            /* The name the uploader's own filesystem used. Kept for the reviewer and for the
               merchant's own list, NEVER used to build a path: it is a stranger's text. Rendered
               escaped. */
            $table->string('original_name', 191);

            $table->string('mime', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);

            /* Supplied per document. Nothing derives either from a default — see config/kyc.php. */
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();

            /* `pending` | `approved` | `rejected` | `superseded` | `expired`. The accepted values
               are App\Models\KycDocument::STATUSES; KycStatusVocabularyTest pins the pair. */
            $table->string('status', 24)->default('pending');

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();

            $table->unsignedBigInteger('uploaded_by')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            /* THE NAME IS THE SECRET, so it is unique at the engine. Two rows naming one file would
               mean one file's access trail split across two subjects. */
            $table->unique('storage_name', 'kyc_doc_storage_name_unique');

            $table->index(['store_id', 'authority'], 'kyc_doc_store_authority');
            $table->index('kyc_verification_id', 'kyc_doc_verification');
            $table->index(['status', 'expires_on'], 'kyc_doc_status_expiry');
        });
    }

    /**
     * A DOCUMENTED NO-OP. Dropping this table loses which identity papers were reviewed and
     * approved, while the files themselves stay on disk with nothing left pointing at them — the
     * worst of both. See the verifications migration's `down()`.
     */
    public function down(): void
    {
        // Intentionally empty. See the note above.
    }
};
