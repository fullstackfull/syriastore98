<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderBillingDetail extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'order_id',
        'product_order_id',
        'first_name',
        'last_name',
        'email',
        'telephone',
        'company_name',
        'address',
        'postcode',
        'country',
        'state',
        'city',
        'delivery_address',
        'delivery_city',
        'delivery_postcode',
        'delivery_country',
        'delivery_state',
    ];

    public function BillingCountry()
    {
        return $this->hasOne(Country::class, 'id', 'country');
    }

    public function BillingState()
    {
        return $this->hasOne(State::class, 'id', 'state');
    }

    public function BillingCity()
    {
        return $this->hasOne(City::class, 'id', 'city');
    }

    public function DeliveryCountry()
    {
        return $this->hasOne(Country::class, 'id', 'delivery_country');
    }

    public function DeliveryState()
    {
        return $this->hasOne(State::class, 'id', 'delivery_state');
    }

    public function DeliveryCity()
    {
        return $this->hasOne(City::class, 'id', 'delivery_city');
    }
}
