<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transactional outbox for commerce events going OUT to Lynomia.
 *
 * WHY AN OUTBOX AND NOT A QUEUED JOB FROM THE OBSERVER. A job dispatched from a model observer is
 * dispatched when the observer runs, not when the transaction commits: roll back and the job
 * still fires, for an order that does not exist. Push it onto the queue after commit instead and
 * a crash between the two loses it with no trace. A row written in the SAME transaction as the
 * order change is the one shape that cannot do either -- it commits exactly when the order change
 * does, and if nothing ever delivers it, it is still sitting there to be found.
 *
 * THE ROW CARRIES NO ORDER DATA. Only which store, which order, which customer, and what
 * happened. Lynomia's realtime design reads the current state from the store rather than
 * trusting an event's contents, so a payload here would be data this table has no reason to hold
 * and every reason not to: names, totals and phone numbers sitting in a queue table, outliving
 * the thing that produced them. `occurred_at` and the status pair are kept because they are what
 * makes a delivery meaningful out of order.
 *
 * DELIVERY IS AT LEAST ONCE, AND THE RECEIVER DEDUPLICATES. delivery_id is a per-row uuid sent
 * with the delivery; Lynomia's webhook queue refuses a key it has already seen. Retrying a row
 * whose answer was lost is therefore free, which is what lets `attempts` back off instead of
 * giving up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lynomia_commerce_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('event', 32);
            $table->unsignedBigInteger('order_id')->nullable();
            // The customer this event is about, as the commerce contract references one: a
            // customers.id, or a `guest:{orderId}` reference. Lynomia refreshes the contacts
            // linked to it; an event naming none invalidates the whole store's cache.
            $table->string('customer_reference', 64)->nullable();
            $table->unsignedTinyInteger('delivered_status')->nullable();
            $table->string('payment_status', 32)->nullable();
            $table->timestamp('occurred_at');
            $table->uuid('delivery_id');

            // Delivery state. `deliver_after` is the backoff clock; a row is eligible when it is
            // in the past, which is also how a brand-new row is eligible immediately.
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('deliver_after')->nullable();
            $table->timestamp('delivered_at')->nullable();
            // Bounded: an upstream error body is not a place to put an essay, and this is read
            // by an operator looking for why a store stopped syncing.
            $table->string('last_error', 191)->nullable();

            $table->timestamps();

            $table->unique('delivery_id');
            // The sweep's query: undelivered rows of any store whose backoff has elapsed, oldest
            // first. delivered_at leads because the overwhelming majority of rows are delivered.
            $table->index(['delivered_at', 'deliver_after', 'id'], 'lynomia_commerce_events_pending');
            $table->index(['store_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_commerce_events');
    }
};
