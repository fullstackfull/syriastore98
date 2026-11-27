<?php

namespace App\Models;

use App\Support\SupportAccess\AccessKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE AUTHORISATION FOR SUPPORT TO REACH ONE STORE, FOR A WHILE, FOR A REASON.
 *
 * `SUPPORT-004` / `SUPPORT-005`. The migration carries the schema reasoning; this class carries the
 * two model-level decisions, both of which are deliberate departures from what most models
 * in this directory do.
 *
 * -------------------------------------------------------------------------------------------------
 *  1. NOT `Cachable`, AND THIS IS A SECURITY PROPERTY RATHER THAN A PREFERENCE
 * -------------------------------------------------------------------------------------------------
 *
 * CLAUDE.md records that most models here use `GeneaLabs\LaravelModelCaching\Cachable`, and
 * that the 16 that do not include `AuditLog`, `Permission` and `Role`. This model joins that list
 * for the same reason those three are on it: **it answers an authorization question, and a cached
 * answer to an authorization question is a stale answer to an authorization question.**
 *
 * Concretely, with `Cachable` the following sequence would admit the operator:
 *
 *   1. the gate reads the grant and the row is cached;
 *   2. the merchant withdraws consent — `revoked_at` is written;
 *   3. the gate reads the grant again and is served the PRE-REVOCATION row.
 *
 * A merchant revoking support access is the one moment in this feature's life when the answer MUST
 * change immediately, and CLAUDE.md's own warning is that under `CACHE_STORE=array` a stale read
 * "lives for the rest of the test" — in production under `CACHE_STORE=database` it lives until the
 * tag is flushed by some unrelated write. `SupportAccessRevocationIsImmediateTest` drives exactly
 * that three-step sequence, and it is the test that would fail if someone adds the trait.
 *
 * -------------------------------------------------------------------------------------------------
 *  2. NO `BelongsToStore`, SO NO `StoreScope`
 * -------------------------------------------------------------------------------------------------
 *
 * The usual rule in this codebase is that a `store_id`-bearing model should be scoped. This one
 * must not be, and the migration explains why at length: the reader is the platform OPERATOR, for
 * whom `getCurrentStore()` returns a hardcoded `1`, so a scope would confine the operator's view of
 * their own grants to store 1 — admitting a store-1 grant everywhere and refusing every other.
 * Every read in `App\Support\SupportAccess\SupportAccessGate` therefore names `store_id` explicitly,
 * which CLAUDE.md requires anyway ("never let the scope be your only tenant check").
 *
 * -------------------------------------------------------------------------------------------------
 *  3. `$fillable` IS NARROW ON PURPOSE
 * -------------------------------------------------------------------------------------------------
 *
 * `consented_at`, `consented_by`, `revoked_at`, `revoked_by`, `uses`, `first_used_at` and
 * `last_used_at` are ABSENT from `$fillable`. They are the columns that decide whether the grant is
 * active, and the only writers are the named methods below — so a future `->update($request->all())`
 * cannot consent to a grant on the merchant's behalf. CLAUDE.md records a measured defect caused by
 * `$fillable` claiming a column the table did not have; this is the opposite direction and the same
 * discipline. `SupportAccessGrantFillableTest` asserts the omission rather than leaving it to be
 * re-added by someone tidying up.
 */
class SupportAccessGrant extends Model
{
    protected $table = 'support_access_grants';

    protected $fillable = [
        'store_id',
        'merchant_id',
        'grantee_id',
        'kind',
        'scope',
        'reason',
        'requested_by',
        'requested_at',
        'expires_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'consented_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'first_used_at' => 'datetime',
        'last_used_at' => 'datetime',
        'uses' => 'integer',
        'store_id' => 'integer',
        'merchant_id' => 'integer',
        'grantee_id' => 'integer',
        'requested_by' => 'integer',
        'consented_by' => 'integer',
        'revoked_by' => 'integer',
    ];

    public function approvals(): HasMany
    {
        return $this->hasMany(SupportAccessApproval::class, 'grant_id');
    }

    public function grantee()
    {
        return $this->belongsTo(User::class, 'grantee_id');
    }

    public function isEmergency(): bool
    {
        return $this->kind === AccessKind::EMERGENCY;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Has this grant run out?
     *
     * `expires_at` is NOT NULL in the schema, but a row read from a database built by an older
     * migration — or hand-edited — could still present null here, and `null` must mean EXPIRED, not
     * "never expires". That is the whole temporary clause of `SUPPORT-004` and it is the one place a
     * null check could silently invert the requirement, so it is written as a positive test rather
     * than `! isPast()`.
     */
    public function hasExpired(): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->isPast();
    }

    /** The merchant's consent, for a `support` grant. Never set on an `emergency` grant. */
    public function isConsented(): bool
    {
        return $this->consented_at !== null;
    }
}
