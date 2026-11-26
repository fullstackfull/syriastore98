<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE PERSON'S DECISION ON ONE EMERGENCY ACCESS REQUEST — `SUPPORT-005`.
 *
 * NOT `Cachable`, for the reason `SupportAccessGrant` gives: a declined approval that is served
 * from cache as absent would admit access the reviewer refused.
 *
 * A DECLINE IS A ROW, NOT A DELETION. `SupportAccessGate` treats any `declined` row as fatal to the
 * grant regardless of how many approvals sit beside it, so "two approved, one refused" cannot be
 * made to read as "two approved" by anyone short of deleting a row from the table. The alternative
 * — recording only approvals — loses the single most important fact a reviewer can contribute.
 */
class SupportAccessApproval extends Model
{
    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    protected $table = 'support_access_approvals';

    protected $fillable = [
        'grant_id',
        'approver_id',
        'decision',
        'note',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'grant_id' => 'integer',
        'approver_id' => 'integer',
    ];

    public function grant(): BelongsTo
    {
        return $this->belongsTo(SupportAccessGrant::class, 'grant_id');
    }

    public static function isKnownDecision(?string $decision): bool
    {
        return $decision !== null && in_array($decision, [self::APPROVED, self::DECLINED], true);
    }
}
