<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes provisioning resumable, and records that an existing-account link was proven.
 *
 * WHY RESUMPTION NEEDS COLUMNS AT ALL. Provisioning is three remote calls -- create the
 * account, create the service user, attach the user to the account -- and the first two mint
 * objects on the Lynomia side that this installation can only find again if it wrote down
 * their ids. The original implementation wrote all three results in one go AFTER the last
 * call succeeded, so any failure in between left a row with no ids at all and no way to tell
 * how far it had got. Its own comment claimed "the next attempt resumes against the same
 * row"; it did not -- provision() returned the stale row untouched and the merchant could
 * never connect again without an operator deleting it by hand.
 *
 *   provisioning_stage       how far the last attempt got, so the next one resumes instead of
 *                            restarting. Written immediately after each remote call returns,
 *                            never batched at the end.
 *   provisioning_started_at  the lock. A conditional UPDATE on this column is what makes two
 *                            simultaneous provision requests resolve to one winner, because
 *                            "SELECT then decide" loses that race. It is a TIMESTAMP rather
 *                            than a boolean so a process that dies mid-flight cannot wedge
 *                            the store forever: the claim goes stale and the next attempt
 *                            takes it.
 *   provisioning_attempts    how many times this row has been worked. Operator-facing, and
 *                            the thing to look at when a store will not connect.
 *   ownership_proven_at      set ONLY when Lynomia itself confirmed, by redeeming a code that
 *                            only an administrator of that account could mint, that this
 *                            merchant controls the account being claimed. It is the column
 *                            that separates "these ids were given to us by the claimant" from
 *                            "these ids were proven" -- and SSO is refused without it.
 *
 * NO EXISTING COLUMN CHANGES TYPE AND NO DATA IS REWRITTEN. Every column added is nullable or
 * has a default, so the migration is additive, and rows written before it keep working: a
 * pre-existing ACTIVE provisioned link has a null provisioning_stage, which the resume logic
 * reads as "nothing in flight" -- correct, because it is already finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lynomia_store_links')) {
            return;
        }

        Schema::table('lynomia_store_links', function (Blueprint $table) {
            // claimed | account_created | user_created | complete
            $table->string('provisioning_stage', 32)->nullable()->after('link_mode');

            // Held while an attempt is in flight; cleared when it finishes either way.
            $table->timestamp('provisioning_started_at')->nullable()->after('provisioning_stage');

            $table->unsignedInteger('provisioning_attempts')->default(0)->after('provisioning_started_at');

            // When Lynomia confirmed the claimant controls the account. Distinct from
            // verified_at, which records the Store Syria OPERATOR's commercial approval --
            // the two are different questions and both must be answered.
            $table->timestamp('ownership_proven_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lynomia_store_links')) {
            return;
        }

        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn([
                'provisioning_stage',
                'provisioning_started_at',
                'provisioning_attempts',
                'ownership_proven_at',
            ]);
        });
    }
};
