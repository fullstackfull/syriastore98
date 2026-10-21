<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cookie;
use Session;
use App\Http\Controllers\CartController;
use Illuminate\Support\Facades\Cache;
use App\Traits\ApiResponser;
use App\Models\Concerns\BelongsToStore;

class Cart extends Model
{
    use HasFactory, Cachable, BelongsToStore;
    use ApiResponser;
    protected $fillable = [
        'customer_id', 'product_id', 'variant_id', 'qty', 'price', 'cookie_session_id','store_id'
    ];

    /**
     * The rows of ONE guest basket, named by the `cart` cookie (a session id). P1-SEC-24.
     *
     * A LOGGED-IN customer's basket rows carry `cookie_session_id` NULL, and Laravel turns
     * `where('cookie_session_id', null)` into `whereNull` -- so every query that read the cookie with
     * `Cookie::get('cart')` and used it unchecked selected EVERY logged-in customer's rows the moment the
     * cookie was absent, which for a logged-in customer is the normal case. Deleted, reassigned or altered
     * depending on the caller: an order's cart clear, the login-time merge, a guest's cart-clear / remove /
     * quantity change (see NullCookieCartTest). A missing, empty or non-string cookie names NO basket, so
     * it matches nothing.
     *
     * The only place in app/ that may name `cookie_session_id` in a where() -- a census test pins that.
     */
    public function scopeForGuestCookie($query, $cookie)
    {
        if (! is_string($cookie) || $cookie === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('cookie_session_id', $cookie);
    }

    /**
     * The rows of ONE logged-in customer's basket. P1-SEC-24 / P1-SEC-23.
     *
     * `customer_id` is `integer default 0`, and a guest's rows carry 0. Two inputs reach a `where('customer_id', $x)`
     * that a basket-clearing path must never honour: the empty string a guest checkout pins (MySQL in production's
     * non-strict mode reads `'' = 0` as true, so every guest's row for the product matched and was deleted), and a
     * positive id that was merely TYPED (a guest's billing e-mail resolves to a registered Customer, and keying the
     * clear on that id deleted that customer's basket). Pass the AUTHENTICATED principal's id; anything that is not
     * a positive integer names no basket and matches nothing.
     */
    public function scopeForCustomer($query, $customerId)
    {
        $positive = (is_int($customerId) && $customerId > 0)
            || (is_string($customerId) && ctype_digit($customerId) && (int) $customerId > 0);

        if (! $positive) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('customer_id', (int) $customerId);
    }

    public function product_data()
    {
        return $this->hasOne(Product::class, 'id', 'product_id');
    }

    public function variant_data()
    {
        return $this->hasOne(ProductVariant::class, 'id', 'variant_id');
    }

    public static function addtocart_cookie($product_id = 0, $variant_id = 0, $qty = 0, $store_id,$chart_data = '')
    {      
        $settings = Utility::Setting($store_id);
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
                    return Utility::error(['message' => __('Product not found.')]);
                } else {
                    if (isset($settings['out_of_stock_threshold']) && ($stock <= $settings['out_of_stock_threshold']) && $ProductVariant->stock_order_status == 'not_allow') {
                        return Utility::error(['message' => __('Product has out of stock.')]);
                    }
                }
            } else {
                $stock = !empty($ProductVariant->stock) ? $ProductVariant->stock : $product->product_stock;
                if (empty($ProductVariant)) {
                    return Utility::error(['message' => __('Product not found.')]);
                } else {
                    if (isset($settings['out_of_stock_threshold']) && ($stock <= $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                        return Utility::error(['message' => __('Product has out of stock.')]);
                    }
                }
            }

            $final_price = $ProductVariant->final_price * $qty;
        } else {
            if (!empty($product)) {
                if (module_is_active('SizeGuideline')) {
                    if ($product->variant_product == 1) {
                        $product_stock_datas = ProductVariant::find($variant_id);
                        $product->setAttribute('variantId', $variant_id);
                        $var_stock = !empty($product_stock_datas->stock) ? $product_stock_datas->stock : $product->product_stock;
                        $product_stock_data = ProductVariant::find($variant_id);
                        if(!$chart_data)
                        {
                            if (empty($variant_id) || $variant_id == 0) {
                                return Utility::error(['message' => __('Please Select a variant in a product.')]);
                            } else if (isset($settings['out_of_stock_threshold']) && ($var_stock <= $settings['out_of_stock_threshold']) && $product_stock_datas->stock_order_status == 'not_allow') {
                                return Utility::error(['message' => __('Please Select a diffrent variant in a product.')]);
                            } else {
                                if($product_stock_data)
                                {
                                    if ($product_stock_data->stock_status == 'out_of_stock') {
                                        return Utility::error(['message' => __('Product has out of stock.')]);
                                    }
                                }
                            }
                        }
                    } else {
                        if ((isset($settings['out_of_stock_threshold']) && ($product->product_stock <= $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) {
                            return Utility::error(['message' => __('Product has out of stock.')]);
                        }
                    }
                    if($chart_data)
                    {
                        $store = Store::find($store_id);
                        $request_value =[
                            'qty' => $qty,
                            'varint' => $variant_id ?? '',
                            'product_id' => $product->id,
                            'size_data' => $chart_data ?? '',
                        ];
                        $size_price = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($product, $request_value,$store);
                        $final_price = floatval($size_price['final_price'] ?? $product->final_price) * floatval($qty);
                    }else{
                        $final_price = floatval($product->final_price) * floatval($qty);
                    }
                }else{
                    if ($product->variant_product == 1) {
                        $product_stock_datas = ProductVariant::find($variant_id);
                        $product->setAttribute('variantId', $variant_id);
                        $var_stock = !empty($product_stock_datas->stock) ? $product_stock_datas->stock : $product->product_stock;
                        if (empty($variant_id) || $variant_id == 0) {
                            return Utility::error(['message' => __('Please Select a variant in a product.')]);
                        } else if (isset($settings['out_of_stock_threshold']) && ($var_stock <= $settings['out_of_stock_threshold']) && $product_stock_datas->stock_order_status == 'not_allow') {
                            return Utility::error(['message' => __('Please Select a diffrent variant in a product.')]);
                        } else {
                            $product_stock_data = ProductVariant::find($variant_id);
                            if ($product_stock_data->stock_status == 'out_of_stock') {
                                return Utility::error(['message' => __('Product has out of stock.')]);
                            }
                        }
                    } else {
                        if ((isset($settings['out_of_stock_threshold']) && ($product->product_stock <= $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) {
                            return Utility::error(['message' => __('Product has out of stock.')]);
                        }
                    }
                    $final_price = floatval($product->final_price) * floatval($qty);
                }
            } else {
                return Utility::error(['message' => __('Product not found.')]);
            }
        }

        $qty = $qty;
        if (module_is_active('SizeGuideline')) {
            if($chart_data)
            {
                $key_name = $product_id . '_' . $variant_id . $chart_data ;
                $cart[$key_name]['size_data'] = $chart_data ?? '';
            }else{
                $key_name = $product_id . '_' . $variant_id;
            }
        }else{
            $key_name = $product_id . '_' . $variant_id;
        }
        $cart[$key_name]['product_id'] = $product_id;
        $cart[$key_name]['variant_id'] = $variant_id;
        $cart[$key_name]['qty'] = $qty;
        $cart[$key_name]['created_at'] = now();
        $cart[$key_name]['store_id'] = $store_id;

        $session_id = session()->getId();
        if (is_null($variant_id)) {
            $variant_id = 0;
        }
        $cart_item = [
            'product_id' => $product_id,
            'variant_id' => $variant_id,
            'qty' => $qty,
            'store_id' => $store_id,
            'cookie_session_id' => $session_id, 
            'created_at' => now(),
        ];
        $cart_Cookie = Cart::forGuestCookie($session_id)->where('store_id',$store_id)->get();
        if (!empty($cart_Cookie)) {
            foreach ($cart_Cookie as $cart_data) {
                if ($cart_data->product_id == $product_id && $cart_data->variant_id == $variant_id) {
                    $new_qty = $cart_data->qty + $qty;
    
                    Cart::where('id', $cart_data->id)->update(['qty' => $new_qty]);
                    $cart_count = Cart::forGuestCookie($cart_item['cookie_session_id'])->count();
                    Cookie::queue('cart', $cart_item['cookie_session_id'], 1440); 
                    return Utility::success(['message' => $product->name . ' quantity updated successfully.', 'count' => $cart_count]);
                }
            }
            Cart::create($cart_item);
            $cart_count = Cart::forGuestCookie($cart_item['cookie_session_id'])->count(); 
            Cookie::queue('cart', $cart_item['cookie_session_id'], 1440);
        } else {
            $cart_count = Cart::forGuestCookie($session_id)->count();
            Cart::create($cart_item);
            Cookie::queue('cart', $session_id, 1440);
        }
        if (!empty($cart_count)) {
            return Utility::success(['message' => $product->name . ' add successfully.', 'count' => $cart_count]);
        } else {
            return Utility::error(['message' => __('Cart is empty.'), 'count' => $cart_count]);
        }
    }

    public static function cart_list_cookie($data, $storeid = '')
    {
        $store = Store::find($storeid);

        $store_id = $store->id ?? getCurrentStore();
        $Carts = $cart_Cookie = Cookie::get('cart');
        $shipping_price_1 = $data['shipping_final_price'] ?? 0;

        $shipping_price = (int)$shipping_price_1;
        $coupon_amount = 0;
        Session::forget('coupon_price');
        if (empty($Carts)) {
            $na = [];
            $Carts = json_encode($na);
        }
        $Carts = Cart::forGuestCookie($Carts)->where('store_id',$store_id)->get();
        if (!empty($Carts)) {
            foreach ($Carts as $key => $cart_value) {
                if ($cart_value->store_id != $store_id) {
                    unset($Carts->$key);
                }
            }
        }
        $cart_array = [];
        $original_price = 0;
        $discount_price = 0;
        $final_price = 0;
        $after_discount_final_price = 0;
        $cart_total_qty = 0;
        $cart_final_price = 0;
        $shipping_original_price = 0;
        $tax_price = $total_orignal_price = 0;
        $cart_array['product_list'] = [];
        if (!isset($data['billing_info'])) {
            $country = isset($data['countryId']) ? $data['countryId'] : null;
            $state_id = isset($data['stateId']) ? $data['stateId'] : null;
            $city_id = isset($data['cityId']) ? $data['cityId'] : null;
        } else {
            $other_info = is_string($data['billing_info']) ? (array)json_decode($data['billing_info']) : ($data['billing_info'] ?? []);
            $country = !empty($other_info['delivery_country']) ? $other_info['delivery_country'] : null;
            $state_id = !empty($other_info['delivery_state']) ? $other_info['delivery_state'] : null;
            $city_id = !empty($other_info['delivery_city']) ? $other_info['delivery_city'] : null;
        }

        $coupon_price = 0;
        $tax_id = null;
        $totalQtyDiscount = 0;
        if (!empty($Carts)) {
            foreach ($Carts as $key => $cart_value) {
                $variant_name = '';
                $cart_product_data = Product::find($cart_value->product_id);
                if (module_is_active('WholesaleProduct')) {
                    if (!module_is_active('SizeGuideline')) {
                        if (module_is_active('WholesaleProduct')) {
                            $ProductVariant = ProductVariant::find($cart_value->variant_id);
                            $request_value =[
                                'qty' => $cart_value->qty,
                                'varint' => $ProductVariant->variant ?? '',
                                'product_id' => $cart_value->product_id,
                            ];
                            $whole_sale_pricing = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($cart_product_data, $request_value,$store);
                            if (empty($cart_value->variant_id) && $cart_value->variant_id == 0) {
                                $per_product_discount_price = !empty($cart_product_data->discount_price) ? $cart_product_data->discount_price : 0;
                                $product_discount_price = $per_product_discount_price * $cart_value->qty;

                                $pricing = $whole_sale_pricing['final_price'] ?? $cart_product_data->sale_price;
                                $final_price = $pricing;

                                $product_orignal_price = !empty($whole_sale_pricing['original_price']) ? $whole_sale_pricing['original_price'] : $cart_product_data->price;
                                $total_product_orignal_price = $whole_sale_pricing['original_price'];
                            } else {
                                $ProductVariant = ProductVariant::find($cart_value->variant_id);
                                $variant_name = $ProductVariant->variant;

                                $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                                $product_discount_price = $ProductVariant->discount_price;

                                $pricing = $whole_sale_pricing['final_price'] ?? $ProductVariant->final_price;
                                
                                $final_price = $pricing;

                                $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                                $total_product_orignal_price = $whole_sale_pricing['original_price'] ?? $product_orignal_price;
                            }
                        }else{
                            if (empty($cart_value->variant_id) && $cart_value->variant_id == 0) {

                                $per_product_discount_price = !empty($cart_product_data->discount_price) ? $cart_product_data->discount_price : 0;
                                $product_discount_price = $per_product_discount_price * $cart_value->qty;

                                // $final_price = !empty($cart_product_data->sale_price) ? $cart_product_data->sale_price : 0;
                                $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,$cart_product_data->variant_id);
                                $final_price = $final_price * $cart_value->qty;

                                $product_orignal_price = !empty($cart_product_data->price) ? $cart_product_data->price : 0;
                                $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                            } else {
                                $ProductVariant = ProductVariant::find($cart_value->variant_id);
                                $variant_name = $ProductVariant->variant;

                                $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                                $product_discount_price = $ProductVariant->discount_price * $cart_value->qty;

                                // $final_price = !empty($ProductVariant->final_price) ? $ProductVariant->final_price : 0;
                                $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,$ProductVariant->id);
                                $final_price = $final_price * $cart_value->qty;

                                $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                                $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                            }
                        }
                    } else {
                        if (module_is_active('WholesaleProduct')) {
                            $ProductVariant = ProductVariant::find($cart_value->variant_id);
                            $request_value =[
                                'qty' => $cart_value->qty,
                                'varint' => $ProductVariant->variant ?? '',
                                'product_id' => $cart_value->product_id,
                            ];
                            $whole_sale_pricing = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($cart_product_data, $request_value,$store);
                            if (empty($cart_value->variant_id) && $cart_value->variant_id == 0) {
                                $per_product_discount_price = !empty($cart_product_data->discount_price) ? $cart_product_data->discount_price : 0;
                                $product_discount_price = $per_product_discount_price;

                                $pricing = $whole_sale_pricing['final_price'] ?? $cart_product_data->sale_price;
                                $pricing = Product::ProductPrice($store->slug, $cart_product_data->id,0);
                                $final_price = $pricing;

                                $product_orignal_price = !empty($whole_sale_pricing['original_price']) ? $whole_sale_pricing['original_price'] : $cart_product_data->price;
                                $total_product_orignal_price = $whole_sale_pricing['original_price'] ?? $product_orignal_price;
                            } else {
                                $ProductVariant = ProductVariant::find($cart_value->variant_id);
                                $variant_name = $ProductVariant->variant;

                                $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                                $product_discount_price = $ProductVariant->discount_price;

                                // $pricing = $whole_sale_pricing['final_price'] ?? $ProductVariant->final_price;
                                $pricing = Product::ProductPrice($store->slug, $cart_product_data->id,$ProductVariant->id);
                                $final_price = $pricing;

                                $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                                $total_product_orignal_price = $whole_sale_pricing['original_price'];
                            }
                        }
                        if (module_is_active('SizeGuideline')) {
                            $request_value =[
                                'qty' => $cart_value->qty,
                                'varint' => $ProductVariant->variant ?? '',
                                'product_id' => $cart_value->product_id,
                                'size_data' => $cart_value->size_data ?? '',
                            ];
                            if(isset($cart_value->size_data))
                                {
                                $size_price = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($cart_product_data, $request_value,$store);
                                if (empty($cart_value->variant_id) && $cart_value->variant_id == 0) {
                                    $per_product_discount_price = !empty($cart_product_data->discount_price) ? $cart_product_data->discount_price : 0;
                                    $product_discount_price = $per_product_discount_price;

                                    $pricing = $size_price['final_price'] ?? $cart_product_data->sale_price;
                                    $final_price = $pricing;

                                    $product_orignal_price = !empty($size_price['original_price']) ? $size_price['original_price'] : $cart_product_data->price;
                                    $total_product_orignal_price = $size_price['original_price'];
                                } else {
                                    $ProductVariant = ProductVariant::find($cart_value->variant_id);
                                    $variant_name = $ProductVariant->variant;

                                    $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                                    $product_discount_price = $ProductVariant->discount_price;

                                    $pricing = $size_price['final_price'] ?? $ProductVariant->final_price;
                                    $final_price = $pricing;

                                    $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                                    $total_product_orignal_price = $size_price['original_price'];
                                }
                            }else{
                                if (empty($cart_value->variant_id) && $cart_value->variant_id == 0) {

                                    $per_product_discount_price = !empty($cart_product_data->discount_price) ? $cart_product_data->discount_price : 0;
                                    $product_discount_price = $per_product_discount_price * $cart_value->qty;

                                    // $final_price = !empty($cart_product_data->sale_price) ? $cart_product_data->sale_price : 0;
                                    $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,0);
                                    $final_price = $final_price * $cart_value->qty;

                                    $product_orignal_price = !empty($cart_product_data->price) ? $cart_product_data->price : 0;
                                    $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                                } else {
                                    $ProductVariant = ProductVariant::find($cart_value->variant_id);
                                    $variant_name = $ProductVariant->variant;

                                    $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                                    $product_discount_price = $ProductVariant->discount_price * $cart_value->qty;

                                    // $final_price = !empty($ProductVariant->final_price) ? $ProductVariant->final_price : 0;
                                    $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,$ProductVariant->id);
                                    $final_price = $final_price * $cart_value->qty;

                                    $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                                    $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                                }
                            }
                        }
                    }
                } elseif (module_is_active('SizeGuideline')) {
                    if (module_is_active('SizeGuideline')) {
                        $request_value =[
                            'qty' => $cart_value->qty,
                            'varint' => $ProductVariant->variant ?? '',
                            'product_id' => $cart_value->product_id,
                            'size_data' => $cart_value->size_data ?? '',
                        ];
                        if(isset($cart_value->size_data))
                            {
                            $size_price = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($cart_product_data, $request_value,$store);
                            if (empty($cart_value->variant_id) && $cart_value->variant_id == 0) {
                                $per_product_discount_price = !empty($cart_product_data->discount_price) ? $cart_product_data->discount_price : 0;
                                $product_discount_price = $per_product_discount_price;

                                $pricing = $size_price['final_price'] ?? $cart_product_data->sale_price;
                                $final_price = $pricing;

                                $product_orignal_price = !empty($size_price['original_price']) ? $size_price['original_price'] : $cart_product_data->price;
                                $total_product_orignal_price = $size_price['original_price'];
                            } else {
                                $ProductVariant = ProductVariant::find($cart_value->variant_id);
                                $variant_name = $ProductVariant->variant;

                                $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                                $product_discount_price = $ProductVariant->discount_price;

                                $pricing = $size_price['final_price'] ?? $ProductVariant->final_price;
                                $final_price = $pricing;

                                $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                                $total_product_orignal_price = $size_price['original_price'];
                            }
                        }else{
                            if (empty($cart_value->variant_id) && $cart_value->variant_id == 0) {

                                $per_product_discount_price = !empty($cart_product_data->discount_price) ? $cart_product_data->discount_price : 0;
                                $product_discount_price = $per_product_discount_price * $cart_value->qty;

                                // $final_price = !empty($cart_product_data->sale_price) ? $cart_product_data->sale_price : 0;
                                $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,0);
                                $final_price = $final_price * $cart_value->qty;

                                $product_orignal_price = !empty($cart_product_data->price) ? $cart_product_data->price : 0;
                                $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                            } else {
                                $ProductVariant = ProductVariant::find($cart_value->variant_id);
                                $variant_name = $ProductVariant->variant;

                                $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                                $product_discount_price = $ProductVariant->discount_price * $cart_value->qty;

                                // $final_price = !empty($ProductVariant->final_price) ? $ProductVariant->final_price : 0;
                                $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,$ProductVariant->id);
                                $final_price = $final_price * $cart_value->qty;

                                $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                                $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                            }
                        }
                    }else{
                        if (empty($cart_value->variant_id) && $cart_value->variant_id == 0) {

                            $per_product_discount_price = !empty($cart_product_data->discount_price) ? $cart_product_data->discount_price : 0;
                            $product_discount_price = $per_product_discount_price * $cart_value->qty;
                            $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,$cart_product_data->variant_id);
                            $final_price = $final_price * $cart_value->qty;

                            $product_orignal_price = !empty($cart_product_data->price) ? $cart_product_data->price : 0;
                            $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                        } else {
                            $ProductVariant = ProductVariant::find($cart_value->variant_id);
                            $variant_name = $ProductVariant->variant;

                            $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                            $product_discount_price = $ProductVariant->discount_price * $cart_value->qty;
                            $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,$cart_product_data->variant_id);
                            $final_price = $final_price * $cart_value->qty;

                            $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                            $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                        }
                    }
                } else {
                    if (empty($cart_value->variant_id) && $cart_value->variant_id == 0) {

                        $per_product_discount_price = !empty($cart_product_data->discount_price) ? $cart_product_data->discount_price : 0;
                        $product_discount_price = $per_product_discount_price * $cart_value->qty;
                        $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,$cart_product_data->variant_id);
                        $final_price = $final_price * $cart_value->qty;

                        $product_orignal_price = !empty($cart_product_data->price) ? $cart_product_data->price : 0;
                        $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                    } else {
                        $ProductVariant = ProductVariant::find($cart_value->variant_id);
                        $variant_name = $ProductVariant->variant;

                        $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                        $product_discount_price = $ProductVariant->discount_price * $cart_value->qty;
                        $final_price = Product::ProductPrice($store->slug, $cart_product_data->id,$ProductVariant->id);
                        $final_price = $final_price * $cart_value->qty;

                        $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                        $total_product_orignal_price = $product_orignal_price * $cart_value->qty;
                    }
                }
                $cart_array['product_list'][$key]['cart_id'] = $key;
                $cart_array['product_list'][$key]['cart_created'] = $cart_value->created_at;
                $cart_array['product_list'][$key]['product_id'] = $cart_value->product_id;
                
                // Handle digital product images
                if ($cart_product_data->product_type === 'digital') {
                    if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                        $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $cart_product_data->id)->first();
                        if ($digitalImage && !empty($digitalImage->image_path)) {
                            $cart_array['product_list'][$key]['image'] = $digitalImage->image_path;
                        } else {
                            $cart_array['product_list'][$key]['image'] = !empty($cart_product_data->cover_image_path) ? $cart_product_data->cover_image_path : ' ';
                        }
                    } else {
                        $cart_array['product_list'][$key]['image'] = !empty($cart_product_data->cover_image_path) ? $cart_product_data->cover_image_path : ' ';
                    }
                } else {
                    $cart_array['product_list'][$key]['image'] = !empty($cart_product_data->cover_image_path) ? $cart_product_data->cover_image_path : ' ';
                }
                $cart_array['product_list'][$key]['name'] = !empty($cart_product_data->name) ? $cart_product_data->name : ' ';
                $cart_array['product_list'][$key]['orignal_price'] = SetNumber($product_orignal_price);
                $cart_array['product_list'][$key]['total_orignal_price'] = SetNumber($total_product_orignal_price);
                // $cart_array['product_list'][$key]['per_product_discount_price'] = SetNumber($per_product_discount_price);
                // $cart_array['product_list'][$key]['discount_price'] = SetNumber($product_discount_price);
                $cart_array['product_list'][$key]['final_price'] = SetNumber($final_price);
                $cart_array['product_list'][$key]['qty'] = $cart_value->qty;
                $cart_array['product_list'][$key]['variant_id'] = $cart_value->variant_id;
                $cart_array['product_list'][$key]['variant_name'] = $variant_name;
                $cart_array['product_list'][$key]['return'] = 0;
                $cart_array['product_list'][$key]['shipping_price'] = $shipping_price;

                if (is_string($total_product_orignal_price)) {
                    if (is_string($total_product_orignal_price)) {
                        preg_match('/\d+/', $total_product_orignal_price, $matches);
                        $total_product_orignal_price = (int)$matches[0];
                    }
                }
                if (module_is_active('ProductPricing')) {
                    $admin  = User::find($store->created_by ?? null) ?? null;
                    $plan   = Plan::find($admin->plan_id ?? null) ?? null;
                    $productPricingRules    = \Workdo\ProductPricing\app\Models\ProductPricingRule::where('store_id',$store->id)->get();
                    if (isset($plan->modules) && strpos($plan->modules, 'ProductPricing') !== false && count($productPricingRules) != 0 && empty($cart_value->variant_id) && $cart_value->variant_id == 0){
                        $product_list_values = [
                            'product_discount_price'        => $product_discount_price,
                            'final_price'                   => $final_price,
                            'product_orignal_price'         => $product_orignal_price,
                            'total_product_orignal_price'   => $total_product_orignal_price,
                        ];
                        $productPricing = \Workdo\ProductPricing\app\Models\ProductPricingRule::productPricingApply($Carts,$cart_value,$store,$cart_product_data,$product_list_values,$totalQtyDiscount);
                        $product_discount_price = $productPricing['product_discount_price'];
                        $final_price            = $productPricing['final_price'];
                        if ($productPricing['product_json_check'] == true && $productPricing['condition_json_check'] == true) {
                            $cart_array['product_list'][$key]['sale_price'] = $final_price ?? 0;
                            $cart_array['product_list'][$key]['apply_conditions'] = $productPricing['apply_conditions'] ?? [];
                            if(isset($productPricing['qty_json'])){
                                $cart_array['product_list'][$key]['qty_json'] = $productPricing['qty_json'] ?? [];
                            }
                            if(isset($productPricing['totalQtyDiscount'])){
                                $totalQtyDiscount += $productPricing['totalQtyDiscount'];
                            }
                        }
                    }
                }

                $discount_price += $product_discount_price;
                $cart_total_qty += $cart_value->qty;
                $cart_final_price += $final_price;
                $original_price += $total_product_orignal_price;
                $shipping_original_price += $shipping_price;

                if (isset($data['coupon_code'])) {
                    // P01-SEC-14 C4: portable exact-code match. See Coupon::findByExactCode.
                    // P01-SEC-14 D2: store-scoped. This is the GUEST (cookie) cart, and the
                    // predicate was absent here too. `$store_id` rather than `$store->id` because
                    // `Store::find($storeid)` above can return null, and a predicate on a null
                    // store matches nothing -- which refuses the coupon rather than widening it.
                    $coupon = Coupon::findByExactCode(
                        $data['coupon_code'],
                        fn ($query) => $query->where('store_id', $store_id)
                            ->whereDate('coupon_expiry_date', '>=', now()->toDateString())
                    );
                    if ($coupon) {
                        $coupon_apply_price = self::getCouponTotalAmount($coupon, $final_price, $cart_product_data->id, $cart_product_data->category_id);
                        $coupon_price += $final_price - $coupon_apply_price;
                        $final_price = $coupon_apply_price;
                    }
                }
                if (isset($cart_product_data->tax_id) && $cart_product_data->tax_id) {
                    $tax_id = $cart_product_data->tax_id;
                    $product_tax = self::getProductTaxAmount($cart_product_data->tax_id, $final_price, $store->id, $city_id, $state_id, $country, true);
                    $tax_price += $product_tax;
                }
            }
        }

        $after_discount_final_price = $cart_final_price;

        $product_discount_price = (float)number_format((float)$discount_price, 2);
        $cart_array['product_discount_price'] = $product_discount_price;
        $after_discount_final_price = (float)$after_discount_final_price;

        $cart_array['sub_total'] = $after_discount_final_price + $shipping_price;

        $tax_option = TaxOption::where('store_id', $store_id)
            ->pluck('value', 'name')->toArray();

        if (isset($tax_option['round_tax']) && $tax_option['round_tax'] == 1) {
            $tax_price = round($tax_price);
        }

        // Check if tax is inclusive
        $tax_option = TaxOption::where('store_id', $store_id)->pluck('value', 'name')->toArray();
        $is_inclusive_tax = isset($tax_option['price_type']) && $tax_option['price_type'] == 'inclusive' && isset($tax_option['shop_price']) && $tax_option['shop_price'] == 'including';
        
        // For inclusive tax, don't add tax to total but keep tax_price for display
        if ($coupon_price == '') {
            $final_total = $cart_final_price - $coupon_price + $shipping_price + ($is_inclusive_tax ? 0 : $tax_price);
        } else {
            $final_total = ($cart_final_price - $coupon_price) + $shipping_price + ($is_inclusive_tax ? 0 : $tax_price);
        }

        $cart_array['cart_total_product'] = $Carts->count();
        $cart_array['cart_total_qty'] = $cart_total_qty;
        $cart_array['original_price'] = SetNumber($original_price);
        $cart_array['final_price'] = SetNumber($cart_final_price);
        $cart_array['total_final_price'] = SetNumber($cart_final_price);
        $cart_array['total_tax_price'] = SetNumber($tax_price);
        $cart_array['tax_price'] = SetNumber($tax_price);
        $cart_array['tax_id'] = $tax_id ?? null;
        $cart_array['total_coupon_price'] = SetNumber($coupon_price);
        $cart_array['total_sub_price'] = SetNumber($final_total);
        $cart_array['coupon_code'] =  $data['coupon_code'] ?? null;
        $cart_array['shipping_original_price'] = $shipping_price;
            
        if (!empty($cart_array)) {
            return Utility::success($cart_array);
        } else {
            return Utility::error(['message' => 'Cart is empty.']);
        }
    }

    public static function CartCount($slug = '')
    {
        $return = 0;
        $store = getStore($slug);
        $theme_id = GetCurrenctTheme($store->slug);
        if (auth('customers')->user()) {
            $return = Cart::where('customer_id', auth('customers')->user()->id)
                ->count();
        } else {
            $cart_Cookie = Cookie::get('cart');
            // $store_id = \Auth::user()->current_store;
            // $store = Store::where('id',$store_id)->first();

            if (!empty($cart_Cookie)) {
                $cart_array = json_decode($cart_Cookie, true);
                if ($cart_array !== null) {
                    foreach ($cart_array as $key => $cart_value) {
                        if ($cart_value['store_id'] != $store->id) {
                            unset($cart_array[$key]);
                        }
                    }
                    $return = count($cart_array);
                }
            }
        }
        return $return;
    }

    public static function cart_qty_cookie($request)
    {
        $rules = [
            'product_id' => 'required',
            'quantity_type' => 'required|in:increase,decrease,remove',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return Utility::error([
                'message' => $messages->first()
            ]);
        }
        $product = Product::find($request->product_id);
        $final_price = 0;
        $cart_id = $request->cart_id;
        if (!empty($request->variant_id) || $request->variant_id != 0) {
            $ProductVariant = ProductVariant::find($request->variant_id);
            $final_price = $ProductVariant->final_price;
        } else {
            $product = Product::find($request->product_id);
            if (!empty($product)) {
                if ($product->variant_product == 1) {
                    if (empty($request->variant_id) || $request->variant_id == 0) {
                        return Utility::error([
                            'message' => 'Please Select a variant in a product.'
                        ]);
                    }
                }
                $final_price = $product->final_price;
            }
        }

        $cart = Cookie::get('cart');
        // $cart_array1 = json_decode($cart, true);
        // $cart_count = count($cart_array1);

        // $cart_array = json_decode($cart);
        $Carts = Cookie::get('cart');
        $cart_array = Cart::forGuestCookie($Carts)->where('product_id',$request->product_id)->where('variant_id',$request->variant_id)->first();
        $cart_count = 1;

        if (empty($cart_array)) {
            return Utility::error(['message' => 'Product not found.'], 'fail', 200, 0, $cart_count);
        } else {
            // P1-SEC-24. Read AFTER the check above: the row was dereferenced (`$cart_array->store_id`) before
            // it, so a missing basket was a null-property 500 instead of the "Product not found." written for it.
            $settings = Setting::where('store_id', $cart_array->store_id)->pluck('value', 'name')->toArray();

            if ($request->quantity_type == 'increase') {
                if (!empty($request->variant_id) || $request->variant_id != 0) {

                    if (module_is_active('CartQuantityControl')) {
                        $cartData = $cart_array;
                        if (isset($settings['cart_quantity_control_enable']) && $settings['cart_quantity_control_enable'] == 'on') {
                            $response = \Workdo\CartQuantityControl\app\Models\CartQuantityControl::checkoutValidation($request, $ProductVariant, $cartData, $settings);

                            if ($response && $response->getData()->status === 'error') {
                                return Utility::error(['message' => $response->getData()->message], 'fail', 200);
                            }
                        }
                    }

                    if (($settings['stock_management'] ?? null) === 'on') {
                        $variationOptions = explode(',', $ProductVariant->variation_option);
                        $option = in_array('manage_stock', $variationOptions);
                        if (!empty($ProductVariant)) {
                            if ($option == true) {
                                if ($cart_array->qty >= $ProductVariant->stock) {
                                    return Utility::error(['message' => 'can not increase product quantity.'], 'fail', 200, 0, $cart_count);
                                } else {
                                    $cart_array->qty += 1;
                                }
                            } else {
                                if ($ProductVariant->stock_status == 'in_stock' || $ProductVariant->stock_status == 'on_backorder') {
                                    $cart_array->qty += 1;
                                } elseif ($ProductVariant->stock_status == null && $option == false) {
                                    if (($product->stock_status == 'in_stock' || $product->stock_status == 'on_backorder')) {
                                        if ($product->track_stock == 1 && $cart_array->qty < $product->product_stock) {
                                            $cart_array->qty += 1;
                                        } else {

                                            $cart_array->qty += 1;
                                        }
                                    } elseif ($product->track_stock == 1 && $product->stock_order_status != 'not_allow') {
                                        $cart_array->qty += 1;
                                    } elseif ($product->track_stock == 0 && $product->stock_order_status == null &&   $product->stock_status == null) {
                                        return Utility::error(['message' => 'This product is out of stock.'], 'fail', 200, 0, $cart_count);
                                    } elseif ($cart_array->qty >= $product->product_stock) {
                                        return Utility::error(['message' => 'can not increase product quantity.'], 'fail', 200, 0, $cart_count);
                                    } else {
                                        $cart_array->qty += 1;
                                    }
                                } else {
                                    return Utility::error(['message' => 'can not increase product quantity.'], 'fail', 200, 0, $cart_count);
                                }
                            }
                        }
                    } else {
                        if ($ProductVariant->stock_status == 'in_stock' || $ProductVariant->stock_status == 'on_backorder') {
                            $cart_array->qty += 1;
                        } elseif ($ProductVariant->stock_status == null && $ProductVariant->stock != 0) {
                            $cart_array->qty += 1;
                        } elseif ($product->track_stock == 0 && $product->stock_order_status == null &&   $product->stock_status == 0) {
                            return Utility::error(['message' => 'This product is out of stock.'], 'fail', 200, 0, $cart_count);
                        } else {
                            return Utility::error(['message' => 'can not increase product quantity.'], 'fail', 200, 0, $cart_count);
                        }
                    }
                    // if ($cart_array->$cart_id->qty >= $ProductVariant->stock) {
                    //     return Utility::error(['message' => 'can not increase product quantity.'], 'fail', 200, 0, $cart_count);
                    // } else {
                    //     $cart_array->$cart_id->qty += 1;
                    // }
                } else {

                    if (module_is_active('CartQuantityControl')) {
                        $cartData = $cart_array;
                        if (isset($settings['cart_quantity_control_enable']) && $settings['cart_quantity_control_enable'] == 'on') {
                            $response = \Workdo\CartQuantityControl\app\Models\CartQuantityControl::checkoutValidation($request, $product, $cartData, $settings);

                            if ($response && $response->getData()->status === 'error') {
                                return Utility::error(['message' => $response->getData()->message], 'fail', 200);
                            }
                        }
                    }
                    if (($product->stock_status == 'in_stock' || $product->stock_status == 'on_backorder')) {
                        if ($product->track_stock == 1 && $cart_array->qty < $product->product_stock) {
                            $cart_array->qty += 1;
                        } else {
                            $cart_array->qty += 1;
                        }
                    } elseif ($cart_array->qty >= $product->product_stock) {
                        return Utility::error(['message' => 'can not increase product quantity.'], 'fail', 200, 0, $cart_count);
                    } else {
                        $cart_array->qty += 1;
                    }
                }
            }
            if ($request->quantity_type == 'decrease') {
                if ($cart_array->qty == 1) {
                    return Utility::error(['message' => 'can not decrease product quantity.'], 'fail', 200, 0, $cart_count);
                }
                if ($cart_array->qty > 0) {
                    $cart_array->qty -= 1;
                    $cart_array->save();
                }
            }

            if ($request->quantity_type == 'remove') {
                unset($cart_array->$cart_id);
            }
            $cart_array->save();

            // P1-SEC-24. The count of THIS guest's basket. It was `Cart::where('customer_id', $request->user_id)`: a
            // request-chosen customer's line count (an oracle for any customer id), or, with no user_id, the NULL-customer rows.
            $cart_count = Cart::forGuestCookie($Carts)->count();
            return Utility::success(['message' => 'Cart successfully updated.'], "successfull", 200, $cart_count);
        }
    }

    public static function cookie_to_cart($user_id = 0, $store_id = '' )
    {
        if ($user_id != 0) {
            $store = getStoreById($store_id);
            $cart_Cookie = Cookie::get('cart');
            if (!empty($cart_Cookie)) {
                $products = json_decode($cart_Cookie);
                foreach ($products as $key => $product) {
                    $cart = Cart::where('customer_id', $user_id)->where('product_id', $product->product_id)->where('variant_id', $product->variant_id)->first();
                    if (!empty($cart)) {
                        $cart->qty = $cart->qty + $product->qty;
                        $cart->save();
                    } else {
                        $price = 0;

                        $cart = new Cart();
                        $cart->customer_id = $user_id;
                        $cart->product_id = $product->product_id;
                        $cart->variant_id = $product->variant_id;
                        $cart->qty = $product->qty;

                        if ($product->variant_id == 0) {
                            $productss = Product::find($product->product_id);
                            $price = !empty($productss) ? ($productss->sale_price ?? $productss->price) : 0;
                        } else {
                            $ProductVariant = ProductVariant::find($product->variant_id);
                            $price = !empty($ProductVariant) ? ($ProductVariant->variation_price ?? $ProductVariant->price) : 0;
                        }
                        $cart->price = $price;
                        $cart->store_id = $store_id;
                        $cart->save();
                    }
                }
                $empty_cart = [];
                $cart_json = json_encode($empty_cart);
                Cookie::queue('cart', $cart_json, 1440);
            }
        }
    }

    public static function CartPageBestseller($slug = '')
    {
        $Category = Category::where('store_id', getCurrentStore())->get()->pluck('name', 'id');
        $Category->prepend('All Products', '0');
        $homeproducts = Product::where('store_id', getCurrentStore())->get();
        $currency_icon = Utility::GetValueByName('CURRENCY', getCurrentStore());

        return view('bestseller_cart', compact('slug', 'homeproducts', 'Category', 'currency_icon'))->render();
    }

    public function UserData()
    {
        return $this->hasOne(Customer::class, 'id', 'customer_id');
    }

    public static function getProductTaxAmount($taxId = null, $sub_total, $storeId, $city_id, $state_id, $country, $flag)
    {
        $tax_price = 0;

        $taxs = TaxMethod::where('store_id', $storeId)->where('tax_id', $taxId)->orderBy('priority', 'asc')->get();

        $settings = TaxOption::where('store_id', $storeId)->pluck('value','name')->toArray();
        if(isset($settings['price_type']) && $settings['price_type'] == 'inclusive' && isset($settings['shop_price']) && $settings['shop_price'] == 'including')
        {
            // For inclusive tax, calculate the tax amount that's already included in the price
            foreach ($taxs as $tax) {
                if ($flag) {
                    if ($tax->country_id && $tax->state_id && $tax->city_id && ($country == $tax->country_id) && ($state_id == $tax->state_id) && ($city_id == $tax->city_id)) {
                        $tax_price += ($sub_total * $tax->tax_rate) / (100 + $tax->tax_rate);
                        continue;
                    }
                    if ($tax->country_id && $tax->state_id && !$tax->city_id && ($country == $tax->country_id) && ($state_id == $tax->state_id)) {
                        $tax_price += ($sub_total * $tax->tax_rate) / (100 + $tax->tax_rate);
                        continue;
                    }
                    if ($tax->country_id && !$tax->state_id && !$tax->city_id && ($country == $tax->country_id)) {
                        $tax_price += ($sub_total * $tax->tax_rate) / (100 + $tax->tax_rate);
                        continue;
                    }
                    if (!$tax->country_id && !$tax->state_id && !$tax->city_id) {
                        $tax_price += ($sub_total * $tax->tax_rate) / (100 + $tax->tax_rate);
                        continue;
                    }
                } else {
                    $tax_price += ($sub_total * $tax->tax_rate) / (100 + $tax->tax_rate);
                }
            }
            // If no location-specific tax found, calculate with all available tax rates
            if ($tax_price == 0) {
                foreach ($taxs as $tax) {
                    $tax_price += ($sub_total * $tax->tax_rate) / (100 + $tax->tax_rate);
                }
            }
            return $tax_price;
        }
        $tax_value = false;
        foreach ($taxs as $tax) {
            if ($flag) {
                if ($tax->country_id && $tax->state_id && $tax->city_id && ($country == $tax->country_id) && ($state_id == $tax->state_id) && ($city_id == $tax->city_id)) {
                    $tax_value = true;
                    $amount = $tax->tax_rate * $sub_total / 100;
                    $tax_price += $amount;
                    continue;
                }
                if ($tax->country_id && $tax->state_id && !$tax->city_id && ($country == $tax->country_id) && ($state_id == $tax->state_id)) {
                    $tax_value = true;
                    $amount = $tax->tax_rate * $sub_total / 100;
                    $tax_price += $amount;
                    continue;
                }
                if ($tax->country_id && !$tax->state_id && !$tax->city_id && ($country == $tax->country_id)) {
                    $tax_value = true;
                    $amount = $tax->tax_rate * $sub_total / 100;
                    $tax_price += $amount;
                    continue;
                }
                if (!$tax->country_id && !$tax->state_id && !$tax->city_id) {
                    $tax_value = true;
                    $amount = $tax->tax_rate * $sub_total / 100;
                    $tax_price += $amount;

                    continue;
                }
                if ($tax_value == false && isset($settings['price_type']) && $settings['price_type'] != 'inclusive') {
                    $amount = $tax->tax_rate * $sub_total / 100;
                    $tax_price += $amount;
                }
            } else {
                $amount = $tax->tax_rate * $sub_total / 100;
                $tax_price += $amount;
            }
        }
        return $tax_price;
    }

    public static function getCouponTotalAmount($coupon, $subTotal, $productId, $categoryId)
    {
        $coupon_price = 0;

        if (!empty($coupon)) {
            $coupon_count = $coupon->UsesCouponCount();
            $couponQuery = Coupon::query();
            $coupon_expiry_date = (clone $couponQuery)->where('id', $coupon->id)
                ->whereDate('coupon_expiry_date', '>=', date('Y-m-d'))
                ->where('coupon_limit', '>', $coupon_count)
                ->first();
            // Usage limit per user
            $i = 0;

            if (auth('customers')->user()) {
                $coupon_email  = $coupon->PerUsesCouponCount();
                foreach ($coupon_email as $email) {
                    if ($email == auth('customers')->user()->email) {
                        $i++;
                    }
                }
            }
            if (!empty($coupon->coupon_limit_user)) {
                if ($i  >= $coupon->coupon_limit_user) {
                    return 0;
                }
            }
            if (empty($coupon_expiry_date)) {
                return 0;
            }
        }

        //if ( $coupon->free_shipping_coupon == 0 ) {
        if ($coupon->coupon_type == 'percentage') {
            $coupon_price =  ($subTotal - (($coupon->discount_amount * $subTotal) / 100));
        } elseif ($coupon->coupon_type == 'flat') {
            $coupon_price = $subTotal - $coupon->discount_amount;
        } elseif ($coupon->coupon_type == 'fixed product discount') {
            $coupon_applied = explode(',', ($coupon->applied_product));
            $exclude_product = explode(',', $coupon->exclude_product);
            $applied_categories = explode(',', $coupon->applied_categories);
            $exclude_categories = explode(',', $coupon->exclude_categories);

            // Check if coupon is applied
            if (count($coupon_applied) > 0) {
                // Check if exclude_product, applied_categories, and exclude_categories are not empty
                if (count($exclude_product) > 0 && count($applied_categories) > 0 && count($exclude_categories) > 0) {
                    // Check if productId is not in exclude_product and coupon_applied
                    if (!in_array($productId, $exclude_product) && !in_array($productId, $coupon_applied)) {
                        // Check if categoryId is in applied_categories and not in exclude_categories
                        if (in_array($categoryId, $applied_categories) && !in_array($categoryId, $exclude_categories)) {
                            $coupon_price = $subTotal - $coupon->discount_amount;
                        } elseif (!in_array($categoryId, $exclude_categories)) {
                            $coupon_price = $subTotal - $coupon->discount_amount;
                        }
                    }
                }
                // Check if exclude_product and applied_categories are not empty and exclude_categories is empty
                elseif (count($exclude_product) > 0 && count($applied_categories) > 0 && count($exclude_categories) == 0) {
                    // Check if productId is not in exclude_product and coupon_applied
                    if (!in_array($productId, $exclude_product) && !in_array($productId, $coupon_applied)) {
                        // Check if categoryId is in applied_categories
                        if (in_array($categoryId, $applied_categories)) {
                            $coupon_price = $subTotal - $coupon->discount_amount;
                        }
                    }
                }
                // Check if exclude_product is not empty and applied_categories is empty and exclude_categories is not empty
                elseif (count($exclude_product) > 0 && count($applied_categories) == 0 && count($exclude_categories) > 0) {
                    // Check if productId is not in exclude_product and coupon_applied
                    if (!in_array($productId, $exclude_product) && !in_array($productId, $coupon_applied)) {
                        // Check if categoryId is not in exclude_categories
                        if (!in_array($categoryId, $exclude_categories)) {
                            $coupon_price = $subTotal - $coupon->discount_amount;
                        }
                    }
                }
                // Check if exclude_product is empty and applied_categories is not empty and exclude_categories is not empty
                elseif (count($exclude_product) == 0 && count($applied_categories) > 0 && count($exclude_categories) > 0) {
                    // Check if productId is not in coupon_applied
                    if (!in_array($productId, $coupon_applied)) {
                        // Check if categoryId is in applied_categories and not in exclude_categories
                        if (in_array($categoryId, $applied_categories) && !in_array($categoryId, $exclude_categories)) {
                            $coupon_price = $subTotal - $coupon->discount_amount;
                        } elseif (!in_array($categoryId, $exclude_categories)) {
                            $coupon_price = $subTotal - $coupon->discount_amount;
                        }
                    }
                }
                // Check if exclude_product, applied_categories, and exclude_categories are empty
                elseif (count($exclude_product) == 0 && count($applied_categories) == 0 && count($exclude_categories) == 0) {
                    // Check if productId is in coupon_applied
                    if (in_array($productId, $coupon_applied)) {
                        $coupon_price = $subTotal - $coupon->discount_amount;
                    }
                }
            }
        }
        //}
        return $coupon_price;
    }

    public static function calculateFlatRateShippingAmount($shippingMethods, $shippingCost, $productList)
    {
        $cost_totle =  $shippingCost;
        $price = 0;
        foreach ($productList as $key => $Product) {
            $productId = $Product->product_id;
            $product_data = Product::find($productId);
            if ($product_data->variant_product == 0) {
                if ($shippingMethods['product_cost'] != null) {
                    $shippingClass = Shipping::find($product_data->shipping_id);
                    $value = $shippingMethods['product_cost'];
                    $product_cost = json_decode($value, true);
                    $most_expensive_shipping_price = max($product_cost['product_cost']);
                    $most_expensive_shipping_price_id = array_search($most_expensive_shipping_price, $product_cost['product_cost']);
                    
                    if ($shippingClass == null) {
                        $price  += $product_cost['product_no_cost'];
                    } else {
                        if ($shippingMethods->calculation_type == 2) {
                            foreach ($product_cost['product_cost'] as $key => $value) {
                                if ($key == $shippingClass->id && $most_expensive_shipping_price_id == $shippingClass->id) {
                                    $price  += $most_expensive_shipping_price;
                                } else {
                                    $price  += 0;
                                }
                            }
                        } else {
                            foreach ($product_cost['product_cost'] as $key => $value) {
                                if ($key == $shippingClass->id) {
                                    $price  += $value;
                                } else {
                                    $price  += 0;
                                }
                            }
                        }
                    }
                } else {
                    $cost_totle = $shippingMethods->cost;
                }
            } else {
                if ($shippingMethods['product_cost'] != null) {
                    $productVariants = [];

                    foreach ($productList as $item) {
                        $productId = $item->product_id;
                        $variantId = $item->variant_id;

                        if (!isset($productVariants[$productId])) {
                            $productVariants[$productId] = [];
                        }
                        $productVariants[$productId][] = $variantId;
                    }
                    $uniqueVariantIds = [];
                    foreach ($productVariants as $variants) {
                        $uniqueVariantIds = array_merge($uniqueVariantIds, $variants);
                    }

                    $uniqueVariantIds = array_values(array_unique($uniqueVariantIds));
                    $product_stock = ProductVariant::whereIn('id', $uniqueVariantIds)->where('product_id', $Product->product_id)->get();
                    $value = $shippingMethods['product_cost'];
                    $product_cost = json_decode($value, true);

                    $most_expensive_shipping_price = max($product_cost['product_cost']);
                    $most_expensive_shipping_price_id = array_search($most_expensive_shipping_price, $product_cost['product_cost']);

                    foreach ($product_stock as $stock) {
                        $shippingClass = Shipping::find($stock->shipping);

                        if ($stock->shipping == 'same_as_parent') {
                            $shipping = Shipping::find($product_data->shipping_id);
                            if ($shipping == null) {
                                $price  += $product_cost['product_no_cost'];
                            }
                            if ($shippingMethods->calculation_type == 2) {
                                foreach ($product_cost['product_cost'] as $key => $value) {
                                    if ($shipping) {
                                        if ($key == $shipping->id && $most_expensive_shipping_price_id == $shipping->id) {
                                            $price  += $most_expensive_shipping_price;
                                        } else {
                                            $price  += 0;
                                        }
                                    }
                                }
                            } else {
                                foreach ($product_cost['product_cost'] as $key => $value) {
                                    if ($shipping) {
                                        if ($key == $shipping->id) {
                                            $price  += $value;
                                        } else {
                                            $price  += 0;
                                        }
                                    }
                                }
                            }
                        } else {
                            if ($shippingMethods->calculation_type == 2) {
                                foreach ($product_cost['product_cost'] as $key => $value) {
                                    if ($shippingClass) {
                                        if ($key == $shippingClass->id && $most_expensive_shipping_price_id == $shippingClass->id) {
                                            $price  += $most_expensive_shipping_price;
                                        } else {
                                            $price  += 0;
                                        }
                                    }
                                }
                            } else {
                                foreach ($product_cost['product_cost'] as $key => $value) {
                                    if ($shippingClass) {
                                        if ($key == $shippingClass->id) {
                                            $price  += $value;
                                        } else {
                                            $price  += 0;
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $cost_totle = $shippingMethods->cost;
                }
            }
        }
        return $cost_totle + $price;
    }
}
