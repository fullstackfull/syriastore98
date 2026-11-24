<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The capability to unsubscribe ONE address from ONE channel at ONE store. D8 / MKT-008.
 *
 * THE TOKEN IS RANDOM, NOT SIGNED. A signed URL derived from `APP_KEY` would be forgeable here,
 * because that key is in git history and unrotated (CLAUDE.md, "Routing"). A random value looked up
 * by its hash is not: forging one needs the database. Only the SHA-256 of the token is stored, so a
 * read of this table cannot be turned into working unsubscribe links, and the plain token exists
 * exactly once, in the message it was issued for.
 *
 * It names the store, channel and address hash -- never the address -- so the page it opens can show
 * a masked address and nothing else.
 */
class MarketingUnsubscribeToken extends Model
{
    protected $table = 'marketing_unsubscribe_tokens';

    /* `created_at` and `used_at` are written explicitly; there is no `updated_at` column. */
    public $timestamps = false;

    /** Everything `UnsubscribeTokens` writes, and nothing else. `used_at` is stamped by a single conditional UPDATE, not mass-assigned. */
    protected $fillable = ['store_id', 'channel', 'purpose', 'address_hash', 'address_hint', 'customer_id', 'token_hash', 'created_at'];

    protected $casts = [
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];
}
