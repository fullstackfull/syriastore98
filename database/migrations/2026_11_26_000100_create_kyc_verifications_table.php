<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 / KYC-001 + KYC-003 — ONE VERIFICATION RECORD PER STORE **PER AUTHORITY**.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — the standing rule for every new table on this
 * branch (see `2026_11_18_000100_create_marketing_consent_events_table`).
 *
 * THE TWO APPROVED SENTENCES THIS TABLE IMPLEMENTS, verbatim from
 * `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *   KYC-001  توثيق الأفراد والشركات.
 *            Verification of individuals and companies.
 *   KYC-003  فصل توثيق Store Syria عن توثيق مزودي الدفع والتراخيص الحكومية.
 *            Separate Store Syria's verification from payment-provider and government-licence
 *            verification.
 *
 * `subject_type` IS KYC-001 AND `authority` IS KYC-003, AND THE UNIQUE KEY IS WHAT MAKES THE
 * SECOND ONE STRUCTURAL RATHER THAN ASPIRATIONAL.
 *
 * The easy implementation of "verification" is one boolean on `stores`, and it is the defect KYC-003
 * names. A single flag cannot say WHO verified WHAT: a merchant approved by a payment provider's own
 * KYC would read as verified by Store Syria, and a store holding a government trade licence would
 * read as verified for payments. The plan asks for the opposite, so the record is keyed
 * `(store_id, authority)` and there is no column anywhere that answers "is this store verified"
 * without naming an authority. `KycAuthoritySeparationTest` asserts that in both directions, and
 * `KycSchemaShapeTest` asserts no single `verified`-style column was added to `stores` as a
 * shortcut.
 *
 * WHAT THE UNIQUE KEY BUYS AT THE ENGINE, not only in the model: two racing submissions for the
 * same store and authority cannot create two records that disagree about the same question. The
 * loser meets the key and re-reads. That is the same argument `marketing_contact_states`
 * ('mcs_contact_unique') makes and the same one this project has already had to learn twice.
 *
 * NO FOREIGN KEYS, DELIBERATELY, AND THE REASON IS AUDIT INTEGRITY RATHER THAN CONVENIENCE.
 * `store_id`, `reviewed_by` and `created_by` all name rows that this application soft-deletes and
 * recreates around tenancy changes — `users` most of all (`UserPolicy::sameTenant()`'s docblock
 * records a merchant demoting their own type through the product). A cascade from `stores` would
 * mean closing a store silently destroys the record of which identity papers were reviewed and by
 * whom, which is the one thing an access log exists to prevent. Whether a closed store's KYC record
 * should be destroyed, and when, is a retention question for the owner (`D-P6-46`), not a side
 * effect of a delete.
 *
 * `expires_on` IS A DATE, NOT A PERIOD. How long any document or verification is valid for is an
 * owner decision; nothing in this schema or in `config/kyc.php` derives an expiry from a default.
 * The column records the date somebody supplied, and `App\Services\Kyc\KycRenewal` reads it.
 *
 * THE STATUS VOCABULARY IS A STRING AND NOT AN ENUM COLUMN, for the reason this project's schema
 * notes give: a MySQL `enum` cannot be extended without an `ALTER`, which is an owner escalation on
 * a live table. The accepted values are `App\Models\KycVerification::STATUSES` and
 * `KycStatusVocabularyTest` pins the two lists against each other so they cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'kyc_verifications',
            [
                'store_id', 'authority', 'subject_type', 'status', 'submitted_at',
                'reviewed_by', 'reviewed_at', 'review_note', 'expires_on', 'created_by',
                'created_at', 'updated_at',
            ],
            ['kyc_ver_store_authority_unique'],
            []
        );

        if (Schema::hasTable('kyc_verifications')) {
            return;
        }

        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');

            /* KYC-003. `store_syria` | `payment_provider` | `government_licence` —
               App\Services\Kyc\KycAuthority::ALL, pinned against this list by KycAuthorityTest. */
            $table->string('authority', 32);

            /* KYC-001. `individual` | `company`. */
            $table->string('subject_type', 16);

            /* `unsubmitted` | `submitted` | `in_review` | `approved` | `rejected` | `expired`. */
            $table->string('status', 24)->default('unsubmitted');

            $table->timestamp('submitted_at')->nullable();

            /* The `users` row that decided. Nullable because an unreviewed record has no reviewer,
               and because a reviewer's account can be removed without the decision being untrue. */
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            /* A reviewer's note. Rendered to the merchant, so it is escaped at the view and is
               never interpolated into SQL or HTML. */
            $table->string('review_note', 1000)->nullable();

            $table->date('expires_on')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['store_id', 'authority'], 'kyc_ver_store_authority_unique');
            $table->index(['status', 'expires_on'], 'kyc_ver_status_expiry');
        });
    }

    /**
     * A DOCUMENTED NO-OP, the posture this project adopted after two GATE F drafts dropped a column
     * *because* it existed and a rollback destroyed 45 `cost_price` values.
     *
     * Dropping this table destroys the record of which identity documents were reviewed, by whom
     * and when — the access trail KYC-002 exists to keep. A rollback must not be able to do that
     * silently, so it does nothing and says so. Removing the table is a deliberate operation
     * against a named environment, not a `migrate:rollback`.
     */
    public function down(): void
    {
        // Intentionally empty. See the note above.
    }
};
