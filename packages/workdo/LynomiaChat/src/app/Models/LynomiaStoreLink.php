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
 * @property int|null    $lynomia_user_id   The Lynomia ADMINISTRATOR who authorized a
 *                                          link_existing claim. Audit only -- this app holds
 *                                          no grant on them and cannot act as them. Null on a
 *                                          provisioned link, which nobody authorized because
 *                                          this app created the workspace itself.
 * @property string      $state
 * @property string      $link_mode
 * @property string|null $provisioning_stage
 * @property int         $provisioning_attempts
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
        'events_reconciled_at',
        'verification_code_hash',
        'verification_expires_at',
        'verified_at',
        'verified_by',
        'last_error',
        'last_synced_at',
        'provisioning_stage',
        'provisioning_started_at',
        'provisioning_attempts',
        'ownership_proven_at',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'owner_user_id' => 'integer',
        'lynomia_account_id' => 'integer',
        'lynomia_user_id' => 'integer',
        'verification_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'events_reconciled_at' => 'datetime',
        'provisioning_started_at' => 'datetime',
        'provisioning_attempts' => 'integer',
        'ownership_proven_at' => 'datetime',
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

    /**
     * The workspace exists and this row knows which one it is.
     *
     * IT USED TO ALSO REQUIRE A USER ID, back when a link carried the one shared identity
     * everybody signed in as. People now get their own identity, created on first use and
     * recorded in lynomia_store_identities, so there is no single user that a link either has
     * or lacks -- a workspace with an account id and no members yet is provisioned, and the
     * first person through the door is given a seat in it.
     */
    public function isProvisioned(): bool
    {
        return $this->lynomia_account_id !== null;
    }

    /**
     * True when Lynomia itself confirmed the merchant controls this workspace.
     *
     * Only a linked workspace needs this: a provisioned one was created by this app, so there
     * was never a claim to prove. The column stands for an answer that came from the other
     * side, and nothing in Store Syria can set it except redeeming a code.
     */
    public function ownershipProven(): bool
    {
        return $this->ownership_proven_at !== null;
    }

    /**
     * SSO is offered only for an active, fully provisioned link -- and, where the workspace
     * was CLAIMED rather than created here, only once Lynomia confirmed who the claimant is.
     *
     * The last clause is what makes an operator's approval insufficient on its own. An
     * operator can be mistaken or persuaded; they cannot manufacture a redemption, so a
     * claimed link that was never proven cannot be opened however it was approved.
     */
    public function allowsSso(): bool
    {
        if (! in_array($this->state, LinkState::SSO_ALLOWED, true) || ! $this->isProvisioned()) {
            return false;
        }

        return $this->link_mode !== self::MODE_LINK_EXISTING || $this->ownershipProven();
    }

    /**
     * Everyone who has been given an identity in this link's current workspace.
     *
     * Keyed on the ACCOUNT rather than on the store, which is what makes a re-pointed link
     * show the new workspace's members instead of the old one's. See the identities migration.
     */
    public function identities()
    {
        return $this->hasMany(LynomiaStoreIdentity::class, 'lynomia_account_id', 'lynomia_account_id');
    }

    public function verificationHasExpired(): bool
    {
        return $this->verification_expires_at !== null
            && $this->verification_expires_at->isPast();
    }
}
