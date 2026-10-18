<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN LYNOMIA STOPPED LETTING THIS APP INTO A WORKSPACE IT IS STILL LINKED TO.
 *
 * THE INCIDENT. A merchant opened Lynomia Chat -> Settings -> Integrations -> Connected
 * platforms, saw "Store Syria Integration" with a Disconnect button, and pressed it. That
 * deleted the one `platform_app_permissibles` row the whole integration rests on, and from that
 * moment every platform call for the workspace answered 401 -- SSO, health, commerce
 * installation, the lifecycle channel. Nothing was corrupt: the account, its users, inboxes,
 * conversations and this store's link row were all intact.
 *
 * AND THE MERCHANT'S PAGE WENT ON SAYING "CONNECTED", because every column it renders from was
 * still true as of the last call that worked. `state` was active, `lynomia_account_id` was 135,
 * `lynomia_commerce_store_id` was set, `commerce_last_error` was null -- so `allowsSso()`
 * returned true and `CommerceState::of()` returned CONNECTED. The one fact that had changed
 * lived entirely on the other system and had nowhere to be written down. This column is that
 * place.
 *
 * NOT A LINK STATE. `state` is what THIS side decided about the arrangement --
 * pending/active/suspended/revoked/decommissioned, all written by an operator or by
 * provisioning. Authorization is what the OTHER side decided about us, it can change without
 * anybody here doing anything, and it is recoverable by the merchant rather than by an operator.
 * Folding it into `state` would mean a merchant's click silently rewriting an operator's
 * decision, and would lose the distinction that decides who can fix it.
 *
 * OBSERVED, NEVER INFERRED. Written only when Lynomia actually answers
 * `error_code: non_permissible_resource`, which is a different 401 from a bad platform
 * credential (see LynomiaApiException::fromStatus). A credential fault is this installation's
 * problem and breaks every merchant at once; this is one merchant's and they can recover it
 * themselves. Cleared only when a call succeeds or a recovery is redeemed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->timestamp('authorization_lost_at')->nullable()->after('last_synced_at');
            // Kept beside it so an operator can tell "recovered once, in May" from "never broken",
            // which the nulled column above cannot say on its own.
            $table->timestamp('authorization_recovered_at')->nullable()->after('authorization_lost_at');
        });
    }

    public function down(): void
    {
        Schema::table('lynomia_store_links', function (Blueprint $table) {
            $table->dropColumn(['authorization_lost_at', 'authorization_recovered_at']);
        });
    }
};
