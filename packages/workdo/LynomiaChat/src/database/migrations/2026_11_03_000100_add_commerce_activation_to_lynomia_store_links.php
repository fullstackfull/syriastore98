<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT HAPPENED THE LAST TIME THIS STORE TRIED TO TURN COMMERCE ON, so the merchant can be told.
 *
 * NOT A SECOND STATE MACHINE. There is no `commerce_state` column here, deliberately. Six things
 * already describe parts of this and a seventh that paraphrased them would be the one that drifts:
 * `state` says whether the workspace is usable, `commerce_credential_version` /
 * `commerce_credential_issued_at` say whether a credential would verify,
 * `lynomia_commerce_store_id` says Lynomia itself has told us the installation exists, and
 * `commerce_external_store_id` says by what name. What was MISSING was only the record of the
 * last attempt, which is what these five columns are. The merchant-facing state is derived from
 * all of them in CommerceState and stored nowhere.
 *
 * WHY `lynomia_commerce_store_id` WAS NOT ENOUGH ON ITS OWN. It is written opportunistically by
 * CampaignApiController when the store happens to appear in Lynomia's `audience_fields`, so its
 * absence has always meant two different things -- "never installed" and "installed, then dropped
 * out of the counted set" -- and neither of those is "the attempt failed, here is why". A merchant
 * looking at a page that cannot tell those apart is told either nothing or something false.
 *
 * commerce_installed_at           Lynomia confirmed an installation exists, at this moment.
 * commerce_remote_status          `commerce_stores.status` as Lynomia last reported it. A CACHE OF
 *                                 THE FAR SIDE'S OWN VALUE, like lynomia_commerce_store_id beside
 *                                 it -- not a state of ours. It is what tells "connected" from
 *                                 "an operator suspended it there" from "Lynomia rejected the
 *                                 credential", three situations with three different things for
 *                                 the merchant to do and no way to distinguish them from this
 *                                 side otherwise. Never written except from an answer.
 * commerce_verified_at            the last time that confirmation was re-obtained, not assumed.
 * commerce_last_error             the merchant-safe reason the last attempt failed; null on success.
 * commerce_activation_started_at  the in-flight claim, so a double click is one call. Same
 *                                 conditional-UPDATE idiom as provisioning_started_at, and stale
 *                                 after the same window, so a killed process cannot wedge a store.
 * commerce_attempts               how many attempts, for the bounded-retry ceiling and for an
 *                                 operator reading one row.
 *
 * Every existing link reads as "not connected" until something observes otherwise, which is the
 * honest default: this installation has never asked Lynomia whether those stores have an
 * installation, so it does not know. Nothing is backfilled, so the rollback is a column drop with
 * nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->timestamp('commerce_installed_at')->nullable()->after('lynomia_commerce_store_id');
            $table->string('commerce_remote_status', 32)->nullable()->after('commerce_installed_at');
            $table->timestamp('commerce_verified_at')->nullable()->after('commerce_remote_status');
            $table->string('commerce_last_error', 480)->nullable()->after('commerce_verified_at');
            $table->timestamp('commerce_activation_started_at')->nullable()->after('commerce_last_error');
            $table->unsignedInteger('commerce_attempts')->default(0)->after('commerce_activation_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn([
                'commerce_installed_at',
                'commerce_remote_status',
                'commerce_verified_at',
                'commerce_last_error',
                'commerce_activation_started_at',
                'commerce_attempts',
            ]);
        });
    }
};
