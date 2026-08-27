<?php

namespace Workdo\Donation\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerDonation extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'customer_id',
        'donation_id',
        'amount',
        'name',
        'email',
        'number',
        'address',
        'country_id',
        'state_id',
        'city_id',
        'payment_type',
        'store_id',
    ];
}
