<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The revocation epoch for a store's commerce credential.
 *
 * The credential itself is derived, never stored (see CommerceCredential), which means there is
 * no row to delete when an operator wants it gone. This column is what makes revocation possible
 * anyway: the version is inside the signed material, so bumping it invalidates every credential
 * minted before that instant, immediately and without a deploy.
 *
 * Starts at 0 and only ever increases. A link that has never issued one is indistinguishable
 * from one whose credential was revoked, which is correct -- in both cases nothing verifies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->unsignedInteger('commerce_credential_version')->default(0)->after('events_reconciled_at');
            $table->timestamp('commerce_credential_issued_at')->nullable()->after('commerce_credential_version');
        });
    }

    public function down(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn(['commerce_credential_version', 'commerce_credential_issued_at']);
        });
    }
};
