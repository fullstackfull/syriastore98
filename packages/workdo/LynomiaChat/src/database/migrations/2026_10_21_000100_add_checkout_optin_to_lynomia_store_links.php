<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The merchant's own switch for the marketing opt-in on their checkout, and the policy
 * version a shopper is agreeing to when they tick it.
 *
 * ON THE LINK ROW RATHER THAN IN SETTINGS, for the reason every other value in this module
 * is: `lynomia_store_links.store_id` is UNIQUE, so one row is one store and a query cannot
 * accidentally return another tenant's answer. The platform's `Utility::GetValueByName`
 * settings bag is keyed by store too, but it is a shared namespace with no ownership, and a
 * consent policy version is not a display preference.
 *
 * DEFAULT OFF. Turning this on puts a new field on a live storefront's checkout. That is the
 * merchant's decision to make, not a consequence of connecting the integration, and a
 * migration that switched it on for every connected store would change checkouts nobody
 * asked to change.
 *
 * THE POLICY VERSION IS NULLABLE AND TRAVELS WITH THE DECISION. A grant recorded under v1 of
 * a marketing policy is evidence about v1; if the merchant later rewrites the policy, old
 * grants must keep saying which text they were given, which is why it is copied onto each
 * decision row rather than read back from here at display time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->boolean('checkout_optin_enabled')->default(false)->after('commerce_credential_issued_at');
            $table->string('consent_policy_version', 64)->nullable()->after('checkout_optin_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn(['checkout_optin_enabled', 'consent_policy_version']);
        });
    }
};
