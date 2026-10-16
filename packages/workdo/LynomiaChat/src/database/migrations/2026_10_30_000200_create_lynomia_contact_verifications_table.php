<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE CHALLENGE IN FLIGHT PER MERCHANT, and the candidate number it is about.
 *
 * WHY THE CANDIDATE DOES NOT GO STRAIGHT INTO `users.mobile`. A merchant who has verified one
 * number and then types another must not lose the verified one to a number nobody has proved
 * -- and must not be able to acquire a verified flag for a number they never answered on. So
 * the candidate waits here and is promoted onto the user only when a code sent TO IT comes
 * back. Until then the merchant's existing contact state is untouched.
 *
 * ONE ROW PER USER, replaced when a merchant asks again. The history is the audit trail, which
 * records every request and every outcome without the number; a second table of expired
 * challenges would hold nothing but personal data nobody reads.
 *
 * THE CODE IS HASHED, as it is for an account claim (lynomia_store_links.verification_code_hash),
 * for the same reason: a readable code in the database is a code anybody with a backup can use.
 * `attempts` is what stops a six-digit code being guessed -- a hashed code with unlimited
 * attempts is a slower brute force, not a closed one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lynomia_contact_verifications', function (Blueprint $table) {
            $table->id();

            // One in flight per merchant. The unique index is the arbiter, not a check: two
            // tabs pressing Send at once would otherwise leave two live codes for one number.
            $table->unsignedBigInteger('user_id')->unique();

            // The store the request was made from, so an operator can see which merchant this
            // is about without joining through users, and so the row is attributable after a
            // store changes hands.
            $table->unsignedBigInteger('store_id');

            // Digits only, no punctuation, no leading plus: the form MerchantContact stores and
            // compares in, so a merchant retyping +963 900 111 222 matches 963900111222.
            $table->string('candidate_phone', 20);

            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);

            // What became of the send. Null until the notice is attempted; the reason it failed
            // otherwise, so a merchant who never received a code is not left guessing.
            $table->string('delivery_state', 32)->nullable();
            $table->string('delivery_reason', 191)->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['store_id']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_contact_verifications');
    }
};
