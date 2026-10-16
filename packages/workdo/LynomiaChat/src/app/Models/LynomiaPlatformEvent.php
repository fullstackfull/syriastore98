<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One delivery the platform feed sent us, applied or not. See the migration for why
 * idempotency, ordering evidence and audit history are one table rather than three.
 */
class LynomiaPlatformEvent extends Model
{
    protected $table = 'lynomia_platform_events';

    /** Why an event was recorded and not acted on. Each one is a fact, not an error. */
    public const SKIP_STALE = 'stale';
    public const SKIP_NO_NOTICE = 'no_matching_notice';
    public const SKIP_UNKNOWN_OUTCOME = 'unknown_outcome';

    protected $fillable = [
        'delivery_id', 'event', 'lynomia_account_id', 'lynomia_message_id', 'notice_id',
        'outcome', 'reason', 'occurred_at_us', 'applied', 'skip_reason',
    ];

    protected $casts = [
        'applied' => 'boolean',
        'occurred_at_us' => 'integer',
    ];
}
