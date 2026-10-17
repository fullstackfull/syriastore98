<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's own Lynomia identity inside one workspace.
 *
 * The row says "this Store Syria user is that Lynomia user, with that role, in that workspace".
 * It is the whole of what the integration knows about a person's remote identity: there is no
 * credential here, because there is no credential anywhere -- the only way in is an SSO link
 * minted per click, scoped to this workspace and valid for five minutes.
 *
 * SCOPED BY WORKSPACE, NOT BY STORE. See the migration: a store can be re-pointed at a
 * different Lynomia account, and an identity minted inside the old one is not a thing that can
 * be reused. Looking up by lynomia_account_id makes that automatic rather than remembered.
 *
 * @property int         $lynomia_account_id
 * @property int         $user_id
 * @property int         $lynomia_user_id
 * @property string      $role
 * @property int         $store_id
 */
class LynomiaStoreIdentity extends Model
{
    protected $table = 'lynomia_store_identities';

    /**
     * Full administrative rights in the workspace: channels, members, settings. Reserved for
     * the tenant OWNER -- the person who would be the merchant on a Lynomia account they had
     * registered themselves.
     */
    public const ROLE_ADMINISTRATOR = 'administrator';

    /**
     * Handle conversations and contacts, and nothing else. This is what every staff member
     * gets, and the absence of a path from "staff" to the constant above is the whole of
     * "no default administrator escalation".
     */
    public const ROLE_AGENT = 'agent';

    protected $fillable = [
        'lynomia_account_id',
        'user_id',
        'lynomia_user_id',
        'role',
        'store_id',
        'last_sso_at',
    ];

    protected $casts = [
        'lynomia_account_id' => 'integer',
        'user_id' => 'integer',
        'lynomia_user_id' => 'integer',
        'store_id' => 'integer',
        'last_sso_at' => 'datetime',
    ];

    /** This person's identity in this workspace, which is the only lookup that should exist. */
    public function scopeForWorkspacePerson(Builder $query, int $lynomiaAccountId, int $userId): Builder
    {
        return $query->where('lynomia_account_id', $lynomiaAccountId)->where('user_id', $userId);
    }

    public function scopeForWorkspace(Builder $query, int $lynomiaAccountId): Builder
    {
        return $query->where('lynomia_account_id', $lynomiaAccountId);
    }

    public function isAdministrator(): bool
    {
        return $this->role === self::ROLE_ADMINISTRATOR;
    }
}
