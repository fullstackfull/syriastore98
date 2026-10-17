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
 *
 * NO `after()`, AND THAT IS NOT COSMETIC. An earlier version placed this column
 * `->after('lynomia_installation')` -- a column this table has NEVER had (the mapping is in
 * `lynomia_account_id`; there is no `lynomia_installation` anywhere in the schema). SQLite
 * ignores column positioning, so the in-memory test database swallowed it and every suite
 * stayed green; MariaDB and MySQL do not, and reject the whole migration with "Unknown column
 * 'lynomia_installation' in 'lynomia_store_links'" (errno 1054) -- which is exactly how it
 * failed the first real deploy onto MariaDB 11.4. Column order carries no behaviour, so the
 * clause bought nothing and cost a broken deploy; it is gone. LynomiaMigrationIntegrityTest
 * now fails any migration whose `after()` names a column the schema does not define.
 *
 * GUARDED, SO A RE-RUN IS A NO-OP. The column may already exist -- on the installation that was
 * repaired by hand before this fix landed, or on any environment that half-applied the broken
 * version. `Schema::hasColumn` makes the migration safe to run again rather than fail on a
 * duplicate column, and leaves whatever data the column already holds untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lynomia_store_links') || Schema::hasColumn('lynomia_store_links', 'events_reconciled_at')) {
            return;
        }

        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->timestamp('events_reconciled_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lynomia_store_links') || ! Schema::hasColumn('lynomia_store_links', 'events_reconciled_at')) {
            return;
        }

        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn('events_reconciled_at');
        });
    }
};
