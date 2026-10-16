<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One merchant's in-flight attempt to prove they hold a phone number.
 *
 * See the migration for why the candidate number waits here rather than going straight onto
 * the user, and why the code is hashed with a bounded attempt count.
 */
class LynomiaContactVerification extends Model
{
    protected $table = 'lynomia_contact_verifications';

    /** How long a code is good for. Short, because the merchant is holding the phone. */
    public const TTL_MINUTES = 10;

    /**
     * Wrong codes before the challenge is burned.
     *
     * Six digits is a million possibilities, which is ample against five tries and nothing at
     * all against unlimited ones. Burning rather than locking: the merchant asks again and
     * gets a new code, which costs them a few seconds and costs an attacker the whole search.
     */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'user_id', 'store_id', 'candidate_phone', 'code_hash', 'expires_at', 'attempts',
        'delivery_state', 'delivery_reason',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function hasExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function attemptsExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    /** Whether a code may still be presented against this challenge at all. */
    public function open(): bool
    {
        return ! $this->hasExpired() && ! $this->attemptsExhausted();
    }
}
