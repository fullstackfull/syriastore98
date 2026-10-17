<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One commerce event waiting to reach Lynomia, or the record that it did.
 *
 * TENANT SCOPING IS EXPLICIT, as it is on LynomiaStoreLink and for the same reason: these rows
 * are written by a model observer and read by a queue worker and a scheduled sweep, none of
 * which resolve a session, so StoreScope would contribute no predicate at all. Every read names
 * its store or is a deliberate cross-store sweep.
 *
 * @property int         $store_id
 * @property string      $event
 * @property int|null    $order_id
 * @property string|null $customer_reference
 * @property int         $attempts
 * @property string      $delivery_id
 */
class LynomiaCommerceEvent extends Model
{
    protected $table = 'lynomia_commerce_events';

    /** The events the observer records. Anything else is a bug, not a value to pass through. */
    public const EVENTS = ['order.created', 'order.status_changed', 'order.payment_changed'];

    protected $fillable = [
        'store_id',
        'event',
        'order_id',
        'customer_reference',
        'delivered_status',
        'payment_status',
        'occurred_at',
        'delivery_id',
        'attempts',
        'deliver_after',
        'delivered_at',
        'last_error',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'deliver_after' => 'datetime',
        'delivered_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function scopeForStore(Builder $query, $storeId): Builder
    {
        // Fails closed, like BelongsToStore::scopeForStore: an unresolvable tenant yields no
        // rows rather than every store's.
        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('store_id', (int) $storeId);
    }

    /**
     * Rows that still owe a delivery and whose backoff has elapsed. A brand-new row has a null
     * `deliver_after` and is therefore eligible at once.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('delivered_at')
            ->where(fn (Builder $inner) => $inner->whereNull('deliver_after')->orWhere('deliver_after', '<=', now()));
    }
}
