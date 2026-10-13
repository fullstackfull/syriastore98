<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store -> Lynomia account mapping, and the only authoritative record of it.
 *
 * TWO UNIQUE INDEXES, AND THEY ARE THE BUSINESS RULE.
 *
 *   store_id          UNIQUE -- "one Lynomia account per Store Syria store". A store cannot
 *                     hold two links, so it cannot be pointed at a second workspace.
 *   lynomia_account_id UNIQUE -- the same rule read the other way. One Lynomia account cannot
 *                     back two stores, which is what would let conversations, contacts and
 *                     channels leak between stores that merely share an owner.
 *
 * Both are enforced by the DATABASE rather than by a service check, because the check-then-
 * write pattern this codebase uses elsewhere for quotas loses races: two concurrent provision
 * requests both read "no link" and both insert. A unique index is the arbiter; the service
 * catches the violation and reports it. The second index is partial-by-convention (NULL is
 * not equal to NULL in SQL, so many unprovisioned rows can coexist) -- that is exactly the
 * behaviour wanted while a link is pending.
 *
 * NO CREDENTIAL IS STORED HERE. The Platform App token lives in the environment
 * (packages/workdo/LynomiaChat/src/config/lynomia.php explains why at length: APP_KEY is
 * public and unrotated, so Laravel's `encrypted` cast protects nothing in this installation).
 * Everything in this table is a non-secret identifier or a state flag, with one exception --
 * verification_code_hash, which is a HASH and is never reversible to the code the merchant saw.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lynomia_store_links')) {
            return;
        }

        Schema::create('lynomia_store_links', function (Blueprint $table) {
            $table->id();

            // The tenant. Unique: one Lynomia account per store.
            $table->unsignedBigInteger('store_id')->unique();

            // The Store Syria user who owns the tenant this link belongs to, resolved through
            // creatorId() at creation time. Carried so an authorization check never has to
            // re-derive ownership from the request.
            $table->unsignedBigInteger('owner_user_id')->index();

            // Lynomia identifiers. Null until provisioning or linking completes.
            $table->unsignedBigInteger('lynomia_account_id')->nullable()->unique();
            $table->unsignedBigInteger('lynomia_user_id')->nullable();
            $table->string('lynomia_account_name')->nullable();

            // pending_verification | active | suspended | revoked
            $table->string('state', 32)->index();

            // provision (a brand new workspace) | link_existing (an account that already
            // existed, which may belong to someone else and therefore needs approval)
            $table->string('link_mode', 32);

            // Existing-account linking only. The merchant is shown the code once; only its
            // hash is kept. Expiry bounds how long a pending claim stays answerable.
            $table->string('verification_code_hash')->nullable();
            $table->timestamp('verification_expires_at')->nullable();

            // Who approved the link, and when. Administrative approval is mandatory for
            // link_existing and is the control that stops a merchant claiming an account
            // they do not own.
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();

            // Operational breadcrumbs. last_error is an operator-facing summary, never a
            // provider body and never a credential.
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_store_links');
    }
};
