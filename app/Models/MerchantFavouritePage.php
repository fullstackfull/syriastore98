<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P2-06-7 — one employee's favourite navigation page, in one store.
 *
 * NOT `Cachable`, on purpose. `User`, `Setting` and `ApikeySetiings` carry the trait and it has
 * produced two measured false readings in this repository (a fixture that looked unapplied, and a
 * security test that passed against unfixed code) because a raw-query write leaves Eloquent reads
 * serving the pre-write row. These rows are written by a toggle and read on the next render, which
 * is exactly the pattern that cache breaks. The read is a single indexed lookup on
 * (`user_id`, `store_id`, `position`); there is nothing here worth the risk.
 *
 * NOT in `config/tenancy.php`'s `scoped_models` either -- see the migration for why. Every query
 * carries its own `where('store_id', ...)`.
 */
class MerchantFavouritePage extends Model
{
    protected $table = 'merchant_favourite_pages';

    protected $fillable = ['user_id', 'store_id', 'page', 'position'];

    protected $casts = [
        'user_id'  => 'integer',
        'store_id' => 'integer',
        'position' => 'integer',
    ];
}
