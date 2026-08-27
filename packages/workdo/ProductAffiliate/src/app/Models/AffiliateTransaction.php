<?php

namespace Workdo\ProductAffiliate\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AffiliateTransaction extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'company_id',
        'order_id',
        'product_order_id',
        'final_price',
        'commission',
        'affiliate_code',
    ];

    public function getOrder()
    {
        return $this->hasOne('App\Models\Order', 'id', 'order_id');
    }

    public function getUser()
    {
        return $this->hasOne('App\Models\Customer', 'id', 'company_id');
    }

    public function getCompany()
    {
        return $this->hasOne('App\Models\Customer', 'affiliate_code', 'affiliate_code');
    }
}
