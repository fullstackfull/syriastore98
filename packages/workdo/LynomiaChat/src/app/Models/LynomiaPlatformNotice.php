<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One operational notice Store Syria sent a merchant, and what became of it.
 *
 * See the migration for why the unique `dedupe_key` is the idempotency mechanism and why the
 * state is written before the remote call rather than after.
 */
class LynomiaPlatformNotice extends Model
{
    /** Store Syria's own vocabulary for the occasion. Only what the owner authorised. */
    public const KINDS = ['onboarding', 'integration_recovery', 'security', 'service', 'support'];

    public const PENDING = 'pending';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    /**
     * The answer was lost. NOT retried automatically: for an operational notice the safe
     * failure is one that might not arrive, not one that arrives twice, and nothing on
     * Lynomia's send path makes a second attempt idempotent.
     */
    public const INDETERMINATE = 'indeterminate';

    protected $table = 'lynomia_platform_notices';

    protected $fillable = [
        'store_id', 'recipient_user_id', 'recipient_phone', 'kind', 'template_name',
        'template_language', 'template_params', 'dedupe_key', 'status', 'failure_reason',
        'lynomia_message_id', 'sent_at', 'requested_by', 'automated',
    ];

    protected $casts = [
        'template_params' => 'array',
        'automated' => 'boolean',
        'sent_at' => 'datetime',
    ];

    /** Fails closed on an unresolvable tenant, as every other store scope in this module does. */
    public function scopeForStore(Builder $query, $storeId): Builder
    {
        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('store_id', (int) $storeId);
    }

    /** Rows an operator has to look at: the send went out and nobody knows whether it landed. */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [self::INDETERMINATE, self::FAILED]);
    }

    public function settled(): bool
    {
        return in_array($this->status, [self::SENT, self::FAILED], true);
    }
}
