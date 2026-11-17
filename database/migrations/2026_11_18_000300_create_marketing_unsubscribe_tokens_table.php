<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 05 D8 / MKT-008 — THE CAPABILITY TO UNSUBSCRIBE, one random token per message recipient.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying it
 * needs the owner's separate authorisation.**
 *
 * RANDOM AND HASHED, NOT SIGNED. A signed URL would be derived from `APP_KEY`, which is in git history
 * and unrotated, so anyone could forge one. A random 256-bit token looked up by its SHA-256 cannot be
 * forged without the database, and storing only the hash means a read of this table cannot be turned
 * into working unsubscribe links. The plain token exists exactly once, in the message it was issued
 * for. `token_hash` is unique so a lookup is one indexed read.
 *
 * THE ROW NAMES THE STORE, THE CHANNEL AND THE ADDRESS HASH -- NEVER THE ADDRESS. The page a token opens
 * shows `address_hint`, a masked rendering, and nothing else, so possessing a link does not reveal who
 * it belongs to.
 *
 * NO FOREIGN KEYS, like its siblings. `used_at` records the first use only; using a link again is a
 * no-op, because withdrawing consent that is already withdrawn changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'marketing_unsubscribe_tokens',
            ['store_id', 'channel', 'purpose', 'address_hash', 'address_hint', 'customer_id', 'token_hash', 'created_at', 'used_at'],
            ['mut_token_hash_unique', 'mut_contact_index'],
            []
        );

        if (Schema::hasTable('marketing_unsubscribe_tokens')) {
            return;
        }

        Schema::create('marketing_unsubscribe_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');
            $table->string('channel', 16);
            $table->string('purpose', 32)->default('marketing');
            $table->char('address_hash', 64);
            $table->string('address_hint', 191)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();

            /* SHA-256 hex of the token. The token itself is never stored. */
            $table->char('token_hash', 64);

            $table->timestamp('created_at')->nullable();
            $table->timestamp('used_at')->nullable();

            $table->unique('token_hash', 'mut_token_hash_unique');
            $table->index(['store_id', 'channel', 'address_hash'], 'mut_contact_index');
        });
    }

    /** Refuses to drop a populated table: dropping it would silently kill every unsubscribe link already sent. */
    public function down(): void
    {
        if (! Schema::hasTable('marketing_unsubscribe_tokens')) {
            return;
        }

        if (Schema::getConnection()->table('marketing_unsubscribe_tokens')->exists()) {
            throw new RuntimeException('Refusing to drop marketing_unsubscribe_tokens: links already sent in messages would stop working.');
        }

        Schema::drop('marketing_unsubscribe_tokens');
    }
};
