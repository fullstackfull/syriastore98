<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What Lynomia actually holds for one scope, as it last told us.
 *
 * WHY THIS IS NOT THE SAME AS A DECISION. Store Syria is authoritative for what a customer
 * told this store; Lynomia is authoritative for whether a message is sent
 * (docs/partner-consent/AUTHORITY.md §1). Those agree almost always and differ whenever a
 * delivery is in flight, when Lynomia refused a grant that followed a withdrawal, or when a
 * shopper opted out on WhatsApp and this side has not caught up. A merchant screen showing
 * only the local decision would promise sends that will not happen.
 *
 * `revision` IS LYNOMIA'S SERVER-ASSIGNED ORDER, and an inbound event carrying one that is not
 * greater is dropped. That is the same rule the other side applies to its own writes, so the
 * two cannot disagree about which decision is newest -- which is what stops a delayed event
 * from reinstating something that was already superseded.
 */
class LynomiaConsentState extends Model
{
    protected $table = 'lynomia_consent_states';

    protected $fillable = [
        'store_id', 'external_subject_id', 'purpose', 'channel', 'state', 'source',
        'revision', 'origin', 'occurred_at', 'observed_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'observed_at' => 'datetime',
        'revision' => 'integer',
    ];

    public function scopeForStore(Builder $query, $storeId): Builder
    {
        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('store_id', (int) $storeId);
    }

    /** Lynomia will not send marketing on this scope. */
    public function blocking(): bool
    {
        return in_array($this->state, ['withdrawn', 'suppressed'], true);
    }
}
