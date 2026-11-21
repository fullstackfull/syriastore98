<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GAP-219 — one recorded nudge: what was sent, to which contact, about what, and how it ended.
 *
 * NOT `Cachable`, DELIBERATELY, and that is the same call D8-1 made for the three consent models.
 * CLAUDE.md records that 79 of 95 models in `app/Models/` are `Cachable` and that a raw write then
 * leaves Eloquent reads serving the pre-write row — three measured false readings. A duplicate
 * guard is the last place that can be tolerated: the question it answers is "did this ALREADY
 * happen", so a cached miss is a second billable message. Every read here must hit the database.
 *
 * `store_id`, `created_by`, `idempotency_key` AND `outcome` ARE NOT FILLABLE. The first two are the
 * ownership and the lesson `order_notes.created_by` taught; the second two are the integrity. A
 * mass-assignable `idempotency_key` would let a caller claim any key, including one that already
 * exists for another action, and a mass-assignable `outcome` would let a refusal be recorded as a
 * send. `NudgeLedger` sets all four as properties, from the principal and from the derived key.
 */
class MarketingNudgeSend extends Model
{
    protected $table = 'marketing_nudge_sends';

    /** @see the class docblock for the four columns deliberately absent. */
    protected $fillable = [
        'channel',
        'message_class',
        'template',
        'contact_hash',
        'subject_type',
        'subject_id',
        'sent_at',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'sent_at' => 'datetime',
    ];

    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_DUPLICATE = 'duplicate_suppressed';

    public const OUTCOME_THROTTLED = 'throttled';

    public const OUTCOME_BLOCKED = 'blocked';

    /** The outcomes that mean a message really left the building. */
    public static function deliveredOutcomes(): array
    {
        return [self::OUTCOME_SENT];
    }
}
