<?php

namespace Workdo\AuctionProduct\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\AuctionProduct\app\Models\AuctionProductSetting;

class AuctionProductBid extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'product_id',
        'bid_amount',
        'customer_id',
        
        'store_id',
    ];

    public static function productAuctionTime($theme, $slug, $productId)
    {
        $storeId = getCurrenctStoreId($slug);
        date_default_timezone_set('Asia/Kolkata');
        $currentDateTime = \Carbon\Carbon::now();
        $auction_product = AuctionProductSetting::where('store_id', $storeId)
            ->where('is_active', 1)
            ->get();
        $auction_setting = [];
        foreach ($auction_product as $product) {
            $saleEnableArray = explode(',', $product->auction_product);
            $saleEnableArray = (array) $saleEnableArray;
            $startDate = \Carbon\Carbon::parse($product->start_date . ' ' . $product->start_time);
            $endDate = \Carbon\Carbon::parse($product->end_date . ' ' . $product->end_time);
            if ($endDate < $startDate) {
                $endDate->addDay();
            }
            $currentDateTime->setTimezone($startDate->getTimezone());
            if ($currentDateTime >= $startDate && $currentDateTime <= $endDate) {
                if (in_array(trim($productId), $saleEnableArray, true)) {
                    $auction_setting[$productId] = [
                        'start_date' => $product->start_date,
                        'end_date' => $product->end_date,
                        'start_time' => $product->start_time,
                        'end_time' => $product->end_time,
                    ];
                }
            }
        }

       return $auction_setting;
    }
}
