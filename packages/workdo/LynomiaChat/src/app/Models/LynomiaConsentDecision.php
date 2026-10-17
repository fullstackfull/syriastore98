<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a customer told THIS store, and whether Lynomia has been told yet.
 *
 * APPEND-ONLY. A withdrawal is not an edit of a grant, it is a later decision, and keeping
 * both is what makes "when did they agree, and when did they stop" answerable. The current
 * local answer for a scope is the newest row, which `latestPerScope` reads.
 *
 * THE OUTBOX FIELDS ARE THE SAME SHAPE AS LynomiaCommerceEvent, deliberately: a consent
 * decision is a durable obligation to tell the other side, which is the same problem a
 * commerce event has, and a second delivery mechanism would be a second thing to get wrong.
 *
 * A REMOTE REFUSAL IS AN ANSWER, NOT A FAILURE. Lynomia refuses a plain grant that follows a
 * withdrawal, and that refusal is correct and final -- the row is marked delivered with the
 * outcome recorded, never retried. Retrying it would be asking the same question until the
 * answer changed, which it will not.
 */
class LynomiaConsentDecision extends Model
{
    protected $table = 'lynomia_consent_decisions';

    public const PURPOSES = ['transactional', 'promotional'];

    public const CHANNELS = ['any', 'whatsapp', 'sms', 'email'];

    /** What a merchant or a checkout may record. `suppressed` is Lynomia's and arrives inbound. */
    public const RECORDABLE_STATES = ['granted', 'withdrawn'];

    protected $fillable = [
        'store_id', 'customer_id', 'external_subject_id', 'purpose', 'channel', 'state', 'source',
        'policy_version', 're_consent', 'evidence', 'captured_at', 'recorded_by', 'decision_ref',
        'attempts', 'deliver_after', 'delivered_at', 'remote_outcome', 'last_error',
    ];

    protected $casts = [
        'evidence' => 'array',
        're_consent' => 'boolean',
        'captured_at' => 'datetime',
        'deliver_after' => 'datetime',
        'delivered_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * Fails closed on an unresolvable tenant, like every other store scope in this module: no
     * rows rather than every store's.
     */
    public function scopeForStore(Builder $query, $storeId): Builder
    {
        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('store_id', (int) $storeId);
    }

    /** Rows that still owe a delivery and whose backoff has elapsed. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('delivered_at')
            ->where(fn (Builder $inner) => $inner->whereNull('deliver_after')->orWhere('deliver_after', '<=', now()));
    }

    /**
     * The newest decision per scope for one store -- the current LOCAL answer, which is not
     * necessarily what Lynomia holds. LynomiaConsentState is the other half of that question.
     */
    public function scopeLatestPerScope(Builder $query, $storeId): Builder
    {
        return $query->forStore($storeId)
            ->whereIn('id', function ($inner) use ($storeId) {
                $inner->selectRaw('MAX(id)')
                    ->from('lynomia_consent_decisions')
                    ->where('store_id', (int) $storeId)
                    ->groupBy('external_subject_id', 'purpose', 'channel');
            });
    }
}
