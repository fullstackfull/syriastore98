<?php

namespace workdo\CartQuantityControl\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartQuantityControl extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    public static function saveData($product, $min_qty, $max_qty)
    {
        //without Variant wise
        if ($product && $min_qty && $max_qty) {

            $product_stock = isset($product->product_stock) ? $product->product_stock : '';
            $track_stock = isset($product->track_stock) ? $product->track_stock : '0';

            if ($track_stock == 1) {
                if (isset($min_qty) && isset($max_qty)) {
                    try {
                        if ($min_qty > 0 && $min_qty <= $product_stock && $max_qty > 0 && $max_qty <= $product_stock && $min_qty <= $max_qty) {
                            $product->maximum_quantity = $max_qty;
                            $product->minimum_quantity = isset($min_qty) ? $min_qty : 1;
                        }
                    } catch (Exception $e) {

                        return redirect()->back()->with('error', $e->getMessage());
                    }
                }
            }
            if ($track_stock == 0) {
                if (isset($min_qty) && isset($max_qty)) {
                    try {
                        if ($min_qty <= $max_qty) {
                            $product->minimum_quantity = isset($min_qty) ? $min_qty : 1;
                            $product->maximum_quantity = $max_qty;
                        }
                    } catch (Exception $e) {

                        return redirect()->back()->with('error', $e->getMessage());
                    }

                }
            }
        }
    }

    public static function variantSaveData($request, $product_stock, $str)
    {

        if ($request && $product_stock && $str) {

            $variant_product = isset($request->variant_product) ? $request->variant_product : '';
            $variation_option = isset($product_stock->variation_option) ? $product_stock->variation_option : '';
            $max_qty = ! empty($request['maximum_quantity_'.str_replace('.', '_', $str)]) ? $request['maximum_quantity_'.str_replace('.', '_', $str)] : 1;
            $min_qty = ! empty($request['minimum_quantity_'.str_replace('.', '_', $str)]) ? $request['minimum_quantity_'.str_replace('.', '_', $str)] : 1;
            $variant_product_stock = ! empty($request['product_stock_'.str_replace('.', '_', $str)]) ? $request['product_stock_'.str_replace('.', '_', $str)] : '';

            if ($variant_product == '1') {

                if (strpos($variation_option, 'manage_stock') !== false) {
                    if (isset($min_qty) && isset($max_qty)) {

                        if ($min_qty > 0 && $min_qty <= $variant_product_stock && $max_qty > 0 && $max_qty <= $variant_product_stock && $min_qty <= $max_qty) {
                            $product_stock->minimum_quantity = isset($min_qty) ? $min_qty : 1;
                            $product_stock->maximum_quantity = $max_qty;
                        }
                    }
                } else {

                    if (strpos($variation_option, 'manage_stock') == false) {
                        if (isset($min_qty) && isset($max_qty)) {
                            if ($min_qty <= $max_qty) {
                                $product_stock->minimum_quantity = isset($min_qty) ? $min_qty : 1;
                                $product_stock->maximum_quantity = $max_qty;
                            }
                        }
                    }
                }
            }
        }
    }

    public static function checkoutValidation($request, $data, $cart, $settings)
    {

        if (! is_object($request) || ! is_object($data) || ! is_object($cart)) {
            $return['status'] = 'error';
            $return['message'] = __('Invalid input data.');

            return response()->json($return);
        }

        //without variant
        if (empty($request->variant_id) || $request->variant_id == 0) {
            $product = isset($data) ? $data : '';
            if ($product->track_stock == 1) {
                if ($product->maximum_quantity <= $product->product_stock) {
                    if ($cart->qty >= $product->maximum_quantity) {
                        $return['status'] = 'error';
                        $return['message'] = __('Can not increase product quantity beacuse The maximum allowed order quantity is '.$product->maximum_quantity.'.');

                        return response()->json($return);
                    }
                }
            } else {
                if ($product->stock_status == 'in_stock' || $product->stock_status == 'on_backorder') {

                    if ($cart->qty >= $product->maximum_quantity) {
                        $return['status'] = 'error';
                        $return['message'] = __('Can not increase product quantity beacuse The maximum allowed order quantity is '.$product->maximum_quantity.'.');

                        return response()->json($return);
                    }

                } elseif ($product->stock_status == 'out_of_stock' && $product->stock_order_status == '') {
                    $return['status'] = 'error';
                    $return['message'] = __('Can not increase product quantity beacuse product is out of stock.');

                    return response()->json($return);
                }
            }
        } elseif (! empty($request->variant_id) || $request->variant_id != 0) {

            $productVariant = isset($data) ? $data : '';
            $variationOptions = explode(',', $productVariant->variation_option);
            $option = in_array('manage_stock', $variationOptions);
            if (! empty($productVariant)) {
                if ($option == true) {
                    if ($productVariant->maximum_quantity <= $productVariant->stock) {
                        if ($cart->qty >= $productVariant->maximum_quantity) {
                            $return['status'] = 'error';
                            $return['message'] = __('Can not increase product quantity beacuse The maximum allowed order quantity is '.$productVariant->maximum_quantity.'.');

                            return response()->json($return);
                        }
                    }

                } elseif ($option == false) {

                    if ($productVariant->stock_status != '' || $productVariant->stock_status != null) {

                        if ($productVariant->stock_status == 'in_stock' || $productVariant->stock_status == 'on_backorder') {
                            if ($cart->qty >= $productVariant->maximum_quantity) {
                                $return['status'] = 'error';
                                $return['message'] = __('Can not increase product quantity beacuse The maximum allowed order quantity is '.$productVariant->maximum_quantity.'.');

                                return response()->json($return);
                            }

                        } elseif ($productVariant->stock_status == 'out_of_stock' && $productVariant->stock_order_status == '') {
                            $return['status'] = 'error';
                            $return['message'] = __('Can not increase product quantity beacuse product is out of stock.');

                            return response()->json($return);
                        }
                    } else {
                        if ($cart->qty >= $productVariant->maximum_quantity) {
                            $return['status'] = 'error';
                            $return['message'] = __('Can not increase product quantity beacuse The maximum allowed order quantity is '.$productVariant->maximum_quantity.'.');

                            return response()->json($return);
                        }
                    }
                } else {
                    $return['status'] = 'error';
                    $return['message'] = __('Can not increase product quantity beacuse product is out of stock.');

                    return response()->json($return);
                }
            }
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Can not increase product quantity beacuse product is out of stock.');

            return response()->json($return);
        }
    }

    public static function minQtyCheckoutValidation($cartlist)
    {
        $Errormessage = ['out_of_stock' => [], 'min_quantity' => []];

        if (!auth('customers')->user()) {
            $new_product_list = [];
            foreach ($cartlist['product_list'] as $item) {
                $new_product_list[] = $item;
            }
            $cartlist['product_list'] = $new_product_list;
        }

        if (isset($cartlist['product_list']) && is_array($cartlist['product_list']) && count($cartlist['product_list']) > 0) {
            $cart_products = $cartlist['product_list'];

            foreach ($cart_products as $cart_product) {
                if (! empty($cart_product->variant_id == '0' && $cart_product->variant_name == '')) {
                    $qty = $cart_product->qty;
                    $product = Product::find($cart_product->product_id);

                    if ($product) {
                        if ($product->stock_status == 'out_of_stock') {
                            $Errormessage['out_of_stock'][] = __('<b>'.$product->name.'</b>  is out of stock.');
                        } elseif ($qty < $product->minimum_quantity) {
                            $Errormessage['min_quantity'][] = $product->name;
                        } elseif ($qty > $product->maximum_quantity) {
                            $Errormessage['max_quantity'][] = $product->name;
                        }
                    } else {
                        $Errormessage['min_quantity'][] = __('Product not found.');
                    }
                } else {
                    $qty = $cart_product->qty;
                    $variantId = ! empty($cart_product->variant_id) ? $cart_product->variant_id : '';
                    $variant = ProductVariant::find($variantId);
                    $product = Product::find($cart_product->product_id);

                    if ($variant) {
                        if ($product->stock_status == 'out_of_stock') {
                            $Errormessage['out_of_stock'][] = __('<b>'.$product->name.'</b>  is out of stock.');
                        } elseif ($variant->stock_status == 'out_of_stock') {
                            $Errormessage['out_of_stock'][] = '<b> Product: '.$product->name.'of Variant: '.$variant->variant.'</b> is out of stock.';
                        } elseif ($qty < $variant->minimum_quantity) {
                            $Errormessage['min_quantity'][] = $product->name;
                        } elseif ($qty > $variant->maximum_quantity) {
                            $Errormessage['max_quantity'][] = $product->name;
                        }
                    } else {
                        $Errormessage['min_quantity'][] = __('Variant not found.');
                    }
                }
            }
        }

        // Clean up empty arrays if there are no errors
        if (empty($Errormessage['out_of_stock'])) {
            unset($Errormessage['out_of_stock']);
        }
        if (empty($Errormessage['min_quantity'])) {
            unset($Errormessage['min_quantity']);
        }

        return empty($Errormessage) ? null : $Errormessage;
    }
}