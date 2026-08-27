<?php

namespace Workdo\WholesaleProduct\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\WholesaleProduct\Database\factories\WholesaleProductFactory;
use App\Models\Product;
use App\Models\Tax;
use App\Models\ProductVariant;

class WholesaleProduct extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['store_id', 'product_id', 'min_qty', 'max_qty', 'per_product_price'];

    protected static function newFactory(): WholesaleProductFactory
    {
        //return WholesaleProductFactory::new();
    }

    public static function saveData($product ,$data_info)
    {
        if ($product || $data_info) {
            if(isset($data_info))
            {
                foreach ($data_info as $item) {
                    if (
                        isset($item['min_qty']) &&
                        isset($item['max_qty']) &&
                        isset($item['per_product_price'])
                    ) {
                        $wholesaleProduct = new WholesaleProduct();
                        $wholesaleProduct->product_id = $product->id;
                        $wholesaleProduct->min_qty = $item['min_qty'];
                        $wholesaleProduct->max_qty = $item['max_qty'];
                        $wholesaleProduct->per_product_price = $item['per_product_price'];
                        $wholesaleProduct->store_id = getCurrentStore();
                        $wholesaleProduct->save();
                    }
                }
            }
        }
    }

    public static function saveUpdateData($product, $data_info)
    {
        if ($product || $data_info) {
            $existingWholesaleProducts = WholesaleProduct::where('product_id', $product->id)->get()->keyBy(function ($item) {
                return $item->min_qty . '-' . $item->max_qty . '-' . $item->per_product_price;
            });

            if(isset($data_info))
            {
                foreach ($data_info as $item) {
                    $key = $item['min_qty'] . '-' . $item['max_qty'] . '-' . $item['per_product_price'];
                    $existingProduct = $existingWholesaleProducts->get($key);

                    if ($existingProduct) {
                        $existingProduct->update([
                            'store_id' => getCurrentStore(),
                        ]);
                        $existingWholesaleProducts->forget($key);
                    } else {
                        if (
                            isset($item['min_qty']) &&
                            isset($item['max_qty']) &&
                            isset($item['per_product_price'])
                        ) {
                            $wholesaleProduct = new WholesaleProduct();
                            $wholesaleProduct->product_id = $product->id;
                            $wholesaleProduct->min_qty = $item['min_qty'];
                            $wholesaleProduct->max_qty = $item['max_qty'];
                            $wholesaleProduct->per_product_price = $item['per_product_price'];
                            $wholesaleProduct->store_id = getCurrentStore();
                            $wholesaleProduct->save();
                        }
                    }
                }
            }
            foreach ($existingWholesaleProducts as $existingProduct) {
                $existingProduct->delete();
            }
        }
    }

    public static function Wholesale_Product_price($product ,$data,$store)
    {
        $product_id = $data['product_id'];
        $varint = $data['varint'] ?? '';
        $qty = $data['qty'] ?? 1;
        $product = Product::find($product_id);
        $wholesale_prices = WholesaleProduct::where('product_id',$product->id)->get();
        $within_wholesale_range = false;
        $product_price = 0;
        if ($wholesale_prices->isNotEmpty()) {
            foreach ($wholesale_prices as $wholesale_price) {
                if ($qty >= $wholesale_price->min_qty && $qty <= $wholesale_price->max_qty) {
                    $within_wholesale_range = true;
                    $product_price = $wholesale_price->per_product_price;
                    break;
                }else{
                    if ($product->variant_product == 0) {
                        $product_price = $product->sale_price;
                    }else{
                        if (is_array($varint)) {
                            $variant_name = implode('-', $varint);
                        } else {
                            $variant_name = $varint;
                        }
                        $product->setAttribute('variantName', $variant_name);
                        $ProductStock = ProductVariant::where('product_id', $product_id)
                            ->where('variant', $variant_name)
                            ->first();
                        $product_price = $ProductStock->price ?? 0;
                    }

                }
            }
        }else{
            if ($product->variant_product == 0) {
                $product_price = $product->sale_price;
            } else {
                if (is_array($varint)) {
                    $variant_name = implode('-', $varint);
                } else {
                    $variant_name = $varint;
                }
                $product->setAttribute('variantName', $variant_name);
                $ProductStock = ProductVariant::where('product_id', $product_id)
                    ->where('variant', $variant_name)
                    ->first();
                $product_price = $ProductStock->price ?? 0;
            }
        }

        $return = [];
        if (!empty($product)) {
            if ($product->variant_product == 0) {
                // no varint
                if (isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                    $return['status'] = 'error';
                    $return['message'] = __('Product has been reached max quantity.');
                } else {
                    $product_original_price = $product_price ;
                    $product_final_price = $product_price;

                    $data['store_id'] = $store->id;
                    $data['product_tax_id'] = $product->tax_id;
                    $data['sub_total'] = $product_final_price * $qty;
                    $data['product_original_price'] = $product_original_price * $qty;
                    $cart_array  = Tax::TaxCount($data);
                    $return['currency'] = $cart_array['currency'];
                    $return['sub_total'] = $cart_array['original_price'];
                    $return['product_original_price'] = $cart_array['original_price'];
                    $return['variant_id'] = 0;
                    $return['original_price'] = $cart_array['original_price'];
                    $return['final_price'] = $cart_array['final_price'];
                    $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                    $return['currency_name'] = $cart_array['currency_name'];
                    $return['total_tax_price'] = $cart_array['total_tax_price'];

                    return $return;
                }
            } elseif ($product->variant_product == 1) {
                // has varint
                if (is_array($varint)) {
                    $variant_name = implode('-', $varint);
                } else {
                    $variant_name = $varint;
                }

                $product->setAttribute('variantName', $variant_name);
                $ProductStock = ProductVariant::where('product_id', $product_id)
                    ->where('variant', $variant_name)
                    ->first();
                if ($ProductStock)
                {
                    $stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                    $variationOptions = explode(',', $ProductStock->variation_option);
                    $option = in_array('manage_stock', $variationOptions);

                    if ($option == true) {
                        $stock_status = $ProductStock->stock_order_status;
                    } else {
                        $stock_status = $product->stock_order_status;
                    }

                    if ($stock < $qty && $stock_status == 'not_allow') {
                        $return['status'] = 'error';
                        $return['variant_id'] = $ProductStock->id;
                        $return['message'] = __('Product has been reached max quantity.');
                    } else {
                        $sale_price = !empty($ProductStock->price) ? $ProductStock->price : $ProductStock->variation_price;

                        $variation_price = !empty($ProductStock->variation_price) ? $ProductStock->variation_price : $ProductStock->price;

                        $var_price = !empty($sale_price) ? $sale_price : 0;

                        $product_original_price = $product_price;
                        $product_final_price = $product_price;

                        // $product_original_price = $variation_price * $qty;
                        // $product_final_price = $var_price * $qty;
                        if ($option == true) {
                            $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : 0;
                        }else{
                            $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                        }
                        
                        $data['store_id'] = $store->id;
                        $data['product_tax_id'] = $product->tax_id;
                        $data['sub_total'] = $product_final_price * $qty;
                        $data['product_original_price'] = $product_original_price * $qty;
                        $cart_array  = Tax::TaxCount($data);
                        $return['sub_total'] = $product_final_price;
                        $return['product_original_price'] = $product_original_price;
                        $return['variant_id'] = $ProductStock->id;


                        $return['currency_name'] = $cart_array['currency_name'];
                        $return['currency'] = $cart_array['currency'];
                        if ($wholesale_prices->isEmpty())
                        {
                            $return['total_tax_price'] = 0;
                            $return['final_price'] = $product_final_price* $qty;
                            $return['original_price'] = $product_original_price* $qty;
                            $return['final_price_text'] = currency_format_with_sym($product_final_price* $qty,  $store->id);
                        }else{
                            $return['total_tax_price'] = $cart_array['total_tax_price'];
                            $return['final_price'] = $cart_array['final_price'];
                            $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                            $return['original_price'] = $cart_array['original_price'];
                        }
                        $return['enable_option_data'] = !empty($option) ? $option : '';
                        $return['stock'] = !empty($variat_stock) ? $variat_stock : 0;
                        $return['stock_status'] = !empty($ProductStock->stock_status) ? $ProductStock->stock_status : '';
                        $return['description'] = !empty($ProductStock->description) ? $ProductStock->description : $product->descripion;
                        $return['variant_name'] = !empty($variant_name) ? $variant_name : '';
                        return $return;
                    }
                }

            } else {
            }
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Whoops! Something went wrong.');
        }
    }

}
