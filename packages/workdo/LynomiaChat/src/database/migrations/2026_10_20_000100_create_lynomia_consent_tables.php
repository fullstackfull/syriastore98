<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store Syria's own consent records, and what Lynomia has confirmed back.
 *
 * TWO TABLES, BECAUSE THEY ANSWER TWO DIFFERENT QUESTIONS, and conflating them is how a
 * consent screen ends up confidently wrong. `lynomia_consent_decisions` is what a customer
 * told THIS store -- append-only, one row per decision, the thing Store Syria is authoritative
 * for (docs/partner-consent/AUTHORITY.md §1). `lynomia_consent_states` is what Lynomia
 * actually holds for that scope, which is what decides whether a message is sent. They are
 * usually the same and will differ whenever a delivery is in flight, so a merchant screen that
 * showed only the first would promise sends that will not happen, and one that showed only the
 * second could not explain why.
 *
 * APPEND-ONLY ON THE DECISION SIDE. A withdrawal is not an edit of a grant, it is a later
 * decision; keeping both is what makes "when did they agree, and when did they stop" a
 * question with an answer. The current local answer is the newest row for a scope, which the
 * index below is shaped for.
 *
 * THE DELIVERY FIELDS LIVE ON THE DECISION, following lynomia_commerce_events exactly --
 * delivery_id, attempts, deliver_after, delivered_at, last_error, drained by a scheduled
 * command with the same backoff. A consent decision is a durable obligation to tell the other
 * side, which is the same shape as a commerce event and must not become a second mechanism.
 *
 * THE DECISION REF IS THE IDEMPOTENCY KEY THE OTHER SIDE DEDUPES ON. It is minted once, when
 * the decision is recorded, and never regenerated -- a retry, a redelivery after a crash and a
 * reconciliation replay all carry the same one, so Lynomia's UNIQUE (commerce_store_id,
 * decision_ref) makes every repeat a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lynomia_consent_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            // The store's own customer. Nullable because a checkout opt-in can be given by a
            // guest who has no account yet; the email/phone captured with it is what links it.
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('external_subject_id', 128);
            $table->string('purpose', 20);
            $table->string('channel', 20);
            $table->string('state', 20);
            $table->string('source', 40);
            $table->string('policy_version', 40)->nullable();
            // An opt-in that follows a withdrawal is a deliberate, separately verified act.
            // Flagged here so the delivery carries it; Lynomia refuses a plain grant after a
            // refusal, which is the behaviour this flag exists to request explicitly.
            $table->boolean('re_consent')->default(false);
            $table->json('evidence')->nullable();
            $table->timestamp('captured_at');
            $table->unsignedBigInteger('recorded_by')->nullable();

            $table->string('decision_ref', 128);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('deliver_after')->nullable();
            $table->timestamp('delivered_at')->nullable();
            // What Lynomia did with it: applied, duplicate, superseded,
            // refused_withdrawal_dominant. A refusal is not a delivery failure -- it is the
            // answer -- so it is recorded here and the row is not retried.
            $table->string('remote_outcome', 32)->nullable();
            $table->string('last_error', 191)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'decision_ref']);
            // The newest decision for a scope, which is the current local answer.
            $table->index(['store_id', 'external_subject_id', 'purpose', 'channel', 'id'], 'lynomia_consent_scope_idx');
            $table->index(['delivered_at', 'deliver_after'], 'lynomia_consent_pending_idx');
        });

        Schema::create('lynomia_consent_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('external_subject_id', 128);
            $table->string('purpose', 20);
            $table->string('channel', 20);
            $table->string('state', 20);
            $table->string('source', 40)->nullable();
            // Lynomia's server-assigned ordering key. An inbound event carrying a revision not
            // greater than this one has already been superseded and is dropped -- the same
            // rule the other side applies, so the two cannot disagree about what is newest.
            $table->unsignedBigInteger('revision')->nullable();
            $table->string('origin', 20)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['store_id', 'external_subject_id', 'purpose', 'channel'], 'lynomia_consent_state_scope_uniq');
            $table->index(['store_id', 'state'], 'lynomia_consent_state_store_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_consent_states');
        Schema::dropIfExists('lynomia_consent_decisions');
    }
};
