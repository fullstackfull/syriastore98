<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notification that something changed in a store's workspace. NOT a copy of what changed.
 *
 * WHAT IS DELIBERATELY ABSENT: message content, contact names, email addresses, phone numbers,
 * attachments. The approved rules say not to put conversation content in a broad event log, and
 * there is a stronger reason than storage hygiene: this table is written by an INBOUND HTTP
 * request. Even with the signature verified, a row here that carried text would be customer
 * conversation content sitting in a table whose write path begins outside this application --
 * and this installation's APP_KEY is published, so "it is in the database" is not a protection.
 *
 * So a row says only "conversation 41 in account 7001 changed, at this time, and here is the
 * delivery that said so". The interface reacts by re-fetching that conversation THROUGH THE BFF,
 * which authorizes the request as the person making it. The consequence is worth stating plainly:
 * a forged or misrouted event cannot leak anything, because an event carries nothing to leak and
 * the fetch it triggers is authorized independently.
 *
 * THE ID IS THE CURSOR. A client asks for everything after the last id it saw, which is what
 * makes reconnection and missed-event recovery the same operation as ordinary polling -- there
 * is no separate catch-up path to get wrong. Out-of-order deliveries are harmless for the same
 * reason: the client re-reads current state rather than applying a diff.
 *
 * delivery_id IS UNIQUE, and that is the idempotency. Lynomia stamps every delivery with a fresh
 * uuid (WebhookListener), webhook delivery is at-least-once with no retry logic to coordinate
 * with, and a duplicate insert is therefore expected traffic rather than an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lynomia_inbox_events')) {
            return;
        }

        Schema::create('lynomia_inbox_events', function (Blueprint $table) {
            // The cursor. Monotonic per installation, which is all a client needs.
            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('lynomia_account_id');

            // message_created, conversation_created, conversation_updated,
            // conversation_status_changed... one of Lynomia's own event names, stored as sent.
            $table->string('event', 64);

            // WHICH conversation, by the per-account display id the agent API addresses them by.
            // Null for events that are not about one (a contact change).
            $table->unsignedBigInteger('conversation_display_id')->nullable();

            // When Lynomia says it happened, as distinct from when we heard about it.
            $table->timestamp('occurred_at')->nullable();

            // Lynomia's X-Chatwoot-Delivery. The idempotency key.
            $table->string('delivery_id', 64);

            $table->timestamps();

            $table->unique('delivery_id');
            // The read every polling client makes: "this store, after this id".
            $table->index(['store_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_inbox_events');
    }
};
