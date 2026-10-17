<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One notification that something changed in a store's workspace.
 *
 * It carries identifiers and nothing else -- see the migration for why that is a security
 * property rather than a storage decision.
 *
 * @property int         $store_id
 * @property int         $lynomia_account_id
 * @property string      $event
 * @property int|null    $conversation_display_id
 * @property string      $delivery_id
 */
class LynomiaInboxEvent extends Model
{
    protected $table = 'lynomia_inbox_events';

    protected $fillable = [
        'store_id', 'lynomia_account_id', 'event', 'conversation_display_id',
        'occurred_at', 'delivery_id',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'lynomia_account_id' => 'integer',
        'conversation_display_id' => 'integer',
        'occurred_at' => 'datetime',
    ];

    /**
     * What this store has not seen yet.
     *
     * SCOPED BY STORE IN THE QUERY, not by anything the caller sends. The store comes from the
     * session through EnsureStoreOwnsLink, so there is no cursor value that can reach another
     * store's rows -- `after` only moves forward within a set that is already narrowed.
     */
    public function scopeForStoreAfter(Builder $query, int $storeId, int $after): Builder
    {
        return $query->where('store_id', $storeId)->where('id', '>', $after)->orderBy('id');
    }
}
