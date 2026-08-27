<?php

namespace Workdo\LotteryPrizes\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PurchaseTicket extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'lottery_id',
        'lottery_code',
        'customer_id',
        'firstname',
        'lastname',
        'email',
        'number',
        'address',
        'country_id',
        'state_id',
        'city_id',
        'postcode',
        'product_id',
        'amount',
        'payment_type',
        'store_id',
    ];

    public function lotterySetting()
    {
        return $this->belongsTo(LotterySetting::class, 'lottery_id');
    }

    public function getCountryNameAttribute()
    {
        return Country::where('id', $this->country_id)->first();
    }

    public function getStateNameAttribute()
    {
        return State::where('id', $this->state_id)->first();
    }
    public function getCityNameAttribute()
    {
        return City::where('id', $this->city_id)->first();
    }
}
