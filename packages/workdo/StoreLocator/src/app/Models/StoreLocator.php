<?php

namespace Workdo\StoreLocator\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StoreLocator extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'store_locator_enabled',
        'store_locator_api_key',
        'store_address',
        'store_longitude',
        'store_latitude',
        'pincode',
        'created_by',
        'store_id',

    ];

    public function Storedata()
    {
        return $this->hasOne(Store::class, 'id', 'store_id');
    }
}
