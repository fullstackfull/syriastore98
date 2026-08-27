<?php

namespace Workdo\RewardClubPoint\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\RewardClubPoint\Database\factories\RewardClubPointFactory;

class RewardClubPoint extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'customer_id',
        'order_id',
        'product_id',
        'points',
        'used_points',
        'reward_price',
        'store_id',
    ];
    
    protected static function newFactory(): RewardClubPointFactory
    {
        //return RewardClubPointFactory::new();
    }

    public function orderData()
    {
        return $this->hasOne('App\Models\Order', 'id', 'order_id');
    }
}
