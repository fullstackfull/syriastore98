<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE COMMERCE STORE ID LYNOMIA GAVE THIS STORE, ONCE WE HAVE SEEN IT.
 *
 * A campaign created without `commerce_store_id` resolves ACCOUNT-WIDE upstream, so the
 * difference between "this merchant has no commerce installation" and "their installation
 * exists but is not usable right now" decides whether a campaign is correctly account-wide
 * or silently addresses another store's customers. Lynomia's `audience_fields` lists only
 * COUNTED stores, so an installation in `needs_reauth`, disabled, or with its provider
 * switched off simply vanishes from it and the two states look identical.
 *
 * The first attempt told them apart by `commerce_credential_issued_at`, which was wrong in
 * both directions: that column means "a Store Syria operator minted a token", which is set
 * BEFORE registration is possible (the token is the input to it) and is cleared on revoke
 * while Lynomia still holds the installation. So it reported "registered" during onboarding
 * before anything existed upstream, and "never registered" after a revoke when it did.
 *
 * This column records an OBSERVED fact instead: the id Lynomia itself returned for this
 * store, written the first time it appears in `audience_fields`. Having seen it once is
 * proof the installation exists; not having seen it is the honest absence of that proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->unsignedBigInteger('lynomia_commerce_store_id')->nullable()->after('commerce_external_store_id');
        });
    }

    public function down(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn('lynomia_commerce_store_id');
        });
    }
};
