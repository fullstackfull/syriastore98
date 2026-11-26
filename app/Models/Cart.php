<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cookie;
use Session;
use App\Http\Controllers\CartController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    /**
     * THE DEFAULTS ARE GONE FROM THE FIRST THREE because `$store_id` never had one: PHP already
     * treated all four as required, and warned that it was doing so. Arity-identical, and NOT
     * silenced the other way (`$store_id = 0`) because a cart row with `store_id` 0 belongs to no
     * tenant. `NoOptionalBeforeRequiredParameterTest` holds the measurement and the rule.
     */
    public static function addtocart_cookie($product_id, $variant_id, $qty, $store_id, $chart_data = '')
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
        // One entry per cart line, filled by the loop and consumed by the coupon/tax pass after
        // it. See the note at the `$coupon_lines[]` push below (P4-F001 / P4-F062).
        $coupon_lines = [];
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

                /*
                 * THE COUPON AND THE TAX ARE DECIDED AFTER THIS LOOP, NOT INSIDE IT.  P4-F001 /
                 * P4-F062. Both used to run here, per line, and a `flat` coupon was therefore
                 * subtracted once PER LINE -- see App\Services\Commerce\CouponBasketAllocator for
                 * the measurement (a 120.00 basket wrote a COD order of 0.00).
                 *
                 * A basket-wide bound cannot be computed from one line, and neither can the
                 * `minimum_spend` / `maximum_spend` gate, so the line is recorded and both are
                 * applied below once `$cart_final_price` holds the whole pre-coupon subtotal.
                 *
                 * TAX MOVES WITH IT DELIBERATELY: tax was, and still is, computed on the line price
                 * AFTER its share of the coupon. Leaving tax here would have taxed the undiscounted
                 * line and changed every taxed basket's total.
                 */
                $coupon_lines[] = [
                    'final_price' => $final_price,
                    'product_id' => $cart_product_data->id,
                    'category_id' => $cart_product_data->category_id,
                    'tax_id' => $cart_product_data->tax_id ?? null,
                ];
            }
        }

        /*
         * SECOND PASS: ONE COUPON ACROSS THE BASKET, THEN TAX ON WHAT IS LEFT.  P4-F001 / P4-F062.
         *
         * `$cart_final_price` is the pre-coupon subtotal -- the loop above accumulated it BEFORE the
         * coupon block used to run -- so it is the right baseline for both the spend gate and the
         * `flat` allowance.
         *
         * The lookup is the same store-scoped predicate as before -- `$store_id`, the same variable,
         * verified against the diff -- hoisted out of the loop it used to be re-run in
         * (P01-SEC-14 D2, preserved exactly).
         *
         * THE REASON THIS USED TO GIVE FOR READING `$store_id` WAS WRONG, and it is corrected here
         * rather than carried forward: it said `Store::find($storeid)` above can return null "and a
         * predicate on a null store matches nothing". `$store_id` is not null in that case --
         * `:242` is `$store->id ?? getCurrentStore()`, so a null `$store` means the AMBIENT tenant,
         * not no tenant. The predicate is nonetheless self-consistent, because the cart rows at
         * `:253` are selected with the same `$store_id`: basket and coupon come from one store.
         * A null `$store` is reachable -- `PaymentController.php:3130`,
         * `PayUPaymentController.php:352` and `PaiementProPaymentController.php:557` all call this
         * with `$store->id ?? ''`, and `Store::find('')` is null. Found by independent review.
         */
        $coupon = null;

        if (isset($data['coupon_code'])) {
            // P01-SEC-14 C4: portable exact-code match. See Coupon::findByExactCode.
            $coupon = Coupon::findByExactCode(
                $data['coupon_code'],
                fn ($query) => $query->where('store_id', $store_id)
                    ->whereDate('coupon_expiry_date', '>=', now()->toDateString())
            );
        }

        $allocator = \App\Services\Commerce\CouponBasketAllocator::forBasket(
            $coupon,
            (float) $cart_final_price
        );

        foreach ($coupon_lines as $line) {
            $linePrice = (float) $line['final_price'];
            $discount = $allocator->discountForLine($linePrice, $line['product_id'], $line['category_id']);
            $coupon_price += $discount;
            $linePrice = max(0.0, $linePrice - $discount);

            if (!empty($line['tax_id'])) {
                $tax_id = $line['tax_id'];
                $tax_price += self::getProductTaxAmount($line['tax_id'], $linePrice, $store->id, $city_id, $state_id, $country, true);
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

            /*
             * P4-F005. THE THIRD QUANTITY TYPE NOW DOES WHAT THE VALIDATOR ADVERTISES.
             *
             * The validator above accepts `increase,decrease,remove`. This arm implemented two of
             * them: `remove` ran `unset($cart_array->$cart_id)`, and `$cart_array` is the basket
             * ROW -- one Eloquent model -- not an array of rows, so that removed a dynamic
             * PROPERTY named by the request's own `cart_id` value from an object that has no such
             * attribute. Nothing was marked dirty, the unconditional `save()` below was gated on
             * `isDirty()` and never reached `performUpdate`, and the handler answered `status: 1`
             * with `Cart successfully updated.` -- which the storefront toasts as a Success -- while
             * the line stayed in the basket and the returned count still counted it. Measured
             * through the real route: `{"count":1,"status":1,...}` with the row and its quantity
             * untouched.
             *
             * THE SAVE MUST NOT RUN AFTER THE DELETE, which is why this is an `else` and not a
             * second statement: `Model::delete()` leaves `$exists` false, so a following `save()`
             * takes the INSERT path and would write the row back with its own id -- a resurrection
             * that looks exactly like the no-op it replaces. `ApiController::cart_qty`'s remove
             * branch returns from inside itself for the same reason; that is the shape copied here.
             *
             * THE ROW IS THE ONE THIS COOKIE'S BASKET HOLDS, not the one `cart_id` names. It was
             * found above through `forGuestCookie($Carts)` plus product and variant, so a caller
             * cannot delete across baskets by guessing a sequential id -- `cart_id` takes no part
             * in the lookup, and this deliberately keeps it that way.
             */
            if ($request->quantity_type == 'remove') {
                $cart_array->delete();
            } else {
                $cart_array->save();
            }

            // P1-SEC-24. The count of THIS guest's basket. It was `Cart::where('customer_id', $request->user_id)`: a
            // request-chosen customer's line count (an oracle for any customer id), or, with no user_id, the NULL-customer rows.
            // P4-F004 follow-up. SCOPED TO THE STORE THE CHANGED LINE BELONGS TO, for the reason
            // given at `CartController::product_cartlist`'s own count: `null_tenant => 'open'`
            // means the global scope adds no predicate for a guest, so this counted the shopper's
            // lines in every store sharing the `cart` cookie and reported them as this store's
            // basket. `$cart_array` is non-null here -- the `empty()` arm returned above.
            $cart_count = Cart::forGuestCookie($Carts)->where('store_id', $cart_array->store_id)->count();
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

    /**
     * `$taxId` LOST ITS DEFAULT, NOT ITS MEANING. The six parameters after it never had one, so PHP
     * already required all seven; measured required=7 total=7 before and after, and the eight-
     * argument callers GAP-211 records still have their last argument dropped exactly as before.
     * `NoOptionalBeforeRequiredParameterTest` holds the measurement and the rule.
     */
    public static function getProductTaxAmount($taxId, $sub_total, $storeId, $city_id, $state_id, $country, $flag)
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
            /*
             * P4-F070 -- THE "IF NOTHING MATCHED, CHARGE EVERYTHING" SWEEP IS GONE.
             *
             * It re-walked every method whenever no jurisdiction matched, which is the inclusive
             * spelling of the fallback removed from the exclusive branch below. Measured on a
             * Damascus shopper against [Aleppo 3%, Homs 5%]: 7.67 charged where 0.00 is owed.
             *
             * A method carrying country/state/city columns is one the MERCHANT restricted. A
             * shopper outside it owes nothing, and a shopper whose address is not known yet is
             * outside every one of them -- which is the first view of every basket, since
             * `country_id`, `state_id` and `city_id` are null until an address is entered. A tax
             * that applies to everyone leaves those columns null and is taken by the universal
             * branch above, which still applies to everyone.
             *
             * WHAT THIS CHANGES DEPENDS ON WHICH CART READS THE FIGURE, and the sentence that stood
             * here said only "THIS CHANGES WHAT SHOPPERS ARE CHARGED ... DOWNWARD". That is true for
             * one caller and false for the other:
             *
             *   * `Cart::cart_list_cookie` (the GUEST cart) adds `($is_inclusive_tax ? 0 : $tax_price)`
             *     to its total and says "keep tax_price for display". Under inclusive pricing the
             *     charge is therefore bit-identical and what moves is the tax the order RECORDS and
             *     DISPLAYS -- `orders.tax_price`, `order_tax_details.tax_final_amount`: a shopper
             *     matching no jurisdiction now shows 0.00 where it showed the sum of every method's
             *     embedded share (the 7.67 measured above). A change to a VAT record on an unchanged
             *     charge.
             *
             *   * `ApiController::cart_list` (the SIGNED-IN customer's cart) adds `$tax_price` to
             *     `total_sub_price` with NO inclusive exemption (`ApiController.php`, the two
             *     `$final_total = ... + $tax_price` lines), and the cod / bank-transfer writer
             *     records that key as `orders.final_price`. So there the swept figure WAS in the
             *     charged amount, and the charge falls by it. For a signed-in shopper who DOES match
             *     a jurisdiction nothing changes -- including that cart's pre-existing addition of
             *     a tax the inclusive price already contains, which this change does not touch and
             *     which is recorded as its own owner item rather than fixed here.
             *
             * THE OTHER CALLERS, read one by one rather than assumed (paths under `packages/workdo/`
             * are vendored and are NOT edited here):
             *
             *   * `AuctionProduct` `Console/Auction.php` prices the winning bid with seven arguments in
             *     order and ADDS the figure to the amount it charges (`$maxBidAmount + $tax_price + ...`),
             *     so it follows the signed-in cart's shape: the charge falls with the figure.
             *   * `BoostSales` (the view composer and the controller) and the auction detail view pass the
             *     same seven arguments and only DISPLAY the figure.
             *   * `FrequentlyProduct` (view composer and controller) passes EIGHT: `$store->theme_id` lands
             *     in `$city_id`, every address id moves one slot, and `$flag` receives the shopper's
             *     country id. The `LotteryPrizes` detail view does the same and also passes a sub-total of
             *     0, so it can only show 0.00. `Product::productTaxIncludeAmount` passes eight as well and
             *     has no caller (Phase 04's tax-shipping finding). All of these are pre-existing, display
             *     only, and reported rather than repaired -- see GAP-211 in the gap register.
             *
             * Both directions are toward what the merchant's own configuration says, and both reach
             * only stores that have saved tax options. Nothing seeds `tax_options`, so a store that
             * has never opened the tax settings form is unaffected in either direction -- pinned by
             * `CartTaxJurisdictionTest::test_a_store_with_no_tax_options_still_restricts_by_jurisdiction`.
             * Whether anyone reports from the old recorded figure is the owner's to confirm.
             */
            return $tax_price;
        }
        /*
         * P4-F070. THE `$taxValue` FLAG IS GONE WITH THE FALLBACK IT GATED.
         *
         * It was initialised once HERE, outside the loop, and never reset -- which is why the
         * fallback fired only for non-matching methods sorting BEFORE the first matching one,
         * and for all of them when nothing matched. Measured on a Damascus shopper against
         * [Aleppo 3% priority 1, Damascus 5% priority 2]: 8.00 charged where 5.00 is owed.
         * Reverse the two priorities and the same shopper is charged correctly, which is what
         * kept this invisible.
         */
        foreach ($taxs as $tax) {
            if ($flag) {
                if ($tax->country_id && $tax->state_id && $tax->city_id && ($country == $tax->country_id) && ($state_id == $tax->state_id) && ($city_id == $tax->city_id)) {
                    $amount = $tax->tax_rate * $sub_total / 100;
                    $tax_price += $amount;
                    continue;
                }
                if ($tax->country_id && $tax->state_id && !$tax->city_id && ($country == $tax->country_id) && ($state_id == $tax->state_id)) {
                    $amount = $tax->tax_rate * $sub_total / 100;
                    $tax_price += $amount;
                    continue;
                }
                if ($tax->country_id && !$tax->state_id && !$tax->city_id && ($country == $tax->country_id)) {
                    $amount = $tax->tax_rate * $sub_total / 100;
                    $tax_price += $amount;
                    continue;
                }
                if (!$tax->country_id && !$tax->state_id && !$tax->city_id) {
                    $amount = $tax->tax_rate * $sub_total / 100;
                    $tax_price += $amount;

                    continue;
                }
                /*
                 * P4-F070. THE FALLBACK THAT STOOD HERE CHARGED A NON-MATCHING METHOD ANYWAY,
                 * whenever `price_type` was anything other than `inclusive`: it tested the
                 * never-reset flag, and on false added this method's full rate.
                 *
                 * Falling past all four branches above means this method's own
                 * country/state/city do not describe this shopper, and there is nothing further
                 * to decide: the merchant restricted it and the shopper is outside it.
                 *
                 * `price_type` is validated only as `required`, with no `in:` rule, so this also
                 * fired for any unrecognised string a form sends -- pinned by
                 * `CartTaxJurisdictionTest::test_an_unrecognised_price_type_does_not_reopen_the_fallback`.
                 */
            } else {
                $amount = $tax->tax_rate * $sub_total / 100;
                $tax_price += $amount;
            }
        }
        return $tax_price;
    }

    /**
     * The line price AFTER the coupon, or the line price UNCHANGED when the coupon does not apply.
     *
     * =============================================================================================
     *  A REFUSED COUPON MADE THE LINE FREE.  OWNER DIRECTIVE: "do not defer any confirmed
     *  payment-total integrity defect, unauthorized discount, or incorrect gateway charge."
     * =============================================================================================
     *
     * THE DEFECT. This method answered **0** on three refusal paths -- per-user limit exceeded
     * (:1049), expiry or usage limit not satisfied (:1053), and a `fixed product discount` whose
     * applied/excluded arms match nothing, which simply fell through to the `$coupon_price = 0`
     * initialiser. Both callers read the answer as the DISCOUNTED LINE PRICE:
     *
     *     $coupon_apply_price = Cart::getCouponTotalAmount($coupon, $final_price, ...);
     *     $coupon_price += $final_price - $coupon_apply_price;   // the WHOLE line becomes the discount
     *     $final_price   = $coupon_apply_price;                  // ... and the line becomes FREE
     *
     * (`Api\ApiController::cart_list` :749-752 for a signed-in shopper, `Cart::cart_list_cookie`
     * :631-633 for a guest.) So a coupon the store had ALREADY EXHAUSTED, or one the shopper had
     * already used to their limit, or one restricted to products that are not in the basket, did
     * not refuse -- it zeroed the line.
     *
     * WHY IT IS MONEY AND NOT A DISPLAY BUG. `$final_price` feeds `total_sub_price`, which
     * `PaymentService::getProductStatus()` writes into `orders.final_price` for `cod` and
     * `bank_transfer`. For a `cod` order that column is THE CASH THE DRIVER COLLECTS. A crafted
     * checkout carrying an exhausted coupon code therefore reduced the cash collected, in the limit
     * to tax and delivery alone.
     *
     * THE FIX IS THE RETURN VALUE'S MEANING. "This coupon does not apply" and "this coupon makes
     * the line free" were the same answer, and they must not be: a legitimate 100%-off percentage
     * coupon computes 0 here, so the CALLER cannot tell the two apart and the correction has to be
     * made where the refusal is known. A refusal now returns `$subTotal` -- the line, undiscounted
     * -- so `$final_price - $coupon_apply_price` is zero and nothing is taken off.
     *
     * AND THE RESULT IS FLOORED AT ZERO. `flat` computes `$subTotal - $coupon->discount_amount`
     * with no floor, so a flat coupon larger than the line produced a NEGATIVE line price, which
     * the caller then added into the basket total as a credit. A coupon may reduce a line to
     * nothing; it may not pay the shopper.
     *
     * @param  mixed  $coupon    The resolved Coupon, already store-scoped by the caller.
     * @param  float|int|string  $subTotal  The line price before the coupon.
     * @return float|int  The line price after the coupon, never negative, never less than the
     *                    caller asked for when the coupon does not apply.
     */
    public static function getCouponTotalAmount($coupon, $subTotal, $productId, $categoryId)
    {
        // NOT 0. The initialiser is also the `fixed product discount` fall-through answer, and
        // "no arm matched" means no discount, not a free line.
        $coupon_price = $subTotal;

        if (!empty($coupon)) {
            /*
             * P4-F061 / GAP-132 -- THE SECOND ARITHMETIC'S OWN STATUS CHECK, and it needs one.
             *
             * `Coupon::findByExactCode()` now refuses an inactive coupon, which closes all eight
             * storefront lookups by CODE. This method is handed an ALREADY-RESOLVED model, so a
             * fix made only at the lookup does not reach it -- and this is the path the
             * `coupon_limit` family went wrong on in exactly that way (see
             * `test_the_cart_and_apply_coupon_agree_about_a_default_usage_limit`: the two
             * arithmetics disagreed, so the shopper was told "applied successfully" with a
             * discount of 0.00). Measured before the gate: `api/{slug}/cart-list` reported
             * `total_coupon_price` 50.00 for a coupon whose `status` was 0.
             *
             * `$subTotal`, NOT 0. The docblock above records why at length: a refusal that answers
             * 0 means "this line is free" to both callers, which is the zeroed-line defect
             * `RefusedCouponLinePriceTest` pins. Same answer as the three refusals below it.
             */
            if ((int) $coupon->status !== 1) {
                return $subTotal;
            }

            $coupon_count = $coupon->UsesCouponCount();
            $couponQuery = Coupon::query();
            $coupon_expiry_date = (clone $couponQuery)->where('id', $coupon->id)
                ->whereDate('coupon_expiry_date', '>=', date('Y-m-d'))
                // THE THIRD CALL SITE OF THIS GATE, and the one a census keyed on "the two sites
                // I had already read" missed. P1-ORD-01: `coupon_limit` defaults to 0, so
                // `0 > 0` read a brand-new coupon as exhausted. Fixing only ApiController's two
                // sites made the two coupon arithmetics DISAGREE -- apply_coupon accepted while
                // this path still refused, and because the free-shipping arm reports the CART's
                // figure the shopper was told "applied successfully" with a discount of 0.00.
                // Keyed on Coupon::applyUsageLimit so all three sites share one definition.
                ->tap(fn ($q) => Coupon::applyUsageLimit($q, $coupon_count))
                ->first();
            // USAGE LIMIT PER USER, counted on the principal. Same change and same reason as
            // ApiController::apply_coupon: the two coupon arithmetics must agree about the per-user
            // cap as well as the total one, or the preview and the charge disagree. The count was
            // keyed on `order_billing_details.email`, which the shopper posts. See
            // Coupon::usesByCustomer().
            $i = 0;

            if (auth('customers')->user()) {
                $i = Coupon::usesByCustomer($coupon, auth('customers')->user());
            }
            if (!empty($coupon->coupon_limit_user)) {
                if ($i  >= $coupon->coupon_limit_user) {
                    // THE SHOPPER HAS USED THIS COUPON TO THEIR LIMIT: no discount, not a free line.
                    return $subTotal;
                }
            }
            if (empty($coupon_expiry_date)) {
                // EXPIRED, OR THE STORE'S TOTAL USAGE LIMIT IS SPENT: same answer, same reason.
                return $subTotal;
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

        // A COUPON MAY REDUCE A LINE TO NOTHING; IT MAY NOT PAY THE SHOPPER. `flat` subtracts a
        // fixed amount with no floor, so a 50.00 coupon on a 20.00 line returned -30.00 and the
        // caller added that into the basket as a credit.
        return max(0, $coupon_price);
    }

    /**
     * This store's product, or null — the payable-path twin of
     * `Api\ApiController::productOfCartLineStore()`.
     *
     * The NULL rule is the cart writers' rule, not a new one: a product whose own `store_id` is
     * NULL (or 0, which `config/tenancy.php` records as a real legacy value) is ACCEPTED, and only
     * a PROVABLE mismatch is refused. Refusing legacy rows would stop pricing a merchant's own
     * catalogue on the checkout path, which is worse than the defect being closed.
     *
     * `$storeId` null means the caller has not been updated; behaviour is then exactly as before.
     */
    private static function productOfStoreForShipping($productId, $storeId)
    {
        if (empty($productId)) {
            return null;
        }

        $product = Product::find($productId);

        if ($product === null || empty($storeId)) {
            return $product;
        }

        if (! empty($product->store_id) && (int) $product->store_id !== (int) $storeId) {
            Log::warning('flat-rate shipping refused a cart line naming another store\'s product', [
                'store_id' => $storeId,
                'product_store_id' => $product->store_id,
                'product_id' => $productId,
            ]);

            return null;
        }

        return $product;
    }

    /**
     * A shipping class within this store. THREE OUTCOMES, for the reason the quote twin records:
     *
     *   null   no class set, or no such class anywhere -> caller charges `product_no_cost`
     *   false  the class exists but is not this store's -> caller charges NOTHING (+0)
     *   model  the class, which this store owns        -> caller charges its bucket
     *
     * Collapsing `false` into `null` turns a cross-tenant under-charge into a deterministic
     * OVER-charge, which is the regression the review caught in the quote twin's first fix. The
     * existence probe reads no columns.
     */
    private static function shippingClassOfStoreForShipping($shippingId, $storeId)
    {
        if (empty($shippingId)) {
            return null;
        }

        if (empty($storeId)) {
            return Shipping::find($shippingId);   // caller not updated: legacy behaviour
        }

        $class = Shipping::where('id', $shippingId)
            ->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->orWhereNull('store_id');
            })
            ->first();

        if ($class !== null) {
            return $class;
        }

        return Shipping::where('id', $shippingId)->exists() ? false : null;
    }

    /**
     * F283 FOLLOW-UP — THIS IS THE TWIN THAT CHARGES MONEY, AND IT WAS LEFT UNSCOPED.
     *
     * ═════════════════════════════════════════════════════════════════════════════════════════
     * WHY THIS CHANGED, AND WHY IT MATTERS MORE THAN THE ONE F283 ORIGINALLY FIXED
     * ═════════════════════════════════════════════════════════════════════════════════════════
     *
     * F283 scoped `Api\ApiController::calculateFlaterate()` — the QUOTE path behind
     * `POST api/{slug}/delivery-charge`. **The order writers do not call that method.** They call
     * this one, through `CartController::get_shipping_method()` (`:631`, `:635`), and its result
     * becomes `$order_array['delivery_charge']` and from there **`orders.delivery_price` and
     * `orders.final_price`** — a persisted payable, not a displayed quote.
     *
     * Found by the mandated independent review of the F283 change, which put it plainly: the
     * diff's own reachability argument read as though `delivery_charge` were the surface, and it
     * is not the surface that charges money.
     *
     * Four unscoped reads, the same shape as the quote twin: `Product::find($productId)` with no
     * null check at all, and three `Shipping::find()`. `Product` carries `StoreScope` but is
     * ABSENT from `config/tenancy.php`'s `scoped_models`, so no predicate is added for any
     * principal; `Shipping` is excluded outright.
     *
     * ═════════════════════════════════════════════════════════════════════════════════════════
     * WHAT IT LEAKS, STATED EXACTLY — IT IS NOT THE SAME AS THE QUOTE
     * ═════════════════════════════════════════════════════════════════════════════════════════
     *
     * This method never reads `sale_price`, so **no catalogue price crosses the boundary here**.
     * What it reproduces is the OTHER half of F283's measurement: the shipping mis-bucketing. A
     * foreign product's class matches no key in this store's `product_cost` table, so the
     * per-class sum stays zero and the line is charged nothing — and here that wrong figure is
     * PERSISTED on the order rather than returned as a quote.
     *
     * ═════════════════════════════════════════════════════════════════════════════════════════
     * THE STORE ARRIVES AS A PARAMETER, because `$productList` cannot supply it
     * ═════════════════════════════════════════════════════════════════════════════════════════
     *
     * `$productList` is `$response->data->product_list` from `ApiController::cart_list` — a
     * decoded payload of per-line display fields, not `Cart` models, and it carries no
     * `store_id`. So the tenant is threaded in from the caller, where it is already
     * slug-resolved (`getStore($slug)`), never taken from a request field.
     *
     * The parameter is OPTIONAL so no caller breaks. When it is absent the behaviour is exactly
     * what it was before this change — unscoped — and that is deliberate rather than an oversight:
     * silently refusing every line for a caller that has not been updated would turn a tenancy
     * hardening into an outage on a payable path. Both first-party callers pass it, and
     * `ForeignProductShippingQuoteTest` pins that they do.
     *
     * @param  int|string|null  $storeId  the slug-resolved store, or null to preserve legacy behaviour
     */
    public static function calculateFlatRateShippingAmount($shippingMethods, $shippingCost, $productList, $storeId = null)
    {
        $cost_totle =  $shippingCost;
        $price = 0;
        foreach ($productList as $key => $Product) {
            $productId = $Product->product_id;
            $product_data = self::productOfStoreForShipping($productId, $storeId);

            /*
             * A LINE THAT IS NOT THIS STORE'S CONTRIBUTES NOTHING. Note this also fixes a
             * pre-existing crash: the old code did `Product::find($productId)` and dereferenced
             * `$product_data->variant_product` with NO null check, so a cart line naming a DELETED
             * product was a 500 on the checkout path.
             */
            if ($product_data === null) {
                continue;
            }

            if ($product_data->variant_product == 0) {
                if ($shippingMethods['product_cost'] != null) {
                    $shippingClass = self::shippingClassOfStoreForShipping($product_data->shipping_id, $storeId);
                    $value = $shippingMethods['product_cost'];
                    $product_cost = json_decode($value, true);
                    $most_expensive_shipping_price = max($product_cost['product_cost']);
                    $most_expensive_shipping_price_id = array_search($most_expensive_shipping_price, $product_cost['product_cost']);
                    
                    /* `=== null` is "no class"; `false` is "not this store's" and adds nothing. */
                    if ($shippingClass === null) {
                        $price  += $product_cost['product_no_cost'] ?? 0;
                    } elseif ($shippingClass === false) {
                        $price  += 0;
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
                        $shippingClass = self::shippingClassOfStoreForShipping($stock->shipping, $storeId);

                        if ($stock->shipping == 'same_as_parent') {
                            $shipping = self::shippingClassOfStoreForShipping($product_data->shipping_id, $storeId);
                            if ($shipping === null) {
                                $price  += $product_cost['product_no_cost'] ?? 0;
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
