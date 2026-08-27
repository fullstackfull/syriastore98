<?php

namespace Workdo\AdditionalFieldInCheckout\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CheckoutAdditionalField extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'order_id',
        'field_name',
        'value',
        'status',
        'store_id',
    ];
}
