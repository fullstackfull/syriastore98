<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * The store -> Lynomia account mapping.
 *
 * TENANT SCOPING IS EXPLICIT, NOT AMBIENT. This model deliberately does NOT use the
 * BelongsToStore trait / StoreScope. That scope resolves the tenant from the session and, by
 * documented decision, adds NO predicate when it cannot resolve one
 * (config/tenancy.php `null_tenant => open`), so it contributes nothing on an unauthenticated
 * path and would be a misleading thing to rely on for a row that grants workspace access.
 * Every read goes through forStore() or forOwnedStore() and names its tenant.
 *
 * @property int         $store_id
 * @property int         $owner_user_id
 * @property int|null    $lynomia_account_id
 * @property int|null    $lynomia_user_id
 * @property string      $state
 * @property string      $link_mode
 */
class LynomiaStoreLink extends Model
{
    protected $table = 'lynomia_store_links';

    protected $fillable = [
        'store_id',
        'owner_user_id',
        'lynomia_account_id',
        'lynomia_user_id',
        'lynomia_account_name',
        'state',
        'link_mode',
        'verification_code_hash',
        'verification_expires_at',
        'verified_at',
        'verified_by',
        'last_error',
        'last_synced_at',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'owner_user_id' => 'integer',
        'lynomia_account_id' => 'integer',
        'lynomia_user_id' => 'integer',
        'verification_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    /**
     * The verification hash must never reach a response or a log, even if a future controller
     * returns the model directly. It is not a credential for Lynomia, but it is the secret
     * that completes a pending claim on someone else's workspace.
     */
    protected $hidden = ['verification_code_hash'];

    public const MODE_PROVISION = 'provision';
    public const MODE_LINK_EXISTING = 'link_existing';

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /**
     * The scope an authenticated merchant path should use: the row must belong to the named
     * store AND to the caller's own tenant. Passing owner_user_id makes a stolen or guessed
     * store id insufficient on its own.
     */
    public function scopeForOwnedStore(Builder $query, int $storeId, int $ownerUserId): Builder
    {
        return $query->where('store_id', $storeId)->where('owner_user_id', $ownerUserId);
    }

    public function isActive(): bool
    {
        return $this->state === LinkState::ACTIVE;
    }

    public function isProvisioned(): bool
    {
        return $this->lynomia_account_id !== null && $this->lynomia_user_id !== null;
    }

    /** SSO is offered only for an active, fully provisioned link. */
    public function allowsSso(): bool
    {
        return in_array($this->state, LinkState::SSO_ALLOWED, true) && $this->isProvisioned();
    }

    public function verificationHasExpired(): bool
    {
        return $this->verification_expires_at !== null
            && $this->verification_expires_at->isPast();
    }
}
