<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where reconciliation has got to for a store.
 *
 * THE WATERMARK CANNOT LIVE IN THE TABLE IT WATCHES. The sweep first compared the newest remote
 * activity against MAX(occurred_at) over lynomia_inbox_events -- and that table is pruned. Once
 * a store's newest marker aged past the retention window the sweep deleted its own evidence,
 * re-detected the same gap, wrote a new marker, and did it again every five minutes forever:
 * a self-sustaining write loop on a quiet store. Caught by running the sweep twice against a
 * fixture whose activity predated the window.
 *
 * So the watermark is durable and lives on the link, which is never pruned. The events table
 * goes back to being purely a cursor for the browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->timestamp('events_reconciled_at')->nullable()->after('lynomia_installation');
        });
    }

    public function down(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn('events_reconciled_at');
        });
    }
};
