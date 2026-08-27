<?php

namespace workdo\FrequentlyProduct\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use workdo\FrequentlyProduct\Database\factories\FrequentlyProductFactory;
use \App\Models\Utility;
use \App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cookie;

class FrequentlyProduct extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    protected static function newFactory(): FrequentlyProductFactory
    {
        //return FrequentlyProductFactory::new();
    }

    public static function addtocart_cookie($product_id = 0, $variant_id = 0, $qty = 0, $store_id)
    {
        $settings = Utility::Setting($theme_id, $store_id);
        $final_price = 0;
        $cart_count = 0;
        $product = Product::find($product_id);
        if (!empty($variant_id) || $variant_id != 0) {
            $ProductVariant = ProductVariant::where('id', $variant_id)
                ->where('product_id', $product_id)
                ->first();
            $product->setAttribute('variantId', $variant_id);
            $variationOptions = explode(',', $ProductVariant->variation_option);
            $option = in_array('manage_stock', $variationOptions);
            if ($option  == true) {
                $stock = !empty($ProductVariant->stock) ? $ProductVariant->stock : 0;
                if (empty($ProductVariant)) {
                    return Utility::error(['message' => 'Product not found.']);
                } else {
                    if ($stock <= $settings['out_of_stock_threshold'] && $ProductVariant->stock_order_status == 'not_allow') {
                        return Utility::error(['message' => 'Product has out of stock.']);
                    }
                }
            } else {
                $stock = !empty($ProductVariant->stock) ? $ProductVariant->stock : $product->product_stock;
                if (empty($ProductVariant)) {
                    return Utility::error(['message' => 'Product not found.']);
                } else {
                    if ($stock <= $settings['out_of_stock_threshold'] && $product->stock_order_status == 'not_allow') {
                        return Utility::error(['message' => 'Product has out of stock.']);
                    }
                }
            }

            $final_price = $ProductVariant->final_price * $qty;
        }
        $qty = $qty;
        $key_name = $product_id . '_' . $variant_id;
        $cart[$key_name]['product_id'] = $product_id;
        $cart[$key_name]['variant_id'] = $variant_id;
        $cart[$key_name]['qty'] = $qty;
        $cart[$key_name]['created_at'] = now();
        $cart[$key_name]['store_id'] = $store_id;


        $cart_Cookie = Cookie::get('cart');
        if (!empty($cart_Cookie)) {
            $cart_array = json_decode($cart_Cookie, true);
            if (!empty($cart_array[$key_name])) {
                $cart_count = count($cart_array);
                return Utility::error(['message' => $product->name . ' already in cart.', 'count' => $cart_count]);
            } else {
                $cart_array = array_merge($cart_array, $cart);
                $cart_count = count($cart_array);
                $cart_json = json_encode($cart_array);
                Cookie::queue('cart', $cart_json, 1440);
            }
        } else {
            $cart_json = json_encode($cart);
            Cookie::queue('cart', $cart_json, 1440);
            $cart_count = 1;
        }

        if (!empty($cart_count)) {
            return Utility::success(['message' => $product->name . ' add successfully.', 'count' => $cart_count]);
        } else {
            return Utility::error(['message' => 'Cart is empty.', 'count' => $cart_count]);
        }
    }

}
