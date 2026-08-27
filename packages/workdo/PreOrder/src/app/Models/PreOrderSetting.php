<?php

namespace Workdo\PreOrder\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PreOrderSetting extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'product_id',
        'available_date',
        'qty',
        'status',
        'created_by',
        
        'store_id',
    ];

    public function productData()
    {
        return $this->hasOne(Product::class, 'id', 'product_id');
    }

    public static function productStockAvailable($slug, $productId)
    {
        $storeId = getCurrenctStoreId($slug);
        date_default_timezone_set('Asia/Kolkata');
        $currentDateTime = \Carbon\Carbon::now();
        $pre_order_setting = PreOrderSetting::where('product_id', $productId)->where('status', 1)->where('store_id', $storeId)->first();

        $preOrderCountdown = [];
        if($pre_order_setting){
            $startDate = \Carbon\Carbon::parse(\Carbon\Carbon::now()->toDateString() . ' ' . '00:00:00');
            $endDate = \Carbon\Carbon::parse($pre_order_setting->available_date . ' ' . '23:59:59');

            $currentDateTime->setTimezone($startDate->getTimezone());

            if ($currentDateTime >= $startDate && $currentDateTime <= $endDate) {
                $preOrderCountdown[$productId] = [
                    'qty' => $pre_order_setting->qty . __(' in pre order stock'),
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'start_time' => '00:00:00',
                    'end_time' => '23:59:59',
                    'formated_end_date' => $endDate->format('F j, Y'),//l, ... , g:i A
                ];
            }
        }

       return $preOrderCountdown;
    }
}
