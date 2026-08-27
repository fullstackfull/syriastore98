<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlashSale extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'sale_product',
        'discount_amount',
        'discount_type',
        'is_active',
        'created_by',
        
        'store_id'
    ];

    public static $options =[
        'Shop', //0
        'Product', //1
        'Category', //2
        'Product price', //3
    ];

    public static $condition =[
        'is',   //0
        'is not', //1
    ];

    public static $price_condition =[
        'is equal to',  //0
        'is not equal to',  //1
        'is greater than',  //2
        'is less than',     //3
        'is greater or equal to',   //4
        'is less or equal to',      //5
    ];

    public static function saleDiscount($store, $product, $product_id, $variantId = 0, $price = 0)
    {
        // flash sale
        date_default_timezone_set('Asia/Kolkata');
        $currentDateTime = \Carbon\Carbon::now()->toDateTimeString();
        $sale_product = FlashSale::where('store_id', $store->id)
            ->where('is_active', 1)
            ->get();

        $latestSales = [];
        if(module_is_active('PreOrder')){
            $customer = auth('customers')->user() ?? null;
            $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
            if (isset($customer) && isset($product) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on' && (($product->track_stock == 0 && $product->stock_status == 'out_of_stock') || ($product->variant_product == 0 && $product->product_stock <= 0) || ($product->variant_product == 1))) {
            }else{
                foreach ($sale_product as $flashsale) {
                    $saleEnableArray = json_decode($flashsale->sale_product, true);
                    $startDate = \Carbon\Carbon::parse($flashsale['start_date'] . ' ' . $flashsale['start_time']);
                    $endDate = \Carbon\Carbon::parse($flashsale['end_date'] . ' ' . $flashsale['end_time']);
        
                    if ($endDate < $startDate) {
                        $endDate->addDay();
                    }
                    if ($currentDateTime >= $startDate && $currentDateTime <= $endDate) {
                        if (is_array($saleEnableArray) && in_array($product_id, $saleEnableArray)) {
                            $latestSales[$product_id] = [
                                'discount_type' => $flashsale->discount_type,
                                'discount_amount' => $flashsale->discount_amount,
                            ];
                        }
                    }
                }

            }
        }else{
            foreach ($sale_product as $flashsale) {
                $saleEnableArray = json_decode($flashsale->sale_product, true);
                $startDate = \Carbon\Carbon::parse($flashsale['start_date'] . ' ' . $flashsale['start_time']);
                $endDate = \Carbon\Carbon::parse($flashsale['end_date'] . ' ' . $flashsale['end_time']);
    
                if ($endDate < $startDate) {
                    $endDate->addDay();
                }
                if ($currentDateTime >= $startDate && $currentDateTime <= $endDate) {
                    if (is_array($saleEnableArray) && in_array($product_id, $saleEnableArray)) {
                        $latestSales[$product_id] = [
                            'discount_type' => $flashsale->discount_type,
                            'discount_amount' => $flashsale->discount_amount,
                        ];
                    }
                }
            }
        }
        if ($latestSales == null) {
            $latestSales[$product_id] = [
                'discount_type' => null,
                'discount_amount' => 0,
            ];
        }
        foreach ($latestSales as $productId => $saleData) {

            if ($product->variant_product == 0) {
                $product_price = $product->sale_price ?? $product->final_price ?? 0;
                if ($saleData['discount_type'] == 'flat') {
                    $price = $product_price - $saleData['discount_amount'];
                }
                if ($saleData['discount_type'] == 'percentage') {
                    $discount_price =  $product_price * $saleData['discount_amount'] / 100;
                    $price = $product_price - $discount_price;
                }
            } else {
                $product_variant_data = ProductVariant::where('product_id', $product->id)->where('id', $variantId)->first();
                
                if ($product_variant_data) {
                    $product_variant_price = $product_variant_data->price ?? $product_variant_data->variation_price ?? 0;
                    if ($saleData['discount_type'] == 'flat') {
                        $price = $product_variant_price - $saleData['discount_amount'];
                    } elseif ($saleData['discount_type'] == 'percentage') {
                        $discount_price = $product_variant_price * $saleData['discount_amount'] / 100;
                        $price = $product_variant_price - $discount_price;
                    }else{
                        $price = $product_variant_price;
                    }
                }
            }
        }
        return $price = max($price, 0);
    }

}
