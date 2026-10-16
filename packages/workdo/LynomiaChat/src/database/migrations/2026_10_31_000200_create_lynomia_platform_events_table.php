<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EVERY DELIVERY THE PLATFORM FEED HAS EVER SENT US, applied or not.
 *
 * THREE JOBS IN ONE TABLE, and they are the same job looked at from three sides:
 *
 *   idempotency    `delivery_id` is UNIQUE. A repeat is refused by Postgres, not by a check
 *                  with a window in it. This is the same mechanism `lynomia_inbox_events` uses
 *                  for the per-store feed, and for the same reason: a webhook that is retried
 *                  is ordinary, and a receiver that counts a retry twice is broken.
 *   ordering       `occurred_at_us` is kept per event, so "why was this one ignored" has an
 *                  answer that is not "it was older" with nothing to show for it.
 *   audit history  an append-only record of what was reported about a notice and when,
 *                  which `lynomia_platform_notices` cannot hold because it keeps only the
 *                  latest state. A reconciliation that disagrees with the feed is
 *                  investigable only if both are written down.
 *
 * `applied` SEPARATES "WE REFUSED IT" FROM "WE NEVER SAW IT". An event that arrives out of
 * order is recorded with applied = false and the reason, because a feed that silently drops
 * is a feed nobody can debug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lynomia_platform_events', function (Blueprint $table) {
            $table->id();

            // Lynomia's own delivery id, derived from the message and the transition so a
            // republication of the same transition carries the same id. 64 chars to match the
            // store feed's column; the producer's ids are well inside it.
            $table->string('delivery_id', 64)->unique();

            $table->string('event', 64);
            $table->unsignedBigInteger('lynomia_account_id')->nullable();
            $table->unsignedBigInteger('lynomia_message_id')->nullable();

            // The notice this was about, when one could be found. Null is a legitimate state:
            // the platform workspace sends things that are not notices.
            $table->unsignedBigInteger('notice_id')->nullable();

            $table->string('outcome', 32)->nullable();
            $table->string('reason', 64)->nullable();
            $table->unsignedBigInteger('occurred_at_us')->nullable();

            $table->boolean('applied')->default(false);
            $table->string('skip_reason', 64)->nullable();

            $table->timestamps();

            $table->index(['lynomia_message_id']);
            $table->index(['notice_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_platform_events');
    }
};
