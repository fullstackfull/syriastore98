<?php

namespace App\Http\Controllers;

use App\Models\{ActivityLog, ProductAttributeOption, ProductVariant, Store};
use App\Models\Utility;
use App\Models\Cart;
use App\Models\{Customer, Product, User};
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\ShippingZone;
use App\Models\TaxOption;
use App\Models\DeliveryAddress;
use App\Models\TaxMethod;
use App\Models\Shipping;
use App\Models\ShippingMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use App\Http\Controllers\Api\ApiController;
use Session;
use Illuminate\Support\Facades\Crypt;
use App\DataTables\AbandonCartDataTable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    public function cart_list_sidebar(Request $request, $storeSlug)
    {
        $store = getStore($storeSlug);
        if (empty($store)) {
            $return['status'] = false;
            $return['message'] = __('Something went wrong');
            $return['sub_total'] = 0;
            return response()->json($return);
        }
        $slug  = $store->slug;
        $admin = User::find($store->created_by);
        $plan  = Plan::find($admin->plan_id);
        if (!$request->shipping_price) {
            $shippingMethod = ShippingMethod::find($request->method_id);

            if ($shippingMethod) {
                $request['shipping_final_price'] = $shippingMethod->cost;
            } else {
                $request['shipping_final_price'] = 0;
            }
        } else {
            $request['shipping_final_price'] = $request->shipping_price ?? 0;
        }

        if (auth('customers')->guest()) {
            $response = Cart::cart_list_cookie($request->all(), $store->id);
            $response = json_decode(json_encode($response));
        } else {
            $request->merge(['customer_id' => auth('customers')->user()->id, 'store_id' => $store->id, 'slug' => $slug,'status' => true, 'order_type' => $request->order_type ?? '']);

            $api = new ApiController();
            $data = $api->cart_list($request, $storeSlug);
            $response = $data->getData();
        }

        $return['status'] = $response->status;
        $return['message'] = $response->message;
        $return['sub_total'] = 0;
        $tax_option = TaxOption::where('store_id', $store->id)
            ->pluck('value', 'name')->toArray();
        if ($response->status == 1) {
            $default_checkout_btn = 'off';
            
            $return['cart_total_product'] = $response->data->cart_total_product;
            $return['html'] = view('front_end.pages.cart-list-sidebar', compact('slug', 'response', 'tax_option', 'store'))->render();
            
            $return['checkout_html'] = view('front_end.pages.cart-list', compact('slug', 'response', 'tax_option', 'store'))->render();
            $return['checkout_html_2'] = view('front_end.pages.checkout-cart-list', compact('slug', 'response', 'tax_option', 'store', 'default_checkout_btn', 'plan'))->render();
            //$return['checkout_html_products'] = view('front_end.pages.checkout-product-list', compact('response', 'currency', 'currency_name', 'store'))->render();
            //$return['checkout_amounts'] = view('front_end.pages.checkout-amount', compact('response', 'currency', 'currency_name', 'store'))->render();
            if (module_is_active('SkipCart')) {
                $enable_skip_cart = Utility::GetValueByName('enable_skip_cart', $store->id);
                $checkout_setting = Utility::GetValueByName('skip_cart_checkout_setting', $store->id);
                if ((isset($checkout_setting) && $checkout_setting == 'sticky_cart') && $enable_skip_cart == 'on') {
                    //$return['sticky_product_list'] = view('skip-cart::theme.sticky-product-list', compact('response', 'currency', 'currency_name', 'store', 'slug'))->render();
                }
            }
            $return['sub_total'] =  $response->data->final_price ?? ($response->data->sub_total ?? 0);
        }
        return response()->json($return);
    }

    public function cart_remove(Request $request)
    {
        $slug = !empty($request->route('storeSlug')) ? $request->route('storeSlug') : '';
        $store = getStore($slug);
        if (auth('customers')->guest()) {
            $Carts = Cookie::get('cart');
            $cart = Cart::where('cookie_session_id', $Carts)->where('product_id',$request->product_id)->where('variant_id',$request->variant_id)->delete();
            Cookie::queue('cart', $Carts, 1440);
        } else {
            $cart = Cart::where('id', $request->cart_id)->first();

            // activity log
            if ($cart && $store) {
                $ActivityLog = new ActivityLog();
                $ActivityLog->customer_id = $cart->customer_id ?? null;
                $ActivityLog->log_type = 'remove to cart';
                $ActivityLog->remark = json_encode(
                    [
                        'product' => $cart->product_id,
                        'variant' => $cart->variant_id,
                    ]
                );
                $ActivityLog->store_id = $store->id;
                $ActivityLog->save();
                $cart->delete();
            }
        }

        $return['status'] = 1;
        return response()->json($return);
    }

    public function cartClear(Request $request)
    {
        $slug = !empty($request->route('storeSlug')) ? $request->route('storeSlug') : '';
        
        if (auth('customers')->guest()) {
            $cartId = Cookie::get('cart');
            $cart = Cart::where('cookie_session_id', $cartId)->delete();
            Cookie::queue('cart', $cartId, 1440);
        } else {
            $carts = Cart::where('customer_id', auth('customers')->id())->delete();
        }

        $return['status'] = 1;
        return response()->json($return);
    }

    public function change_cart(Request $request, $storeSlug)
    {
        $slug = !empty($storeSlug) ? $storeSlug : '';
        $store = getStore($slug);
        
        $cart_id = $request->cart_id;
        $quantity_type = $request->quantity_type;
        if (auth('customers')->guest()) {
            $Carts = Cookie::get('cart');
            

            $param = [
                'product_id' => $request->product_id,
                'variant_id' => $request->variant_id,
                'quantity_type' => $quantity_type,
            ];

            $request->merge($param);

            $response = Cart::cart_qty_cookie($request);
            return response()->json($response);
        } else {
            $Cart = Cart::find($cart_id);

            $param = [
                'customer_id' => $Cart->customer_id,
                'product_id' => $Cart->product_id,
                'variant_id' => $Cart->variant_id,
                'quantity_type' => $quantity_type,
                'slug' => $slug,

            ];

            $request->merge($param);

            $api = new ApiController();
            $data = $api->cart_qty($request, $slug);
            $response = $data->getData();

            return response()->json($response);
        }
    }

    public function product_cartlist(Request $request, $slug)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        $customer = auth('customers')->user();
        $customer_id = $customer->id ?? 0;

        $product_id = $request->product_id;
        $variant_id = $request->variant_id ?? 0;

        // P01-SEC-14 F1. THE SOURCE OF THE NEGATIVE CART LINE, and the one writer with no
        // validation at all. `$request->qty ?? 0` took whatever was posted to the storefront web
        // route `{storeSlug?}/product_cart` (routes/customer.php) and wrote it to `carts.qty`
        // under the session customer's own id and this store -- exactly the rows the API order
        // writers price. MEASURED through those writers with a -5 row present: a basket worth
        // 1000.00 recorded `product_price` and `final_price` of 750.0, and the discounted
        // product's stock went UP from 20 to 25.
        //
        // The three order handlers and payment_sheet now refuse such a basket, but closing it
        // where it is written is what stops the row existing. An adversarial review of
        // P01-SEC-14 E traced this path; the API's own entry point (addtocart) was validated in
        // that commit and this one was missed.
        //
        // REFUSES rather than clamps, and nothing legitimate is refused: the storefront's own JS
        // already clamps to 1 before posting (public/assets/js/front-theme.js:275-278, and the
        // ProductQuickView module's view does the same), "remove" is a different endpoint with a
        // `quantity_type` of its own, and there is no weighed/decimal quantity anywhere in the
        // platform. So a non-positive value reaching here is not a client doing its job.
        $qty = $request->qty ?? 0;

        if (! is_numeric($qty) || (int) $qty < 1 || (float) $qty != (int) $qty) {
            Log::warning('product_cart: refused a non-positive or non-integer quantity', [
                'store_id' => $store->id ?? null,
                'customer_id' => $customer_id,
                'qty' => is_scalar($qty) ? (string) $qty : gettype($qty),
            ]);

            return Utility::error(['message' => __('Please choose a quantity of at least 1.')]);
        }

        $qty = (int) $qty;
        $size_data = '';
        $final_price = 0;
        $cart_count = 0;

        if (module_is_active('SizeGuideline')) {
            $size_data = $request->size_data ?? '';
        }
        $settings = Utility::Setting($store->id);

        $product = Product::find($product_id);
        if (empty($product)) {
            return Utility::error(['message' => __('Product not found.')]);
        }

        if (!$customer) {
            if (!empty($variant_id) || $variant_id != 0) {
                $ProductVariant = ProductVariant::where('id', $variant_id)
                    ->where('product_id', $product_id)
                    ->first();
                if (empty($ProductVariant)) {
                    return Utility::error(['message' => __('Product not found.')]);
                }
                $product->setAttribute('variantId', $variant_id);
                $variationOptions = explode(',', $ProductVariant->variation_option);
                $variant_enable = in_array('enabled', $variationOptions);
                if($variant_enable == false){
                    return Utility::error(['message' => __('Product variant is not available.')]);
                }
                $variant_stock_status = in_array('manage_stock', $variationOptions);
                if ($variant_stock_status == true) {
                    $stock = !empty($ProductVariant->stock) ? $ProductVariant->stock : 0;
                    if ((isset($settings['out_of_stock_threshold']) && ($stock <= $settings['out_of_stock_threshold']) && $ProductVariant->stock_order_status == 'not_allow') || ($ProductVariant->stock_status == 'out_of_stock')) {
                        return Utility::error(['message' => __('Product has out of stock.')]);
                    }
                } else {
                    $stock = !empty($ProductVariant->stock) ? $ProductVariant->stock : ($product->product_stock ?? 0);
                    if (isset($settings['out_of_stock_threshold']) && ($stock <= $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                        return Utility::error(['message' => __('Product has out of stock.')]);
                    }
                }
    
                $final_price = floatval($product->final_price) * floatval($qty);
            } else {
                if (module_is_active('SizeGuideline')) {
                    if ($product->variant_product == 1) {
                        $ProductVariant = ProductVariant::find($variant_id);
                        if (empty($ProductVariant)) {
                            return Utility::error(['message' => __('Product not found.')]);
                        }
                        $product->setAttribute('variantId', $variant_id);
                        $var_stock = !empty($ProductVariant->stock) ? $ProductVariant->stock : ($product->product_stock ?? 0);
                        if(!$size_data)
                        {
                            if (empty($variant_id) || $variant_id == 0) {
                                return Utility::error(['message' => __('Please Select a variant in a product.')]);
                            } else if ((isset($settings['out_of_stock_threshold']) && ($var_stock <= $settings['out_of_stock_threshold']) && $ProductVariant->stock_order_status == 'not_allow') || ($ProductVariant->stock_status == 'out_of_stock')) {
                                return Utility::error(['message' => __('Product has out of stock.')]);
                            }
                        }
                    } else {
                        if ((isset($settings['out_of_stock_threshold']) && ($product->product_stock <= $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) {
                            return Utility::error(['message' => __('Product has out of stock.')]);
                        }
                    }
                    if($size_data)
                    {
                        $request_value = [
                            'qty' => $qty,
                            'varint' => $variant_id ?? '',
                            'product_id' => $product->id,
                            'size_data' => $size_data ?? '',
                        ];
                        $size_price = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($product, $request_value, $store);
                        $final_price = floatval($size_price['final_price'] ?? $product->final_price) * floatval($qty);
                    }else{
                        $final_price = floatval($product->final_price) * floatval($qty);
                    }
                }else{
                    if ($product->variant_product == 1) {
                        if (empty($variant_id) || $variant_id == 0) {
                            return Utility::error(['message' => __('Please Select a variant in a product.')]);
                        }
                    } else {
                        if ((isset($settings['out_of_stock_threshold']) && ($product->product_stock <= $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) {
                            return Utility::error(['message' => __('Product has out of stock.')]);
                        }
                    }
                    $final_price = floatval($product->final_price) * floatval($qty);
                }
            }

            $session_id = session()->getId();
            $cart = Cart::where('cookie_session_id', $session_id)
                ->where('product_id', $request->product_id)
                ->where('variant_id', $variant_id)
                ->where('store_id', $store->id)
                ->first();
                
        } else{

            if ($product->variant_product == 1) {
                $ProductVariant = ProductVariant::find($request->variant_id);
                if (empty($ProductVariant)) {
                    return Utility::error(['message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                }
                $variant_id = isset($ProductVariant->id) ? $ProductVariant->id : $request->variant_id;

                $product->setAttribute('variantId', $variant_id);
                $variationOptions = explode(',', $ProductVariant->variation_option);
                $variant_enable = in_array('enabled', $variationOptions);
                if($variant_enable == false){
                    return Utility::error(['message' => __('Product variant is not available.')]);
                }
                $variant_stock_status = in_array('manage_stock', $variationOptions);
                if ($variant_stock_status == true) {
                    $stock = !empty($ProductVariant->stock) ? $ProductVariant->stock : 0;
                    if ((isset($settings['out_of_stock_threshold']) && ($stock <= $settings['out_of_stock_threshold']) && $ProductVariant->stock_order_status == 'not_allow') || ($ProductVariant->stock_status == 'out_of_stock')) {
                        return Utility::error(['message' => __('Product has out of stock.')]);
                    }
                } else {
                    $stock = !empty($ProductVariant->stock) ? $ProductVariant->stock : ($product->product_stock ?? 0);
                    if (isset($settings['out_of_stock_threshold']) && ($stock <= $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                        return Utility::error(['message' => __('Product has out of stock.')]);
                    }
                }
            } else {
                if(module_is_active('PreOrder') && isset($customer)){
                    $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                    $pre_order_setting = \Workdo\PreOrder\app\Models\PreOrderSetting::where('product_id', $product->id)->where('status', 1)->where('store_id', $store->id)->first();
                    if(isset($pre_order_setting) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                        if ($pre_order_setting->qty <= 0) {
                            return Utility::error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                        }
                    }
                }else{
                    if (($product->product_stock <= $settings['out_of_stock_threshold'] && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) {
                        return Utility::error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                    }
                }
            }
            $final_price = floatval($product->final_price) * floatval($request->qty);

            if (module_is_active('SizeGuideline') && isset($request->size_data)) {
                $cart = Cart::where('customer_id', $customer_id)
                    ->where('product_id', $request->product_id)
                    ->where('variant_id', $variant_id)
                    ->where('size_data', $request->size_data)
                    ->where('store_id', $store->id)
                    ->first();
            }else{
                $cart = Cart::where('customer_id', $customer_id)
                    ->where('product_id', $request->product_id)
                    ->where('variant_id', $variant_id)
                    ->where('store_id', $store->id)
                    ->first();
            }

            // activity log
            $ActivityLog = new ActivityLog();
            $ActivityLog->customer_id = $customer_id;
            $ActivityLog->log_type = 'add to cart';
            $ActivityLog->remark = json_encode(
                [
                    'product' => $request->product_id,
                    'variant' => $variant_id,
                ]
            );
            $ActivityLog->store_id = $store->id;
            $ActivityLog->save();
        }

        if (empty($cart)) {
            $cart = new Cart();
        } else {
            $final_price += $cart->price;
            $qty = $cart->qty + $qty;
        }

        $cart->customer_id    = $customer_id;
        $cart->product_id     = $request->product_id;
        $cart->variant_id     = !empty($variant_id) ? $variant_id : 0;
        $cart->qty            = $qty;
        $cart->price          = $final_price;
        if ($customer && module_is_active('SizeGuideline') && isset($request->size_data)) {
            $cart->size_data  = !empty($request->size_data) ? $request->size_data : '';
        }
        if ($customer && module_is_active('PreOrder') && isset($request->order_type)) {
            $cart->order_type = !empty($request->order_type) ? $request->order_type : '';
        }
        if (!$customer){
            $cart->cookie_session_id   = $session_id;
        }
        $cart->store_id       = $store->id;
        $cart->save();

        if (!$customer) {
            $cart_count = Cart::where('cookie_session_id', $session_id)->count();
            Cookie::queue('cart', $session_id, 1440);
        } else {
            $cart_count = Cart::where('customer_id', $customer_id)->where('store_id', $store->id)->count();
        }
        if (!empty($cart_count)) {
            return Utility::success(['message' => __(':product added successfully.', ['product' => $product->name]), 'count' => $cart_count]);
        } else {
            return Utility::error(['message' => __('Cart is empty.'), 'count' => $cart_count]);
        }
    }

    public function get_shipping_method(Request $request, $slug)
    {
        $store = getStore($slug);
        /*
         * P01-COMMERCE-SEC-01. STORE-SCOPED. This was `ShippingMethod::find($request->method_id)`
         * -- no store predicate, and ShippingMethod is NOT in config/tenancy.php's scoped_models,
         * so the global scope adds none either. `method_id` is caller-supplied at BOTH legs of a
         * checkout, so any shopper could apply ANY tenant's shipping method, at that tenant's
         * cost, to their own order.
         *
         * `cost` is a merchant-controlled string column written with no validation by
         * ShippingMethodController::update(), so a merchant can publish a NEGATIVE one. MEASURED,
         * before this change: posting only `method_id=<store B's 'Local pickup', cost '-50'>` to
         * {slug}/process-order produced a SERVER-COMPUTED checkout state carrying
         * shipping_final_price '-50' and cartlist_final_price 50.0 -- the payable -- and the
         * following gateway return, posting no money fields at all, wrote an order with
         * product_price 100.0 and final_price 50.0. For AuthorizeNet the same figure is the amount
         * captured from the card (GuardsGatewayReturn::serverPayableAmount()).
         *
         * This is why the gateway-return guards could not see it: the state really was the
         * server's. The server had computed a poisoned figure one leg earlier.
         *
         * A NULL store_id is still accepted. The column is nullable and legacy rows may not carry
         * it; refusing those would hide a merchant's own shipping method, which is a broken
         * storefront rather than a fix. What is refused is a method belonging to a DIFFERENT store.
         */
        $shippingMethods = ShippingMethod::where('id', $request->method_id)
            ->where(function ($q) use ($store) {
                $q->where('store_id', $store->id ?? 0)->orWhereNull('store_id');
            })
            ->first();
        if (auth('customers')->guest()) {
            $response = Cart::cart_list_cookie($request->all(), $store->id);
            $response = json_decode(json_encode($response));
        } else {
            $address = DeliveryAddress::find($request->billing_address_id);
            if ($address) {
                $parms['billing_info']['delivery_country'] = $address->country_id;
                $parms['billing_info']['delivery_state'] = $address->state_id;
                $parms['billing_info']['delivery_city'] = $address->city_id;
                $request->merge($parms);
            }
            $request->merge(['customer_id' => auth('customers')->user()->id, 'store_id' => $store->id, 'slug' => $slug]);
            $api = new ApiController();
            $data = $api->cart_list($request, $slug);
            $response = $data->getData();
        }
        \Session::put('shipping_method', '1');
        if (!empty($shippingMethods)) {
            $shipp_name = $shippingMethods->method_name;


if ($shipp_name == 'Delivery Syria') {
    $totalWeight = 0;
foreach ($response->data->product_list as $item) {
    $product = Product::find($item->product_id);
    
    // التعديل هنا: استخدام 'product_weight' بدلاً من 'weight'
    $itemWeight = ($product && $product->product_weight > 0) ? $product->product_weight : 1;
    
    // حساب الوزن الإجمالي
    $totalWeight += ($itemWeight * $item->qty);
}
\Log::info("DEBUG SHIPPING: Weight=$totalWeight, Cost={$shippingMethods->cost}");
    // حساب التكلفة النهائية وتسميتها لتطابق ما يستخدمه النظام
    $shipping_final_price = $totalWeight * $shippingMethods->cost;
    
    // تعريف المتغير الذي يسبب الخطأ في السطر 469 لضمان عدم حدوث Undefined variable
    $cost_totle = $shipping_final_price; 
    
    $total_shipping_price = $shipping_final_price;
}
            elseif ($shipp_name == 'Flat Rate') {
                if ($shippingMethods->calculation_type == 1) {
                    $cost_totle = Cart::calculateFlatRateShippingAmount($shippingMethods, $shippingMethods->cost, $response->data->product_list);
                    $shipping_final_price =  $cost_totle;
                    $total_shipping_price = $cost_totle;
                } else {
                    $cost_totle = Cart::calculateFlatRateShippingAmount($shippingMethods, $shippingMethods->cost, $response->data->product_list);
                    $shipping_final_price =  $cost_totle;
                    $total_shipping_price = $cost_totle;
                }
            } elseif ($shipp_name == 'Local pickup') {
                $cost_totle = $shipping_final_price =  $shippingMethods->cost;
                $total_shipping_price = $shipping_final_price;
            } else {
                $cost_totle = $shipping_final_price =  0;

                $total_shipping_price = $shipping_final_price;
            }

            /*
             * P01-COMMERCE-SEC-01. A SHIPPING CHARGE IS NEVER NEGATIVE.
             *
             * Every branch above multiplies or copies `$shippingMethods->cost`, which is a
             * merchant-controlled string column written with no validation. A negative one does
             * not merely zero the shipping -- it is SUBTRACTED from the payable, because
             * PaymentController::prepareFinalRequestData() folds the delivery price into
             * `cartlist_final_price` and Cart::cart_list_cookie() adds it into `total_sub_price`.
             * So a negative charge is a discount on the goods, and on the card capture.
             *
             * The floor is applied here rather than at the six call sites because this method is
             * the single chokepoint they all share: PaymentController::processShipping() at
             * initiation, the five gateway returns, and PaymentController::getProductStatus().
             * It cannot refuse a legitimate charge, since no legitimate charge is below zero.
             */
            foreach (['cost_totle', 'shipping_final_price', 'total_shipping_price'] as $moneyVar) {
                if (isset($$moneyVar) && is_numeric($$moneyVar) && $$moneyVar < 0) {
                    $$moneyVar = 0;
                }
            }

            $product_digital_id = $product_digital_not_id = [];
            foreach ($response->data->product_list as $item) {
                $products_datas = Product::find($item->product_id);
                if ($products_datas->product_type == 'digital') {
                    $product_digital_id[] = $products_datas->id;
                } else {
                    $product_digital_not_id[] = $products_datas->id;
                }
            }

            if (count($product_digital_id) > 0 && count($product_digital_not_id) == 0) {
                $total_shipping_price = 0;
                $shipping_final_price = 0;
            }
            $price = $cost_totle;
            $total_price = $total_shipping_price;

            if (module_is_active('FreeShippingPopup')) {
                $admin          = User::find($store->created_by);
                $plan           = Plan::find($admin->plan_id);
                $adminSetting   = getAdminAllSetting($store->created_by, $store->id);
                if (isset($plan->modules) && strpos($plan->modules, 'FreeShippingPopup') !== false) {
                    if (isset($adminSetting['free_shipping_popup_enabled']) && $adminSetting['free_shipping_popup_enabled'] == 'on' && isset($adminSetting['free_shipping_apply_price']) && ($response->data->total_sub_price ?? 0) >= $adminSetting['free_shipping_apply_price']) {
                        $return['shipping_final_price'] = 0;
                        $return['shipping_total_price'] = currency_format_with_sym(($response->data->total_sub_price ?? 0), $store->id);
                        $return['total_sub_price'] = ($response->data->total_sub_price ?? 0);
                    } else {
                        $return['shipping_final_price'] = $shipping_final_price ?? 0;
                        $return['shipping_total_price'] = currency_format_with_sym(($response->data->total_sub_price ?? 0) + $total_shipping_price, $store->id);
                        $return['total_sub_price'] = ($response->data->total_sub_price ?? 0) + $shipping_final_price;
                    }
                } else {
                    $return['shipping_final_price'] = $shipping_final_price ?? 0;
                    $return['shipping_total_price'] = currency_format_with_sym(($response->data->total_sub_price ?? 0) + $total_shipping_price, $store->id);
                    $return['total_sub_price'] = ($response->data->total_sub_price ?? 0) + $shipping_final_price;
                }
            } else {
                $return['shipping_final_price'] = $shipping_final_price ?? 0;
                $return['shipping_total_price'] = currency_format_with_sym(($response->data->total_sub_price ?? 0) + $total_shipping_price, $store->id);
                $return['total_sub_price'] = ($response->data->total_sub_price ?? 0) + $shipping_final_price;
            }

            $return['cart_total_product'] = $response->data->cart_total_product ?? null;
            $return['cart_total_qty'] = $response->data->cart_total_qty ?? null;
            $return['original_price'] = $response->data->original_price ?? 0;
            // $return['total_final_price'] = $response->data->total_final_price ?? 0;
            $return['total_final_price'] = $total_shipping_price ?? 0;
            $return['final_price'] = $response->data->final_price ?? 0;
            $return['total_coupon_price'] = $response->data->total_coupon_price ?? 0;
            $return['total_coupon_price_with_currency'] = currency_format_with_sym(($response->data->total_coupon_price ?? 0), $store->id);
            $return['tax_id_value'] = $response->data->tax_id ?? null;
            $return['final_tax_price'] = $response->data->total_tax_price ?? 0;
            $return['tax_price'] =($response->data->total_tax_price ?? 0);
            // $return['shipping_final_price'] = currency_format_with_sym(($response->data->total_final_price ?? 0), $store->id);
            $return['shipping_final_price'] = currency_format_with_sym(($total_shipping_price ?? 0), $store->id);
            $return['final_tax_price_with_currency'] = currency_format_with_sym(($response->data->total_tax_price ?? 0), $store->id);
            $return['sub_total'] = currency_format_with_sym(($response->data->total_final_price ?? 0), $store->id);
            $return['message'] = 'Add Shipping success';
            return response()->json($return);
        } else {
            $product_digital_id = $product_digital_not_id = [];
            foreach ($response->data->product_list as $item) {
                $products_datas = Product::find($item->product_id);
                if ($products_datas->product_type == 'digital') {
                    $product_digital_id[] = $products_datas->id;
                } else {
                    $product_digital_not_id[] = $products_datas->id;
                }
            }

            if (count($product_digital_id) > 0 && count($product_digital_not_id) == 0) {
                $total_shipping_price = 0;
                $shipping_final_price = 0;
            }
            $return['shipping_final_price'] = $shipping_final_price ?? 0;
            $return['cart_total_product'] = $response->data->cart_total_product ?? null;
            $return['cart_total_qty'] = $response->data->cart_total_qty ?? null;
            $return['original_price'] = $response->data->original_price ?? 0;
            // $return['total_final_price'] = $response->data->total_final_price ?? 0;
            $return['total_final_price'] = $total_shipping_price ?? 0;
            $return['final_price'] = $response->data->final_price ?? 0;
            $return['total_coupon_price'] = $response->data->total_coupon_price ?? 0;
            $return['total_coupon_price_with_currency'] = currency_format_with_sym(($response->data->total_coupon_price ?? 0), $store->id);
            $return['total_sub_price'] = $response->data->total_sub_price ?? 0;
            $return['tax_id_value'] = $response->data->total_tax_id ?? null;
            $return['shipping_total_price'] = currency_format_with_sym(($response->data->total_sub_price ?? 0), $store->id);
            $return['final_tax_price'] = $response->data->total_tax_price ?? 0;
            $return['tax_price'] =($response->data->total_tax_price ?? 0);
            // $return['shipping_final_price'] = currency_format_with_sym(($response->data->total_final_price ?? 0), $store->id);
            $return['shipping_final_price'] = currency_format_with_sym(($total_shipping_price ?? 0), $store->id);
            $return['final_tax_price_with_currency'] = currency_format_with_sym(($response->data->total_tax_price ?? 0), $store->id);
            $return['sub_total'] = currency_format_with_sym(($response->data->total_final_price ?? 0), $store->id);
            $return['message'] = __('Add Shipping success');
            return response()->json($return);
        }
    }

    public function get_shipping_data(Request $request, $slug)
    {
        $totalCouponAmount = $request['total_coupon_amount'];
        $Products = $request['product_id'];
        $Product = Product::find($Products);

        $store = getStore($slug);
        $CURRENCY = \App\Models\Utility::GetValueByName('defult_currancy_symbol', $store->id);
        $code = trim($request->coupon_code);
        // P01-SEC-14 C4: portable exact-code match. See Coupon::findByExactCode.
        $coupon = Coupon::findByExactCode($code, fn ($query) => $query->where('store_id', $store->id));
        $user = User::where('id', $store->created_by)->first();
        if ($user) {
            $plan = Plan::find($user->plan_id);
        }
        if ($plan->shipping_method == 'on') {
            if (auth('customers')->user()) {
                $request->merge(['customer_id' => auth('customers')->user()->id, 'store_id' => $store->id, 'slug' => $slug]);
                $api = new ApiController();
                $data = $api->cart_list($request, $slug);
                $response = $data->getData();
                $sub_total = $response->data->sub_total;

                $Delivery_Address = DeliveryAddress::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->first();

                if ($Delivery_Address == "") {
                    $country = $request->countryId;
                    $state_id = $request->stateId;

                    $Shipping_zone = ShippingZone::where('store_id', $store->id)->where('country_id', $country)->where('state_id', $state_id)->first();
                } else {
                    $User_address = $request['address_id'] ?? $request['billing_addres_id'];
                    $Delivery_Address = DeliveryAddress::where('id', $User_address)->where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->first();
                    $country = !empty($Delivery_Address->country_id) ? $Delivery_Address->country_id : '';
                    $state_id = !empty($Delivery_Address->state_id) ? $Delivery_Address->state_id : '';

                    $Address = DeliveryAddress::find($User_address);
                    $Shipping_zone = ShippingZone::where('store_id', $store->id)->where('country_id', $country)->where('state_id', $state_id)->first();
                }
                if(empty($Shipping_zone)){
                    $Shipping_zone = ShippingZone::where('store_id', $store->id)->where('country_id', $country)->whereNull('state_id')->first();
                }
                $shipping_requires = ShippingMethod::freeShipping();
                if (!empty($Shipping_zone)) {
                    $methods = ShippingMethod::where('zone_id', $Shipping_zone->id)->where('store_id', $store->id)->get();
                    $shippingMethods = [];
                    $freeShippingMethod = null;
                    foreach ($methods as $method) {
                        if ($method->shipping_requires == '1' || $method->shipping_requires == '3' || $method->shipping_requires == '4') {
                            if ($method->method_name == "Free shipping") {
                                if ($sub_total >= $method->cost) {
                                    $freeShippingMethod = $method;
                                }
                            } else {
                                $shippingMethods[] = $method;
                            }
                        } elseif ($method->shipping_requires == '2' || $method->shipping_requires == '5') {
                            if ($method->method_name == "Free shipping") {
                                if (!empty($coupon)) {
                                    if ($sub_total >= $method->cost) {
                                        $freeShippingMethod = $method;
                                    }
                                }
                            } else {
                                $shippingMethods[] = $method;
                            }
                        } else {
                            $shippingMethods[] = $method;
                        }
                    }
                    if ($freeShippingMethod !== null) {
                        $shippingMethods = [$freeShippingMethod];
                    }
                } else {
                    $Shipping_zone = ShippingZone::where('store_id', $store->id)
                        ->where(function ($query) {
                            $query->where('country_id', '')
                                ->orWhereNull('country_id');
                        })
                        ->where(function ($query) {
                            $query->where('state_id', '')
                                ->orWhereNull('state_id');
                        })
                        ->first();
                    if ($Shipping_zone) {
                        $methods = ShippingMethod::where('zone_id', $Shipping_zone->id)->where('store_id', $store->id)->get();
                        $shippingMethods = [];
                        $freeShippingMethod = null;
                        foreach ($methods as $method) {
                            if ($method->shipping_requires == '1' || $method->shipping_requires == '3' || $method->shipping_requires == '4') {
                                if ($method->method_name == "Free shipping") {
                                    if ($sub_total >= $method->cost) {
                                        $freeShippingMethod = $method;
                                    }
                                } else {
                                    $shippingMethods[] = $method;
                                }
                            } elseif ($method->shipping_requires == '2' || $method->shipping_requires == '5') {
                                if ($method->method_name == "Free shipping") {
                                    if (!empty($coupon)) {
                                        if ($sub_total >= $method->cost) {
                                            $freeShippingMethod = $method;
                                        }
                                    }
                                } else {
                                    $shippingMethods[] = $method;
                                }
                            } else {
                                $shippingMethods[] = $method;
                            }
                        }
                        if ($freeShippingMethod !== null) {
                            $shippingMethods = [$freeShippingMethod];
                        }
                    }
                }
            } else {
                $country = $request->countryId;
                $state_id = $request->stateId;

                $response = Cart::cart_list_cookie($request->all(), $store->id);
                $response = json_decode(json_encode($response));
                $Shipping_zone = ShippingZone::where('store_id', $store->id)->where('country_id', $country)->where('state_id', $state_id)->first();

                $shipping_requires = ShippingMethod::freeShipping();

                $sub_total = $response->data->sub_total;

                if (!empty($Shipping_zone)) {
                    $methods = ShippingMethod::where('zone_id', $Shipping_zone->id)->where('store_id', $store->id)->get();
                    $shippingMethods = [];
                    $freeShippingMethod = null;
                    foreach ($methods as $method) {
                        if ($method->shipping_requires == '1' || $method->shipping_requires == '3' || $method->shipping_requires == '4') {
                            if ($method->method_name == "Free shipping") {
                                if ($sub_total >= $method->cost) {
                                    $freeShippingMethod = $method;
                                }
                            } else {
                                $shippingMethods[] = $method;
                            }
                        } elseif ($method->shipping_requires == '2' || $method->shipping_requires == '5') {
                            if ($method->method_name == "Free shipping") {
                                if (!empty($coupon)) {
                                    if ($sub_total >= $method->cost) {
                                        $freeShippingMethod = $method;
                                    }
                                }
                            } else {
                                $shippingMethods[] = $method;
                            }
                        } else {
                            $shippingMethods[] = $method;
                        }
                    }

                    if ($freeShippingMethod !== null) {
                        $shippingMethods = [$freeShippingMethod];
                    }
                } else {
                    $Shipping_zone = ShippingZone::where('store_id', $store->id)
                        ->where(function ($query) {
                            $query->where('country_id', '')
                                ->orWhereNull('country_id');
                        })
                        ->where(function ($query) {
                            $query->where('state_id', '')
                                ->orWhereNull('state_id');
                        })
                        ->first();
                    if ($Shipping_zone) {
                        $methods = ShippingMethod::where('zone_id', $Shipping_zone->id)->where('store_id', $store->id)->get();

                        $shippingMethods = [];
                        $freeShippingMethod = null;
                        foreach ($methods as $method) {
                            if ($method->shipping_requires == '1' || $method->shipping_requires == '3' || $method->shipping_requires == '4') {
                                if ($method->method_name == "Free shipping") {
                                    if ($sub_total >= $method->cost) {
                                        $freeShippingMethod = $method;
                                    }
                                } else {
                                    $shippingMethods[] = $method;
                                }
                            } elseif ($method->shipping_requires == '2' || $method->shipping_requires == '5') {
                                if ($method->method_name == "Free shipping") {
                                    if (!empty($coupon)) {
                                        if ($sub_total >= $method->cost) {
                                            $freeShippingMethod = $method;
                                        }
                                    }
                                } else {
                                    $shippingMethods[] = $method;
                                }
                            } else {
                                $shippingMethods[] = $method;
                            }
                        }
                        if ($freeShippingMethod !== null) {
                            $shippingMethods = [$freeShippingMethod];
                        }
                    }
                }
            }

            $return['CURRENCY'] = $CURRENCY;
            $return['shipping_method'] = $shippingMethods ?? null;

            return response()->json($return);
        }

        $return['CURRENCY'] = $CURRENCY;
        $return['shipping_method'] = "";

        return response()->json($return);
    }


    public function get_tax_data(Request $request, $slug)
    {

        $Products = $request['product_id'];
        $Product = Product::find($Products);
        $store = getStore($slug);
        $user = User::where('id', $store->created_by)->first();
        if ($user) {
            $plan = Plan::find($user->plan_id);
        }

        $code = trim($request->coupon_code);
        // P01-SEC-14 C4: portable exact-code match. See Coupon::findByExactCode.
        $coupon = Coupon::findByExactCode($code, fn ($query) => $query->where('store_id', $store->id));
        $CURRENCY = \App\Models\Utility::GetValueByName('defult_currancy_symbol', $store->id);
        $tax_option = TaxOption::where('store_id', $store->id)
            ->pluck('value', 'name')->toArray();
        if ($Product == null) {
            $taxs = TaxMethod::where('tax_id', $request['tax_id_value'])->where('store_id', $store->id)->orderBy('priority', 'asc')->get();
            $tax_id = $request['tax_id_value'];
        } else {
            $taxs = TaxMethod::where('tax_id', $Product->tax_id)->where('store_id', $store->id)->orderBy('priority', 'asc')->get();
            $tax_id = $Product->tax_id;
        }
        $return = '';
        if (auth('customers')->user()) {
            $request->merge(['customer_id' => auth('customers')->user()->id, 'store_id' => $store->id, 'slug' => $slug]);
            $address_id = $request->billing_address_id ?? $request->address_id;
            $other_info = $request['billing_info'];

            if ($address_id) {
                $billing_address  = DeliveryAddress::find($address_id);
                $country = !empty($billing_address->country_id) ? $billing_address->country_id : $other_info['delivery_country'];
                $state_id = !empty($billing_address->state_id) ? $billing_address->state_id : $other_info['delivery_state'];
                $city_id = !empty($billing_address->city_id) ? $billing_address->city_id : $other_info['delivery_city'];
            }


            $api = new ApiController();
            $data = $api->cart_list($request, $slug);
            $response = $data->getData();

            $sub_total = $response->data->sub_total;

            $country = !empty($request['countryId']) ? $request['countryId'] : ($other_info['delivery_country'] ?? 0);
            $state_id = !empty($request['stateId']) ? $request['stateId'] : ($other_info['delivery_state'] ?? 0);

            $city_id = !empty($request['cityId']) ? $request['cityId'] : ($other_info['delivery_city'] ?? 0);

            $tax_price = 0;

            if (count($taxs) > 0 && $plan->shipping_method != 'on') {
                $tax_price = 0;

                foreach ($taxs as $tax) {
                    $countryMatch = (!$tax->country_id || $country == $tax->country_id);
                    $stateMatch = (!$tax->state_id || $state_id == $tax->state_id);
                    $cityMatch = (!$tax->city_id || $city_id == $tax->city_id);

                    if ($countryMatch && $stateMatch && $cityMatch) {
                        $amount = $tax->tax_rate * $Product->sale_price / 100;
                        $tax_price += $amount;
                    }
                }
                if (isset($tax_option['round_tax']) && $tax_option['round_tax'] == 1) {
                    $tax_price = round($tax_price);
                } else {
                    $tax_price = $tax_price;
                }
                $return = [
                    'sale_price' => SetNumber($Product->sale_price),
                    'tax_price' => SetNumber($tax_price),
                    'tax_id_value' => $tax_id,
                    'final_total_amount' => SetNumber($Product->sale_price + $tax_price),
                    'CURRENCY' => $CURRENCY,
                ];
            } elseif (count($taxs) > 0 && $plan->shipping_method == 'on') {

                $shipping = $request['shipping_final_price'] ? $request['shipping_final_price'] : (!empty($request->data->shipping_original_price) ? $response->data->shipping_original_price : 0);
                $coupon_amount = 0;
                if ($request['total_coupon_amount'] == '') {
                    $total_amount = $sub_total + $shipping;
                } else {
                    $total_amount = ($sub_total - $request['total_coupon_amount']) + $shipping;
                }
                foreach ($taxs as $tax) {

                    $countryMatch = (!$tax->country_id || $country == $tax->country_id);
                    $stateMatch = (!$tax->state_id || $state_id == $tax->state_id);
                    $cityMatch = (!$tax->city_id || $city_id == $tax->city_id);

                    if ($countryMatch && $stateMatch && $cityMatch) {

                        $amount = $tax->tax_rate * $total_amount / 100;
                        $tax_price += $amount;
                    }
                }
                if ($tax_option['round_tax'] ?? '' == 1) {
                    $tax_price = round($tax_price);
                } else {
                    $tax_price = $tax_price;
                }

                $return = [
                    'sale_price' => SetNumber($response->data->sub_total),
                    'tax_price' => SetNumber($tax_price),
                    'tax_id_value' => $tax_id,
                    'final_total_amount' => SetNumber($total_amount + $tax_price),
                    'CURRENCY' => $CURRENCY,
                ];
            }
            return response()->json($return);
        } else {
            $response = Cart::cart_list_cookie($request->all(), $store->id);

            $response = json_decode(json_encode($response));

            if ($Product == null) {
                $tax_id = $request['tax_id_value'];
            } else {
                $tax_id = $Product->tax_id;
            }

            if ($plan->shipping_method != 'on') {
                $return = [
                    'sale_price' => SetNumber($response->data->sub_total),
                    'tax_price' => SetNumber($response->data->total_tax_price),
                    'tax_id_value' => $tax_id,
                    'final_total_amount' => SetNumber($response->data->total_sub_price),
                    'total_coupon_price' => SetNumber($response->data->total_coupon_price),
                    'CURRENCY' => $CURRENCY,
                ];
            } elseif ($plan->shipping_method == 'on') {
                $shipping = $request['shipping_final_price'] ? $request['shipping_final_price'] : (!empty($request->data->shipping_original_price) ? $response->data->shipping_original_price : 0);
                $coupon_amount = 0;
                if ($request['total_coupon_amount'] == '') {
                    $total_amount = $response->data->total_sub_price;
                } else {
                    $total_amount = ($response->data->total_sub_price);
                }

                $return = [
                    'sale_price' => SetNumber($response->data->sub_total),
                    'tax_id_value' => $tax_id,
                    'tax_price' => SetNumber($response->data->total_tax_price),
                    'final_total_amount' => SetNumber($total_amount),
                    'CURRENCY' => $CURRENCY,
                ];
            }
            return response()->json($return);
        }
    }

    public function abandon_carts_handled(AbandonCartDataTable $dataTable, Request $request)
    {
        if (auth()->user()->isAbleTo('Manage Cart')) {
            return $dataTable->render('cart.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function abandon_carts_show($cartId)
    {
        if (auth()->user()->isAbleTo('Show Cart')) {

            $cart = Cart::where('customer_id', $cartId)->first();
            if ($cart) {
                $cart_product = Cart::where('customer_id', $cartId)->where('store_id', getCurrentStore())->get();
                return view('cart.show', compact('cart_product'));
            }
            return redirect()->back()->with('error', __('Data not found.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function abandon_carts_destroy($cart_id)
    {
        if (auth()->user()->isAbleTo('Delete Cart')) {
            $store = Store::find(getCurrentStore());
            $carts = Cart::where('customer_id', $cart_id)->where('store_id',$store->id)->get();
            foreach ($carts as $cart) {
                $cart->delete();
            }

            return redirect()->back()->with('success', __('Cart delete successfully'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    public function abandon_carts_emailsend(Request $request)
    {

        if (auth()->user()->isAbleTo('Abandon Cart')) {
            $cart  = Cart::find($request->cart_id);
            $user_id = $cart->customer_id;
            $cart_product = Cart::where('customer_id', $user_id)->get();
            $email = $cart->UserData->email;

            $store = getStoreById(getCurrentStore());
            $owner = User::find($store->created_by);
            $product_id    = Crypt::encrypt($cart->product_id);


            try {
                $dArr = Cart::where('customer_id', $user_id)->get();

                $order_id = 1;
                $resp  = Utility::sendEmailTemplate('Abandon Cart', $email, $dArr, $owner, $store, $product_id, $user_id);



                // $return = 'Mail send successfully';
                if ($resp['is_success'] == false) {
                    return response()->json(
                        [
                            'is_success' => false,
                            'message' => $resp['error'],
                        ]
                    );
                } else {
                    return response()->json(
                        [
                            'is_success' => true,
                            'message' => __('Mail send successfully'),
                        ]
                    );
                }
            } catch (\Exception $e) {

                $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                return response()->json(
                    [
                        'is_success' => false,
                        'message' => $smtp_error,
                    ]
                );
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function abandon_carts_messsend(Request $request)
    {
        $cart  = Cart::find($request->cart_id);
        $customer_id = $cart->customer_id;
        $mobile = $cart->UserData;
        if (auth()->user()->isAbleTo('Abandon Cart')) {

            try {
                $dArr = Cart::where('customer_id', $customer_id)->pluck('product_id')->toArray();

                $product = [];
                foreach ($dArr as $item) {
                    $product[] = Product::where('id', $item)->pluck('name')->first();
                }
                $product_name = implode(',', $product);
                $store = getStoreById(getCurrentStore());
                $msg = __("We noticed that you recently visited our $store->name site and added some fantastic items to your shopping cart. We are thrilled that you found products you love! However, it seems like you did not finish your purchase.You finish your order process as soon as possible, Added Product name : $product_name");
                $resp  = Utility::SendMsgs('Abandon Cart', $mobile, $msg);

                // $return = 'Mail send successfully';
                if ($resp  == false) {
                    return response()->json(
                        [
                            'is_success' => false,
                            'message' => __("Invalid Auth access token - Cannot parse access token"),
                        ]
                    );
                } else {
                    return response()->json(
                        [
                            'is_success' => true,
                            'message' => __('Message send successfully'),
                        ]
                    );
                }
            } catch (\Exception $e) {

                $smtp_error = __('Invalid Auth access token - Cannot parse access token');
                return response()->json(
                    [
                        'is_success' => false,
                        'message' => $smtp_error,
                    ]
                );
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}