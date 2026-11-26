<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `IAM-003` — one request for a dangerous operator action, and its approval.
 *
 * **DELIBERATELY NOT `Cachable`**, which is the one thing about this class that would be wrong by
 * default. CLAUDE.md records that 79 of the 95 models in `app/Models/` carry that trait and that a
 * cached read serves a pre-write row for the rest of a test under `CACHE_STORE=array` — three
 * measured false readings. The 16 exceptions are the models whose rows are read back immediately
 * after being written, `AuditLog` and `Permission` among them, and this is exactly that shape: the
 * gate writes a `pending` row and the very next request asks whether one exists. A stale answer
 * here does not cost a wrong number on a screen; it costs a SECOND execution of a dangerous
 * action, or an approval applied to a row that has already been executed.
 *
 * **NO `StoreScope` AND NOT IN `config/tenancy.php`'s `scoped_models`**, on purpose. Every action
 * governed here is a platform action. CLAUDE.md records `null_tenant => 'open'`, so a scope would
 * add no predicate for the principal that matters anyway (the operator), while making the
 * approvals queue silently narrow for anybody who did resolve a tenant.
 */
class OperatorActionApproval extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_APPROVED = 'approved';
    public const STATE_EXECUTED = 'executed';
    public const STATE_FAILED = 'failed';
    public const STATE_REJECTED = 'rejected';

    protected $table = 'operator_action_approvals';

    protected $fillable = [
        'action',
        'payload',
        'fingerprint',
        'state',
        'requested_by',
        'approved_by',
        'decided_at',
        'executed_at',
        'refusal_reason',
        'outcome',
    ];

    protected $casts = [
        'payload' => 'array',
        'outcome' => 'array',
        'decided_at' => 'datetime',
        'executed_at' => 'datetime',
        'requested_by' => 'integer',
        'approved_by' => 'integer',
    ];

    /**
     * The operator who asked. `belongsTo` because this table holds the key.
     *
     * NOT used for authorisation anywhere: `TwoPersonApproval` compares integer ids
     * (`requested_by` against the approver's `id`) rather than loading a relation, so a deleted
     * or renamed user cannot change who may approve a row.
     */
    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Has the effect this row asks for NOT happened yet? */
    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }
}
