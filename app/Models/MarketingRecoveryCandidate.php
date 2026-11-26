<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MKT-007 — one thing worth recovering, and the record that nothing was sent about it.
 *
 * NOT `Cachable`, and for the reason `MarketingNudgeSend` gives: the question this table answers is
 * "have I already queued this", so a cached miss is a duplicate row and, on the day sending is
 * approved, a duplicate message. Most models in `app/Models/` carry the trait and CLAUDE.md
 * records three measured false readings from it; the sixteen that do not are the ones whose
 * correctness depends on reading the database as it is right now.
 *
 * NOT IN `config/tenancy.php`'s `scoped_models` either. The sweep runs from the CONSOLE, where no
 * tenant resolves and `null_tenant => 'open'` means a global scope would add no predicate at all,
 * so the explicit `store_id` on every query is the only tenant control on that path.
 */
class MarketingRecoveryCandidate extends Model
{
    public const STATE_HELD = 'held';

    public const STATE_RELEASED = 'released';

    /** Why a candidate was held. These are the only reasons the pipeline can give today. */
    public const HELD_CONSENT_OFF = 'consent_enforcement_off';

    public const HELD_NO_CADENCE = 'no_approved_cadence';

    protected $table = 'marketing_recovery_candidates';

    /**
     * NOTHING IS FILLABLE. Every column names a tenant, a subject or a person, and all of them are
     * written by the one command that owns this table. There is no attribute here a request may name
     * -- and there is no request: nothing on the web writes to it.
     */
    protected $guarded = ['*'];

    protected $casts = ['detected_at' => 'datetime'];

    /** Every read goes through here, so the tenant predicate cannot be forgotten at a call site. */
    public static function forStore(int $storeId): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->where('marketing_recovery_candidates.store_id', $storeId);
    }

    public function isHeld(): bool
    {
        return $this->send_state === self::STATE_HELD;
    }
}
