<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Lynomia identity per PERSON per workspace, replacing one shared identity per store.
 *
 * WHY THIS TABLE EXISTS. The first implementation attached a single service user to the
 * workspace as an ADMINISTRATOR and signed every member of the merchant's team in as it. Three
 * things followed from that, and all three are properties of the design rather than bugs in it:
 *
 *   nothing was attributable   Every conversation reply, every contact edit and every channel
 *                              change in Lynomia's own audit trail carried the same actor, so
 *                              "who did this" had no answer on either side of the integration.
 *   everyone was an admin      A warehouse packer and the owner arrived with identical rights,
 *                              including the ability to add channels and remove members.
 *   one leak was total         A single SSO URL, intercepted anywhere, was administrator access
 *                              to the whole workspace rather than one person's own seat.
 *
 * THE ROW IS KEYED ON THE WORKSPACE, NOT ON THE STORE, and that is deliberate. A store can be
 * re-pointed at a different Lynomia account -- a claim is replaced, a revoked link is restored
 * and re-proven -- and an identity minted inside the OLD workspace means nothing in the new one.
 * Keying the lookup on lynomia_account_id makes a stale row unfindable instead of making it
 * somebody's job to remember to delete it. store_id is carried alongside for operators and for
 * the cleanup that runs when a grant is handed back.
 *
 * TWO UNIQUE INDEXES, BOTH LOAD-BEARING:
 *
 *   (lynomia_account_id, user_id)  One identity per person per workspace. Two browser tabs both
 *                                  reaching "open my workspace" is the ordinary case, and the
 *                                  index -- not a check -- is what makes the loser re-read the
 *                                  winner's row instead of minting a second Lynomia user.
 *   lynomia_user_id                A Lynomia user backs exactly one mapping. If this ever fired
 *                                  it would mean two Store Syria people had been handed the same
 *                                  remote identity, which is the attribution failure this table
 *                                  exists to end.
 *
 * NO CREDENTIAL IS STORED. A Lynomia identity has no password anyone holds: the account is
 * created with a random secret that is discarded on the spot and never written down, and the
 * only way into it is the short-lived, account-scoped SSO link minted per click.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lynomia_store_identities')) {
            return;
        }

        Schema::create('lynomia_store_identities', function (Blueprint $table) {
            $table->id();

            // Which workspace this identity lives in. The lookup key, with user_id.
            $table->unsignedBigInteger('lynomia_account_id');

            // The Store Syria person. Not a foreign key for the same reason the links table
            // has none: users are soft-deleted and re-created around tenancy changes here, and
            // a cascade would silently drop the only record of a remote identity that still
            // exists on the Lynomia side.
            $table->unsignedBigInteger('user_id');

            // The Lynomia user created for that person.
            $table->unsignedBigInteger('lynomia_user_id');

            // administrator | agent -- the role this person holds IN LYNOMIA, derived from
            // their Store Syria role at the moment the identity was last synchronised. Stored
            // so a demotion can be detected without asking Lynomia on every sign-in.
            $table->string('role', 32);

            // Operator-facing: carried so a store's identities can be listed and cleaned up
            // without a join, and so an operator can see which store a remote user belongs to.
            $table->unsignedBigInteger('store_id')->index();

            $table->timestamp('last_sso_at')->nullable();

            $table->timestamps();

            $table->unique(['lynomia_account_id', 'user_id'], 'lynomia_identity_workspace_person_unique');
            $table->unique('lynomia_user_id', 'lynomia_identity_remote_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_store_identities');
    }
};
