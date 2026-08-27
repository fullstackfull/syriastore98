<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'zone_name', 'country_id', 'state_id', 'shipping_method',  'store_id'
    ];

    public static function modules()
    {
        $shippingMethod = [
        'Delivery Syria' => 'Delivery Syria',
            'Flat Rate' => 'Flat Rate',
            'Free shipping' => 'Free shipping',
            'Local pickup' => 'Local pickup',
        ];
        return $shippingMethod;
    }

    public function getCountryNameAttribute()
    {
        return Country::where('id', $this->country_id)->first();
    }

    public function getStateNameAttribute()
    {
        return State::where('id', $this->state_id)->first();
    }

    public function getShippingMethod()
    {
        return ShippingMethod::where('id',$this->shipping_method)->first();
    }

    public function shipping_methods()
    {
        return $this->hasMany(ShippingMethod::class, 'zone_id', 'id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function state()
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}