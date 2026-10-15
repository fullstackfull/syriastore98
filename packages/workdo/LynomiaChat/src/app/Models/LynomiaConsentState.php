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
 * `revision` IS LYNOMIA'S SERVER-ASSIGNED ORDER WITHIN ONE ORIGIN, and an inbound event
 * carrying one that is not greater than the stored one FOR THAT ORIGIN is dropped. That is the
 * same rule the other side applies to its own writes, so the two cannot disagree about which
 * decision is newest -- which is what stops a delayed event from reinstating something that
 * was already superseded.
 *
 * ONE ROW PER ORIGIN, SO NO SINGLE ROW IS THE ANSWER. Lynomia publishes from two consent
 * stores whose revisions count in unrelated sequences (see the migration
 * 2026_10_21_000200_scope_lynomia_consent_states_by_origin for what sharing a row cost). The
 * effective answer is `effectiveFor`, which unions the negatives -- the same rule Lynomia
 * applies at its own send boundary, so the merchant screen and the sender agree.
 */
class LynomiaConsentState extends Model
{
    protected $table = 'lynomia_consent_states';

    public const ORIGIN_UNKNOWN = 'unknown';

    /** States that stop a send, in precedence order: a suppression outranks a withdrawal. */
    public const BLOCKING = ['suppressed', 'withdrawn'];

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
        return in_array($this->state, self::BLOCKING, true);
    }

    /**
     * WHAT LYNOMIA WILL ACTUALLY DO for one person and one purpose at this store, across every
     * origin and every channel this side knows about.
     *
     * NEGATIVES UNION AND POSITIVES DO NOT, which is the rule Lynomia enforces at its own send
     * boundary (custom/app/services/campaigns/send_guard.rb). So: any blocking row blocks, a
     * suppression is reported ahead of a withdrawal because it is the one the merchant cannot
     * undo, and a grant is only the answer when nothing blocks. Reading one row instead would
     * let a partner grant hide a native withdrawal and promise the merchant a send that will
     * not happen.
     *
     * CHANNELS COLLAPSE THE SAME WAY. A withdrawal on one channel blocks that channel and a
     * channel-agnostic one blocks all of them; neither is overturned by a grant elsewhere.
     * This answers the merchant's question -- "will anything reach this person" -- so it does
     * not try to be a per-channel matrix, which the screen shows from the rows themselves.
     *
     * @param iterable<LynomiaConsentState> $rows every row for one store, subject and purpose
     * @return array{state: string, origin: ?string, source: ?string, occurred_at: ?\Illuminate\Support\Carbon}
     */
    public static function effectiveFor(iterable $rows): array
    {
        $blocking = [];
        $granted = null;

        foreach ($rows as $row) {
            if ($row->blocking()) {
                $blocking[$row->state] = $row;
            } elseif ($row->state === 'granted') {
                $granted ??= $row;
            }
        }

        foreach (self::BLOCKING as $state) {
            if (isset($blocking[$state])) {
                return self::summarise($blocking[$state]);
            }
        }

        return $granted !== null ? self::summarise($granted) : ['state' => 'unknown', 'origin' => null, 'source' => null, 'occurred_at' => null];
    }

    private static function summarise(self $row): array
    {
        return [
            'state' => (string) $row->state,
            'origin' => $row->origin,
            'source' => $row->source,
            'occurred_at' => $row->occurred_at,
        ];
    }
}
