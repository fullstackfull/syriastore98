<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An add-on a given store holds: so many bundles, valid until so long.
 *
 * A TRIAL IS ONE OF THESE WITH A DATE IN `expires_at`. "Two thousand free sends until the end
 * of next month" and "a bundle of two thousand sends" differ only in what was paid, and a
 * second table for trials would be a second expiry rule to get right.
 *
 * A LEDGER, NOT A BALANCE. Each grant is its own row with its own validity and its own note,
 * so "why does this merchant have 12,000 sends" is answerable. A column on the store would
 * hold the number and lose the reason.
 */
class LynomiaStoreAddon extends Model
{
    protected $table = 'lynomia_store_addons';

    protected $fillable = ['store_id', 'addon_id', 'quantity', 'expires_at', 'granted_by', 'note'];

    protected $casts = [
        'store_id' => 'integer',
        'addon_id' => 'integer',
        'quantity' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function addon(): BelongsTo
    {
        return $this->belongsTo(LynomiaMarketingAddon::class, 'addon_id');
    }

    /**
     * Fails closed on an unresolvable tenant, like every other store scope in this module: no
     * rows rather than every store's.
     */
    public function scopeForStore(Builder $query, $storeId): Builder
    {
        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('store_id', (int) $storeId);
    }

    /** Grants that still count. A null expiry never expires; a past one stops counting. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function sends(): int
    {
        return (int) $this->quantity * (int) ($this->addon?->campaign_sends ?? 0);
    }
}
