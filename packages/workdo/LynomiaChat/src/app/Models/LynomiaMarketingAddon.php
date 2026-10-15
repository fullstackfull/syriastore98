<?php

namespace Workdo\LynomiaChat\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An add-on an operator offers: a bundle of extra marketing sends.
 *
 * A CATALOGUE, NOT A PRICE LIST THAT CHARGES. `price_cents` is recorded so an operator can
 * see what a bundle costs; nothing here takes a payment. The payment rail is Store Syria's
 * own plan-order machinery, and every gateway in this installation is switched off.
 */
class LynomiaMarketingAddon extends Model
{
    protected $table = 'lynomia_marketing_addons';

    protected $fillable = ['name', 'campaign_sends', 'price_cents', 'currency', 'is_active'];

    protected $casts = [
        'campaign_sends' => 'integer',
        'price_cents' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
