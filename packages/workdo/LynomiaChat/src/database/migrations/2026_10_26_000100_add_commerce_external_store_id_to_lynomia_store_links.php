<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE IDENTIFIER LYNOMIA HOLDS FOR THIS STORE, KEPT WHERE IT CANNOT MOVE.
 *
 * `StoreIdentity::of()` composed it from `config('lynomia.installation_ref')`, which falls
 * back to the host of `APP_URL`. Lynomia PERSISTS the resulting string in
 * `commerce_stores.external_store_id` when the store registers; nothing on this side stored
 * what was sent. A domain change, or one deploy target where `LYNOMIA_INSTALLATION_REF` is
 * unset so the `APP_URL` fallback takes over, therefore moved every answer at once:
 *
 *   outbound decisions   404 on set_store, classed non-transient, retired on first attempt
 *   reconciliation       404 -- the sweep that is supposed to notice could not
 *   inbound events       rejected as speaking for another installation
 *
 * All three fail closed and none of them says anything, which is the original bare-id defect
 * re-entered through configuration rather than through code.
 *
 * Nullable, because every existing link predates it; StoreIdentity fills it on first use and
 * reads it forever after, so the value is whatever was actually in force when this
 * installation first told Lynomia who it was. A DELIBERATE change of installation reference
 * now requires updating Lynomia's own `commerce_stores` row as well -- which is correct, and
 * is the conversation this column forces instead of a silent outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->string('commerce_external_store_id', 191)->nullable()->after('commerce_credential_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn('commerce_external_store_id');
        });
    }
};
