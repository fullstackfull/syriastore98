<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT ACTUALLY BECAME OF A NOTICE, as distinct from what Lynomia said when it took it.
 *
 * `status` on this table records THIS side's attempt: pending, sending, sent, failed,
 * indeterminate. Its `sent` means "Lynomia answered 200", and Lynomia answers 200 as soon as
 * the message ROW exists -- before the provider call, before the UTILITY gate, before the
 * consent gate and before the allowance meter. So `status = sent` has never meant a merchant
 * received anything, and the console had to be worded around that.
 *
 * These columns carry the OTHER half: what Lynomia went on to report through its delivery
 * feed. They are written only by the platform event projector, never by the sender, and the
 * two never overwrite each other -- `status` stays the record of the attempt.
 *
 * `outcome_at_us` IS THE ORDERING KEY AND THE REASON THIS IS SAFE. Delivery events arrive out
 * of order whenever a retry, a queue backlog or a provider re-send puts two in flight at once,
 * and a late `provider_accepted` landing after a `delivered` would otherwise walk the row
 * backwards. The projector refuses any event not strictly newer than the stamp already stored.
 *
 * `lynomia_conversation_id` exists so a notice can be RECONCILED: with the message id alone
 * there is no way to ask Lynomia what became of it, because its read endpoints are addressed
 * by conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lynomia_platform_notices', function (Blueprint $table) {
            // The nine-state vocabulary, as far as Lynomia can honestly report it.
            $table->string('delivery_outcome', 32)->nullable()->after('lynomia_message_id');
            $table->string('delivery_reason', 64)->nullable()->after('delivery_outcome');
            $table->timestamp('delivery_outcome_at')->nullable()->after('delivery_reason');
            $table->unsignedBigInteger('outcome_at_us')->nullable()->after('delivery_outcome_at');
            $table->unsignedBigInteger('lynomia_conversation_id')->nullable()->after('lynomia_message_id');

            // The projector finds a notice BY the message id Lynomia reports.
            $table->index(['lynomia_message_id'], 'lynomia_notices_message_id_index');
            $table->index(['delivery_outcome'], 'lynomia_notices_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::table('lynomia_platform_notices', function (Blueprint $table) {
            $table->dropIndex('lynomia_notices_message_id_index');
            $table->dropIndex('lynomia_notices_outcome_index');
            $table->dropColumn([
                'delivery_outcome', 'delivery_reason', 'delivery_outcome_at',
                'outcome_at_us', 'lynomia_conversation_id',
            ]);
        });
    }
};
