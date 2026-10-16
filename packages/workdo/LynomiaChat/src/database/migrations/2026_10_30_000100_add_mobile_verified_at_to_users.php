<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHETHER THE HOLDER OF A MERCHANT'S NUMBER EVER PROVED THEY HOLD IT.
 *
 * `users.mobile` has been nullable, unindexed and unvalidated since the table was created, and
 * is actively written with values that were never anybody's number -- self-registration writes
 * an empty string, and Super Admin store creation wrote the literal 7878787878 for every
 * merchant it made. Nothing anywhere recorded whether a number had been confirmed, so a
 * platform message addressed from that column reaches whoever actually holds the number, who
 * is a stranger, about a merchant's account. That is blocker B4.
 *
 * NOT `lynomia_`-PREFIXED, unlike the other core columns this module adds. `plans.lynomia_agent_seats`
 * is a fact about Lynomia; whether a merchant's phone number is confirmed is a fact about the
 * merchant, and the approved architecture says other channels follow this one. The module owns
 * the verifying today; it does not own the fact.
 *
 * WRITTEN ONLY BY A MERCHANT ANSWERING A CODE SENT TO THAT NUMBER. Nothing backfills it, no
 * operator can set it, and changing the number clears it -- see
 * Workdo\LynomiaChat\app\Services\MerchantContactVerification.
 *
 * THE INDEX IS FOR THE OPERATOR REPORT, which counts and lists merchants by contact state
 * across every store on the installation, and would otherwise scan `users` on every page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('mobile_verified_at')->nullable()->after('mobile');
            $table->index(['mobile_verified_at'], 'users_mobile_verified_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_mobile_verified_at_index');
            $table->dropColumn('mobile_verified_at');
        });
    }
};
