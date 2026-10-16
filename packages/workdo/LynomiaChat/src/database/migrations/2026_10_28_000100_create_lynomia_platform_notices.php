<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE OPERATIONAL NOTICE STORE SYRIA SENT A MERCHANT, and what became of it.
 *
 * Store Syria tells merchants about their accounts -- onboarding, a broken integration, a
 * security notice -- from its own Lynomia workspace. This is the record of each one: what was
 * sent, to whom, by which operator, and how it ended.
 *
 * THE UNIQUE KEY IS THE IDEMPOTENCY. There is no idempotency mechanism anywhere on Lynomia's
 * send path: no key on message create, no unique index on messages.source_id, and the send
 * guard's own docblock records that re-running a half-sent campaign messages people twice. So
 * "tell this merchant about this occasion once" has to be made true HERE, and the only thing
 * that can make it true under concurrency is a database constraint rather than a check.
 *
 * `dedupe_key` is the caller's word for the occasion -- "integration broken, store 12,
 * credential version 3" -- not a random id. Two attempts to say the same thing about the same
 * event collide on the index, and the second is refused by the database rather than by a race
 * somebody has to get right.
 *
 * THE STATE IS WRITTEN BEFORE THE CALL, NOT AFTER. A row recorded only on success cannot tell
 * "never sent" from "sent, and the answer was lost". So the row is created `pending`, moved to
 * `sending` before the remote call, and only then to `sent` or `failed`. A row left `sending`
 * is the lost-response case and is NEVER retried automatically: for an operational notice the
 * safe failure is one that might not arrive, not one that arrives twice. An operator decides.
 *
 * NO MESSAGE BODY IS STORED. The template name and its placeholder values are what was sent;
 * the rendered text belongs to Lynomia, and copying a merchant's details into a second
 * database is how a notice table becomes a shadow CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lynomia_platform_notices', function (Blueprint $table) {
            $table->id();

            // Who it was about and who it went to. `store_id` rather than a link id: a link
            // can be revoked and reissued, and the notice is about the merchant's store.
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('recipient_user_id')->nullable();

            // WHAT WAS ADDRESSED, recorded as it was sent. Store Syria has no verified phone
            // number for a merchant -- users.mobile is nullable, unindexed, has no format rule
            // and is seeded with placeholders -- so the resolver refuses anything it cannot
            // normalise, and this column is the audit of what the refusal let through.
            $table->string('recipient_phone', 32);

            // The occasion, and the template that carries it. `kind` is Store Syria's own
            // vocabulary (onboarding, integration_recovery, security, service, support);
            // `template_name` is Meta's, resolved against the workspace's synced list.
            $table->string('kind', 40)->index();
            $table->string('template_name', 128);
            $table->string('template_language', 16)->default('en_US');
            $table->json('template_params')->nullable();

            // SAY IT ONCE. See the note above: the constraint is the mechanism.
            $table->string('dedupe_key', 191)->unique();

            // pending -> sending -> sent | failed, plus `indeterminate` for a lost answer.
            $table->string('status', 24)->default('pending')->index();
            $table->string('failure_reason', 191)->nullable();
            $table->unsignedBigInteger('lynomia_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Who decided to send it. An automated notice records no operator and says so.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->boolean('automated')->default(false);

            $table->timestamps();

            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_platform_notices');
    }
};
