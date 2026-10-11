<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    protected $table = 'currency_rates';

  protected $fillable = [
    'store_id',
    'currency_code',
    'currency_name',
    'symbol',
    'rate_per_usd',
    'is_active',
    'created_by',
];

    protected $casts = [
        'rate_per_usd' => 'decimal:8',
        'is_active' => 'boolean',
    ];
}