<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE NARROW DOOR THAT LETS A SUSPENDED INSTALLATION BE BROUGHT BACK.
 *
 * WHY ANY OF THIS EXISTS. Lynomia verifies an installation before it re-enables one:
 * `Commerce::StoreConnection#enable` runs the provider health check, which is a single GET of
 * this side's commerce IDENTITY endpoint. Store Syria, for its part, pushes a lifecycle
 * transition to Lynomia BEFORE writing it locally, deliberately, so this side can never claim
 * "active" while Lynomia is still refusing to send. Each rule is right and together they
 * deadlocked: a resume asked Lynomia to health-check a store whose link was still `suspended`,
 * the identity endpoint refused 403, Lynomia answered `resume_failed`, and the unguarded push
 * threw -- so the local transition never happened. Measured: resume() raised a conflict and
 * reauthenticate() reported back_online=false, both leaving the link suspended with no way back
 * short of editing the row.
 *
 * WHAT THIS IS NOT. It is NOT a wider state list on the commerce gate, and it does NOT make a
 * suspended installation active in order to recover it. Messaging and every commerce DATA
 * endpoint stay refused for the whole of a suspended link's life.
 *
 * WHAT IT IS. A recovery window: opened only by an authorised lifecycle recovery operation,
 * good for ONE read of the identity endpoint, expiring in seconds, closed again as soon as the
 * transition finishes either way, and audited at open, consume and close. The window authorises
 * nothing on its own -- the request still has to carry the store's own valid commerce
 * credential, which is what proves WHICH store is asking.
 *
 * NO SECRET IS STORED HERE, and that is deliberate rather than an omission. Lynomia's health
 * check presents the commerce credential it already holds and nothing else, so a nonce minted on
 * this side could never travel to be presented back. The window is therefore server-side state,
 * and its narrowness comes from being single-use, short-lived, purpose-stamped and route-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            // When the window stops being usable. Null means no window has ever been opened.
            $table->timestamp('recovery_window_expires_at')->nullable()->after('ownership_proven_at');
            // The transition it was opened for, so an audit reader can tell a resume from a
            // re-authentication without joining anything.
            $table->string('recovery_window_purpose', 32)->nullable()->after('recovery_window_expires_at');
            // The operator who opened it. Attribution is the point: a recovery is a privileged act.
            $table->unsignedBigInteger('recovery_window_opened_by')->nullable()->after('recovery_window_purpose');
            // Stamped by the ONE identity read the window permits. Non-null means spent.
            $table->timestamp('recovery_window_consumed_at')->nullable()->after('recovery_window_opened_by');
        });
    }

    public function down(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn([
                'recovery_window_expires_at',
                'recovery_window_purpose',
                'recovery_window_opened_by',
                'recovery_window_consumed_at',
            ]);
        });
    }
};
